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
    /** Scan from the tenant's stored messages + orders. */
    public function scan(Tenant $tenant, int $messageLimit = 5000): BusinessDiscovery
    {
        $rows = Message::where('tenant_id', $tenant->id)
            ->orderByDesc('id')->limit($messageLimit)->get()
            ->map(fn (Message $m) => [
                'direction' => (string) $m->direction,
                'body'      => (string) $m->body,
                'ts'        => optional($m->created_at)->toDateTimeString(),
                'media'     => $this->hasMedia($m),
            ])->all();

        $orders = $this->orders($tenant);
        $corpus = MessageCorpus::fromRows($rows);

        return $this->persist($tenant, $corpus, $orders);
    }

    /** Scan from a pasted/uploaded WhatsApp export. */
    public function scanExport(Tenant $tenant, string $exportText, array $ownerNames = []): BusinessDiscovery
    {
        $rows   = WhatsAppExportParser::parse($exportText, $ownerNames);
        $corpus = MessageCorpus::fromRows($rows);
        $orders = $this->orders($tenant);

        return $this->persist($tenant, $corpus, $orders);
    }

    protected function persist(Tenant $tenant, MessageCorpus $corpus, array $orders): BusinessDiscovery
    {
        $report = DiscoveryReport::build($corpus, $orders, (string) ($tenant->name ?? ''));

        return BusinessDiscovery::create([
            'status'          => 'pending',
            'readiness'       => (int) $report['readiness_score'],
            'report'          => $report,
            'sample_messages' => $corpus->total(),
            'sample_orders'   => count($orders),
        ]);
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
