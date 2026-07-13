<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before a bulk sync operation starts.
 * Allows extensions to initialize resources or modify channel contexts.
 */
class PreSyncEvent extends Event
{
    /**
     * @param string $entityType 'products' or 'pages'
     * @param array<string, array<int, array<string, string>>> $channelContexts
     */
    public function __construct(
        private readonly string $entityType,
        private readonly string $sessionId,
        private array $channelContexts,
    ) {
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /** @return array<string, array<int, array<string, string>>> */
    public function getChannelContexts(): array
    {
        return $this->channelContexts;
    }

    /** @param array<string, array<int, array<string, string>>> $channelContexts */
    public function setChannelContexts(array $channelContexts): void
    {
        $this->channelContexts = $channelContexts;
    }
}
