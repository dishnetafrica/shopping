<?php
namespace App\Apps\Orders\Extract;

/** Delivery time/date detection: "8 am", "tomorrow 8:30pm", "sharp 8 am", "morning". Pure. */
class TimeParser
{
    /** @return array{time:?string,date:?string,span:?string,confidence:float} */
    public static function detect(string $text): array
    {
        $low = mb_strtolower($text);
        $date = null;
        if (str_contains($low, 'tomorrow') || str_contains($low, 'kal'))  $date = date('Y-m-d', strtotime('+1 day'));
        elseif (str_contains($low, 'today') || str_contains($low, 'aaje')) $date = date('Y-m-d');

        // clock time with am/pm (handles "sharp 8 am", "8:30pm")
        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $low, $m)) {
            $h = (int) $m[1] % 12; if (strtolower($m[3]) === 'pm') $h += 12;
            $time = sprintf('%02d:%02d', $h, (int) ($m[2] ?? 0));
            return ['time' => $time, 'date' => $date, 'span' => $m[0], 'confidence' => 0.97];
        }
        // 24h "at 18:30"
        if (preg_match('/\bat\s+(\d{1,2}):(\d{2})\b/', $low, $m)) {
            return ['time' => sprintf('%02d:%02d', (int) $m[1], (int) $m[2]), 'date' => $date, 'span' => $m[0], 'confidence' => 0.9];
        }
        foreach (['morning' => '09:00', 'afternoon' => '14:00', 'evening' => '18:00', 'night' => '20:00', 'noon' => '12:00'] as $w => $t) {
            if (str_contains($low, $w)) return ['time' => $t, 'date' => $date, 'span' => $w, 'confidence' => 0.6];
        }
        return ['time' => null, 'date' => $date, 'span' => null, 'confidence' => $date ? 0.6 : 0.0];
    }
}
