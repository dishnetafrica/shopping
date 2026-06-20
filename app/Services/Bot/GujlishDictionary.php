<?php
namespace App\Services\Bot;

/**
 * Gujlish / Gujarati Intent Dictionary V1.
 *
 * A deterministic phrase -> intent lookup, mined from real Pal's Snacks chat data
 * (not invented, not GPT). The router consults this BEFORE its regex lexicons and
 * BEFORE any catalogue matching, so common Gujlish phrases are understood as intents
 * instead of being searched as product names ("kale aavishu" = "we'll come tomorrow",
 * not a product).
 *
 * Pure logic. Keys are written naturally and normalized at load time, so spelling
 * variants converge: "kale aavishu" / "kaale aavishu" / "kale avishu" -> "kale avishu".
 *
 * Intents returned are the OrderIntentRouter intent strings, plus 'visit'.
 */
class GujlishDictionary
{
    /** Natural phrase => intent. Normalized once at load (see map()). */
    private const RAW = [
        // ---- greeting ----
        'hi' => 'greeting', 'hii' => 'greeting', 'hello' => 'greeting', 'helo' => 'greeting',
        'hey' => 'greeting', 'jsk' => 'greeting', 'jai shree krishna' => 'greeting',
        'jai shri krishna' => 'greeting', 'jay shree krishna' => 'greeting',
        'jai swaminarayan' => 'greeting', 'jay swaminarayan' => 'greeting',
        'kem cho' => 'greeting', 'kemcho' => 'greeting', 'kem cho' => 'greeting',
        'namaste' => 'greeting', 'namaskar' => 'greeting', 'ram ram' => 'greeting',
        'radhe radhe' => 'greeting', 'kaise ho' => 'greeting',
        'good morning' => 'greeting', 'good afternoon' => 'greeting',
        'good evening' => 'greeting', 'good night' => 'greeting',

        // ---- social (relationship terms, pleasantries, gratitude, acknowledgement) ----
        'bhabhi' => 'social', 'hi bhabhi' => 'social', 'helo bhabhi' => 'social',
        'yes bhabhi' => 'social', 'ben' => 'social', 'ha ben' => 'social',
        'masi' => 'social', 'kaka' => 'social', 'bhai' => 'social', 'bhaiya' => 'social',
        'how are you' => 'social', 'hi how are you' => 'social', 'maja ma' => 'social',
        'aa tamaru che' => 'social', 'a tamaru che' => 'social',
        'thank you' => 'social', 'thanks' => 'social', 'thank u' => 'social',
        'thank you so much' => 'social', 'welcome' => 'social', 'great' => 'social',
        'nice' => 'social', 'good' => 'social', 'wow' => 'social',

        // ---- confirmation ----
        'ok' => 'confirm', 'okay' => 'confirm', 'ohk' => 'confirm', 'okk' => 'confirm',
        'k' => 'confirm', 'yes' => 'confirm', 'ya' => 'confirm', 'yes plz' => 'confirm',
        'yes please' => 'confirm', 'ha' => 'confirm', 'haa' => 'confirm', 'ho' => 'confirm',
        'ha chalse' => 'confirm', 'chalse' => 'confirm', 'chale' => 'confirm',
        'thik' => 'confirm', 'thik che' => 'confirm', 'thik che' => 'confirm',
        'barabar' => 'confirm', 'barabar che' => 'confirm', 'baraber che' => 'confirm',
        'ha barabar che' => 'confirm', 'ha baraber che' => 'confirm', 'ha right' => 'confirm',
        'done' => 'confirm', 'noted' => 'confirm', 'confirm' => 'confirm',
        'please confirm' => 'confirm', 'pls confirm' => 'confirm', 'ha paku' => 'confirm',
        'paki' => 'confirm', 'ok thanks' => 'confirm', 'ok thank you' => 'confirm',
        'ok done' => 'confirm', 'ok ha' => 'confirm', 'sure' => 'confirm',

        // ---- cancellation (mapped to removal) ----
        'nathi lavanu' => 'removal', 'nathi levanu' => 'removal', 'na nai levanu' => 'removal',
        'cancel' => 'removal', 'cancel kar do' => 'removal', 'ap cancel kar do' => 'removal',
        'cancel karo' => 'removal', 'rehva do' => 'removal', 'nahi joie' => 'removal',
        'nathi joiti' => 'removal', 'nathi joitu' => 'removal', 'remove' => 'removal',
        'order cancel' => 'removal',

        // ---- delivery / logistics ----
        'location' => 'delivery', 'address' => 'delivery', 'home delivery' => 'delivery',
        'delivery' => 'delivery', 'mokli dejo' => 'delivery', 'mokali dejo' => 'delivery',
        'thali mokali dejo' => 'delivery', 'thali mokali dejo ne' => 'delivery',
        'moklavjo' => 'delivery', 'vage moklavjo' => 'delivery', 'mokljo' => 'delivery',
        'mokli apjo' => 'delivery', 'bhej do' => 'delivery', 'bhej dejo' => 'delivery',
        'deliver' => 'delivery', 'send it' => 'delivery',

        // ---- price ----
        'how much' => 'price', 'price' => 'price', 'total' => 'price', 'amount' => 'price',
        'cost' => 'price', 'ketla thay' => 'price', 'ketla thaya' => 'price',
        'ketlo thay' => 'price', 'shu bhav' => 'price', 'bhav' => 'price', 'rate' => 'price',

        // ---- menu ----
        'menu' => 'menu', 'today menu' => 'menu', 'todays menu' => 'menu',
        'thali menu' => 'menu', 'lunch menu' => 'menu', 'show menu' => 'menu',
        'menu che' => 'menu', 'menu please' => 'menu',

        // ---- visit / arrival (the screenshot failure class) ----
        'kale aavishu' => 'visit', 'kale avishu' => 'visit', 'kal aavishu' => 'visit',
        'kale avu' => 'visit', 'kale avu chu' => 'visit', 'kale aavu chu' => 'visit',
        'aavu chu' => 'visit', 'hu aavu chu' => 'visit', 'avu chu' => 'visit',
        'kale avana cho' => 'visit', 'kale aavana cho' => 'visit', 'kale avana chho' => 'visit',
        'aje ava na cho' => 'visit', 'ava na cho' => 'visit', 'aje avu chu' => 'visit',
        'we are coming' => 'visit', 'ame aavie chie' => 'visit', 'ame avie chie' => 'visit',
        'kal aau ga' => 'visit', 'kal aavu' => 'visit',

        // ---- support (mapped to human) ----
        'contact' => 'human', 'call me' => 'human', 'send me number' => 'human',
        'number send' => 'human', 'customer care' => 'human', 'agent' => 'human',
        'talk to you' => 'human',
    ];

