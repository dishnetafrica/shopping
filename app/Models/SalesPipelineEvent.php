<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One stage move of a lead (written only by App\Services\Sales\Pipeline\PipelineService). */
class SalesPipelineEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'lead_id', 'from_stage', 'to_stage', 'user_id', 'reason', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
