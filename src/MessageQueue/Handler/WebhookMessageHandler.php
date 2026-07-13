<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Handler;

use Emporiqa\ShopwarePlugin\Exception\RateLimitException;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
class WebhookMessageHandler
{
    private const RATE_LIMIT_DELAY_MS = 65_000; // 65 seconds, past the 60s rate limit window
    private const MAX_RATE_LIMIT_RETRIES = 5;

    public function __construct(
        private readonly WebhookClientInterface $webhookClient,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WebhookMessage $message): void
    {
        $events = $message->getEvents();

        if (empty($events)) {
            return;
        }

        try {
            $success = $this->webhookClient->sendBatchEvents($events);
        } catch (RateLimitException $e) {
            if ($message->getRetryCount() >= self::MAX_RATE_LIMIT_RETRIES) {
                $this->logger->error(sprintf(
                    '[Emporiqa] Rate limit retry exhausted after %d attempts, dropping %d events.',
                    self::MAX_RATE_LIMIT_RETRIES,
                    \count($events),
                ));

                throw new \RuntimeException(sprintf(
                    '[Emporiqa] Webhook rate limit exhausted after %d retries for %d events.',
                    self::MAX_RATE_LIMIT_RETRIES,
                    \count($events),
                ));
            }

            $this->logger->info(sprintf(
                '[Emporiqa] Rate limited, re-queuing %d events with %ds delay (attempt %d/%d).',
                \count($events),
                self::RATE_LIMIT_DELAY_MS / 1000,
                $message->getRetryCount() + 1,
                self::MAX_RATE_LIMIT_RETRIES,
            ));
            $this->messageBus->dispatch($message->withIncrementedRetry(), [new DelayStamp(self::RATE_LIMIT_DELAY_MS)]);

            return;
        }

        if (!$success) {
            $this->logger->warning('[Emporiqa] Failed to send webhook events via message queue.', [
                'event_count' => \count($events),
            ]);

            throw new \RuntimeException(sprintf(
                '[Emporiqa] Webhook delivery failed for %d events.',
                \count($events),
            ));
        }
    }
}
