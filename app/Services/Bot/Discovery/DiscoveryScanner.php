<?php

namespace App\Services\Bot\Discovery;

use App\Models\BusinessDiscovery;
use App\Models\Message;
use App\Models\Order;
use App\Models\Tenant;

/**
 * Business Discovery — orchestrator.
 *
 * Pulls a tenant's in-system WhatsApp history (messages) + order history, runs the pure analysis
 * engine, and persists the report as PENDING. Nothing is activated. Also supports scanning an
 * uploaded WhatsApp .txt export for brand-new businesses with no in-system history yet.
 */
class DiscoveryScanner
{
    /**
     * Scan from the tenant's stored messages + orders.
     *
     * @param int|null $sinceDays  Limit to the last N days (Phase-1 onboarding passes 30 for a fast
     *                             scan). Null = full available history (used by background widening).
     */
    public function scan(Tenant $tenant, int $messageLimit = 5000, ?int $sinceDays = null): BusinessDiscovery
    {
        $q = Message::where('tenant_id', $tenant->id);
        if ($sinceDays !== null) {
            $q->where('created_at', '>=', now()->subDays($sinceDays));
        }
        $rows = $q->orderByDesc('id')->limit($messageLimit)->get()
            ->map(fn (Message $m) => [
                'direction' => (string) $m->direction,
                'body'      => (string) $m->body,
                'ts'        => optional($m->created_at)->toDateTimeString(),
                'media'     => $this->hasMedia($m),
            ])->all();

        $orders = $this->orders($tenant);
        $corpus = MessageCorpus::fromRows($rows);

        return $this->persist($tenant, $corpus, $orders, $sinceDays);
    }

    /** Scan from a pasted/uploaded WhatsApp export. */
    public function scanExport(Tenant $tenant, string $exportText, array $ownerNames = []): BusinessDiscovery
    {
        $rows   = WhatsAppExportParser::parse($exportText, $ownerNames);
        $corpus = MessageCorpus::fromRows($rows);
        $orders = $this->orders($tenant);

        return $this->persist($tenant, $corpus, $orders);
    }

    protected function persist(Tenant $tenant, MessageCorpus $corpus, array $orders, ?int $sinceDays = null): BusinessDiscovery
    {
        $catalogue  = $this->catalogue($tenant);
        $knownAreas = $this->knownAreas($tenant);
        $report = DiscoveryReport::build($corpus, $orders, (string) ($tenant->name ?? ''), $catalogue, $knownAreas);
        $report['window_days'] = $sinceDays;

        return BusinessDiscovery::create([
            'status'          => 'pending',
            'readiness'       => (int) $report['readiness_score'],
            'report'          => $report,
            'sample_messages' => $corpus->total(),
            'sample_orders'   => count($orders),
        ]);
    }

    /** Active product catalogue — the whitelist the ProductMiner matches discovered terms against. */
    protected function catalogue(Tenant $tenant): array
    {
        return \App\Models\Product::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->limit(2000)->get(['name', 'category', 'keywords'])
            ->map(fn ($p) => [
                'name'     => (string) $p->name,
                'category' => (string) ($p->category ?? ''),
                'keywords' => (string) ($p->keywords ?? ''),
            ])->all();
    }

    /** Known delivery zones — used to validate discovered served areas. */
    protected function knownAreas(Tenant $tenant): array
    {
        try {
            return \App\Models\DeliveryZone::where('tenant_id', $tenant->id)
                ->get(['name', 'match_keywords'])
                ->flatMap(function ($z) {
                    $names = [(string) $z->name];
                    foreach (preg_split('/[,\n]+/', (string) ($z->match_keywords ?? '')) as $kw) {
                        $kw = trim($kw);
                        if ($kw !== '') $names[] = $kw;
                    }
                    return $names;
                })->filter()->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function orders(Tenant $tenant): array
    {
        return Order::where('tenant_id', $tenant->id)
            ->orderByDesc('id')->limit(2000)->get()
            ->map(fn (Order $o) => [
                'items_json' => is_array($o->items_json) ? $o->items_json : [],
                'items_text' => (string) ($o->items_text ?? ''),
                'location'   => (string) ($o->location ?? ''),
            ])->all();
    }

    protected function hasMedia(Message $m): bool
    {
        $meta = is_array($m->meta) ? $m->meta : [];
        return ! empty($meta['media']) || ! empty($meta['has_media']) || ($m->body ?? '') === '';
    }
}
