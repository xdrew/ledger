<?php

declare(strict_types=1);

namespace App\Tests\Unit\NlQuery;

use App\Infrastructure\NlQuery\AnthropicStatementQueryTranslator;
use App\Infrastructure\NlQuery\TranslationFailed;
use App\Projections\Query\StatementFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The adapter's request/response contract, tested without an API client: the
 * structured-output schema it sends, and the parsing of (fixture) payloads the
 * API returns under that schema.
 */
final class AnthropicTranslatorContractTest extends TestCase
{
    #[Test]
    public function parsesASchemaConformingPayload(): void
    {
        // As returned by the Messages API under the structured-output schema.
        $fixture = '{"entry_types":["deposit"],"date_from":"2026-06-01","date_to":"2026-06-30","min_amount":null,"max_amount":null,"aggregation":"sum"}';

        $filter = AnthropicStatementQueryTranslator::parse($fixture);

        self::assertSame(['deposit'], $filter->entryTypes);
        self::assertSame('sum', $filter->aggregation);
        self::assertSame('2026-06-01', $filter->dateFrom?->format('Y-m-d'));
    }

    #[Test]
    public function malformedJsonFailsAsTranslationFailed(): void
    {
        $this->expectException(TranslationFailed::class);
        AnthropicStatementQueryTranslator::parse('not json at all');
    }

    #[Test]
    public function validJsonOutsideTheContractFailsAsTranslationFailed(): void
    {
        $this->expectException(TranslationFailed::class);
        // Schema-invalid content must still be rejected by our validation layer.
        AnthropicStatementQueryTranslator::parse('{"entry_types":["wire_transfer"],"aggregation":"list"}');
    }

    #[Test]
    public function theSchemaConstrainsEverythingTheFilterAccepts(): void
    {
        $schema = AnthropicStatementQueryTranslator::filterSchema();

        self::assertFalse($schema['additionalProperties']);
        self::assertEqualsCanonicalizing(
            ['entry_types', 'date_from', 'date_to', 'min_amount', 'max_amount', 'aggregation'],
            $schema['required'],
        );

        $properties = $schema['properties'];
        self::assertIsArray($properties);
        $aggregation = $properties['aggregation'];
        self::assertIsArray($aggregation);
        self::assertSame(StatementFilter::AGGREGATIONS, $aggregation['enum']);

        $entryTypes = $properties['entry_types'];
        self::assertIsArray($entryTypes);
        $anyOf = $entryTypes['anyOf'];
        self::assertIsArray($anyOf);
        $arrayVariant = $anyOf[0];
        self::assertIsArray($arrayVariant);
        $items = $arrayVariant['items'];
        self::assertIsArray($items);
        self::assertSame(StatementFilter::ENTRY_TYPES, $items['enum']);
    }

    #[Test]
    public function theSystemPromptAnchorsRelativeDatesToToday(): void
    {
        $prompt = AnthropicStatementQueryTranslator::systemPrompt(new \DateTimeImmutable('2026-07-02'));

        self::assertStringContainsString('2026-07-02', $prompt);
        self::assertStringContainsString('minor units', $prompt);
        self::assertStringContainsString('no counterparty', $prompt);
    }
}
