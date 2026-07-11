<?php

declare(strict_types=1);

namespace App\Showcase;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the flow visualization: one self-contained HTML file (vanilla JS, no
 * build step) that animates how a single event travels through the machine —
 * command → aggregate → event log → outbox & projections → read models. Like
 * the playground it lives outside the /api firewall; the page needs no key, but
 * its optional "live" mode drives the same public API and so does.
 */
final readonly class FlowAction
{
    #[Route('/flow', name: 'flow', methods: ['GET'])]
    public function __invoke(): Response
    {
        $html = file_get_contents(__DIR__ . '/flow.html');
        if ($html === false) {
            throw new \RuntimeException('Flow page is missing.');
        }

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
