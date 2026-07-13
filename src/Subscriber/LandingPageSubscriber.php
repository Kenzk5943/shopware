<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\Event\PostPageFormatEvent;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\LandingPage\LandingPageEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

class LandingPageSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var array<string, true> Page IDs already queued in this request */
    private array $queuedPageIds = [];

    /**
     * @param EntityRepository<LandingPageCollection> $landingPageRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly CmsPageFormatterInterface $cmsPageFormatter,
        private readonly SyncServiceInterface $syncService,
        private readonly EntityRepository $landingPageRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LandingPageEvents::LANDING_PAGE_WRITTEN_EVENT => 'onLandingPageWritten',
            LandingPageEvents::LANDING_PAGE_DELETED_EVENT => 'onLandingPageDeleted',
        ];
    }

    public function onLandingPageWritten(EntityWrittenEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $context = $event->getContext();
        $channelContexts = $this->syncService->buildChannelContexts();

        if (empty($channelContexts)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $pageId = $result->getPrimaryKey();
            if (!\is_string($pageId)) {
                continue;
            }

            if (isset($this->queuedPageIds[$pageId])) {
                continue;
            }
            $this->queuedPageIds[$pageId] = true;

            $criteria = new Criteria([$pageId]);
            $criteria->addAssociation('cmsPage.sections.blocks.slots.translations');
            $criteria->addAssociation('translations');
            $criteria->addAssociation('salesChannels');

            /** @var LandingPageEntity|null $landingPage */
            $landingPage = $this->landingPageRepository->search($criteria, $context)->first();

            if ($landingPage === null) {
                continue;
            }

            if (!$landingPage->isActive()) {
                $deletePayloads = $this->cmsPageFormatter->formatPageDelete($pageId);
                $events = [];
                foreach ($deletePayloads as $deleteData) {
                    $events[] = ['type' => 'page.deleted', 'data' => $deleteData];
                }
                if (!empty($events)) {
                    try {
                        $this->messageBus->dispatch(new WebhookMessage($events));
                    } catch (\Throwable $e) {
                        $this->logger->error('[Emporiqa] Failed to queue page deactivation webhook.', [
                            'pageId' => $pageId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                continue;
            }

            $eventType = $result->getOperation() === EntityWriteResult::OPERATION_INSERT
                ? 'page.created'
                : 'page.updated';

            $formatted = $this->cmsPageFormatter->formatLandingPage($landingPage, $channelContexts);

            $postFormatEvent = new PostPageFormatEvent($landingPage, $formatted);
            $this->eventDispatcher->dispatch($postFormatEvent);
            $formatted = $postFormatEvent->getFormattedData();

            try {
                $this->messageBus->dispatch(new WebhookMessage([
                    ['type' => $eventType, 'data' => $formatted],
                ]));
            } catch (\Throwable $e) {
                $this->logger->error('[Emporiqa] Failed to queue page webhook.', [
                    'pageId' => $pageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function onLandingPageDeleted(EntityDeletedEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $pageId = $result->getPrimaryKey();
            if (!\is_string($pageId)) {
                continue;
            }

            $deletePayloads = $this->cmsPageFormatter->formatPageDelete($pageId);

            $events = [];
            foreach ($deletePayloads as $deleteData) {
                $events[] = [
                    'type' => 'page.deleted',
                    'data' => $deleteData,
                ];
            }

            if (!empty($events)) {
                try {
                    $this->messageBus->dispatch(new WebhookMessage($events));
                } catch (\Throwable $e) {
                    $this->logger->error('[Emporiqa] Failed to queue page delete webhook.', [
                        'pageId' => $pageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function reset(): void
    {
        $this->queuedPageIds = [];
    }
}
