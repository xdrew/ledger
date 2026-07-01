<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the Helm chart's structure (helm lint/template is the deeper gate, run
 * in CI / by hand): the chart parses and the required templates and hallmarks —
 * separate api/worker deployments, the pre-upgrade migration hook, probes, and
 * graceful shutdown — are present.
 */
final class HelmChartTest extends TestCase
{
    private function chartDir(): string
    {
        return \dirname(__DIR__, 3) . '/deploy/helm/ledger-core';
    }

    #[Test]
    public function chartMetadataParses(): void
    {
        $chart = Yaml::parseFile($this->chartDir() . '/Chart.yaml');
        self::assertIsArray($chart);
        self::assertSame('ledger-core', $chart['name'] ?? null);

        self::assertIsArray(Yaml::parseFile($this->chartDir() . '/values.yaml'));
    }

    #[Test]
    public function requiredTemplatesExist(): void
    {
        foreach ([
            'deployment-api.yaml', 'deployment-worker.yaml', 'service.yaml', 'configmap.yaml',
            'secret.yaml', 'migrate-job.yaml', 'hpa-api.yaml', 'hpa-worker.yaml',
            'servicemonitor.yaml', 'pdb.yaml',
        ] as $template) {
            self::assertFileExists($this->chartDir() . '/templates/' . $template);
        }
    }

    #[Test]
    public function migrationsRunAsAPreUpgradeHook(): void
    {
        $job = (string) file_get_contents($this->chartDir() . '/templates/migrate-job.yaml');
        self::assertStringContainsString('pre-install,pre-upgrade', $job);
        self::assertStringContainsString('"migrate"', $job);
    }

    #[Test]
    public function theApiWiresProbesAndGracefulShutdown(): void
    {
        $api = (string) file_get_contents($this->chartDir() . '/templates/deployment-api.yaml');
        self::assertStringContainsString('/healthz', $api);
        self::assertStringContainsString('/readyz', $api);
        self::assertStringContainsString('preStop', $api);
    }
}
