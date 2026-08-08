<?php declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * v4.65.7 — turning a parity report into a message worth waking someone for.
 *
 * PURE. A decoded `stock:reconcile --json` in, a message out or nothing. No models, no database, no
 * WhatsApp.
 *
 * ── the only thing this has to get right ───────────────────────────────────────────────────────
 *
 * A watchdog is judged on its false alarms, not its catches. Send "⚠️ your stock is wrong" on a
 * morning when the stock is fine and you have spent the credit you needed for the morning it isn't.
 * v4.65.5 split the report into two findings for exactly this reason and the watchdog never learned
 * the difference:
 *
 *   DRIFT AT HOME    the ledger disagrees with the product screen. Somebody's on-hand is wrong right
 *                    now, on a screen a person is reading. Worth a message today.
 *
 *   MISFILED STOCK   movements at a location the shop does not use. Nothing reads them, no screen is
 *                    affected, no number is wrong. Worth mentioning once; worth nagging about never.
 *
 * Before this, both produced the same "⚠️ stock drift" alert. A shop with 219,445 misfiled units and
 * perfect stock would have been told its numbers were broken every morning until somebody muted it —
 * and a muted watchdog is not a watchdog, it is a cron job burning battery.
 */
final class StockAlert
{
    /**
     * @param  array<string,mixed>  $json            decoded `stock:reconcile --json`
     * @param  bool                 $includeMisfiled report misfiled stock as well as home drift
     * @return array{urgent:bool,text:string}|null   null = say nothing
     */
    public static function from(array $json, bool $includeMisfiled = false): ?array
    {
        if (! empty($json['ok'])) {
            return null;                                  // parity — silence is the correct output
        }

        $tenantId  = (int) ($json['tenants'][0]['tenant_id'] ?? 0);
        $homeDrift = (bool) ($json['home_drift'] ?? false);
        $misfiled  = (float) ($json['misfiled_units'] ?? 0);

        [$drifting, $strays] = self::split($json);

        if ($homeDrift && $drifting) {
            // Misfiled stock is mentioned here whatever the flag says. Once somebody is already
            // reading a message about their stock, the second finding is context, not noise.
            $extra = $misfiled > 0.0
                ? "\n\nSeparately, " . self::num($misfiled) . ' unit(s) are filed at a location you do not use. '
                  . 'Not urgent — nothing reads them.'
                : '';

            return [
                'urgent' => true,
                'text'   => "⚠️ *Stock check — the numbers moved*\n"
                    . "Live stock and the stock ledger disagree:\n"
                    . implode("\n", $drifting)
                    . $extra
                    . "\n\nRun  stock:reconcile {$tenantId}  to see which products."
                    . "\n(Fixes: see DEPLOY-STOCK-LEDGER-ENABLE.txt)",
            ];
        }

        if ($misfiled > 0.0 && $strays) {
            if (! $includeMisfiled) {
                return null;                              // real, inert, and not worth a daily message
            }

            return [
                'urgent' => false,
                'text'   => "📦 *Stock filed in the wrong place*\n"
                    . self::num($misfiled) . " unit(s) sit at a location this shop does not use. "
                    . "Nothing reads them and no screen is affected — your on-hand numbers are correct.\n"
                    . implode("\n", $strays)
                    . "\n\nTo see what would be removed (writes nothing):"
                    . "\n  stock:clear-misfiled {$tenantId}",
            ];
        }

        // Not ok, but nothing this knows how to describe. Saying nothing beats saying something
        // shaped like an alarm with no content in it.
        return null;
    }

    /**
     * Sort the per-location summaries into the two findings.
     *
     * A location is a stray when the report says it had no legacy source to compare against — the
     * report's own word for it, not a guess made here from the location's name. `kampala` is a stray
     * on a Juba shop and the shop's whole warehouse on a Kampala one.
     *
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private static function split(array $json): array
    {
        $drifting = []; $strays = [];

        foreach (($json['tenants'] ?? []) as $tr) {
            foreach (($tr['locations'] ?? []) as $name => $s) {
                $n = (int) ($s['drift_products'] ?? 0);
                if ($n <= 0) {
                    continue;
                }
                $place = ucfirst(str_replace('-consignment', '', (string) $name));
                $units = self::num((float) ($s['total_abs_drift'] ?? 0));

                if (($s['source'] ?? '') === LegacySource::NONE) {
                    $strays[] = "• {$place}: {$n} product(s), {$units} unit(s)";
                } else {
                    $drifting[] = "• {$place}: {$n} product(s) off (total {$units})";
                }
            }
        }

        return [$drifting, $strays];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
