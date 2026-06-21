<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An individual employee's habits — style, greeting, emoji use, upsell, and the items only they
 * mention. Kept separate so the AI represents the company, not this person.
 */
class EmployeeMemory extends Model
{
    use BelongsToTenant;

    protected $table = 'employee_memories';

    protected $fillable = ['tenant_id', 'employee', 'category', 'fact', 'detail', 'confidence'];

    protected $casts = [
        'detail'     => 'array',
        'confidence' => 'integer',
    ];
}
