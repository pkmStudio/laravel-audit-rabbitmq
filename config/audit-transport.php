<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit transport — конвенции доставки записей аудита
|--------------------------------------------------------------------------
|
| Конфигурация продьюсер/консьюмер-граней пакета dan-center/audit.
| Owen-it хранит свою конфигурацию в config/audit.php (модель, ресолверы и т.д.);
| здесь — только то, что относится к доставке через RabbitMQ.
|
| Routing key записи аудита формируется как:
|     {prefix}{table}.{event}        (например crm.audit.audits.created)
| Логическое имя события (поле тела `name`, токен (б)) — `event_name`.
|
*/

return [

    /*
    | Префикс routing key для исходящих аудит-сообщений (токен (а)).
    | Полный ключ: {prefix}{table}.{event}. Prefix `crm.` задаётся приложением.
    */
    'routing_key_prefix' => env('AUDIT_ROUTING_KEY_PREFIX', 'crm.audit.'),

    /*
    | Логическое имя события аудита в теле сообщения (токен (б)),
    | по которому консьюмер диспетчеризует обработку через rabbit-transport.inbound.
    */
    'event_name' => env('AUDIT_EVENT_NAME', 'AUDIT_RECORDED'),

    /*
    | Ключ Context для источника аудита и fallback-значения источников.
    | CRM-импорты кладут в этот Context `file`; UI по умолчанию получает `filament`,
    | console/queue-контекст — `system`.
    */
    'source_context_key' => env('AUDIT_SOURCE_CONTEXT_KEY', 'audit_source'),

    'default_console_source' => env('AUDIT_DEFAULT_CONSOLE_SOURCE', 'system'),

    'default_http_source' => env('AUDIT_DEFAULT_HTTP_SOURCE', 'filament'),

    /*
    | Порог «зависшей» outbox-записи (минуты) для ресендера: записи с
    | published_at = null старше этого порога переотправляются.
    */
    'resend_stale_after_minutes' => (int) env('AUDIT_RESEND_STALE_AFTER_MINUTES', 2),

];
