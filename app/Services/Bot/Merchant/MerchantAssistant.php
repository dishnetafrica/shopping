<?php
namespace App\Services\Bot\Merchant;

use App\Models\Conversation;
use App\Models\MerchantChangeRequest;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalogue\ProductSearch;

/**
 * Merchant Conversation Mode orchestration. Returns a reply string for an authorized
 * merchant's admin message / confirmation / self-check, or null to fall through to the
 * normal customer shopping flow. Framework code — deploy-tested.
 *
 * Pure pieces it leans on (unit-tested): MerchantConversationParser, MerchantDirectory,
 * DailyState, MerchantSummary, PriceGuard.
 */
class MerchantAssistant
{
    private const EXPIRE_MIN = 15;

    public function __construct(
        protected ProductSearch $search,
        protected MerchantChangeApplier $applier,
    ) {}

    public function handle(Tenant $tenant, Conversation $convo, string $text): ?string
    {
        if (! MerchantDirectory::isAuthorized($tenant, (string) $convo->customer_phone)) {
            return null;                                            // not a merchant → customer flow
        }
        $t = trim($text);
        $low = mb_strtolower($t);
        $pendingId = data_get($convo->state, 'merchant_pending');

        // 1) resolve an outstanding confirmation
        if ($pendingId && $req = MerchantChangeRequest::where('id', $pendingId)->where('status', 'pending')->first()) {
            if (in_array($low, ['yes', 'y', 'ha', 'haa', 'confirm', 'ok', 'okay'], true)) {
                return $this->confirm($convo, $req);
            }
            if (in_array($low, ['no', 'n', 'cancel', 'nahi', 'nathi'], true)) {
                $req->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                $this->clearPending($convo);
                return 'Cancelled — nothing changed.';
            }
            // any other message supersedes the pending one
            $req->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            $this->clearPending($convo);
        }

        // 2) undo
        if (preg_match('/^undo(?:\s+last(?:\s+change)?)?$/i', $low)) {
            return $this->proposeUndo($tenant, $convo);
        }

        // 3) extract changes
        $res = MerchantConversationParser::extract($t);

        if ($res['changes']) {
            return $this->propose($tenant, $convo, $res);
        }
        if ($res['selfcheck']) {
            return $this->selfCheckReport($tenant, $res['selfcheck']);
        }
        return null;                                               // merchant ordering / chit-chat → customer flow
    }

    // ---- propose ----
    private function propose(Tenant $tenant, Conversation $convo, array $res): ?string
    {
        $resolved = []; $notFound = $res['unparsed'];

        foreach ($res['changes'] as $c) {
            switch ($c['type']) {
                case 'availability':
                case 'special':
                    if ($p = $this->find($c['target'])) { $c['product_id'] = $p->id; $c['label'] = $p->name; $resolved[] = $c; }
                    else $notFound[] = $c['target'];
                    break;
                case 'price':
                    if ($p = $this->find($c['target'])) {
                        $c['product_id'] = $p->id; $c['label'] = $p->name;
                        $c['old'] = $this->oldPrice($p, $c['weight_grams'] ?? null);
                        if ($w = PriceGuard::warn($c['old'], (int) $c['price'])) $c['warn'] = $w;
                        $resolved[] = $c;
                    } else $notFound[] = $c['target'];
                    break;
                case 'menu':
                    $ids = []; $labels = [];
                    foreach ($c['items'] as $name) {
                        if ($p = $this->find($name)) { $ids[] = $p->id; $labels[] = $p->name; }
                        else $notFound[] = $name;
                    }
                    if ($ids) { $c['items_ids'] = $ids; $c['labels'] = $labels; $resolved[] = $c; }
                    break;
                default:                                            // hours, notice, note — no resolution
                    $resolved[] = $c;
            }
        }

        if (! $resolved) {
            return "I couldn't find: " . implode(', ', array_unique($notFound)) . ".\nTry the exact product name.";
        }

        $req = MerchantChangeRequest::create([
            'tenant_id' => $tenant->id,
            'merchant_phone' => MerchantDirectory::normalize((string) $convo->customer_phone),
            'change_type' => 'batch',
            'payload_json' => $resolved,
            'status' => 'pending',
            'conversation_id' => $convo->id,
        ]);
        $this->setPending($convo, $req->id);

        return MerchantSummary::render($resolved, $notFound);
    }

