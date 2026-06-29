<?php

declare(strict_types=1);

use PkmStudio\Audit\Models\Audit;
use PkmStudio\Audit\Services\AuditOutboxService;
use PkmStudio\Audit\Tests\Fakes\FakeAuditMessagePublisher;

it('marks audit as published when rabbit publish is confirmed', function (): void {
    $audit = Audit::query()->create([
        'event' => 'updated',
        'auditable_type' => 'App\\Models\\Warehouse\\Kit',
        'auditable_id' => 10,
        'old_values' => ['name' => 'Старое'],
        'new_values' => ['name' => 'Новое'],
        'tags' => 'system',
    ]);
    $publisher = new FakeAuditMessagePublisher(true);
    $service = new AuditOutboxService($publisher);

    $published = $service->attemptDelivery($audit);

    $audit->refresh();

    expect($published)->toBeTrue()
        ->and($audit->attempts)->toBe(1)
        ->and($audit->published_at)->not->toBeNull()
        ->and($publisher->published)->toHaveCount(1)
        ->and($publisher->published[0]['routing_key'])->toBe('crm.audit.kits.updated')
        ->and($publisher->published[0]['message']->name)->toBe('AUDIT_RECORDED')
        ->and($publisher->published[0]['message']->data['dedupe_id'])->toBe((string) $audit->id)
        ->and($publisher->published[0]['message']->data['new_values']['name'])->toBe('Новое');
});

it('increments attempts without published_at when rabbit publish fails', function (): void {
    $audit = Audit::query()->create([
        'event' => 'updated',
        'auditable_type' => 'App\\Models\\Warehouse\\Kit',
        'auditable_id' => 11,
        'old_values' => ['name' => 'Старое'],
        'new_values' => ['name' => 'Новое'],
        'tags' => 'system',
    ]);
    $publisher = new FakeAuditMessagePublisher(false);
    $service = new AuditOutboxService($publisher);

    $published = $service->attemptDelivery($audit);

    $audit->refresh();

    expect($published)->toBeFalse()
        ->and($audit->attempts)->toBe(1)
        ->and($audit->published_at)->toBeNull()
        ->and($publisher->published)->toHaveCount(1);
});
