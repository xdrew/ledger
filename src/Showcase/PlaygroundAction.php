<?php

declare(strict_types=1);

namespace App\Showcase;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the showcase playground: one self-contained HTML file (vanilla JS, no
 * build step) that drives the public API as a plain client. Lives outside the
 * /api firewall — the page itself needs no key; every API call it makes does.
 */
final readonly class PlaygroundAction
{
    #[Route('/', name: 'playground', methods: ['GET'])]
    public function __invoke(): Response
    {
        $html = file_get_contents(__DIR__ . '/playground.html');
        if ($html === false) {
            throw new \RuntimeException('Playground page is missing.');
        }

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
