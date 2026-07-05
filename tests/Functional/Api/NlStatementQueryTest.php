<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ?q= natural-language statement queries, end to end against the fake
 * translator (no Anthropic API calls in CI).
 */
final class NlStatementQueryTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Force the flag on for this class regardless of the container's real env
        // (a real LLM_STATEMENT_QUERY_ENABLED=0 outranks .env.test in dotenv).
        $_SERVER['LLM_STATEMENT_QUERY_ENABLED'] = $_ENV['LLM_STATEMENT_QUERY_ENABLED'] = '1';
    }

    protected function tearDown(): void
    {
        // Restore on — unsetting would erase it for the rest of the suite,
        // since bootEnv only runs once per process.
        $_SERVER['LLM_STATEMENT_QUERY_ENABLED'] = $_ENV['LLM_STATEMENT_QUERY_ENABLED'] = '1';
        parent::tearDown();
    }

    private function seedAccountWithDeposit(): string
    {
        $accountId = $this->openAccount('USD');
        $this->post(\sprintf('/api/accounts/%s/deposits', $accountId), ['amount' => 10_000, 'currency' => 'USD']);
        $this->catchUpProjections();

        return $accountId;
    }

    #[Test]
    public function aQueryReturnsFilteredEntriesWithTheInterpretation(): void
    {
        $accountId = $this->seedAccountWithDeposit();

        $this->get(\sprintf('/api/accounts/%s/statement?q=show my deposit entries', $accountId));

        self::assertSame(200, $this->statusCode());
        $body = $this->json();
        $entries = $body['entries'] ?? null;
        self::assertIsArray($entries);
        self::assertCount(1, $entries);

        $interpretation = $body['interpretation'] ?? null;
        self::assertIsArray($interpretation);
        self::assertSame(['deposit'], $interpretation['entry_types'] ?? null);
        self::assertSame('list', $interpretation['aggregation'] ?? null);
    }

    #[Test]
    public function aHowMuchQueryReturnsASqlComputedSum(): void
    {
        $accountId = $this->seedAccountWithDeposit();

        $this->get(\sprintf('/api/accounts/%s/statement?q=how much did I deposit', $accountId));

        self::assertSame(200, $this->statusCode());
        $body = $this->json();
        $aggregate = $body['aggregate'] ?? null;
        self::assertIsArray($aggregate);
        self::assertSame('sum', $aggregate['type'] ?? null);
        self::assertSame(10_000, $aggregate['value'] ?? null);
        self::assertSame('USD', $aggregate['currency'] ?? null);
    }

    #[Test]
    public function theFlagOffRejectsWith501InsteadOfDegrading(): void
    {
        $accountId = $this->seedAccountWithDeposit();
        $_SERVER['LLM_STATEMENT_QUERY_ENABLED'] = $_ENV['LLM_STATEMENT_QUERY_ENABLED'] = '0';

        $this->get(\sprintf('/api/accounts/%s/statement?q=how much did I deposit', $accountId));

        self::assertSame(501, $this->statusCode());
        self::assertStringContainsString('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(501, $this->json()['status'] ?? null);
    }

    #[Test]
    public function aTranslationFailureSurfacesAs502(): void
    {
        $accountId = $this->seedAccountWithDeposit();

        $this->get(\sprintf('/api/accounts/%s/statement?q=please fail', $accountId));

        self::assertSame(502, $this->statusCode());
        self::assertSame(502, $this->json()['status'] ?? null);
    }

    #[Test]
    public function withoutAQueryTheStatementIsUnchanged(): void
    {
        $accountId = $this->seedAccountWithDeposit();

        $this->get(\sprintf('/api/accounts/%s/statement', $accountId));

        self::assertSame(200, $this->statusCode());
        $body = $this->json();
        self::assertArrayNotHasKey('interpretation', $body);
        self::assertArrayNotHasKey('aggregate', $body);
        self::assertIsArray($body['entries'] ?? null);
    }
}