    // ---- confirm ----
    private function confirm(Conversation $convo, MerchantChangeRequest $req): string
    {
        if ($req->created_at && $req->created_at->diffInMinutes(now()) > self::EXPIRE_MIN) {
            $req->forceFill(['status' => 'expired'])->save();
            $this->clearPending($convo);
            return 'That request expired. Please resend it.';
        }
        if ($req->change_type === 'undo') {
            $target = MerchantChangeRequest::find($req->payload_json['undo_of'] ?? 0);
            if ($target) $this->applier->undo($target);
            $req->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'applied_at' => now()])->save();
            $this->clearPending($convo);
            return '✅ Reverted the last change.';
        }
        $this->applier->apply($req);
        $this->clearPending($convo);
        $n = count($req->payload_json);
        return "✅ Applied {$n} change" . ($n === 1 ? '' : 's') . '. (undo: "undo last change")';
    }

    // ---- undo proposal ----
    private function proposeUndo(Tenant $tenant, Conversation $convo): string
    {
        $last = MerchantChangeRequest::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')->where('change_type', 'batch')
            ->orderByDesc('applied_at')->first();
        if (! $last) return 'Nothing to undo.';

        $req = MerchantChangeRequest::create([
            'tenant_id' => $tenant->id,
            'merchant_phone' => MerchantDirectory::normalize((string) $convo->customer_phone),
            'change_type' => 'undo',
            'payload_json' => ['undo_of' => $last->id],
            'status' => 'pending',
            'conversation_id' => $convo->id,
        ]);
        $this->setPending($convo, $req->id);

        $n = count($last->payload_json);
        return "Undo the last change ({$n} item" . ($n === 1 ? '' : 's') . ")?\nReply YES to confirm.";
    }

    // ---- self-check ----
    private function selfCheckReport(Tenant $tenant, array $want): string
    {
        $ds = DailyState::get($tenant);
        $names = fn (array $ids) => Product::whereIn('id', $ids)->pluck('name')->map(fn ($n) => ucwords($n))->all();
        $out = ['Today (' . date('j M') . '):'];

        if (in_array('menu', $want, true) || in_array('availability', $want, true)) {
            $menu = $ds['menu'] ? implode(', ', $names($ds['menu'])) : '(all products)';
            $out[] = 'Menu: ' . $menu;
            if ($ds['unavailable']) $out[] = 'Unavailable: ' . implode(', ', $names($ds['unavailable']));
        }
        if (in_array('specials', $want, true)) {
            $out[] = 'Specials: ' . ($ds['specials'] ? implode(', ', $names($ds['specials'])) : '—');
        }
        if (in_array('hours', $want, true)) {
            $h = $ds['hours'];
            $out[] = ($h['closed'] ?? false) ? 'Closed today.'
                : 'Hours: ' . ($h['open'] ?? '—') . '–' . ($h['close'] ?? '—');
        }
        if (! empty($ds['notice'])) $out[] = 'Notice: ' . implode(' | ', $ds['notice']);
        return implode("\n", $out);
    }

    // ---- helpers ----
    private function find(string $name): ?Product
    {
        $n = trim($name);
        return $n === '' ? null : $this->search->find($n)->first();
    }

    private function oldPrice(Product $p, ?int $grams): ?int
    {
        if ($grams && $p->sold_by_weight) {
            if ((int) $grams === (int) $p->reference_weight_grams) return $p->reference_price !== null ? (int) $p->reference_price : null;
            $v = $p->weightVariants()->where('weight_grams', $grams)->first();
            return $v ? (int) $v->price : null;
        }
        return $p->price !== null ? (int) $p->price : null;
    }

    private function setPending(Conversation $convo, int $id): void
    {
        $s = $convo->state ?? []; $s['merchant_pending'] = $id; $convo->state = $s; $convo->save();
    }

    private function clearPending(Conversation $convo): void
    {
        $s = $convo->state ?? []; unset($s['merchant_pending']); $convo->state = $s; $convo->save();
    }
}
