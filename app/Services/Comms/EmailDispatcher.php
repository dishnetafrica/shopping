<?php

namespace App\Services\Comms;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Models\Tenant;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\Providers\BrevoProvider;
use App\Services\Comms\Providers\MailgunProvider;
use App\Services\Comms\Providers\PostmarkProvider;
use App\Services\Comms\Providers\SendgridProvider;
use App\Services\Comms\Providers\SmtpProvider;
use Illuminate\Support\Facades\DB;

/**
 * The failover engine. Given one due CommMessage:
 *
 *   1. Claim it atomically (status pending|retry → sending) so two workers /
 *      the sweeper can never double-send. Loser of the race is a no-op.
 *   2. Walk the tenant's ACTIVE providers by priority ASC, skipping any that
 *      spent their daily budget.
 *   3. First success wins → status=sent, provider counters bumped.
 *   4. All providers fail → attempts++, exponential backoff (5m·2^n, cap 6h),
 *      status=retry … until max_attempts → status=failed.
 */
class EmailDispatcher
{
    public const DRIVER_MAP = [
        'smtp'     => SmtpProvider::class,
        'mailgun'  => MailgunProvider::class,
        'sendgrid' => SendgridProvider::class,
        'postmark' => PostmarkProvider::class,
        'brevo'    => BrevoProvider::class,
    ];

    public function attempt(int $messageId): void
    {
        // Atomic claim — mirrors OrderNotificationSend::claim() idempotency style.
        // channel guard: the WhatsApp dispatcher claims its own rows (M3).
        $claimed = DB::table('comm_messages')
            ->where('id', $messageId)
            ->where('channel', 'email')
            ->whereIn('status', [CommMessage::ST_PENDING, CommMessage::ST_RETRY])
            ->update(['status' => CommMessage::ST_SENDING, 'updated_at' => now()]);
        if (! $claimed) return;                         // someone else has it / not sendable

        $m = CommMessage::withoutGlobalScope('tenant')->find($messageId);
        if (! $m) return;

        $tenant = Tenant::find($m->tenant_id);
        if (! $tenant) {
            $this->fail($m, 'tenant not found');
            return;
        }

        $from = $this->fromIdentity($tenant);
        if (empty($from['email'])) {
            $this->fail($m, 'no From address configured (Email Center → Settings)');
            return;
        }

        $providers = CommProvider::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)->where('channel', 'email')
            ->where('is_active', true)->orderBy('priority')->orderBy('id')->get();

        if ($providers->isEmpty()) {
            $this->fail($m, 'no active email provider configured (Email Center → Providers)');
            return;
        }

        $lastError = 'no provider had daily budget left';
        foreach ($providers as $account) {
            $account->resetDailyWindowIfNeeded();
            if (! $account->hasDailyBudget()) continue;

            $impl = $this->make($account->driver);
            if (! $impl) {
                $lastError = "unknown driver '{$account->driver}'";
                continue;
            }

            $result = $impl->send($account, $m, $from);
            if ($result->ok) {
                $account->recordSend();
                $m->status              = CommMessage::ST_SENT;
                $m->provider_id         = $account->id;
                $m->provider_message_id = $result->messageId;
                $m->sent_at             = now();
                $m->attempts            = $m->attempts + 1;
                $m->last_error          = null;
                $m->save();
                return;
            }

            $lastError = '['.$account->name.'] '.$result->error;
            $account->last_error = $result->error;
            $account->save();
            // …and fall through to the next provider (the whole point).
        }

        // Every provider failed this round.
        $m->attempts = $m->attempts + 1;
        if ($m->attempts >= $m->max_attempts) {
            $this->fail($m, $lastError);
            return;
        }
        $minutes = min(5 * (2 ** $m->attempts), 360);
        $m->status          = CommMessage::ST_RETRY;
        $m->next_attempt_at = now()->addMinutes($minutes);
        $m->last_error      = $lastError;
        $m->save();
    }

    public function make(string $driver): ?EmailProvider
    {
        $cls = self::DRIVER_MAP[$driver] ?? null;
        return $cls ? app($cls) : null;
    }

    public function fromIdentity(Tenant $tenant): array
    {
        return [
            'email'    => (string) $tenant->setting('comms_email.from_email', ''),
            'name'     => (string) ($tenant->setting('comms_email.from_name') ?: $tenant->name),
            'reply_to' => (string) $tenant->setting('comms_email.reply_to', ''),
        ];
    }

    protected function fail(CommMessage $m, string $error): void
    {
        $m->status     = CommMessage::ST_FAILED;
        $m->last_error = mb_substr($error, 0, 1000);
        $m->save();
    }
}
