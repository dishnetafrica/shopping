<?php
namespace App\Services\Bot\Merchant;

/**
 * Pure renderer for the confirmation summary. Takes resolved changes (each may carry a
 * display 'label'/'name' added by the assistant) and produces the "I found … Reply YES"
 * text. Also lists anything the parser could not read, so nothing is ever guessed.
 */
class MerchantSummary
{
    public static function render(array $changes, array $unparsed = []): string
    {
        $lines = ['I found:'];
        foreach ($changes as $c) {
            switch ($c['type'] ?? '') {
                case 'menu':
                    $lines[] = '🟢 Today’s menu: ' . self::names($c['items'] ?? ($c['labels'] ?? []));
                    break;
                case 'availability':
                    $mark = ($c['available'] ?? false) ? '✅' : '❌';
                    $word = ($c['available'] ?? false) ? 'available' : 'unavailable';
                    $lines[] = "$mark " . self::name($c) . " — $word today";
                    break;
                case 'special':
                    $lines[] = '⭐ Today’s special: ' . self::name($c);
                    break;
                case 'hours':
                    if ($c['closed'] ?? false) { $lines[] = '🔒 Closed today'; break; }
                    $bits = [];
                    if (! empty($c['open']))  $bits[] = '🕒 Open ' . $c['open'];
                    if (! empty($c['close'])) $bits[] = '🕖 Close ' . $c['close'];
                    $lines[] = implode('  ', $bits) . ' (today)';
                    break;
                case 'price':
                    $w = ! empty($c['weight_grams']) ? ' ' . \App\Services\Bot\Pricing\WeightParser::label((int) $c['weight_grams']) : '';
                    $was = isset($c['old']) && $c['old'] ? ' (was UGX ' . number_format((int) $c['old']) . ')' : '';
                    $lines[] = self::name($c) . "$w — UGX " . number_format((int) $c['price']) . $was;
                    if (! empty($c['warn'])) $lines[] = '⚠️ ' . $c['warn'];
                    break;
                case 'create_product':
                    $w = ! empty($c['weight_grams']) ? ' ' . \App\Services\Bot\Pricing\WeightParser::label((int) $c['weight_grams']) : '';
                    $cat = ! empty($c['category']) ? ' · ' . ucwords((string) $c['category']) : '';
                    $lines[] = '🆕 NEW: ' . self::name($c) . "$w — UGX " . number_format((int) $c['price']) . $cat;
                    $lines[] = '   (new product — reply NO if it should update an existing item)';
                    break;
                case 'notice':
                    $lines[] = '📣 Notice (today): "' . trim($c['text'] ?? '') . '"';
                    break;
                case 'note':
                    $lines[] = '📝 Note: "' . trim($c['text'] ?? '') . '"';
                    break;
            }
        }
        if ($unparsed) {
            $lines[] = '';
            $lines[] = 'Couldn’t read: ' . self::names($unparsed) . ' — please resend if needed.';
        }
        $lines[] = '';
        $lines[] = 'Apply? Reply YES to confirm.';
        return implode("\n", $lines);
    }

    private static function name(array $c): string
    {
        return ucwords((string) ($c['label'] ?? $c['name'] ?? $c['target'] ?? '?'));
    }

    private static function names(array $items): string
    {
        return implode(', ', array_map(fn ($i) => ucwords((string) (is_array($i) ? ($i['label'] ?? $i['name'] ?? '?') : $i)), $items));
    }
}
