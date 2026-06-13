<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'name', 'sort', 'active'];
    protected $casts = ['active' => 'boolean'];
}
