<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** v4.19 TenderBot — an analysed tender opportunity (see CLOUDBSS-APPLY-v4.19.md). */
class Tender extends Model
{
    use BelongsToTenant;   // P0: structural tenant scoping (panel access already filters tenant_id;
                           // the cross-tenant TendersRemindCommand sweep opts out via withoutGlobalScopes).

    protected $fillable = ['tenant_id', 'source', 'source_ref', 'organization', 'tender_no', 'title',
        'division', 'deadline', 'opportunity_score', 'win_probability', 'recommendation', 'status',
        'summary', 'report', 'files'];

    protected $casts = [
        'report' => 'array',
        'files' => 'array',
        'deadline' => 'date',
    ];
}
