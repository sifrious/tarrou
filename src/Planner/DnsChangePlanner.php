<?php

declare(strict_types=1);

namespace Tarrou\Planner;

use DateInterval;
use Tarrou\Model\ChangeOperation;
use Tarrou\Model\ChangePlan;
use Tarrou\Model\DnsRecord;
use Tarrou\Model\ObservationSnapshot;
use Tarrou\Policy\PlanningPolicy;

final class DnsChangePlanner
{
    /**
     * @param list<DnsRecord> $desiredRecords
     */
    public function plan(
        string $zone,
        array $desiredRecords,
        ObservationSnapshot $observation,
        PlanningPolicy $policy
    ): ChangePlan {
        $desired = $this->sortRecords($desiredRecords);
        $observed = $this->sortRecords($observation->records);

        $operations = [];
        $operations = array_merge($operations, $this->conflictOperations($desired, $observed));

        $desiredBySet = $this->groupByRecordSet($desired);
        $observedBySet = $this->groupByRecordSet($observed);
        $allKeys = array_unique(array_merge(array_keys($desiredBySet), array_keys($observedBySet)));
        sort($allKeys);

        foreach ($allKeys as $recordSetKey) {
            [$name, $type] = explode('|', $recordSetKey, 2);
            $isManaged = $policy->managesType($type);
            $desiredSet = $desiredBySet[$recordSetKey] ?? [];
            $observedSet = $observedBySet[$recordSetKey] ?? [];

            if (!$isManaged) {
                foreach ($observedSet as $existing) {
                    $operations[] = new ChangeOperation(
                        kind: 'preserve',
                        classification: ChangeOperation::CLASS_PRESERVE,
                        before: $existing,
                        after: $existing,
                        policyReason: 'managed-types fence preserves unmanaged record types',
                        blocksApply: false
                    );
                }
                continue;
            }

            $operations = array_merge(
                $operations,
                $this->diffManagedSet($type, $desiredSet, $observedSet, $observation->complete)
            );
        }

        if (count($operations) === 0) {
            $operations[] = new ChangeOperation(
                kind: 'noop',
                classification: ChangeOperation::CLASS_NOOP,
                before: null,
                after: null,
                policyReason: 'desired state already converged with observation',
                blocksApply: false
            );
        }

        usort(
            $operations,
            static fn (ChangeOperation $left, ChangeOperation $right): int => $left->sortKey() <=> $right->sortKey()
        );

        [$fresh, $expiresAtIso] = $this->freshness($observation, $policy);
        $observedAtIso = $observation->observedAt?->format(DATE_ATOM);

        if (!$fresh) {
            $operations[] = new ChangeOperation(
                kind: 'observation_stale',
                classification: ChangeOperation::CLASS_UNKNOWN,
                before: null,
                after: null,
                policyReason: 'observation is stale or has no timestamp',
                blocksApply: true
            );
        }

        usort(
            $operations,
            static fn (ChangeOperation $left, ChangeOperation $right): int => $left->sortKey() <=> $right->sortKey()
        );

        return new ChangePlan(
            zone: strtolower(rtrim($zone, '.')),
            operations: $operations,
            policy: $policy,
            observationFresh: $fresh,
            observationObservedAtIso: $observedAtIso,
            observationExpiresAtIso: $expiresAtIso
        );
    }

