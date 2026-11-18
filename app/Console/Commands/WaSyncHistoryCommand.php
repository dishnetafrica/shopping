<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Tenant;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\HistorySync;
use Illuminate\Console\Command;

/**
 * Copy a business's whole WhatsApp history from Evolution into ShopBot's inbox (HistorySync — the same
 * writer as the inbox "Sync" button), from the command line. Safe to re-run: it adds only what is missing.
 *
 *   php artisan wa:sync-history 31
 */
class WaSyncHistoryCommand extends Command
{
    protected $signature = 'wa:sync-history {tenant} {--pages=40 : most pages of 500 messages to read}';

    protected $description = 'Copy the WhatsApp line\'s stored history into the ShopBot inbox (original times; never read by the AI as its own replies)';

    public function handle(EvolutionAdmin $evo, HistorySync $sync): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t || ! $evo->configured() || (string) $t->whatsapp_instance === '') {
            $this->error('No such business, or it has no Evolution WhatsApp line.');

            return self::FAILURE;
        }
        $before = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->count();
        $res = $sync->run($t, max(1, (int) $this->option('pages')));
        $this->info("{$t->name}: read {$res['scanned']} WhatsApp messages · added {$res['imported']} · already there {$res['skipped_known']} · contacts {$res['contacts']}");
        $this->line('  messages in ShopBot: ' . $before . ' → ' . Message::withoutGlobalScopes()->where('tenant_id', $t->id)->count());

        return self::SUCCESS;
    }
}
