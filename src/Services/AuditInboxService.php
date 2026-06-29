<?php

declare(strict_types=1);

namespace PkmStudio\Audit\Services;

use PkmStudio\Audit\DTOs\AuditRecordDTO;
use PkmStudio\Audit\Validators\AuditRecordValidator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Audit;

/**
 * Сервис приёма входящих записей аудита из RabbitMQ.
 */
final readonly class AuditInboxService
{
    /**
     * Принимает запись аудита из очереди и сохраняет её в БД.
     *
     * Шаги:
     * 1. Валидирует входной массив отдельным validator-классом.
     * 2. Строит DTO из валидированных данных.
     * 3. Идемпотентно создаёт/обновляет запись аудита.
     * 4. Логирует безопасный контекст успешной/ошибочной обработки.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \Throwable
     */
    public function upsert(array $data): void
    {
        Log::debug('Audit inbox upsert started.', [
            'audit_id' => $data['audit_id'] ?? null,
            'auditable_type' => $data['auditable_type'] ?? null,
            'auditable_id' => $data['auditable_id'] ?? null,
            'event' => $data['event'] ?? null,
        ]);

        try {
            $validated = AuditRecordValidator::validate($data);
            $dto = AuditRecordDTO::fromArray($validated);
            $audit = $this->store($dto);

            Log::debug('Audit inbox upsert completed.', [
                'audit_id' => $audit->getAttribute('id'),
                'source_audit_id' => $dto->audit_id,
                'auditable_type' => $dto->auditable_type,
                'auditable_id' => $dto->auditable_id,
                'event' => $dto->event,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit inbox: failed to store record.', [
                'audit_id' => $data['audit_id'] ?? null,
                'auditable_type' => $data['auditable_type'] ?? null,
                'auditable_id' => $data['auditable_id'] ?? null,
                'event' => $data['event'] ?? null,
                'payload_keys' => array_keys($data),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Идемпотентно сохраняет запись аудита в таблицу audits.
     *
     * Шаги:
     * 1. Резолвит модель аудита из config('audit.implementation').
     * 2. Нормализует tags к строке.
     * 3. Выполняет updateOrCreate по явному dedupe_id из payload.
     * 4. Возвращает сохранённую audit-запись.
     */
    private function store(AuditRecordDTO $dto): Audit
    {
        $auditModelClass = $this->auditModelClass();
        $dedupeId = $dto->dedupeId();

        /** @var Audit $audit */
        $audit = $auditModelClass::query()->updateOrCreate(
            [
                'dedupe_id' => $dedupeId,
            ],
            [
                'auditable_type' => $dto->auditable_type,
                'auditable_id' => $dto->auditable_id,
                'event' => $dto->event,
                'user_type' => $dto->user_type,
                'user_id' => $dto->user_id,
                'old_values' => $dto->old_values,
                'new_values' => $dto->new_values,
                'url' => $dto->url,
                'ip_address' => $dto->ip_address,
                'user_agent' => $dto->user_agent,
                'tags' => $this->normalizeTags($dto->tags),
                'created_at' => $dto->created_at?->toDateTimeString(),
                'updated_at' => $dto->updated_at?->toDateTimeString(),
            ],
        );

        return $audit;
    }

    /**
     * Резолвит class-string модели аудита из конфигурации приложения.
     *
     * Шаги:
     * 1. Читает config('audit.implementation').
     * 2. Проверяет существование класса и реализацию OwenIt audit contract.
     * 3. Возвращает class-string для Eloquent query.
     *
     * @return class-string<Audit>
     */
    private function auditModelClass(): string
    {
        $implementation = config('audit.implementation');

        if (! is_string($implementation) || ! class_exists($implementation) || ! is_subclass_of($implementation, Audit::class)) {
            throw new InvalidArgumentException('Invalid audit.implementation config value.');
        }

        return $implementation;
    }

    /**
     * Приводит tags к строковому виду для строковой колонки `tags`.
     *
     * Шаги:
     * 1. Если tags переданы массивом — соединяет значения через запятую.
     * 2. Если tags уже строка или null — возвращает как есть.
     *
     * @param  array<int, string>|string|null  $tags
     */
    private function normalizeTags(array|string|null $tags): ?string
    {
        if (is_array($tags)) {
            return implode(',', $tags);
        }

        return $tags;
    }
}
