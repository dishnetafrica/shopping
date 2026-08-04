<?php

namespace App\Services\Marketing;

use App\Models\CommSuppression;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers that must never receive a campaign.
 *
 * Until now nothing stopped one. There is a `comm_suppressions` table, but only the Email
 * Center consulted it — both WhatsApp blast paths sent to whoever the audience query returned,
 * so a shop had no way to keep its own staff, a competitor, or a customer who had asked to be
 * left alone off a broadcast.
 *
 * THE MATCHING RULE. Every number, from every source, is reduced to digits with
 * `preg_replace('/[^0-9]/', '', …)` — because that is exactly what the send loops do
 * (AudienceResolver::norm, AiCampaignController, CampaignService, WhatsAppService).
 * An exclusion list that normalises differently does not error; it silently fails to match
 * and the message goes out anyway. There is a PhoneNormalizer in this codebase that adds a
 * country code and strips leading zeros — do NOT use it here, it would not match.
 *
 * Because a typed number and a stored number rarely agree on country code, matching is also
 * done on SUFFIX: excluding "0772 123 456" stops "256772123456" too. That is deliberate. The
 * tail is compared at 9 digits, long enough that a false positive needs two customers whose
 * numbers agree on their last nine digits.
 *
 * Sources, merged:
 *   1. Settings → WhatsApp & bot → "Never message these numbers"
 *   2. comm_suppressions where channel = 'whatsapp' (the unsubscribe/do-not-contact table)
 *   3. the shop's own staff numbers, when "Never message my own team" is on
 */
final class CampaignExclusions
{
    /** How many trailing digits must agree for two numbers to be the same person. */
    private const TAIL = 9;

    /** @var string[] full digit strings */
    private array $exact = [];

    /** @var string[] last-9-digit tails */
    private array $tails = [];

    public function __construct(private ?Tenant $tenant = null)
    {
        $this->load();
    }

    public static function for(?Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** Digits only — the same reduction every send loop applies. */
    public static function digits(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }

    /** Is this number on the do-not-message list? */
    public function blocks(?string $phone): bool
    {
        $d = self::digits($phone);
        if ($d === '') {
            return false;
        }
        if (in_array($d, $this->exact, true)) {
            return true;
        }
        if (strlen($d) >= self::TAIL) {
            return in_array(substr($d, -self::TAIL), $this->tails, true);
        }

        return false;
    }

    /**
     * Drop every excluded number from a list.
     *
     * @param  iterable<string>  $phones
     * @return array{kept:string[], removed:string[]}
     */
    public function filter(iterable $phones): array
    {
        $kept = []; $removed = [];
        foreach ($phones as $p) {
            if ($this->blocks($p)) {
                $removed[] = $p;
            } else {
                $kept[] = $p;
            }
        }

        return ['kept' => array_values($kept), 'removed' => array_values($removed)];
    }

    /** Just the survivors — the common case. */
    public function keep(iterable $phones): array
    {
        return $this->filter($phones)['kept'];
    }

    public function isEmpty(): bool
    {
        return $this->exact === [] && $this->tails === [];
    }

    /** How many distinct numbers are on the list (for the settings screen / audience preview). */
    public function size(): int
    {
        return count($this->exact);
    }

    // ------------------------------------------------------------------ loading

    private function load(): void
    {
        $t = $this->tenant;
        if (! $t) {
            return;
        }

        $raw = [];

        // 1 — the free-text list the owner maintains in Settings.
        try {
            foreach (self::parseList((string) $t->setting('campaign_exclude', '')) as $bit) {
                $raw[] = $bit;
            }
        } catch (\Throwable $e) {
            // a bad setting must never stop a campaign from being filtered at all
        }

        // 2 — the do-not-contact table, WhatsApp channel.
        try {
            if (Schema::hasTable('comm_suppressions')) {
                $rows = \Illuminate\Support\Facades\DB::table('comm_suppressions')
                    ->where('tenant_id', $t->id)->where('channel', 'whatsapp')
                    ->pluck('address');
                foreach ($rows as $a) {
                    $raw[] = (string) $a;
                }
            }
        } catch (\Throwable $e) {
        }

        // 3 — the shop's own team. Default ON: a broadcast that messages your own staff looks
        //     careless to them and skews every open/reply figure you then read.
        try {
            if ((bool) $t->setting('campaign_exclude_staff', true)) {
                foreach ($this->staffNumbers($t) as $s) {
                    $raw[] = $s;
                }
            }
        } catch (\Throwable $e) {
        }

        foreach ($raw as $one) {
            $d = self::digits($one);
            if ($d === '' || strlen($d) < 6) {         // too short to be a real number
                continue;
            }
            $this->exact[] = $d;
            if (strlen($d) >= self::TAIL) {
                $this->tails[] = substr($d, -self::TAIL);
            }
        }
        $this->exact = array_values(array_unique($this->exact));
        $this->tails = array_values(array_unique($this->tails));
    }

    /**
     * Split the owner's free-text box into individual numbers.
     *
     * Deliberately NOT split on spaces: "+256 772 123 456" is how a person actually writes a
     * number, and splitting on whitespace shattered it into four fragments that matched
     * nobody — silently, because a list that matches nothing looks exactly like a list that
     * is working. Lines, commas and semicolons separate; spaces are part of the number.
     *
     * The one ambiguous case is several numbers space-separated on a single line. E.164 caps
     * a real number at 15 digits, so anything longer than that must be more than one, and
     * only then is the line split on whitespace as well.
     *
     * @return string[]
     */
    public static function parseList(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $d = self::digits($chunk);
            if ($d === '') {
                continue;
            }
            if (strlen($d) > 15) {
                foreach (preg_split('/\s+/', $chunk) ?: [] as $piece) {
                    $pd = self::digits($piece);
                    if ($pd !== '') {
                        $out[] = $pd;
                    }
                }
                continue;
            }
            $out[] = $d;
        }

        return $out;
    }

    /** @return string[] */
    private function staffNumbers(Tenant $t): array
    {
        $out = [];
        try {
            if (method_exists($t, 'ownerAlertNumbers')) {
                foreach ((array) $t->ownerAlertNumbers() as $n) {
                    $out[] = (string) $n;
                }
            }
        } catch (\Throwable $e) {
        }
        try {
            $out[] = (string) ($t->whatsapp_number ?? '');     // the shop's own line
        } catch (\Throwable $e) {
        }
        try {
            if (Schema::hasTable('order_notification_recipients')) {
                $rows = \Illuminate\Support\Facades\DB::table('order_notification_recipients')
                    ->where('tenant_id', $t->id)->pluck('phone');
                foreach ($rows as $p) {
                    $out[] = (string) $p;
                }
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }

    // ------------------------------------------------------------------ writing

    /**
     * Add a number to the do-not-contact table for WhatsApp. Stored as digits so it matches
     * what the send loops compare against.
     */
    public static function block(int $tenantId, string $phone, string $reason = 'manual', ?string $source = null): bool
    {
        $d = self::digits($phone);
        if ($d === '' || strlen($d) < 6) {
            return false;
        }
        CommSuppression::add($tenantId, $d, $reason, $source, 'whatsapp');

        return true;
    }
}
