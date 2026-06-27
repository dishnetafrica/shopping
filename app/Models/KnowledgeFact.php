<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class KnowledgeFact extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id', 'capability', 'fact_type', 'key', 'value_json', 'version', 'is_current',
        'supersedes_id', 'source', 'confidence', 'scope', 'effective_from', 'event_id',
    ];
    protected $casts = [
        'value_json' => 'array', 'is_current' => 'boolean', 'version' => 'integer',
        'confidence' => 'decimal:3', 'effective_from' => 'date',
    ];
}
