<?php
namespace App\Apps\DailyMenu;

use App\Models\DailyMenu;
use App\Models\KnowledgeAction;
use App\Models\Product;
use App\Services\Knowledge\Contracts\Projector;
use App\Services\Knowledge\OperationalStateStore;

/**
 * Owns the Daily Menu projections: writes meal membership/specials into daily_menus (by date)
 * and availability into the generic OperationalStateStore (never into the legacy per-day store).
 */
class MenuProjector implements Projector
{
    public const CAP = 'daily_menu';

    public function __construct(private OperationalStateStore $state) {}

    public function apply(KnowledgeAction $action): string
    {
        $p = $action->params_json ?? [];
        $date = $p['date'] ?? date('Y-m-d');

        return match ($action->action_type) {
            'add_menu_item'    => $this->addItem((int) $action->tenant_id, $date, (string) $p['meal'], (string) $action->target, $p['price'] ?? null),
            'clear_meal'       => $this->clearMeal((int) $action->tenant_id, $date, (string) $p['meal']),
            'add_special'      => $this->addSpecial((int) $action->tenant_id, $date, (string) $action->target),
            'mark_unavailable' => $this->markUnavailable((int) $action->tenant_id, $date, (string) $action->target),
            default            => 'noop',
        };
    }

    public function revert(KnowledgeAction $action): void
    {
        // Phase 1: menu reverts are covered by the merchant change-request undo snapshot (2b).
    }

    private function row(int $tenantId, string $date): DailyMenu
    {
        return DailyMenu::firstOrNew(['tenant_id' => $tenantId, 'menu_date' => $date]);
    }

    private function addItem(int $tenantId, string $date, string $meal, string $name, ?int $price): string
    {
        if ($price !== null) {
            if ($pr = Product::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()) { $pr->price = $price; $pr->save(); }
        }
        $row = $this->row($tenantId, $date);
        $payload = $row->payload_json ?? ['meals' => [], 'specials' => []];
        $payload['meals'][$meal][] = ['name' => $name, 'price' => $price];
        $row->payload_json = $payload; $row->source = 'owner_whatsapp'; $row->save();
        return "menu:{$meal}+{$name}";
    }

    private function clearMeal(int $tenantId, string $date, string $meal): string
    {
        $row = $this->row($tenantId, $date);
        $payload = $row->payload_json ?? ['meals' => [], 'specials' => []];
        $payload['meals'][$meal] = [];
        $row->payload_json = $payload; $row->save();
        return "menu:clear:{$meal}";
    }

    private function addSpecial(int $tenantId, string $date, string $name): string
    {
        $row = $this->row($tenantId, $date);
        $payload = $row->payload_json ?? ['meals' => [], 'specials' => []];
        $payload['specials'][] = ['name' => $name];
        $row->payload_json = $payload; $row->save();
        return "special+{$name}";
    }

    private function markUnavailable(int $tenantId, string $date, string $name): string
    {
        $cur = $this->state->get($tenantId, self::CAP, 'unavailable', $date) ?? ['items' => []];
        $cur['items'] = array_values(array_unique(array_merge($cur['items'] ?? [], [$name])));
        $this->state->set($tenantId, self::CAP, 'unavailable', $cur, 'dated', $date);
        return "unavailable+{$name}";
    }
}
