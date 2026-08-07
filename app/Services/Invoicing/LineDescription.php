<?php

namespace App\Services\Invoicing;

/**
 * v4.52 — ONE definition of "what this charge was for", shared by the statement on screen and the
 * statement in print.
 *
 * v4.51 put descriptions on the V3 Account statement tab and nowhere else, so the PDF a customer
 * actually receives still read as a column of bare invoice numbers. Two surfaces, two definitions,
 * and the printed one — the only one the customer ever sees — was the one left behind. They now
 * share this class, so the only way to change what a statement line says is to change it here, for
 * both at once.
 *
 * Pure by design: no models, no container, no database, no tenant. It reads plain properties off
 * whatever it is handed, which is what lets the harness assert it without a framework.
 */
final class LineDescription
{
    /** How long a description may run on screen before it starts wrapping the column. */
    public const DETAIL_MAX = 72;

    /** Tighter in print: the PDF's document column is narrow and the text sits under a number. */
    public const PRINT_MAX = 60;

    /**
     * What was bought, with no document number attached — "Aluminium Foil ×5, Cling Film ×5".
     *
     * The invoice's own title wins when somebody wrote one; otherwise the goods are named from the
     * lines in the order they were billed, with a count for the rest. Returns '' when there is
     * genuinely nothing to say, so callers print nothing rather than a stranded separator.
     */
    public static function goods($i): string
    {
        $title = trim((string) ($i->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        try {
            $names = [];
            foreach ($i->items as $it) {
                $n = trim((string) ($it->name ?? ''));
                if ($n === '') {
                    continue;
                }
                $q = (float) ($it->qty ?? 0);
                $names[] = $q > 1 ? ($n . ' ×' . rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.')) : $n;
            }
            if ($names) {
                $shown = array_slice($names, 0, 2);
                $rest  = count($names) - count($shown);

                return implode(', ', $shown) . ($rest > 0 ? ' +' . $rest . ' more' : '');
            }
        } catch (\Throwable $e) {
            // A description is never worth an error page — and never worth a failed statement send.
        }

        return trim((string) ($i->order_no ?? ''));   // last resort: the order it came from
    }

    /**
     * The screen's version: number and goods in one cell, because the on-screen statement has no
     * separate number column to put the reference in.
     */
    public static function forInvoice($i, int $max = self::DETAIL_MAX): string
    {
        $base = 'Invoice ' . ($i->invoice_no ?: '');
        $what = self::goods($i);

        return $what === '' ? $base : self::clip($base . ' · ' . $what, $max);
    }

    /** The print version: goods only — in the PDF the invoice number is already the row's label. */
    public static function forPrint($i, int $max = self::PRINT_MAX): string
    {
        return self::clip(self::goods($i), $max);
    }

    /**
     * A payment line says how it arrived, its reference, and what it was set against — the three
     * things a customer checks when they think a payment has gone missing.
     */
    public static function forPayment($p, int $max = self::DETAIL_MAX): string
    {
        $bits = ['Payment · ' . self::methodWords((string) ($p->method ?? ''))];
        $ref = trim((string) ($p->reference ?? ''));
        if ($ref !== '') {
            $bits[] = 'ref ' . $ref;
        }
        $against = trim((string) ($p->invoice_no ?? '')) ?: trim((string) ($p->order_no ?? ''));
        if ($against !== '') {
            $bits[] = 'against ' . $against;
        }

        return self::clip(implode(' · ', $bits), $max);
    }

    /** Stored codes are for us; the customer reading this has never seen the word "momo". */
    public static function methodWords(string $m): string
    {
        $m = strtolower(trim($m));
        if ($m === '') {
            return 'cash';
        }

        return match (true) {
            str_contains($m, 'momo') || str_contains($m, 'mobile') => 'Mobile Money',
            str_contains($m, 'bank')   => 'bank transfer',
            str_contains($m, 'cash')   => 'cash',
            str_contains($m, 'cheque') || str_contains($m, 'check') => 'cheque',
            str_contains($m, 'card')   => 'card',
            default => $m,
        };
    }

    /** Trim on a word boundary — a description cut mid-word reads like a bug, not a limit. */
    public static function clip(string $s, int $max = self::DETAIL_MAX): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($max < 8 || mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max - 1);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > $max * 0.6) {
            $cut = mb_substr($cut, 0, $sp);
        }

        return rtrim($cut, " ,·") . '…';
    }
}
