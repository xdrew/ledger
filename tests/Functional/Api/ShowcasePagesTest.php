<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ShowcasePagesTest extends ApiTestCase
{
    #[Test]
    public function thePlaygroundIsServedWithoutAKey(): void
    {
        $this->get('/', withKey: false);

        self::assertSame(200, $this->statusCode());
        self::assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('ledger-core', $html);
        self::assertStringContainsString('Double-spend race', $html);
        self::assertStringContainsString('the journal', $html);
    }

    #[Test]
    public function theDocsPageIsServedWithoutAKey(): void
    {
        $this->get('/api/docs', withKey: false);

        self::assertSame(200, $this->statusCode());
        self::assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('/api/openapi.json', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function theOpenApiDocumentCoversTheEventEndpoints(): void
    {
        $this->get('/api/openapi.json', withKey: false);

        self::assertSame(200, $this->statusCode());
        $paths = $this->json()['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertArrayHasKey('/api/accounts/{id}/events', $paths);
        self::assertArrayHasKey('/api/transfers/{id}/events', $paths);
    }
}
