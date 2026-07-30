<?php declare(strict_types=1);

namespace App\Services\Trust;

use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Inventory Trust — small shared helpers for the session flows: WhatsApp to the owner numbers
 * (same recipients as the daily digest) and base64 photo storage (TaskLink pattern: JPG/PNG/WEBP,
 * ≤15 MB, public disk, returns a full URL or null).
 */
final class TrustNotifier
{
    public static function sendOwners(Tenant $t, string $text): int
    {
        $numbers = method_exists($t, 'ownerAlertNumbers') ? $t->ownerAlertNumbers() : [];
        $extra = array_filter(array_map('trim',
            preg_split('/[,\s]+/', (string) $t->setting('trust_digest_numbers', ''))));
        $numbers = array_values(array_unique(array_merge($numbers, $extra)));
        $instance = $t->whatsapp_instance ?? null;
        if (! $instance || ! $numbers) {
            return 0;
        }
        $gw = app(WhatsAppManager::class)->forTenant($t);
        $sent = 0;
        foreach ($numbers as $to) {
            try {
                $gw->sendText($instance, $to, $text);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('trust notify failed', ['to' => $to, 'err' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /** Store a data-URL image publicly; returns full URL or null if absent/invalid. */
    public static function storePhoto(int $tenantId, string $dataUrl): ?string
    {
        if ($dataUrl === '' || ! preg_match('#^data:(image/(jpeg|jpg|png|webp));base64,(.+)$#s', $dataUrl, $m)) {
            return null;
        }
        $bytes = base64_decode($m[3], true);
        if ($bytes === false || strlen($bytes) < 128 || strlen($bytes) > 15 * 1024 * 1024) {
            return null;
        }
        $ext = $m[2] === 'jpeg' ? 'jpg' : $m[2];
        $path = 'trust/' . $tenantId . '/' . date('Ymd') . '-' . Str::lower(Str::random(10)) . '.' . $ext;
        Storage::disk('public')->put($path, $bytes);
        return url(Storage::url($path));
    }
}
