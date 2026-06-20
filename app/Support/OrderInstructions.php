<?php
namespace App\Support;

/**
 * Splits a customer message into a product query and a free-text cooking instruction.
 *
 *   "chicken biryani extra spicy"  -> dish: "chicken biryani", note: "extra spicy"
 *   "butter chicken no onion"      -> dish: "butter chicken",  note: "no onion"
 *   "less spicy"                   -> instruction only (no dish)
 *   "hot & sour soup"              -> dish unchanged, no note   (NOT treated as "hot")
 *
 * Design rule: the INLINE splitter is deliberately conservative — it only fires on a
 * *qualified* instruction (no / less / extra / without / hold the …) so it can never
 * truncate a real dish name that merely contains a spice word ("Hot & Sour Soup",
 * "Garlic Naan", "Chilly Chicken", "Black Garlic Chicken"). "Add …" is excluded so the
 * common "Add Veg Burger" add-to-cart phrase is never eaten.
 *
 * The instruction-ONLY check (for follow-up messages like "make it mild") is broader,
 * because there is no dish in the message to damage.
 *
 * Pure logic — unit-tested framework-free in qa/order_instructions.php.
 */
class OrderInstructions
{
    /** Qualified instruction phrases — safe to split a dish on. Match to end of string. */
    private const QUALIFIED = '/\b(?:'
        . 'no|less|without|hold\s+the|hold|easy\s+on(?:\s+the)?|extra|'
        . 'not\s+too|very|too'
        . ')\s+[a-z][a-z &]*'
        . '|\bwell[\s-]?(?:done|cooked)\b'
        . '|\bfully\s+cooked\b'
        . '|\bjain\b'
        . '|\bspicy\s+(?:hot|mild)\b'
        . '/i';

    /** Bare spice-level words — only meaningful when the WHOLE message is an instruction. */
    private const BARE_LEVEL = '/\b(?:spicy|mild|medium|hot|bland|sweet|salty|oily|crispy|soft)\b/i';

    /** Filler that is neither dish nor instruction. */
    private const FILLER = '/\b(?:make|keep|cook|do|have|want|it|its|the|my|order|this|that|please|pls|kindly|and|with|for|me|a|an|to|all)\b/i';

    /**
     * @return array{0:string,1:string} [dishQuery, note]. dishQuery is '' when the message
     * is instruction-only (or instruction-led with no trailing dish).
     */
    public static function split(string $text): array
    {
        $t = trim(preg_replace('/\s+/', ' ', $text));
        if ($t === '') return ['', ''];

        if (! preg_match(self::QUALIFIED, $t, $m, PREG_OFFSET_CAPTURE)) {
            return [$t, ''];                         // no qualified instruction -> all dish
        }

        $pos  = $m[0][1];
        $dish = self::tidyDish(substr($t, 0, $pos));
        $note = self::tidyNote(substr($t, $pos));

        return [$dish, $note];
    }

    /** True when the whole message is a cooking instruction with no dish to act on. */
    public static function isInstructionOnly(string $text): bool
    {
        $t = trim(preg_replace('/\s+/', ' ', $text));
        if ($t === '') return false;

        // Remove qualified instructions, bare spice levels and filler; if almost nothing
        // is left, it was an instruction, not a dish.
        $residual = preg_replace(self::QUALIFIED, ' ', $t);
        $residual = preg_replace(self::BARE_LEVEL, ' ', $residual);
        $residual = preg_replace(self::FILLER, ' ', $residual);
        $residual = preg_replace('/[^a-z0-9]+/i', '', $residual);

        // Must have actually contained an instruction signal.
        $hadSignal = preg_match(self::QUALIFIED, $t) || preg_match(self::BARE_LEVEL, $t);

        return (bool) $hadSignal && mb_strlen($residual) < 3;
    }

    /** Pull just the instruction out of a message (for the follow-up / order-note path). */
    public static function note(string $text): string
    {
        [$dish, $note] = self::split($text);
        if ($note !== '') return $note;
        // Instruction-only message with only bare spice words: keep the cleaned phrase.
        $t = self::tidyNote($text);
        return $t;
    }

    private static function tidyDish(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/[,\s]*\b(and|with)\b\s*$/i', '', $s); // drop trailing "and"/"with"
        $s = rtrim($s, " ,-");
        // If what's left is only filler, there's no real dish.
        $probe = preg_replace(self::FILLER, ' ', $s);
        $probe = preg_replace('/[^a-z0-9]+/i', '', $probe);
        return mb_strlen($probe) < 2 ? '' : trim($s);
    }

    private static function tidyNote(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/^\W+/', '', $s);
        $s = preg_replace('/\b(please|pls|thanks|thank you|thx)\b/i', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s, " ,.-");
    }
}
