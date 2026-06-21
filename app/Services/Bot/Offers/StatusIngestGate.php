<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — WhatsApp Status ingestion gate. Pure logic, no framework deps.
 *
 * The gateway normally drops every status@broadcast / @broadcast / @g.us message. This carves
 * out exactly one case: a STATUS post that carries an IMAGE — a published menu / offer poster.
 * The job then verifies the poster is an authorized owner before ingesting it. Text-only
 * statuses, customer statuses, groups and other broadcasts are still dropped.
 */
class StatusIngestGate
{
    /** True if this message is a status post with an image (a candidate menu/offer poster). */
    public static function isStatusImage(string $remoteJid, bool $hasImage): bool
    {
        return $hasImage && str_contains($remoteJid, 'status@broadcast');
    }

    /**
     * The sender's phone number. For a status post the real author is in `participant`
     * (remoteJid is just status@broadcast); for a normal message it's the remoteJid.
     */
    public static function senderNumber(string $remoteJid, string $participantJid): string
    {
        $jid = (str_contains($remoteJid, 'status@broadcast') && $participantJid !== '')
            ? $participantJid
            : $remoteJid;

        return preg_replace('/[^0-9]/', '', explode('@', $jid)[0]) ?? '';
    }
}
