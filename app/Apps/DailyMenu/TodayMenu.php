<?php
namespace App\Apps\DailyMenu;

use App\Models\DailyMenu;
use App\Services\Knowledge\OperationalStateStore;

/**
 * Customer-facing read model for the Daily Menu app: today's meal buckets + specials, minus
 * whatever was marked sold-out today (operational state). O(1)-ish read from the projection,
 * so the bot/storefront never interpret raw knowledge at request time.
 */
class TodayMenu
{
    public function __construct(private OperationalStateStore $state) {}

    public function for(int $tenantId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $row = DailyMenu::where('tenant_id', $tenantId)->where('menu_date', $date)->first();
        $payload = $row?->payload_json ?? ['meals' => [], 'specials' => []];

        $unavailable = ($this->state->get($tenantId, MenuProjector::CAP, 'unavailable', $date)['items'] ?? []);
        $unavailable = array_map('mb_strtolower', $unavailable);

        $meals = [];
        foreach (($payload['meals'] ?? []) as $meal => $items) {
            $meals[$meal] = array_values(array_filter($items, fn ($i) => ! in_array(mb_strtolower($i['name'] ?? ''), $unavailable, true)));
        }
        return ['date' => $date, 'meals' => $meals, 'specials' => $payload['specials'] ?? [], 'unavailable' => $unavailable];
    }
}
