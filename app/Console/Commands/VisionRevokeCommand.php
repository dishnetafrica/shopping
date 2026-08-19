<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VisionBox;
use Illuminate\Console\Command;

/**
 * Revoke a device. Online, the box stops at its next heartbeat; offline, it stops when its short license
 * can no longer renew (expiry + grace). Ingest already rejects non-claimed boxes, so a revoked box's
 * events are refused too.
 *
 *   php artisan vision:revoke <box_uid>
 */
final class VisionRevokeCommand extends Command
{
    protected $signature = 'vision:revoke {box_uid : the device box_uid (machine-id)}';
    protected $description = 'Revoke a Vision box remotely.';

    public function handle(): int
    {
        $box = VisionBox::where('box_uid', $this->argument('box_uid'))->first();
        if (! $box) {
            $this->error('no such box');
            return self::FAILURE;
        }
        $box->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $this->info("Box {$box->box_uid} revoked. It stops on next heartbeat (online) or at license expiry (offline).");
        return self::SUCCESS;
    }
}
