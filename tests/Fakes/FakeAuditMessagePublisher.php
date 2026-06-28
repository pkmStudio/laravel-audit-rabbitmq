<?php

declare(strict_types=1);

namespace DanCenter\Audit\Tests\Fakes;

use DanCenter\Audit\Contracts\AuditMessagePublisher;
use DanCenter\RabbitTransport\DTOs\RabbitMessageDTO;

/**
 * Fake publisher для проверки outbox-логики без реального RabbitMQ.
 */
final class FakeAuditMessagePublisher implements AuditMessagePublisher
{
    /**
     * @var array<int, array{message: RabbitMessageDTO, routing_key: string}>
     */
    public array $published = [];

    /**
     * Создаёт fake publisher с управляемым результатом publish().
     *
     * Шаги:
     * 1. Принимает result, который будет возвращать publish().
     */
    public function __construct(
        private readonly bool $result,
    ) {}

    /**
     * Сохраняет сообщение в памяти и возвращает заданный результат.
     *
     * Шаги:
     * 1. Запоминает DTO и routing key.
     * 2. Возвращает configured result.
     */
    public function publish(RabbitMessageDTO $message, string $routingKey): bool
    {
        $this->published[] = [
            'message' => $message,
            'routing_key' => $routingKey,
        ];

        return $this->result;
    }
}
