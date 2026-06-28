<?php

declare(strict_types=1);

namespace DanCenter\Audit\Console\Commands;

use DanCenter\Audit\Services\AuditOutboxService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Contracts\Audit;

/**
 * Добивает недоставленные audit-записи по outbox-очереди.
 */
final class ResendFailedAuditRecordsCommand extends Command
{
    protected $signature = 'audit:resend-failed';

    protected $description = 'Повторно отправляет в RabbitMQ audit-записи с published_at = null';

    /**
     * Выполняет повторную доставку зависших audit-записей.
     *
     * Шаги:
     * 1. Проверяет корректность config('audit.implementation').
     * 2. Находит записи с published_at = NULL старше configured threshold.
     * 3. Передаёт каждую запись в AuditOutboxService.
     * 4. Логирует итог и возвращает код ошибки при неуспешных попытках.
     */
    public function handle(AuditOutboxService $outboxService): int
    {
        $implementation = config('audit.implementation');
        if (! is_string($implementation) || ! class_exists($implementation)) {
            Log::error('Audit resend failed: invalid audit implementation config.', [
                'implementation' => $implementation,
            ]);

            return self::FAILURE;
        }

        /** @var class-string<Audit> $implementation */
        $auditModelClass = $implementation;
        $thresholdMinutes = $this->thresholdMinutes();
        $threshold = now()->subMinutes($thresholdMinutes);
        $pendingQuery = $auditModelClass::query()
            ->whereNull('published_at')
            ->where('created_at', '<=', $threshold)
            ->orderBy('id');

        Log::debug('Audit resend scan started.', [
            'implementation' => $auditModelClass,
            'threshold_minutes' => $thresholdMinutes,
            'threshold' => $threshold->toISOString(),
        ]);

        if (! $this->hasPending($pendingQuery)) {
            $this->info('Нет не доставленных audit-записей для повторной отправки.');

            return self::SUCCESS;
        }

        $pending = $pendingQuery->get();
        $attempted = 0;
        $failed = 0;

        foreach ($pending as $audit) {
            $attempted++;

            if (! $outboxService->attemptDelivery($audit)) {
                $failed++;
            }
        }

        Log::debug('Audit resend scan completed.', [
            'attempted' => $attempted,
            'failed' => $failed,
        ]);

        if ($failed > 0) {
            $this->warn("Повторная отправка завершена с ошибками: {$failed} из {$attempted} не доставлены.");

            return self::FAILURE;
        }

        $this->info("Повторная отправка завершена: успешно доставлено {$attempted} записей.");

        return self::SUCCESS;
    }

    /**
     * Проверяет, есть ли хотя бы одна запись для обработки.
     *
     * Шаги:
     * 1. Выполняет exists() для сохранения памяти на больших выборках.
     */
    private function hasPending(Builder $pendingQuery): bool
    {
        return $pendingQuery->exists();
    }

    /**
     * Возвращает возраст зависших outbox-записей для ресендера.
     *
     * Шаги:
     * 1. Читает audit-transport.resend_stale_after_minutes.
     * 2. Приводит значение к неотрицательному integer.
     */
    private function thresholdMinutes(): int
    {
        return max(0, (int) config('audit-transport.resend_stale_after_minutes', 2));
    }
}
