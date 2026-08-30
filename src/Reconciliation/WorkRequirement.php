<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

final class WorkRequirement
{
    public function __construct(
        public readonly string $name,
        public readonly string $workDedupeKey,
        public readonly string $evidence,
        public readonly string $rule
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            workDedupeKey: (string) $data['work_dedupe_key'],
            evidence: (string) $data['evidence'],
            rule: (string) $data['rule']
        );
    }
}
