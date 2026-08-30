<?php

declare(strict_types=1);

namespace Tarrou\Apply;

use RuntimeException;
use Tarrou\Model\ChangePlan;

final class PlanBlockedException extends RuntimeException
{
    public static function fromPlan(ChangePlan $plan): self
    {
        return new self('Plan cannot be applied because it contains blockers. Hash: ' . $plan->hash());
    }
}
