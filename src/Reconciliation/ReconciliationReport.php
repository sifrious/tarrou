<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

final class ReconciliationReport
{
    /**
     * @param list<LinearProjectRef> $unmatchedProjects
     * @param list<LinearIssueRef> $linkedIssues
     * @param list<array{work_dedupe_key: string, issue_ids: list<string>}> $duplicates
     * @param list<IssueCreationIntent> $suggestedWork
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?LinearProjectRef $canonicalProject,
        public readonly bool $explicitNoProject,
        public readonly array $unmatchedProjects,
        public readonly array $linkedIssues,
        public readonly array $duplicates,
        public readonly array $suggestedWork
    ) {
    }

    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'canonical_project' => $this->canonicalProject?->toArray(),
            'explicit_no_project' => $this->explicitNoProject,
            'unmatched_projects' => array_map(static fn (LinearProjectRef $project): array => $project->toArray(), $this->unmatchedProjects),
            'linked_issues' => array_map(static fn (LinearIssueRef $issue): array => $issue->toArray(), $this->linkedIssues),
            'duplicates' => $this->duplicates,
            'suggested_work' => array_map(static fn (IssueCreationIntent $intent): array => $intent->toArray(), $this->suggestedWork),
        ];
    }
}
