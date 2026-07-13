<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller\Admin;

use Emporiqa\ShopwarePlugin\Service\ConnectServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ConnectController extends AbstractController
{
    public function __construct(
        private readonly ConnectServiceInterface $connectService,
    ) {
    }

    #[Route(path: '/api/_action/emporiqa/connect/initiate', name: 'api.action.emporiqa.connect.initiate', methods: ['POST'])]
    public function initiate(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $origin = (\is_array($body) && isset($body['origin']) && \is_string($body['origin']))
            ? $body['origin']
            : '';

        try {
            $url = $this->connectService->initiate($origin);

            return new JsonResponse(['url' => $url]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to start the connect handshake: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/emporiqa/connect/exchange', name: 'api.action.emporiqa.connect.exchange', methods: ['POST'])]
    public function exchange(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $code = (\is_array($body) && isset($body['code']) && \is_string($body['code'])) ? $body['code'] : '';
        $state = (\is_array($body) && isset($body['state']) && \is_string($body['state'])) ? $body['state'] : '';

        if ($code === '' || $state === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing code or state.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->connectService->exchange($code, $state);

            return new JsonResponse(
                $result,
                $result['success'] ? JsonResponse::HTTP_OK : JsonResponse::HTTP_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to complete the connect handshake: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
