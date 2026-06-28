<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет явный ключ идемпотентности audit-записей.
 */
return new class extends Migration
{
    /**
     * Применяет миграцию ключа идемпотентности.
     *
     * Шаги:
     * 1. Резолвит connection и имя таблицы из config('audit.*').
     * 2. Добавляет nullable `dedupe_id` для source audit id / hash fallback.
     * 3. Создаёт unique-индекс по `dedupe_id`.
     *
     * Решение: используем явный `dedupe_id`, а не unique по
     * auditable_type/auditable_id/event/created_at, потому что created_at в
     * существующей CRM-схеме не гарантирует микросекундную точность.
     */
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->string('dedupe_id')->nullable();
            $table->unique('dedupe_id', 'audits_dedupe_id_unique');
        });
    }

    /**
     * Откатывает миграцию ключа идемпотентности.
     *
     * Шаги:
     * 1. Резолвит connection и имя таблицы из config('audit.*').
     * 2. Удаляет unique-индекс `dedupe_id`.
     * 3. Удаляет колонку `dedupe_id`.
     */
    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->dropUnique('audits_dedupe_id_unique');
            $table->dropColumn('dedupe_id');
        });
    }
};
