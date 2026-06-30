<?php

namespace App\Support;

/**
 * In-product release notes ("What's new"). Add a new entry at the TOP for each release.
 * The newest entry's `version` is what drives the unseen badge in the help widget, so bump
 * it whenever you want merchants to see the dot.
 */
class Changelog
{
    /** Newest first. Keep entries short and benefit-led (what the merchant gets, not internals). */
    public static function entries(): array
    {
        return [
            [
                'version' => '2026.06.30',
                'date'    => '30 Jun 2026',
                'title'   => 'Easier setup & in-app help',
                'items'   => [
                    'Setup wizard — set your country, currency and language yourself on first login.',
                    'Help assistant — tap the “?” button on any screen to ask how to do things.',
                    'Getting-started checklist — a guided list to get your shop live and taking orders.',
                    'Currency fix — shops can now run in their own currency (e.g. USD) with no conversion.',
                ],
            ],
        ];
    }

    /** The version string that drives the "unseen" badge. */
    public static function latestVersion(): string
    {
        $all = self::entries();
        return (string) ($all[0]['version'] ?? '');
    }
}
