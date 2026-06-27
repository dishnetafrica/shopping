<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OwnerProfile extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'owner_ref', 'language', 'timezone', 'style', 'aliases_json', 'learned_json'];
    protected $casts = ['aliases_json' => 'array', 'learned_json' => 'array'];
}
