<?php

declare(strict_types=1);

namespace Tarrou\Apply;

use Tarrou\Model\ChangeOperation;
use Tarrou\Model\ChangePlan;
use Tarrou\Model\DnsRecord;
use Tarrou\Model\ObservationSnapshot;
use Tarrou\Planner\DnsChangePlanner;
use Tarrou\Support\Canonicalizer;

final class PlanApplyEngine
{
    public function execute(
        ChangePlan $plan,
        ApplyApproval $approval,
        FakeProviderAdapter $provider,
        ?ApplyResult $previous = null
    ): ApplyResult {
        $completed = [];
        $pending = [];
        $blocked = [];

        if ($approval->planHash !== $plan->hash()) {
            $blocked[] = new AppliedOperationEvidence(
                index: -1,
                operationKind: 'approval',
                operationFingerprint: $plan->hash(),
                idempotencyKey: 'n/a',
                state: 'blocked',
                providerCode: 'approval_hash_mismatch',
                note: 'approval must bind to exact plan hash'
            );

            $currentFingerprint = $this->observationFingerprint($provider->observe($plan->zone));

            return new ApplyResult(
                planHash: $plan->hash(),
                status: ApplyResult::STATUS_BLOCKED,
                converged: false,
                preObservationFingerprint: $currentFingerprint,
                postObservationFingerprint: $currentFingerprint,
                completed: [],
                pending: [],
                blocked: $blocked,
                rollbackOperations: []
            );
        }

        if (!$plan->observationFresh) {
            $blocked[] = new AppliedOperationEvidence(
                index: -1,
                operationKind: 'precondition',
                operationFingerprint: $plan->observationFingerprint,
                idempotencyKey: 'n/a',
                state: 'blocked',
                providerCode: 'stale_observation',
                note: 'observation must be fresh at apply time'
            );

            $currentFingerprint = $this->observationFingerprint($provider->observe($plan->zone));

            return new ApplyResult(
                planHash: $plan->hash(),
                status: ApplyResult::STATUS_BLOCKED,
                converged: false,
                preObservationFingerprint: $currentFingerprint,
                postObservationFingerprint: $currentFingerprint,
                completed: [],
                pending: [],
                blocked: $blocked,
                rollbackOperations: []
            );
        }

        $preObservation = $provider->observe($plan->zone);
        $preFingerprint = $this->observationFingerprint($preObservation);
        $isRetry = $previous !== null && $previous->planHash === $plan->hash();
        if (!$isRetry && $preFingerprint !== $plan->observationFingerprint) {
            $blocked[] = new AppliedOperationEvidence(
                index: -1,
                operationKind: 'precondition',
                operationFingerprint: $preFingerprint,
                idempotencyKey: 'n/a',
                state: 'blocked',
                providerCode: 'provider_state_drifted',
                note: 'provider state changed since planning'
            );

            return new ApplyResult(
                planHash: $plan->hash(),
                status: ApplyResult::STATUS_BLOCKED,
                converged: false,
                preObservationFingerprint: $preFingerprint,
                postObservationFingerprint: $preFingerprint,
                completed: [],
                pending: [],
                blocked: $blocked,
                rollbackOperations: []
            );
        }

        $completedFingerprints = [];
        $pendingByFingerprint = [];
        $blockedByFingerprint = [];
        if ($previous !== null) {
            foreach ($previous->completed as $entry) {
                $completedFingerprints[$entry->operationFingerprint] = true;
            }
            foreach ($previous->pending as $entry) {
                $pendingByFingerprint[$entry->operationFingerprint] = $entry;
            }
            foreach ($previous->blocked as $entry) {
                $blockedByFingerprint[$entry->operationFingerprint] = $entry;
            }
        }

        foreach ($plan->operations as $index => $operation) {
            if (!$this->isMutatingOperation($operation)) {
                continue;
            }

            $fingerprint = $this->operationFingerprint($operation);
            $idempotencyKey = $this->idempotencyKey($plan->hash(), $index, $operation);

            if (isset($completedFingerprints[$fingerprint])) {
                $completed[] = new AppliedOperationEvidence(
                    index: $index,
                    operationKind: $operation->kind,
                    operationFingerprint: $fingerprint,
                    idempotencyKey: $idempotencyKey,
                    state: 'completed',
                    providerCode: 'already_completed'
                );
                continue;
            }

            if (isset($pendingByFingerprint[$fingerprint]) && $this->isDestructiveOperation($operation)) {
                $blocked[] = new AppliedOperationEvidence(
                    index: $index,
                    operationKind: $operation->kind,
                    operationFingerprint: $fingerprint,
                    idempotencyKey: $idempotencyKey,
                    state: 'blocked',
                    providerCode: 'destructive_retry_blocked',
                    note: 'destructive operation requires manual review before retry'
                );
                continue;
            }

            if (isset($blockedByFingerprint[$fingerprint]) && $this->isDestructiveOperation($operation)) {
                $blocked[] = new AppliedOperationEvidence(
                    index: $index,
                    operationKind: $operation->kind,
                    operationFingerprint: $fingerprint,
                    idempotencyKey: $idempotencyKey,
                    state: 'blocked',
                    providerCode: 'destructive_retry_blocked',
                    note: 'destructive operation was previously blocked and cannot be replayed automatically'
                );
                continue;
            }

            $providerResult = $provider->mutate($operation, $idempotencyKey);
            if ($providerResult->status === ProviderMutationResult::STATUS_ACK) {
                $completed[] = new AppliedOperationEvidence(
                    index: $index,
                    operationKind: $operation->kind,
                    operationFingerprint: $fingerprint,
                    idempotencyKey: $idempotencyKey,
                    state: 'completed',
                    providerCode: $providerResult->code
                );
                continue;
            }

            if ($providerResult->status === ProviderMutationResult::STATUS_LOST_ACK) {
                $postMutationObservation = $provider->observe($plan->zone);
                if ($this->isOperationConverged($operation, $postMutationObservation)) {
                    $completed[] = new AppliedOperationEvidence(
                        index: $index,
                        operationKind: $operation->kind,
                        operationFingerprint: $fingerprint,
                        idempotencyKey: $idempotencyKey,
                        state: 'completed',
                        providerCode: 'reconciled_after_lost_ack'
                    );
                } else {
                    $pending[] = new AppliedOperationEvidence(
                        index: $index,
                        operationKind: $operation->kind,
                        operationFingerprint: $fingerprint,
                        idempotencyKey: $idempotencyKey,
                        state: 'pending',
                        providerCode: 'lost_ack_pending_reconciliation'
                    );
                }
                continue;
            }

            if ($this->isDestructiveOperation($operation)) {
                $blocked[] = new AppliedOperationEvidence(
                    index: $index,
                    operationKind: $operation->kind,
                    operationFingerprint: $fingerprint,
                    idempotencyKey: $idempotencyKey,
                    state: 'blocked',
                    providerCode: $providerResult->code,
                    note: 'destructive operation rejected'
                );
                continue;
            }

            $pending[] = new AppliedOperationEvidence(
                index: $index,
                operationKind: $operation->kind,
                operationFingerprint: $fingerprint,
                idempotencyKey: $idempotencyKey,
                state: 'pending',
                providerCode: $providerResult->code
            );
        }

        $postObservation = $provider->observe($plan->zone);
        $postFingerprint = $this->observationFingerprint($postObservation);
        $converged = $this->isPlanConverged($plan, $postObservation);

        $status = ApplyResult::STATUS_APPLIED;
        if ($blocked !== []) {
            $status = ApplyResult::STATUS_BLOCKED;
        } elseif ($pending !== [] || !$converged) {
            $status = ApplyResult::STATUS_PARTIAL;
        }

        return new ApplyResult(
            planHash: $plan->hash(),
            status: $status,
            converged: $converged,
            preObservationFingerprint: $preFingerprint,
            postObservationFingerprint: $postFingerprint,
            completed: $completed,
            pending: $pending,
            blocked: $blocked,
            rollbackOperations: $this->buildRollback($plan, $completed)
        );
    }

