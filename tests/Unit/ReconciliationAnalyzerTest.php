<?php

declare(strict_types=1);

namespace Tarrou\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tarrou\Reconciliation\ReconciliationAnalyzer;

final class ReconciliationAnalyzerTest extends TestCase
{
    public function test_fixture_produces_matched_project_and_explicit_no_project_views(): void
    {
        $fixture = require __DIR__ . '/../Fixtures/reconciliation_fixture.php';
        $analyzer = new ReconciliationAnalyzer();

        $withProject = $analyzer->analyze(
            $fixture['assignment_with_project'],
            $fixture['projects'],
            $fixture['issues'],
            $fixture['requirements'],
            $fixture['policy']
        );
        $noProject = $analyzer->analyze(
            $fixture['assignment_no_project'],
            $fixture['projects'],
            $fixture['issues'],
            $fixture['requirements'],
            $fixture['policy']
        );

        self::assertNotNull($withProject->canonicalProject);
        self::assertSame('proj_dns', $withProject->canonicalProject?->id);
        self::assertFalse($withProject->explicitNoProject);

        self::assertNull($noProject->canonicalProject);
        self::assertTrue($noProject->explicitNoProject);
    }

    public function test_linked_issues_use_stable_identifiers_and_duplicates_are_detected(): void
    {
        $fixture = require __DIR__ . '/../Fixtures/reconciliation_fixture.php';
        $report = (new ReconciliationAnalyzer())->analyze(
            $fixture['assignment_with_project'],
            $fixture['projects'],
            $fixture['issues'],
            $fixture['requirements'],
            $fixture['policy']
        );

        self::assertCount(2, $report->linkedIssues);
        self::assertSame('lin_100', $report->linkedIssues[0]->id);
        self::assertSame('MME-1550', $report->linkedIssues[0]->identifier);
        self::assertNotEmpty($report->duplicates);
        self::assertSame('dns/example.com/drift-followup', $report->duplicates[0]['work_dedupe_key']);
        self::assertSame(['lin_100', 'lin_101'], $report->duplicates[0]['issue_ids']);
    }

    public function test_missing_work_generates_approval_gated_replay_safe_create_intent_with_evidence(): void
    {
        $fixture = require __DIR__ . '/../Fixtures/reconciliation_fixture.php';
        $analyzer = new ReconciliationAnalyzer();
        $first = $analyzer->analyze(
            $fixture['assignment_with_project'],
            $fixture['projects'],
            $fixture['issues'],
            $fixture['requirements'],
            $fixture['policy']
        );
        $second = $analyzer->analyze(
            $fixture['assignment_with_project'],
            $fixture['projects'],
            $fixture['issues'],
            $fixture['requirements'],
            $fixture['policy']
        );

        self::assertCount(1, $first->suggestedWork);
        $intent = $first->suggestedWork[0];
        self::assertSame('Nameserver hardening follow-up for example.com', $intent->name);
        self::assertSame('dns/example.com/ns-hardening', $intent->workDedupeKey);
        self::assertTrue($intent->approvalRequired);
        self::assertStringContainsString('missing requirement dedupe key', $intent->rule);
        self::assertNotSame('', $intent->evidence);

        // Replay-safe model behavior: same fixture => same idempotency key.
        self::assertSame($first->suggestedWork[0]->idempotencyKey, $second->suggestedWork[0]->idempotencyKey);
    }

    public function test_unmatched_project_is_reported(): void
    {
        $fixture = require __DIR__ . '/../Fixtures/reconciliation_fixture.php';
        $report = (new ReconciliationAnalyzer())->analyze(
            $fixture['assignment_with_project'],
            $fixture['projects'],
            $fixture['issues'],
            $fixture['requirements'],
            $fixture['policy']
        );

        self::assertCount(1, $report->unmatchedProjects);
        self::assertSame('proj_unmatched', $report->unmatchedProjects[0]->id);
    }
}
