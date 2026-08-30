<?php

declare(strict_types=1);

namespace Tarrou\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tarrou\Apply\FakePlanApplier;
use Tarrou\Apply\PlanBlockedException;
use Tarrou\Model\DnsRecord;
use Tarrou\Model\ObservationSnapshot;
use Tarrou\Planner\DnsChangePlanner;
use Tarrou\Policy\PlanningPolicy;

final class DnsChangePlannerTest extends TestCase
{
    public function test_fixture_domain_requires_update_create_and_preserve(): void
    {
        $fixture = require __DIR__ . '/../Fixtures/domain_primary_fixture.php';

        $planner = new DnsChangePlanner();
        $plan = $planner->plan(
            $fixture['zone'],
            $fixture['desired'],
            $fixture['observation'],
            $fixture['policy']
        );

        $operationKinds = array_map(static fn ($operation): string => $operation->kind, $plan->operations);
        self::assertContains('update', $operationKinds, 'apex update should be planned');
        self::assertContains('create', $operationKinds, 'www cname create should be planned');
        self::assertContains('preserve', $operationKinds, 'unmanaged records should be preserved');
    }

    public function test_plan_hash_is_deterministic_across_input_order(): void
    {
        $policy = PlanningPolicy::fromArray([
            'managed_types' => ['A', 'CNAME', 'NS'],
            'freshness_ttl_seconds' => 900,
        ]);

        $desiredA = [
            DnsRecord::fromArray(['name' => 'www', 'type' => 'CNAME', 'value' => 'example.com', 'ttl' => 300]),
            DnsRecord::fromArray(['name' => '@', 'type' => 'A', 'value' => '203.0.113.10', 'ttl' => 300]),
        ];
        $desiredB = array_reverse($desiredA);

        $observedA = ObservationSnapshot::fromArray(
            [
                ['name' => '@', 'type' => 'A', 'value' => '203.0.113.9', 'ttl' => 300],
                ['name' => 'www', 'type' => 'CNAME', 'value' => 'old.example.com', 'ttl' => 300],
            ],
            true,
            new DateTimeImmutable('+5 minutes')
        );
        $observedB = ObservationSnapshot::fromArray(
            array_reverse([
                ['name' => '@', 'type' => 'A', 'value' => '203.0.113.9', 'ttl' => 300],
                ['name' => 'www', 'type' => 'CNAME', 'value' => 'old.example.com', 'ttl' => 300],
            ]),
            true,
            new DateTimeImmutable('+5 minutes')
        );

        $planner = new DnsChangePlanner();
        $planA = $planner->plan('example.com', $desiredA, $observedA, $policy);
        $planB = $planner->plan('example.com', $desiredB, $observedB, $policy);

        self::assertSame($planA->hash(), $planB->hash());
        self::assertSame($planA->toArray(), $planB->toArray());
    }

    public function test_destructive_and_delegation_changes_are_classified_separately(): void
    {
        $policy = PlanningPolicy::fromArray(['managed_types' => ['A', 'NS']]);
        $desired = [
            DnsRecord::fromArray(['name' => '@', 'type' => 'A', 'value' => '198.51.100.2', 'ttl' => 300]),
        ];
        $observed = ObservationSnapshot::fromArray(
            [
                ['name' => '@', 'type' => 'A', 'value' => '198.51.100.1', 'ttl' => 300],
                ['name' => '@', 'type' => 'A', 'value' => '198.51.100.3', 'ttl' => 300],
                ['name' => '@', 'type' => 'NS', 'value' => 'ns1.example.net.', 'ttl' => 300],
            ],
            true,
            new DateTimeImmutable('+5 minutes')
        );

        $plan = (new DnsChangePlanner())->plan('example.com', $desired, $observed, $policy);
        $classes = array_map(static fn ($operation): string => $operation->classification, $plan->operations);

        self::assertContains('destructive', $classes);
        self::assertContains('delegation', $classes);
    }

    public function test_conflict_and_incomplete_observation_block_apply(): void
    {
        $policy = PlanningPolicy::fromArray(['managed_types' => ['A', 'CNAME']]);
        $desired = [DnsRecord::fromArray(['name' => 'www', 'type' => 'CNAME', 'value' => 'example.com'])];
        $observed = ObservationSnapshot::fromArray(
            [
                ['name' => 'www', 'type' => 'A', 'value' => '192.0.2.10'],
                ['name' => 'www', 'type' => 'CNAME', 'value' => 'old.example.com'],
            ],
            false,
            new DateTimeImmutable('+5 minutes')
        );

        $plan = (new DnsChangePlanner())->plan('example.com', $desired, $observed, $policy);
        self::assertTrue($plan->blocksApply());

        $operationKinds = array_map(static fn ($operation): string => $operation->kind, $plan->operations);
        self::assertContains('conflict_cname_coexists_with_other_types', $operationKinds);
        self::assertContains('delete_blocked_incomplete_observation', $operationKinds);

        $this->expectException(PlanBlockedException::class);
        (new FakePlanApplier())->assertApplicable($plan);
    }

    public function test_every_operation_cites_before_and_policy_reason(): void
    {
        $fixture = require __DIR__ . '/../Fixtures/domain_primary_fixture.php';
        $plan = (new DnsChangePlanner())->plan(
            $fixture['zone'],
            $fixture['desired'],
            $fixture['observation'],
            $fixture['policy']
        );

        foreach ($plan->operations as $operation) {
            $serialized = $operation->toArray();
            self::assertArrayHasKey('before', $serialized);
            self::assertArrayHasKey('policy_reason', $serialized);
            self::assertNotSame('', $serialized['policy_reason']);
        }
    }

    public function test_already_converged_state_yields_only_noop_and_preserve(): void
    {
        $policy = PlanningPolicy::fromArray(['managed_types' => ['A']]);
        $desired = [DnsRecord::fromArray(['name' => '@', 'type' => 'A', 'value' => '203.0.113.7', 'ttl' => 300])];
        $observed = ObservationSnapshot::fromArray(
            [
                ['name' => '@', 'type' => 'A', 'value' => '203.0.113.7', 'ttl' => 300],
                ['name' => '@', 'type' => 'TXT', 'value' => 'keep-me'],
            ],
            true,
            new DateTimeImmutable('+5 minutes')
        );

        $plan = (new DnsChangePlanner())->plan('example.com', $desired, $observed, $policy);
        $classes = array_map(static fn ($operation): string => $operation->classification, $plan->operations);

        self::assertNotContains('destructive', $classes);
        self::assertNotContains('replacing', $classes);
        self::assertContains('noop', $classes);
        self::assertContains('preserve', $classes);
    }

    public function test_observation_without_time_is_never_fresh_and_blocks(): void
    {
        $policy = PlanningPolicy::fromArray(['managed_types' => ['A']]);
        $desired = [DnsRecord::fromArray(['name' => '@', 'type' => 'A', 'value' => '203.0.113.7'])];
        $observed = ObservationSnapshot::fromArray(
            [['name' => '@', 'type' => 'A', 'value' => '203.0.113.7']],
            true,
            null
        );

        $plan = (new DnsChangePlanner())->plan('example.com', $desired, $observed, $policy);
        self::assertFalse($plan->observationFresh);
        self::assertTrue($plan->blocksApply());
        self::assertTrue($plan->approvalHook()['required']);
    }
}
