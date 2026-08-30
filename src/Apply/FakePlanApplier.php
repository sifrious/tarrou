<?php

declare(strict_types=1);

namespace Tarrou\Apply;

use Tarrou\Model\ChangePlan;

final class FakePlanApplier
{
    public function assertApplicable(ChangePlan $plan): void
    {
        if ($plan->blocksApply()) {
            throw PlanBlockedException::fromPlan($plan);
        }
    }
}
