<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A stored quotation with its line items and lifecycle status. */
class Quotation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'quote_no', 'public_token', 'customer_name', 'customer_phone',
        'delivery_address', 'remarks', 'currency',
        'total', 'freight', 'status', 'source', 'valid_until', 'pdf_path', 'order_id', 'sales_exec_id',
        'current_rev', 'send_count', 'last_sent_at', 'viewed_at', 'accepted_at', 'meta',
    ];

    protected $casts = [
        'total'        => 'decimal:2',
        'freight'      => 'decimal:2',
        'valid_until'  => 'date',
        'last_sent_at' => 'datetime',
        'viewed_at'    => 'datetime',
        'accepted_at'  => 'datetime',
        'meta'         => 'array',
    ];

    /**
     * Every quotation gets an unguessable public portal token on creation — from ANY source
     * (bot, panel, v3, storefront), so the customer portal works for all of them. Purely
     * additive: if the column is absent (pre-migration) or already set, this is a no-op.
     */
    protected static function booted(): void
    {
        static::creating(function (self $q) {
            if (empty($q->public_token)) {
                $q->public_token = Str::lower(Str::random(40));
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(QuotationRevision::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function salesExec(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_exec_id');
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted' && $this->order_id;
    }

    /** Open lifecycle statuses — the customer may still view/collaborate on these. */
    public function isOpen(): bool
    {
        return ! in_array($this->status, ['converted', 'declined', 'expired', 'archived'], true);
    }

    /** Editable = open AND not yet approved/converted (an accepted quote is agreed, so it's locked). */
    public function isEditable(): bool
    {
        return $this->isOpen() && $this->status !== 'accepted';
    }

    /** Build the customer portal URL for a given storefront base ('' on a custom domain, '/{slug}' on the platform). */
    public function portalUrl(string $base = ''): string
    {
        return url(rtrim($base, '/') . '/q/' . $this->public_token);
    }

    /** Canonical portal URL resolved from the tenant (custom domain if set, else platform slug) — host-independent. */
    public function canonicalPortalUrl(): string
    {
        $tenant = $this->relationLoaded('tenant') ? $this->tenant : Tenant::find($this->tenant_id);
        $dom = strtolower(trim((string) ($tenant->custom_domain ?? '')));
        if ($dom !== '') {
            return 'https://' . rtrim($dom, '/') . '/q/' . $this->public_token;
        }
        $slug = $tenant && $tenant->slug ? '/' . $tenant->slug : '';

        return url($slug . '/q/' . $this->public_token);
    }
}
