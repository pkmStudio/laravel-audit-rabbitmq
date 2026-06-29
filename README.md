# pkmstudio/audit

> Shared Laravel audit package for producer and consumer services: owen-it audit records, RabbitMQ outbox delivery, idempotent inbox storage and readable JSON payloads.

`pkmstudio/audit` extracts the audit pipeline from the Dan Center monolith into a reusable package. It is built for a two-application setup:

- the CRM writes local `audits` records and publishes them to RabbitMQ;
- the auditor service consumes `AUDIT_RECORDED` messages and stores them in its own PostgreSQL database.

The package depends on:

- `owen-it/laravel-auditing` for Eloquent model auditing;
- `pkmstudio/rabbit-transport` for RabbitMQ publish/consume transport.

## What it provides

| Component | Class | Purpose |
|---|---|---|
| Audit model | `PkmStudio\Audit\Models\Audit` | Owen-it Audit model with `json:unicode` and nested JSON-string decode. |
| Source tags | `PkmStudio\Audit\Traits\ResolvesAuditSource` | Writes `file`, `filament` or `system` into `tags`. |
| Outbox service | `PkmStudio\Audit\Services\AuditOutboxService` | Builds `AUDIT_RECORDED` payloads and publishes them through RabbitMQ. |
| Publisher adapter | `PkmStudio\Audit\Contracts\AuditMessagePublisher` | Allows the audit package to depend on a small publisher contract. |
| Listener | `PkmStudio\Audit\Listeners\PublishAuditRecord` | Handles `OwenIt\Auditing\Events\Audited` after DB commit. |
| Resender command | `audit:resend-failed` | Retries `audits` records where `published_at` is still null. |
| Inbox service | `PkmStudio\Audit\Services\AuditInboxService` | Validates incoming payloads and upserts by `dedupe_id`. |
| DTO | `PkmStudio\Audit\DTOs\AuditRecordDTO` | Stable payload between producer and consumer. |
| Migrations | `database/migrations/*audits*` | `audits` table, outbox fields and `dedupe_id` unique index. |

## Installation

When both packages are published to Packagist:

```bash
composer require pkmstudio/audit:^1.0
```

`pkmstudio/rabbit-transport` is required by this package and will be installed automatically.

Before Packagist, add both GitHub repositories to the consuming app:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/pkmStudio/laravel-rabbitmq-transport"
    },
    {
      "type": "vcs",
      "url": "https://github.com/pkmStudio/laravel-audit-rabbitmq"
    }
  ],
  "require": {
    "pkmstudio/audit": "dev-master",
    "pkmstudio/rabbit-transport": "dev-master"
  }
}
```

For local development with sibling packages:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../packages/rabbit-transport",
      "options": { "symlink": true }
    },
    {
      "type": "path",
      "url": "../packages/audit",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "pkmstudio/audit": "@dev"
  }
}
```

Publish config:

```bash
php artisan vendor:publish --tag=audit-transport-config
```

Run migrations:

```bash
php artisan migrate
```

## Configure owen-it audit

In `config/audit.php`, use the package model:

```php
'implementation' => PkmStudio\Audit\Models\Audit::class,
'console' => env('AUDIT_CONSOLE', true),
```

The package model keeps Cyrillic readable in JSON columns and decodes nested JSON strings produced by array/json-cast Eloquent attributes.

## Producer setup

Use this setup in the application that owns the original models and publishes audit events.

### 1. Make models auditable

```php
use PkmStudio\Audit\Traits\ResolvesAuditSource;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

final class Organization extends Model implements Auditable
{
    use AuditableTrait, ResolvesAuditSource {
        ResolvesAuditSource::generateTags insteadof AuditableTrait;
    }

    protected array $auditInclude = [
        'name',
        'inn',
        'legal_address',
        'status',
    ];
}
```

### 2. Register the listener

Register the listener for owen-it events:

```php
use PkmStudio\Audit\Listeners\PublishAuditRecord;
use OwenIt\Auditing\Events\Audited;

protected $listen = [
    Audited::class => [
        PublishAuditRecord::class,
    ],
];
```

The listener is queued and runs after transaction commit.

### 3. Configure audit transport

`config/audit-transport.php` controls the audit-specific wire contract:

