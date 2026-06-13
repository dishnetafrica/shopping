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

    protected $fillable = ['tenant_id','name','phone','email','password','role','is_super_admin'];
    protected $hidden   = ['password','remember_token'];
    protected $casts    = ['is_super_admin' => 'boolean','password' => 'hashed'];

    public function tenant() { return $this->belongsTo(Tenant::class); }

    /** Super admins use the /admin panel; tenant staff use /app. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            ? (bool) $this->is_super_admin
            : ($this->tenant_id !== null);
    }
}
