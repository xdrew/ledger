<?php

declare(strict_types=1);

namespace App\Ops\Healthz;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness probe: depends on nothing, so a `200` means the worker is up. Served
 * outside the `/api` firewall, so it needs no API key.
 */
final class Action
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'alive']);
    }
}
