<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a bulk sync operation completes.
 * Allows extensions to perform cleanup or log results.
 */
class PostSyncEvent extends Event
{
    /**
     * @param string $entityType 'products' or 'pages'
     * @param array<string, mixed> $result Sync result array
     */
    public function __construct(
        private readonly string $entityType,
        private readonly string $sessionId,
        private readonly array $result,
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

    /** @return array<string, mixed> */
    public function getResult(): array
    {
        return $this->result;
    }
}
