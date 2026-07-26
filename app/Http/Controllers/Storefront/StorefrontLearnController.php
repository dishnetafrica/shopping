<?php declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Advisor\KnowledgeLibrary;
use App\Services\Advisor\SolarArticles;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Public Solar Knowledge Center (customer-facing, no login) — the SEO engine.
 *
 * Server-rendered so crawlers get real HTML with a unique title, meta description and canonical per
 * page. The hub lists educational articles by category plus an FAQ (reused from the knowledge library),
 * and each article has its own URL. Every page funnels toward the 3-minute assessment.
 */
class StorefrontLearnController extends Controller
{
    private function shop(?string $shop): Tenant
    {
        $tenant = $shop ? Tenant::withoutGlobalScopes()->where('slug', $shop)->first() : null;
        if (! $tenant) {
            abort(404);
        }
        app(TenantContext::class)->set($tenant->id);
        return $tenant;
    }

    private function base(Tenant $t): string
    {
        return '/' . $t->slug;
    }

    /** GET /{shop}/learn — the Knowledge Center hub. */
    public function index(Request $r, ?string $shop = null)
    {
        $t = $this->shop($shop);
        $base = $this->base($t);

        $cards = '';
        foreach (SolarArticles::categories() as $cat) {
            $items = array_filter(SolarArticles::all(), fn ($a) => $a['category'] === $cat);
            $cards .= '<h2 class="cat">' . e($cat) . '</h2><div class="grid">';
            foreach ($items as $a) {
                $cards .= '<a class="card" href="' . e($base . '/learn/' . $a['slug']) . '">'
                    . '<div class="ct">' . e($a['title']) . '</div>'
                    . '<div class="cx">' . e($a['excerpt']) . '</div>'
                    . '<div class="cm">' . (int) $a['read'] . ' min read →</div></a>';
            }
            $cards .= '</div>';
        }

        $faqs = (new KnowledgeLibrary())->search('solar', ['type' => 'faq']);
        $faqHtml = '';
        foreach ($faqs as $f) {
            $faqHtml .= '<details><summary>' . e($f['title']) . '</summary><div class="fa">' . e($f['body']) . '</div></details>';
        }

        $inner = '<div class="hero"><h1>Solar, explained simply.</h1>'
            . '<p>Everything you need to understand solar for your home or business — then design your own system in 3 minutes.</p>'
            . '<a class="btn pri" href="' . e($base . '/solar') . '">Start my free assessment →</a></div>'
            . '<div class="wrap">' . $cards
            . ($faqHtml !== '' ? '<h2 class="cat">Frequently asked questions</h2><div class="faqs">' . $faqHtml . '</div>' : '')
            . '<div class="cta"><div><b>Ready to see your system?</b><div class="mut">Pick your appliances and get an instant recommendation.</div></div>'
            . '<a class="btn pri" href="' . e($base . '/solar') . '">Start my assessment →</a></div>'
            . '</div>';

        return $this->page(
            $t,
            'Solar Knowledge Center — ' . $t->name,
            'Learn how solar works, compare batteries and systems, and see if solar fits your home or business. Free guides from ' . $t->name . '.',
            $r->url(),
            $inner
        );
    }

    /** GET /{shop}/learn/{slug} — a single article. */
    public function article(Request $r, ?string $shop = null, string $slug = '')
    {
        $t = $this->shop($shop);
        $base = $this->base($t);
        $a = SolarArticles::get($slug);
        if (! $a) {
            abort(404);
        }

        $related = '';
        foreach ((array) ($a['related'] ?? []) as $rs) {
            $ra = SolarArticles::get($rs);
            if ($ra) {
                $related .= '<a class="card" href="' . e($base . '/learn/' . $ra['slug']) . '"><div class="ct">' . e($ra['title']) . '</div><div class="cm">' . (int) $ra['read'] . ' min read →</div></a>';
            }
        }

        $inner = '<div class="wrap article">'
            . '<a class="back" href="' . e($base . '/learn') . '">← Knowledge Center</a>'
            . '<div class="acat">' . e($a['category']) . ' · ' . (int) $a['read'] . ' min read</div>'
            . '<h1>' . e($a['title']) . '</h1>'
            . '<div class="body">' . $a['body'] . '</div>'
            . '<div class="cta"><div><b>See what this means for you</b><div class="mut">Get a system recommendation in 3 minutes — no jargon.</div></div>'
            . '<a class="btn pri" href="' . e($base . '/solar') . '">Start my assessment →</a></div>'
            . ($related !== '' ? '<h2 class="cat">Related reading</h2><div class="grid">' . $related . '</div>' : '')
            . '</div>';

        $extra = '<meta property="og:type" content="article"><meta property="og:title" content="' . e($a['title']) . '">'
            . '<meta property="og:description" content="' . e($a['meta']) . '">';

        return $this->page($t, $a['title'] . ' — ' . $t->name, (string) $a['meta'], $r->url(), $inner, $extra);
    }

