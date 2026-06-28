<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OrderFlowMetric extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $fillable = ['tenant_id', 'conversation_id', 'intent', 'draft_parsed', 'products_found',
        'has_address', 'has_time', 'has_payment', 'clarification', 'outcome', 'sample', 'processing_ms', 'created_at'];
    protected $casts = ['draft_parsed' => 'boolean', 'has_address' => 'boolean', 'has_time' => 'boolean',
        'has_payment' => 'boolean', 'clarification' => 'boolean', 'products_found' => 'integer',
        'processing_ms' => 'integer', 'created_at' => 'datetime'];
}
