<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WwMachine extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_machines';
    protected $fillable = ['tenant_id','process','machine','max_speed','speed_type','cavity_repeat_pcs','active','remarks'];
    protected $casts = ['max_speed'=>'decimal:3','cavity_repeat_pcs'=>'integer','active'=>'boolean'];

    public function plannings() { return $this->hasMany(WwPlanning::class, 'machine_id'); }
}
