<?php

declare(strict_types=1);

namespace Tarrou\Apply;

use Tarrou\Model\ChangeOperation;
use Tarrou\Model\ChangePlan;

final class ApplyResult
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @param list<AppliedOperationEvidence> $completed
     * @param list<AppliedOperationEvidence> $pending
     * @param list<AppliedOperationEvidence> $blocked
     * @param list<ChangeOperation> $rollbackOperations
     */
    public function __construct(
        public readonly string $planHash,
        public readonly string $status,
        public readonly bool $converged,
        public readonly string $preObservationFingerprint,
        public readonly string $postObservationFingerprint,
        public readonly array $completed,
        public readonly array $pending,
        public readonly array $blocked,
        public readonly array $rollbackOperations
    ) {
    }

    public function toArray(): array
    {
        return [
            'plan_hash' => $this->planHash,
            'status' => $this->status,
            'converged' => $this->converged,
            'pre_observation_fingerprint' => $this->preObservationFingerprint,
            'post_observation_fingerprint' => $this->postObservationFingerprint,
            'completed' => array_map(static fn (AppliedOperationEvidence $entry): array => $entry->toArray(), $this->completed),
            'pending' => array_map(static fn (AppliedOperationEvidence $entry): array => $entry->toArray(), $this->pending),
            'blocked' => array_map(static fn (AppliedOperationEvidence $entry): array => $entry->toArray(), $this->blocked),
            'rollback' => array_map(static fn (ChangeOperation $operation): array => $operation->toArray(), $this->rollbackOperations),
        ];
    }
}
