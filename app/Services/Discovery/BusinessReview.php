<?php declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\BusinessSuggestion;
use App\Models\CustomerProfile;
use App\Services\Crm\BusinessStage;

/**
 * v4.62 — approving and rejecting discovered businesses.
 *
 * One place, because there will be three callers — the row buttons, the bulk action, and whatever
 * imports a spreadsheet next — and the safety check has to be identical in all of them. Modelled
 * directly on `SuggestionReview`, which has been doing exactly this job for product research.
 *
 * THE CHECK, and it is made TWICE: a suggestion is only ever written into a field that is STILL
 * empty. Once when the row was built, and again here, because a row may have been raised days ago
 * and somebody may have typed the answer since. In bulk it matters far more, because nobody reads
 * 800 rows carefully enough to notice.
 *
 * A SECOND CHECK, particular to businesses: a row that was going to CREATE a record re-looks for a
 * profile with that phone first. Between the run and the approval, the business may have walked in
 * and bought something — and creating a second record for a customer who already exists is the one
 * outcome that would make discovery worse than not having it.
 */
final class BusinessReview
{
    /**
     * Apply one row. Returns why it did not apply rather than failing silently.
     *
     * @return array{ok:bool, reason:string, profile_id?:int, wrote?:int}
     */
    public static function applyOne(BusinessSuggestion $s, int $userId): array
    {
        if ((string) $s->status !== 'pending') {
            return ['ok' => false, 'reason' => 'already_decided'];
        }

        $proposed = (array) ($s->proposed ?? []);
        if (! $proposed) {
            self::close($s, $userId, 'dismissed');

            return ['ok' => false, 'reason' => 'nothing_to_write'];
        }

        $profile = self::resolve($s);

        // Re-checked NOW, not trusted from when the row was built. Anything a person has typed in
        // the meantime wins, always — their value is the one somebody can be asked about.
        $writes = [];
        foreach ($proposed as $column => $value) {
            if (! in_array($column, BusinessMatch::COLUMN, true)) {
                continue;                       // a column outside the whitelist is never written
            }
            if ($profile && ! BusinessMatch::isEmpty($profile->{$column} ?? null)) {
                continue;
            }
            $writes[$column] = $value;
        }

        if (! $writes) {
            // Everything it had to offer has been answered by a human since. Their work stands and
            // the row goes away — that is the rule working, not a failure.
            self::close($s, $userId, 'dismissed');

            return ['ok' => false, 'reason' => 'already_filled'];
        }

        if (! $profile) {
            $profile = new CustomerProfile();
            $profile->tenant_id     = $s->tenant_id;
            // The stage that keeps this record out of every customer count and every campaign
            // audience until it buys something. v4.60 is what makes that true.
            $profile->stage         = BusinessStage::DISCOVERED;
            $profile->discovered_at = now();
            $profile->source        = (string) ($s->provider ?: 'discovery');
        }

        foreach ($writes as $column => $value) {
            $profile->{$column} = $value;
        }
        $profile->save();

        self::close($s, $userId, 'applied');
        $s->customer_profile_id = $profile->id;
        $s->save();

        return ['ok' => true, 'reason' => 'applied', 'profile_id' => (int) $profile->id, 'wrote' => count($writes)];
    }

    public static function dismissOne(BusinessSuggestion $s, int $userId): void
    {
        self::close($s, $userId, 'dismissed');
    }

    /**
     * @param  iterable<BusinessSuggestion>  $rows
     * @return array{applied:int, skipped:int, created:int, reasons:array<string,int>}
     */
    public static function applyMany(iterable $rows, int $userId): array
    {
        $applied = 0; $skipped = 0; $created = 0; $reasons = [];
        foreach ($rows as $s) {
            $isNew = $s->customer_profile_id === null;
            $r = self::applyOne($s, $userId);
            if ($r['ok']) {
                $applied++;
                if ($isNew) {
                    $created++;
                }
            } else {
                $skipped++;
                $reasons[$r['reason']] = ($reasons[$r['reason']] ?? 0) + 1;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'created' => $created, 'reasons' => $reasons];
    }

    /** @param iterable<BusinessSuggestion> $rows */
    public static function dismissMany(iterable $rows, int $userId): int
    {
        $n = 0;
        foreach ($rows as $s) {
            self::close($s, $userId, 'dismissed');
            $n++;
        }

        return $n;
    }

    /**
     * The row's target, re-resolved at approval time.
     *
     * The stored id first, then the phone — because a row raised as "new business" may have become
     * an existing customer since, and creating a second record for them is the one outcome that
     * would make discovery worse than not having it.
     */
    private static function resolve(BusinessSuggestion $s): ?CustomerProfile
    {
        if ($s->customer_profile_id) {
            $p = CustomerProfile::withoutGlobalScopes()->find($s->customer_profile_id);
            if ($p) {
                return $p;
            }
            // The record was deleted after the row was raised. Fall through: the business is still
            // real and re-creating it is the right answer, not an error.
        }

        // BusinessIdentity::digits() applies the "too short to be a phone number" rule, so the
        // minimum lives in one place rather than being restated here as a number.
        $digits = BusinessIdentity::digits((string) $s->phone);
        if ($digits === '') {
            return null;
        }

        return CustomerProfile::withoutGlobalScopes()
            ->where('tenant_id', $s->tenant_id)
            ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$digits])
            ->orderBy('id')
            ->first();
    }

    private static function close(BusinessSuggestion $s, int $userId, string $status): void
    {
        $s->status = $status;
        $s->decided_by = $userId ?: null;
        $s->decided_at = now();
        $s->save();
    }
}
