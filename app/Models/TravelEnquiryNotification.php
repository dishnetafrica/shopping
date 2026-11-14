<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One staff alert for a travel enquiry: who it was for, whether it was delivered, and why not.
 * Written only by App\Services\Travel\Handoff\StaffHandoff.
 */
class TravelEnquiryNotification extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'enquiry_id', 'recipient', 'kind', 'state_hash', 'status', 'attempts', 'last_error', 'sent_at'];

    protected $casts = ['attempts' => 'integer', 'sent_at' => 'datetime'];
}
