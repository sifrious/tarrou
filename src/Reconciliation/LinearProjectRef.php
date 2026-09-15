<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

final class LinearProjectRef
{
    public function __construct(
        public readonly string $id,
        public readonly string $name
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name']
        );
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
