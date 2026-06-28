<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет в `audits` поля transactional outbox для доставки в RabbitMQ.
 */
return new class extends Migration
{
    /**
     * Применяет миграцию outbox-полей аудита.
     *
     * Шаги:
     * 1. Резолвит connection и имя таблицы из config('audit.*').
     * 2. Добавляет `published_at` для времени подтверждённой публикации.
     * 3. Добавляет `attempts` для счётчика попыток публикации/ресенда.
     * 4. Создаёт индексы для быстрой выборки недоставленных записей.
     */
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable()->after('tags');
            $table->unsignedInteger('attempts')->default(0)->after('published_at');

            $table->index('published_at');
            $table->index(['published_at', 'created_at'], 'audits_outbox_pending_index');
        });
    }

    /**
     * Откатывает миграцию outbox-полей аудита.
     *
     * Шаги:
     * 1. Резолвит connection и имя таблицы из config('audit.*').
     * 2. Удаляет индексы outbox-выборки.
     * 3. Удаляет колонки attempts и published_at.
     */
    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->dropIndex('audits_outbox_pending_index');
            $table->dropIndex(['published_at']);
            $table->dropColumn(['attempts', 'published_at']);
        });
    }
};
