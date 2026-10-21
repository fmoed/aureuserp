<?php

namespace Webkul\Support\Assets;

use Filament\Support\Assets\Css;

/**
 * Panel stylesheet whose cache-busting version follows the file's own
 * content hash instead of the fallback composer version: the support
 * plugin is not a composer-installed package, so Filament falls back to
 * the filament/support version for ?v= — which only changes on a
 * filament upgrade. Rebuilt stylesheets would then stay cached in every
 * admin's browser. Hashing the file makes every rebuild a new version.
 */
class SupportCss extends Css
{
    public function getVersion(): string
    {
        $path = $this->getPath();

        if ($path && is_file($path)) {
            return substr((string) md5_file($path), 0, 10);
        }

        return parent::getVersion();
    }
}
