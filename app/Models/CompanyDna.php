<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A compact snapshot of the latest multi-employee consensus (team styles, languages, common topics,
 * conflicts, confidence) — persisted so the Business Brain UI can show Team Insights and Consensus
 * Conflicts without recomputing discovery on every page load.
 */
class CompanyDna extends Model
{
    use BelongsToTenant;

    protected $table = 'company_dna';

    protected $fillable = ['tenant_id', 'employee_count', 'messages_analyzed', 'snapshot'];

    protected $casts = [
        'employee_count'    => 'integer',
        'messages_analyzed' => 'integer',
        'snapshot'          => 'array',
    ];
}
