<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface ChannelResolverInterface
{
    /**
     * Resolve the Emporiqa channel key for a given sales channel ID.
     */
    public function resolveChannelKey(string $salesChannelId): string;

    /**
     * Get the full channel mapping (salesChannelId => emporiqaChannelKey).
     *
     * If an explicit mapping is configured, it is used.
     * Otherwise, auto-detects from active storefront sales channels:
     * - 1 channel: everything maps to "" (default)
     * - Multiple channels: first maps to "", rest to slugified name
     *
     * @return array<string, string>
     */
    public function getMapping(): array;
}
