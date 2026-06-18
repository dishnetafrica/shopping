<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WwMaterial extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_materials';
    protected $fillable = ['tenant_id','material_code','material_name','type','uom','active'];
    protected $casts = ['active'=>'boolean'];
}
