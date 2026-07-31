<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A cashier's till session: float in → sales → count → variance (v3.9). */
class PosShift extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'operator', 'opened_at', 'opening_float',
        'closed_at', 'expected_cash', 'counted_cash', 'variance',
        'denominations', 'currency', 'note',
    ];

    protected $casts = [
        'opened_at' => 'datetime', 'closed_at' => 'datetime',
        'opening_float' => 'decimal:2', 'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2', 'variance' => 'decimal:2',
        'denominations' => 'array',
    ];
}
