<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Event;

use Emporiqa\ShopwarePlugin\Event\OrderTrackingResponseEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;

class OrderTrackingResponseEventTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $data = ['order_number' => '10001', 'status' => 'Open'];

        $event = new OrderTrackingResponseEvent($order, $data);

        $this->assertSame($order, $event->getOrder());
        $this->assertSame($data, $event->getData());

        $newData = ['order_number' => '10001', 'status' => 'Open', 'custom_field' => 'value'];
        $event->setData($newData);
        $this->assertSame($newData, $event->getData());
    }

    public function testExtendsSymfonyEvent(): void
    {
        $order = $this->createMock(OrderEntity::class);

        $event = new OrderTrackingResponseEvent($order, []);

        $this->assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
    }
}
