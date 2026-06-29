<?php

declare(strict_types=1);

namespace PkmStudio\Audit\Traits;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Помечает записи аудита источником изменения: file | filament | system.
 *
 * Источник читается из Context в момент создания записи аудита и сохраняется в
 * колонку `tags` таблицы `audits` через хук owen-it `generateTags()`.
 */
trait ResolvesAuditSource
{
    /**
     * Формирует теги записи аудита, включая источник изменения.
     *
     * Шаги:
     * 1. Вычисляет дефолтный источник по runtime-контексту.
     * 2. Читает фактический источник из Context по конфигурируемому ключу.
     * 3. Логирует безопасный debug-контекст резолва.
     * 4. Возвращает массив тегов owen-it.
     *
     * @return array<int, string>
     */
    public function generateTags(): array
    {
        $contextKey = (string) config('audit-transport.source_context_key', 'audit_source');
        $default = app()->runningInConsole()
            ? (string) config('audit-transport.default_console_source', 'system')
            : (string) config('audit-transport.default_http_source', 'filament');
        $source = (string) Context::get($contextKey, $default);

        Log::debug('Audit source resolved.', [
            'source' => $source,
            'context_key' => $contextKey,
            'model_type' => $this::class,
            'model_id' => method_exists($this, 'getKey') ? $this->getKey() : null,
        ]);

        return [$source];
    }
}
