<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v4.63 — something that came up on a visit.
 *
 * `product_id` is nullable and deliberately not a foreign key: a product the rep was asked for that
 * the shop does not stock is the most valuable row in this table, and a constraint would throw
 * exactly those rows away. They reach the "Asked for" screen as demand from the field.
 */
class VisitItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'visit_id', 'product_id', 'name', 'qty', 'stocked', 'note'];

    protected $casts = ['qty' => 'float', 'stocked' => 'boolean'];

    public function scopeUnstocked($q)
    {
        return $q->where('stocked', false);
    }
}
