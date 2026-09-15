<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

final class ReconciliationPolicy
{
    public function __construct(
        public readonly string $projectAssociationRule,
        public readonly string $issueLinkRule,
        public readonly string $idempotencyRule,
        public readonly bool $issueCreationRequiresApproval = true
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            projectAssociationRule: 'domain assignment canonical_project_id maps to Linear project.id',
            issueLinkRule: 'stable identifiers are issue.id and issue.identifier',
            idempotencyRule: 'create-intent idempotency key is hash(domain|work_dedupe_key|rule)'
        );
    }
}
