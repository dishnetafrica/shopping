<?php declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Advisor\ProjectAdvisorService;
use App\Services\Advisor\SolarAppliances;
use App\Services\Advisor\SolarPackages;
use App\Services\Advisor\SolarSizingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Public Solar Experience Center (customer-facing, no login).
 *
 * Serves a shop's guided solar assessment: the appliance-based calculator, a live deterministic
 * recommendation with a plain-language "why", a matching solution package with an indicative price, and
 * a lead capture that drops a sized project straight into the staff Advisor. Tenant is resolved by the
 * shop slug and pinned into context so the shared Advisor engine + packages work on this public route.
 */
class StorefrontSolarController extends Controller
{
    /** Goal cards → the segment + recommended package they map to. */
    public const GOALS = [
        ['key' => 'home',     'label' => 'Home',              'icon' => '🏠', 'segment' => 'Residential',     'package' => 'home_starter'],
        ['key' => 'business', 'label' => 'Business',          'icon' => '🏢', 'segment' => 'Commercial',      'package' => 'small_business'],
        ['key' => 'school',   'label' => 'School',            'icon' => '🏫', 'segment' => 'School',          'package' => 'school'],
        ['key' => 'hospital', 'label' => 'Hospital / Clinic', 'icon' => '🏥', 'segment' => 'Government',      'package' => 'health_centre'],
        ['key' => 'farm',     'label' => 'Farm',              'icon' => '🚜', 'segment' => 'Farm',            'package' => 'farm'],
        ['key' => 'water',    'label' => 'Water Pump',        'icon' => '💧', 'segment' => 'Water Pump',      'package' => 'borehole'],
        ['key' => 'street',   'label' => 'Street Lighting',   'icon' => '💡', 'segment' => 'Street Lighting', 'package' => 'street_light'],
        ['key' => 'factory',  'label' => 'Factory',           'icon' => '🏭', 'segment' => 'Industrial',      'package' => 'small_business'],
    ];

    /** Resolve the shop by slug, pin tenant context, or 404. */
    private function shop(?string $shop): Tenant
    {
        $tenant = $shop ? Tenant::withoutGlobalScopes()->where('slug', $shop)->first() : null;
        if (! $tenant) {
            abort(404);
        }
        app(TenantContext::class)->set($tenant->id);
        return $tenant;
    }

    private function goal(string $key): ?array
    {
        foreach (self::GOALS as $g) {
            if ($g['key'] === $key) {
                return $g;
            }
        }
        return null;
    }

    private function currency(Tenant $t): string
    {
        return (string) ($t->setting('currency', 'UGX') ?: 'UGX');
    }

    private function waNumber(Tenant $t): string
    {
        return preg_replace('/[^0-9]/', '', (string) ($t->whatsapp_number ?? '')) ?: '';
    }

