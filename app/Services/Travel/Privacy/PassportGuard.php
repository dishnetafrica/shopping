<?php

namespace App\Services\Travel\Privacy;

/**
 * Passport data is never collected over chat on a travel tenant. Two rules, both PURE decisions:
 *
 *   1. A passport number a customer types anyway is masked BEFORE the message is stored or shown to
 *      the AI model. Deliberately narrow: the message must contain the word "passport", and only a
 *      document-shaped token is masked, so booking references, dates and long phone numbers survive.
 *   2. A photo sent to a travel tenant is not downloaded, not stored and not shown to the AI model.
 *      The platform cannot tell a passport photo from any other photo without reading it, so it
 *      reads none of them. Staff still see the photo in WhatsApp itself on the business phone.
 *
 * The bot's own side of the rule (never ASK for passport data) is App\Services\Travel\Conversation\ReplyGuard.
 */
final class PassportGuard
{
    public const MASK = '[passport number removed]';

    public const PHOTO_NOTICE = "Thank you. For your privacy I can't open photos sent here, so please type the details instead. Please do not send passport photos or numbers in this chat — our team will collect them securely when you book.";

    public const PHOTO_PLACEHOLDER = '[photo not stored — travel privacy rule]';

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

    /** Whether an incoming message kind is withheld on a travel tenant. Photos only, in milestone 1. */
    public static function withholds(string $kind): bool
    {
        return $kind === 'image';
    }
}
