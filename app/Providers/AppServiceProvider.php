<?php
namespace App\Providers;

use App\Models\Order;
use App\Observers\OrderObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Behind EasyPanel's HTTPS proxy the container receives plain HTTP;
        // force https so asset()/url() (and Filament's CSS/JS) aren't blocked as mixed content.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Order::observe(OrderObserver::class);
    }
}
