<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A Go-Live Report — the formal readiness assessment produced after Business Discovery.
 * Always created as 'pending'. recommended_mode is advisory; approved_mode is only set when the
 * owner explicitly chooses a mode. The AI never sets approved_mode itself.
 */
class GoLiveReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'overall_score', 'classification', 'recommended_mode',
        'category_scores', 'recommendations', 'status', 'approved_mode', 'generated_at',
    ];

    protected $casts = [
        'overall_score'   => 'integer',
        'category_scores' => 'array',
        'recommendations' => 'array',
        'generated_at'    => 'datetime',
    ];
}
