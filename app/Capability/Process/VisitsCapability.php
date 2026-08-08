<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\CustomerProfile;
use App\Models\Visit;
use App\Services\Crm\BusinessStage;
use App\Services\Field\VisitOutcome;
use App\Services\Field\VisitService;

/**
 * v4.63 — Visits: where a rep went, and what came of it.
 *
 * The gap this fills is the one everything else was waiting on. Nothing recorded that somebody went
 * somewhere, so the shop could not tell "never approached" from "approached and refused" — and any
 * future scoring had positives (676 customers) and no negatives at all.
 *
 * The column worth reading is **What happens next**, not the outcome. An outcome is a fact about
 * yesterday; a next action with a date is the only part of a visit that can still earn money.
 *
 * One number on this screen is deliberately not the one people expect. "Told us something" counts
 * visits where a person was actually asked — so it excludes *owner was out*, which is not a no.
 * Counting those as refusals is how a set of training labels quietly becomes a record of the rep's
 * timing rather than the market's appetite.
 */
final class VisitsCapability implements ProcessCapability
{
    private const MAX_ROWS = 300;
    private const RECENT_DAYS = 90;

    public function key(): string { return 'visits'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $visits = Visit::withoutGlobalScopes()
            ->where('tenant_id', $t)
            ->where('checked_in_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->orderByDesc('checked_in_at')->limit(self::MAX_ROWS)
            ->get();

        $names = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t)
            ->whereIn('id', $visits->pluck('customer_profile_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $counts = ['total' => 0, 'informative' => 0, 'positive' => 0, 'due' => 0, 'overdue' => 0];
        $rows = [];
        $today = now()->startOfDay();

        foreach ($visits as $v) {
            $o = (string) $v->outcome;
            $counts['total']++;
            if (VisitOutcome::informative($o)) {
                $counts['informative']++;
            }
            if (VisitOutcome::isPositive($o)) {
                $counts['positive']++;
            }

            $open = ! $v->next_action_done && $v->next_action_at;
            $late = $open && $v->next_action_at->lt($today);
            if ($open) {
                $counts['due']++;
            }
            if ($late) {
                $counts['overdue']++;
            }

            $when = $v->next_action_at
                ? ($late ? 'was due ' . $v->next_action_at->diffForHumans(null, true) . ' ago'
                         : 'by ' . $v->next_action_at->toDateString())
                : '';

            $rows[] = [
                'business' => ['value' => (string) ($names[$v->customer_profile_id] ?? 'Business #' . $v->customer_profile_id),
                               'sub' => (string) ($v->user_name ?: 'rep'), 'avatar' => true,
                               'link' => '/api/v1/workspace/customer/' . (int) $v->customer_profile_id],
                'when'     => ['value' => $v->checked_in_at ? $v->checked_in_at->toDateString() : '—'],
                'outcome'  => ['value' => VisitOutcome::label($o), 'badge' => VisitOutcome::tone($o)],
                'next'     => ['value' => $v->next_action_done ? 'done'
                                          : ((string) ($v->next_action ?: '—')),
                               'sub' => $v->next_action_done ? '' : $when,
                               'tone' => $late ? 'danger' : null],
                'asked'    => ['value' => (string) ($v->items_summary ?? '')],
                'act'      => ['buttons' => array_values(array_filter([
                    $open ? ['label' => 'Done', 'icon' => 'check', 'title' => 'Mark the next action done',
                             'post' => '/api/v1/workspace/visits/' . (int) $v->id . '/close_action'] : null,
                ]))],
            ];
        }

        // One query for every visit's items rather than one per row.
        $this->attachItems($t, $visits, $rows);

        $columns = [
            ['key' => 'business', 'label' => 'Business', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'when',     'label' => 'Visited',  'sortable' => true],
            ['key' => 'outcome',  'label' => 'What happened', 'type' => 'badge', 'sortable' => true],
            ['key' => 'next',     'label' => 'What happens next', 'sortable' => true],
            ['key' => 'asked',    'label' => 'Asked for', 'sortable' => false],
            ['key' => 'act',      'label' => '', 'sortable' => false],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:visits', 'title' => 'Visits',
                'subtitle' => $counts['total']
                    ? $counts['total'] . ' visits in the last ' . self::RECENT_DAYS . ' days'
                    : 'No visits logged yet · this is where the shop learns who says no, and why',
            ])
            ->actions([$this->logAction($ctx)])
            ->headline((string) $counts['overdue'], 'follow-ups overdue', $counts['overdue'] > 0 ? 'danger' : 'ok')
            ->financials([
                ['key' => 'visits', 'label' => 'Visits', 'value' => $counts['total'], 'foot' => 'last ' . self::RECENT_DAYS . ' days'],
                ['key' => 'yes',    'label' => 'Interested', 'value' => $counts['positive'], 'foot' => 'wants to buy or wants a quote'],
                // Deliberately not "visits minus yeses". "Owner was out" is not a no: nobody was
                // asked, so nothing was learned, and counting it as a refusal poisons every future
                // count of what this market actually thinks.
                ['key' => 'known',  'label' => 'Told us something', 'value' => $counts['informative'],
                 'foot' => $counts['total'] > $counts['informative']
                     ? ($counts['total'] - $counts['informative']) . ' nobody was there to ask'
                     : 'every visit reached somebody'],
                ['key' => 'due',    'label' => 'Follow-ups open', 'value' => $counts['due'],
                 'tone' => $counts['overdue'] > 0 ? 'warn' : null,
                 'foot' => $counts['overdue'] > 0 ? $counts['overdue'] . ' already late' : 'none late'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'visits', 'ttl' => 60, 'permissions' => ['menu:customers']]);
    }

    /** @param array<int,array> $rows */
    private function attachItems(int $t, $visits, array &$rows): void
    {
        if ($visits->isEmpty()) {
            return;
        }
        try {
            $byVisit = \App\Models\VisitItem::withoutGlobalScopes()
                ->where('tenant_id', $t)->whereIn('visit_id', $visits->pluck('id')->all())
                ->get(['visit_id', 'name', 'stocked'])
                ->groupBy('visit_id');
        } catch (\Throwable $e) {
            return;
        }

        foreach ($visits->values() as $i => $v) {
            $items = $byVisit[$v->id] ?? collect();
            if ($items->isEmpty() || ! isset($rows[$i])) {
                continue;
            }
            // The ones the shop does not stock are the point, so they are named first and marked.
            $missing = $items->where('stocked', false)->pluck('name')->all();
            $have    = $items->where('stocked', true)->pluck('name')->all();
            $txt = [];
            foreach (array_slice($missing, 0, 3) as $m) { $txt[] = $m . ' ✕'; }
            foreach (array_slice($have, 0, 2) as $h)    { $txt[] = $h; }
            $rows[$i]['asked'] = [
                'value' => implode(' · ', $txt),
                'sub'   => $missing ? count($missing) . ' not stocked' : '',
            ];
        }
    }

    /** The form a rep fills in. Everything optional except the business and what happened. */
    private function logAction(CapabilityContext $ctx): array
    {
        $t = $ctx->tenantId();
        // Real customers and discovered businesses both — visiting a stranger is the whole point.
        $picker = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t)
            ->orderBy('name')->limit(2000)->get(['id', 'name', 'phone', 'city', 'stage']);

        $map = []; $opts = [];
        foreach ($picker as $p) {
            $label = trim((string) ($p->name ?: $p->phone ?: ('#' . $p->id)));
            if ($label === '' || isset($map[$label])) {
                $label .= ' · ' . ($p->city ?: $p->phone ?: $p->id);
            }
            if (BusinessStage::isDiscovered($p->stage)) {
                $label .= ' (not yet a customer)';
            }
            $map[$label] = (int) $p->id;
            $opts[] = $label;
        }

        return [
            'key' => 'log_visit', 'label' => 'Log a visit', 'tone' => 'primary', 'actor' => 'human',
            'endpoint' => '/api/v1/workspace/visits/_/log_visit',
            'schema' => [
                'business'    => 'combo:' . implode('|', $opts),
                'outcome'     => 'enum:' . implode(',', array_values(VisitOutcome::options())),
                'asked_for'   => 'textarea',
                'notes'       => 'textarea',
                'next_action' => 'text',
                'next_action_at' => 'date',
                'photos'      => 'gallery',
            ],
            'labels' => [
                'business'  => 'Which business',
                'outcome'   => 'What happened',
                'asked_for' => 'What did they ask for?'
                    . '<br><span class="mut" style="font-weight:400;font-size:12px">'
                    . 'One per line. Anything you do not stock lands on the <b>Asked for</b> screen '
                    . 'with this customer&rsquo;s number beside it.</span>',
                'notes'     => 'Notes',
                'next_action' => 'What happens next <span class="mut" style="font-weight:400;font-size:12px">(left blank, the outcome fills it in)</span>',
                'next_action_at' => 'By when',
                'photos'    => 'Photos',
            ],
            'valueMap' => ['field' => 'business', 'map' => $map, 'into' => 'customer_profile_id',
                           'error' => 'Pick a business from the list.'],
            'submitLabel' => 'Save visit',
        ];
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['open' => Visit::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())
            ->where('next_action_done', false)->whereNotNull('next_action_at')
            ->where('next_action_at', '<', now())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $uid = (int) ($ctx->user->id ?? 0);

        if ($action === 'log_visit') {
            // The enum posts the LABEL, because that is what a person picked. Map it back.
            $outcome = '';
            foreach (VisitOutcome::options() as $key => $label) {
                if ($label === (string) ($input['outcome'] ?? '')) {
                    $outcome = $key;
                    break;
                }
            }

            $items = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) ($input['asked_for'] ?? '')) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $items[] = ['name' => $line];
                }
            }

            $r = app(VisitService::class)->record($ctx->tenant, [
                'customer_profile_id' => (int) ($input['customer_profile_id'] ?? 0),
                'outcome'             => $outcome ?: (string) ($input['outcome'] ?? ''),
                'notes'               => (string) ($input['notes'] ?? ''),
                'photos'              => (array) ($input['photos'] ?? []),
                'latitude'            => $input['lat'] ?? null,
                'longitude'           => $input['lng'] ?? null,
                'next_action'         => (string) ($input['next_action'] ?? ''),
                'next_action_at'      => $input['next_action_at'] ?? null,
                'items'               => $items,
            ], $uid, (string) ($ctx->user->name ?? ''));

            if (! $r['ok']) {
                return ['ok' => false, 'error' => $r['error'] ?? 'Could not save the visit.'];
            }

            $msg = 'Visit saved.';
            if (($r['items'] ?? 0) > 0) {
                $msg .= ' ' . $r['items'] . ' item(s) noted.';
            }
            if (! empty($r['promoted'])) {
                // Worth saying out loud: this is the moment a discovered business enters the
                // customer list, and it happened because a person went there.
                $msg .= ' They are no longer just a discovered business.';
            }

            return ['ok' => true, 'message' => $msg];
        }

        if ($action === 'close_action') {
            $v = Visit::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
            if (! $v) {
                return ['ok' => false, 'error' => 'That visit is no longer here.'];
            }
            $v->next_action_done = true;
            $v->save();

            return ['ok' => true, 'message' => 'Marked done.'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
