<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence v16 — activity source classifier. Pure logic, no framework deps.
 *
 * Tags where a learned signal came from, so the Activity Feed can show the owner's real workflow:
 *   owner_status  — a WhatsApp Status image
 *   owner_forward — a forwarded image (marketing poster, broadcast)
 *   owner_image   — an image sent directly to the bot
 *   owner_message — a plain text message
 */
class ActivitySource
{
    public const MESSAGE = 'owner_message';
    public const IMAGE   = 'owner_image';
    public const STATUS  = 'owner_status';
    public const FORWARD = 'owner_forward';

    public const ALL = [self::MESSAGE, self::IMAGE, self::STATUS, self::FORWARD];

    public static function classify(bool $isStatus, bool $isForward, bool $hasImage): string
    {
        if ($isStatus) return self::STATUS;
        if ($isForward && $hasImage) return self::FORWARD;
        if ($hasImage) return self::IMAGE;
        return self::MESSAGE;
    }
}
