<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\Content\Product\ProductEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a product is formatted and before the webhook payload is sent.
 * Allows extensions to modify the formatted product data.
 */
class PostProductFormatEvent extends Event
{
    /**
     * @param list<array<string, mixed>> $formattedItems Parent + variant formatted payloads
     * @param string $eventType 'product.created' or 'product.updated'
     */
    public function __construct(
        private readonly ProductEntity $product,
        private array $formattedItems,
        private readonly string $eventType,
    ) {
    }

    public function getProduct(): ProductEntity
    {
        return $this->product;
    }

    /** @return list<array<string, mixed>> */
    public function getFormattedItems(): array
    {
        return $this->formattedItems;
    }

    /** @param list<array<string, mixed>> $formattedItems */
    public function setFormattedItems(array $formattedItems): void
    {
        $this->formattedItems = $formattedItems;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }
}
