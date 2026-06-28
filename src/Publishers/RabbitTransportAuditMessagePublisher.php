<?php

declare(strict_types=1);

namespace DanCenter\Audit\Publishers;

use DanCenter\Audit\Contracts\AuditMessagePublisher;
use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;
use DanCenter\RabbitTransport\RabbitMQPublisher;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер audit-пакета к dan-center/rabbit-transport.
 */
final readonly class RabbitTransportAuditMessagePublisher implements AuditMessagePublisher
{
    /**
     * Создаёт adapter к RabbitMQPublisher.
     *
     * Шаги:
     * 1. Получает shared RabbitMQPublisher через DI-контейнер.
     */
    public function __construct(
        private RabbitMQPublisher $publisher,
    ) {}

    /**
     * Публикует audit-сообщение через rabbit-transport.
     *
     * Шаги:
     * 1. Логирует debug-контекст публикации без payload.
     * 2. Делегирует publish() в RabbitMQPublisher.
     * 3. Возвращает результат publisher confirms.
     */
    public function publish(RabbitMessageDTO $message, string $routingKey): bool
    {
        Log::debug('Audit message publish delegated to rabbit-transport.', [
            'event_name' => $message->name,
            'routing_key' => $routingKey,
        ]);

        return $this->publisher->publish($message, $routingKey);
    }
}
