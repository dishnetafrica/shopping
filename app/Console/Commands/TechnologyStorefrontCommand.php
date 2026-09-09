<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TechTheme;
use App\Support\Vertical;
use Illuminate\Console\Command;

/**
 * Turn the technology storefront on (or off) for ONE tenant, optionally loading a
 * settings JSON file produced for that tenant.
 *
 *   php artisan storefront:technology --tenant=17 --dry
 *   php artisan storefront:technology --tenant=17 --settings=storage/secure-solutions-settings.json
 *   php artisan storefront:technology --tenant=17 --off
 *
 * Writes ONLY the keys present in the file, through Tenant::putSetting(), which locks
 * the row and re-reads before merging — so a concurrent watchdog cannot clobber it, and
 * settings this command does not name are left exactly as they are.
 *
 * No other tenant is read or written. There is no "all tenants" mode by design.
 */
class TechnologyStorefrontCommand extends Command
{
    protected $signature = 'storefront:technology
        {--tenant= : tenant id (required)}
        {--settings= : path to a JSON file of settings to merge}
        {--off : disable the technology storefront for this tenant}
        {--dry : show what would change, write nothing}';

    protected $description = 'Enable/configure the reusable technology storefront for one tenant';

    public function handle(): int
    {
        $id = (int) $this->option('tenant');
        if ($id <= 0) {
            $this->error('--tenant=<id> is required.');
            return self::FAILURE;
        }

        $t = Tenant::find($id);
        if (! $t) {
            $this->error("Tenant {$id} not found.");
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $this->line("Tenant <info>{$t->id}</info> — {$t->name}" . ($dry ? '  <comment>[DRY RUN]</comment>' : ''));

        // ---- disable path -------------------------------------------------------
        if ($this->option('off')) {
            $this->warn('Disabling the technology storefront (vertical is left untouched).');
            if (! $dry) {
                $t->putSetting('technology_site', false);
            }
            $this->info($dry ? 'Would set technology_site = false' : 'technology_site = false');
            return self::SUCCESS;
        }

        // ---- settings file ------------------------------------------------------
        $incoming = [];
        if ($path = $this->option('settings')) {
            if (! is_file($path)) {
                $this->error("Settings file not found: {$path}");
                return self::FAILURE;
            }
            $raw = json_decode((string) file_get_contents($path), true);
            if (! is_array($raw)) {
                $this->error('Settings file is not valid JSON object.');
                return self::FAILURE;
            }
            $incoming = $raw;
        }

        // Always ensure the two selection keys, whatever the file says.
        $incoming['vertical'] = Vertical::TECHNOLOGY;
        $incoming['technology_site'] = true;

        $this->newLine();
        $this->line('Keys to write:');
        foreach ($incoming as $k => $v) {
            $shown = is_scalar($v) ? (string) $v : '<' . gettype($v) . ', ' . count((array) $v) . ' entries>';
            $this->line(sprintf('  %-22s %s', $k, mb_substr($shown, 0, 70)));
        }

        if (! $dry) {
            foreach ($incoming as $k => $v) {
                $t->putSetting($k, $v);
            }
            $t->refresh();
        }

        // ---- report what the storefront will resolve to --------------------------
        $settings = $dry
            ? array_replace(is_array($t->settings) ? $t->settings : [], $incoming)
            : (is_array($t->settings) ? $t->settings : []);

        $cfg = TechTheme::build($settings, [
            'name'     => (string) $t->name,
            'currency' => (string) ($settings['currency'] ?? 'UGX'),
            'waNumber' => preg_replace('/[^0-9]/', '', (string) ($t->whatsapp_number ?? '')),
        ]);
        $seo = TechTheme::seo($settings, (string) $t->name);

        $this->newLine();
        $this->line('Resolved storefront:');
        $this->line('  title        ' . $seo['title']);
        $this->line('  accent       ' . $cfg['colors']['accent']);
        $this->line('  sections     ' . implode(', ', $cfg['sections']));
        $this->line('  nav items    ' . count($cfg['nav']));
        $this->line('  solutions    ' . count($cfg['solutions']));
        $this->line('  services     ' . count($cfg['services']));
        $this->line('  faq          ' . count($cfg['faq']));
        $this->line('  pages        ' . implode(', ', array_keys($cfg['pages'])));
        $this->line('  contacts     ' . count($cfg['contact']['phones']) . ' phone(s), '
                                      . count($cfg['contact']['emails']) . ' email(s)');

        $this->newLine();
        $this->info($dry
            ? 'Dry run only — nothing written. Re-run without --dry to apply.'
            : 'Applied. Visit the tenant storefront to verify.');

        if (! $dry) {
            $this->line('  platform URL : ' . url('/' . $t->slug));
            if ($t->custom_domain) {
                $this->line('  custom domain: https://' . $t->custom_domain . '/');
            }
        }

        return self::SUCCESS;
    }
}
