<?php declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\AbstractWidget;
use App\Models\Tenant;
use App\Services\Money\CashService;

/**
 * Cash on hand per currency (Roadmap E10.1) — reads the CashService SSOT. Owner/manager see everyone;
 * a staff member sees only their own cash.
 */
class CashOnHandWidget extends AbstractWidget
{
    public function key(): string { return 'cash_on_hand'; }
    public function title(): string { return 'Cash on hand'; }
    public function type(): string { return 'stat_multi'; }

    public function value(Tenant $t, $user): array
    {
        $filters = [];
        if (! $this->isPrivileged($user) && $user) {
            $filters['holder'] = (string) ($user->name ?? '');
            $filters['holder_user_id'] = (int) ($user->id ?? 0);
        }
        return ['balances' => CashService::forTenant($t)->balances($filters)];
    }
}
