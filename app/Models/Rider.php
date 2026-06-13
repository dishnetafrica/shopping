<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Rider extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','phone','active','photo','city','dob','address','notes','profile'];
    protected $casts = ['active'=>'boolean','profile'=>'array'];
}
