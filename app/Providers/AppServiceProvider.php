<?php

namespace App\Providers;

use App\Filament\Support\View\Components\ButtonComponent;
use Filament\Support\View\Components\ButtonComponent as BaseButtonComponent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Webkul\Security\Models\User;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Authenticatable::class, User::class);

        $this->app->bind(BaseButtonComponent::class, ButtonComponent::class);
    }

    public function boot(): void
    {
        $origin = request()->getSchemeAndHttpHost();

        if (in_array($origin, config('app.lan_urls', []), true)) {
            URL::useAssetOrigin($origin);

            return;
        }

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
