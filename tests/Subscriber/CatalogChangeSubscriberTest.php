<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\CatalogChangeSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;

class CatalogChangeSubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private CatalogChangeSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new CatalogChangeSubscriber($this->config, $this->logger);
    }

    public function testGetSubscribedEventsCoversCatalogEntities(): void
    {
        $events = CatalogChangeSubscriber::getSubscribedEvents();

        $this->assertSame('onCategoryChanged', $events['category.written']);
        $this->assertSame('onCategoryChanged', $events['category.deleted']);

        foreach (['product_manufacturer', 'currency', 'language', 'tax', 'promotion', 'rule'] as $entity) {
            $this->assertSame('onCatalogEntityChanged', $events[$entity . '.written']);
            $this->assertSame('onCatalogEntityChanged', $events[$entity . '.deleted']);
        }
    }

    public function testPromotionChangeLogsWarning(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Promotion changed'));

        $this->subscriber->onCatalogEntityChanged($this->createEvent('promotion'));
    }

    public function testRuleChangeLogsWarning(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Pricing rule changed'));

        $this->subscriber->onCatalogEntityChanged($this->createEvent('rule'));
    }

    public function testManufacturerChangeLogsWarning(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Manufacturer changed'));

        $this->subscriber->onCatalogEntityChanged($this->createEvent('product_manufacturer'));
    }

    public function testWarningIsLoggedOncePerEntityTypePerRequest(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger->expects($this->once())->method('warning');

        $this->subscriber->onCatalogEntityChanged($this->createEvent('currency'));
        $this->subscriber->onCatalogEntityChanged($this->createEvent('currency'));
    }

    public function testDifferentEntityTypesEachLogTheirOwnWarning(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $messages = [];
        $this->logger
            ->expects($this->exactly(3))
            ->method('warning')
            ->willReturnCallback(function (string $message) use (&$messages) {
                $messages[] = $message;
            });

        $this->subscriber->onCatalogEntityChanged($this->createEvent('currency'));
        $this->subscriber->onCatalogEntityChanged($this->createEvent('language'));
        $this->subscriber->onCatalogEntityChanged($this->createEvent('tax'));

        $this->assertStringContainsString('Currency changed', $messages[0]);
        $this->assertStringContainsString('Language changed', $messages[1]);
        $this->assertStringContainsString('Tax changed', $messages[2]);
        foreach ($messages as $message) {
            $this->assertStringContainsString('emporiqa:sync:products', $message);
        }
    }

    public function testNoWarningWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $this->logger->expects($this->never())->method('warning');

        $this->subscriber->onCatalogEntityChanged($this->createEvent('tax'));
    }

    public function testUnknownEntityTypeIsIgnored(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger->expects($this->never())->method('warning');

        $this->subscriber->onCatalogEntityChanged($this->createEvent('sales_channel'));
    }

    public function testNonPageCategoryChangeLogsWarning(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Category changed'));

        $this->subscriber->onCategoryChanged(
            $this->createEvent('category', [['type' => 'link', 'name' => 'External']]),
        );
    }

    public function testPageCategoryChangeIsSkipped(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger->expects($this->never())->method('warning');

        $this->subscriber->onCategoryChanged(
            $this->createEvent('category', [['type' => 'page', 'name' => 'Landing']]),
        );
    }

    public function testCategoryChangeWithoutTypeInPayloadLogsWarning(): void
    {
        // Renames don't include `type` in the payload — since we can't
        // positively confirm CategorySubscriber owns this category, warn.
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Category changed'));

        $this->subscriber->onCategoryChanged(
            $this->createEvent('category', [['name' => 'Renamed only']]),
        );
    }

    public function testCategoryDeletedWithEmptyPayloadLogsWarning(): void
    {
        // Deletes carry an empty payload — same reasoning as renames: we
        // can't confirm it was a page-type category, so warn.
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Category changed'));

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn([]);

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getEntityName')->willReturn('category');
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->subscriber->onCategoryChanged($event);
    }

    public function testResetAllowsWarningAgain(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger->expects($this->exactly(2))->method('warning');

        $this->subscriber->onCatalogEntityChanged($this->createEvent('language'));
        $this->subscriber->reset();
        $this->subscriber->onCatalogEntityChanged($this->createEvent('language'));
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function createEvent(string $entityName, array $payloads = [[]]): EntityWrittenEvent&MockObject
    {
        $results = [];
        foreach ($payloads as $payload) {
            $result = $this->createMock(EntityWriteResult::class);
            $result->method('getPayload')->willReturn($payload);
            $results[] = $result;
        }

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getEntityName')->willReturn($entityName);
        $event->method('getWriteResults')->willReturn($results);

        return $event;
    }
}
