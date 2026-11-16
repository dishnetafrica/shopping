<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A reply the AI Sales Coach suggested to a salesperson (written by App\Services\Isp\SalesCoach). */
class SalesCoachSuggestion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'conversation_id', 'user_id', 'customer_phone', 'suggestion', 'why', 'next_step', 'used', 'check_note', 'guard_removed'];

    protected $casts = ['used' => 'array', 'guard_removed' => 'integer'];
}
