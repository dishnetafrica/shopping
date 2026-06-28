<?php
namespace App\Apps\DailyMenu;

use App\Models\DailyMenu;
use App\Models\KnowledgeAction;
use Illuminate\Support\Facades\Log;

/**
 * Diagnostic only (Phase 1): rebuild a projection from the knowledge store and diff it against the
 * live projection. Does not block requests or auto-repair — it just surfaces drift so we trust the
 * projections over time. Currently covers the Daily Menu projection.
 */
class MenuParity
{
    /** @return array{match:bool,expected:array,live:array,diff:array} */
    public function checkDailyMenu(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');

        // Rebuild expected menu membership from applied menu actions for this date, in order.
        $expected = ['meals' => [], 'specials' => []];
        $actions = KnowledgeAction::where('tenant_id', $tenantId)->where('capability', 'daily_menu')
            ->where('status', 'applied')
            ->whereJsonContains('params_json->date', $date)
            ->orderBy('id')->get();

        foreach ($actions as $a) {
            $p = $a->params_json ?? [];
            switch ($a->action_type) {
                case 'add_menu_item': $expected['meals'][$p['meal']][] = ['name' => $a->target, 'price' => $p['price'] ?? null]; break;
                case 'clear_meal':    $expected['meals'][$p['meal']] = []; break;
                case 'add_special':   $expected['specials'][] = ['name' => $a->target]; break;
            }
        }

        $live = DailyMenu::where('tenant_id', $tenantId)->where('menu_date', $date)->value('payload_json')
            ?? ['meals' => [], 'specials' => []];

        $diff = $this->diff($expected, $live);
        if ($diff) {
            Log::warning('OKE projection parity mismatch', ['tenant' => $tenantId, 'date' => $date, 'diff' => $diff]);
        }
        return ['match' => $diff === [], 'expected' => $expected, 'live' => $live, 'diff' => $diff];
    }

    private function diff(array $expected, array $live): array
    {
        $norm = fn ($a) => json_encode($this->sort($a));
        return $norm($expected) === $norm($live) ? [] : ['expected' => $expected, 'live' => $live];
    }

    private function sort(array $a): array
    {
        ksort($a);
        foreach ($a as &$v) { if (is_array($v)) $v = $this->sort($v); }
        return $a;
    }
}
