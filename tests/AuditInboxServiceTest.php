<?php

declare(strict_types=1);

use PkmStudio\Audit\Models\Audit;
use PkmStudio\Audit\Services\AuditInboxService;

it('upserts incoming audit records idempotently by dedupe id', function (): void {
    $service = new AuditInboxService();
    $payload = [
        'audit_id' => '100',
        'dedupe_id' => 'crm-audit-100',
        'event' => 'updated',
        'auditable_type' => 'App\\Models\\Warehouse\\Kit',
        'auditable_table' => 'kits',
        'auditable_id' => 10,
        'source' => 'file',
        'tags' => ['file'],
        'user_id' => null,
        'user_type' => null,
        'url' => null,
        'ip_address' => null,
        'user_agent' => null,
        'old_values' => ['name' => 'Старое'],
        'new_values' => ['name' => 'Первое'],
        'created_at' => '2026-06-21T12:00:00.000000Z',
        'updated_at' => '2026-06-21T12:00:01.000000Z',
    ];

    $service->upsert($payload);
    $service->upsert([
        ...$payload,
        'new_values' => ['name' => 'Второе'],
        'updated_at' => '2026-06-21T12:00:02.000000Z',
    ]);

    $audit = Audit::query()->firstOrFail();

    expect(Audit::query()->count())->toBe(1)
        ->and($audit->dedupe_id)->toBe('crm-audit-100')
        ->and($audit->tags)->toBe('file')
        ->and($audit->new_values['name'])->toBe('Второе');
});
