<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Services\Sales\SalesOwnership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Which salesperson a WhatsApp number belongs to (DishNet, 25 Nov 2026: "Aida has a separate number and we want
 * her to work the same as Justus"). A customer who first writes to — or calls — that number becomes that
 * salesperson's customer (SalesOwnership::ownerForLine): alerts go to her, quotations and follow-ups carry her
 * name, and follow-ups go out from her number. Customers who already have a salesperson keep them.
 *
 *   php artisan wa:line-owner 31                                   list the numbers and who each belongs to
 *   php artisan wa:line-owner 31 shopbot_t31_2 --user=72          the number belongs to user 72 (a salesperson)
 *   php artisan wa:line-owner 31 shopbot_t31_2 --clear            the number belongs to the business again
 */
class WaLineOwnerCommand extends Command
{
    protected $signature = 'wa:line-owner {tenant} {instance? : the number\'s instance, from the list}
        {--user= : id of the salesperson the number belongs to}
        {--clear : the number belongs to the business again}';

    protected $description = 'Internet provider: say which salesperson a WhatsApp number belongs to';

    public function handle(SalesOwnership $owners): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('No such business.');

            return self::FAILURE;
        }
        if (! Schema::hasColumn('whatsapp_channels', 'owner_user_id')) {
            $this->error('The database is not migrated yet (whatsapp_channels.owner_user_id): run php artisan migrate --force.');

            return self::FAILURE;
        }
        $t->syncPrimaryChannel();
        $instance = trim((string) $this->argument('instance'));

        if ($instance !== '') {
            $c = WhatsappChannel::where('tenant_id', $t->id)->where('instance', $instance)->first();
            if (! $c) {
                $this->error("{$t->name} has no number with instance \"{$instance}\". Run without an instance to list them.");

                return self::FAILURE;
            }
            if ($this->option('clear')) {
                $c->owner_user_id = null;
                $c->save();
                $this->info("{$instance} belongs to the business again.");
            } elseif ($this->option('user') !== null) {
                $u = collect($owners->salespeople($t))->firstWhere('id', (int) $this->option('user'));
                if (! $u) {
                    $this->error('--user must be the id of a salesperson of this business (role Sales, with a WhatsApp number).');
                    $this->listSalespeople($owners, $t);

                    return self::FAILURE;
                }
                $c->owner_user_id = $u->id;
                $c->save();
                $this->info("{$instance} now belongs to {$u->name}: new customers who write to or call it become {$u->name}'s.");
            } else {
                $this->error('Say --user=<salesperson id> or --clear.');

                return self::FAILURE;
            }
        }

        $this->line("{$t->name} — WhatsApp numbers:");
        foreach (WhatsappChannel::where('tenant_id', $t->id)->orderByDesc('is_primary')->orderBy('id')->get() as $c) {
            $who = $c->owner_user_id ? (User::where('tenant_id', $t->id)->find($c->owner_user_id)?->name ?? "user {$c->owner_user_id} (not found)") : 'the business';
            $this->line(sprintf('  %-18s %-16s %-10s %s', $c->instance, $c->number ?: '(not linked)', $c->is_primary ? 'main' : ($c->active ? 'extra' : 'off'), 'belongs to: ' . $who));
        }
        if ($instance === '') {
            $this->listSalespeople($owners, $t);
        }

        return self::SUCCESS;
    }

    private function listSalespeople(SalesOwnership $owners, Tenant $t): void
    {
        $this->line('Salespeople:');
        foreach ($owners->salespeople($t) as $u) {
            $this->line("  {$u->id}  {$u->name}  +{$u->phone}");
        }
    }
}
