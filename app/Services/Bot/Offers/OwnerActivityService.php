<?php

namespace App\Services\Bot\Offers;

use App\Models\Conversation;
use App\Models\OwnerActivityLog;
use App\Models\Tenant;

/**
 * Status Intelligence v15 — Owner Activity Learning.
 *
 * Scores a free-form owner message and acts on the confidence band:
 *   >= 90  auto-apply the event        60-89  ask the owner to confirm        < 60  ignore
 *
 * Every detected candidate is written to owner_activity_log (the learning trail). The confirm
 * flow stores a pending event on the conversation; the owner's "yes"/"no" resolves it.
 *
 * Returns ['reply' => ?string, 'consume' => bool]. consume=false means "not an update" — the
 * message keeps flowing through normal handling so the owner can use the bot like anyone else.
 */
class OwnerActivityService
{
    public function __construct(protected OfferUpdateService $updates) {}

    public function process(Tenant $tenant, Conversation $convo, string $text): array
    {
        $text = trim($text);
        if ($text === '') return ['reply' => null, 'consume' => false];

        // 1) Resolve a pending confirmation first ("yes"/"no" to the last prompt).
        $pend = $this->pending($convo);
        if ($pend !== null) {
            if ($this->isAffirmative($text)) {
                $reply = $this->updates->applyParsed($tenant, $pend['parsed'], (string) ($pend['raw'] ?? ''));
                $this->log($tenant, (string) ($pend['raw'] ?? $text), $pend['parsed']['event'] ?? null, (int) ($pend['confidence'] ?? 0), true);
                $this->clearPending($convo);
                return ['reply' => $reply, 'consume' => true];
            }
            if ($this->isNegative($text)) {
                $this->log($tenant, (string) ($pend['raw'] ?? $text), $pend['parsed']['event'] ?? null, (int) ($pend['confidence'] ?? 0), false);
                $this->clearPending($convo);
                return ['reply' => "👍 Okay, ignored — nothing changed.", 'consume' => true];
            }
            // neither yes nor no: fall through and score this message fresh (pending self-expires)
        }

        // 2) Score the message.
        $sc    = OwnerActivityScorer::score($text);
        $event = $sc['event'];
        $conf  = (int) $sc['confidence'];

        if ($event === null) {
            return ['reply' => null, 'consume' => false];
        }

        if ($conf < 60) {
            $this->log($tenant, $text, $event, $conf, false);          // learning trail (ignored)
            return ['reply' => null, 'consume' => false];
        }

        if ($conf >= 90) {
            $reply = $this->updates->applyParsed($tenant, $sc, $text);
            $this->log($tenant, $text, $event, $conf, true);
            return ['reply' => $reply, 'consume' => true];
        }

        // 60-89 -> ask the owner to confirm
        $this->setPending($convo, ['parsed' => $sc, 'confidence' => $conf, 'raw' => $text, 'at' => time()]);
        $this->log($tenant, $text, $event, $conf, null);
        return ['reply' => $this->confirmPrompt($sc), 'consume' => true];
    }

    // ----------------------------------------------------------------- pending

    private function pending(Conversation $convo): ?array
    {
        $st = is_array($convo->state) ? $convo->state : [];
        $p  = $st['pending_owner_event'] ?? null;
        if (! is_array($p)) return null;
        if (time() - (int) ($p['at'] ?? 0) > 900) return null;          // 15-minute expiry
        return $p;
    }

    private function setPending(Conversation $convo, array $p): void
    {
        $st = is_array($convo->state) ? $convo->state : [];
        $st['pending_owner_event'] = $p;
        $convo->state = $st;
    }

    private function clearPending(Conversation $convo): void
    {
        $st = is_array($convo->state) ? $convo->state : [];
        unset($st['pending_owner_event']);
        $convo->state = $st;
    }

    // ------------------------------------------------------------- yes/no + ui

    private function isAffirmative(string $raw): bool
    {
        if (str_contains($raw, '👍') || str_contains($raw, '✅')) return true;
        $t = trim(preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($raw)));
        $yes = ['yes', 'y', 'ya', 'yeah', 'yep', 'yup', 'ok', 'okay', 'okk', 'sure', 'haa', 'ha',
            'han', 'haan', 'correct', 'confirm', 'confirmed', 'right', 'sahi', 'barabar', 'bilkul',
            'done', 'apply', 'add'];
        foreach (explode(' ', $t) as $w) {
            if ($w !== '' && in_array($w, $yes, true)) return true;
        }
        return false;
    }

    private function isNegative(string $raw): bool
    {
        $t = trim(preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($raw)));
        $no = ['no', 'n', 'na', 'nah', 'nope', 'nahi', 'cancel', 'ignore', 'skip', 'wrong', 'galat',
            'dont', 'stop', 'remove'];
        foreach (explode(' ', $t) as $w) {
            if ($w !== '' && in_array($w, $no, true)) return true;
        }
        return false;
    }

    private function confirmPrompt(array $sc): string
    {
        return "🤔 Should I tell customers: *" . $this->summary($sc) . "*?\nReply *YES* to confirm or *NO* to skip.";
    }

    private function summary(array $sc): string
    {
        $d = trim((string) ($sc['display'] ?? '')) ?: 'Lunch';
        return match ($sc['event'] ?? '') {
            'sold_out'     => "{$d} is sold out",
            'available'    => "Fresh {$d} is available now",
            'ready'        => "{$d} is ready",
            'low_stock'    => "Only " . (int) ($sc['qty'] ?? 0) . " {$d} left",
            'price_change' => "{$d} price updated",
            default        => "{$d} update",
        };
    }

    private function log(Tenant $tenant, string $message, ?string $event, int $conf, ?bool $approved): void
    {
        try {
            OwnerActivityLog::create([
                'message'        => mb_substr($message, 0, 500),
                'detected_event' => $event,
                'confidence'     => max(0, min(100, $conf)),
                'approved'       => $approved,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // logging is best-effort
        }
    }
}
