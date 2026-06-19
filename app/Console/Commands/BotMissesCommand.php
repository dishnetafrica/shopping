<?php
namespace App\Console\Commands;

use App\Models\BotMiss;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Weekly review tool: the bot's top unmatched terms per shop — promote real ones to aliases. */
class BotMissesCommand extends Command
{
    protected $signature = 'bot:misses {--tenant= : tenant id} {--limit=40} {--all : include resolved}';
    protected $description = 'Show the terms the bot most often failed to match (vocabulary gaps).';

    public function handle(): int
    {
        $q = BotMiss::query()->orderByDesc('count');
        if ($this->option('tenant')) $q->where('tenant_id', (int) $this->option('tenant'));
        if (! $this->option('all'))  $q->where('resolved', false);
        $rows = $q->limit((int) $this->option('limit'))->get();

        if ($rows->isEmpty()) { $this->info('No misses logged yet.'); return self::SUCCESS; }

        $names = Tenant::pluck('name', 'id');
        $this->table(
            ['Tenant', 'Term', 'Count', 'Last seen', 'Sample'],
            $rows->map(fn ($r) => [
                $names[$r->tenant_id] ?? ('#' . $r->tenant_id),
                $r->term,
                $r->count,
                optional($r->last_seen_at)->format('Y-m-d'),
                mb_substr((string) $r->sample, 0, 40),
            ])->all()
        );
        return self::SUCCESS;
    }
}
