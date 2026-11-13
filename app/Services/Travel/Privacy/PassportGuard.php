<?php

namespace App\Services\Travel\Privacy;

/**
 * Passport data is never collected over chat on a travel tenant. Two rules, both PURE decisions:
 *
 *   1. A passport number a customer types anyway is masked BEFORE the message is stored or shown to
 *      the AI model. Deliberately narrow: the message must contain the word "passport", and only a
 *      document-shaped token is masked, so booking references, dates and long phone numbers survive.
 *   2. A photo, a PDF or other document, or a video sent to a travel tenant is not downloaded, not
 *      stored and not shown to the AI model. The platform cannot tell a passport from any other
 *      attachment without reading it, so it reads none of them. Staff still see the attachment in
 *      WhatsApp itself on the business phone. Voice notes are not affected.
 *
 * The bot's own side of the rule (never ASK for passport data) is App\Services\Travel\Conversation\ReplyGuard.
 */
final class PassportGuard
{
    public const MASK = '[passport number removed]';

    /** Said once to a customer who sends a photo, a document or a video. */
    public const NOTICE = "Thank you. For your privacy I can't open photos or documents sent here, so please type the details instead. Please do not send passport photos, scans or numbers in this chat — our team will collect them securely when you book.";

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

        return "📎 +{$from} sent {$what}. On a travel account photos and documents are not stored or read (passport privacy rule). Open WhatsApp on the business phone if you need to see it.";
    }

    /** Whether an attachment kind is withheld on a travel tenant: everything except a voice note. */
    public static function withholds(string $kind): bool
    {
        return in_array($kind, ['image', 'document', 'file', 'video'], true);
    }
}
