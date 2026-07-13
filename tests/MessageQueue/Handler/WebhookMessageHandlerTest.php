<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\MessageQueue\Handler;

use Emporiqa\ShopwarePlugin\Exception\RateLimitException;
use Emporiqa\ShopwarePlugin\MessageQueue\Handler\WebhookMessageHandler;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class WebhookMessageHandlerTest extends TestCase
{
    private WebhookClientInterface&MockObject $webhookClient;
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private WebhookMessageHandler $handler;

    protected function setUp(): void
    {
        $this->webhookClient = $this->createMock(WebhookClientInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new WebhookMessageHandler($this->webhookClient, $this->messageBus, $this->logger);
    }

    public function testInvokeSendsBatchEvents(): void
    {
        $events = [
            ['type' => 'product.created', 'data' => ['name' => 'Test Product']],
            ['type' => 'product.updated', 'data' => ['name' => 'Updated Product']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->with($events)
            ->willReturn(true);

        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    public function testInvokeSkipsEmptyEvents(): void
    {
        $message = new WebhookMessage([]);

        $this->webhookClient->expects($this->never())->method('sendBatchEvents');
        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    public function testInvokeThrowsRuntimeExceptionOnFailure(): void
    {
        $events = [
            ['type' => 'product.created', 'data' => ['name' => 'Failing Product']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->with($events)
            ->willReturn(false);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to send webhook events'),
                $this->callback(function (array $context) {
                    return $context['event_count'] === 1;
                }),
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery failed for 1 events');

        ($this->handler)($message);
    }

    public function testInvokeRequeuesWithDelayOnRateLimit(): void
    {
        $events = [
            ['type' => 'product.updated', 'data' => ['name' => 'Rate Limited Product']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->willThrowException(new RateLimitException('Rate limit exceeded (HTTP 429).'));

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(function (WebhookMessage $requeued) use ($events) {
                    return $requeued->getEvents() === $events && $requeued->getRetryCount() === 1;
                }),
                $this->callback(function (array $stamps) {
                    foreach ($stamps as $stamp) {
                        if ($stamp instanceof DelayStamp && $stamp->getDelay() >= 60_000) {
                            return true;
                        }
                    }
                    return false;
                }),
            )
            ->willReturn(new Envelope($message));

        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    public function testInvokeDropsMessageAfterMaxRetries(): void
    {
        $events = [
            ['type' => 'product.updated', 'data' => ['name' => 'Exhausted Product']],
        ];

        $message = new WebhookMessage($events, 5);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->willThrowException(new RateLimitException('Rate limit exceeded (HTTP 429).'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Rate limit retry exhausted'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook rate limit exhausted after 5 retries');

        ($this->handler)($message);
    }

    public function testInvokeDoesNotThrowOnRateLimit(): void
    {
        $events = [['type' => 'product.updated', 'data' => []]];
        $message = new WebhookMessage($events);

        $this->webhookClient
            ->method('sendBatchEvents')
            ->willThrowException(new RateLimitException('Rate limit exceeded (HTTP 429).'));

        $this->messageBus
            ->method('dispatch')
            ->willReturn(new Envelope($message));

        // Must not throw — rate limit causes re-queue, not failure
        ($this->handler)($message);
        $this->addToAssertionCount(1);
    }

    public function testInvokeSendsMultipleEventsSuccessfully(): void
    {
        $events = [
            ['type' => 'product.created', 'data' => ['name' => 'Product 1']],
            ['type' => 'product.created', 'data' => ['name' => 'Product 2']],
            ['type' => 'product.created', 'data' => ['name' => 'Product 3']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->with($events)
            ->willReturn(true);

        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    public function testInvokeThrowsWithCorrectEventCountOnFailure(): void
    {
        $events = [
            ['type' => 'product.deleted', 'data' => ['id' => '1']],
            ['type' => 'product.deleted', 'data' => ['id' => '2']],
            ['type' => 'product.deleted', 'data' => ['id' => '3']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->method('sendBatchEvents')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery failed for 3 events');

        ($this->handler)($message);
    }
}
