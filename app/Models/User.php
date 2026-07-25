<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = ['tenant_id','name','phone','email','password','role','is_super_admin','allowed_categories','allowed_menus'];
    protected $hidden   = ['password','remember_token'];
    protected $casts    = ['is_super_admin' => 'boolean','password' => 'hashed','allowed_categories' => 'array','allowed_menus' => 'array'];

    public function tenant() { return $this->belongsTo(Tenant::class); }

    // ---- Panel permissions -------------------------------------------------
    // Roles: 'owner'/'admin' (full), 'manager' (all except staff), 'product_staff'
    // (Products only, limited to allowed_categories). Any other/legacy value = unrestricted.

    public function isOwnerLike(): bool
    {
        return (bool) $this->is_super_admin || in_array((string) $this->role, ['owner', 'admin'], true);
    }

    /** Menu keys this user may open. Owner/legacy = everything. */
    /** All menu keys that exist (the master list for the RBAC editor). */
    public static function allMenuKeys(): array
    {
        return ['dashboard', 'products', 'orders', 'customers', 'quotations', 'whatsapp', 'finance', 'marketing', 'settings', 'staff', 'cash', 'purchases', 'ordercosts', 'approvals', 'tasks'];
    }

    public function effectiveMenus(): array
    {
        $menus = $this->effectiveMenusRaw();
        // Everyone can see their own task list, regardless of role.
        if (! in_array('tasks', $menus, true)) $menus[] = 'tasks';
        return array_values($menus);
    }

    private function effectiveMenusRaw(): array
    {
        $all = self::allMenuKeys();
        if ($this->isOwnerLike()) return $all;
        // Per-user override wins over the role default (owner sets it in the Staff editor).
        if (is_array($this->allowed_menus)) {
            return array_values(array_intersect($all, $this->allowed_menus));
        }
        switch ((string) $this->role) {
            case 'manager':       return array_values(array_diff($all, ['staff']));
            case 'product_staff': return ['dashboard', 'products'];
            // Jaeysh (Uganda buyer): sees orders+margin, books purchases, sees cash. No approvals (can't approve own spend).
            case 'purchaser':     return ['dashboard', 'orders', 'ordercosts', 'purchases', 'cash'];
            // Asish (South Sudan field): sees orders+margin, books delivery/local costs, sees cash. No approvals.
            case 'field':         return ['dashboard', 'orders', 'ordercosts', 'purchases', 'cash'];
            // Salesperson: builds quotations + orders + customers, sees orders+margin. No purchasing, no cash, no approvals.
            case 'sales':         return ['dashboard', 'orders', 'quotations', 'customers', 'ordercosts'];

            // --- Roadmap E2.1: roles from Product Architecture v2 §5 -----------------------
            // Mapped onto today's coarse menu keys. NOTE: the coarse `finance` key still bundles
            // Invoices+Payments+Expenses+Reports and `cash` = Cashbook; the finer per-screen
            // separation (e.g. Cashier sees Payments but not Reports) lands in E2.3/E2.4 as
            // server-side action guards + finer keys. These sets are the intended visibility now.

            // Warehouse: Orders (read) + Stock (Inventory/Consignments/Deliveries). No money, purchasing, messaging, settings.
            case 'warehouse':     return ['dashboard', 'orders', 'ordercosts', 'products'];
            // Procurement: Buy List/Purchases/Suppliers + stock (read) + Cashbook + Payments/Expenses/Reports. No sales customers, messaging, settings.
            case 'procurement':   return ['dashboard', 'orders', 'ordercosts', 'purchases', 'products', 'cash', 'finance'];
            // Accountant: Invoices/Payments/Expenses/Reports + Cashbook + Cost Approvals; reads Orders/Customers. No purchasing edit, stock, messaging, settings.
            case 'accountant':    return ['dashboard', 'orders', 'customers', 'finance', 'cash', 'approvals'];
            // Cashier: take Payments + Expenses + own Cashbook; reads Orders/Customers. No approvals, purchasing, stock, settings, messaging.
            case 'cashier':       return ['dashboard', 'orders', 'customers', 'finance', 'cash'];
            // Marketing: Chats + Leads + Campaigns; reads Customers. No money, stock, purchasing, settings.
            case 'marketing':     return ['dashboard', 'customers', 'whatsapp', 'marketing'];
            // Transport (bus-park operator): confined to the Transport Register only
            // (see ConfineTransportRole middleware). No ERP menus.
            case 'transport':     return ['dashboard'];

            // E2.2 — deny-by-default (Constitution R9). When the tenant opts into strict
            // permissions (setting perm_strict = true), an unknown/legacy role — e.g. the common
            // "staff" default — sees only the minimum until an owner assigns a proper role or
            // grants specific menus via allowed_menus. Flag OFF (default) preserves today's
            // unrestricted behaviour, so this is enabled tenant-by-tenant after review.
            default:              return $this->permStrict() ? ['dashboard'] : $all;
        }
    }

    /** Whether this user's tenant has opted into strict (deny-by-default) permissions. */
    private function permStrict(): bool
    {
        try {
            return (bool) optional($this->tenant)->setting('perm_strict', false);
        } catch (\Throwable $e) {
            return false; // never let permission resolution throw
        }
    }

    public function canMenu(string $key): bool
    {
        return in_array($key, $this->effectiveMenus(), true);
    }

    /** null = all categories; array (possibly empty) = limited to exactly these. */
    public function categoryScope(): ?array
    {
        if ((string) $this->role === 'product_staff') {
            return is_array($this->allowed_categories) ? array_values($this->allowed_categories) : [];
        }
        return null;
    }

    /** Super admins use the /admin panel; tenant staff use /app. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            ? (bool) $this->is_super_admin
            : ($this->tenant_id !== null);
    }
}
