<?php

declare(strict_types=1);

namespace DanCenter\Audit\Services;

use Carbon\CarbonImmutable;
use DanCenter\Audit\Contracts\AuditMessagePublisher;
use DanCenter\Audit\DTOs\AuditRecordDTO;
use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Audit;

/**
 * Сервис формирования payload и доставки записей audits в RabbitMQ.
 */
final readonly class AuditOutboxService
{
    /**
     * Создаёт сервис outbox-доставки аудита.
     *
     * Шаги:
     * 1. Получает контракт publisher через DI-контейнер.
     */
    public function __construct(
        private AuditMessagePublisher $publisher,
    ) {}

    /**
     * Публикует одну запись аудита в RabbitMQ.
     *
     * Шаги:
     * 1. Резолвит routing key в формате {prefix}{table}.{event}.
     * 2. Формирует payload с метаданными и изменениями.
     * 3. Публикует сообщение и получает результат publisher confirms.
     * 4. Обновляет `published_at`/`attempts` или только `attempts`.
     *
     * @return bool true при подтверждённой публикации.
     */
    public function attemptDelivery(Audit $audit): bool
    {
        $routingKey = $this->buildRoutingKey($audit);
        $payload = $this->buildPayload($audit);
        $eventName = $this->eventName();
        $message = new RabbitMessageDTO(
            name: $eventName,
            data: $payload->toArray(),
        );

        Log::debug('Audit outbox delivery started.', [
            'audit_id' => $audit->getAttribute('id'),
            'event_name' => $eventName,
            'routing_key' => $routingKey,
        ]);

        $published = $this->publisher->publish($message, $routingKey);
        if (! $published) {
            $this->markFailed($audit);

            Log::warning('Audit publish failed.', [
                'audit_id' => $audit->getAttribute('id'),
                'routing_key' => $routingKey,
            ]);

            return false;
        }

        $this->markPublished($audit);

        Log::debug('Audit outbox delivery completed.', [
            'audit_id' => $audit->getAttribute('id'),
            'routing_key' => $routingKey,
        ]);

        return true;
    }

    /**
     * Формирует payload для downstream-сервисов из audit-записи.
     *
     * Шаги:
     * 1. Ресолвит таблицу сущности.
     * 2. Ресолвит source из tags.
     * 3. Убирает семантически неизменённые old/new значения.
     * 4. Собирает DTO для передачи через RabbitMQ.
     */
    public function buildPayload(Audit $audit): AuditRecordDTO
    {
        $createdAt = $audit->getAttribute('created_at');
        $updatedAt = $audit->getAttribute('updated_at');

        [$oldValues, $newValues] = $this->stripUnchangedValues(
            $audit->old_values ?? [],
            $audit->new_values ?? [],
            $audit,
        );

        $payload = new AuditRecordDTO(
            audit_id: (string) $audit->getAttribute('id'),
            dedupe_id: (string) $audit->getAttribute('id'),
            event: (string) $audit->event,
            auditable_type: (string) $audit->auditable_type,
            auditable_table: $this->resolveAuditableTable($audit),
            auditable_id: $audit->auditable_id,
            source: $this->resolveSource($audit),
            tags: $audit->tags,
            user_id: $audit->user_id !== null ? (int) $audit->user_id : null,
            user_type: $audit->user_type,
            url: $audit->url,
            ip_address: $audit->ip_address,
            user_agent: $audit->user_agent,
            old_values: $oldValues,
            new_values: $newValues,
            created_at: $createdAt !== null ? CarbonImmutable::parse($createdAt) : null,
            updated_at: $updatedAt !== null ? CarbonImmutable::parse($updatedAt) : null,
        );

        Log::debug('Audit payload built.', [
            'audit_id' => $payload->audit_id,
            'event' => $payload->event,
            'auditable_table' => $payload->auditable_table,
            'source' => $payload->source,
            'old_keys' => array_keys($payload->old_values),
            'new_keys' => array_keys($payload->new_values),
        ]);

        return $payload;
    }

    /**
     * Убирает из old/new атрибуты, которые семантически не изменились.
     *
     * Шаги:
     * 1. Перебирает ключи, присутствующие и в old, и в new.
     * 2. Канонизирует обе стороны и сравнивает строгим `===`.
     * 3. Совпавшие ключи удаляет из обоих наборов.
     * 4. Логирует список отброшенных no-op ключей.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function stripUnchangedValues(array $oldValues, array $newValues, Audit $audit): array
    {
        $removed = [];

        foreach (array_keys($oldValues) as $key) {
            if (! array_key_exists($key, $newValues)) {
                continue;
            }

            if ($this->canonicalize($oldValues[$key]) === $this->canonicalize($newValues[$key])) {
                unset($oldValues[$key], $newValues[$key]);
                $removed[] = $key;
            }
        }

        if ($removed !== []) {
            Log::debug('Audit unchanged values stripped from payload.', [
                'audit_id' => $audit->getAttribute('id'),
                'keys' => $removed,
            ]);
        }

        return [$oldValues, $newValues];
    }

    /**
     * Приводит значение аудита к канонической форме для сравнения.
     *
     * Шаги:
     * 1. JSON-строку декодирует в массив, если это возможно.
     * 2. Массив рекурсивно канонизирует.
     * 3. Сортирует ключи массива для стабильного сравнения.
     * 4. Скаляры возвращает без изменений.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (is_array($value)) {
            $canonical = [];
            foreach ($value as $key => $item) {
                $canonical[$key] = $this->canonicalize($item);
            }
            ksort($canonical);

            return $canonical;
        }

        return $value;
    }

    /**
     * Ресолвит routing key для RabbitMQ.
     *
     * Шаги:
     * 1. Ресолвит имя таблицы audited-сущности.
     * 2. Берёт event из audit-записи.
     * 3. Формирует routing key из audit-transport.routing_key_prefix.
     */
    private function buildRoutingKey(Audit $audit): string
    {
        $table = $this->resolveAuditableTable($audit);
        $event = (string) $audit->event;
        $prefix = (string) config('audit-transport.routing_key_prefix', 'crm.audit.');
        $normalizedPrefix = $prefix === '' ? '' : rtrim($prefix, '.').'.';

        return sprintf('%s%s.%s', $normalizedPrefix, $table, $event);
    }

    /**
     * Ресолвит имя таблицы auditable-объекта.
     *
     * Шаги:
     * 1. Читает auditable_type без загрузки morph-relation.
     * 2. Если class-string существует — пытается взять таблицу у relation/model instance.
     * 3. Если class-string не существует — fallback по имени класса.
     */
    private function resolveAuditableTable(Audit $audit): string
    {
        $auditableType = (string) $audit->auditable_type;
        if ($auditableType !== '' && class_exists($auditableType) && is_subclass_of($auditableType, Model::class)) {
            $auditable = $audit->auditable;
            if ($auditable instanceof Model) {
                return $auditable->getTable();
            }

            return (string) (new $auditableType())->getTable();
        }

        return (string) Str::of($auditableType)
            ->afterLast('\\')
            ->snake()
            ->plural()
            ->lower();
    }

    /**
     * Обновляет счётчик попыток после неуспеха публикации.
     *
     * Шаги:
     * 1. Приводит attempts к int.
     * 2. Добавляет одну попытку.
     * 3. Сохраняет audit-запись через Eloquent update().
     */
    private function markFailed(Audit $audit): void
    {
        $attempts = ((int) $audit->attempts) + 1;

        $audit->update([
            'attempts' => $attempts,
        ]);

        Log::debug('Audit outbox record marked as failed attempt.', [
            'audit_id' => $audit->getAttribute('id'),
            'attempts' => $attempts,
        ]);
    }

    /**
     * Помечает audit как доставленный.
     *
     * Шаги:
     * 1. Пишет confirmed time в published_at.
     * 2. Инкрементирует attempts.
     * 3. Сохраняет audit-запись через Eloquent update().
     */
    private function markPublished(Audit $audit): void
    {
        $attempts = ((int) $audit->attempts) + 1;

        $audit->update([
            'published_at' => now(),
            'attempts' => $attempts,
        ]);

        Log::debug('Audit outbox record marked as published.', [
            'audit_id' => $audit->getAttribute('id'),
            'attempts' => $attempts,
        ]);
    }

    /**
     * Ресолвит источник изменений из tags.
     *
     * Шаги:
     * 1. Берёт первую непустую строку из тегов как источник.
     * 2. Возвращает `system`, если источника нет.
     */
    private function resolveSource(Audit $audit): string
    {
        $source = Arr::first(
            Arr::wrap($audit->tags),
            static fn (mixed $tag): bool => is_string($tag) && trim($tag) !== '',
            null,
        );

        if (is_string($source) && $source !== '') {
            return $source;
        }

        return 'system';
    }

    /**
     * Возвращает логическое имя audit-события для поля body.name.
     *
     * Шаги:
     * 1. Читает audit-transport.event_name.
     * 2. Использует AUDIT_RECORDED как fallback при пустом значении.
     */
    private function eventName(): string
    {
        $eventName = (string) config('audit-transport.event_name', 'AUDIT_RECORDED');

        return $eventName !== '' ? $eventName : 'AUDIT_RECORDED';
    }
}
