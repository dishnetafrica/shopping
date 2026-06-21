<?php
namespace App\Services\Bot\Merchant;

use App\Models\MerchantChangeRequest;
use App\Models\Product;
use App\Models\ProductWeightVariant;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Applies a confirmed ChangeSet in one transaction and snapshots the prior state into
 * previous_json so a single "undo last change" reverses the whole set. Framework code —
 * deploy-tested (no DB in sandbox).
 *
 * Resolved payload change shapes (product_id present where relevant):
 *   {type:'menu', items_ids:[..]}
 *   {type:'availability', product_id, available:bool}
 *   {type:'special', product_id}
 *   {type:'hours', open?, close?, closed?}
 *   {type:'price', product_id, weight_grams|null, price}
 *   {type:'notice'|'note', text}
 */
class MerchantChangeApplier
{
    public function apply(MerchantChangeRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $tenant = Tenant::findOrFail($req->tenant_id);
            $ds = DailyState::get($tenant);
            $prev = ['daily_state' => $ds, 'products' => [], 'variants' => []];

            foreach ($req->payload_json as $c) {
                switch ($c['type']) {
                    case 'menu':
                        $ds['menu'] = array_values(array_unique($c['items_ids'] ?? []));
                        break;
                    case 'availability':
                        $id = (int) $c['product_id'];
                        if (empty($c['available'])) {
                            $ds['unavailable'] = array_values(array_unique(array_merge($ds['unavailable'] ?? [], [$id])));
                        } else {
                            $ds['unavailable'] = array_values(array_diff($ds['unavailable'] ?? [], [$id]));
                        }
                        break;
                    case 'special':
                        $ds['specials'] = array_values(array_unique(array_merge($ds['specials'] ?? [], [(int) $c['product_id']])));
                        break;
                    case 'hours':
                        $ds['hours'] = array_merge($ds['hours'] ?? [], array_filter([
                            'open' => $c['open'] ?? null, 'close' => $c['close'] ?? null,
                            'closed' => $c['closed'] ?? null,
                        ], fn ($v) => $v !== null));
                        break;
                    case 'notice':
                        $ds['notice'][] = trim($c['text'] ?? '');
                        break;
                    case 'note':
                        $ds['notes'][] = trim($c['text'] ?? '');
                        break;
                    case 'price':
                        $this->applyPrice($c, $prev);
                        break;
                }
            }

            DailyState::put($tenant, $ds);
            $req->forceFill([
                'previous_json' => $prev, 'status' => 'confirmed',
                'confirmed_at' => now(), 'applied_at' => now(),
            ])->save();
        });
    }

    private function applyPrice(array $c, array &$prev): void
    {
        $p = Product::find((int) $c['product_id']);
        if (! $p) return;
        $price = (int) $c['price'];
        $w = $c['weight_grams'] ?? null;

        if ($w && $p->sold_by_weight) {
            if ((int) $w === (int) $p->reference_weight_grams) {
                $prev['products'][$p->id]['reference_price'] = $p->reference_price;
                $p->reference_price = $price; $p->save();
            } else {
                $existing = ProductWeightVariant::where('product_id', $p->id)->where('weight_grams', $w)->first();
                $prev['variants'][] = ['product_id' => $p->id, 'weight_grams' => (int) $w,
                                       'old_price' => $existing->price ?? null, 'existed' => (bool) $existing];
                ProductWeightVariant::updateOrCreate(
                    ['product_id' => $p->id, 'weight_grams' => (int) $w], ['price' => $price]
                );
            }
        } else {
            $prev['products'][$p->id]['price'] = $p->price;
            $p->price = $price; $p->save();
        }
    }

    /** Reverse a previously confirmed request from its snapshot. */
    public function undo(MerchantChangeRequest $req): void
    {
        $prev = $req->previous_json ?? [];
        DB::transaction(function () use ($req, $prev) {
            $tenant = Tenant::findOrFail($req->tenant_id);
            if (isset($prev['daily_state'])) DailyState::put($tenant, $prev['daily_state']);

            foreach (($prev['products'] ?? []) as $pid => $fields) {
                if ($p = Product::find((int) $pid)) { $p->forceFill($fields)->save(); }
            }
            foreach (($prev['variants'] ?? []) as $v) {
                $q = ProductWeightVariant::where('product_id', $v['product_id'])->where('weight_grams', $v['weight_grams']);
                if (! ($v['existed'] ?? false)) { $q->delete(); }
                elseif ($v['old_price'] !== null) { $q->update(['price' => $v['old_price']]); }
            }
            $req->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        });
    }
}
