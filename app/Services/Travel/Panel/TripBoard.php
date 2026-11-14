<?php

namespace App\Services\Travel\Panel;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryEvent;
use App\Models\User;
use App\Services\Travel\Quality\EnquiryMetrics;
use Illuminate\Support\Collection;

/**
 * Read side of the Trips screen (Travel Vertical · M1): the enquiries a tenant's staff see, the
 * staff names for "owner", and the rows EnquiryMetrics needs. Every query names the tenant.
 */
final class TripBoard
{
    public const LIST_LIMIT = 300;

    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** @return Collection<int,TravelEnquiry> newest activity first */
    public function enquiries(): Collection
    {
        return TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->with('travellers')
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
    }

    public function find(int $id): ?TravelEnquiry
    {
        return TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->find($id);
    }

    /** @return array<int,string> user id => name, this tenant's staff only */
    public function staff(): array
    {
        return User::query()->where('tenant_id', $this->tenant->id)->orderBy('name')->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)->all();
    }

    /** @return Collection<int,TravelEnquiryEvent> oldest first */
    public function events(int $enquiryId): Collection
    {
        return TravelEnquiryEvent::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('enquiry_id', $enquiryId)->orderBy('id')->limit(500)->get();
    }

    /**
     * The rows EnquiryMetrics::compute() takes, for these enquiries.
     *
     * @param  Collection<int,TravelEnquiry> $enquiries
     * @return array<int,array<string,mixed>> keyed by enquiry id
     */
    public function metricRows(Collection $enquiries): array
    {
        if ($enquiries->isEmpty()) {
            return [];
        }
        $ids = $enquiries->pluck('id')->all();

        $events = TravelEnquiryEvent::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->whereIn('enquiry_id', $ids)->whereIn('type', ['stage_changed', 'handover_started', 'ai_flagged', 'guard_hit', 'validator_reject'])
            ->get(['enquiry_id', 'type', 'meta']);
        $byEnquiry = [];
        foreach ($events as $ev) {
            $row = &$byEnquiry[$ev->enquiry_id];
            $row ??= ['reached' => [], 'handover' => false, 'flagged' => 0, 'guard_hits' => 0];
            $meta = is_array($ev->meta) ? $ev->meta : [];
            match ($ev->type) {
                'stage_changed'    => $row['reached'][] = (string) ($meta['to'] ?? ''),
                'handover_started' => $row['handover'] = true,
                'ai_flagged'       => $row['flagged']++,
                'guard_hit'        => $row['guard_hits'] += max(1, (int) ($meta['count'] ?? 1)),
                'validator_reject' => $row['guard_hits']++,
                default            => null,
            };
            unset($row);
        }

        // A person wrote in the chat after the enquiry opened: last staff/owner message per customer.
        $phones = $enquiries->pluck('customer_phone')->unique()->values()->all();
        $lastHuman = Message::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->whereIn('customer_phone', $phones)->where('direction', 'out')->whereIn('sender', ['agent', 'human'])
            ->selectRaw('customer_phone, MAX(created_at) as last_at')->groupBy('customer_phone')->pluck('last_at', 'customer_phone');

        $out = [];
        foreach ($enquiries as $e) {
            $x = $byEnquiry[$e->id] ?? ['reached' => [], 'handover' => false, 'flagged' => 0, 'guard_hits' => 0];
            $human = isset($lastHuman[$e->customer_phone]) && $e->created_at
                && strtotime((string) $lastHuman[$e->customer_phone]) >= $e->created_at->getTimestamp();
            $out[$e->id] = [
                'stage'            => (string) $e->stage,
                'reached'          => array_values(array_filter($x['reached'])),
                'last_customer_at' => $e->last_customer_at ? $e->last_customer_at->getTimestamp() : null,
                'human_touched'    => $human,
                'handover'         => $x['handover'],
                'flagged'          => $x['flagged'],
                'guard_hits'       => $x['guard_hits'],
            ];
        }

        return $out;
    }

    public function abandonHours(): int
    {
        return max(1, (int) $this->tenant->setting('travel_abandon_hours', 48));
    }

    /** @return array<string,int|float|null> */
    public function metrics(Collection $enquiries): array
    {
        return EnquiryMetrics::compute(array_values($this->metricRows($enquiries)), time(), $this->abandonHours());
    }
}
