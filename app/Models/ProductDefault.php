<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store owner's default SKU for a generic term (e.g. term "rice" -> Rice 5kg).
 * Used by the bot to resolve a generic request without asking, per the
 * Default Product Strategy. One row per (tenant, term).
 */
class ProductDefault extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'term', 'product_id', 'active', 'source', 'created_by'];

    protected $casts = ['active' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Canonicalise a term the same way the bot matcher does, so lookups line up. */
    public static function canonicalTerm(string $raw): string
    {
        return implode(' ', (new \App\Services\Bot\CatalogueMatcher())->tokens($raw));
    }
}
