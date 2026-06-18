<?php
namespace App\Services\Bot;

/**
 * Pure parsing + phone normalisation for bulk lead import. No DB, no framework —
 * the controller handles dedupe + persistence. Import only: this never sends anything.
 */
class LeadImport
{
    /** Country codes we recognise as already-prefixed (East/Central Africa region). */
    private const CCS = ['211', '256', '254', '255', '250', '243', '249', '257', '253', '291'];

    /**
     * Normalise a raw phone string to bare international digits (no +), or null if implausible.
     * Handles +cc, 00cc, local trunk 0, and bare national numbers (prepends $cc).
     */
    public static function normalizePhone(string $raw, string $cc = '211'): ?string
    {
        $raw  = trim($raw);
        if ($raw === '') return null;
        $plus = strpos($raw, '+') === 0;
        $d    = preg_replace('/\D+/', '', $raw);
        if ($d === '') return null;

        if (strpos($d, '00') === 0) { $d = substr($d, 2); $plus = true; } // 00 intl prefix → treat as already-CC'd

        $hasCc = false;
        foreach (self::CCS as $c) {
            if (strpos($d, $c) === 0 && strlen($d) >= strlen($c) + 7) { $hasCc = true; break; }
        }

        if (! $plus && ! $hasCc) {
            if (strpos($d, '0') === 0) $d = substr($d, 1); // strip local trunk 0
            $stillCc = false;
            foreach (self::CCS as $c) {
                if (strpos($d, $c) === 0 && strlen($d) >= strlen($c) + 7) { $stillCc = true; break; }
            }
            if (! $stillCc) $d = $cc . $d;
        }

        if (strlen($d) < 10 || strlen($d) > 15) return null;
        return $d;
    }

    /** Parse pasted text / CSV into rows of [name, phone, source, tag]. Phone is raw (not normalised). */
    public static function parseRows(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $rows  = [];
        $header = null;

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cells = (strpos($line, ',') !== false)
                ? array_map('trim', explode(',', $line))
                : [trim($line)];

            if ($header === null && $i === 0 && self::looksHeader($cells)) {
                $header = self::mapHeader($cells);
                continue;
            }
            $rows[] = $header ? self::byHeader($cells, $header) : self::positional($cells);
        }

        return array_values(array_filter($rows, fn ($r) => $r['phone'] !== ''));
    }

    private static function isPhoneish(string $s): bool
    {
        return strlen(preg_replace('/\D+/', '', $s)) >= 7;
    }

    private static function looksHeader(array $cells): bool
    {
        foreach ($cells as $c) {
            if (preg_match('/^(phone|number|mobile|phone number|msisdn|name|source|tag|interest|label)$/i', trim($c))) {
                return true;
            }
        }
        return false;
    }

    private static function mapHeader(array $cells): array
    {
        $map = [];
        foreach ($cells as $k => $c) {
            $c = strtolower(trim($c));
            if (in_array($c, ['phone', 'number', 'mobile', 'phone number', 'msisdn'], true)) $map['phone'] = $k;
            elseif ($c === 'name')                                                            $map['name'] = $k;
            elseif ($c === 'source')                                                          $map['source'] = $k;
            elseif (in_array($c, ['tag', 'interest', 'label'], true))                         $map['tag'] = $k;
        }
        return $map;
    }

    private static function byHeader(array $cells, array $header): array
    {
        $g = fn ($f) => (isset($header[$f]) && isset($cells[$header[$f]])) ? $cells[$header[$f]] : '';
        return ['name' => $g('name'), 'phone' => $g('phone'), 'source' => $g('source'), 'tag' => $g('tag')];
    }

    private static function positional(array $cells): array
    {
        $name = $source = $tag = '';
        $pi = -1;
        foreach ($cells as $k => $c) {
            if (self::isPhoneish($c)) { $pi = $k; break; }
        }
        if ($pi < 0) return ['name' => $cells[0] ?? '', 'phone' => '', 'source' => '', 'tag' => ''];

        $phone = $cells[$pi];
        for ($k = 0; $k < $pi; $k++) {
            if (! self::isPhoneish($cells[$k])) { $name = $cells[$k]; break; }
        }
        $rest = array_values(array_slice($cells, $pi + 1));
        // Phone-first paste ("+211…,John"): the trailing cell is the name, not a source.
        if ($name === '' && $pi === 0 && $rest) {
            $name = array_shift($rest);
        }
        $source = $rest[0] ?? '';
        $tag    = $rest[1] ?? '';
        return ['name' => $name, 'phone' => $phone, 'source' => $source, 'tag' => $tag];
    }
}
