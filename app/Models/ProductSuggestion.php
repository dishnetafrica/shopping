<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A researched claim about one product field, waiting for a human.
 *
 * Rows are only ever created for a field that was EMPTY at the time — the pipeline
 * never proposes replacing something a person typed — so approving one can never
 * destroy existing work. See App\Services\Research\ProductResearch::plan().
 */
class ProductSuggestion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'field', 'current_value', 'suggested_value',
        'confidence', 'source', 'note', 'status', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'decided_at' => 'datetime',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }

    /** How the field name reads on the review screen. */
    public static function label(string $field): string
    {
        return [
            'brand'          => 'Brand',
            'unit_size'      => 'Size',
            'origin_country' => 'Country of origin',
            'category'       => 'Category',
            'description'    => 'Description',
        ][$field] ?? ucfirst(str_replace('_', ' ', $field));
    }
}
