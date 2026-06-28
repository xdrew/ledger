<?php

declare(strict_types=1);

namespace App\Api\Health;

use App\Infrastructure\OpenApi\OpenApiPublic;
use App\Infrastructure\OpenApi\Tag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness endpoint. Public — no API key required.
 */
#[OpenApiPublic]
#[Tag('Health')]
final class Action
{
    #[Route('/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
