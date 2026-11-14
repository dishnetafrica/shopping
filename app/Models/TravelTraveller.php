<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Travel\Passport\Travellers;
use Illuminate\Database\Eloquent\Model;

/**
 * A traveller on a trip, read from the passport or ID card the customer sent (machine-readable zone).
 * Written only by App\Services\Travel\Passport\PassportIntake.
 *
 * The document number is encrypted at rest and hidden from arrays and JSON; read it only through
 * App\Capability\Object\TravelEnquiryCapability's "reveal" command, which checks the role and logs
 * who looked.
 */
class TravelTraveller extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'enquiry_id', 'source', 'mrz_format', 'document_type', 'issuing_country', 'surname', 'given_names',
        'nationality', 'date_of_birth', 'sex', 'expiry_date', 'document_number', 'document_last4', 'fingerprint', 'read_at',
    ];

    protected $hidden = ['document_number', 'fingerprint'];

    protected $casts = [
        'date_of_birth'   => 'date',
        'expiry_date'     => 'date',
        'document_number' => 'encrypted',
        'read_at'         => 'datetime',
    ];

    public function fullName(): string
    {
        return Travellers::fullName((string) $this->given_names, (string) $this->surname);
    }

    /** The document number as staff normally see it: only its last characters. */
    public function maskedNumber(): string
    {
        return Travellers::mask((string) $this->document_last4);
    }

    /** The plain fields the pure helpers in Travellers work on. Never includes the document number. */
    public function facts(): array
    {
        return [
            'id'              => (int) $this->id,
            'name'            => $this->fullName(),
            'document_type'   => (string) $this->document_type,
            'issuing_country' => (string) $this->issuing_country,
            'nationality'     => (string) $this->nationality,
            'date_of_birth'   => $this->date_of_birth?->format('Y-m-d'),
            'sex'             => (string) $this->sex,
            'expiry_date'     => $this->expiry_date?->format('Y-m-d'),
            'last4'           => (string) $this->document_last4,
        ];
    }
}
