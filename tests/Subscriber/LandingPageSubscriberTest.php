<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\LandingPageSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\LandingPage\LandingPageEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class LandingPageSubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private CmsPageFormatterInterface&MockObject $cmsPageFormatter;
    private SyncServiceInterface&MockObject $syncService;
    private EntityRepository&MockObject $landingPageRepository;
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private LandingPageSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->cmsPageFormatter = $this->createMock(CmsPageFormatterInterface::class);
        $this->syncService = $this->createMock(SyncServiceInterface::class);
        $this->landingPageRepository = $this->createMock(EntityRepository::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->subscriber = new LandingPageSubscriber(
            $this->config,
            $this->cmsPageFormatter,
            $this->syncService,
            $this->landingPageRepository,
            $this->messageBus,
            $this->logger,
            $this->eventDispatcher,
        );
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = LandingPageSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(LandingPageEvents::LANDING_PAGE_WRITTEN_EVENT, $events);
        $this->assertArrayHasKey(LandingPageEvents::LANDING_PAGE_DELETED_EVENT, $events);
        $this->assertSame('onLandingPageWritten', $events[LandingPageEvents::LANDING_PAGE_WRITTEN_EVENT]);
        $this->assertSame('onLandingPageDeleted', $events[LandingPageEvents::LANDING_PAGE_DELETED_EVENT]);
    }

    public function testOnLandingPageWrittenSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onLandingPageWritten($event);
    }

    public function testOnLandingPageWrittenSkipsWhenSyncDisabled(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(false);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onLandingPageWritten($event);
    }

    public function testOnLandingPageWrittenSkipsNonLiveVersion(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn('non-live-version-id');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onLandingPageWritten($event);
    }

    public function testOnLandingPageWrittenDispatchesWebhookMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);
        $context->method('getSource')->willReturn(new \Shopware\Core\Framework\Api\Context\SystemSource());

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ];
        $this->syncService->method('buildChannelContexts')->willReturn($channelContexts);

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('page-123');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('isActive')->willReturn(true);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($landingPage);
        $this->landingPageRepository->method('search')->willReturn($searchResult);

        $this->cmsPageFormatter->method('formatLandingPage')->willReturn([
            'identification_number' => 'page-page-123',
            'channels' => [''],
            'titles' => ['' => ['en' => 'Test Page']],
            'contents' => ['' => ['en' => 'Content']],
            'links' => ['' => ['en' => 'https://shop.example.com/test']],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'page.updated';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onLandingPageWritten($event);
    }

    public function testOnLandingPageWrittenSendsDeleteWhenInactive(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);
        $context->method('getSource')->willReturn(new \Shopware\Core\Framework\Api\Context\SystemSource());

        $this->syncService->method('buildChannelContexts')->willReturn([
            '' => [['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en']],
        ]);

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('page-inactive');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('isActive')->willReturn(false);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($landingPage);
        $this->landingPageRepository->method('search')->willReturn($searchResult);

        $this->cmsPageFormatter->method('formatPageDelete')->willReturn([
            ['identification_number' => 'page-page-inactive'],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'page.deleted';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onLandingPageWritten($event);
    }

    public function testOnLandingPageWrittenDeduplicatesPageIds(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);
        $context->method('getSource')->willReturn(new \Shopware\Core\Framework\Api\Context\SystemSource());

        $this->syncService->method('buildChannelContexts')->willReturn([
            '' => [['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en']],
        ]);

        $writeResult1 = $this->createMock(EntityWriteResult::class);
        $writeResult1->method('getPrimaryKey')->willReturn('page-dup');
        $writeResult2 = $this->createMock(EntityWriteResult::class);
        $writeResult2->method('getPrimaryKey')->willReturn('page-dup');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult1, $writeResult2]);

        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('isActive')->willReturn(true);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($landingPage);
        $this->landingPageRepository->method('search')->willReturn($searchResult);

        $this->cmsPageFormatter->method('formatLandingPage')->willReturn([
            'identification_number' => 'page-page-dup',
        ]);

        $this->messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onLandingPageWritten($event);
    }

    public function testOnLandingPageDeletedSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onLandingPageDeleted($event);
    }

    public function testOnLandingPageDeletedDispatchesEvents(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('page-del-001');

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->cmsPageFormatter->method('formatPageDelete')->willReturn([
            ['identification_number' => 'page-page-del-001'],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'page.deleted';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onLandingPageDeleted($event);
    }

    public function testOnLandingPageDeletedSkipsNonLiveVersion(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn('draft-version-id');

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onLandingPageDeleted($event);
    }

    public function testResetClearsQueuedIds(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);
        $context->method('getSource')->willReturn(new \Shopware\Core\Framework\Api\Context\SystemSource());

        $this->syncService->method('buildChannelContexts')->willReturn([
            '' => [['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en']],
        ]);

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('page-reset');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('isActive')->willReturn(true);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($landingPage);
        $this->landingPageRepository->method('search')->willReturn($searchResult);

        $this->cmsPageFormatter->method('formatLandingPage')->willReturn([
            'identification_number' => 'page-page-reset',
        ]);

        $this->messageBus->expects($this->exactly(2))->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onLandingPageWritten($event);
        $this->subscriber->reset();
        $this->subscriber->onLandingPageWritten($event);
    }
}
