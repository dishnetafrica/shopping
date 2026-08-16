<?php

namespace App\Console\Commands;

use App\Models\CommCampaign;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Services\Comms\CampaignService;
use App\Services\Comms\Marketing\CampaignSafetyGuard;
use App\Services\Comms\Marketing\DailyLimiter;
use App\Services\Comms\Marketing\MarketingConsent;
use App\Services\Comms\WhatsAppDispatcher;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * comms:verify-broadcast — the controlled staging transaction for the WhatsApp broadcast engine
 * (ADR-000 §12: prove with real data before trusting with real money/messages).
 *
 * Sends ONE real WhatsApp message to a recipient YOU name (--to, the owner's own number) and, in the
 * same run, drives an opted-out recipient through the pipeline — then reconciles what actually persisted:
 *   • opted-in  → comm_messages row 'sent' with a provider_message_id
 *   • opted-out → comm_messages row 'skipped' (skip_reason opted_out), NEVER sent
 *   • the daily counter advanced by exactly the real send (the opt-out cost nothing)
 *   • CampaignService::stats() reconciles (sent=1, skipped=1, opted_out=1)
 *
 * SAFETY — this is engineered so it can never fan out to real customers:
 *   • both test profiles get a unique one-off segment tag, and the campaign targets ONLY that tag, so
 *     the audience is exactly these two rows (never the tenant's real opted-in list);
 *   • it drives enqueueBatch() on ITS OWN campaign only — never processDue(), which would fire every
 *     due campaign on the tenant;
 *   • the opted-out number is synthetic and never contacted; the recipient is only ever --to;
 *   • nothing happens without --confirm (default is a dry run that just prints the plan).
 *
 * Routes through the authoritative writers only (MarketingConsent, CampaignService, WhatsAppDispatcher) —
 * it adds no second writer for consent, campaigns, or sends.
 */
class CommsVerifyBroadcast extends Command
{
    protected $signature = 'comms:verify-broadcast
        {--tenant= : Tenant id to run against}
        {--to= : The real WhatsApp number to send the single verification message to (the owner\'s own)}
        {--confirm : Actually send. Without this the command only prints what it would do}
        {--cleanup : Delete the verification campaign, its messages and the synthetic opt-out profile afterwards}';

    protected $description = 'Controlled staging transaction: one real broadcast + one opt-out, reconciled against comm_messages/stats/daily-cap';

    /** @var array<int,array{name:string,ok:bool,got:string}> */
    private array $checks = [];

    public function handle(CampaignService $svc): int
    {
        $tenantId = (int) $this->option('tenant');
        $to       = preg_replace('/[^0-9]/', '', (string) $this->option('to'));
        $confirm  = (bool) $this->option('confirm');
        $cleanup  = (bool) $this->option('cleanup');

        if ($tenantId <= 0 || strlen((string) $to) < 7) {
            $this->error('Usage: php artisan comms:verify-broadcast --tenant=<id> --to=<whatsapp number> --confirm [--cleanup]');
            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant {$tenantId} not found.");
            return self::FAILURE;
        }
        app(TenantContext::class)->set($tenant->id);

        $instance = $tenant->sendInstance();
        if ($instance === '') {
            $this->error("Tenant {$tenantId} has no WhatsApp instance connected — cannot send. Configure it first.");
            return self::FAILURE;
        }

        if (! $confirm) {
            $this->warn('DRY RUN — no message will be sent. Re-run with --confirm to perform the real transaction.');
            $this->line("  Tenant:     {$tenant->id} ({$tenant->name})");
            $this->line("  Instance:   {$instance}");
            $this->line("  Would send ONE real WhatsApp message to: {$to}");
            $this->line('  Would also drive a synthetic opted-out number through the pipeline (never contacted),');
            $this->line('  then reconcile comm_messages / stats / daily-cap and print PASS/FAIL.');
            return self::SUCCESS;
        }

        // Unique one-off tag so the campaign audience is exactly the two test rows — never real customers.
        $tag = 'stgverify_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $out = $this->synthOptOutNumber($tenant->id);

        $this->info("Staging broadcast verification — tenant {$tenant->id}, instance {$instance}");
        $this->line("  recipient (opted-in):  {$to}");
        $this->line("  synthetic (opted-out): {$out}  (never contacted)");
        $this->line("  audience tag:          {$tag}");
        $this->newLine();

        $dailyBefore = DailyLimiter::sentToday($tenant);

        // 1) IN profile — the real recipient. Tag it, opt it in. Remember the original tag to restore.
        $inProfile     = $this->ensureProfile($tenant, $to, 'Staging verify (IN)');
        $inOrigSegment = $inProfile->segment;
        $inProfile->segment = $tag;
        $inProfile->save();
        MarketingConsent::recordOptIn($tenant, $to, 'staging-verify');

        // 2) OUT profile — synthetic, opted in then straight back out (writes suppression + audit stamp).
        $outProfile = $this->ensureProfile($tenant, $out, 'Staging verify (OUT, synthetic)');
        $outProfile->segment = $tag;
        $outProfile->save();
        MarketingConsent::recordOptIn($tenant, $out, 'staging-verify');
        MarketingConsent::optOut($tenant->id, $out, 'staging-verify');

        // 3) Tiny draft campaign targeting ONLY the tag.
        $c = new CommCampaign();
        $c->tenant_id           = $tenant->id;
        $c->name                = 'STAGING VERIFY ' . $tag;
        $c->channel             = 'whatsapp';
        $c->text_body           = 'Staging verification for the broadcast engine — please ignore. ({{customer_name}})';
        $c->segment             = ['segment' => $tag];
        $c->status              = CommCampaign::ST_DRAFT;
        $c->throttle_per_minute = 60;
        $c->save();

        // 4) Launch and enqueue OUR campaign to completion (never processDue — that would fire others).
        $svc->launch($c);
        $guard = 0;
        do {
            $svc->enqueueBatch($c);
            $c->refresh();
        } while ($c->status !== CommCampaign::ST_DONE && ++$guard < 20);

        // 5) Force the single real send synchronously (atomic-claim-safe if a worker races), then poll.
        $inMsg = $this->campaignMessageTo($c, $to);
        if ($inMsg && in_array($inMsg->status, [CommMessage::ST_PENDING, CommMessage::ST_RETRY], true)) {
            app(WhatsAppDispatcher::class)->attempt($inMsg->id);
        }
        for ($i = 0; $i < 15; $i++) {
            $inMsg = $this->campaignMessageTo($c, $to);
            if ($inMsg && in_array($inMsg->status, [CommMessage::ST_SENT, CommMessage::ST_FAILED], true)) {
                break;
            }
            usleep(1_000_000);
        }

        // 6) Reconcile what actually persisted.
        $outMsg     = $this->campaignMessageTo($c, $out);
        $stats      = $svc->stats($c->refresh());
        $dailyafter = DailyLimiter::sentToday($tenant);

        $this->check('opted-in message reached "sent"',        ($inMsg?->status === CommMessage::ST_SENT),          'status=' . ($inMsg?->status ?? 'missing'));
        $this->check('provider_message_id captured',           (! empty($inMsg?->provider_message_id)),             'id=' . ($inMsg?->provider_message_id ?? '(none)'));
        $this->check('opted-out message is "skipped"',         ($outMsg?->status === CommMessage::ST_SKIPPED),      'status=' . ($outMsg?->status ?? 'missing'));
        $this->check('skip reason is opted_out',               ($outMsg?->skip_reason === CampaignSafetyGuard::R_OPTED_OUT), 'reason=' . ($outMsg?->skip_reason ?? '(none)'));
        $this->check('opted-out was NEVER sent',               ($outMsg && $outMsg->sent_at === null),              'sent_at=' . ($outMsg?->sent_at ?? 'null'));
        $this->check('stats: sent = 1',                        (($stats['sent'] ?? null) === 1),                    'sent=' . ($stats['sent'] ?? '?'));
        $this->check('stats: skipped = 1',                     (($stats['skipped'] ?? null) === 1),                 'skipped=' . ($stats['skipped'] ?? '?'));
        $this->check('stats: opted_out = 1',                   (($stats['opted_out'] ?? null) === 1),               'opted_out=' . ($stats['opted_out'] ?? '?'));
        $this->check('daily counter advanced by exactly 1',    ($dailyafter === $dailyBefore + 1),                  "before={$dailyBefore} after={$dailyafter}");
        $this->check('opted-out number is NOT eligible',       (! MarketingConsent::isEligible($tenant, $out)),     'eligible=' . (MarketingConsent::isEligible($tenant, $out) ? 'true' : 'false'));

        $this->renderReport($c);

        // 7) Restore / clean up.
        $inProfile->refresh();
        $inProfile->segment = $inOrigSegment;   // don't leave the owner's profile mis-tagged
        $inProfile->save();

        if ($cleanup) {
            CommMessage::withoutGlobalScope('tenant')->where('related_type', 'campaign')->where('related_id', $c->id)->delete();
            CommCampaign::withoutGlobalScope('tenant')->where('id', $c->id)->delete();
            CommSuppression::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->where('channel', 'whatsapp')
                ->where('address', CommSuppression::normalise($out, 'whatsapp'))->delete();
            CustomerProfile::withoutGlobalScope('tenant')->where('id', $outProfile->id)->delete();
            $this->line('Cleaned up: campaign, its messages, and the synthetic opt-out profile/suppression.');
        } else {
            $this->line("Kept for panel reconciliation — campaign id {$c->id} (\"{$c->name}\"). Re-run with --cleanup to remove.");
        }

        $allPass = ! collect($this->checks)->contains(fn ($c) => ! $c['ok']);
        $this->newLine();
        if ($allPass) {
            $this->info('RESULT: PASS — the broadcast engine reconciles with real data on staging.');
            return self::SUCCESS;
        }
        $this->error('RESULT: FAIL — one or more checks did not reconcile (see table above). Do NOT trust with live broadcasts yet.');
        return self::FAILURE;
    }

    /** Find an existing profile by digit-tail, or create a minimal one that passes the audience filter. */
    private function ensureProfile(Tenant $tenant, string $phone, string $label): CustomerProfile
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        $tail   = substr($digits, -9);

        $existing = CustomerProfile::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)->where('phone', 'like', '%' . $tail)->get()
            ->first(fn ($p) => CommSuppression::normalise((string) $p->phone, 'whatsapp') === $digits);
        if ($existing) {
            return $existing;
        }

        return CustomerProfile::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'phone'     => $phone,
            'name'      => $label,
            'stage'     => 'customer',   // anything but DISCOVERED so BusinessStage::sql() keeps it in the audience
        ]);
    }

    /** A synthetic number that is guaranteed not to collide (by digit-tail) with any existing profile. */
    private function synthOptOutNumber(int $tenantId): string
    {
        for ($try = 0; $try < 10; $try++) {
            $candidate = '999' . str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
            $tail      = substr($candidate, -9);
            $collides  = CustomerProfile::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)->where('phone', 'like', '%' . $tail)->exists();
            if (! $collides) {
                return $candidate;
            }
        }
        // Astronomically unlikely; fall back to a fixed synthetic value.
        return '999000000000';
    }

    private function campaignMessageTo(CommCampaign $c, string $phone): ?CommMessage
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');

        return CommMessage::withoutGlobalScope('tenant')
            ->where('related_type', 'campaign')->where('related_id', $c->id)
            ->where('channel', 'whatsapp')->where('to_email', $digits)
            ->orderByDesc('id')->first();
    }

    private function check(string $name, bool $ok, string $got): void
    {
        $this->checks[] = ['name' => $name, 'ok' => $ok, 'got' => $got];
    }

    private function renderReport(CommCampaign $c): void
    {
        $this->newLine();
        $this->line('Reconciliation:');
        foreach ($this->checks as $row) {
            $this->line(sprintf('  [%s] %-38s  %s', $row['ok'] ? '✓' : '✗', $row['name'], $row['got']));
        }
        $this->newLine();
    }
}
