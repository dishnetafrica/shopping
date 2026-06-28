<?php
namespace App\Apps\Orders\Extract;

/** Phone-number detection (Uganda/South Sudan friendly). Pure. */
class PhoneDetector
{
    /** @return array{phone:?string,span:?string,confidence:float} */
    public static function detect(string $text): array
    {
        if (preg_match('/(?<!\d)(\+?256|0)\s?7\d{2}\s?\d{3}\s?\d{3}(?!\d)/', $text, $m)) {
            return ['phone' => preg_replace('/\s+/', '', $m[0]), 'span' => $m[0], 'confidence' => 0.97];
        }
        if (preg_match('/(?<!\d)\+?\d{9,13}(?!\d)/', $text, $m)) {
            return ['phone' => $m[0], 'span' => $m[0], 'confidence' => 0.8];
        }
        return ['phone' => null, 'span' => null, 'confidence' => 0.0];
    }
}
