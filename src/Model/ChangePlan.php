<?php

declare(strict_types=1);

namespace Tarrou\Model;

use Tarrou\Policy\PlanningPolicy;
use Tarrou\Support\Canonicalizer;

final class ChangePlan
{
    /**
     * @param list<ChangeOperation> $operations
     */
    public function __construct(
        public readonly string $zone,
        public readonly array $desiredRecords,
        public readonly array $operations,
        public readonly PlanningPolicy $policy,
        public readonly bool $observationFresh,
        public readonly ?string $observationObservedAtIso,
        public readonly ?string $observationExpiresAtIso,
        public readonly string $observationFingerprint
    ) {
    }

    public function blocksApply(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->blocksApply) {
                return true;
            }
        }

        return false;
    }

    public function hash(): string
    {
        return hash('sha256', Canonicalizer::encode($this->toArray()));
    }

    public function approvalHook(): array
    {
        return [
            'actor' => $this->policy->actor,
            'required' => $this->blocksApply() || $this->hasClassification(ChangeOperation::CLASS_DESTRUCTIVE) || $this->hasClassification(ChangeOperation::CLASS_DELEGATION),
        ];
    }

    public function toArray(): array
    {
        return [
            'zone' => $this->zone,
            'desired_records' => array_map(static fn (DnsRecord $record): array => $record->toArray(), $this->desiredRecords),
            'operations' => array_map(static fn (ChangeOperation $operation): array => $operation->toArray(), $this->operations),
            'policy' => $this->policy->toArray(),
            'observation' => [
                'fresh' => $this->observationFresh,
                'observed_at' => $this->observationObservedAtIso,
                'expires_at' => $this->observationExpiresAtIso,
                'fingerprint' => $this->observationFingerprint,
            ],
            'approval' => $this->approvalHook(),
        ];
    }

    private function hasClassification(string $classification): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->classification === $classification) {
                return true;
            }
        }

        return false;
    }
}
