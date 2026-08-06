<?php

namespace App\Services\Documents;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * v4.46 — the company stamp, on the documents customers ask to see it on.
 *
 * WHERE IT GOES, AND WHY THAT MATTERS
 * Three of these documents already reserve a box for exactly this and have done for months:
 * the receipt prints "Official stamp" over an empty rule, the quotation has a "Company Stamp &
 * Authorised Signature" box, the delivery note has "AUTHORIZED SIGNATORY". They were drawn for a
 * stamp that was never applied, so staff were printing and stamping by hand. This fills the box
 * that is already there rather than floating an image over the page — no overlap to tune, no
 * layout to re-measure, and it lands where the document already says it should.
 *
 * The invoice is the exception: it had no stamp area at all, and its default footer reads "this is
 * a computer-generated invoice and is valid without signature" — which is the very sentence
 * customers are pushing back on. It gets a box, and that sentence changes when a stamp is applied.
 *
 * DOMPDF CONSTRAINTS THIS RESPECTS
 * - The image must be a data URI. A remote URL means dompdf makes an HTTP call while rendering:
 *   slow, and silently blank when the box cannot reach itself (this host's APP_URL points at an
 *   internal hostname, which is exactly how such things fail).
 * - Styles are INLINE. Every one of these renderers keeps its CSS in its own <style> heredoc; a
 *   shared class name would mean editing five stylesheets and would break the first time somebody
 *   copied a renderer without it.
 * - No rotation. dompdf's transform support is unreliable, and a stamp that fails to rotate on one
 *   document and not another looks worse than one that never tries.
 */
final class DocumentStamp
{
    /** Documents that can carry the stamp. The key is also the settings suffix. */
    public const DOCS = ['invoice', 'receipt', 'quotation', 'delivery_note', 'credit_note'];

    /** Rendered data URIs, per tenant, per request — five documents must not re-read one file five times. */
    private static array $cache = [];

    /** Is a stamp set up at all? No image means no stamp anywhere, whatever the switches say. */
    public static function available(Tenant $t): bool
    {
        return trim((string) $t->setting('stamp_image', '')) !== '';
    }

    /**
     * Should THIS document carry it?
     *
     * The per-document switches default ON, because they only ever apply once an image has been
     * uploaded — so "upload your stamp" is the whole setup, and someone who wants it off just one
     * document can still say so.
     */
    public static function on(Tenant $t, string $doc): bool
    {
        $doc = self::docKey($doc);
        if ($doc === '' || ! self::available($t)) {
            return false;
        }

        return (bool) $t->setting('stamp_on_' . $doc, true);
    }

    /** Only the documents we know about; anything else is a typo and must not silently stamp. */
    public static function docKey(string $doc): string
    {
        $doc = strtolower(trim(str_replace('-', '_', $doc)));

        return in_array($doc, self::DOCS, true) ? $doc : '';
    }

    /** Printed size in points/px. Clamped so a bad number cannot blow the page apart. */
    public static function sizePx(Tenant $t): int
    {
        return max(60, min(260, (int) ($t->setting('stamp_size', 150) ?: 150)));
    }

    /**
     * The stamp on its own, no caption — for the boxes that already print their own label
     * ("Official stamp", "Company Stamp & Authorised Signature", "AUTHORIZED SIGNATORY").
     */
    public static function img(Tenant $t, string $doc, ?int $size = null): string
    {
        if (! self::on($t, $doc)) {
            return '';
        }
        $src = self::dataUri($t);
        if ($src === '') {
            return '';
        }
        $px = $size ?: self::sizePx($t);

        return '<img src="' . $src . '" alt="" style="width:' . $px . 'px;height:' . $px . 'px;">';
    }

    /**
     * The stamp with its own right-aligned box and caption — for documents that had no stamp area
     * (the invoice and the credit note).
     */
    public static function block(Tenant $t, string $doc, string $caption = 'For {business}'): string
    {
        $img = self::img($t, $doc);
        if ($img === '') {
            return '';
        }
        $cap = trim(str_replace('{business}', (string) $t->name, $caption));

        return '<table width="100%" style="margin-top:6px;"><tr><td style="text-align:right;">'
            . $img
            . ($cap !== '' ? '<div style="font-size:8.5px;color:#6b7280;margin-top:-6px;">' . e($cap) . '</div>' : '')
            . '</td></tr></table>';
    }

    /**
     * The stored image as a base64 data URI.
     *
     * Same resolution rule the invoice renderer already uses for the logo: a `/storage/...` URL is
     * read straight off the public disk rather than fetched over HTTP. Returns '' on anything it
     * cannot read, so a missing file prints no stamp instead of a broken-image box on a document
     * that goes to a customer.
     */
    public static function dataUri(Tenant $t): string
    {
        $key = (int) $t->id;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        self::$cache[$key] = '';

        $url = trim((string) $t->setting('stamp_image', ''));
        if ($url === '') {
            return '';
        }
        // Native str_starts_with, not Str::startsWith — identical behaviour for a string needle,
        // and it keeps this class loadable (and therefore testable) without the framework.
        if (str_starts_with($url, 'data:')) {
            return self::$cache[$key] = $url;
        }

        try {
            if (preg_match('#/storage/(.+)$#', $url, $m)) {
                $rel = $m[1];
                if (Storage::disk('public')->exists($rel)) {
                    $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'png'  => 'image/png',
                        'webp' => 'image/webp',
                        'gif'  => 'image/gif',
                        default => 'image/jpeg',
                    };

                    return self::$cache[$key] = 'data:' . $mime . ';base64,'
                        . base64_encode(Storage::disk('public')->get($rel));
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('DocumentStamp: could not read the stamp image — ' . $e->getMessage());
        }

        return '';
    }

    /** Test seam: the per-request cache must not leak between tenants in a queue worker. */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
