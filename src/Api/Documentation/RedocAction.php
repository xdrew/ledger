<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use App\Infrastructure\OpenApi\OpenApiPublic;
use App\Infrastructure\OpenApi\Tag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Human-readable API documentation: a Redoc shell rendering the live generated
 * OpenAPI document. Public, like the document itself (travel-project convention).
 */
#[OpenApiPublic]
#[Tag('Documentation')]
final class RedocAction
{
    #[Route('/docs', name: 'api_docs', methods: ['GET'])]
    public function __invoke(): Response
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Ledger Core API — documentation</title>
                <style>body { margin: 0; padding: 0; }</style>
            </head>
            <body>
                <redoc spec-url="/api/openapi.json"></redoc>
                <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
            </body>
            </html>
            HTML;

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
