<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the §8 documentation artifacts: they must exist and keep their mandated
 * sections, so CI fails if they are removed or gutted (structure, not substance —
 * substance is reviewed).
 */
final class DocsArtifactsTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function read(string $relativePath): string
    {
        $content = file_get_contents(self::root() . '/' . $relativePath);
        self::assertIsString($content, \sprintf('Missing artifact %s', $relativePath));

        return $content;
    }

    #[Test]
    #[DataProvider('provideAdrHasTheMandatedSectionsCases')]
    public function adrHasTheMandatedSections(string $path): void
    {
        $adr = self::read($path);

        self::assertStringContainsString('Status: Accepted', $adr);
        foreach (['## Context', '## Decision', '## Consequences', '## Options considered and rejected'] as $section) {
            self::assertStringContainsString($section, $adr, \sprintf('%s is missing "%s"', $path, $section));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAdrHasTheMandatedSectionsCases(): iterable
    {
        yield 'ADR-001' => ['docs/adr/ADR-001-event-sourcing.md'];
        yield 'ADR-002' => ['docs/adr/ADR-002-transactional-outbox.md'];
        yield 'ADR-003' => ['docs/adr/ADR-003-async-projections.md'];
        yield 'ADR-004' => ['docs/adr/ADR-004-deliberate-non-es.md'];
        yield 'ADR-005' => ['docs/adr/ADR-005-saga-orchestration.md'];
        yield 'ADR-006' => ['docs/adr/ADR-006-event-versioning-upcasting.md'];
    }

    #[Test]
    public function designDocCoversTheMandatedAnalyses(): void
    {
        $design = self::read('docs/design.md');

        foreach ([
            '## Architecture summary',
            '## Options considered and rejected',
            '## Brownfield evolution path',
            '## 100x scaling analysis',
            '## Cost and on-call',
        ] as $section) {
            self::assertStringContainsString($section, $design);
        }
    }

    #[Test]
    public function runbookCoversThePlaysAndEveryAlert(): void
    {
        $runbook = self::read('docs/runbook.md');

        foreach ([
            '## Play: rebuild a projection',
            '## Play: drain a stuck transfer saga',
            '## Play: outbox backlog',
            '## Alert index',
        ] as $section) {
            self::assertStringContainsString($section, $runbook);
        }

        // Every shipped alert rule must appear in the runbook.
        $alerts = Yaml::parseFile(self::root() . '/deploy/observability/alerts.yaml');
        self::assertIsArray($alerts);
        $groups = $alerts['groups'] ?? [];
        self::assertIsArray($groups);
        foreach ($groups as $group) {
            self::assertIsArray($group);
            $rules = $group['rules'] ?? [];
            self::assertIsArray($rules);
            foreach ($rules as $rule) {
                self::assertIsArray($rule);
                $name = $rule['alert'] ?? null;
                if (\is_string($name)) {
                    self::assertStringContainsString($name, $runbook, \sprintf('Alert %s has no runbook entry', $name));
                }
            }
        }
    }

    #[Test]
    public function sloDocMapsSlosToTheirAlerts(): void
    {
        $slo = self::read('docs/slo.md');

        self::assertStringContainsString('## SLOs', $slo);
        self::assertStringContainsString('Burn alert', $slo, 'The SLO table must map each SLO to its alert.');
        foreach (['ProjectionLagHigh', 'OutboxBacklogGrowing', 'RequestLatencyP99SloBurn', 'NotReady'] as $alert) {
            self::assertStringContainsString($alert, $slo);
        }
    }

    #[Test]
    public function readmeCoversTheDefinitionOfDoneItems(): void
    {
        $readme = self::read('README.md');

        self::assertStringContainsString('```mermaid', $readme, 'Architecture diagram');
        self::assertStringContainsString('Bounded contexts', $readme, 'Bounded-context map');
        self::assertStringContainsString('## How to run', $readme);
        self::assertStringContainsString('## How to rebuild a projection', $readme);
        self::assertStringContainsString('projections:rebuild', $readme, 'Real rebuild instructions, not a placeholder');
        self::assertStringContainsString('deliberately NOT built', $readme);
    }
}
