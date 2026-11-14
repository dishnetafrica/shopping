<?php

namespace App\Services\Travel\Passport;

use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelTraveller;
use App\Services\Travel\Enquiry\EnquiryService;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the document numbers of travellers on finished trips — the only code that does.
 *
 * A trip's numbers are deleted once it has been closed (completed, cancelled or lost) for the agency's
 * retention period (setting `travel_document_retention_days`, default 90), or once a trip that was
 * never closed has had no activity for 180 days (Travellers::deletionDue). The traveller's name, age
 * and the last four characters stay as booking history; the number itself, and the keyed fingerprint
 * derived from it, are removed. Each trip gets one timeline entry saying how many and why.
 *
 * Run daily by `travel:purge-documents` (routes/console.php).
 */
class TravellerRetention
{
    public const SETTING = 'travel_document_retention_days';

    public function __construct(private EnquiryService $enquiries) {}

    /**
     * @return array{checked:int,deleted:int,trips:int}
     */
    public function run(bool $dryRun = false): array
    {
        // Only trips that could possibly be due: closed at least the shortest allowed period ago, or
        // open and untouched for the inactivity period. The exact per-agency rule is applied below.
        $query = DB::table('travel_travellers as t')
            ->join('travel_enquiries as e', 'e.id', '=', 't.enquiry_id')
            ->whereNotNull('t.document_number')
            ->where(function ($q) {
                $q->where('e.closed_at', '<=', now()->subDays(Travellers::RETENTION_MIN_DAYS))
                    ->orWhere(fn ($o) => $o->whereNull('e.closed_at')->where('e.updated_at', '<=', now()->subDays(Travellers::INACTIVE_DAYS)));
            })
            ->select(['t.id', 't.tenant_id', 't.enquiry_id', 'e.closed_at', 'e.last_customer_at', 'e.updated_at']);

        $tenants = [];
        $due = [];                                   // enquiry_id => ['ids' => [...], 'rule' => ..., 'days' => ...]
        $checked = 0;
        $query->chunkById(500, function ($rows) use (&$tenants, &$due, &$checked) {
            foreach ($rows as $r) {
                $checked++;
                $tid = (int) $r->tenant_id;
                if (! array_key_exists($tid, $tenants)) {
                    $t = Tenant::find($tid);
                    $tenants[$tid] = $t ? [
                        'days'  => Travellers::retentionDays($t->setting(self::SETTING, null)),
                        'today' => now($this->enquiries->timezone($t))->format('Y-m-d'),
                        'tz'    => $this->enquiries->timezone($t),
                    ] : null;
                }
                $cfg = $tenants[$tid];
                if ($cfg === null) {
                    continue;
                }
                $day = fn ($ts) => $ts ? \Illuminate\Support\Carbon::parse($ts)->setTimezone($cfg['tz'])->format('Y-m-d') : null;
                $active = max((string) $day($r->last_customer_at), (string) $day($r->updated_at)) ?: null;
                $rule = Travellers::deletionDue($day($r->closed_at), $active, $cfg['today'], $cfg['days']);
                if ($rule !== null) {
                    $due[(int) $r->enquiry_id] ??= ['ids' => [], 'rule' => $rule, 'days' => $cfg['days'], 'tenant' => $tid];
                    $due[(int) $r->enquiry_id]['ids'][] = (int) $r->id;
                }
            }
        }, 't.id', 'id');

        $deleted = 0;
        if (! $dryRun) {
            foreach ($due as $enquiryId => $d) {
                DB::transaction(function () use ($enquiryId, $d, &$deleted) {
                    $n = 0;
                    foreach ($d['ids'] as $id) {
                        // The fingerprint is derived from the number, so it goes too (kept unique per trip).
                        $n += TravelTraveller::withoutGlobalScopes()->whereKey($id)->whereNotNull('document_number')
                            ->update(['document_number' => null, 'fingerprint' => 'deleted:' . $id, 'updated_at' => now()]);
                    }
                    $enquiry = TravelEnquiry::withoutGlobalScopes()->where('tenant_id', $d['tenant'])->find($enquiryId);
                    if ($n > 0 && $enquiry) {
                        $this->enquiries->log($enquiry, 'document_numbers_deleted', 'system', ['count' => $n, 'rule' => $d['rule'], 'days' => $d['days']]);
                    }
                    $deleted += $n;
                });
            }
        }

        return [
            'checked' => $checked,
            'deleted' => $dryRun ? array_sum(array_map(fn ($d) => count($d['ids']), $due)) : $deleted,
            'trips'   => count($due),
        ];
    }
}
