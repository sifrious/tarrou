<?php

declare(strict_types=1);

namespace Tarrou\Apply;

final class AppliedOperationEvidence
{
    public function __construct(
        public readonly int $index,
        public readonly string $operationKind,
        public readonly string $operationFingerprint,
        public readonly string $idempotencyKey,
        public readonly string $state,
        public readonly string $providerCode,
        public readonly ?string $note = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'operation_kind' => $this->operationKind,
            'operation_fingerprint' => $this->operationFingerprint,
            'idempotency_key' => $this->idempotencyKey,
            'state' => $this->state,
            'provider_code' => $this->providerCode,
            'note' => $this->note,
        ];
    }
}
