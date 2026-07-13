<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface WebhookClientInterface
{
    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     */
    public function sendBatchEvents(array $events): bool;

    /**
     * @param array<string, mixed> $data
     */
    public function sendEvent(string $type, array $data): bool;

    public function startSyncSession(string $sessionId, string $entity): bool;

    public function completeSyncSession(string $sessionId, string $entity): bool;

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events Real product events for dry run
     * @return array{success: bool, message: string, dry_run?: array<string, mixed>}
     */
    public function testConnection(array $events = []): array;

    /**
     * Friendly detail parsed from the most recent failed request's response
     * body (or transport exception). Cleared at the start of every request.
     * Never contains secrets.
     */
    public function getLastError(): ?string;
}
