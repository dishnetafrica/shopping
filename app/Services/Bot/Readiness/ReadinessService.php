<?php

namespace App\Services\Bot\Readiness;

use App\Models\BusinessDiscovery;
use App\Models\DailyOffer;
use App\Models\GoLiveReport;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Business Readiness — framework facade.
 *
 * Loads the latest Business Discovery for a tenant, reconstructs the current approval/coverage
 * state from what is actually live (catalogue, offers, delivery zones, order areas, settings),
 * runs the pure evaluator, and persists a PENDING Go-Live Report. Never enables AI or sets a mode.
 */
class ReadinessService
{
    public function __construct(protected WhatsAppManager $wa) {}

    public function evaluate(Tenant $tenant): ?GoLiveReport
    {
        $discovery = BusinessDiscovery::where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        if (! $discovery || ! is_array($discovery->report)) {
            Log::warning("ReadinessService: no discovery report for tenant {$tenant->id}.");
            return null;
        }

        $result = BusinessReadinessEvaluator::evaluate($tenant->id, $discovery->report, $this->state($tenant));

        return GoLiveReport::create([
            'overall_score'    => $result['overall_score'],
            'classification'   => $result['classification'],
            'recommended_mode' => $result['recommended_mode'],
            'category_scores'  => $result['category_scores'],
            'recommendations'  => $result['recommendations'],
            'status'           => 'pending',
            'approved_mode'    => null,
            'generated_at'     => now(),
        ]);
    }

    public function send(Tenant $tenant, GoLiveReport $report): bool
    {
        $numbers = $tenant->ownerAlertNumbers();
        if (! $numbers) return false;

        $payload = [
            'category_scores'  => $report->category_scores,
            'overall_score'    => $report->overall_score,
            'classification'   => $report->classification,
            'recommended_mode' => $report->recommended_mode,
            'recommendations'  => $report->recommendations,
        ];
        $msg = BusinessReadinessEvaluator::toWhatsApp($payload, (string) ($tenant->name ?? ''));
        $gateway = $this->wa->forTenant($tenant);

        $sent = false;
        foreach ($numbers as $to) {
            try { $gateway->sendText($tenant->whatsapp_instance, $to, $msg); $sent = true; }
            catch (\Throwable $e) { Log::warning("ReadinessService send to {$to}: " . $e->getMessage()); }
        }
        return $sent;
    }

    /** Reconstruct approval + coverage state from what is actually live for this tenant. */
    protected function state(Tenant $tenant): array
    {
        // Customer areas: distinct order locations (where customers actually are)
        $areas = Order::where('tenant_id', $tenant->id)
            ->whereNotNull('location')->where('location', '!=', '')
            ->orderByDesc('id')->limit(500)->pluck('location')
            ->map(fn ($l) => trim((string) $l))->filter()->unique()->take(20)->values()->all();

        $hasProducts = Product::where('tenant_id', $tenant->id)->where('active', true)->exists();
        $hasOffers   = class_exists(DailyOffer::class)
            && DailyOffer::where('tenant_id', $tenant->id)->where('is_active', true)->exists();
        $hasZones    = Schema::hasTable('delivery_zones')
            && \DB::table('delivery_zones')->where('tenant_id', $tenant->id)->exists();

        return [
            'areas_seen'          => $areas,
            'supported_languages' => $this->supportedLanguages($tenant),
            'approved' => [
                // a section counts as "approved" when its data is actually live for the tenant,
                // or when the owner has explicitly confirmed it via a setting flag
                'products' => $hasProducts || (bool) $tenant->setting('readiness_products_ok', false),
                'offers'   => $hasOffers   || (bool) $tenant->setting('readiness_offers_ok', false),
                'delivery' => $hasZones    || (bool) $tenant->setting('readiness_delivery_ok', false),
                'faqs'     => (bool) $tenant->setting('readiness_faqs_ok', false),
                'hours'    => (bool) $tenant->setting('readiness_hours_ok', false),
                'language' => (bool) $tenant->setting('readiness_language_ok', false),
            ],
            'hours_confirmed' => (bool) $tenant->setting('readiness_hours_ok', false),
        ];
    }

    protected function supportedLanguages(Tenant $tenant): array
    {
        $set = $tenant->setting('supported_languages', null);
        if (is_array($set) && $set) return $set;
        return ['English', 'Gujlish', 'Swahili', 'Hindi'];
    }
}
