<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OperationalState extends Model
{
    use BelongsToTenant;
    protected $table = 'operational_state';
    protected $fillable = ['tenant_id', 'capability', 'key', 'value_json', 'scope', 'effective_date'];
    protected $casts = ['value_json' => 'array', 'effective_date' => 'date'];
}
