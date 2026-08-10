<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v4.81 (M-CHAT-4, ADR-018) — "does chat actually sell?" answered with counts, not feelings.
 *
 * Reads what already exists — bot_events (the server-side stages the channel writes on every
 * message) and product_events (the three browser-only facts: chat_open, chat_add, chat_order) —
 * and prints the funnel. Read-only; explicit tenant scoping (console rule).
 */
class WebchatStatsCommand extends Command
{
    public const VERSION = 'v4.81';

    protected $signature = 'webchat:stats {tenant : Tenant id} {--days=14 : Window in days}';

    protected $description = 'Web-chat funnel: opens → conversations → replies → cards → adds → chat-attributed orders';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }
        $days  = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $this->line(sprintf('webchat:stats · %s · tenant %d (%s) · last %d day(s)', self::VERSION, $t->id, $t->name, $days));
        $this->line('');

        // Server-side stages (one row per handled message) — bot_events.
        $stages = DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('created_at', '>=', $since)->where('stage', 'like', 'web\_%')
            ->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');

        // Distinct web conversations that actually spoke in the window.
        $convos = (int) DB::table('messages')->where('tenant_id', $t->id)
            ->where('instance', 'web')->where('direction', 'in')
            ->where('created_at', '>=', $since)->distinct()->count(DB::raw('customer_phone'));
        $inbound = (int) DB::table('messages')->where('tenant_id', $t->id)
            ->where('instance', 'web')->where('direction', 'in')
            ->where('created_at', '>=', $since)->count();

        // Browser-only facts — product_events.
        $ev = DB::table('product_events')->where('tenant_id', $t->id)
            ->where('created_at', '>=', $since)
            ->whereIn('event', ['chat_open', 'chat_add', 'chat_order'])
            ->selectRaw('event, count(*) as n')->groupBy('event')->pluck('n', 'event');

        $g = fn (string $k) => (int) ($stages[$k] ?? 0);
        $e = fn (string $k) => (int) ($ev[$k] ?? 0);

        $rows = [
            ['Chat opened (browser)',              $e('chat_open')],
            ['Conversations that wrote',           $convos],
            ['Messages received',                  $inbound],
            ['AI replies',                         $g('web_replied')],
            ['…with product cards',                $g('web_cards')],
            ['ADD from chat (browser)',            $e('chat_add')],
            ['Orders after chat ADD (browser)',    $e('chat_order')],
            ['Signed-in links',                    $g('web_linked')],
            ['Asked for a person',                 $g('web_human_requested')],
            ['Session caps hit',                   $g('web_session_cap')],
            ['Daily caps hit',                     $g('web_day_cap')],
            ['Failures (owner was alerted)',       $g('web_failed')],
        ];
        foreach ($rows as [$label, $n]) {
            $this->line(sprintf('  %-34s %6d', $label, $n));
        }

        $this->line('');
        if ($e('chat_open') > 0) {
            $this->info(sprintf('Funnel: %d opens → %d talked → %d added from chat → %d ordered after chat.',
                $e('chat_open'), $convos, $e('chat_add'), $e('chat_order')));
        } else {
            $this->line('No chat opens in the window yet.');
        }

        return self::SUCCESS;
    }
}
