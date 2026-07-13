<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Event;

use Emporiqa\ShopwarePlugin\Event\PostOrderFormatEvent;
use Emporiqa\ShopwarePlugin\Event\PostPageFormatEvent;
use Emporiqa\ShopwarePlugin\Event\PostProductFormatEvent;
use Emporiqa\ShopwarePlugin\Event\PostSyncEvent;
use Emporiqa\ShopwarePlugin\Event\PreSyncEvent;
use Emporiqa\ShopwarePlugin\Event\WidgetParamsEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class EventClassesTest extends TestCase
{
    public function testPostProductFormatEventGettersAndSetters(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $items = [['identification_number' => 'product-123']];

        $event = new PostProductFormatEvent($product, $items, 'product.created');

        $this->assertSame($product, $event->getProduct());
        $this->assertSame($items, $event->getFormattedItems());
        $this->assertSame('product.created', $event->getEventType());

        $newItems = [['identification_number' => 'product-456']];
        $event->setFormattedItems($newItems);
        $this->assertSame($newItems, $event->getFormattedItems());
    }

    public function testPostPageFormatEventGettersAndSetters(): void
    {
        $page = $this->createMock(LandingPageEntity::class);
        $data = ['identification_number' => 'page-123', 'titles' => []];

        $event = new PostPageFormatEvent($page, $data);

        $this->assertSame($page, $event->getLandingPage());
        $this->assertSame($data, $event->getFormattedData());

        $newData = ['identification_number' => 'page-456'];
        $event->setFormattedData($newData);
        $this->assertSame($newData, $event->getFormattedData());
    }

    public function testPostOrderFormatEventGettersAndSetters(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $data = ['order_id' => '10001', 'total' => 99.99];

        $event = new PostOrderFormatEvent($order, $data, 'order.state.completed');

        $this->assertSame($order, $event->getOrder());
        $this->assertSame($data, $event->getEventData());
        $this->assertSame('order.state.completed', $event->getStateKey());

        $newData = ['order_id' => '10001', 'total' => 99.99, 'custom_field' => 'value'];
        $event->setEventData($newData);
        $this->assertSame($newData, $event->getEventData());
    }

    public function testPreSyncEventGettersAndSetters(): void
    {
        $contexts = ['' => [['languageCode' => 'en', 'domainUrl' => 'https://example.com']]];

        $event = new PreSyncEvent('products', 'session-123', $contexts);

        $this->assertSame('products', $event->getEntityType());
        $this->assertSame('session-123', $event->getSessionId());
        $this->assertSame($contexts, $event->getChannelContexts());

        $newContexts = ['b2b' => [['languageCode' => 'de', 'domainUrl' => 'https://b2b.example.com']]];
        $event->setChannelContexts($newContexts);
        $this->assertSame($newContexts, $event->getChannelContexts());
    }

    public function testPostSyncEventGetters(): void
    {
        $result = ['success' => true, 'products' => 100, 'events' => 150, 'errors' => []];

        $event = new PostSyncEvent('products', 'session-456', $result);

        $this->assertSame('products', $event->getEntityType());
        $this->assertSame('session-456', $event->getSessionId());
        $this->assertSame($result, $event->getResult());
    }

    public function testWidgetParamsEventGettersAndSetters(): void
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $params = ['storeId' => 'store-123', 'language' => 'en'];

        $event = new WidgetParamsEvent($params, $salesChannelContext);

        $this->assertSame($params, $event->getParams());
        $this->assertSame($salesChannelContext, $event->getSalesChannelContext());

        $newParams = ['storeId' => 'store-123', 'language' => 'en', 'customParam' => 'value'];
        $event->setParams($newParams);
        $this->assertSame($newParams, $event->getParams());
    }

    public function testAllEventsExtendSymfonyEvent(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $page = $this->createMock(LandingPageEntity::class);
        $order = $this->createMock(OrderEntity::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        $events = [
            new PostProductFormatEvent($product, [], 'product.created'),
            new PostPageFormatEvent($page, []),
            new PostOrderFormatEvent($order, [], 'order.state.completed'),
            new PreSyncEvent('products', 'sess', []),
            new PostSyncEvent('products', 'sess', []),
            new WidgetParamsEvent([], $salesChannelContext),
        ];

        foreach ($events as $event) {
            $this->assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
        }
    }
}
