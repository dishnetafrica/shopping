<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','phone','address','lat','lng'];
    protected $casts = ['lat'=>'float','lng'=>'float'];
}
