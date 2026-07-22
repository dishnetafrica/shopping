<?php

namespace App\BOS;

use App\Models\CustomerProfile;
use Illuminate\Support\Facades\Schema;

/**
 * Thin, SAFE bridge for existing services to record a customer-subject event with ONE call. It resolves
 * the customer subject (CustomerProfile id, by phone), is fully guarded (no-op if the spine table isn't
 * migrated), and never throws — so adding an emit to a money path can never break that path.
 *
 * Existing service → `Emit::customerEvent(...)` → `Bos::emit(...)`. This is the ONLY way legacy code
 * writes to the spine (ADR-012); it keeps event creation in one auditable place.
 */
final class Emit
{
    public static function customerEvent(int $tenantId, string $name, ?string $phone, array $payload, array $meta = []): ?Event
    {
        try {
            if (! Schema::hasTable('bos_events')) return null;

            $subjectId = 0;
            $digits = preg_replace('/[^0-9]/', '', (string) $phone);
            if ($digits !== '') {
                $subjectId = (int) (CustomerProfile::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('phone', $digits)->value('id') ?? 0);
            }

            return Bos::emit($name, $payload, $meta + [
                'tenant_id'    => $tenantId,
                'subject_type' => 'customer',
                'subject_id'   => $subjectId,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('BOS Emit::customerEvent failed for ' . $name . ': ' . $e->getMessage());
            return null;
        }
    }
}
