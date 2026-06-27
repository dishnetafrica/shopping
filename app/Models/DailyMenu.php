<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DailyMenu extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'menu_date', 'payload_json', 'source'];
    protected $casts = ['payload_json' => 'array', 'menu_date' => 'date'];
}
