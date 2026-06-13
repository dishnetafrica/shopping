<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name','slug','status','plan',
        'whatsapp_driver','whatsapp_instance','whatsapp_number','order_prefix','settings',
    ];

    protected $casts = ['settings' => 'array'];

    public function users(): HasMany    { return $this->hasMany(User::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function orders(): HasMany   { return $this->hasMany(Order::class); }
    public function riders(): HasMany   { return $this->hasMany(Rider::class); }

    public function setting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }
}
