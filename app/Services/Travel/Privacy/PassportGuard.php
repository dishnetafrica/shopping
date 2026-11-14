<?php

namespace App\Services\Travel\Privacy;

/**
 * Passport privacy on a travel tenant. The rules, all PURE decisions:
 *
 *   1. A passport number a customer TYPES is masked BEFORE the message is stored or shown to the AI
 *      model. Deliberately narrow: the message must contain the word "passport", and only a
 *      document-shaped token is masked, so booking references, dates and long phone numbers survive.
 *   2. No attachment is ever stored in the chat records or shown to the AI model, and the provider's
 *      raw body is dropped before queueing.
 *   3. A photo or a PDF may be a passport or ID card: readable() says how to reach it (its message key,
 *      and any bytes that arrived inline) so App\Services\Travel\Passport\PassportIntake can read its
 *      machine-readable zone ON THIS SERVER, in memory, and save the traveller's details to the trip.
 *      The file itself is never kept. A video or any other file is withheld unread, as before.
 *      Voice notes are not affected.
 *
 * The bot's own side of the rule (never ASK for passport data) is App\Services\Travel\Conversation\ReplyGuard.
 */
final class PassportGuard
{
    public const MASK = '[passport number removed]';

    /** Said once to a customer who sends a file that cannot be read here (a video, a Word file…). */
    public const NOTICE = "Thank you. I can't open this kind of file here, so please type the details instead. For a passport or ID card, you can send a clear photo of the page with the photo (for an ID card, the back) and I will read the names and dates of birth.";

    /** Said once to a customer whose photo or PDF could not be read as a passport or ID card. */
    public const NOTICE_UNREADABLE = "Thank you. I couldn't read the details from that file. If it is a passport or ID card, please send a clear photo of the page with the photo (for an ID card, the back), flat and in good light, with the lines of letters and <<< at the bottom fully visible. Or simply type each traveller's full name and date of birth. The file itself has not been kept.";

    /** What the chat log shows for a photo or PDF that was read. The details are on the trip, not in the chat. */
    public const READ_PLACEHOLDER = '[travel document read — details saved to the trip; file not kept]';

    public const PHOTO_PLACEHOLDER = '[photo not stored — travel privacy rule]';

    public const DOCUMENT_PLACEHOLDER = '[document not stored — travel privacy rule]';

    public const VIDEO_PLACEHOLDER = '[video not stored — travel privacy rule]';

    /**
     * @return array{text:string,masked:bool}
     */
    public static function mask(string $text): array
    {
        if ($text === '' || ! preg_match('/passport/i', $text)) {
            return ['text' => $text, 'masked' => false];
        }
        $masked = false;
        // A passport number: 6–10 letters/digits in one unbroken token, at least five of them digits,
        // optionally led by up to three letters and ended by up to two (A1234567, AB123456, 123456789).
        $out = preg_replace_callback('/(?<![A-Za-z0-9+])([A-Za-z]{0,3}\d{5,9}[A-Za-z]{0,2})(?![A-Za-z0-9])/', function (array $m) use (&$masked) {
            $token = $m[1];
            if (strlen($token) < 6 || strlen($token) > 10) {
                return $token;
            }
            // A plain year-first or day-first date written without separators (20261220, 20122026) is not a passport.
            if (preg_match('/^(19|20)\d{6}$/', $token) || preg_match('/^\d{4}(19|20)\d{2}$/', $token)) {
                return $token;
            }
            $masked = true;

            return self::MASK;
        }, $text);

        // The same number spelt out one character at a time, as a voice note is often transcribed
        // ("A 1 2 3 4 5 6 7", "1-2-3-4-5-6-7"). Only single characters separated by one space, dot or
        // dash qualify, so a date ("20 12 2026") or a grouped phone number ("0772 123 456") is left.
        $out = preg_replace_callback('/(?<![A-Za-z0-9])([A-Za-z0-9](?:[ .\-][A-Za-z0-9]){5,9})(?![A-Za-z0-9])/', function (array $m) use (&$masked) {
            $token = preg_replace('/[ .\-]/', '', $m[1]) ?? '';
            if (! preg_match('/^[A-Za-z]{0,3}\d{5,9}[A-Za-z]{0,2}$/', $token)) {
                return $m[1];
            }
            $masked = true;

            return self::MASK;
        }, $out ?? $text);

        return ['text' => $out ?? $text, 'masked' => $masked];
    }

