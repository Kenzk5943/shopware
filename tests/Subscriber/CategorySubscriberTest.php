<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\CategorySubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class CategorySubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private CmsPageFormatterInterface&MockObject $cmsPageFormatter;
    private SyncServiceInterface&MockObject $syncService;
    private EntityRepository&MockObject $categoryRepository;
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private CategorySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->cmsPageFormatter = $this->createMock(CmsPageFormatterInterface::class);
        $this->syncService = $this->createMock(SyncServiceInterface::class);
        $this->categoryRepository = $this->createMock(EntityRepository::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new CategorySubscriber(
            $this->config,
            $this->cmsPageFormatter,
            $this->syncService,
            $this->categoryRepository,
            $this->messageBus,
            $this->logger,
        );
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = CategorySubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(CategoryEvents::CATEGORY_WRITTEN_EVENT, $events);
        $this->assertArrayHasKey(CategoryEvents::CATEGORY_DELETED_EVENT, $events);
        $this->assertSame('onCategoryWritten', $events[CategoryEvents::CATEGORY_WRITTEN_EVENT]);
        $this->assertSame('onCategoryDeleted', $events[CategoryEvents::CATEGORY_DELETED_EVENT]);
    }

    public function testOnCategoryWrittenSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onCategoryWritten($event);
    }

    public function testOnCategoryWrittenSkipsWhenSyncDisabled(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(false);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onCategoryWritten($event);
    }

    public function testOnCategoryWrittenSkipsNonLiveVersion(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn('non-live-version-id');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onCategoryWritten($event);
    }

    public function testOnCategoryWrittenDispatchesUpdateForActiveShopPage(): void
    {
        $context = $this->createLiveContext();
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('cat-123');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $category = $this->mockShopPageCategory(active: true);
        $this->mockRepositorySearchReturns($category);

        $this->cmsPageFormatter->method('formatShopPage')->willReturn([
            'identification_number' => 'page-cat-123',
            'channels' => [''],
            'titles' => ['' => ['en' => 'Shop Page']],
            'contents' => ['' => ['en' => 'Content']],
            'links' => ['' => ['en' => 'https://shop.example.com/cat-123']],
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

        $this->subscriber->onCategoryWritten($event);
    }

    public function testOnCategoryWrittenSendsPageDeletedWhenShopPageDeactivated(): void
    {
        $context = $this->createLiveContext();
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('cat-inactive');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $category = $this->mockShopPageCategory(active: false);
        $this->mockRepositorySearchReturns($category);

        $this->cmsPageFormatter->method('formatPageDelete')->willReturn([
            ['identification_number' => 'page-cat-inactive'],
        ]);
        $this->cmsPageFormatter->expects($this->never())->method('formatShopPage');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'page.deleted'
                    && $message->getEvents()[0]['data'] === ['identification_number' => 'page-cat-inactive'];
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onCategoryWritten($event);
    }

    public function testOnCategoryWrittenSkipsNonPageCategory(): void
    {
        $context = $this->createLiveContext();
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('cat-link');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $category = $this->createMock(CategoryEntity::class);
        $category->method('getType')->willReturn('link');
        $this->mockRepositorySearchReturns($category);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCategoryWritten($event);
    }

    public function testOnCategoryWrittenSkipsWhenCategoryNotFound(): void
    {
        $context = $this->createLiveContext();
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('cat-missing');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);
        $this->categoryRepository->method('search')->willReturn($searchResult);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCategoryWritten($event);
    }

    public function testOnCategoryDeletedDispatchesEvents(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('cat-del-001');

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->cmsPageFormatter->method('formatPageDelete')->willReturn([
            ['identification_number' => 'page-cat-del-001'],
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

        $this->subscriber->onCategoryDeleted($event);
    }

    public function testResetClearsQueuedIds(): void
    {
        $context = $this->createLiveContext();
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('cat-reset');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $category = $this->mockShopPageCategory(active: true);
        $this->mockRepositorySearchReturns($category);

        $this->cmsPageFormatter->method('formatShopPage')->willReturn([
            'identification_number' => 'page-cat-reset',
        ]);

        $this->messageBus->expects($this->exactly(2))->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onCategoryWritten($event);
        $this->subscriber->reset();
        $this->subscriber->onCategoryWritten($event);
    }

    private function configureEnabled(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);
        $this->syncService->method('buildChannelContexts')->willReturn([
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ]);
    }

    private function createLiveContext(): Context&MockObject
    {
        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);

        return $context;
    }

    private function mockShopPageCategory(bool $active): CategoryEntity&MockObject
    {
        $cmsPage = $this->createMock(CmsPageEntity::class);
        $cmsPage->method('getType')->willReturn('page');

        $category = $this->createMock(CategoryEntity::class);
        $category->method('getType')->willReturn('page');
        $category->method('getActive')->willReturn($active);
        $category->method('getCmsPage')->willReturn($cmsPage);

        return $category;
    }

    private function mockRepositorySearchReturns(CategoryEntity&MockObject $category): void
    {
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($category);
        $this->categoryRepository->method('search')->willReturn($searchResult);
    }
}
