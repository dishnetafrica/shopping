<?php
namespace App\Apps\DailyMenu;

use App\Services\Knowledge\Contracts\Extractor;
use App\Services\Knowledge\Dto\ActionRequest;
use App\Services\Knowledge\Dto\ExtractionResult;
use App\Services\Knowledge\EntityConfidence;
use App\Services\Knowledge\Intent;

/**
 * Daily-menu extraction (pure): headed meal blocks, inline meal lines, sold-out, specials,
 * and "no <meal>". Emits Actions only (menu membership + availability + specials); product
 * prices on a line ride along in params and are applied by the projector. Date defaults to
 * today here; the evening-nudge "tomorrow" default is applied by the assistant (2b).
 */
class MenuExtractor implements Extractor
{
    private const MEALS = [
        'breakfast' => 'breakfast', 'nasto' => 'breakfast',
        'lunch' => 'lunch', 'jaman' => 'lunch',
        'dinner' => 'dinner',
        'snacks' => 'snacks', 'farsan' => 'snacks',
    ];

    public function extract(string $text, array $profile = []): ExtractionResult
    {
        $r = new ExtractionResult(intent: Intent::MENU);
        $date = $this->date($text);
        $current = null;                                   // active meal bucket while scanning a block

        foreach (preg_split('/\n+/', $text) as $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            $low = mb_strtolower($line);

            // header line e.g. "Lunch:" or "Lunch" (also "Dinner only Paneer 22000")
            if ($meal = $this->headerMeal($low)) {
                $current = $meal;
                $rest = trim(preg_replace('/^[a-z]+\s*:?\s*(only)?\s*/i', '', $line) ?? '');
                if ($rest !== '') $this->item($r, $current, $rest, $date);   // inline item after header
                continue;
            }
            // "no lunch (tomorrow)"
            if (preg_match('/^no\s+([a-z]+)/i', $low, $m) && isset(self::MEALS[$m[1]])) {
                $r->actions[] = new ActionRequest('daily_menu', 'clear_meal', null, ['meal' => self::MEALS[$m[1]], 'date' => $date]);
                continue;
            }
            // "X sold out / finished / khatam"
            if (preg_match('/^(.+?)\s+(sold out|finished|khatam|out of stock|not available)\b/i', $line, $m)) {
                $r->actions[] = new ActionRequest('daily_menu', 'mark_unavailable', $this->clean($m[1]), ['date' => $date]);
                $r->intent = Intent::AVAILABILITY;
                continue;
            }
            // "special X" / "today special X"
            if (preg_match('/^(?:today\'?s?\s+)?special\s*:?\s*(.+)$/i', $line, $m)) {
                $this->special($r, $this->clean($m[1]), $date);
                continue;
            }
            // bullet / plain item under an active meal header
            $item = ltrim($line, "-*•\t ");
            if ($current && $item !== '') $this->item($r, $current, $item, $date);
        }

        if (! $r->actions) $r->intent = Intent::NOTE;
        return $r;
    }

    private function item(ExtractionResult $r, string $meal, string $text, ?string $date): void
    {
        [$name, $price] = $this->splitPrice($text);
        if ($name === '') return;
        $entities = [EntityConfidence::entity('product', $name, 0.95), EntityConfidence::entity('meal', $meal, 0.9)];
        if ($price !== null) $entities[] = EntityConfidence::entity('price', $price, 0.95);
        $r->actions[] = new ActionRequest('daily_menu', 'add_menu_item', $name,
            array_filter(['meal' => $meal, 'price' => $price, 'date' => $date], fn ($v) => $v !== null));
    }

    private function special(ExtractionResult $r, string $text, ?string $date): void
    {
        [$name, $price] = $this->splitPrice($text);
        if ($name === '') return;
        $r->actions[] = new ActionRequest('daily_menu', 'add_special', $name,
            array_filter(['price' => $price, 'date' => $date], fn ($v) => $v !== null));
    }

    private function headerMeal(string $low): ?string
    {
        if (preg_match('/^([a-z]+)\b/', $low, $m) && isset(self::MEALS[$m[1]])) return self::MEALS[$m[1]];
        return null;
    }

    /** @return array{0:string,1:?int} name, price|null */
    private function splitPrice(string $text): array
    {
        if (preg_match('/^(.+?)\s+(?:ugx\s*)?([0-9][0-9,\.]*\s*k?)$/i', trim($text), $m)) {
            return [$this->clean($m[1]), $this->num($m[2])];
        }
        return [$this->clean($text), null];
    }

    private function date(string $text): ?string
    {
        $low = mb_strtolower($text);
        if (str_contains($low, 'tomorrow') || str_contains($low, 'kal')) return date('Y-m-d', strtotime('+1 day'));
        if (str_contains($low, 'today') || str_contains($low, 'aaje')) return date('Y-m-d');
        return null;                                        // assistant decides default (today vs tomorrow) in 2b
    }

    private function num(string $s): int
    {
        $s = strtolower(trim($s)); $k = str_ends_with($s, 'k');
        $n = (float) str_replace([',', ' ', 'k'], '', $s);
        return (int) round($k ? $n * 1000 : $n);
    }

    private function clean(string $s): string { return ucwords(trim(preg_replace('/\s+/', ' ', mb_strtolower($s)) ?? '')); }
}
