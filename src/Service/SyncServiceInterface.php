<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface SyncServiceInterface
{
    /**
     * @return array{success: bool, products: int, events: int, errors: string[]}
     */
    public function syncProducts(?callable $progressCallback = null, bool $dryRun = false): array;

    /**
     * @return array{success: bool, pages: int, events: int, errors: string[]}
     */
    public function syncPages(?callable $progressCallback = null, bool $dryRun = false): array;

    /**
     * @return array{success: bool, products: int, pages: int, events: int, errors: string[]}
     */
    public function syncAll(?callable $progressCallback = null, bool $dryRun = false): array;

    /**
     * Build channel contexts from sales channels and channel mapping config.
     *
     * @return array<string, array<int, array<string, string>>> Grouped by Emporiqa channel key
     */
    public function buildChannelContexts(): array;

    /**
     * Count active items for an entity.
     *
     * 'products' counts active parent products; 'pages' counts active landing
     * pages plus active shop-page categories combined.
     *
     * @param string $entity 'products' or 'pages'
     */
    public function countItems(string $entity): int;

    /**
     * Process a single page of an entity's driven bulk sync.
     *
     * Uses the same criteria/ordering (id ASC) as the full sync loops, offset
     * by (page - 1) * batch size. For 'pages', pages across landing pages
     * first, then shop-page categories, using one continuous offset.
     *
     * @param string $entity 'products' or 'pages'
     * @param int $page 1-based page number
     * @param string $sessionId Sync session id to stamp onto formatted events
     * @param int|null $batchSize Page size pinned at session start; null falls back to the current config value
     *
     * @return array{success: bool, processed: int, events: int, error?: string}
     */
    public function syncBatch(string $entity, int $page, string $sessionId, ?int $batchSize = null): array;
}
