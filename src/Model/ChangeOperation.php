<?php

declare(strict_types=1);

namespace Tarrou\Model;

final class ChangeOperation
{
    public const CLASS_ADDITIVE = 'additive';
    public const CLASS_REPLACING = 'replacing';
    public const CLASS_DESTRUCTIVE = 'destructive';
    public const CLASS_DELEGATION = 'delegation';
    public const CLASS_CONFLICT = 'conflict';
    public const CLASS_UNKNOWN = 'unknown';
    public const CLASS_NOOP = 'noop';
    public const CLASS_PRESERVE = 'preserve';

    public function __construct(
        public readonly string $kind,
        public readonly string $classification,
        public readonly ?DnsRecord $before,
        public readonly ?DnsRecord $after,
        public readonly string $policyReason,
        public readonly bool $blocksApply
    ) {
    }

    public function sortKey(): string
    {
        $beforeKey = $this->before?->key() ?? '';
        $afterKey = $this->after?->key() ?? '';

        return implode('|', [$this->classification, $this->kind, $beforeKey, $afterKey, $this->policyReason]);
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'classification' => $this->classification,
            'before' => $this->before?->toArray(),
            'after' => $this->after?->toArray(),
            'policy_reason' => $this->policyReason,
            'blocks_apply' => $this->blocksApply,
        ];
    }
}
