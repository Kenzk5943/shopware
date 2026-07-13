<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after order completion data is built and before the webhook is sent.
 * Allows extensions to modify the order event payload (add custom fields, etc.).
 */
class PostOrderFormatEvent extends Event
{
    /** @param array<string, mixed> $eventData */
    public function __construct(
        private readonly OrderEntity $order,
        private array $eventData,
        private readonly string $stateKey,
    ) {
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    /** @return array<string, mixed> */
    public function getEventData(): array
    {
        return $this->eventData;
    }

    /** @param array<string, mixed> $eventData */
    public function setEventData(array $eventData): void
    {
        $this->eventData = $eventData;
    }

    public function getStateKey(): string
    {
        return $this->stateKey;
    }
}
