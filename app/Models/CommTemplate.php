<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant, per-locale message template. Body + subject use {{var}} placeholders
 * rendered by App\Services\Comms\TemplateRenderer. `variables` documents the
 * placeholders so the panel editor can list them for the operator.
 */
class CommTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'channel', 'key', 'locale', 'name', 'subject',
        'html_body', 'text_body', 'variables', 'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    /** Resolve a template for tenant+key with locale fallback (xx → en → any). */
    public static function resolve(int $tenantId, string $key, string $locale = 'en', string $channel = 'email'): ?self
    {
        $base = static::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)->where('channel', $channel)
            ->where('key', $key)->where('is_active', true);

        return (clone $base)->where('locale', $locale)->first()
            ?: (clone $base)->where('locale', 'en')->first()
            ?: $base->orderBy('id')->first();
    }
}
