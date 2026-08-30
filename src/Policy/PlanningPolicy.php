<?php

declare(strict_types=1);

namespace Tarrou\Policy;

final class PlanningPolicy
{
    /**
     * @param list<string> $managedTypes
     */
    public function __construct(
        public readonly array $managedTypes,
        public readonly int $freshnessTtlSeconds = 300,
        public readonly ?string $actor = null
    ) {
    }

    public function managesType(string $type): bool
    {
        return in_array(strtoupper($type), $this->managedTypes, true);
    }

    public static function fromArray(array $policy): self
    {
        $managedTypes = array_map(
            static fn (string $type): string => strtoupper($type),
            $policy['managed_types'] ?? []
        );
        sort($managedTypes);

        return new self(
            $managedTypes,
            isset($policy['freshness_ttl_seconds']) ? (int) $policy['freshness_ttl_seconds'] : 300,
            isset($policy['actor']) ? (string) $policy['actor'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'managed_types' => $this->managedTypes,
            'freshness_ttl_seconds' => $this->freshnessTtlSeconds,
            'actor' => $this->actor,
        ];
    }
}
