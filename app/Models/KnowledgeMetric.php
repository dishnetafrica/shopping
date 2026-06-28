<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class KnowledgeMetric extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id', 'event_id', 'capability', 'intent', 'confidence', 'result',
        'language', 'processing_ms', 'confirmed', 'correction', 'sample',
    ];
    protected $casts = ['confidence' => 'decimal:3', 'confirmed' => 'boolean', 'processing_ms' => 'integer'];
}
