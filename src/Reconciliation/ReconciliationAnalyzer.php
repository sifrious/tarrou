<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

use Tarrou\Support\Canonicalizer;

final class ReconciliationAnalyzer
{
    /**
     * @param list<LinearProjectRef> $projects
     * @param list<LinearIssueRef> $issues
     * @param list<WorkRequirement> $requirements
     */
    public function analyze(
        DomainAssignment $assignment,
        array $projects,
        array $issues,
        array $requirements,
        ReconciliationPolicy $policy
    ): ReconciliationReport {
        $projectById = [];
        foreach ($projects as $project) {
            $projectById[$project->id] = $project;
        }

        $canonical = null;
        if ($assignment->canonicalProjectId !== null && isset($projectById[$assignment->canonicalProjectId])) {
            $canonical = $projectById[$assignment->canonicalProjectId];
        }

        $unmatchedProjects = [];
        foreach ($projects as $project) {
            if ($canonical === null || $project->id !== $canonical->id) {
                $unmatchedProjects[] = $project;
            }
        }
        usort($unmatchedProjects, static fn (LinearProjectRef $a, LinearProjectRef $b): int => $a->id <=> $b->id);

        $duplicates = [];
        $issueIdsByDedupe = [];
        foreach ($issues as $issue) {
            $issueIdsByDedupe[$issue->workDedupeKey][] = $issue->id;
        }
        ksort($issueIdsByDedupe);
        foreach ($issueIdsByDedupe as $dedupeKey => $issueIds) {
            if (count($issueIds) > 1) {
                sort($issueIds);
                $duplicates[] = ['work_dedupe_key' => $dedupeKey, 'issue_ids' => $issueIds];
            }
        }

        usort($issues, static fn (LinearIssueRef $a, LinearIssueRef $b): int => $a->id <=> $b->id);

        $requirementsByDedupe = [];
        foreach ($requirements as $requirement) {
            $requirementsByDedupe[$requirement->workDedupeKey] = $requirement;
        }
        ksort($requirementsByDedupe);

        $existingIssueByDedupe = [];
        foreach ($issues as $issue) {
            $existingIssueByDedupe[$issue->workDedupeKey] = $issue;
        }

        $suggested = [];
        foreach ($requirementsByDedupe as $dedupeKey => $requirement) {
            if (isset($existingIssueByDedupe[$dedupeKey])) {
                continue;
            }

            $suggested[] = new IssueCreationIntent(
                domain: $assignment->domain,
                name: $requirement->name,
                workDedupeKey: $requirement->workDedupeKey,
                idempotencyKey: $this->idempotencyKey($assignment->domain, $requirement->workDedupeKey, $policy->idempotencyRule),
                approvalRequired: $policy->issueCreationRequiresApproval,
                rule: $requirement->rule,
                evidence: $requirement->evidence
            );
        }

        return new ReconciliationReport(
            domain: $assignment->domain,
            canonicalProject: $canonical,
            explicitNoProject: $assignment->canonicalProjectId === null,
            unmatchedProjects: $unmatchedProjects,
            linkedIssues: $issues,
            duplicates: $duplicates,
            suggestedWork: $suggested
        );
    }

    private function idempotencyKey(string $domain, string $workDedupeKey, string $rule): string
    {
        return hash('sha256', Canonicalizer::encode([
            'domain' => $domain,
            'work_dedupe_key' => $workDedupeKey,
            'rule' => $rule,
        ]));
    }
}
