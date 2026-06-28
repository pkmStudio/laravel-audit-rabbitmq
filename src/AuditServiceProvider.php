<?php

declare(strict_types=1);

namespace DanCenter\Audit;

use DanCenter\Audit\Console\Commands\ResendFailedAuditRecordsCommand;
use DanCenter\Audit\Contracts\AuditMessagePublisher;
use DanCenter\Audit\Publishers\RabbitTransportAuditMessagePublisher;
use Illuminate\Support\ServiceProvider;

/**
 * Сервис-провайдер пакета dan-center/audit.
 *
 * Регистрирует конфигурацию доставки аудита, публикует миграции таблицы `audits`
 * и регистрирует console-команды producer-грани. Producer-грань публикует записи
 * через dan-center/rabbit-transport, consumer-грань принимает их в любой
 * реализации сервиса.
 */
final class AuditServiceProvider extends ServiceProvider
{
    /**
     * Регистрирует сервисы пакета в контейнере.
     *
     * Шаги:
     * 1. Сливает дефолтную конфигурацию доставки аудита с конфигом приложения.
     * 2. Регистрирует adapter публикации audit-сообщений в rabbit-transport.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/audit-transport.php',
            'audit-transport',
        );

        $this->app->bind(
            AuditMessagePublisher::class,
            RabbitTransportAuditMessagePublisher::class,
        );
    }

    /**
     * Выполняет загрузочные действия пакета.
     *
     * Шаги:
     * 1. Публикует config-stub доставки аудита.
     * 2. Загружает миграции таблицы audits из пакета.
     * 3. Регистрирует console-команды при запуске в консоли.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/audit-transport.php' => $this->app->configPath('audit-transport.php'),
        ], 'audit-transport-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ResendFailedAuditRecordsCommand::class,
            ]);
        }
    }
}
