<?php

declare(strict_types=1);

use Tarrou\Reconciliation\DomainAssignment;
use Tarrou\Reconciliation\LinearIssueRef;
use Tarrou\Reconciliation\LinearProjectRef;
use Tarrou\Reconciliation\ReconciliationPolicy;
use Tarrou\Reconciliation\WorkRequirement;

return [
    'policy' => ReconciliationPolicy::defaults(),
    'assignment_with_project' => DomainAssignment::fromArray([
        'domain' => 'example.com',
        'canonical_project_id' => 'proj_dns',
    ]),
    'assignment_no_project' => DomainAssignment::fromArray([
        'domain' => 'noproj.example.com',
        'canonical_project_id' => null,
    ]),
    'projects' => [
        LinearProjectRef::fromArray(['id' => 'proj_dns', 'name' => 'DNS Platform']),
        LinearProjectRef::fromArray(['id' => 'proj_unmatched', 'name' => 'Other Workstream']),
    ],
    'issues' => [
        LinearIssueRef::fromArray([
            'id' => 'lin_100',
            'identifier' => 'MME-1550',
            'title' => 'DNS drift follow-up for example.com',
            'project_id' => 'proj_dns',
            'work_dedupe_key' => 'dns/example.com/drift-followup',
        ]),
        LinearIssueRef::fromArray([
            'id' => 'lin_101',
            'identifier' => 'MME-1551',
            'title' => 'Duplicate drift follow-up',
            'project_id' => 'proj_dns',
            'work_dedupe_key' => 'dns/example.com/drift-followup',
        ]),
    ],
    'requirements' => [
        WorkRequirement::fromArray([
            'name' => 'DNS drift follow-up for example.com',
            'work_dedupe_key' => 'dns/example.com/drift-followup',
            'evidence' => 'existing issue already tracks drift follow-up',
            'rule' => 'if requirement dedupe key exists in linked issues, do not suggest create',
        ]),
        WorkRequirement::fromArray([
            'name' => 'Nameserver hardening follow-up for example.com',
            'work_dedupe_key' => 'dns/example.com/ns-hardening',
            'evidence' => 'plan classifies delegation-sensitive operations requiring tracked follow-up',
            'rule' => 'missing requirement dedupe key should generate approval-gated create intent',
        ]),
    ],
];