```php
return [
    'routing_key_prefix' => env('AUDIT_ROUTING_KEY_PREFIX', 'crm.audit.'),
    'event_name' => env('AUDIT_EVENT_NAME', 'AUDIT_RECORDED'),
    'source_context_key' => env('AUDIT_SOURCE_CONTEXT_KEY', 'audit_source'),
    'default_console_source' => env('AUDIT_DEFAULT_CONSOLE_SOURCE', 'system'),
    'default_http_source' => env('AUDIT_DEFAULT_HTTP_SOURCE', 'filament'),
    'resend_stale_after_minutes' => (int) env('AUDIT_RESEND_STALE_AFTER_MINUTES', 2),
];
```

Routing key format:

```text
{routing_key_prefix}{auditable_table}.{event}
```

Example:

```text
crm.audit.organizations.updated
```

### 4. Configure RabbitMQ outbound

The producer app also needs `pkmstudio/rabbit-transport` config:

```php
'outbound' => [
    'AUDIT_RECORDED' => 'crm.audit.recorded',
],

'setup' => [
    'exchange' => 'application.events',
    'exchange_type' => 'topic',
    'queue' => 'crm.inbox',
    'bindings' => [
        'crm.inbox',
    ],
],
```

`AuditOutboxService` usually passes a per-message routing key like `crm.audit.organizations.updated`, so the outbound value is only a fallback.

### 5. Schedule the resender

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('audit:resend-failed')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(300);
```

The command retries source `audits` rows with `published_at = null` older than `AUDIT_RESEND_STALE_AFTER_MINUTES`.

## Consumer setup

Use this setup in a separate audit service or any application that stores audit events from RabbitMQ.

### 1. Configure inbound handler

```php
use PkmStudio\Audit\Services\AuditInboxService;

'inbound' => [
    'AUDIT_RECORDED' => [AuditInboxService::class, 'upsert'],
],

'setup' => [
    'exchange' => 'application.events',
    'exchange_type' => 'topic',
    'queue' => 'auditor.audit',
    'bindings' => [
        'crm.audit.#',
    ],
],
```

Then create the topology:

```bash
php artisan rabbit-transport:setup
```

Run the worker:

```bash
php artisan queue:work rabbitmq_inbox --queue=auditor.audit --sleep=1 --tries=3 --timeout=90 --verbose
```

### 2. Keep `audit.implementation` configured

The inbox service resolves the model from `config('audit.implementation')`, so the consumer must also use:

```php
'implementation' => PkmStudio\Audit\Models\Audit::class,
```

## Payload contract

`AuditOutboxService` publishes `RabbitMessageDTO` with:

```json
{
  "name": "AUDIT_RECORDED",
  "data": {
    "audit_id": "123",
    "dedupe_id": "123",
    "event": "updated",
    "auditable_type": "App\\Models\\Organization\\Organization",
    "auditable_table": "organizations",
    "auditable_id": 42,
    "source": "filament",
    "tags": "filament",
    "user_id": 1,
    "user_type": "App\\Models\\User",
    "url": "https://example.test/admin",
    "ip_address": "127.0.0.1",
    "user_agent": "Mozilla/5.0",
    "old_values": { "status": "old" },
    "new_values": { "status": "new" },
    "created_at": "2026-06-27T09:00:00.000000Z",
    "updated_at": "2026-06-27T09:00:00.000000Z"
  }
}
```

`dedupe_id` is the idempotency key. The consumer stores incoming records through:

```php
updateOrCreate(['dedupe_id' => $dedupeId], $attributes);
```

If `dedupe_id` is missing, `AuditRecordDTO` falls back to `audit_id`, then to a stable hash of the natural key.

## Source tags

`ResolvesAuditSource` writes one tag into owen-it `tags`:

| Runtime | Default source |
|---|---|
| HTTP | `filament` |
| Console / queue | `system` |
| Explicit import scope | `file` |

For an import or any file-driven operation:

```php
use Illuminate\Support\Facades\Context;

Context::scope(['audit_source' => 'file'], function (): void {
    // import/update audited models here
});
```

## Commands

```bash
php artisan audit:resend-failed
```

Retries pending outbox rows on the producer side.

Consumer-only services can add their own retention command. In Dan Center, the `auditor` service provides `audit:prune` outside this package because retention belongs to the consumer application.

## Testing

```bash
composer install
vendor/bin/pest
```

Current package tests cover:

- outbox publish success/failure;
- idempotent inbox upsert by `dedupe_id`;
- source tag resolving.

## Versioning

Tag releases and update consuming apps through Composer:

```bash
git tag v1.0.0
git push origin v1.0.0
composer update pkmstudio/audit pkmstudio/rabbit-transport
```

## License

The package is currently marked as `proprietary` in `composer.json`. Change the license before publishing as open source.
