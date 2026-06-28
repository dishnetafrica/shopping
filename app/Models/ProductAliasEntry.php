<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductAliasEntry extends Model
{
    use BelongsToTenant;
    protected $table = 'product_aliases';
    protected $fillable = ['tenant_id', 'product_id', 'alias', 'type', 'weight', 'is_default'];
    protected $casts = ['weight' => 'integer', 'is_default' => 'boolean'];
}