    private function isMutatingOperation(ChangeOperation $operation): bool
    {
        return in_array($operation->kind, ['create', 'update', 'delete', 'delegation_update', 'delegation_delete'], true);
    }

    private function isDestructiveOperation(ChangeOperation $operation): bool
    {
        return in_array($operation->kind, ['delete', 'delegation_delete'], true);
    }

    private function idempotencyKey(string $planHash, int $index, ChangeOperation $operation): string
    {
        return hash('sha256', $planHash . '|' . $index . '|' . $operation->sortKey());
    }

    private function operationFingerprint(ChangeOperation $operation): string
    {
        return hash('sha256', Canonicalizer::encode($operation->toArray()));
    }

    private function observationFingerprint(ObservationSnapshot $observation): string
    {
        $records = $observation->records;
        usort($records, static fn (DnsRecord $left, DnsRecord $right): int => $left->key() <=> $right->key());
        $normalized = array_map(static fn (DnsRecord $record): array => $record->toArray(), $records);

        return hash('sha256', Canonicalizer::encode($normalized));
    }

    private function isOperationConverged(ChangeOperation $operation, ObservationSnapshot $observation): bool
    {
        if ($operation->after !== null) {
            foreach ($observation->records as $record) {
                if ($record->equals($operation->after)) {
                    return true;
                }
            }
            return false;
        }

        if ($operation->before === null) {
            return true;
        }

        foreach ($observation->records as $record) {
            if ($record->equals($operation->before)) {
                return false;
            }
        }

        return true;
    }

