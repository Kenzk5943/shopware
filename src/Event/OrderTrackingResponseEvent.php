<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched just before the order tracking response is returned to the
 * caller. Allows extensions to modify the response payload (add custom
 * fields, etc.).
 */
class OrderTrackingResponseEvent extends Event
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly OrderEntity $order,
        private array $data,
    ) {
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): void
    {
        $this->data = $data;
    }
}
