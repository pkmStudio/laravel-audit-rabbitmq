<?php

declare(strict_types=1);

namespace PkmStudio\Audit\Tests\Fixtures;

use PkmStudio\Audit\Traits\ResolvesAuditSource;
use Illuminate\Database\Eloquent\Model;

/**
 * Минимальная Eloquent-модель для проверки audit source tags и routing table.
 */
final class SourceTaggedModel extends Model
{
    use ResolvesAuditSource;

    protected $table = 'kits';
}
