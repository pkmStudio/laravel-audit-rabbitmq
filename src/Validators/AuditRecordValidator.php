<?php

declare(strict_types=1);

namespace PkmStudio\Audit\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Валидатор входящего payload записи аудита.
 */
final readonly class AuditRecordValidator
{
    /**
     * Валидирует входящий payload записи аудита.
     *
     * Шаги:
     * 1. Требует ключевые поля сущности: event, auditable_type, auditable_table, auditable_id.
     * 2. Допускает служебные поля метаданных как nullable.
     * 3. Требует old_values/new_values как присутствующие массивы.
     * 4. Возвращает валидированный массив для AuditRecordDTO.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $data): array
    {
        return Validator::make($data, [
            'audit_id' => 'nullable|string|max:255',
            'dedupe_id' => 'nullable|string|max:255',
            'event' => 'required|string|max:255',
            'auditable_type' => 'required|string|max:255',
            'auditable_table' => 'required|string|max:255',
            'auditable_id' => 'required',
            'source' => 'nullable|string|max:255',
            'tags' => 'nullable',
            'user_id' => 'nullable|integer',
            'user_type' => 'nullable|string|max:255',
            'url' => 'nullable|string',
            'ip_address' => 'nullable|string|max:45',
            'user_agent' => 'nullable|string|max:1023',
            'old_values' => 'present|array',
            'new_values' => 'present|array',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ])->validate();
    }
}
