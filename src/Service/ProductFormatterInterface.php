<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Content\Product\ProductEntity;

interface ProductFormatterInterface
{
    /**
     * Format a product (and its variants) as consolidated webhook payloads.
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts Grouped by channel key
     * @return array<int, array<string, mixed>>
     */
    public function formatProduct(
        ProductEntity $product,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatProductDelete(string $productId): array;

    /**
     * Format lightweight availability event entries (one per variation, or one
     * for the product itself when it has no variations).
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts Grouped by channel key
     * @return array<int, array<string, mixed>>
     */
    public function formatAvailabilityEvents(ProductEntity $product, array $channelContexts): array;
}
