<?php

namespace App\Services\Relationship;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Support\BotTrace;

/**
 * V4.2 — a reasoned escalation, built from mechanisms that already exist
 * (TaskEngine is the ORDER workflow, so no fake generic tasks):
 *   1. take the conversation over (agent_active — the bot stops, the proven mechanic)
 *   2. stamp the profile follow_up (visible on Customer 360 + follow-up surfaces)
 *   3. push-notify the team with the reason
 *   4. BotTrace the why (V4.3 reads these)
 * Fail-soft at every step — an escalation problem never breaks the reply.
 */
class EscalationService
{
    public function raise(Tenant $t, string $phone, string $reason, string $note = ''): void
    {
        $reason = in_array($reason, PlanParser::ESCALATION_REASONS, true) ? $reason : 'repeated_misunderstanding';
        $label = ucfirst(str_replace('_', ' ', $reason));

        try {
            Conversation::withoutGlobalScope('tenant')
                ->where('tenant_id', $t->id)->where('customer_phone', $phone)
                ->update(['agent_active' => true]);
        } catch (\Throwable $e) {
        }

        try {
            $p = CustomerProfile::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $t->id, 'phone' => $phone], []
            );
            $p->follow_up = mb_substr('ESCALATED: '.$label.($note !== '' ? ' — '.$note : ''), 0, 240);
            $p->save();
            $who = trim((string) $p->name) ?: $phone;
        } catch (\Throwable $e) {
            $who = $phone;
        }

        try {
            app(\App\Services\Push\WebPushService::class)->sendToTenant(
                $t->id, '🚨 Escalation: '.$label, $who.($note !== '' ? ' — '.$note : ''), '/v3#/chats');
        } catch (\Throwable $e) {
        }

        try {
            BotTrace::log($t->id, 'escalation', $phone, $reason, $note ?: null);
        } catch (\Throwable $e) {
        }
    }
}
