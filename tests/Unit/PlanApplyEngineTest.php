<?php

declare(strict_types=1);

namespace Tarrou\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tarrou\Apply\ApplyApproval;
use Tarrou\Apply\ApplyResult;
use Tarrou\Apply\FakeProviderAdapter;
use Tarrou\Apply\PlanApplyEngine;
use Tarrou\Apply\ProviderMutationResult;
use Tarrou\Model\DnsRecord;
use Tarrou\Model\ObservationSnapshot;
use Tarrou\Planner\DnsChangePlanner;
use Tarrou\Policy\PlanningPolicy;

final class PlanApplyEngineTest extends TestCase
{
    public function test_apply_success_with_traceable_evidence_and_convergence(): void
    {
        $plan = $this->planForApexAndWww();
        $provider = new FakeProviderAdapter($plan['initial_records']);
        $engine = new PlanApplyEngine();

        $result = $engine->execute(
            $plan['plan'],
            new ApplyApproval($plan['plan']->hash(), 'ops@example.com', 'approval-1'),
            $provider
        );

        self::assertSame(ApplyResult::STATUS_APPLIED, $result->status);
        self::assertTrue($result->converged);
        self::assertNotSame('', $result->preObservationFingerprint);
        self::assertNotSame('', $result->postObservationFingerprint);
        self::assertNotEmpty($result->completed);
        self::assertNotEmpty($result->rollbackOperations);
    }

    public function test_lost_ack_after_create_reconciles_and_retry_does_not_duplicate_record(): void
    {
        $planData = $this->planForApexAndWww();
        $targetRecord = DnsRecord::fromArray([
            'name' => 'www',
            'type' => 'CNAME',
            'value' => 'example.com',
            'ttl' => 300,
        ]);

        $provider = new FakeProviderAdapter(
            $planData['initial_records'],
            ['create' => [ProviderMutationResult::STATUS_LOST_ACK]]
        );
        $engine = new PlanApplyEngine();
        $approval = new ApplyApproval($planData['plan']->hash(), 'ops@example.com', 'approval-2');

        $first = $engine->execute($planData['plan'], $approval, $provider);
        self::assertNotEmpty($first->completed);
        self::assertSame(1, $provider->mutationCountForRecord($targetRecord), 'create should apply exactly once');

        $retry = $engine->execute($planData['plan'], $approval, $provider, $first);
        self::assertSame(1, $provider->mutationCountForRecord($targetRecord), 'retry must not duplicate record creation');
        self::assertTrue($retry->converged);
    }

    public function test_no_apply_without_approval_bound_to_exact_plan_hash(): void
    {
        $planData = $this->planForApexAndWww();
        $provider = new FakeProviderAdapter($planData['initial_records']);
        $engine = new PlanApplyEngine();

        $result = $engine->execute(
            $planData['plan'],
            new ApplyApproval('not-the-plan-hash', 'ops@example.com', 'approval-3'),
            $provider
        );

        self::assertSame(ApplyResult::STATUS_BLOCKED, $result->status);
        self::assertNotEmpty($result->blocked);
        self::assertSame('approval_hash_mismatch', $result->blocked[0]->providerCode);
    }

    public function test_stale_observation_blocks_apply(): void
    {
        $policy = PlanningPolicy::fromArray(['managed_types' => ['A'], 'freshness_ttl_seconds' => 10]);
        $desired = [DnsRecord::fromArray(['name' => '@', 'type' => 'A', 'value' => '203.0.113.9', 'ttl' => 300])];
        $observation = ObservationSnapshot::fromArray(
            [['name' => '@', 'type' => 'A', 'value' => '203.0.113.5', 'ttl' => 300]],
            true,
            null
        );
        $plan = (new DnsChangePlanner())->plan('example.com', $desired, $observation, $policy);

        $result = (new PlanApplyEngine())->execute(
            $plan,
            new ApplyApproval($plan->hash(), 'ops@example.com', 'approval-4'),
            new FakeProviderAdapter($observation->records)
        );

        self::assertSame(ApplyResult::STATUS_BLOCKED, $result->status);
        self::assertSame('stale_observation', $result->blocked[0]->providerCode);
    }

