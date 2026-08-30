<?php

declare(strict_types=1);

namespace Tarrou\Apply;

final class ApplyApproval
{
    public function __construct(
        public readonly string $planHash,
        public readonly string $actor,
        public readonly string $approvalId
    ) {
    }
}
