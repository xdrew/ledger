<?php

declare(strict_types=1);

namespace App\Ops\Readyz;

use App\Ops\ReadinessProbe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Readiness probe: `200` when the database answers and the outbox relay is live,
 * otherwise `503` naming the failing check. Served outside the `/api` firewall.
 */
final readonly class Action
{
    public function __construct(private ReadinessProbe $probe) {}

    #[Route('/readyz', name: 'readyz', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $result = $this->probe->check();
        $status = $result['ready'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse(
            ['status' => $result['ready'] ? 'ready' : 'not_ready', 'checks' => $result['checks']],
            $status,
        );
    }
}
