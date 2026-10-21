<?php

use Composer\InstalledVersions;
use Webkul\Support\Assets\SupportCss;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

it('versions the support stylesheet by its own content hash', function () {
    $asset = SupportCss::make('support', __DIR__.'/../../resources/dist/support.css');

    $path = $asset->getPath();
    $expectedHash = substr((string) md5_file($path), 0, 10);

    expect($asset->getVersion())->toBe($expectedHash)
        ->and($asset->getHref())->toContain('?v='.$expectedHash);
});

it('falls back to the composer version when the stylesheet file is missing', function () {
    $asset = SupportCss::make('support', __DIR__.'/does-not-exist.css');

    expect($asset->getVersion())->toBe(InstalledVersions::getVersion('filament/support'));
});
