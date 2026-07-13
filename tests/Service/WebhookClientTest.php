<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookClientTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private WebhookClient $webhookClient;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->webhookClient = new WebhookClient($this->config, $this->logger);
    }

    private function createClientWithMock(array $responses, array &$history = []): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    public function testSendBatchEventsReturnsTrueForEmptyEvents(): void
    {
        $this->assertTrue($this->webhookClient->sendBatchEvents([]));
    }

    public function testGenerateSignatureCreatesHmacSha256(): void
    {
        $payload = '{"events":[]}';
        $secret = 'test-secret';

        $expected = hash_hmac('sha256', $payload, $secret);
        $result = WebhookClient::generateSignature($payload, $secret);

        $this->assertSame($expected, $result);
    }

    public function testGenerateSignatureProduces64CharHex(): void
    {
        $result = WebhookClient::generateSignature('data', 'secret');

        $this->assertSame(64, strlen($result));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result);
    }

    public function testGenerateSignatureDifferentPayloadsProduceDifferentSignatures(): void
    {
        $secret = 'shared-secret';

        $sig1 = WebhookClient::generateSignature('payload-one', $secret);
        $sig2 = WebhookClient::generateSignature('payload-two', $secret);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testGenerateSignatureDifferentSecretsProduceDifferentSignatures(): void
    {
        $payload = 'same-payload';

        $sig1 = WebhookClient::generateSignature($payload, 'secret-one');
        $sig2 = WebhookClient::generateSignature($payload, 'secret-two');

        $this->assertNotSame($sig1, $sig2);
    }

    public function testVerifySignatureValidatesCorrectly(): void
    {
        $payload = '{"type":"product.created"}';
        $secret = 'webhook-secret';
        $signature = WebhookClient::generateSignature($payload, $secret);

        $this->assertTrue(WebhookClient::verifySignature($payload, $signature, $secret));
    }

    public function testVerifySignatureRejectsInvalidSignature(): void
    {
        $payload = '{"type":"product.created"}';
        $secret = 'webhook-secret';
        $invalidSignature = 'invalid-signature-value';

        $this->assertFalse(WebhookClient::verifySignature($payload, $invalidSignature, $secret));
    }

    public function testVerifySignatureRejectsWrongSecret(): void
    {
        $payload = '{"type":"product.created"}';
        $signature = WebhookClient::generateSignature($payload, 'correct-secret');

        $this->assertFalse(WebhookClient::verifySignature($payload, $signature, 'wrong-secret'));
    }

    public function testVerifySignatureRejectsTamperedPayload(): void
    {
        $secret = 'webhook-secret';
        $signature = WebhookClient::generateSignature('original-payload', $secret);

        $this->assertFalse(WebhookClient::verifySignature('tampered-payload', $signature, $secret));
    }

    public function testGenerateUserTokenFormat(): void
    {
        $userId = 'user-abc-123';
        $secret = 'webhook-secret';

        $token = WebhookClient::generateUserToken($userId, $secret);

        $this->assertStringContainsString('.', $token);

        $parts = explode('.', $token);
        $this->assertCount(2, $parts);

        // The first part is base64url-encoded JSON
        $decodedPayload = base64_decode(strtr($parts[0], '-_', '+/'));
        $payloadData = json_decode($decodedPayload, true);

        $this->assertIsArray($payloadData);
        $this->assertArrayHasKey('uid', $payloadData);
        $this->assertArrayHasKey('ts', $payloadData);
        $this->assertSame($userId, $payloadData['uid']);
        $this->assertIsInt($payloadData['ts']);
    }

    public function testGenerateUserTokenSignatureIsValid(): void
    {
        $userId = 'customer-456';
        $secret = 'my-secret';

        $token = WebhookClient::generateUserToken($userId, $secret);
        $parts = explode('.', $token);

        $encodedPayload = $parts[0];
        $signature = $parts[1];
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret);

        $this->assertSame($expectedSignature, $signature);
    }

    public function testTestConnectionReturnsErrorWhenNotConfigured(): void
    {
        $this->config
            ->method('isConfigured')
            ->willReturn(false);

        $result = $this->webhookClient->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    public function testSendBatchEventsReturnsFalseWhenWebhookNotConfigured(): void
    {
        $this->config->method('getFullWebhookUrl')->willReturn('/');
        $this->config->method('getWebhookSecret')->willReturn('');

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('not configured'));

        $result = $this->webhookClient->sendBatchEvents([
            ['type' => 'product.created', 'data' => ['name' => 'Test']],
        ]);

        $this->assertFalse($result);
    }

    public function testSendBatchEventsReturnsFalseWhenSecretEmpty(): void
    {
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('');

        $this->logger
            ->expects($this->once())
            ->method('warning');

        $result = $this->webhookClient->sendBatchEvents([
            ['type' => 'product.updated', 'data' => []],
        ]);

        $this->assertFalse($result);
    }

    // --- Dry run tests ---

    public function testTestConnectionAppendsDryRunToUrl(): void
    {
        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], '{"events":[]}'),
        ], $history);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $webhookClient->testConnection();

        $this->assertCount(1, $history);
        $requestUrl = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('?dry_run=true', $requestUrl);
    }

    public function testTestConnectionParsesSuccessResponse(): void
    {
        $dryRunBody = json_encode([
            'status' => 'dry_run',
            'signature' => 'valid',
            'events_validated' => 1,
            'events' => [
                [
                    'type' => 'product.created',
                    'valid' => true,
                    'sku' => 'ABC-123',
                    'identification_number' => 'product-abc',
                    'languages_detected' => ['en', 'de'],
                    'channels_detected' => [''],
                    'fields' => ['names' => true, 'descriptions' => true, 'prices' => true],
                    'warnings' => [],
                ],
            ],
        ]);

        $client = $this->createClientWithMock([
            new Response(200, [], $dryRunBody),
        ]);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $result = $webhookClient->testConnection();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('dry_run', $result);
        $this->assertCount(1, $result['dry_run']['events']);
        $this->assertSame('ABC-123', $result['dry_run']['events'][0]['sku']);
        $this->assertSame(['en', 'de'], $result['dry_run']['events'][0]['languages_detected']);
    }

    public function testTestConnectionHandles401(): void
    {
        $client = $this->createClientWithMock([
            new Response(401, [], '{"error":"Unauthorized"}'),
        ]);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('wrong-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $result = $webhookClient->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid webhook secret', $result['message']);
    }

    public function testTestConnectionHandles400(): void
    {
        $validationBody = json_encode([
            'error' => 'Validation failed',
            'details' => [
                [
                    'event_index' => 1,
                    'event_type' => 'product.created',
                    'errors' => [
                        ['type' => 'dict_type', 'loc' => ['variation_attributes'], 'msg' => 'Input should be a valid dictionary'],
                    ],
                ],
            ],
        ]);

        $client = $this->createClientWithMock([
            new Response(400, [], $validationBody),
        ]);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $result = $webhookClient->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Validation failed', $result['message']);
        $this->assertArrayHasKey('dry_run', $result);
        $this->assertCount(1, $result['dry_run']['details']);
        $this->assertSame('variation_attributes', $result['dry_run']['details'][0]['errors'][0]['loc'][0]);
    }

    public function testTestConnectionHandlesNetworkError(): void
    {
        $client = $this->createClientWithMock([
            new ConnectException('Connection refused', new Request('POST', 'https://emporiqa.com/')),
        ]);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $result = $webhookClient->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection failed', $result['message']);
    }

    public function testTestConnectionUsesProvidedEvents(): void
    {
        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], '{"events":[]}'),
        ], $history);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $customEvents = [
            ['type' => 'product.created', 'data' => ['sku' => 'REAL-001', 'names' => ['' => ['en' => 'Real Product']]]],
        ];

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $webhookClient->testConnection($customEvents);

        $this->assertCount(1, $history);
        $sentBody = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('REAL-001', $sentBody['events'][0]['data']['sku']);
    }

    // --- Redirects disabled (G1) ---

    public function testSendBatchEventsDisablesRedirects(): void
    {
        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], '{}'),
        ], $history);

        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);

        $this->assertCount(1, $history);
        $this->assertSame(false, $history[0]['options']['allow_redirects']);
    }

    public function testTestConnectionDisablesRedirects(): void
    {
        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], '{}'),
        ], $history);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $webhookClient->testConnection();

        $this->assertCount(1, $history);
        $this->assertSame(false, $history[0]['options']['allow_redirects']);
    }

    // --- getLastError (G2) ---

    public function testGetLastErrorIsNullInitially(): void
    {
        $this->assertNull($this->webhookClient->getLastError());
    }

    public function testGetLastErrorParsesErrorField(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['error' => 'Store not found'])),
        ]);
        $webhookClient = $this->webhookClientWithFailingRequest($client);

        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);

        $this->assertSame('Store not found', $webhookClient->getLastError());
    }

    public function testGetLastErrorParsesDetailField(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['detail' => 'Signature mismatch'])),
        ]);
        $webhookClient = $this->webhookClientWithFailingRequest($client);

        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);

        $this->assertSame('Signature mismatch', $webhookClient->getLastError());
    }

    public function testGetLastErrorParsesMessageField(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['message' => 'Internal error occurred'])),
        ]);
        $webhookClient = $this->webhookClientWithFailingRequest($client);

        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);

        $this->assertSame('Internal error occurred', $webhookClient->getLastError());
    }

    public function testGetLastErrorParsesErrorsArrayField(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['errors' => [['msg' => 'Field X is required']]])),
        ]);
        $webhookClient = $this->webhookClientWithFailingRequest($client);

        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);

        $this->assertSame('Field X is required', $webhookClient->getLastError());
    }

    public function testGetLastErrorParsesHintField(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['hint' => 'Check your webhook secret'])),
        ]);
        $webhookClient = $this->webhookClientWithFailingRequest($client);

        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);

        $this->assertSame('Check your webhook secret', $webhookClient->getLastError());
    }

    public function testGetLastErrorSetOnTransportException(): void
    {
        $client = $this->createClientWithMock([
            new ConnectException('Connection refused', new Request('POST', 'https://emporiqa.com/')),
        ]);

        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);

        $this->assertStringContainsString('Connection refused', $webhookClient->getLastError());
    }

    public function testGetLastErrorClearedAtStartOfNextRequest(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['error' => 'First failure'])),
            new Response(200, [], '{}'),
        ]);

        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);
        $this->assertSame('First failure', $webhookClient->getLastError());

        $webhookClient->sendBatchEvents([['type' => 'product.created', 'data' => []]]);
        $this->assertNull($webhookClient->getLastError());
    }

    private function webhookClientWithFailingRequest(Client $client): WebhookClient
    {
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        return new WebhookClient($this->config, $this->logger, $client);
    }

    public function testTestConnectionFallsBackToDefaultPayload(): void
    {
        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], '{"events":[]}'),
        ], $history);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getFullWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/store-1/');
        $this->config->method('getWebhookSecret')->willReturn('test-secret');

        $webhookClient = new WebhookClient($this->config, $this->logger, $client);
        $webhookClient->testConnection();

        $this->assertCount(1, $history);
        $sentBody = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('test-connection', $sentBody['events'][0]['data']['identification_number']);
        $this->assertSame('TEST-001', $sentBody['events'][0]['data']['sku']);
    }
}
