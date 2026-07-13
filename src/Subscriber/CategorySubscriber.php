<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

class CategorySubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var array<string, true> */
    private array $queuedIds = [];

    /**
     * @param EntityRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly CmsPageFormatterInterface $cmsPageFormatter,
        private readonly SyncServiceInterface $syncService,
        private readonly EntityRepository $categoryRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CategoryEvents::CATEGORY_WRITTEN_EVENT => 'onCategoryWritten',
            CategoryEvents::CATEGORY_DELETED_EVENT => 'onCategoryDeleted',
        ];
    }

    public function onCategoryWritten(EntityWrittenEvent $event): void
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
            $categoryId = $result->getPrimaryKey();
            if (!\is_string($categoryId)) {
                continue;
            }

            if (isset($this->queuedIds[$categoryId])) {
                continue;
            }

            $criteria = new Criteria([$categoryId]);
            $criteria->addAssociation('cmsPage.sections.blocks.slots.translations');
            $criteria->addAssociation('translations');
            $criteria->addAssociation('seoUrls');

            /** @var CategoryEntity|null $category */
            $category = $this->categoryRepository->search($criteria, $context)->first();

            if ($category === null || $category->getType() !== 'page') {
                continue;
            }

            $cmsPage = $category->getCmsPage();
            if ($cmsPage === null || $cmsPage->getType() !== 'page') {
                continue;
            }

            $this->queuedIds[$categoryId] = true;

            // A deactivated shop page must be removed from Emporiqa rather
            // than silently left in place (mirrors LandingPageSubscriber).
            if (!$category->getActive()) {
                $deletePayloads = $this->cmsPageFormatter->formatPageDelete($categoryId);
                $events = [];
                foreach ($deletePayloads as $deleteData) {
                    $events[] = ['type' => 'page.deleted', 'data' => $deleteData];
                }
                if (!empty($events)) {
                    try {
                        $this->messageBus->dispatch(new WebhookMessage($events));
                    } catch (\Throwable $e) {
                        $this->logger->error('[Emporiqa] Failed to queue shop page deactivation webhook.', [
                            'categoryId' => $categoryId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                continue;
            }

            $formatted = $this->cmsPageFormatter->formatShopPage($category, $channelContexts);

            if ($formatted === null) {
                continue;
            }

            try {
                $this->messageBus->dispatch(new WebhookMessage([
                    ['type' => 'page.updated', 'data' => $formatted],
                ]));
            } catch (\Throwable $e) {
                $this->logger->error('[Emporiqa] Failed to queue shop page webhook.', [
                    'categoryId' => $categoryId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function onCategoryDeleted(EntityDeletedEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $categoryId = $result->getPrimaryKey();
            if (!\is_string($categoryId)) {
                continue;
            }

            $deletePayloads = $this->cmsPageFormatter->formatPageDelete($categoryId);

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
                    $this->logger->error('[Emporiqa] Failed to queue shop page delete webhook.', [
                        'categoryId' => $categoryId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function reset(): void
    {
        $this->queuedIds = [];
    }
}
