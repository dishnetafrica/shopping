<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'name', 'image_url', 'sort', 'active'];
    protected $casts = ['active' => 'boolean'];
}
