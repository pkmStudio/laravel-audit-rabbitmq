<?php

declare(strict_types=1);

use PkmStudio\Audit\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/Fakes/FakeAuditMessagePublisher.php';
require_once __DIR__.'/Fixtures/SourceTaggedModel.php';

pest()
    ->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);
