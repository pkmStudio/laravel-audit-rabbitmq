<?php

declare(strict_types=1);

namespace DanCenter\Audit\Tests\Fixtures;

use DanCenter\Audit\Traits\ResolvesAuditSource;
use Illuminate\Database\Eloquent\Model;

/**
 * Минимальная Eloquent-модель для проверки audit source tags и routing table.
 */
final class SourceTaggedModel extends Model
{
    use ResolvesAuditSource;

    protected $table = 'kits';
}