    private function isPlanConverged(ChangePlan $plan, ObservationSnapshot $observation): bool
    {
        $replanned = (new DnsChangePlanner())->plan($plan->zone, $plan->desiredRecords, $observation, $plan->policy);

        foreach ($replanned->operations as $operation) {
            if (!in_array($operation->classification, [ChangeOperation::CLASS_NOOP, ChangeOperation::CLASS_PRESERVE], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<AppliedOperationEvidence> $completed
     * @return list<ChangeOperation>
     */
    private function buildRollback(ChangePlan $plan, array $completed): array
    {
        $byFingerprint = [];
        foreach ($completed as $entry) {
            $byFingerprint[$entry->operationFingerprint] = true;
        }

        $rollback = [];
        foreach ($plan->operations as $operation) {
            $fingerprint = $this->operationFingerprint($operation);
            if (!isset($byFingerprint[$fingerprint])) {
                continue;
            }

            if ($operation->kind === 'create' || $operation->kind === 'delegation_update') {
                $rollback[] = new ChangeOperation(
                    kind: $operation->kind === 'create' ? 'delete' : 'delegation_delete',
                    classification: ChangeOperation::CLASS_DESTRUCTIVE,
                    before: $operation->after,
                    after: null,
                    policyReason: 'rollback evidence from completed create/delegation update',
                    blocksApply: false
                );
                continue;
            }

            if ($operation->kind === 'delete' || $operation->kind === 'delegation_delete') {
                $rollback[] = new ChangeOperation(
                    kind: $operation->kind === 'delete' ? 'create' : 'delegation_update',
                    classification: ChangeOperation::CLASS_ADDITIVE,
                    before: null,
                    after: $operation->before,
                    policyReason: 'rollback evidence from completed delete/delegation delete',
                    blocksApply: false
                );
                continue;
            }

            if ($operation->kind === 'update') {
                $rollback[] = new ChangeOperation(
                    kind: 'update',
                    classification: ChangeOperation::CLASS_REPLACING,
                    before: $operation->after,
                    after: $operation->before,
                    policyReason: 'rollback evidence from completed update',
                    blocksApply: false
                );
            }
        }

        usort($rollback, static fn (ChangeOperation $left, ChangeOperation $right): int => $left->sortKey() <=> $right->sortKey());

        return $rollback;
    }
}