    /**
     * @param list<DnsRecord> $desiredSet
     * @param list<DnsRecord> $observedSet
     * @return list<ChangeOperation>
     */
    private function diffManagedSet(
        string $type,
        array $desiredSet,
        array $observedSet,
        bool $observationComplete
    ): array {
        $operations = [];
        $desiredByKey = [];
        $observedByKey = [];
        foreach ($desiredSet as $record) {
            $desiredByKey[$record->key()] = $record;
        }
        foreach ($observedSet as $record) {
            $observedByKey[$record->key()] = $record;
        }

        $recordClass = $this->classForType($type);

        $exactMatches = array_intersect(array_keys($desiredByKey), array_keys($observedByKey));
        foreach ($exactMatches as $matchKey) {
            $operations[] = new ChangeOperation(
                kind: 'noop',
                classification: ChangeOperation::CLASS_NOOP,
                before: $observedByKey[$matchKey],
                after: $desiredByKey[$matchKey],
                policyReason: 'desired record already converged',
                blocksApply: false
            );
        }

        $unmatchedDesired = array_values(array_filter(
            $desiredSet,
            static fn (DnsRecord $record): bool => !isset($observedByKey[$record->key()])
        ));
        $unmatchedObserved = array_values(array_filter(
            $observedSet,
            static fn (DnsRecord $record): bool => !isset($desiredByKey[$record->key()])
        ));

        $pairedCount = min(count($unmatchedDesired), count($unmatchedObserved));
        for ($index = 0; $index < $pairedCount; $index++) {
            $operations[] = new ChangeOperation(
                kind: 'update',
                classification: ChangeOperation::CLASS_REPLACING,
                before: $unmatchedObserved[$index],
                after: $unmatchedDesired[$index],
                policyReason: 'managed type differs from desired record attributes',
                blocksApply: false
            );
        }

        for ($index = $pairedCount; $index < count($unmatchedDesired); $index++) {
            $operations[] = new ChangeOperation(
                kind: $this->kindForCreateType($type),
                classification: $recordClass,
                before: null,
                after: $unmatchedDesired[$index],
                policyReason: 'managed type missing from observation, create required',
                blocksApply: false
            );
        }

        for ($index = $pairedCount; $index < count($unmatchedObserved); $index++) {
            $existing = $unmatchedObserved[$index];

            if (!$observationComplete) {
                $operations[] = new ChangeOperation(
                    kind: 'delete_blocked_incomplete_observation',
                    classification: ChangeOperation::CLASS_UNKNOWN,
                    before: $existing,
                    after: null,
                    policyReason: 'incomplete observation cannot safely drive deletes',
                    blocksApply: true
                );
                continue;
            }

            $operations[] = new ChangeOperation(
                kind: $this->kindForDeleteType($type),
                classification: $recordClass === ChangeOperation::CLASS_DELEGATION ? ChangeOperation::CLASS_DELEGATION : ChangeOperation::CLASS_DESTRUCTIVE,
                before: $existing,
                after: null,
                policyReason: 'managed type present but absent from desired state',
                blocksApply: false
            );
        }

        return $operations;
    }

    /**
     * @param list<DnsRecord> $records
     * @return list<ChangeOperation>
     */
    private function conflictOperations(array $desired, array $observed): array
    {
        $operations = [];
        $all = array_merge($desired, $observed);
        $byName = [];

        foreach ($all as $record) {
            $byName[$record->name][] = $record;
        }

        foreach ($byName as $name => $recordsForName) {
            $hasCname = false;
            $otherType = false;

            foreach ($recordsForName as $record) {
                if ($record->type === 'CNAME') {
                    $hasCname = true;
                    continue;
                }

                $otherType = true;
            }

            if ($hasCname && $otherType) {
                $operations[] = new ChangeOperation(
                    kind: 'conflict_cname_coexists_with_other_types',
                    classification: ChangeOperation::CLASS_CONFLICT,
                    before: $this->firstRecordByName($observed, $name) ?? $this->firstRecordByName($desired, $name),
                    after: null,
                    policyReason: 'cname must not coexist with other record types at the same name',
                    blocksApply: true
                );
            }
        }

        return $operations;
    }

    /**
     * @param list<DnsRecord> $records
     * @return array<string, list<DnsRecord>>
     */
    private function groupByRecordSet(array $records): array
    {
        $grouped = [];

        foreach ($records as $record) {
            $grouped[$record->recordSetKey()][] = $record;
        }

        foreach ($grouped as $key => $set) {
            usort(
                $set,
                static fn (DnsRecord $left, DnsRecord $right): int => $left->key() <=> $right->key()
            );
            $grouped[$key] = $set;
        }

        return $grouped;
    }

    private function kindForCreateType(string $type): string
    {
        return match ($type) {
            'NS' => 'delegation_update',
            default => 'create',
        };
    }

    private function kindForDeleteType(string $type): string
    {
        return match ($type) {
            'NS' => 'delegation_delete',
            default => 'delete',
        };
    }

    private function classForType(string $type): string
    {
        return match ($type) {
            'NS' => ChangeOperation::CLASS_DELEGATION,
            default => ChangeOperation::CLASS_ADDITIVE,
        };
    }

    /**
     * @param list<DnsRecord> $records
     */
    private function firstRecordByName(array $records, string $name): ?DnsRecord
    {
        foreach ($records as $record) {
            if ($record->name === $name) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param list<DnsRecord> $records
     * @return list<DnsRecord>
     */
    private function sortRecords(array $records): array
    {
        usort($records, static fn (DnsRecord $left, DnsRecord $right): int => $left->key() <=> $right->key());

        return $records;
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function freshness(ObservationSnapshot $observation, PlanningPolicy $policy): array
    {
        if ($observation->observedAt === null) {
            return [false, null];
        }

        $expiresAt = $observation->observedAt->add(new DateInterval('PT' . $policy->freshnessTtlSeconds . 'S'));
        $isFresh = $expiresAt->getTimestamp() >= time();

        return [$isFresh, $expiresAt->format(DATE_ATOM)];
    }
}
