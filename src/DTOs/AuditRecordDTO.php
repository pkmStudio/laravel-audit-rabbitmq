<?php

declare(strict_types=1);

namespace PkmStudio\Audit\DTOs;

use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * DTO записи аудита, передаваемой между приложением и RabbitMQ.
 *
 * Используется producer-гранью пакета для формирования wire-payload и
 * consumer-гранью пакета для восстановления payload во входящий объект.
 */
final readonly class AuditRecordDTO implements JsonSerializable
{
    /**
     * Создаёт DTO записи аудита.
     *
     * Шаги:
     * 1. Принимает идентификаторы audit/auditable-сущностей.
     * 2. Сохраняет контекст источника, пользователя и HTTP-метаданные.
     * 3. Сохраняет old/new значения и timestamps в типизированном виде.
     *
     * @param  array<int, string>|string|null  $tags
     * @param  array<string, mixed>  $old_values
     * @param  array<string, mixed>  $new_values
     */
    public function __construct(
        public string $audit_id,
        public ?string $dedupe_id,
        public string $event,
        public string $auditable_type,
        public string $auditable_table,
        public int|string|null $auditable_id,
        public string $source,
        public array|string|null $tags,
        public ?int $user_id,
        public ?string $user_type,
        public ?string $url,
        public ?string $ip_address,
        public ?string $user_agent,
        public array $old_values,
        public array $new_values,
        public ?CarbonImmutable $created_at,
        public ?CarbonImmutable $updated_at,
    ) {}

    /**
     * Создаёт DTO из валидированного массива входящего payload.
     *
     * Шаги:
     * 1. Приводит скалярные поля к ожидаемым типам.
     * 2. Нормализует old/new значения к массивам.
     * 3. Парсит created_at/updated_at в CarbonImmutable при наличии значений.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            audit_id: (string) ($data['audit_id'] ?? ''),
            dedupe_id: isset($data['dedupe_id']) ? (string) $data['dedupe_id'] : null,
            event: (string) $data['event'],
            auditable_type: (string) $data['auditable_type'],
            auditable_table: (string) $data['auditable_table'],
            auditable_id: $data['auditable_id'] ?? null,
            source: (string) ($data['source'] ?? 'system'),
            tags: $data['tags'] ?? null,
            user_id: isset($data['user_id']) ? (int) $data['user_id'] : null,
            user_type: isset($data['user_type']) ? (string) $data['user_type'] : null,
            url: isset($data['url']) ? (string) $data['url'] : null,
            ip_address: isset($data['ip_address']) ? (string) $data['ip_address'] : null,
            user_agent: isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            old_values: (array) ($data['old_values'] ?? []),
            new_values: (array) ($data['new_values'] ?? []),
            created_at: isset($data['created_at']) ? CarbonImmutable::parse($data['created_at']) : null,
            updated_at: isset($data['updated_at']) ? CarbonImmutable::parse($data['updated_at']) : null,
        );
    }

    /**
     * Преобразует DTO в wire-формат payload.
     *
     * Шаги:
     * 1. Возвращает поля в snake_case, совместимые с текущим RabbitMQ-контрактом.
     * 2. Сериализует даты в ISO-строки.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'audit_id' => $this->audit_id,
            'dedupe_id' => $this->dedupeId(),
            'event' => $this->event,
            'auditable_type' => $this->auditable_type,
            'auditable_table' => $this->auditable_table,
            'auditable_id' => $this->auditable_id,
            'source' => $this->source,
            'tags' => $this->tags,
            'user_id' => $this->user_id,
            'user_type' => $this->user_type,
            'url' => $this->url,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Сериализует DTO в массив для json_encode().
     *
     * Шаги:
     * 1. Делегирует формирование payload в toArray().
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Возвращает явный ключ идемпотентности входящей audit-записи.
     *
     * Шаги:
     * 1. Использует dedupe_id из payload, если он уже передан.
     * 2. Иначе использует source audit_id, который уникален на стороне producer.
     * 3. Если source audit_id отсутствует, строит стабильный hash натурального ключа.
     */
    public function dedupeId(): string
    {
        if (is_string($this->dedupe_id) && $this->dedupe_id !== '') {
            return $this->dedupe_id;
        }

        if ($this->audit_id !== '') {
            return $this->audit_id;
        }

        return hash('sha256', implode('|', [
            $this->auditable_type,
            (string) $this->auditable_id,
            $this->event,
            $this->created_at?->format('Y-m-d H:i:s.u') ?? 'null',
        ]));
    }
}
