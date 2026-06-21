<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SearchEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'type', 'query', 'results', 'product_id', 'created_at',
    ];

    protected $casts = [
        'results'    => 'integer',
        'product_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
