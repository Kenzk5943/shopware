<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClient;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class UserTokenController extends StorefrontController
{
    public function __construct(
        private readonly ConfigServiceInterface $config,
    ) {
    }

    #[Route(path: '/emporiqa/api/user-token', name: 'frontend.emporiqa.user.token', methods: ['GET'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function getToken(SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['error' => 'Cart API is disabled'], Response::HTTP_NOT_FOUND);
        }

        $customer = $context->getCustomer();

        if ($customer === null) {
            return new JsonResponse(
                ['token' => null],
                200,
                ['Cache-Control' => 'private, no-store'],
            );
        }

        $secret = $this->config->getWebhookSecret($context->getSalesChannelId());

        if ($secret === '') {
            return new JsonResponse(
                ['token' => null],
                200,
                ['Cache-Control' => 'private, no-store'],
            );
        }

        $token = WebhookClient::generateUserToken($customer->getId(), $secret);

        return new JsonResponse(
            ['token' => $token],
            200,
            ['Cache-Control' => 'private, no-store'],
        );
    }
}
