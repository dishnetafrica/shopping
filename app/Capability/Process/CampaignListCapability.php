<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Campaign;
use App\Models\Product;
use App\Services\Catalog\ImageUploadService;
use Illuminate\Support\Carbon;

/**
 * Marketing campaigns — the v3 face of the panel campaigns screen. Reads the Campaign model directly
 * for the list + KPIs; every WRITE goes through the proven panel endpoints /papi/campaign/save and
 * /papi/campaign/send (POST JSON). Sending is a background, throttled job on the engine side — this
 * screen only queues it, behind an owner/manager gate and an explicit confirm.
 */
final class CampaignListCapability implements ProcessCapability
{
    private const TYPES = 'promotion,launch,discount,seasonal';
    private const AUDIENCES = 'all,recent,inactive,vip,category';

    public function key(): string { return 'campaigns'; }

    public function permission(): string { return 'menu:customers'; }

    private function privileged(CapabilityContext $ctx): bool
    {
        $u = $ctx->user;
        if (method_exists($u, 'isOwnerLike') && $u->isOwnerLike()) { return true; }

        return in_array((string) ($u->role ?? ''), ['manager'], true);
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $canEdit = $ctx->can('menu:customers');
        $canSend = $this->privileged($ctx);

        $business = (string) (optional($ctx->tenant)->name ?: 'Your business');
        // Same source the send job uses, so the preview currency can never drift from the send.
        [$curCode, $curDec] = \App\Jobs\SendCampaign::priceCurrency($ctx->tenant);
        $typeLabels = ['promotion' => 'Product Promotion', 'launch' => 'New Product Launch', 'discount' => 'Discount Campaign', 'seasonal' => 'Seasonal Offer'];
        $audLabels = ['all' => 'Everyone', 'recent' => 'Recent buyers (30d)', 'inactive' => 'Lapsed (60d+)', 'vip' => 'VIP spenders', 'category' => 'Bought a category'];

        // category pick-list for the create form (only used when audience = "category")
        $cats = Product::where('tenant_id', $t)->where('active', true)->whereNotNull('category')
            ->where('category', '<>', '')->distinct()->orderBy('category')->pluck('category')->map(fn ($c) => trim((string) $c))->filter()->values()->all();
        $comboCats = 'combo:' . (count($cats) ? implode('|', $cats) : '');

        $campaigns = Campaign::where('tenant_id', $t)->orderByDesc('id')->limit(100)->get();

        $counts = ['draft' => 0, 'scheduled' => 0, 'sending' => 0, 'sent' => 0];
        $reached = 0;
        $rows = [];
        foreach ($campaigns as $c) {
            $status = (string) ($c->status ?: 'draft');
            if (isset($counts[$status])) { $counts[$status]++; }
            $stats = is_array($c->stats) ? $c->stats : [];
            $sent = (int) ($stats['sent'] ?? 0);
            $targeted = (int) ($stats['targeted'] ?? 0);
            $reached += $sent;
            $when = $c->scheduled_for ? Carbon::parse($c->scheduled_for) : null;
            $name = (string) ($c->name ?: ($typeLabels[$c->type] ?? 'Campaign'));

            $statusBadge = ['draft' => 'default', 'scheduled' => 'info', 'sending' => 'warn', 'sent' => 'ok'][$status] ?? 'default';
            $reach = $status === 'sent'
                ? ['value' => number_format($sent) . ($targeted ? ' / ' . number_format($targeted) : ''), 'tone' => 'ok', 'sort' => $sent]
                : ($status === 'sending' ? ['value' => 'sending…', 'tone' => 'warn', 'sort' => 0] : ['value' => '—', 'sort' => 0]);

            $btns = [];
            if ($canEdit) {
                $btns[] = ['icon' => 'edit', 'title' => 'Edit campaign', 'action' => $this->editForm($c, $comboCats, $typeLabels, $audLabels, $business, $curCode, $curDec)];
            }
            if ($canSend && in_array($status, ['draft', 'scheduled'], true)) {
                $btns[] = ['icon' => 'bolt', 'title' => 'Send now', 'tone' => 'primary', 'action' => [
                    'key' => 'send_campaign', 'label' => 'Send now', 'method' => 'POST', 'endpoint' => '/papi/campaign/send', 'actor' => 'human', 'tone' => 'primary',
                    'body' => ['id' => $c->id],
                    'confirm' => 'Send “' . $name . '” now to the ' . ($audLabels[$c->audience] ?? $c->audience) . ' audience? This messages every customer in it on WhatsApp and cannot be undone.',
                ]];
            }

            $rows[] = [
                'campaign' => ['value' => $name, 'sub' => ($typeLabels[$c->type] ?? 'Campaign'), 'avatar' => true],
                'audience' => (string) ($audLabels[$c->audience] ?? $c->audience ?: '—'),
                'status'   => ['value' => ucfirst($status), 'badge' => $statusBadge, 'sort' => $status],
                'when'     => $when ? ['value' => $when->format('d M, H:i'), 'sort' => $when->timestamp] : ['value' => '—', 'sort' => 0],
                'reach'    => $reach,
                'act'      => ['buttons' => $btns],
            ];
        }

        $columns = [
            ['key' => 'campaign', 'label' => 'Campaign', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'audience', 'label' => 'Audience', 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
            ['key' => 'when', 'label' => 'Scheduled', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'reach', 'label' => 'Reached', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'act', 'label' => '', 'type' => 'action', 'align' => 'right'],
        ];

        $newCampaign = $this->editForm(null, $comboCats, $typeLabels, $audLabels, $business, $curCode, $curDec);

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:campaigns', 'title' => 'Campaigns',
                'subtitle' => number_format($campaigns->count()) . ' campaigns · ' . $counts['draft'] . ' draft · ' . $counts['scheduled'] . ' scheduled',
            ])
            ->financials([
                ['key' => 'total', 'label' => 'Campaigns', 'value' => number_format($campaigns->count()), 'foot' => 'last 100'],
                ['key' => 'draft', 'label' => 'Drafts', 'value' => (string) $counts['draft'], 'tone' => $counts['draft'] > 0 ? 'info' : 'default', 'foot' => 'not sent yet'],
                ['key' => 'scheduled', 'label' => 'Scheduled', 'value' => (string) $counts['scheduled'], 'tone' => $counts['scheduled'] > 0 ? 'warn' : 'default', 'foot' => 'queued to send'],
                ['key' => 'sent', 'label' => 'Sent', 'value' => (string) $counts['sent'], 'tone' => 'ok', 'foot' => 'completed'],
                ['key' => 'reached', 'label' => 'Messages sent', 'value' => number_format($reached), 'tone' => 'ok', 'foot' => 'across all campaigns'],
            ])
            ->table($columns, $rows, [['key' => 'status', 'label' => 'Status'], ['key' => 'audience', 'label' => 'Audience']], ['pageSize' => 25])
            ->actions($canEdit ? [$newCampaign] : [])
            ->meta(['capability' => 'campaigns', 'ttl' => 30, 'permissions' => ['menu:customers']]);
    }

    /** Create (null) or edit a campaign — POST JSON to the proven /papi/campaign/save endpoint. */
    private function editForm(?Campaign $c, string $comboCats, array $typeLabels, array $audLabels, string $business = 'Your business', string $curCode = 'UGX', int $curDec = 0): array
    {
        $isEdit = $c !== null;
        $whenPrefill = ($isEdit && $c->scheduled_for) ? Carbon::parse($c->scheduled_for)->format('Y-m-d H:i') : '';

        // On edit, resolve any already-attached products so the picker shows them as chips (with photos).
        $attached = [];
        if ($isEdit && is_array($c->product_ids) && $c->product_ids) {
            $attached = Product::where('tenant_id', $c->tenant_id)->whereIn('id', $c->product_ids)
                ->get(['id', 'name', 'price', 'image_url'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'price' => round((float) $p->price, 2), 'image' => (string) ($p->image_url ?: '')])
                ->values()->all();
        }

        return [
            'key' => $isEdit ? 'edit_campaign' : 'new_campaign',
            'label' => $isEdit ? ('Edit campaign — ' . ($c->name ?: 'Campaign')) : 'New campaign',
            'method' => 'POST', 'endpoint' => '/papi/campaign/save', 'actor' => 'human', 'tone' => 'primary',
            'schema' => [
                'name' => 'text', 'type' => 'enum:' . self::TYPES, 'audience' => 'enum:' . self::AUDIENCES,
                'category' => $comboCats, 'message' => 'textarea', 'cta' => 'text', 'image_url' => 'mediapick', 'scheduled_for' => 'text',
            ],
            'labels' => [
                'name' => 'Campaign name', 'type' => 'Type', 'audience' => 'Send to (audience)',
                'category' => 'Category (only if audience = "Bought a category")', 'message' => 'Message',
                'cta' => 'Call to action', 'image_url' => 'Photo (pick a product, upload, or paste a link)',
                'scheduled_for' => 'Schedule for (YYYY-MM-DD HH:MM · blank = save as draft)',
            ],
            // The picker reads these to seed chips on edit and to search/upload under the
            // campaigns permission (menu:customers) — NOT the products capability, which a
            // sales/marketing campaign operator has no menu access to.
            'attachedProducts' => $attached,
            'mediaSearch' => '/api/v1/workspace/campaigns/_/search',
            'mediaUpload' => '/api/v1/workspace/campaigns/_/upload_image',
            // Live WhatsApp phone-frame preview — composes message + product lines + CTA + photo
            // exactly like SendCampaign, so the operator sees what the customer will receive.
            'waPreview' => [
                'business'     => $business,
                'messageField' => 'message',
                'ctaField'     => 'cta',
                'imageField'   => 'image_url',
                'currency'     => $curCode,
                'dec'          => $curDec,
            ],
            'prefill' => $isEdit
                ? ['name' => (string) $c->name, 'type' => (string) ($c->type ?: 'promotion'), 'audience' => (string) ($c->audience ?: 'all'), 'category' => (string) $c->category, 'message' => (string) $c->message, 'cta' => (string) ($c->cta ?: 'Reply BUY to order instantly.'), 'image_url' => (string) $c->image_url, 'scheduled_for' => $whenPrefill]
                : ['type' => 'promotion', 'audience' => 'all', 'cta' => 'Reply BUY to order instantly.'],
            'body' => $isEdit ? ['id' => $c->id] : [],
        ];
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();

        return [
            'total' => Campaign::where('tenant_id', $t)->count(),
            'draft' => Campaign::where('tenant_id', $t)->where('status', 'draft')->count(),
            'scheduled' => Campaign::where('tenant_id', $t)->where('status', 'scheduled')->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        // Product picker for the campaign form — gated by this capability's own permission
        // (menu:customers), so sales/marketing operators can use it. Returns id (needed so
        // the chosen product's name/price attach via product_ids) + image for the thumbnail.
        if ($action === 'search') {
            $q = trim((string) ($input['q'] ?? ''));
            $rows = Product::where('tenant_id', $ctx->tenantId())
                ->where('active', true)
                ->when($q !== '', fn ($qq) => $qq->where('name', 'ilike', '%' . $q . '%'))
                ->orderBy('name')->limit(20)->get();
            $items = $rows->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'price' => round((float) $p->price, 2),
                'unit' => (string) ($p->unit_label ?: ''),
                'image' => (string) ($p->image_url ?: ''),
            ])->all();

            return ['ok' => true, 'items' => $items];
        }

        // Upload a photo for the campaign — same store as products, under menu:customers.
        if ($action === 'upload_image') {
            try {
                $url = app(ImageUploadService::class)->store($ctx->tenant, (string) ($input['data'] ?? ''), (string) ($input['name'] ?? 'upload'));
            } catch (\InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'Could not read that image.'];
            }

            return ['ok' => true, 'url' => $url];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
