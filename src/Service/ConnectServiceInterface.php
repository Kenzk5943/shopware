<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface ConnectServiceInterface
{
    /**
     * Start the one-click connect handshake: mint state + PKCE verifier,
     * persist them, and return the Emporiqa /connect/start URL to redirect to.
     *
     * @throws \InvalidArgumentException when the shop origin is not a valid https origin
     */
    public function initiate(string $shopOrigin): string;

    /**
     * Complete the handshake: verify state, consume the pending nonce, and
     * exchange the one-time code for credentials via /connect/exchange.
     *
     * @return array{success: bool, storeId?: string, error?: string}
     */
    public function exchange(string $code, string $state): array;
}
