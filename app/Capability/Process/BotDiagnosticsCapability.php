<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Tenant;
use App\Models\WhatsappChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bot diagnostics IN V3 (2026-07-18) — the classic /panel/diagnostics page, migrated.
 * "Every customer message and what the bot did with it — use this to see where a
 * message stopped." Health chips + numbers + failed jobs + the live bot_events trail.
 */
class BotDiagnosticsCapability implements ProcessCapability
{
    public function key(): string { return 'diagnostics'; }

    public function permission(): string { return ''; }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $err = (int) DB::table('bot_events')->where('tenant_id', $ctx->tenantId())
            ->where('created_at', '>', now()->subDay())
            ->whereIn('stage', ['error', 'send_failed', 'job_failed'])->count();

        return ['count' => $err, 'label' => $err > 0 ? $err.' bot error(s) 24h' : 'bot healthy'];
    }

    public function command(CapabilityContext $ctx, string $verb, array $args): array
    {
        return ['ok' => false, 'error' => 'read-only capability'];
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = Tenant::find($ctx->tenantId());

        // ---- health chips (every probe guarded — diagnostics must never itself be down) ----
        $waStatus = (string) ($t?->setting('wa_status', 'open') ?? 'open');
        try {
            $inst = (string) ($t?->whatsapp_instance ?? '');
            if ($inst !== '') {
                $live = Cache::remember('wa_live:'.$ctx->tenantId(), 60,
                    fn () => app(\App\Services\WhatsApp\EvolutionAdmin::class)->state($inst));
                if ($live !== '') $waStatus = $live === 'open' ? 'open' : ($live === 'connecting' ? 'connecting' : 'down');
            }
        } catch (\Throwable $e) {
        }
        try { $redis = \Illuminate\Support\Facades\Redis::connection()->ping() ? 'connected' : 'down'; }
        catch (\Throwable $e) { $redis = 'down'; }
        $openai = trim((string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''))) !== '' ? 'configured' : 'MISSING';
        try { $failed = (int) DB::table('failed_jobs')->count(); } catch (\Throwable $e) { $failed = 0; }
        $lastHook = DB::table('bot_events')->where('tenant_id', $ctx->tenantId())
            ->where('stage', 'started')->orderByDesc('id')->value('created_at');
        $lastDone = DB::table('bot_events')->where('tenant_id', $ctx->tenantId())
            ->whereIn('stage', ['ai_replied', 'replied'])->orderByDesc('id')->value('created_at');
        $ago = fn ($v) => $v ? Carbon::parse($v)->diffForHumans(short: true) : '—';

        // ---- WhatsApp numbers ----
        $numRows = WhatsappChannel::query()->where('tenant_id', $ctx->tenantId())->orderByDesc('is_primary')->get()
            ->map(function ($ch) {
                try { $st = app(\App\Services\WhatsApp\EvolutionAdmin::class)->state((string) $ch->instance); }
                catch (\Throwable $e) { $st = 'unknown'; }

                return ($ch->is_primary ? 'PRIMARY · ' : '').($ch->label ?: $ch->instance).' · '.$ch->number
                    .' — '.($st === 'open' ? 'Connected ✅' : ($st ?: 'unknown').' ⚠️');
            })->all();

        // ---- the trail: last 200 bot_events, filterable by stage ----
        $events = DB::table('bot_events')->where('tenant_id', $ctx->tenantId())
            ->orderByDesc('id')->limit(200)->get();
        $columns = [
            ['key' => 'when', 'label' => 'When'],
            ['key' => 'phone', 'label' => 'Customer'],
            ['key' => 'stage', 'label' => 'Stage'],
            ['key' => 'detail', 'label' => 'Detail'],
        ];
        $rows = [];
        foreach ($events as $e) {
            $tone = in_array($e->stage, ['error', 'send_failed', 'job_failed'], true) ? 'danger'
                : (in_array($e->stage, ['skipped', 'paused', 'empty'], true) ? 'warn' : 'ok');
            $rows[] = [
                'id' => (int) $e->id,
                'when' => ['value' => Carbon::parse($e->created_at)->format('d M H:i:s'), 'sort' => strtotime($e->created_at)],
                'phone' => (string) $e->from_number,
                'stage' => ['value' => (string) $e->stage, 'badge' => $tone, 'sort' => (string) $e->stage],
                'detail' => mb_substr((string) ($e->detail ?? ''), 0, 90),
            ];
        }

        // Numbers as chips (guaranteed-rendering house UI; one chip per channel, max 3)
        $numChips = [];
        foreach (array_slice($numRows, 0, 3) as $i => $line) {
            $ok = str_contains($line, '\u{2705}') || str_contains($line, 'Connected');
            $numChips[] = ['key' => 'num'.$i, 'label' => 'Number '.($i + 1),
                'value' => $ok ? 'Connected' : 'Not linked', 'tone' => $ok ? 'ok' : 'danger',
                'foot' => mb_substr($line, 0, 60)];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:diagnostics', 'title' => 'Bot diagnostics',
                'subtitle' => 'Every customer message and what the bot did with it — newest first. Use this to see where a message stopped.',
            ])
            ->financials(array_merge([
                ['key' => 'wa', 'label' => 'WhatsApp',
                 'value' => $waStatus === 'open' ? 'Connected' : ($waStatus === 'connecting' ? 'Connecting…' : 'DOWN'),
                 'tone' => $waStatus === 'open' ? 'ok' : ($waStatus === 'connecting' ? 'warn' : 'danger'),
                 'foot' => (string) ($t?->whatsapp_instance ?? '')],
                ['key' => 'redis', 'label' => 'Queue (Redis)', 'value' => $redis,
                 'tone' => $redis === 'connected' ? 'ok' : 'danger', 'foot' => $failed.' failed job(s)'],
                ['key' => 'openai', 'label' => 'OpenAI', 'value' => $openai,
                 'tone' => $openai === 'configured' ? 'ok' : 'danger', 'foot' => 'AI brain'],
                ['key' => 'hook', 'label' => 'Last webhook', 'value' => $ago($lastHook), 'tone' => 'info', 'foot' => 'message received'],
                ['key' => 'done', 'label' => 'Last reply', 'value' => $ago($lastDone), 'tone' => 'info', 'foot' => 'bot answered'],
            ], $numChips))
            ->table($columns, $rows, [
                ['key' => 'stage', 'label' => 'Stage'],
                ['key' => 'phone', 'label' => 'Customer'],
            ], [
                'pageSize' => 25,
            ])
            ->actions([
                ['key' => 'wa_health', 'label' => 'Check connection now', 'method' => 'GET',
                 'endpoint' => '/papi/wa/health', 'actor' => 'human', 'icon' => 'refresh'],
                ['key' => 'flush', 'label' => 'Clear old failed jobs', 'method' => 'POST',
                 'endpoint' => '/papi/diag/queue-flush', 'actor' => 'human', 'icon' => 'trash',
                 'confirm' => 'Delete ALL failed job records? (These are historical failures — live messages are unaffected.)'],
            ]);
    }
}
