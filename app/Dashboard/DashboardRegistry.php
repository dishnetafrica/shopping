<?php declare(strict_types=1);

namespace App\Dashboard;

use App\Dashboard\Widgets\CashOnHandWidget;
use App\Dashboard\Widgets\LowStockWidget;
use App\Dashboard\Widgets\NetProfitWidget;
use App\Dashboard\Widgets\OpenInvoicesWidget;
use App\Dashboard\Widgets\PaymentsTodayWidget;

/**
 * The dashboard widget registry + role→widgets map (Roadmap E10.1). Each role gets a focused set of
 * widgets; the API resolves the current user's role to its widgets and computes their values from
 * SSOT. Unknown roles fall back to a safe default.
 */
class DashboardRegistry
{
    /** @var array<string,DashboardWidget> */
    private array $widgets = [];

    /** role => ordered widget keys */
    private array $byRole = [
        'owner'       => ['cash_on_hand', 'open_invoices', 'payments_today', 'net_profit', 'low_stock'],
        'manager'     => ['cash_on_hand', 'open_invoices', 'payments_today', 'low_stock'],
        'accountant'  => ['cash_on_hand', 'open_invoices', 'net_profit'],
        'cashier'     => ['cash_on_hand', 'payments_today'],
        'warehouse'   => ['low_stock'],
        'procurement' => ['low_stock', 'open_invoices'],
        'sales'       => ['open_invoices', 'payments_today'],
    ];

    private array $default = ['open_invoices'];

    public function register(DashboardWidget $w): void
    {
        $this->widgets[$w->key()] = $w;
    }

    public function get(string $key): ?DashboardWidget
    {
        return $this->widgets[$key] ?? null;
    }

    /** @return DashboardWidget[] the widgets for a role, in order */
    public function forRole(string $role): array
    {
        $keys = $this->byRole[strtolower($role)] ?? $this->default;
        $out = [];
        foreach ($keys as $k) {
            if (isset($this->widgets[$k])) $out[] = $this->widgets[$k];
        }
        return $out;
    }

    public function roles(): array
    {
        return array_keys($this->byRole);
    }

    /** The built-in widget set + role map. */
    public static function default(): self
    {
        $r = new self();
        $r->register(new CashOnHandWidget());
        $r->register(new OpenInvoicesWidget());
        $r->register(new PaymentsTodayWidget());
        $r->register(new LowStockWidget());
        $r->register(new NetProfitWidget());
        return $r;
    }
}
