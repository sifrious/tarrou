<?php

declare(strict_types=1);

use Tarrou\Model\DnsRecord;
use Tarrou\Model\ObservationSnapshot;
use Tarrou\Policy\PlanningPolicy;

return [
    'zone' => 'example.com',
    'desired' => [
        DnsRecord::fromArray([
            'name' => '@',
            'type' => 'A',
            'value' => '198.51.100.10',
            'ttl' => 300,
        ]),
        DnsRecord::fromArray([
            'name' => 'www',
            'type' => 'CNAME',
            'value' => 'example.com',
            'ttl' => 300,
        ]),
    ],
    'observation' => ObservationSnapshot::fromArray(
        records: [
            [
                'name' => '@',
                'type' => 'A',
                'value' => '198.51.100.5',
                'ttl' => 600,
            ],
            [
                'name' => '@',
                'type' => 'TXT',
                'value' => 'google-site-verification=abc123',
                'ttl' => 3600,
            ],
            [
                'name' => '@',
                'type' => 'MX',
                'value' => '10 mail.example.com.',
                'ttl' => 3600,
            ],
        ],
        complete: true,
        observedAt: new DateTimeImmutable('+2 minutes')
    ),
    'policy' => PlanningPolicy::fromArray([
        'managed_types' => ['A', 'CNAME', 'NS'],
        'freshness_ttl_seconds' => 600,
        'actor' => 'test-actor',
    ]),
];
