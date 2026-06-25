# dan-center/audit

Shared audit grane extracted from the dan-center monolith.

- **Audit model** — owen-it `Audit` with `json:unicode` (readable cyrillic) and a `saving` hook that decodes nested JSON-string columns (no string-in-string).
- **Source tagging** — `ResolvesAuditSource` trait tags records `file|filament|system` from `Context`.
- **Outbox producer** — publishes audit records over `dan-center/rabbit-transport` with routing key `crm.audit.{table}.{event}` and body `name = AUDIT_RECORDED`.
- **Inbox consumer** (app-agnostic) — idempotent upsert into `audits` by natural key; the audit model is resolved from `config('audit.implementation')`.
- **Migrations** — `audits` table, outbox fields, idempotency unique index.

Depends on `dan-center/rabbit-transport` (sibling path package).

PSR-4 namespace: `DanCenter\Audit\`.
