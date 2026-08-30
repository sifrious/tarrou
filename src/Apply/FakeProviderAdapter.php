<?php

declare(strict_types=1);

namespace Tarrou\Apply;

use DateTimeImmutable;
use Tarrou\Model\ChangeOperation;
use Tarrou\Model\DnsRecord;
use Tarrou\Model\ObservationSnapshot;

final class FakeProviderAdapter
{
    /** @var list<DnsRecord> */
    private array $records;

    /** @var array<string, ProviderMutationResult> */
    private array $resultsByIdempotencyKey = [];

    /** @var array<string, list<string>> */
    private array $outcomeScriptByKind;

    /** @var array<string, int> */
    private array $mutationCountByRecordKey = [];

    /**
     * @param list<DnsRecord> $records
     * @param array<string, list<string>> $outcomeScriptByKind
     */
    public function __construct(array $records, array $outcomeScriptByKind = [])
    {
        $this->records = $records;
        $this->outcomeScriptByKind = $outcomeScriptByKind;
    }

    public function observe(string $zone): ObservationSnapshot
    {
        return new ObservationSnapshot(
            records: $this->records,
            complete: true,
            observedAt: new DateTimeImmutable('+10 minutes')
        );
    }

    public function mutate(ChangeOperation $operation, string $idempotencyKey): ProviderMutationResult
    {
        if (isset($this->resultsByIdempotencyKey[$idempotencyKey])) {
            return $this->resultsByIdempotencyKey[$idempotencyKey];
        }

        $outcome = $this->nextOutcomeFor($operation->kind);
        $result = match ($outcome) {
            ProviderMutationResult::STATUS_REJECTED => new ProviderMutationResult(
                status: ProviderMutationResult::STATUS_REJECTED,
                code: 'provider_rejected',
                detail: 'provider rejected mutation'
            ),
            ProviderMutationResult::STATUS_LOST_ACK => $this->applyMutationAndRespond($operation, true),
            default => $this->applyMutationAndRespond($operation, false),
        };

        $this->resultsByIdempotencyKey[$idempotencyKey] = $result;

        return $result;
    }

    public function mutationCountForRecord(DnsRecord $record): int
    {
        return $this->mutationCountByRecordKey[$record->key()] ?? 0;
    }

    public function injectRecord(DnsRecord $record): void
    {
        $this->records[] = $record;
    }

    private function applyMutationAndRespond(ChangeOperation $operation, bool $lostAcknowledgement): ProviderMutationResult
    {
        $this->apply($operation);

        return new ProviderMutationResult(
            status: $lostAcknowledgement ? ProviderMutationResult::STATUS_LOST_ACK : ProviderMutationResult::STATUS_ACK,
            code: $lostAcknowledgement ? 'acknowledgement_lost' : 'applied',
            detail: $lostAcknowledgement ? 'mutation applied but acknowledgement lost' : null
        );
    }

    private function apply(ChangeOperation $operation): void
    {
        if ($operation->kind === 'create' || $operation->kind === 'delegation_update') {
            if ($operation->after !== null && !$this->hasExactRecord($operation->after)) {
                $this->records[] = $operation->after;
                $this->mutationCountByRecordKey[$operation->after->key()] = ($this->mutationCountByRecordKey[$operation->after->key()] ?? 0) + 1;
            }
            return;
        }

        if ($operation->kind === 'update') {
            if ($operation->before !== null) {
                $this->removeExactRecord($operation->before);
            }
            if ($operation->after !== null && !$this->hasExactRecord($operation->after)) {
                $this->records[] = $operation->after;
                $this->mutationCountByRecordKey[$operation->after->key()] = ($this->mutationCountByRecordKey[$operation->after->key()] ?? 0) + 1;
            }
            return;
        }

        if ($operation->kind === 'delete' || $operation->kind === 'delegation_delete') {
            if ($operation->before !== null) {
                $this->removeExactRecord($operation->before);
            }
        }
    }

    private function hasExactRecord(DnsRecord $record): bool
    {
        foreach ($this->records as $existing) {
            if ($existing->equals($record)) {
                return true;
            }
        }

        return false;
    }

    private function removeExactRecord(DnsRecord $record): void
    {
        foreach ($this->records as $index => $existing) {
            if ($existing->equals($record)) {
                unset($this->records[$index]);
            }
        }

        $this->records = array_values($this->records);
    }

    private function nextOutcomeFor(string $kind): string
    {
        if (!isset($this->outcomeScriptByKind[$kind]) || $this->outcomeScriptByKind[$kind] === []) {
            return ProviderMutationResult::STATUS_ACK;
        }

        return array_shift($this->outcomeScriptByKind[$kind]);
    }
}
