<?php
namespace App\Services\Knowledge\Observatory;

/** Cheap script heuristic so "unknown intents by language" works. Pure. */
class LanguageDetector
{
    public static function detect(string $text): string
    {
        $hasGujarati = (bool) preg_match('/[\x{0A80}-\x{0AFF}]/u', $text);
        $hasLatin    = (bool) preg_match('/[a-zA-Z]/', $text);
        if ($hasGujarati && $hasLatin) return 'gujlish';
        if ($hasGujarati)              return 'gu';
        return 'en';
    }
}
