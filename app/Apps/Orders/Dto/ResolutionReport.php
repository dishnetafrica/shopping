<?php
namespace App\Apps\Orders\Dto;

/**
 * Per-message resolution summary for debugging production conversations: what got resolved (✓),
 * what's uncertain (⚠), what's still missing (✗). Built from the draft after a merge.
 */
final class ResolutionReport
{
    /** @param array<int,array{field:string,status:string,detail:string}> $items */
    public function __construct(public array $items = []) {}

    private const LOW_CONF = 0.5;

    public static function from(DraftOrder $draft): self
    {
        $items = [];
        foreach (DraftOrder::REQUIRED as $field) {
            if (! $draft->has($field)) {
                $items[] = ['field' => $field, 'status' => 'missing', 'detail' => ''];
                continue;
            }
            $status = ($draft->confidence[$field] ?? 1.0) < self::LOW_CONF ? 'uncertain' : 'resolved';
            $items[] = ['field' => $field, 'status' => $status, 'detail' => self::detail($draft, $field)];
        }
        return new self($items);
    }

    private static function detail(DraftOrder $d, string $field): string
    {
        return match ($field) {
            'lines'          => count($d->lines) . ' item(s)',
            'address'        => (string) ($d->address ?? $d->deliveryArea),
            'delivery_time'  => (string) ($d->deliveryTime . ($d->deliveryDate ? ' ' . $d->deliveryDate : '')),
            'payment_method' => (string) $d->paymentMethod,
            'phone'          => (string) $d->phone,
            default          => '',
        };
    }

    public function render(): string
    {
        $glyph = ['resolved' => '✓', 'uncertain' => '⚠', 'missing' => '✗'];
        $out = [];
        foreach ($this->items as $i) {
            $out[] = ($glyph[$i['status']] ?? '?') . ' ' . $i['field'] . ($i['detail'] !== '' ? " — {$i['detail']}" : '');
        }
        return implode("\n", $out);
    }
}
