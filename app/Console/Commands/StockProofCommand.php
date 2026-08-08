<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockProvenance as P;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v4.66 — how much of your stock can you prove?
 *
 * READ-ONLY. Writes nothing, changes nothing, safe on production at any hour.
 *
 * The owner's words: "what you show on the product page is not real stock. Only what we purchased
 * is real stock, against supplier." He is right, and `stock:reconcile` is structurally incapable of
 * noticing — parity proves the ledger equals the product screen, and both can agree perfectly on a
 * figure somebody typed once.
 *
 * This asks the other question. Every movement already carries a `ref_type` saying where it came
 * from, so every unit of on-hand can be traced to a supplier bill, a physical count, a sale, or
 * somebody typing. Nothing new is recorded; it is only read.
 *
 * The headline it exists to produce: what the on-hand would be if every typed unit were struck out.
 */
class StockProofCommand extends Command
{
    private const VERSION = 'v4.66';

    protected $signature = 'stock:proof
        {tenant : Tenant id}
        {--limit=25 : How many products to list}
        {--sort=exposure : exposure | typed | onhand — exposure = most money resting on a typed number}
        {--all : List every product, not just the ones with typed stock}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: where every unit of on-hand came from — supplier bill, count, or somebody typing.';

    public function handle(): int
    {
        $t = Tenant::where('id', (int) $this->argument('tenant'))->first();
        if (! $t) {
            $this->error('No such tenant.');
            return self::FAILURE;
        }

        $home  = StockLedger::home($t);
        $limit = max(1, (int) $this->option('limit'));

        $products = [];
        DB::table('products')->where('tenant_id', $t->id)->select('id', 'name', 'sku', 'cost', 'unit')
            ->orderBy('id')->chunkById(1000, function ($rows) use (&$products) {
                foreach ($rows as $p) {
                    $products[(int) $p->id] = [
                        'name' => (string) ($p->name ?? ''),
                        'unit' => (string) ($p->unit ?? ''),
                        'cost' => (float) ($p->cost ?? 0),
                    ];
                }
            });

        // One grouped query, not one per product: (product, ref_type) → Σ qty.
        $byProduct = [];
        DB::table('stock_movements')
            ->where('tenant_id', $t->id)->where('location', $home)->whereNotNull('product_id')
            ->select('product_id', 'ref_type', DB::raw('SUM(qty) as qty'))
            ->groupBy('product_id', 'ref_type')->get()
            ->each(function ($r) use (&$byProduct) {
                $byProduct[(int) $r->product_id][] = ['ref_type' => $r->ref_type, 'qty' => (float) $r->qty];
            });

        $rows = [];
        foreach ($byProduct as $pid => $movements) {
            $s = P::summarise($movements);
            $rows[] = [
                'product_id' => $pid,
                'name'       => $products[$pid]['name'] ?? ('#' . $pid),
                'unit'       => $products[$pid]['unit'] ?? '',
                'cost'       => $products[$pid]['cost'] ?? 0.0,
                'sums'       => $s,
                'provable'   => P::provable($s),
                'verdict'    => P::verdict($s),
                'exposure'   => P::exposure($s, $products[$pid]['cost'] ?? 0.0),
            ];
        }

        $totals = [
            'products' => count($rows),
            'on_hand'  => 0.0, 'supplier' => 0.0, 'counted' => 0.0, 'sales' => 0.0, 'typed' => 0.0,
            'provable' => 0.0, 'exposure' => 0.0,
            'would_zero' => 0, 'would_go_negative' => 0, 'counted_products' => 0,
        ];
        foreach ($rows as $r) {
            $totals['on_hand']  += $r['sums']['on_hand'];
            $totals['supplier'] += $r['sums'][P::SUPPLIER];
            $totals['counted']  += $r['sums'][P::COUNTED];
            $totals['sales']    += $r['sums'][P::SALES];
            $totals['typed']    += $r['sums'][P::TYPED];
            $totals['provable'] += $r['provable'];
            $totals['exposure'] += $r['exposure'];
            if ($r['verdict'] === 'counted') $totals['counted_products']++;
            if ($r['provable'] < -0.0005)    $totals['would_go_negative']++;
            elseif (abs($r['provable']) < 0.0005 && abs($r['sums']['on_hand']) > 0.0005) $totals['would_zero']++;
        }
        foreach (['on_hand', 'supplier', 'counted', 'sales', 'typed', 'provable', 'exposure'] as $k) {
            $totals[$k] = round($totals[$k], 3);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'version' => self::VERSION, 'tenant_id' => (int) $t->id, 'location' => $home,
                'totals' => $totals, 'products' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->render($t, $home, $rows, $totals, $limit);
        return self::SUCCESS;
    }

    private function render(Tenant $t, string $home, array $rows, array $totals, int $limit): void
    {
        $this->line('<fg=gray>stock:proof · ' . self::VERSION . ' · read-only</>');
        $this->line("<options=bold>Tenant #{$t->id} — " . ($t->name ?? '') . "</>");
        $this->line("  <fg=gray>{$home} · {$totals['products']} product(s) with movements</>");

        if (! $rows) {
            $this->newLine();
            $this->warn('No stock movements at all. Nothing to trace.');
            return;
        }

        // ── the headline ───────────────────────────────────────────────────────────────────────
        $share = $totals['on_hand'] != 0.0
            ? (int) round(100 * abs($totals['typed']) / abs($totals['on_hand'])) : 0;

        $this->newLine();
        $this->line('<options=bold>WHERE YOUR ON-HAND COMES FROM</>');
        $this->table(
            ['Kind', 'Units', 'What stands behind it'],
            [
                ['from supplier bills', $this->num($totals['supplier']), 'a purchase line or a received shipment'],
                ['physically counted',  $this->num($totals['counted']),  'somebody counted the shelf'],
                ['sold / returned',     $this->num($totals['sales']),    'an order that was delivered'],
                ['<fg=yellow>typed by hand</>', '<fg=yellow>' . $this->num($totals['typed']) . '</>',
                 '<fg=yellow>an opening balance, a new-product box, an edit, a CSV</>'],
                ['<options=bold>on hand now</>', '<options=bold>' . $this->num($totals['on_hand']) . '</>', ''],
            ]
        );

        $this->newLine();
        $this->line(sprintf('  <fg=%s>%d%% of your on-hand rests on a number somebody typed.</>',
            $share >= 50 ? 'yellow' : 'default', $share));
        $this->line(sprintf('  <options=bold>Strike out every typed unit and your stock becomes %s</> <fg=gray>(from %s).</>',
            $this->num($totals['provable']), $this->num($totals['on_hand'])));
        $this->line(sprintf('  <fg=gray>%d product(s) would read zero · %d would go negative · %d have been counted.</>',
            $totals['would_zero'], $totals['would_go_negative'], $totals['counted_products']));

        if ($totals['would_go_negative'] > 0) {
            $this->line('  <fg=gray>Negative is informative, not an error: more was sold than the bills account for,</>');
            $this->line('  <fg=gray>so goods were on the shelf that this system never saw arrive.</>');
        }

        // ── which products to look at ──────────────────────────────────────────────────────────
        $sort = (string) $this->option('sort');
        usort($rows, match ($sort) {
            'typed'  => fn ($a, $b) => abs($b['sums'][P::TYPED]) <=> abs($a['sums'][P::TYPED]),
            'onhand' => fn ($a, $b) => $b['sums']['on_hand'] <=> $a['sums']['on_hand'],
            default  => fn ($a, $b) => $b['exposure'] <=> $a['exposure'],
        });
        if (! $this->option('all')) {
            $rows = array_values(array_filter($rows, fn ($r) => abs($r['sums'][P::TYPED]) > 0.0005));
        }

        $this->newLine();
        $this->line('<options=bold>WORTH COUNTING FIRST</> <fg=gray>— most money resting on a typed number</>');
        $this->table(
            ['Product', 'On hand', 'Bills', 'Counted', 'Sold', 'Typed', 'Provable', 'Verdict', 'At risk @cost'],
            array_map(fn ($r) => [
                mb_strlen($r['name']) > 30 ? mb_substr($r['name'], 0, 29) . '…' : $r['name'],
                $this->num($r['sums']['on_hand']),
                $this->num($r['sums'][P::SUPPLIER]),
                $this->num($r['sums'][P::COUNTED]),
                $this->num($r['sums'][P::SALES]),
                $this->num($r['sums'][P::TYPED]),
                $this->num($r['provable']),
                $r['verdict'],
                number_format($r['exposure']),
            ], array_slice($rows, 0, $limit))
        );
        if (count($rows) > $limit) {
            $this->line(sprintf('  <fg=gray>…and %d more (raise --limit, or --json for all).</>', count($rows) - $limit));
        }

        // ── what to do about it ────────────────────────────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>WHAT THIS MEANS</>');
        $this->line('  A typed figure is not necessarily wrong. It is just not evidence, and nothing in this');
        $this->line('  system can make it evidence — only counting the shelf can.');
        $this->line('');
        $this->line('  Counting the top few by "at risk" converts the most money for the least work. A count');
        $this->line('  through Trust → blind count writes a movement this report will show as <options=bold>counted</>,');
        $this->line('  and that product stops being a question for good.');
        $this->line('');
        $this->line('  <fg=gray>This command wrote nothing. Run it as often as you like.</>');
    }

    private function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 3, '.', ','), '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }
}
