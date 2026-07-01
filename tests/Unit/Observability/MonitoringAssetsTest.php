<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the committed observability deliverables: the Grafana dashboard and the
 * Prometheus alert rules must parse and cover the expected metrics/alerts.
 */
final class MonitoringAssetsTest extends TestCase
{
    private function assetDir(): string
    {
        return \dirname(__DIR__, 3) . '/deploy/observability';
    }

    #[Test]
    public function grafanaDashboardIsValidAndCoversTheBusinessMetrics(): void
    {
        $raw = file_get_contents($this->assetDir() . '/grafana-dashboard.json');
        self::assertIsString($raw);
        $dashboard = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($dashboard);

        self::assertSame('ledger-core', $dashboard['uid'] ?? null);
        $panels = $dashboard['panels'] ?? null;
        self::assertIsArray($panels);
        self::assertNotEmpty($panels);

        $allExpressions = json_encode($panels, JSON_THROW_ON_ERROR);
        foreach (['transfers_total', 'journal_entries_total', 'idempotency_replays_total', 'holds_active', 'outbox_pending', 'projection_lag_seconds'] as $metric) {
            self::assertStringContainsString($metric, $allExpressions, \sprintf('Dashboard is missing %s', $metric));
        }
    }

    #[Test]
    public function alertRulesParseAndIncludeTheKeyAlerts(): void
    {
        $parsed = Yaml::parseFile($this->assetDir() . '/alerts.yaml');
        self::assertIsArray($parsed);

        $groups = $parsed['groups'] ?? null;
        self::assertIsArray($groups);
        self::assertNotEmpty($groups);

        $names = [];
        foreach ($groups as $group) {
            self::assertIsArray($group);
            $rules = $group['rules'] ?? [];
            self::assertIsArray($rules);
            foreach ($rules as $rule) {
                self::assertIsArray($rule);
                if (isset($rule['alert']) && \is_string($rule['alert'])) {
                    $names[] = $rule['alert'];
                }
            }
        }

        foreach (['ProjectionLagHigh', 'OutboxBacklogGrowing', 'RequestLatencyP99SloBurn'] as $alert) {
            self::assertContains($alert, $names, \sprintf('Missing alert %s', $alert));
        }
    }
}
