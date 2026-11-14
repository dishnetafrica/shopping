<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Travel\Enquiry\EnquiryBrief;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\Travel\Enquiry\TravelPipeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A travel enquiry (Travel Vertical · M1): the trip a customer is describing, its pipeline stage and
 * who owns it. Tenant-scoped. The trip fields are read and written as one "state" array through
 * EnquirySlots, which is the only place that decides what a customer's words change.
 */
class TravelEnquiry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'conversation_id', 'customer_phone', 'customer_name', 'channel', 'lead_id', 'owner_user_id',
        'stage', 'trip_type', 'segments', 'origin', 'destination', 'depart_date', 'return_date',
        'adults', 'children', 'infants', 'cabin', 'airlines', 'baggage', 'flexible_days', 'direct_only',
        'notes', 'pending', 'is_group', 'lost_reason', 'details_completed_at', 'last_customer_at', 'closed_at',
        'brief', 'summary_shown_at', 'submitted_at', 'handoff_status',
    ];

    protected $casts = [
        'segments'             => 'array',
        'airlines'             => 'array',
        'pending'              => 'array',
        'brief'                => 'array',
        'summary_shown_at'     => 'datetime',
        'submitted_at'         => 'datetime',
        'depart_date'          => 'date:Y-m-d',
        'return_date'          => 'date:Y-m-d',
        'direct_only'          => 'boolean',
        'is_group'             => 'boolean',
        'details_completed_at' => 'datetime',
        'last_customer_at'     => 'datetime',
        'closed_at'            => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(TravelEnquiryEvent::class, 'enquiry_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(TravelEnquiryNotification::class, 'enquiry_id');
    }

    /** The reference staff and customers quote: "TE-1042". */
    public function reference(): string
    {
        return 'TE-' . (int) $this->id;
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isOpen(): bool
    {
        return TravelPipeline::isOpen((string) $this->stage);
    }

    /** The trip as the EnquirySlots state array. */
    public function state(): array
    {
        return array_merge(EnquirySlots::blank(), [
            'trip_type'     => $this->trip_type,
            'segments'      => is_array($this->segments) ? $this->segments : [],
            'return_date'   => $this->return_date ? $this->return_date->format('Y-m-d') : null,
            'adults'        => $this->adults === null ? null : (int) $this->adults,
            'children'      => $this->children === null ? null : (int) $this->children,
            'infants'       => $this->infants === null ? null : (int) $this->infants,
            'cabin'         => $this->cabin,
            'airlines'      => is_array($this->airlines) ? $this->airlines : [],
            'baggage'       => $this->baggage,
            'flexible_days' => $this->flexible_days === null ? null : (int) $this->flexible_days,
            'direct_only'   => $this->direct_only,
            'notes'         => $this->notes,
            'pending'       => is_array($this->pending) ? $this->pending : [],
            'brief'         => EnquiryBrief::normalise($this->brief),
        ]);
    }

    /** Write an EnquirySlots state back onto the row (does not save). */
    public function fillState(array $state): static
    {
        $first = $state['segments'][0] ?? null;
        $last  = $state['segments'] ? $state['segments'][count($state['segments']) - 1] : null;
        $brief = EnquiryBrief::normalise($state['brief'] ?? null);
        // The list columns show the flight when there is one, otherwise the trip itself.
        $flightTo = $state['trip_type'] === 'multi_city' ? ($last['to']['label'] ?? null) : ($first['to']['label'] ?? null);
        $places   = $brief['destinations'] ? mb_substr(implode(' → ', $brief['destinations']), 0, 250) : null;

        return $this->fill([
            'trip_type'     => $state['trip_type'],
            'segments'      => $state['segments'],
            'origin'        => ($first['from']['label'] ?? null) ?: $brief['departure'],
            // For a multi-city trip the list shows where the journey ends up.
            'destination'   => $flightTo ?: $places,
            'depart_date'   => ($first['date'] ?? null) ?: $brief['start_date'],
            'return_date'   => $state['return_date'] ?: $brief['end_date'],
            'brief'         => $brief,
            'adults'        => $state['adults'],
            'children'      => $state['children'],
            'infants'       => $state['infants'],
            'cabin'         => $state['cabin'],
            'airlines'      => $state['airlines'],
            'baggage'       => $state['baggage'],
            'flexible_days' => $state['flexible_days'],
            'direct_only'   => $state['direct_only'],
            'notes'         => $state['notes'],
            'pending'       => $state['pending'],
            'is_group'      => EnquirySlots::isGroup($state),
        ]);
    }
}
