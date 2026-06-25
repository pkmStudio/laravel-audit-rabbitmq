<?php

declare(strict_types=1);

namespace DanCenter\Audit;

use Illuminate\Support\ServiceProvider;

/**
 * Сервис-провайдер пакета dan-center/audit.
 *
 * Регистрирует конфигурацию доставки аудита, публикует миграции таблицы
 * `audits` (с outbox-полями и unique-индексом идемпотентности) и console-команды
 * (ресендер). Producer-грань публикует записи через dan-center/rabbit-transport,
 * consumer-грань (app-agnostic) принимает их в любой реализации сервиса.
 */
final class AuditServiceProvider extends ServiceProvider
{
    /**
     * Регистрирует сервисы пакета в контейнере.
     *
     * Шаги:
     * 1. Сливает дефолтную конфигурацию доставки аудита с конфигом приложения.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/audit-transport.php',
            'audit-transport',
        );
    }

    /**
     * Выполняет загрузочные действия пакета.
     *
     * Шаги:
     * 1. Публикует config-stub доставки аудита.
     * 2. Загружает миграции таблицы audits из пакета.
     * 3. Регистрирует console-команды при запуске в консоли (ресендер — T2.2).
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/audit-transport.php' => $this->app->configPath('audit-transport.php'),
        ], 'audit-transport-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            // Команды пакета регистрируются здесь по мере переноса (T2.2).
            $this->commands([]);
        }
    }
}
