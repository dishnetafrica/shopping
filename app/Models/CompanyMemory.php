<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A fact agreed across the business's employees — the AI's company-level knowledge.
 */
class CompanyMemory extends Model
{
    use BelongsToTenant;

    protected $table = 'company_memories';

    protected $fillable = ['tenant_id', 'category', 'fact', 'agreement', 'confidence', 'employees', 'contested'];

    protected $casts = [
        'agreement'  => 'integer',
        'confidence' => 'integer',
        'employees'  => 'array',
        'contested'  => 'boolean',
    ];
}
