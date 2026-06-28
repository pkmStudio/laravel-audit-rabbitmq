<?php

declare(strict_types=1);

namespace DanCenter\Audit\Listeners;

use DanCenter\Audit\Services\AuditOutboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Events\Audited;

/**
 * Переносит события аудита в RabbitMQ-поток через outbox-маршрут.
 */
final class PublishAuditRecord implements ShouldQueue
{
    use Queueable;

    /**
     * Создаёт listener публикации audit-записей.
     *
     * Шаги:
     * 1. Получает outbox-сервис через DI-контейнер.
     * 2. Включает выполнение listener только после commit транзакции.
     */
    public function __construct(
        private readonly AuditOutboxService $outboxService,
    ) {
        $this->afterCommit();
    }

    /**
     * Обрабатывает событие Audited.
     *
     * Шаги:
     * 1. Проверяет, что событие содержит audit-запись.
     * 2. Логирует безопасный контекст публикации.
     * 3. Делегирует доставку в RabbitMQ сервису outbox.
     */
    public function handle(Audited $event): void
    {
        $audit = $event->audit;
        if (! ($audit instanceof Audit)) {
            Log::warning('Audit publish skipped: empty audit payload in Audited event.', [
                'auditable_type' => $event->model::class,
            ]);

            return;
        }

        Log::debug('Audit publish listener received event.', [
            'audit_id' => $audit->getAttribute('id'),
            'auditable_type' => $audit->getAttribute('auditable_type'),
            'auditable_id' => $audit->getAttribute('auditable_id'),
            'event' => $audit->getAttribute('event'),
        ]);

        $this->outboxService->attemptDelivery($audit);
    }
}
