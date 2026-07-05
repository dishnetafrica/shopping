<?php

namespace App\Services\Marketplace;

use App\Models\Tenant;
use App\Services\Marketplace\Config\MarketplaceConfig;
use App\Services\Marketplace\Contracts\MarketplaceProvider;
use App\Services\Marketplace\Dto\MarketplaceOutcome;
use App\Services\Marketplace\Dto\MarketplaceQuote;
use App\Services\Marketplace\Pricing\PricingPlanFactory;
use App\Services\Marketplace\Support\Confidence;
use App\Services\Marketplace\Support\ImageHostAllowList;

/**
 * The one class the bot talks to for special-order pricing. It:
 *
 *   1. resolves the tenant's configured provider (Jumia today, anything later),
 *   2. runs the query through it,
 *   3. prices each hit through the tenant's landed-cost pipeline,
 *   4. scores each hit's confidence and host-checks its image,
 *   5. returns a DECISION: quote outright / ask "did you mean?" / stand down.
 *
 * BotBrain never sees a provider, a marketplace name, or a raw price. It sees a
 * MarketplaceOutcome of MarketplaceQuote objects. Everything here is guarded so
 * a failure returns MarketplaceOutcome::none() and the bot behaves as it does
 * today.
 */
final class MarketplaceSearch
{
    public function __construct(
        private ProviderRegistry $registry,
    ) {}

    /**
     * Has this tenant switched special orders on? Lets the bot distinguish
     * "feature off -> plain we-don't-stock" from "feature on but nothing to
     * quote -> the shop will confirm", without the bot knowing about config.
     */
    public function enabledFor(Tenant $tenant): bool
    {
        try {
            return MarketplaceConfig::fromTenant($tenant)->enabled;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Spec-compatible surface: return the high-confidence quotes as plain arrays,
     * or null when there's nothing to quote (so existing callers/tests that
     * expect the original ?array shape keep working).
     *
     * @return array<int,array{name:string,sell_price:int,currency:string,image_url:string,url:string,provider:string,cached:bool,confidence:float}>|null
     */
    public function search(Tenant $tenant, string $query): ?array
    {
        $outcome = $this->quote($tenant, $query);
        if (! $outcome->isQuote()) {
            return null;
        }
        return array_map(fn (MarketplaceQuote $q) => $q->toArray(), $outcome->quotes());
    }

    /**
     * Rich surface used by the bot: a confidence-gated decision. Never throws.
     */
    public function quote(Tenant $tenant, string $query): MarketplaceOutcome
    {
        try {
            $cfg = MarketplaceConfig::fromTenant($tenant);
            if (! $cfg->enabled) {
                return MarketplaceOutcome::none();
            }

            $provider = $this->registry->resolve($cfg->providerKey);
            if ($provider === null || ! $provider->isEnabled()) {
                return MarketplaceOutcome::none();
            }

            return $this->quoteWith($cfg, $provider, $query);
        } catch (\Throwable $e) {
            report($e);
            return MarketplaceOutcome::none();
        }
    }

    /**
     * Facade-free core: price + score a provider's results against a config.
     * Kept public and provider-injected so it can be unit-tested with a fake
     * provider and a hand-built config (no Eloquent, no HTTP, no container).
     */
    public function quoteWith(MarketplaceConfig $cfg, MarketplaceProvider $provider, string $query): MarketplaceOutcome
    {
        $query = trim($query);
        if ($query === '') {
            return MarketplaceOutcome::none();
        }

        $results = $provider->search($query);          // provider never throws
        if ($results === []) {
            return MarketplaceOutcome::none();
        }

        $plan = PricingPlanFactory::fromConfig($cfg);
        $quotes = [];

        foreach (array_slice($results, 0, max(1, $cfg->maxOptions)) as $r) {
            $priced = $plan->price($r->baseAmount, $r->baseCurrency);
            $image  = ImageHostAllowList::allowed($r->image, $cfg->allowedImageHosts) ? $r->image : '';
            $conf   = Confidence::score($query, $r->name);

            $quotes[] = new MarketplaceQuote(
                name: $r->name,
                sellPrice: $priced->sellPrice(),
                currency: $priced->currency(),
                image: $image,
                url: $r->url,
                provider: $r->provider,
                cached: $r->cached,
                confidence: $conf,
                breakdown: $priced->breakdown(),
            );
        }

        // Best match first.
        usort($quotes, fn (MarketplaceQuote $a, MarketplaceQuote $b) => $b->confidence <=> $a->confidence);
        $best = $quotes[0];

        $bucket = Confidence::classify($best->confidence, $cfg->confidenceHigh, $cfg->confidenceMedium);

        if ($bucket === 'high') {
            // Quote only the options that clear the "did you mean" floor — never
            // pad a strong answer with weak, unrelated hits.
            $good = array_values(array_filter(
                $quotes,
                fn (MarketplaceQuote $q) => $q->confidence >= $cfg->confidenceMedium,
            ));
            return MarketplaceOutcome::quote($good ?: [$best]);
        }

        if ($bucket === 'medium') {
            return MarketplaceOutcome::confirm($best);      // "Did you mean X?"
        }

        return MarketplaceOutcome::none();                  // too weak to quote
    }
}
