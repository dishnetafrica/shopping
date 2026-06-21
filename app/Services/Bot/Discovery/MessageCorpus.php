<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — message corpus. Pure logic, no framework deps.
 *
 * Normalizes mixed message sources (our `messages` rows with direction in/out, or parsed export
 * rows with from_owner) into two text streams — owner[] and customer[] — plus light stats the
 * miners and profilers run over. One place decides "who said this".
 */
class MessageCorpus
{
    /** @var string[] */ public array $owner = [];
    /** @var string[] */ public array $customer = [];
    /** @var array<int,array{ts:?string,from_owner:bool,body:string,media:bool}> */ public array $all = [];

    /**
     * Build from rows. Each row may carry:
     *   ['direction'=>'in'|'out'] (our messages: out = owner/bot) OR ['from_owner'=>bool],
     *   ['body'|'text'=>string], ['ts'|'created_at'=>?string], ['media'=>bool]
     */
    public static function fromRows(array $rows): self
    {
        $c = new self();
        foreach ($rows as $r) {
            $body = trim((string) ($r['body'] ?? $r['text'] ?? ''));
            $media = (bool) ($r['media'] ?? false);

            if (array_key_exists('from_owner', $r)) {
                $fromOwner = (bool) $r['from_owner'];
            } else {
                $fromOwner = (string) ($r['direction'] ?? 'in') === 'out';
            }

            $c->all[] = [
                'ts'         => $r['ts'] ?? $r['created_at'] ?? null,
                'from_owner' => $fromOwner,
                'body'       => $body,
                'media'      => $media,
            ];
            if ($body === '') continue;
            if ($fromOwner) $c->owner[] = $body; else $c->customer[] = $body;
        }
        return $c;
    }

    public function ownerText(): string { return mb_strtolower(implode("\n", $this->owner)); }
    public function customerText(): string { return mb_strtolower(implode("\n", $this->customer)); }
    public function total(): int { return count($this->all); }
    public function ownerCount(): int { return count($this->owner); }
    public function customerCount(): int { return count($this->customer); }
}