    public function test_provider_drift_since_planning_blocks_apply(): void
    {
        $planData = $this->planForApexAndWww();
        $provider = new FakeProviderAdapter($planData['initial_records']);
        $provider->injectRecord(DnsRecord::fromArray([
            'name' => 'drift',
            'type' => 'A',
            'value' => '192.0.2.199',
            'ttl' => 300,
        ]));

        $result = (new PlanApplyEngine())->execute(
            $planData['plan'],
            new ApplyApproval($planData['plan']->hash(), 'ops@example.com', 'approval-5'),
            $provider
        );

        self::assertSame(ApplyResult::STATUS_BLOCKED, $result->status);
        self::assertSame('provider_state_drifted', $result->blocked[0]->providerCode);
    }

    public function test_partial_failure_exposes_completed_pending_and_rollback(): void
    {
        $planData = $this->planForApexAndWww();
        $provider = new FakeProviderAdapter(
            $planData['initial_records'],
            ['update' => [ProviderMutationResult::STATUS_ACK], 'create' => [ProviderMutationResult::STATUS_REJECTED]]
        );

        $result = (new PlanApplyEngine())->execute(
            $planData['plan'],
            new ApplyApproval($planData['plan']->hash(), 'ops@example.com', 'approval-6'),
            $provider
        );

        self::assertSame(ApplyResult::STATUS_PARTIAL, $result->status);
        self::assertNotEmpty($result->completed);
        self::assertNotEmpty($result->pending);
        self::assertEmpty($result->blocked);
        self::assertNotEmpty($result->rollbackOperations);
    }

    public function test_destructive_rejection_blocks_and_retry_does_not_blindly_replay(): void
    {
        $policy = PlanningPolicy::fromArray(['managed_types' => ['A']]);
        $desired = [];
        $observation = ObservationSnapshot::fromArray(
            [['name' => '@', 'type' => 'A', 'value' => '203.0.113.7', 'ttl' => 300]],
            true,
            new DateTimeImmutable('+5 minutes')
        );
        $plan = (new DnsChangePlanner())->plan('example.com', $desired, $observation, $policy);
        $provider = new FakeProviderAdapter($observation->records, ['delete' => [ProviderMutationResult::STATUS_REJECTED]]);
        $engine = new PlanApplyEngine();
        $approval = new ApplyApproval($plan->hash(), 'ops@example.com', 'approval-7');

        $first = $engine->execute($plan, $approval, $provider);
        self::assertSame(ApplyResult::STATUS_BLOCKED, $first->status);
        self::assertNotEmpty($first->blocked);

        $retry = $engine->execute($plan, $approval, $provider, $first);
        self::assertSame(ApplyResult::STATUS_BLOCKED, $retry->status);
        self::assertSame('destructive_retry_blocked', $retry->blocked[0]->providerCode);
    }

    /**
     * @return array{plan: \Tarrou\Model\ChangePlan, initial_records: list<DnsRecord>}
     */
    private function planForApexAndWww(): array
    {
        $policy = PlanningPolicy::fromArray([
            'managed_types' => ['A', 'CNAME'],
            'freshness_ttl_seconds' => 600,
        ]);
        $desired = [
            DnsRecord::fromArray(['name' => '@', 'type' => 'A', 'value' => '198.51.100.10', 'ttl' => 300]),
            DnsRecord::fromArray(['name' => 'www', 'type' => 'CNAME', 'value' => 'example.com', 'ttl' => 300]),
        ];
        $initial = [
            DnsRecord::fromArray(['name' => '@', 'type' => 'A', 'value' => '198.51.100.5', 'ttl' => 600]),
        ];

        $observation = new ObservationSnapshot($initial, true, new DateTimeImmutable('+5 minutes'));
        $plan = (new DnsChangePlanner())->plan('example.com', $desired, $observation, $policy);

        return ['plan' => $plan, 'initial_records' => $initial];
    }
}
