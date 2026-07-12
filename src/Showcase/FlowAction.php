<?php

declare(strict_types=1);

namespace App\Showcase;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The standalone flow visualization was merged into the playground, where it
 * lives as the "event flow" tab and is driven by the same real API calls. The
 * old /flow URL is kept as a permanent redirect so existing links still land.
 */
final readonly class FlowAction
{
    #[Route('/flow', name: 'flow', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new RedirectResponse('/', Response::HTTP_MOVED_PERMANENTLY);
    }
}
