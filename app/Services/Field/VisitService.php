<?php declare(strict_types=1);

namespace App\Services\Field;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Visit;
use App\Models\VisitItem;
use App\Services\Crm\BusinessStage;
use App\Services\Growth\UnmetDemandService;
use Illuminate\Support\Facades\DB;

/**
 * v4.63 — recording a visit.
 *
 * One writer, so that everything a visit implies happens together or not at all: the visit row, the
 * things that were asked for, the follow-up, and — only ever from DISCOVERED — the stage.
 *
 * The transaction matters more here than it looks. A visit whose ITEMS failed to save is worse than
 * no visit at all: the rep believes the shop now knows somebody asked for brake pads, and it does
 * not, so nobody orders them and nobody ever finds out why.
 */
final class VisitService
{
    /** More than this on one visit is a stock-take, not a conversation. */
    private const MAX_ITEMS = 40;

    /**
     * @param  array{
     *   customer_profile_id:int, outcome:string, notes?:string, photos?:array,
     *   latitude?:float|string|null, longitude?:float|string|null,
     *   next_action?:string, next_action_at?:string|null,
     *   items?:array<int,array{name:string, qty?:float, product_id?:int|null, note?:string}>
     * } $in
     * @return array{ok:bool, error?:string, id?:int, items?:int, promoted?:string|null}
     */
    public function record(Tenant $t, array $in, ?int $userId, ?string $userName = null): array
    {
        // The closed vocabulary, enforced here rather than by a database constraint — so changing it
        // is a code change with a test rather than a migration against a live table.
        $outcome = VisitOutcome::norm($in['outcome'] ?? '');
        if (! VisitOutcome::isValid($outcome)) {
            return ['ok' => false, 'error' => 'Choose what happened on the visit.'];
        }

        $profile = CustomerProfile::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->find((int) ($in['customer_profile_id'] ?? 0));
        if (! $profile) {
            return ['ok' => false, 'error' => 'That business is no longer here.'];
        }

        $now = now();
        $due = VisitOutcome::followUpDays($outcome);
        $nextAction = trim((string) ($in['next_action'] ?? ''));
        if ($nextAction === '') {
            // A rep who types nothing still gets the follow-up the outcome implies. Left blank, a
            // "wants a quotation" visit becomes a note nobody acts on, which is the failure this
            // whole table exists to stop.
            $nextAction = VisitOutcome::nextStep($outcome);
        }
        $nextAt = $in['next_action_at'] ?? null;
        if (! $nextAt && $due > 0) {
            $nextAt = $now->copy()->addDays($due);
        }

        // Both halves or neither. Stored as (4.85, null) a reading renders at (4.85, 0) — open water
        // in the Gulf of Guinea, 1,700 km from Juba — and on a map that is a dot, not an error.
        $coords = Coordinates::pair($in['latitude'] ?? null, $in['longitude'] ?? null);
        if (Coordinates::isNullIsland($coords[0], $coords[1])) {
            $coords = [null, null];      // a broken sensor, not a place
        }

        $promoted = null;
        $itemCount = 0;
        $visitId = 0;

        try {
            DB::transaction(function () use ($t, $in, $profile, $outcome, $userId, $userName, $now, $nextAction, $nextAt, $coords, &$promoted, &$itemCount, &$visitId) {
                $visit = Visit::create([
                    'tenant_id'           => $t->id,
                    'customer_profile_id' => $profile->id,
                    'user_id'             => $userId,
                    'user_name'           => $userName,
                    'checked_in_at'       => $in['checked_in_at'] ?? $now,
                    'checked_out_at'      => $in['checked_out_at'] ?? $now,
                    'latitude'            => $coords[0],
                    'longitude'           => $coords[1],
                    'outcome'             => $outcome,
                    'notes'               => trim((string) ($in['notes'] ?? '')) ?: null,
                    'photos'              => array_values(array_filter((array) ($in['photos'] ?? []))),
                    'next_action'         => $nextAction ?: null,
                    'next_action_at'      => $nextAt,
                    'next_action_done'    => false,
                ]);

                $visitId   = (int) $visit->id;
                $itemCount = $this->saveItems($t, $visit, (array) ($in['items'] ?? []));

                // Only ever from DISCOVERED. v4.60's rule stands underneath: money is what makes a
                // business a customer; this only decides when a stranger stops being a stranger.
                $newStage = VisitOutcome::promotesFrom($outcome, (string) $profile->stage);
                if ($newStage !== null && $newStage !== BusinessStage::norm($profile->stage)) {
                    $profile->stage = $newStage;
                    $profile->stage_changed_at = $now;
                    $profile->save();
                    $promoted = $newStage;
                }
            });
        } catch (\Throwable $e) {
            \Log::warning('visit not recorded: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not save the visit.'];
        }

        // Demand from the field joins demand from the bot and the storefront. Never fatal: a cache
        // key that will not clear must not lose a visit that has already been written.
        try { UnmetDemandService::forget((int) $t->id); } catch (\Throwable $e) {}

        return ['ok' => true, 'id' => $visitId, 'items' => $itemCount, 'promoted' => $promoted];
    }

    /**
     * The things that came up, matched to the catalogue where they exist.
     *
     * An item that matches nothing is KEPT, with `stocked = false`. That row is the point of the
     * table: unmet demand arriving from the field rather than from the bot, with a phone number
     * attached to it, which is what turns a report into a callback list.
     */
    private function saveItems(Tenant $t, Visit $visit, array $items): int
    {
        $n = 0;
        foreach (array_slice($items, 0, self::MAX_ITEMS) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $productId = isset($row['product_id']) && $row['product_id'] ? (int) $row['product_id'] : null;
            if ($productId === null) {
                $productId = $this->matchProduct($t, $name);
            }

            VisitItem::create([
                'tenant_id'  => $t->id,
                'visit_id'   => $visit->id,
                'product_id' => $productId,
                'name'       => mb_substr($name, 0, 200),
                'qty'        => isset($row['qty']) && $row['qty'] !== '' ? (float) $row['qty'] : null,
                'stocked'    => $productId !== null,
                'note'       => mb_substr(trim((string) ($row['note'] ?? '')), 0, 300) ?: null,
            ]);
            $n++;
        }

        return $n;
    }

    /** An exact-ish name match only. A fuzzy guess here would hide real unmet demand as a sale. */
    private function matchProduct(Tenant $t, string $name): ?int
    {
        try {
            $id = Product::withoutGlobalScopes()
                ->where('tenant_id', $t->id)
                ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
                ->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
