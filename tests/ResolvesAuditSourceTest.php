<?php

declare(strict_types=1);

use PkmStudio\Audit\Tests\Fixtures\SourceTaggedModel;
use Illuminate\Support\Facades\Context;

it('resolves source tag from context with console fallback', function (): void {
    $model = new SourceTaggedModel();

    expect($model->generateTags())->toBe(['system']);

    Context::add('audit_source', 'file');

    try {
        expect($model->generateTags())->toBe(['file']);
    } finally {
        Context::forget('audit_source');
    }
});
