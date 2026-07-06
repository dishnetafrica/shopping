<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

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
        return ['dashboard', 'products', 'orders', 'customers', 'quotations', 'whatsapp', 'finance', 'marketing', 'settings', 'staff', 'cash', 'purchases', 'ordercosts', 'approvals'];
    }

    public function effectiveMenus(): array
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
            default:              return $all; // legacy/unknown role = unrestricted (owner opts in to limit)
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
