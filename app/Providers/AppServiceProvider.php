<?php
namespace App\Providers;

use App\Models\Order;
use App\Observers\OrderObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Durable OpenAI key. config/openai.php is wiped on every EasyPanel rebuild unless
        // committed, which leaves config('openai.api_key') null while the OPENAI_API_KEY env
        // var is still present — making the AI brain throw "API key missing", fall back, and
        // send a blank reply. Force the key from env so the bot, Whisper voice transcription
        // and image vision never silently break after a deploy.
        if (! config('openai.api_key') && env('OPENAI_API_KEY')) {
            config(['openai.api_key' => env('OPENAI_API_KEY')]);
        }

        // Marketplace (special-order) provider registry. Providers are declared in
        // config/marketplace.php as key => class; the registry resolves them from
        // the container on demand, so adding a marketplace never touches the bot.
        $this->app->singleton(\App\Services\Marketplace\ProviderRegistry::class, function ($app) {
            return new \App\Services\Marketplace\ProviderRegistry(
                $app,
                (array) config('marketplace.providers', []),
            );
        });

        // The Jumia provider reads the existing Playwright price API credentials.
        $this->app->bind(\App\Services\Marketplace\Providers\JumiaProvider::class, function () {
            return new \App\Services\Marketplace\Providers\JumiaProvider(
                (string) config('marketplace.jumia.url', ''),
                (string) config('marketplace.jumia.key', ''),
                (int) config('marketplace.jumia.timeout', 60),
            );
        });
    }

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
