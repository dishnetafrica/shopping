<?php
namespace App\Services\Knowledge;

/** Where a knowledge event came from. WhatsApp is just the first adapter. */
final class Source
{
    public const WHATSAPP   = 'owner_whatsapp';
    public const PANEL      = 'seller_panel';
    public const VOICE_NOTE = 'voice_note';
    public const WEBSITE    = 'website';
    public const OCR        = 'ocr';
    public const IMPORT     = 'import';
    public const ADMIN      = 'admin';
    public const API        = 'api';
    public const FUTURE_AI  = 'future_ai';

    /** @return string[] */
    public static function all(): array
    {
        return [self::WHATSAPP, self::PANEL, self::VOICE_NOTE, self::WEBSITE,
                self::OCR, self::IMPORT, self::ADMIN, self::API, self::FUTURE_AI];
    }

    public static function valid(string $s): bool { return in_array($s, self::all(), true); }
}
