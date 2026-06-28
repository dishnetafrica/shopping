<?php
namespace App\Apps\Orders\Extract;

/** Payment method detection. Pure. */
class PaymentDetector
{
    /** @return array{method:?string,span:?string,confidence:float} */
    public static function detect(string $text): array
    {
        $low = mb_strtolower($text);
        $map = [
            'Mobile Money' => ['mobile money', 'momo', 'mtn money', 'airtel money', 'mtn momo', 'mm'],
            'Cash'         => ['cash on delivery', 'cash', 'cod'],
            'Card'         => ['card', 'visa', 'mastercard'],
        ];
        foreach ($map as $method => $needles) {
            foreach ($needles as $n) {
                if (preg_match('/\b' . preg_quote($n, '/') . '\b/', $low)) {
                    return ['method' => $method, 'span' => $n, 'confidence' => 0.96];
                }
            }
        }
        return ['method' => null, 'span' => null, 'confidence' => 0.0];
    }
}
