<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

final class DomainAssignment
{
    public function __construct(
        public readonly string $domain,
        public readonly ?string $canonicalProjectId
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            domain: strtolower(rtrim((string) $data['domain'], '.')),
            canonicalProjectId: isset($data['canonical_project_id']) ? (string) $data['canonical_project_id'] : null
        );
    }
}
