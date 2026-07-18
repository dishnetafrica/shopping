<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * shop:configure — one-line preconfiguration of the bot's shop identity.
 * Sets the settings BOTH bot brains read: shop_address (the "where are you"
 * answer) and website (shared for catalogue/price/product questions; AiBrain's
 * BUSINESS DETAILS already honors it). Blank option = leave that setting alone.
 */
class ShopConfigureCommand extends Command
{
    protected $signature   = 'shop:configure {tenant} {--address=} {--website=} {--show : Just display current values}';
    protected $description = 'Preconfigure shop address + website for the WhatsApp bot (both answer paths)';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) { $this->error('tenant not found'); return self::FAILURE; }

        if (! $this->option('show')) {
            $addr = trim((string) $this->option('address'));
            $web  = trim((string) $this->option('website'));
            if ($addr !== '') { $t->putSetting('shop_address', str_replace('\n', "\n", $addr)); $this->info('shop_address set.'); }
            if ($web !== '') {
                if (! str_starts_with($web, 'http')) $web = 'https://'.$web;
                $t->putSetting('website', $web);
                $this->info('website set.');
            }
            if ($addr === '' && $web === '') $this->warn('nothing passed — use --address= and/or --website= (or --show)');
        }

        $this->line('');
        $this->line('Current configuration for '.$t->name.':');
        $this->line('  shop_address: '.((string) $t->setting('shop_address', '') ?: '(not set)'));
        $this->line('  website:      '.((string) $t->setting('website', '') ?: '(not set)'));
        $this->line('');
        $this->comment('Effective immediately — WhatsApp the bot "where is your shop?" and "send me your catalogue".');

        return self::SUCCESS;
    }
}
