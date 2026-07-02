<?php

declare(strict_types=1);

namespace App\Infrastructure\NlQuery;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use App\Projections\Query\StatementFilter;

/**
 * Translates a statement question via the Anthropic Messages API using
 * structured outputs: the JSON schema (additionalProperties: false, enums for
 * entry types and aggregation) makes a schema-conforming response an API
 * guarantee. The result is still re-validated by StatementFilter::fromArray
 * before it can touch SQL. The model is configurable (LLM_MODEL); translation
 * cost is one small non-thinking call.
 */
final readonly class AnthropicStatementQueryTranslator implements StatementQueryTranslator
{
    public function __construct(
        private Client $client,
        private string $model,
    ) {}

    public function translate(string $query, \DateTimeImmutable $today): StatementFilter
    {
        try {
            $message = $this->client->messages->create(
                model: $this->model,
                maxTokens: 1_024,
                system: self::systemPrompt($today),
                messages: [
                    ['role' => 'user', 'content' => $query],
                ],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::filterSchema()]],
            );
        } catch (\Throwable $error) {
            throw TranslationFailed::because('the translation service is unavailable', $error);
        }

        if ($message->stopReason === 'refusal') {
            throw TranslationFailed::because('the query was declined');
        }

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                return self::parse($block->text);
            }
        }

        throw TranslationFailed::because('the response contained no text block');
    }

    /**
     * Decodes and validates a structured-output payload into a filter. Public
     * and static so the parsing contract is unit-testable against fixture
     * payloads without an API client.
     */
    public static function parse(string $json): StatementFilter
    {
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            throw TranslationFailed::because('the response was not valid JSON');
        }

        try {
            /** @var array<string, mixed> $decoded */
            return StatementFilter::fromArray($decoded);
        } catch (\InvalidArgumentException $error) {
            throw TranslationFailed::because('the response did not satisfy the filter contract', $error);
        }
    }

    public static function systemPrompt(\DateTimeImmutable $today): string
    {
        return <<<PROMPT
            You translate natural-language questions about a payment account statement into a
            structured filter. You do not answer the question and you never see the data.

            The statement has entries with exactly these fields:
            - entry_type: one of deposit, hold, hold_release, debit, credit
            - amount: a positive integer in minor units (cents)
            - occurred_at: a timestamp

            There is no counterparty, description, or category information — questions about
            "who" or "what for" cannot be filtered; translate only the parts that map to the
            fields above and leave the rest unfiltered.

            Rules:
            - Money mentioned by the user is in major units; convert to minor units (x100).
            - "How much" questions -> aggregation "sum". "How many" -> "count". Otherwise "list".
            - Outgoing money means debit (and hold for reserved funds); incoming means deposit or credit.
            - Resolve relative dates against today: {$today->format('Y-m-d')}.
            - Use null for any dimension the question does not constrain.
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function filterSchema(): array
    {
        $nullable = static fn(array $schema): array => ['anyOf' => [$schema, ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'entry_types' => $nullable([
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => StatementFilter::ENTRY_TYPES],
                ]),
                'date_from' => $nullable(['type' => 'string', 'format' => 'date']),
                'date_to' => $nullable(['type' => 'string', 'format' => 'date']),
                'min_amount' => $nullable(['type' => 'integer']),
                'max_amount' => $nullable(['type' => 'integer']),
                'aggregation' => ['type' => 'string', 'enum' => StatementFilter::AGGREGATIONS],
            ],
            'required' => ['entry_types', 'date_from', 'date_to', 'min_amount', 'max_amount', 'aggregation'],
            'additionalProperties' => false,
        ];
    }
}
