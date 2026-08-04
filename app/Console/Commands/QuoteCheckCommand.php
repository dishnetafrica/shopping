<?php

namespace App\Console\Commands;

use App\Models\Quotation;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Why is this quotation link a 404?
 *
 * A dead review link has several possible causes that all look identical from a browser —
 * a 404 page. This answers it in one run: does the token exist, who owns it, is that tenant
 * suspended, does the host the link points at resolve to that same tenant, and is the link
 * the system would generate today the same as the one that was sent.
 *
 *   php artisan quote:check jbe3kt5rebgtssrguh4frzpbeqxz2zetud6wqqoh
 *   php artisan quote:check --audit          (no token: how many quotations have no token)
 */
class QuoteCheckCommand extends Command
{
    protected $signature = 'quote:check
        {token? : the 40-character token from the /q/ link}
        {--audit : also report quotations that have no portal token at all}';

    protected $description = 'Diagnose a quotation review link that returns 404';

    public function handle(): int
    {
        if (! Schema::hasColumn('quotations', 'public_token')) {
            $this->error('The quotations table has no public_token column — run php artisan migrate.');

            return self::FAILURE;
        }

        $token = trim((string) $this->argument('token'));

        if ($token !== '') {
            $this->line('Token: ' . $token);
            $this->line('Shape: ' . strlen($token) . ' chars · '
                . (preg_match('/^[a-z0-9]{40}$/', $token) ? '<fg=green>matches the route pattern</>'
                    : '<fg=red>does NOT match [a-z0-9]{40} — the route will not even be reached</>'));

            $q = Quotation::withoutGlobalScopes()->where('public_token', $token)->first();
            if (! $q) {
                $this->newLine();
                $this->error('NOT FOUND — no quotation in any tenant has this token.');
                $this->line('That is why the page 404s. Likely causes:');
                $this->line('  · the quotation was created by a path that bypassed the model (a raw insert');
                $this->line('    skips the creating hook that generates the token);');
                $this->line('  · the row was deleted;');
                $this->line('  · the token was truncated in the message.');
                $this->line('Run with --audit to see how many quotations are missing a token.');
            } else {
                $t = Tenant::withoutGlobalScopes()->find($q->tenant_id);
                $this->newLine();
                $this->info('FOUND');
                $this->table(['field', 'value'], [
                    ['quotation id', (string) $q->id],
                    ['quote_no', (string) $q->quote_no],
                    ['status', (string) $q->status],
                    ['revision', (string) ($q->current_rev ?? 1)],
                    ['tenant_id', (string) $q->tenant_id],
                    ['tenant', $t ? (string) $t->name : '<MISSING TENANT — this would 404>'],
                    ['tenant status', $t ? (string) ($t->status ?? 'active') : '—'],
                    ['tenant custom_domain', $t ? ((string) ($t->custom_domain ?: '(none)')) : '—'],
                    ['custom_domain_live', $t ? (($t->setting('custom_domain_live', false)) ? 'ON' : 'off') : '—'],
                    ['link it would send now', method_exists($q, 'canonicalPortalUrl') ? $q->canonicalPortalUrl() : '—'],
                ]);

                if ($t && ($t->status ?? 'active') === 'suspended') {
                    $this->error('The owning tenant is SUSPENDED — the portal 404s on purpose.');
                }

                // The failure this command exists to catch: the link points at a host that
                // resolves to a DIFFERENT tenant than the one that owns the quotation.
                $dom = strtolower(trim((string) ($t->custom_domain ?? '')));
                if ($dom !== '') {
                    $byHost = Tenant::byCustomDomain($dom);
                    $this->newLine();
                    $this->line('Host check — ' . $dom . ' resolves to: '
                        . ($byHost ? ('tenant ' . $byHost->id . ' (' . $byHost->name . ')') : '<fg=yellow>no tenant</>'));
                    if ($byHost && (int) $byHost->id !== (int) $q->tenant_id) {
                        $this->error('MISMATCH: that domain belongs to a DIFFERENT tenant than the quotation.');
                        $this->line('Before v4.31.2 this alone made the link 404. v4.31.2 resolves by token first,');
                        $this->line('so it now opens regardless — but the domain setting is still worth correcting.');
                    }
                }
            }
        }

        if ($this->option('audit') || $token === '') {
            $this->newLine();
            $missing = Quotation::withoutGlobalScopes()
                ->where(fn ($w) => $w->whereNull('public_token')->orWhere('public_token', ''))->count();
            $total = Quotation::withoutGlobalScopes()->count();
            $this->line("Quotations without a portal token: {$missing} of {$total}");
            if ($missing > 0) {
                $this->warn('Those cannot have a working review link. They were created by a path that');
                $this->warn('bypassed the model (a raw insert skips the token-generating hook).');
            }

            // A tenant claiming the platform host as its custom domain breaks host resolution
            // for everyone else — worth surfacing because it is invisible otherwise.
            $platform = preg_replace('#^https?://#', '', rtrim((string) config('app.url'), '/'));
            if ($platform !== '') {
                $claim = Tenant::byCustomDomain($platform);
                if ($claim) {
                    $this->newLine();
                    $this->error('Tenant ' . $claim->id . ' (' . $claim->name . ') has the PLATFORM host "'
                        . $platform . '" set as its custom_domain.');
                    $this->line('That makes every host-resolved page on the platform domain belong to that tenant.');
                }
            }
        }

        return self::SUCCESS;
    }
}
