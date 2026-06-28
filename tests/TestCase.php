<?php

declare(strict_types=1);

namespace DanCenter\Audit\Tests;

use DanCenter\Audit\AuditServiceProvider;
use DanCenter\Audit\Models\Audit;
use DanCenter\RabbitTransport\RabbitTransportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Базовый TestCase пакета dan-center/audit на основе Orchestra Testbench.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Возвращает service providers пакетов для тестового Laravel-приложения.
     *
     * Шаги:
     * 1. Регистрирует AuditServiceProvider.
     * 2. Регистрирует RabbitTransportServiceProvider для DTO/publisher binding.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            RabbitTransportServiceProvider::class,
            AuditServiceProvider::class,
        ];
    }

    /**
     * Настраивает окружение тестового Laravel-приложения.
     *
     * Шаги:
     * 1. Включает in-memory SQLite.
     * 2. Настраивает owen-it audit implementation на модель пакета.
     * 3. Настраивает audit-transport/rabbit-transport defaults для тестов.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('audit.implementation', Audit::class);
        $app['config']->set('audit.user.morph_prefix', 'user');
        $app['config']->set('audit.drivers.database.connection', null);
        $app['config']->set('audit.drivers.database.table', 'audits');

        $app['config']->set('audit-transport.routing_key_prefix', 'crm.audit.');
        $app['config']->set('audit-transport.event_name', 'AUDIT_RECORDED');
        $app['config']->set('audit-transport.source_context_key', 'audit_source');
        $app['config']->set('audit-transport.default_console_source', 'system');
        $app['config']->set('audit-transport.default_http_source', 'filament');

        $app['config']->set('rabbit-transport.connection', 'sync');
    }
}
