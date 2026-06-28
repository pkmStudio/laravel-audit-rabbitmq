<?php

declare(strict_types=1);

namespace DanCenter\Audit\Models;

use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * Модель записи аудита с читаемым JSON и нормализацией вложенных JSON-строк.
 *
 * Фикс A: `json:unicode` сохраняет old_values/new_values без unicode escaping.
 * Фикс B: saving-хук декодирует array/json-cast значения, пришедшие от owen-it
 * как JSON-строки, до записи в БД.
 */
class Audit extends BaseAudit
{
    /**
     * Касты атрибутов записи аудита.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'json:unicode',
        'new_values' => 'json:unicode',
    ];

    /**
     * Регистрирует модельные хуки.
     *
     * Шаги:
     * 1. Вешает listener на событие saving.
     * 2. Перед сохранением нормализует old_values и new_values.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $audit): void {
            $audit->decodeNestedJsonStrings('old_values');
            $audit->decodeNestedJsonStrings('new_values');
        });
    }

    /**
     * Декодирует вложенные JSON-строки в наборе значений аудита.
     *
     * Шаги:
     * 1. Читает значение атрибута через cast-геттер.
     * 2. Ищет строковые значения верхнего уровня, которые являются JSON-массивом/объектом.
     * 3. Заменяет такие строки декодированными массивами.
     * 4. При изменениях переустанавливает атрибут для повторного json:unicode-каста.
     *
     * @param  string  $key  Имя атрибута: old_values или new_values.
     */
    protected function decodeNestedJsonStrings(string $key): void
    {
        $values = $this->{$key};

        if (! is_array($values) || $values === []) {
            return;
        }

        $changedColumns = [];

        foreach ($values as $column => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $values[$column] = $decoded;
                $changedColumns[] = (string) $column;
            }
        }

        if ($changedColumns === []) {
            return;
        }

        $this->{$key} = $values;

        Log::debug('Audit nested JSON values decoded before save.', [
            'audit_id' => $this->getAttribute('id'),
            'attribute' => $key,
            'columns' => $changedColumns,
            'auditable_type' => $this->getAttribute('auditable_type'),
            'auditable_id' => $this->getAttribute('auditable_id'),
        ]);
    }
}