    /** Shared server-rendered shell with SEO head + branding. */
    private function page(Tenant $t, string $title, string $metaDesc, string $canonical, string $inner, string $extraHead = '')
    {
        $base = $this->base($t);
        $brand = e((string) $t->name);
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title) . '</title>'
            . '<meta name="description" content="' . e($metaDesc) . '">'
            . '<link rel="canonical" href="' . e($canonical) . '">'
            . '<meta property="og:site_name" content="' . $brand . '">'
            . $extraHead
            . '<style>'
            . ':root{--amber:#f59e0b;--amber-d:#d97706;--ink:#1a1a1a;--mut:#6b7280;--line:#ececec;--bg:#fbfaf8}'
            . '*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);background:var(--bg);line-height:1.6}'
            . '.wrap{max-width:960px;margin:0 auto;padding:0 18px}'
            . '.top{background:#fff;border-bottom:1px solid var(--line)}.top .in{max-width:960px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;gap:14px}'
            . '.top .bn{font-weight:800}.top a{color:var(--ink);text-decoration:none;font-size:14px}.top .sp{margin-left:auto}'
            . '.top .go{background:var(--amber-d);color:#fff;padding:8px 14px;border-radius:10px;font-weight:700}'
            . '.hero{background:linear-gradient(135deg,#fff7ed,#fde68a);text-align:center;padding:50px 18px}'
            . '.hero h1{font-size:32px;margin:0 0 10px;letter-spacing:-.02em}.hero p{color:#7c5a1a;max-width:560px;margin:0 auto 20px;font-size:17px}'
            . '.btn{display:inline-block;border:0;border-radius:12px;padding:13px 22px;font-weight:700;text-decoration:none;cursor:pointer}'
            . '.btn.pri{background:var(--amber-d);color:#fff;box-shadow:0 4px 14px rgba(217,119,6,.3)}'
            . '.cat{font-size:20px;margin:28px 0 12px}'
            . '.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}'
            . '.card{display:block;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;text-decoration:none;color:inherit;transition:.15s}'
            . '.card:hover{border-color:#fcd34d;transform:translateY(-2px)}'
            . '.card .ct{font-weight:700;margin-bottom:4px}.card .cx{font-size:13.5px;color:var(--mut);margin-bottom:8px}.card .cm{font-size:12px;color:var(--amber-d);font-weight:700}'
            . '.faqs details{background:#fff;border:1px solid var(--line);border-radius:12px;padding:2px 16px;margin-bottom:8px}'
            . '.faqs summary{cursor:pointer;font-weight:600;padding:12px 0}.faqs .fa{color:var(--mut);padding-bottom:12px}'
            . '.article{max-width:720px}.article h1{font-size:30px;line-height:1.2;margin:6px 0 14px;letter-spacing:-.02em}'
            . '.article .acat{color:var(--amber-d);font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.05em;margin-top:22px}'
            . '.article .body h2{font-size:20px;margin:22px 0 8px}.article .body p,.article .body li{font-size:16px}.article .body ul{padding-left:20px}'
            . '.back{display:inline-block;margin-top:18px;color:var(--amber-d);text-decoration:none;font-weight:700;font-size:14px}'
            . '.cta{display:flex;flex-wrap:wrap;align-items:center;gap:14px;background:linear-gradient(135deg,#1f2937,#111827);color:#fff;border-radius:16px;padding:22px;margin:28px 0}'
            . '.cta .btn{margin-left:auto}.cta .mut{color:#cbd5e1;font-size:13px}.mut{color:var(--mut)}'
            . '.foot{text-align:center;color:var(--mut);font-size:12px;padding:30px 0 40px}'
            . '@media(max-width:600px){.hero h1{font-size:26px}.cta .btn{margin-left:0;width:100%;text-align:center}}'
            . '</style></head><body>'
            . '<div class="top"><div class="in"><span class="bn">' . $brand . '</span>'
            . '<a href="' . e($base . '/learn') . '">Learn</a>'
            . '<span class="sp"></span><a class="go" href="' . e($base . '/solar') . '">Start assessment →</a></div></div>'
            . $inner
            . '<div class="foot">' . $brand . ' — clean, reliable solar power.</div>'
            . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
