<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HealthAndContractTest extends ApiTestCase
{
    #[Test]
    public function healthIsPublic(): void
    {
        $this->get('/api/health', withKey: false);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['status' => 'ok'], $this->json());
    }

    #[Test]
    public function openApiDocumentIsServedAndWellFormed(): void
    {
        $this->get('/api/openapi.json', withKey: false);

        self::assertSame(200, $this->statusCode());
        $spec = $this->json();

        self::assertSame('3.1.0', $spec['openapi'] ?? null);

        $paths = $spec['paths'] ?? null;
        self::assertIsArray($paths);
        foreach (['/api/accounts', '/api/accounts/{id}', '/api/accounts/{id}/deposits', '/api/transfers', '/api/transfers/{id}', '/api/accounts/{id}/statement', '/api/health'] as $expected) {
            self::assertArrayHasKey($expected, $paths, \sprintf('Missing path %s', $expected));
        }

        $components = $spec['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        foreach (['AccountsOpenRequest', 'AccountsOpenResponse', 'TransfersCreateRequest', 'TransfersCreateResponse'] as $schema) {
            self::assertArrayHasKey($schema, $schemas, \sprintf('Missing schema %s', $schema));
        }

        // API-key security scheme is declared.
        $securitySchemes = $components['securitySchemes'] ?? null;
        self::assertIsArray($securitySchemes);
        $apiKeyScheme = $securitySchemes['apiKey'] ?? null;
        self::assertIsArray($apiKeyScheme);
        self::assertSame('apiKey', $apiKeyScheme['type'] ?? null);
        self::assertSame('X-Api-Key', $apiKeyScheme['name'] ?? null);

        // Protected operations carry the security requirement; public ones do not.
        $accounts = $paths['/api/accounts'] ?? null;
        self::assertIsArray($accounts);
        $accountsPost = $accounts['post'] ?? null;
        self::assertIsArray($accountsPost);
        self::assertArrayHasKey('security', $accountsPost);

        $health = $paths['/api/health'] ?? null;
        self::assertIsArray($health);
        $healthGet = $health['get'] ?? null;
        self::assertIsArray($healthGet);
        self::assertArrayNotHasKey('security', $healthGet);
    }
}