    /** GET /{shop}/solar — the Experience Center page (static HTML with the shop config injected). */
    public function page(?string $shop = null)
    {
        $t = $this->shop($shop);
        $path = resource_path('storefront/solar.html');
        $html = is_file($path) ? (string) file_get_contents($path) : '<h1>Solar assessment unavailable</h1>';
        $html = str_replace('__SOLAR_CONFIG__', json_encode($this->configArray($t), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $html);
        $html = str_replace('<title>Solar</title>', '<title>' . e($t->name) . ' — Solar Assessment</title>', $html);
        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
    }

    /** GET /{shop}/solar/config — appliances, goals, and shop info for the page/tests. */
    public function config(?string $shop = null)
    {
        return response()->json(['ok' => true] + $this->configArray($this->shop($shop)));
    }

    private function configArray(Tenant $t): array
    {
        return [
            'shop'       => (string) $t->name,
            'base'       => '/' . $t->slug,
            'currency'   => $this->currency($t),
            'whatsapp'   => $this->waNumber($t),
            'goals'      => self::GOALS,
            'appliances' => SolarAppliances::all(),
        ];
    }

    /** POST /{shop}/solar/estimate — appliances → deterministic recommendation + why + package price. */
    public function estimate(Request $r, ?string $shop = null)
    {
        $t = $this->shop($shop);
        $goal = $this->goal((string) $r->input('customer_type', 'home')) ?? self::GOALS[0];
        $loads = SolarAppliances::toLoads((array) $r->input('appliances', []));
        $backup = (float) $r->input('backup_hours', 6);

        $sizing = (new SolarSizingService())->size([
            'loads' => $loads, 'backup_hours' => $backup, 'peak_sun_hours' => 5.0, 'expansion_pct' => 0.2,
        ]);
        $rr = $sizing['result'];

        $pkg = SolarPackages::get((string) $goal['package']);
        $priceFrom = $pkg ? $this->packagePrice($pkg) : null;

        return response()->json([
            'ok'     => true,
            'sizing' => $sizing,
            'why'    => $this->why($rr, $backup),
            'recommendation' => [
                'panels'       => $rr['panel_count'],
                'pv_w'         => $rr['pv_array_w'],
                'battery_wh'   => $rr['battery_bank_wh'],
                'battery_kwh'  => round(((float) $rr['battery_bank_wh']) / 1000, 1),
                'inverter_va'  => $rr['inverter_va'],
                'daily_kwh'    => $rr['daily_energy_kwh'],
                'peak_w'       => $rr['peak_demand_w'],
            ],
            'package' => $pkg ? [
                'key' => $pkg['key'], 'name' => $pkg['name'], 'summary' => $pkg['summary'],
                'price_from' => $priceFrom, 'currency' => $this->currency($t),
            ] : null,
        ]);
    }

    /** POST /{shop}/solar/lead — capture the assessment as a sized project in the staff Advisor. */
    public function lead(Request $r, ?string $shop = null)
    {
        $t = $this->shop($shop);
        $name  = trim((string) $r->input('name', ''));
        $phone = preg_replace('/[^0-9+]/', '', (string) $r->input('phone', ''));
        if ($phone === '') {
            return response()->json(['ok' => false, 'error' => 'phone_required'], 422);
        }
        $goal = $this->goal((string) $r->input('customer_type', 'home')) ?? self::GOALS[0];
        $loads = SolarAppliances::toLoads((array) $r->input('appliances', []));
        $backup = (float) $r->input('backup_hours', 6);

        $answers = [
            'customer_type'     => $goal['segment'],
            'project_objective' => 'Backup during outages',
            'backup_hours'      => $backup,
            'loads'             => $loads,
            'contact_name'      => $name,
            'contact_phone'     => $phone,
            'source'            => 'storefront-solar',
        ];
        $project = ProjectAdvisorService::make()->save($t, [
            'profile' => 'solar',
            'name'    => ($name !== '' ? $name : $goal['label'] . ' enquiry') . ' (web)',
            'status'  => 'draft',
            'answers' => $answers,
        ], null);

        $rr  = (array) ($project->sizing['result'] ?? []);
        $wa  = $this->waNumber($t);
        $msg = 'Hi! I just designed a solar system on your website'
            . (($rr['pv_array_w'] ?? 0) ? (' (~' . round((float) $rr['pv_array_w']) . ' W of panels, '
                . round(((float) ($rr['battery_bank_wh'] ?? 0)) / 1000, 1) . ' kWh battery)') : '')
            . '. My name is ' . ($name !== '' ? $name : 'a customer') . ' and I would like a full proposal.';
        $waLink = $wa !== '' ? ('https://wa.me/' . $wa . '?text=' . rawurlencode($msg)) : null;

        return response()->json(['ok' => true, 'project_id' => $project->id, 'whatsapp' => $waLink]);
    }

    /** Sum of a package's indicative equipment cost — the "from" price shown to customers. */
    private function packagePrice(array $pkg): float
    {
        $sum = 0.0;
        foreach ((array) ($pkg['equipment'] ?? []) as $e) {
            $sum += max(0.0, (float) ($e['unit_price'] ?? 0)) * max(0.0, (float) ($e['qty'] ?? 1));
        }
        return round($sum, 2);
    }

    /** Plain-language explanation of the recommendation (customers buy explanations). */
    private function why(array $r, float $backup): string
    {
        $daily   = (float) ($r['daily_energy_kwh'] ?? 0);
        $panels  = (int) ($r['panel_count'] ?? 0);
        $pv      = round((float) ($r['pv_array_w'] ?? 0));
        $battKwh = round(((float) ($r['battery_bank_wh'] ?? 0)) / 1000, 1);
        $inv     = round((float) ($r['inverter_va'] ?? 0));
        $peak    = round((float) ($r['peak_demand_w'] ?? 0));

        return 'Your appliances use about ' . rtrim(rtrim(number_format($daily, 1), '0'), '.') . ' kWh a day. '
            . 'We recommend ' . ($panels > 0 ? $panels . ' solar panel' . ($panels === 1 ? '' : 's') . ' (~' . number_format($pv) . ' W)' : 'a solar array')
            . ' — enough to cover that plus about 20% for cloudy days and future growth — '
            . 'a ' . $battKwh . ' kWh battery for roughly ' . rtrim(rtrim(number_format($backup, 1), '0'), '.') . ' hours of backup on your essential appliances, '
            . 'and a ' . number_format($inv) . ' VA inverter to handle your peak demand of about ' . number_format($peak) . ' W.';
    }
}
