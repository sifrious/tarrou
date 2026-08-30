<?php

declare(strict_types=1);

namespace Tarrou\Model;

use DateTimeImmutable;

final class ObservationSnapshot
{
    /**
     * @param list<DnsRecord> $records
     */
    public function __construct(
        public readonly array $records,
        public readonly bool $complete,
        public readonly ?DateTimeImmutable $observedAt
    ) {
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public static function fromArray(array $records, bool $complete, ?DateTimeImmutable $observedAt): self
    {
        return new self(
            array_map(static fn (array $record): DnsRecord => DnsRecord::fromArray($record), $records),
            $complete,
            $observedAt
        );
    }
}
