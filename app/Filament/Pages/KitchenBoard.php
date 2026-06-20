<?php
namespace App\Filament\Pages;

use App\Filament\Concerns\VerticalGate;
use App\Models\Order;
use App\Models\Tenant;
use App\Support\TenantContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Kitchen Board (KOT) — the live operator surface for restaurant staff.
 *
 * Columns follow Order::KITCHEN_FLOW (New → Accepted → Preparing → Ready → Dispatched).
 * One tap advances a ticket to the next stage; OrderObserver stamps timing and fires the
 * customer's WhatsApp notification for that stage. Finished/cancelled orders drop off.
 *
 * Auto-refreshes via wire:poll and pings audibly when a new ticket arrives.
 */
class KitchenBoard extends Page
{
    use VerticalGate;

    /** Live KOT for restaurant kitchens only. Hidden for grocery & snacks tenants. */
    protected static string $verticalFeature = 'kitchen_board';

    protected static ?string $navigationIcon = 'heroicon-o-fire';
    protected static ?int $navigationSort = 2;
    protected static ?string $title = 'Kitchen';
    protected static ?string $navigationLabel = 'Kitchen';
    protected static string $view = 'filament.pages.kitchen-board';

    /** Statuses shown on the board, in column order. Finished orders leave the board. */
    public const BOARD = ['New', 'Accepted', 'Preparing', 'Ready', 'Dispatched'];

    protected function tenantId(): int
    {
        return (int) (app(TenantContext::class)->id() ?? auth()->user()->tenant_id);
    }

    /** Live tickets grouped by status column. Oldest first within a column (cook FIFO). */
    public function getColumnsProperty(): array
    {
        $orders = Order::query()
            ->whereIn('status', self::BOARD)
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->orderBy('created_at')
            ->limit(300)
            ->get();

        $cols = array_fill_keys(self::BOARD, []);
        foreach ($orders as $o) {
            $cols[$o->status][] = [
                'id'        => $o->id,
                'order_no'  => $o->order_no,
                'phone'     => $o->customer_phone,
                'name'      => $o->customer_name,
                'channel'   => $o->channel,
                'mins'      => $o->created_at ? (int) $o->created_at->diffInMinutes(now()) : 0,
                'notes'     => trim((string) $o->notes),
                'location'  => (string) $o->location,
                'next'      => $o->nextKitchenStatus(),
                'items'     => $this->ticketLines($o),
            ];
        }
        return $cols;
    }

    /**
     * Ticket lines: order_items relation when present (WhatsApp/web), else items_json
     * (POS orders carry no item rows). Modifiers un-folded off the name in both paths.
     */
    private function ticketLines(Order $o): array
    {
        $modNames = fn ($m) => is_array($m)
            ? array_values(array_filter(array_map(fn ($x) => trim((string) ($x['name'] ?? '')), $m)))
            : [];
        $unfold = function (string $name, array $mods): string {
            if ($mods) {
                $suffix = ' + ' . implode(', ', $mods);
                if (str_ends_with($name, $suffix)) {
                    $name = substr($name, 0, -strlen($suffix));
                }
            }
            return $name;
        };

        if ($o->items->isNotEmpty()) {
            return $o->items->map(function ($i) use ($modNames, $unfold) {
                $mods = $modNames($i->modifiers);
                return ['qty' => $i->qty, 'name' => $unfold((string) $i->name, $mods), 'mods' => $mods, 'notes' => trim((string) $i->notes)];
            })->all();
        }

        $json = is_array($o->items_json) ? $o->items_json : [];
        return array_map(function ($it) use ($modNames, $unfold) {
            $mods = $modNames($it['modifiers'] ?? null);
            return [
                'qty'   => (int) ($it['qty'] ?? 1),
                'name'  => $unfold(trim((string) ($it['name'] ?? '')), $mods),
                'mods'  => $mods,
                'notes' => trim((string) ($it['notes'] ?? '')),
            ];
        }, $json);
    }

    /** Badge in the sidebar = number of brand-new tickets waiting to be accepted. */
    public static function getNavigationBadge(): ?string
    {
        $n = Order::where('status', 'New')->count();
        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Order::where('status', 'New')->count() > 0 ? 'danger' : null;
    }

    private function find(int $id): ?Order
    {
        return Order::find($id);   // tenant-scoped by global scope
    }

    /** Advance a ticket to the next kitchen stage. */
    public function advance(int $id): void
    {
        $o = $this->find($id);
        if (! $o) return;
        $next = $o->nextKitchenStatus();
        if (! $next) { Notification::make()->title('Already at the final stage')->warning()->send(); return; }
        $o->status = $next;
        $o->save();   // OrderObserver stamps timing + notifies the customer
        Notification::make()->title("{$o->order_no} → {$next}")->success()->send();
    }

    public function reject(int $id): void
    {
        $o = $this->find($id);
        if (! $o) return;
        $o->status = 'Rejected';
        $o->save();
        Notification::make()->title("{$o->order_no} rejected")->danger()->send();
    }

    public function cancel(int $id): void
    {
        $o = $this->find($id);
        if (! $o) return;
        $o->status = 'Cancelled';
        $o->save();
        Notification::make()->title("{$o->order_no} cancelled")->warning()->send();
    }
}
