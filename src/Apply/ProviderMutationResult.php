<?php

declare(strict_types=1);

namespace Tarrou\Apply;

final class ProviderMutationResult
{
    public const STATUS_ACK = 'ack';
    public const STATUS_LOST_ACK = 'lost_ack';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly ?string $detail = null
    ) {
    }
}
