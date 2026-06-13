<?php
namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Pricing;
use App\Support\TenantContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Pos extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?int $navigationSort = 3;
    protected static ?string $title = 'POS';
    protected static string $view = 'filament.pages.pos';

    public string $search = '';
    public array $results = [];
    public array $cart = [];          // product_id => ['name','price','qty']
    public string $customerPhone = '';
    public string $payment = 'cash';

    protected function tenant(): Tenant
    {
        return Tenant::findOrFail(app(TenantContext::class)->id() ?? auth()->user()->tenant_id);
    }

    public function updatedSearch(): void
    {
        $q = trim($this->search);
        if (mb_strlen($q) < 2) { $this->results = []; return; }
        $t = $this->tenant();
        $this->results = Product::query()->where('active', true)
            ->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                  ->orWhere('sku', $q)
                  ->orWhere('barcode', $q)
                  ->orWhere('keywords', 'ilike', "%{$q}%");
            })
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', ["{$q}%"])
            ->limit(25)->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => Pricing::net($t, (float) $p->price)])
            ->toArray();
    }

    public function add(int $id): void
    {
        $p = Product::find($id);
        if (! $p) return;
        $price = Pricing::net($this->tenant(), (float) $p->price);
        if (isset($this->cart[$id])) $this->cart[$id]['qty']++;
        else $this->cart[$id] = ['name' => $p->name, 'price' => $price, 'qty' => 1];
    }

    public function inc(int $id): void { if (isset($this->cart[$id])) $this->cart[$id]['qty']++; }
    public function dec(int $id): void
    {
        if (! isset($this->cart[$id])) return;
        $this->cart[$id]['qty']--;
        if ($this->cart[$id]['qty'] <= 0) unset($this->cart[$id]);
    }
    public function remove(int $id): void { unset($this->cart[$id]); }

    public function total(): float
    {
        $t = 0;
        foreach ($this->cart as $l) $t += $l['price'] * $l['qty'];
        return $t;
    }

    public function complete(): void
    {
        if (! $this->cart) { Notification::make()->title('Cart is empty')->warning()->send(); return; }

        $itemsText = collect($this->cart)->map(fn ($l) => "{$l['qty']}x {$l['name']}")->implode(', ');
        $order = Order::create([
            'customer_phone' => $this->customerPhone ?: null,
            'customer_name'  => 'Walk-in',
            'items_text'     => $itemsText,
            'items_json'     => array_values($this->cart),
            'total'          => $this->total(),
            'payment'        => $this->payment,
            'status'         => 'Delivered',
            'channel'        => 'pos',
        ]);
        foreach ($this->cart as $pid => $l) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $pid,
                'name' => $l['name'], 'price' => $l['price'], 'qty' => $l['qty'],
            ]);
        }

        Notification::make()->title("Sale complete — {$order->order_no}")->success()->send();
        $this->reset(['cart', 'search', 'results', 'customerPhone']);
        $this->payment = 'cash';
    }
}
