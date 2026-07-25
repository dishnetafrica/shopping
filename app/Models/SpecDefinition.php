<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable product-spec field (the catalogue). Core platform capability; tenant-scoped.
 */
class SpecDefinition extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'key', 'label', 'unit', 'data_type', 'options',
        'group', 'filterable', 'searchable', 'display_order', 'active',
    ];

    protected $casts = [
        'options'       => 'array',
        'filterable'    => 'boolean',
        'searchable'    => 'boolean',
        'active'        => 'boolean',
        'display_order' => 'integer',
    ];

    /** The allowed spec data types. */
    public const TYPES = ['number', 'text', 'enum', 'boolean'];
}
