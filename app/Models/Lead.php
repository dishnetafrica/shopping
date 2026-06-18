<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_phone', 'customer_name', 'intent', 'interest',
        'message', 'source', 'status', 'assigned_to', 'claimed_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    public const OPEN_STATUSES = ['new', 'assigned', 'contacted', 'qualified'];
}
