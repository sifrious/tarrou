<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

final class IssueCreationIntent
{
    public function __construct(
        public readonly string $domain,
        public readonly string $name,
        public readonly string $workDedupeKey,
        public readonly string $idempotencyKey,
        public readonly bool $approvalRequired,
        public readonly string $rule,
        public readonly string $evidence
    ) {
    }

    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'name' => $this->name,
            'work_dedupe_key' => $this->workDedupeKey,
            'idempotency_key' => $this->idempotencyKey,
            'approval_required' => $this->approvalRequired,
            'rule' => $this->rule,
            'evidence' => $this->evidence,
        ];
    }
}