    private static ?array $map = null;

    /** Normalize a phrase/message: lowercase, strip punctuation/emoji/urls, collapse repeats + spaces. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('#https?://\S+#u', ' ', $s);
        $s = preg_replace('/[^a-z\s]/u', ' ', $s);          // keep latin letters + space (Gujlish)
        $s = preg_replace('/([a-z])\1+/u', '$1', $s);       // collapse repeats: aavishu->avishu, okk->ok
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** @return string|null intent for the message, or null if no known phrase matches. */
    public static function lookup(string $text): ?string
    {
        $n = self::normalize($text);
        if ($n === '') return null;
        $map = self::map();

        if (isset($map[$n])) return $map[$n];               // exact whole-message match

        // Longest multi-word phrase appearing as a whole-word run inside the message.
        // Greeting/social are excluded here — they are frequently polite prefixes to a real
        // request ("good morning, please add…"), so they only match as the whole message.
        $best = null; $bestLen = 0;
        foreach ($map as $ph => $intent) {
            if (strpos($ph, ' ') === false) continue;        // single-word entries: exact-only
            if ($intent === 'greeting' || $intent === 'social') continue;
            if (preg_match('/(?:^| )' . preg_quote($ph, '/') . '(?: |$)/u', $n) && strlen($ph) > $bestLen) {
                $bestLen = strlen($ph); $best = $intent;
            }
        }
        return $best;
    }

    private static function map(): array
    {
        if (self::$map !== null) return self::$map;
        $out = [];
        foreach (self::RAW as $phrase => $intent) {
            $k = self::normalize($phrase);
            if ($k !== '' && ! isset($out[$k])) $out[$k] = $intent;
        }
        return self::$map = $out;
    }
}
