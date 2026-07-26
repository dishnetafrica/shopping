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

    /** GET /{shop}/how — the animated "How Solar Works" + comparisons page. */
    public function how(Request $r, ?string $shop = null)
    {
        $t = $this->shop($shop);
        $path = resource_path('storefront/how.html');
        $html = is_file($path) ? (string) file_get_contents($path) : '<h1>Unavailable</h1>';
        $html = str_replace(['__SHOP_NAME__', '__BASE__', '__CANON__'], [e((string) $t->name), e($this->base($t)), e($r->url())], $html);
        $html = str_replace('<title>How Solar Works</title>', '<title>How Solar Works — ' . e((string) $t->name) . '</title>', $html);
        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
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
            . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">'
            . '<style>'
            . ':root{--green:#1cb22a;--green-d:#159021;--green-l:#eaf8ec;--yellow:#dad674;--dark:#252525;--ink:#252525;--mut:#6d7278;--line:#e7e7e7;--bg:#ffffff;--soft:#f6f7f5}'
            . '*{box-sizing:border-box}body{margin:0;font-family:\'Roboto\',-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:var(--ink);background:var(--bg);line-height:1.6}'
            . 'h1,h2,h3,.cat,.hero h1,.card .ct{font-family:\'Montserrat\',sans-serif}'
            . '.wrap{max-width:1000px;margin:0 auto;padding:0 20px}'
            . '.top{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:40}.top .in{max-width:1000px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;gap:18px}'
            . '.top .bn{font-family:\'Montserrat\',sans-serif;font-weight:800;font-size:19px}.top .bn .dot{color:var(--green)}.top a{color:#444;text-decoration:none;font-size:14px;font-weight:600}.top a:hover{color:var(--green)}.top .sp{margin-left:auto}'
            . '.top .go{background:var(--green);color:#fff;padding:9px 16px;border-radius:8px;font-weight:700}'
            . '.hero{position:relative;color:#fff;text-align:center;padding:60px 18px;background:linear-gradient(180deg,rgba(37,37,37,.6),rgba(37,37,37,.72)),url(\'/img/solar/hero.jpg\') center/cover no-repeat,linear-gradient(135deg,#1cb22a,#0f7a1c)}'
            . '.hero h1{font-size:38px;margin:0 0 10px;letter-spacing:-.02em;font-weight:800}.hero p{color:rgba(255,255,255,.9);max-width:580px;margin:0 auto 22px;font-size:18px}'
            . '.btn{display:inline-block;border:0;border-radius:8px;padding:14px 26px;font-weight:700;text-decoration:none;cursor:pointer;font-family:\'Montserrat\',sans-serif}'
            . '.btn.pri{background:var(--green);color:#fff;box-shadow:0 8px 22px rgba(28,178,42,.3)}.btn.pri:hover{background:var(--green-d)}'
            . '.cat{font-size:22px;margin:34px 0 14px;font-weight:800}'
            . '.grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}'
            . '.card{display:block;background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;text-decoration:none;color:inherit;transition:.15s}'
            . '.card:hover{border-color:var(--green);transform:translateY(-3px);box-shadow:0 10px 24px rgba(0,0,0,.06)}'
            . '.card .ct{font-weight:700;margin-bottom:5px;font-size:16px}.card .cx{font-size:13.5px;color:var(--mut);margin-bottom:10px}.card .cm{font-size:12px;color:var(--green-d);font-weight:700}'
            . '.faqs details{background:#fff;border:1px solid var(--line);border-radius:12px;padding:2px 18px;margin-bottom:8px}'
            . '.faqs summary{cursor:pointer;font-weight:700;padding:14px 0;font-family:\'Montserrat\',sans-serif}.faqs .fa{color:var(--mut);padding-bottom:14px}'
            . '.article{max-width:740px}.article h1{font-size:34px;line-height:1.15;margin:8px 0 16px;letter-spacing:-.02em;font-weight:800}'
            . '.article .acat{color:var(--green);font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.08em;margin-top:24px}'
            . '.article .body h2{font-size:22px;margin:26px 0 10px}.article .body p,.article .body li{font-size:16px}.article .body ul{padding-left:20px}'
            . '.back{display:inline-block;margin-top:20px;color:var(--green-d);text-decoration:none;font-weight:700;font-size:14px}'
            . '.cta{display:flex;flex-wrap:wrap;align-items:center;gap:16px;background:var(--dark);color:#fff;border-radius:16px;padding:26px;margin:32px 0}'
            . '.cta .btn{margin-left:auto}.cta .mut{color:#c9cdc9;font-size:14px}.cta b{font-family:\'Montserrat\',sans-serif;font-size:18px}.mut{color:var(--mut)}'
            . '.footband{background:var(--dark);color:#cfd3cf}.footband .in{max-width:1000px;margin:0 auto;padding:34px 20px;display:flex;flex-wrap:wrap;gap:24px;align-items:center}'
            . '.footband .bn{font-family:\'Montserrat\',sans-serif;font-weight:800;font-size:20px;color:#fff}.footband .bn .dot{color:var(--green)}'
            . '.footband .lk{display:flex;gap:20px;margin-left:auto;flex-wrap:wrap}.footband .lk a{color:#cfd3cf;text-decoration:none;font-size:14px;font-weight:600}.footband .lk a:hover{color:#fff}'
            . '.foot{text-align:center;color:#8b908b;font-size:12px;padding:16px 0 30px;background:var(--dark)}'
            . '@media(max-width:600px){.hero h1{font-size:28px}.cta .btn{margin-left:0;width:100%;text-align:center}.footband .lk{margin-left:0}}'
            . '</style></head><body>'
            . '<div class="top"><div class="in"><span class="bn">' . $brand . '<span class="dot">.</span></span>'
            . '<a href="' . e($base . '/learn') . '">Learn</a>'
            . '<a href="' . e($base . '/how') . '">How it works</a>'
            . '<span class="sp"></span><a class="go" href="' . e($base . '/solar') . '">Start assessment →</a></div></div>'
            . $inner
            . '<div class="footband"><div class="in"><span class="bn">' . $brand . '<span class="dot">.</span></span>'
            . '<div class="lk"><a href="' . e($base . '/learn') . '">Learn</a><a href="' . e($base . '/how') . '">How solar works</a><a href="' . e($base . '/solar') . '">Start assessment</a></div></div></div>'
            . '<div class="foot">&copy; ' . $brand . ' — clean, reliable solar power.</div>'
            . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
