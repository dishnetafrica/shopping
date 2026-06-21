<?php

namespace App\Services\Bot\Validation;

use App\Models\Order;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\ValidationRun;
use App\Services\Bot\Discovery\DiscoveryReport;
use App\Services\Bot\Discovery\MessageCorpus;
use App\Services\Bot\Readiness\BusinessReadinessEvaluator;

/**
 * Platform Validation — framework facade. Runs the validation pipeline, times the scan, persists
 * a validation_runs row, and aggregates accuracy reports across business types.
 */
class ValidationService
{
    /** Run one fixture business type and (optionally) persist the result. */
    public function runFixture(string $type, bool $save = true): ?array
    {
        $fx = ValidationFixtures::get($type);
        if (! $fx) return null;

        $t0 = microtime(true);
        $result = ValidationRunner::run($type, $fx['export'], $fx['actual'], $fx['owner'], $fx['orders']);
        $result['scan_ms'] = (int) round((microtime(true) - $t0) * 1000);

        if ($save) $this->persist(null, $result);
        return $result;
    }

    /** Run all five fixtures. */
    public function runAllFixtures(bool $save = true): array
    {
        $out = [];
        foreach (array_keys(ValidationFixtures::all()) as $type) {
            $out[$type] = $this->runFixture($type, $save);
        }
        return $out;
    }

    /**
     * Validate a real tenant: scan its in-system history + orders, compare against the operator-
     * supplied ground truth. Tracks how the live onboarding performs on a real shop.
     */
    public function runTenant(Tenant $tenant, string $businessType, array $actual, bool $save = true): array
    {
        $t0 = microtime(true);

        $rows = Message::where('tenant_id', $tenant->id)->orderByDesc('id')->limit(5000)->get()
            ->map(fn (Message $m) => [
                'direction' => (string) $m->direction,
                'body'      => (string) $m->body,
                'ts'        => optional($m->created_at)->toDateTimeString(),
            ])->all();
        $orders = Order::where('tenant_id', $tenant->id)->orderByDesc('id')->limit(2000)->get()
            ->map(fn (Order $o) => ['items_json' => is_array($o->items_json) ? $o->items_json : []])->all();

        $corpus = MessageCorpus::fromRows($rows);
        $report = DiscoveryReport::build($corpus, $orders, (string) ($tenant->name ?? $businessType));
        $coverage = (int) $report['readiness_score'];
        $metrics  = ValidationComparator::compare($report['sections'], $actual, $coverage);

        $sec = $report['sections'];
        $result = [
            'business_type'          => $businessType,
            'messages_scanned'       => $corpus->total(),
            'products_found'         => count($sec['top_products'] ?? []),
            'faq_found'              => count($sec['faqs'] ?? []),
            'delivery_rules_found'   => count($sec['delivery']['areas'] ?? []) + (! empty($sec['delivery']['fee']) ? 1 : 0),
            'readiness_score'        => $coverage,
            'accuracy_score'         => $metrics['overall_accuracy'],
            'products_discovery_pct' => $metrics['products']['recall'],
            'faq_discovery_pct'      => $metrics['faqs']['recall'],
            'can_go_live'            => $coverage >= 70,
            'metrics'                => $metrics,
            'scan_ms'                => (int) round((microtime(true) - $t0) * 1000),
        ];

        if ($save) $this->persist($tenant->id, $result);
        return $result;
    }

    protected function persist(?int $tenantId, array $r): ValidationRun
    {
        return ValidationRun::create([
            'tenant_id'            => $tenantId,
            'business_type'        => $r['business_type'],
            'messages_scanned'     => $r['messages_scanned'],
            'products_found'       => $r['products_found'],
            'faq_found'            => $r['faq_found'],
            'delivery_rules_found' => $r['delivery_rules_found'],
            'readiness_score'      => $r['readiness_score'],
            'accuracy_score'       => $r['accuracy_score'],
            'scan_ms'              => $r['scan_ms'] ?? null,
            'detail'               => $r['metrics'] ?? null,
            'created_at'           => now(),
        ]);
    }

    /** Aggregate accuracy report across stored runs (optionally by business type). */
    public function accuracyReport(?string $type = null): array
    {
        $q = ValidationRun::query();
        if ($type) $q->where('business_type', $type);
        $runs = $q->get();
        if ($runs->isEmpty()) return ['runs' => 0];

        $avg = fn (string $f) => (int) round($runs->avg($f));
        $goLive = $runs->filter(fn ($r) => $r->readiness_score >= 70)->count();
        $band   = $runs->filter(fn ($r) => $r->readiness_score >= 70 && $r->readiness_score <= 90)->count();

        return [
            'runs'                   => $runs->count(),
            'avg_messages'           => $avg('messages_scanned'),
            'avg_products_found'     => $avg('products_found'),
            'avg_readiness'          => $avg('readiness_score'),
            'avg_accuracy'           => $avg('accuracy_score'),
            'avg_scan_ms'            => $avg('scan_ms'),
            'go_live_ready'          => $goLive,
            'in_target_band_70_90'   => $band,
            'by_type'                => $runs->groupBy('business_type')->map(fn ($g) => [
                'runs'          => $g->count(),
                'avg_readiness' => (int) round($g->avg('readiness_score')),
                'avg_accuracy'  => (int) round($g->avg('accuracy_score')),
            ])->all(),
        ];
    }
}
