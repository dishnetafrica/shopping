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

    protected $fillable = ['tenant_id','name','phone','email','password','role','is_super_admin','allowed_categories'];
    protected $hidden   = ['password','remember_token'];
    protected $casts    = ['is_super_admin' => 'boolean','password' => 'hashed','allowed_categories' => 'array'];

    public function tenant() { return $this->belongsTo(Tenant::class); }

    // ---- Panel permissions -------------------------------------------------
    // Roles: 'owner'/'admin' (full), 'manager' (all except staff), 'product_staff'
    // (Products only, limited to allowed_categories). Any other/legacy value = unrestricted.

    public function isOwnerLike(): bool
    {
        return (bool) $this->is_super_admin || in_array((string) $this->role, ['owner', 'admin'], true);
    }

    /** Menu keys this user may open. Owner/legacy = everything. */
    public function effectiveMenus(): array
    {
        $all = ['dashboard', 'products', 'orders', 'customers', 'quotations', 'whatsapp', 'finance', 'marketing', 'settings', 'staff'];
        if ($this->isOwnerLike()) return $all;
        switch ((string) $this->role) {
            case 'manager':       return array_values(array_diff($all, ['staff']));
            case 'product_staff': return ['dashboard', 'products'];
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