    /**
     * Apply the rules to one incoming message payload (the array the webhook builds and the message
     * job handles). Used on travel tenants only.
     *
     *   - Typed passport numbers are masked in every text field.
     *   - Any attachment that is not a voice note (a photo, a PDF or other document, a video) is
     *     withheld: its bytes, the key that could fetch it later and its file name are removed, so
     *     nothing downstream can download, store or show it. The `has_*` flag is kept so the caller
     *     still knows what arrived.
     *   - The provider's raw webhook body is removed. Nothing reads it, and it carries the original
     *     text and, on some provider settings, the attachment itself.
     *
     * Idempotent: scrubbing twice gives the same result.
     *
     * @return array{incoming:array,withheld:?string}  withheld = image | document | video | null
     */
    public static function scrubInbound(array $incoming): array
    {
        foreach (['text', 'image_caption', 'media_caption', 'quoted_text'] as $k) {
            if (isset($incoming[$k]) && is_string($incoming[$k])) {
                $incoming[$k] = self::mask($incoming[$k])['text'];
            }
        }
        unset($incoming['raw']);

        $kind = self::attachmentKind($incoming);
        if ($kind !== null) {
            unset($incoming['image_b64'], $incoming['media_key'], $incoming['doc_name']);
        }

        return ['incoming' => $incoming, 'withheld' => $kind];
    }

    /**
     * What kind of withheld attachment a payload carries, or null (text, location, voice note).
     * The explicit flags decide; an attachment with no flag we recognise is treated as a document,
     * so a new attachment type is withheld by default rather than let through.
     */
    public static function attachmentKind(array $incoming): ?string
    {
        if (! empty($incoming['has_image'])) {
            return 'image';
        }
        if (! empty($incoming['has_document'])) {
            return 'document';
        }
        if (! empty($incoming['has_video'])) {
            return 'video';
        }
        $mime = strtolower(is_string($incoming['media_mime'] ?? null) ? $incoming['media_mime'] : '');
        if (! empty($incoming['has_audio']) || str_starts_with($mime, 'audio/')) {
            return null;
        }
        if (empty($incoming['media_key']) && $mime === '') {
            return null;
        }

        return str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'document');
    }

    /**
     * A photo or PDF from the customer that may be a passport or ID card, and how to reach it: the
     * provider's message key, the bytes if they arrived inline (an image's base64, or a document's
     * base64 inside the provider's raw body), or the reference the webhook left for the job
     * (`travel_media`, with `stashed` = the bytes wait in PassportInbox). Null for anything else,
     * including the business's own messages.
     *
     * @return array{ref:?array,mime:string,inline:?string,stashed:bool}|null
     */
    public static function readable(array $incoming): ?array
    {
        if (! empty($incoming['from_me'])) {
            return null;
        }
        $kind = self::attachmentKind($incoming);
        $tm   = is_array($incoming['travel_media'] ?? null) ? $incoming['travel_media'] : null;
        $mime = strtolower(is_string($incoming['media_mime'] ?? null) && $incoming['media_mime'] !== '' ? $incoming['media_mime'] : (string) ($tm['mime'] ?? ''));
        $readable = $kind === 'image' || ($kind === 'document' && (str_contains($mime, 'pdf') || str_starts_with($mime, 'image/')));
        if (! $readable) {
            return null;
        }
        $inline = null;
        foreach ([$incoming['image_b64'] ?? null, $incoming['raw']['data']['message']['base64'] ?? null, $incoming['raw']['data']['base64'] ?? null] as $b64) {
            if (is_string($b64) && $b64 !== '') {
                $inline = $b64;
                break;
            }
        }
        $ref = is_array($incoming['media_key'] ?? null) ? $incoming['media_key'] : (is_array($tm['ref'] ?? null) ? $tm['ref'] : null);
        $stashed = (bool) ($tm['stashed'] ?? false);
        if ($ref === null && $inline === null && ! $stashed) {
            return null;
        }

        return ['ref' => $ref, 'mime' => $mime !== '' ? $mime : ($kind === 'image' ? 'image/jpeg' : ''), 'inline' => $inline, 'stashed' => $stashed];
    }

    /** What the chat log shows in place of a withheld attachment. */
    public static function placeholder(string $kind): string
    {
        return match ($kind) {
            'image' => self::PHOTO_PLACEHOLDER,
            'video' => self::VIDEO_PLACEHOLDER,
            default => self::DOCUMENT_PLACEHOLDER,
        };
    }

    /** The alert the team receives when an attachment was withheld. */
    public static function staffAlert(string $kind, string $from): string
    {
        $what = match ($kind) {
            'image' => 'a photo',
            'video' => 'a video',
            default => 'a document',
        };

        return "📎 +{$from} sent {$what} that could not be read as a passport or ID card. It has not been stored (passport privacy rule). Open WhatsApp on the business phone if you need to see it.";
    }

    /** Whether an attachment kind is withheld on a travel tenant: everything except a voice note. */
    public static function withholds(string $kind): bool
    {
        return in_array($kind, ['image', 'document', 'file', 'video'], true);
    }
}
