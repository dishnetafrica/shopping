<?php
namespace App\Providers;

use App\Apps\Core\CoreCapability;
use App\Apps\DailyMenu\DailyMenuCapability;
use App\Services\Knowledge\BusinessMemory;
use App\Services\Knowledge\CapabilityRegistry;
use App\Services\Knowledge\Classifier\DeterministicClassifier;
use App\Services\Knowledge\Contracts\Classifier;
use App\Services\Knowledge\KnowledgeEngine;
use App\Services\Knowledge\OperationalStateStore;
use App\Services\Knowledge\ProjectionCoordinator;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Owner Knowledge Engine. Registering Core + Daily Menu here is the ONLY place new
 * capabilities are added — engine code never changes. (Add this provider to bootstrap/providers.php
 * in Drop 2b alongside the MerchantAssistant/BotBrain wiring.)
 */
class OkeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationalStateStore::class);
        $this->app->bind(Classifier::class, DeterministicClassifier::class);

        $this->app->singleton(CapabilityRegistry::class, function ($app) {
            $registry = new CapabilityRegistry();
            $state = $app->make(OperationalStateStore::class);
            $registry->register(new CoreCapability($state));        // cross-industry
            $registry->register(new DailyMenuCapability($state));   // first application
            return $registry;
        });

        $this->app->singleton(ProjectionCoordinator::class, fn ($app) => new ProjectionCoordinator($app->make(CapabilityRegistry::class)));

        $this->app->singleton(KnowledgeEngine::class, fn ($app) => new KnowledgeEngine(
            $app->make(CapabilityRegistry::class),
            $app->make(Classifier::class),
            $app->make(BusinessMemory::class),
            $app->make(ProjectionCoordinator::class),
        ));
    }
}
