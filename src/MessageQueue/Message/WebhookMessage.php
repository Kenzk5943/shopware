<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class WebhookMessage implements AsyncMessageInterface
{
    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     */
    public function __construct(
        private readonly array $events,
        private readonly int $retryCount = 0,
    ) {
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function withIncrementedRetry(): self
    {
        return new self($this->events, $this->retryCount + 1);
    }
}
