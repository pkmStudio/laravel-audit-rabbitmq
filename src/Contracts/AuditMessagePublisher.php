<?php

declare(strict_types=1);

namespace DanCenter\Audit\Contracts;

use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;

/**
 * Контракт публикации audit-сообщений в транспорт.
 */
interface AuditMessagePublisher
{
    /**
     * Публикует audit-сообщение по routing key.
     *
     * Шаги:
     * 1. Принимает готовый RabbitMessageDTO.
     * 2. Передаёт per-message routing key в транспорт.
     * 3. Возвращает true только при подтверждённой публикации.
     */
    public function publish(RabbitMessageDTO $message, string $routingKey): bool;
}
