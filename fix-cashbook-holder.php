<?php
/**
 * One-off correction: re-assign a Trade Cashbook conversion to the right person.
 *
 * WHAT IT DOES
 *   Moves cashbook entry CB-0067 (and the matching leg of the same conversion)
 *   into "Jayesh Bhai"'s cash account. A conversion is two rows — the USD that
 *   left the hand and the UGX that came in — and both must sit with the same
 *   person, so this script fixes BOTH legs together.
 *
 * IT IS SAFE
 *   - Scoped to the entry's own tenant (no cross-company edits).
 *   - Runs inside a DB transaction; nothing is deleted.
 *   - Prints a BEFORE / AFTER table and writes an audit-log row for each change.
 *   - Set $DRY_RUN = true first to preview without saving.
 *
 * HOW TO RUN  (in the app container, e.g. EasyPanel -> Terminal)
 *   php artisan tinker
 *   >>> require base_path('fix-cashbook-holder.php');
 *
 * ---- EDIT THESE TWO IF NEEDED -------------------------------------------- */
$CB_ID       = 67;             // CB-0067  ->  order_costs.id = 67
$NEW_HOLDER  = 'Jayesh Bhai';  // exact person name as it appears in the panel
$MOVE_SIBLING = true;          // also move the other leg of the conversion (recommended)
$DRY_RUN      = false;         // true = preview only, false = actually save
/* ------------------------------------------------------------------------- */

use App\Models\OrderCost;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$target = OrderCost::find($CB_ID);
if (! $target) { echo "❌ CB-".str_pad($CB_ID,4,'0',STR_PAD_LEFT)." (id $CB_ID) not found.\n"; return; }

$tenantId = (int) $target->tenant_id;
$oldHolder = (string) $target->holder;

// Resolve the target person's user id within THIS tenant (name string still works if null).
$newUid = User::where('tenant_id',$tenantId)->where('name',$NEW_HOLDER)->value('id');

// Collect the rows to fix: the target, plus (optionally) its conversion sibling(s).
$rows = collect([$target]);
if ($MOVE_SIBLING && strtolower((string)$target->category) === 'conversion') {
    $windowStart = (clone $target->created_at)->subSeconds(10);
    $windowEnd   = (clone $target->created_at)->addSeconds(10);
    $siblings = OrderCost::where('tenant_id',$tenantId)
        ->where('id','!=',$target->id)
        ->whereRaw('LOWER(category) = ?', ['conversion'])
        ->whereBetween('created_at', [$windowStart, $windowEnd])
        ->where('holder', $oldHolder)          // same person as the target's current holder
        ->get();
    $rows = $rows->concat($siblings);
}

echo "\n=== ".($DRY_RUN?'PREVIEW (no changes saved)':'APPLYING CHANGES')." ===\n";
echo "Tenant: $tenantId   Move: '$oldHolder'  ->  '$NEW_HOLDER'".($newUid?" (user #$newUid)":" (name only, no user match)")."\n\n";
printf("%-9s %-9s %-10s %-14s %-16s %s\n",'REF','STAGE','CURRENCY','AMOUNT','HOLDER (old)','NOTE');
foreach ($rows as $r) {
    printf("%-9s %-9s %-10s %-14s %-16s %s\n",
        'CB-'.str_pad((string)$r->id,4,'0',STR_PAD_LEFT), $r->stage, $r->currency,
        number_format((float)$r->native_amount,2), (string)$r->holder, (string)$r->note);
}

if ($DRY_RUN) { echo "\nDry run only — set \$DRY_RUN = false to apply.\n"; return; }

DB::transaction(function () use ($rows, $NEW_HOLDER, $newUid, $tenantId) {
    foreach ($rows as $r) {
        $before = ['holder'=>$r->holder,'holder_user_id'=>$r->holder_user_id];
        $r->holder = $NEW_HOLDER;
        $r->holder_user_id = $newUid;
        $r->save();
        \App\Services\AuditLogger::log(
            $tenantId,'order_cost',$r->id,'reassign-holder','correction-script',
            $before, ['holder'=>$NEW_HOLDER,'holder_user_id'=>$newUid],
            'Cash reassigned to '.$NEW_HOLDER.' (data-entry correction)'
        );
    }
});

echo "\n✅ Done. ".$rows->count()." entr".($rows->count()===1?'y':'ies')." now held by '$NEW_HOLDER'.\n";
echo "Reload the Cashbook and check '$NEW_HOLDER''s balance.\n";
