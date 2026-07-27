#!/usr/bin/env python3
"""
Bajaj Genuine Parts — static site generator.

Assembles HTML from:
  * design-system.json  (tokens + component specs)  -> the "design" layer
  * data/*.json         (models, systems, products)  -> the "data" layer

The point of the project: pages are ASSEMBLED from one system, never hand-drawn
page by page. Change a token in design-system.json (or a value in a data file)
and every page re-renders consistently.

Usage:  python3 build/generate.py
Output: dist/index.html  (self-contained, previewable via file://)
"""
import json, os, pathlib, html, base64, sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
DIST = ROOT / "dist"

# Active tenant config (auto_parts vertical). Set in build(); read by renderers so
# the storefront is a pure function of the tenant's brand/theme/market/catalogue.
TENANT = {}


def apply_tenant(ds, tenant):
    """Fold tenant brand/theme/market into the design tokens so one engine themes
    any auto-parts tenant. Mirrors CloudBSS theme_accent / currency tenant settings."""
    th = tenant.get("theme", {})
    if th.get("accent"):
        ds["color"]["brand"]["blue"] = th["accent"]
        ds["color"]["text"]["link"] = th["accent"]
        ds["color"]["state"]["info"] = th["accent"]
        ds["color"]["badge"]["popular"]["bg"] = th["accent"]
        ds["color"]["badge"]["new"]["fg"] = th.get("accentDark", th["accent"])
    if th.get("accentDark"):
        ds["color"]["brand"]["blueDark"] = th["accentDark"]
    if th.get("secondary"):
        ds["color"]["brand"]["accent"] = th["secondary"]
    cur = tenant.get("market", {}).get("currency")
    if cur:
        ds["meta"]["currency"] = cur
    return ds

# When True, referenced images are inlined as base64 data URIs so each page is a
# single self-contained file (previews anywhere). Set EMBED_IMAGES=0 in the env to
# emit real asset paths instead (small HTML that loads images from ../assets when
# the page is opened inside the repo). For the Laravel port, use the path mode too.
EMBED_IMAGES = os.environ.get("EMBED_IMAGES", "1") != "0"
# Pages are written into dist/, assets live at the repo root, so path-mode <img>
# needs to climb one level.
WEB_PREFIX = "" if EMBED_IMAGES else "../"


def data_uri(relpath):
    p = ROOT / relpath
    if not p.exists():
        return None
    mime = "image/svg+xml" if p.suffix == ".svg" else "image/jpeg"
    return f"data:{mime};base64," + base64.b64encode(p.read_bytes()).decode()


def img(relpath, alt="", cls="", style="", attrs=""):
    """<img> from an asset path; embeds as data URI when EMBED_IMAGES, else src=path.
    Falls back to a neutral placeholder box if the file is missing."""
    if EMBED_IMAGES:
        src = data_uri(relpath)
    else:
        src = (WEB_PREFIX + relpath) if (ROOT / relpath).exists() else None
    if src is None:
        return f'<div class="imgph {cls}" style="{style}" aria-label="{html.escape(alt)}"></div>'
    st = f' style="{style}"' if style else ""
    return f'<img class="{cls}" src="{src}" alt="{html.escape(alt)}" loading="lazy"{st} {attrs}>'


# --- v3 portal image-upload layer (mirrors Family Shoppers image_url) -----------
try:
    _MEDIA = json.loads((ROOT / "data/media.json").read_text(encoding="utf-8")).get("uploads", {})
except Exception:
    _MEDIA = {}


def img_slot(slot_key, default_asset, alt="", cls="", style="", attrs=""):
    """Resolve an image UPLOAD SLOT the way the v3 portal does:
       uploads[slot_key] (a portal-uploaded url) overrides the bundled default asset.
    An uploaded url is used verbatim (CDN or /storage/...); the bundled default is
    embedded so the standalone preview still renders."""
    url = _MEDIA.get(slot_key)
    a = (attrs + f' data-slot="{html.escape(slot_key)}"').strip()
    if url:
        remote = url.startswith(("http://", "https://", "//", "/storage"))
        if not remote:
            # portal-uploaded local file -> embed (or ../path) exactly like any asset
            return img(url, alt, cls, style, a)
        # remote CDN or app-relative /storage path written by the portal -> verbatim
        st = f' style="{style}"' if style else ""
        return (f'<img class="{cls}" src="{html.escape(url)}" alt="{html.escape(alt)}" '
                f'loading="lazy" {a}{st}>')
    return img(default_asset, alt, cls, style, a)


# ----------------------------------------------------------------------------- IO
def load(p):
    with open(ROOT / p, encoding="utf-8") as f:
        return json.load(f)


def load_models():
    models = {}
    for fp in sorted((ROOT / "data/models").glob("*.json")):
        m = load(f"data/models/{fp.name}")
        models[m["id"]] = m
    return models


# ------------------------------------------------------------------- tokens -> css
def css_vars(ds):
    lines = []
    for group, kv in ds["color"].items():
        for k, v in kv.items():
            if isinstance(v, dict):
                for kk, vv in v.items():
                    lines.append(f"--c-{group}-{k}-{kk}:{vv};")
            else:
                lines.append(f"--c-{group}-{k}:{v};")
    for k, v in ds["space"].items():
        lines.append(f"--space-{k}:{v};")
    for k, v in ds["radius"].items():
        lines.append(f"--radius-{k}:{v};")
    for k, v in ds["shadow"].items():
        lines.append(f"--shadow-{k}:{v};")
    for k, v in ds["typography"]["scale"].items():
        lines.append(f"--fs-{k}:{v['size']};")
        lines.append(f"--lh-{k}:{v['line']};")
        lines.append(f"--fw-{k}:{v['weight']};")
    lines.append(f"--font-sans:{ds['typography']['fontFamily']['sans']};")
    lines.append(f"--font-mono:{ds['typography']['fontFamily']['mono']};")
    lines.append(f"--maxw:{ds['layout']['maxWidth']};")
    lines.append(f"--gutter:{ds['layout']['gutter']};")
    lines.append(f"--header-h:{ds['layout']['headerHeight']};")
    return "\n      ".join(lines)


# ------------------------------------------------------------------------- icons
ICONS = {
    "search":       '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
    "chevron-right":'<path d="M9 6l6 6-6 6"/>',
    "arrow-right":  '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
    "cart":         '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.4 12.4a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L22 7H6"/>',
    "user":         '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
    "shield-check": '<path d="M12 3l7 3v6c0 4.6-3 7.7-7 9-4-1.3-7-4.4-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
    "diagram":      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><path d="M10 6.5h4a3 3 0 0 1 3 3V14"/>',
    "ai":           '<rect x="4" y="6" width="16" height="12" rx="3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><path d="M12 3v3M8 18v2M16 18v2"/>',
    "truck":        '<rect x="1" y="6" width="13" height="10" rx="1"/><path d="M14 9h4l3 3v4h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/>',
    "headset":      '<path d="M4 13v-1a8 8 0 0 1 16 0v1"/><rect x="2.5" y="13" width="4" height="6" rx="1.4"/><rect x="17.5" y="13" width="4" height="6" rx="1.4"/><path d="M20 19a4 4 0 0 1-4 3h-2"/>',
    "engine":       '<path d="M4 10h3l2-2h4l2 2h2v3h2v4h-2v2h-4l-2 2H7v-3H4z"/><path d="M9 8V5h4v3"/>',
    "fuel":         '<rect x="4" y="3" width="10" height="18" rx="2"/><path d="M14 8h3a2 2 0 0 1 2 2v6a1.5 1.5 0 0 1-3 0v-3h-2"/><path d="M7 7h4"/>',
    "electrical":   '<path d="M13 2L5 13h6l-2 9 8-11h-6z"/>',
    "transmission": '<circle cx="8" cy="8" r="3"/><circle cx="17" cy="16" r="3"/><path d="M8 11v3a2 2 0 0 0 2 2h4"/>',
    "brake":        '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3"/>',
    "suspension":   '<path d="M8 3v4M8 21v-4"/><path d="M8 7c3 0 3 2 0 2s-3 2 0 2 3 2 0 2 3 2 0 2"/><path d="M16 3v18"/><circle cx="16" cy="6" r="1.4"/><circle cx="16" cy="18" r="1.4"/>',
    "frame":        '<path d="M3 18l5-11h5l-4 9h8l-2 2z"/><circle cx="6" cy="18" r="1.3"/>',
    "controls":     '<path d="M4 9h16"/><circle cx="4" cy="9" r="2"/><circle cx="20" cy="9" r="2"/><path d="M12 9v6"/><circle cx="12" cy="17" r="1.4"/>',
    "tyre":         '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/>',
    "power":        '<path d="M13 2L5 13h6l-2 9 8-11h-6z"/>',
    "speed":        '<path d="M4 15a8 8 0 0 1 16 0"/><path d="M12 15l4-4"/><circle cx="12" cy="15" r="1.3"/>',
    "carrier":      '<rect x="4" y="7" width="16" height="10" rx="1.5"/><path d="M4 11h16M9 7v10"/>',
    "star":         '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.8 6.6 20l1-6.1L3.2 9.5l6.1-.9z"/>',
    "chat":         '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
    "diamond":      '<path d="M12 3l9 9-9 9-9-9z"/>',
}

def icon(name, size=20, cls="icn", stroke="1.75"):
    body = ICONS.get(name, ICONS["diamond"])
    return (f'<svg class="{cls}" width="{size}" height="{size}" viewBox="0 0 24 24" '
            f'fill="none" stroke="currentColor" stroke-width="{stroke}" '
            f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{body}</svg>')


# ------------------------------------------------------- motorcycle illustration
def motorcycle(color="#B22222", uid="m", frame="#20293a"):
    """Clean side-profile commuter-bike illustration used as premium image
    placeholder until real studio photography drops into the asset paths."""
    g = f"grad-{uid}"
    rim = f"rim-{uid}"
    return f'''<svg class="moto" viewBox="0 0 440 250" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Motorcycle illustration">
  <defs>
    <linearGradient id="{g}" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="{color}" stop-opacity="1"/>
      <stop offset="1" stop-color="{color}" stop-opacity="0.82"/>
    </linearGradient>
    <radialGradient id="{rim}" cx="0.5" cy="0.5" r="0.5">
      <stop offset="0" stop-color="#f2f4f8"/><stop offset="1" stop-color="#c4ccd8"/>
    </radialGradient>
  </defs>
  <ellipse cx="230" cy="228" rx="180" ry="12" fill="#0c1220" opacity="0.07"/>
  <!-- wheels -->
  <g stroke="{frame}" stroke-width="2" fill="none">
    <g>
      <circle cx="108" cy="182" r="50" fill="#161c28"/>
      <circle cx="108" cy="182" r="49" fill="none" stroke="#2c3648" stroke-width="6"/>
      <circle cx="108" cy="182" r="34" fill="url(#{rim})"/>
      <circle cx="108" cy="182" r="8" fill="#8a94a6"/>
      {''.join(f'<line x1="108" y1="182" x2="{108+30*__import__("math").cos(a)}" y2="{182+30*__import__("math").sin(a)}" stroke="#aeb8cc" stroke-width="2"/>' for a in [i*0.5236 for i in range(12)])}
    </g>
    <g>
      <circle cx="336" cy="182" r="50" fill="#161c28"/>
      <circle cx="336" cy="182" r="49" fill="none" stroke="#2c3648" stroke-width="6"/>
      <circle cx="336" cy="182" r="34" fill="url(#{rim})"/>
      <circle cx="336" cy="182" r="8" fill="#8a94a6"/>
      {''.join(f'<line x1="336" y1="182" x2="{336+30*__import__("math").cos(a)}" y2="{182+30*__import__("math").sin(a)}" stroke="#aeb8cc" stroke-width="2"/>' for a in [i*0.5236 for i in range(12)])}
    </g>
  </g>
  <!-- exhaust -->
  <path d="M150 178 C 190 196, 250 200, 300 196 L 322 194" stroke="#c4ccd8" stroke-width="9" fill="none" stroke-linecap="round"/>
  <rect x="150" y="168" width="70" height="18" rx="9" fill="#d7dde6"/>
  <!-- engine block -->
  <g fill="{frame}">
    <path d="M170 150 h44 a8 8 0 0 1 8 8 v20 a10 10 0 0 1-10 10 h-40 a8 8 0 0 1-8-8 v-22 a8 8 0 0 1 6-16 z"/>
  </g>
  <g stroke="#3a465c" stroke-width="2"><path d="M176 150v34M184 150v34M192 150v34M200 150v34"/></g>
  <!-- rear fork + shock -->
  <path d="M120 176 L 196 168" stroke="{frame}" stroke-width="6" stroke-linecap="round"/>
  <path d="M150 130 L 118 176" stroke="{color}" stroke-width="6" stroke-linecap="round"/>
  <!-- rear carrier rack -->
  <path d="M60 118 h74 M64 118 l-2 10 M130 118 l2 10 M60 118 a4 4 0 0 0 0 8 h72" stroke="{frame}" stroke-width="4" fill="none" stroke-linecap="round"/>
  <!-- seat -->
  <path d="M96 128 C 96 118, 108 116, 130 116 L 176 116 C 186 116, 190 120, 188 128 L 96 128 Z" fill="#1a1f2b"/>
  <!-- side panel -->
  <path d="M132 130 L 176 130 L 168 152 L 138 152 Z" fill="url(#{g})"/>
  <!-- fuel tank -->
  <path d="M176 118 C 196 108, 236 106, 258 112 C 268 116, 268 132, 256 138 C 232 146, 196 146, 178 138 C 172 134, 172 122, 176 118 Z" fill="url(#{g})"/>
  <path d="M186 116 C 206 110, 234 110, 250 116" stroke="#ffffff" stroke-width="2.5" opacity="0.35" fill="none" stroke-linecap="round"/>
  <!-- frame top tube -->
  <path d="M188 130 L 258 122" stroke="{frame}" stroke-width="5" stroke-linecap="round"/>
  <path d="M258 122 L 300 108" stroke="{frame}" stroke-width="5" stroke-linecap="round"/>
  <!-- front fork -->
  <path d="M300 104 L 336 176" stroke="#c4ccd8" stroke-width="7" stroke-linecap="round"/>
  <path d="M308 100 L 340 172" stroke="{frame}" stroke-width="4" stroke-linecap="round"/>
  <!-- front mudguard -->
  <path d="M306 150 A 44 44 0 0 1 372 168" stroke="#c4ccd8" stroke-width="7" fill="none" stroke-linecap="round"/>
  <!-- handlebar + headlight -->
  <path d="M296 104 L 322 88" stroke="{frame}" stroke-width="5" stroke-linecap="round"/>
  <path d="M322 88 l 18 -4" stroke="{frame}" stroke-width="5" stroke-linecap="round"/>
  <circle cx="312" cy="106" r="12" fill="#f2f4f8" stroke="{frame}" stroke-width="3"/>
  <circle cx="312" cy="106" r="5" fill="#F5B301"/>
  <!-- foot peg -->
  <path d="M196 190 l 16 6" stroke="{frame}" stroke-width="4" stroke-linecap="round"/>
</svg>'''


# ----------------------------------------------------------------- fmt helpers
def money(amount, ds):
    c = ds["meta"]["currency"]
    n = f"{amount:,}"
    return f"{c['symbol']} {n}"


def esc(s):
    return html.escape(str(s))


# --------------------------------------------------------------- section render
def badge_chip(kind, ds, label=None):
    b = ds["color"]["badge"].get(kind, {"fg": "#333", "bg": "#eee"})
    label = label or kind.capitalize()
    return f'<span class="chip" style="color:{b["fg"]};background:{b["bg"]}">{esc(label)}</span>'


def render_navbar(ds, logo_svg):
    links = "".join(f'<a href="#">{esc(l)}</a>' for l in ds["components"]["navbar"]["links"])
    return f'''<header class="nav">
    <div class="nav-inner wrap">
      <a class="brand" href="#">{logo_svg}</a>
      <nav class="nav-links">{links}</nav>
      <div class="nav-search">
        {icon("search",18)}
        <input placeholder="{esc(ds['components']['navbar']['search']['placeholder'])}" aria-label="Search parts"/>
      </div>
      <div class="nav-actions">
        <button class="flag" aria-label="Country">{TENANT.get("market",{}).get("flag","🇺🇬")} <span>{esc(TENANT.get("market",{}).get("code","UG"))}</span></button>
        <button class="iconbtn" aria-label="Account">{icon("user",20)}</button>
        <button class="iconbtn cart" aria-label="Cart">{icon("cart",20)}<span class="cart-dot">0</span></button>
      </div>
    </div>
  </header>'''


def render_hero(ds, hero_art):
    popular = ["Cylinder Head", "Clutch Plate", "Air Filter", "Brake Shoe"]
    chips = "".join(f'<a href="#" class="pop-chip">{esc(p)}</a>' for p in popular)
    hero = TENANT.get("hero", {})
    headline = esc(hero.get("headline", "Find. Understand.\nBuy. Keep Riding.")).replace("\n", "<br>")
    sub = esc(hero.get("sub", "Genuine parts, diagrams, manuals and AI support — all in one place."))
    return f'''<section class="hero">
    <div class="hero-grid wrap">
      <div class="hero-copy">
        <h1>{headline}</h1>
        <p>{sub}</p>
        <div class="hero-search">
          {icon("search",22)}
          <input placeholder="Search by part number, name or keyword..." aria-label="Search"/>
          <button class="btn btn-primary">Search</button>
        </div>
        <div class="pop-row"><span>Popular searches:</span>{chips}</div>
      </div>
      <div class="hero-art">{hero_art}</div>
    </div>
  </section>'''


def render_trustbar(ds):
    items = ds["components"]["trustBar"]["items"]
    cells = "".join(
        f'''<div class="trust-item">
          <span class="trust-ic">{icon(it["icon"],22)}</span>
          <div><strong>{esc(it["title"])}</strong><span>{esc(it["sub"])}</span></div>
        </div>''' for it in items)
    return f'<section class="wrap trustbar">{cells}</section>'


def render_models(ds, models):
    order = [m for m in TENANT.get("models", list(models)) if m in models] or list(models)
    cards = []
    for i, mid in enumerate(order):
        m = models[mid]
        badges = "".join(badge_chip(b, ds) for b in m.get("badges", []))
        photo = img_slot(f"{mid}:hero", f"assets/motorcycles/{mid}/hero.jpg", m["name"], "moto-photo")
        cards.append(f'''<a class="moto-card" href="#">
        <div class="moto-card-img">{badges}{photo}</div>
        <div class="moto-card-body">
          <h3>{esc(m["name"])}</h3>
          <p>{esc(m["tagline"])}</p>
          <span class="ghost-cta">Explore Motorcycle {icon("chevron-right",16)}</span>
        </div>
      </a>''')
    return f'''<section class="wrap block">
    <div class="block-head">
      <div><h2>Which motorcycle do you own?</h2>
      <p class="muted">Select your model to explore parts, diagrams and more.</p></div>
      <a href="#" class="link">View all models {icon("chevron-right",16)}</a>
    </div>
    <div class="moto-grid">{''.join(cards)}</div>
  </section>'''


def system_grid(ds, model, systems, order):
    """Reusable Browse-by-System grid for a given model."""
    sysmap = {s["id"]: s for s in systems["systems"]}
    counts = {s["id"]: s["partCount"] for s in model["systems"]}
    cards = []
    for sid in order:
        s = sysmap[sid]
        cover = img_slot(f"system:{sid}", s["cover"], s["name"], "sys-bg")
        cards.append(f'''<a class="sys-card" href="#">
        {cover}
        <div class="sys-content">
          <span class="sys-ic">{icon(s["icon"],22)}</span>
          <div class="sys-meta"><strong>{esc(s["name"])}</strong><span>{counts.get(sid,0)} Parts</span></div>
        </div>
      </a>''')
    return f'<div class="sys-grid">{"".join(cards)}</div>'


def render_systems(ds, models, systems):
    boxer = models["boxer100"]
    order = ["engine", "fuel_system", "electrical", "transmission",
             "brakes", "suspension", "frame_body", "controls"]
    return f'''<section class="wrap block">
    <div class="block-head">
      <div><h2>Browse by System</h2>
      <p class="muted">Jump straight into the exploded diagrams for the {esc(boxer["name"])}.</p></div>
      <a href="#" class="link">View all systems {icon("chevron-right",16)}</a>
    </div>
    {system_grid(ds, boxer, systems, order)}
  </section>'''


def render_popular_parts(ds, product):
    price = money(product["price"]["amount"], ds)
    badges = "".join(badge_chip(b, ds, {"genuine":"Genuine","bestseller":"Best Seller"}.get(b)) for b in product["badges"])
    def card(name, partno, amt, pid, imgpath, seller=False):
        b = badge_chip("bestseller", ds, "Best Seller") if seller else badge_chip("genuine", ds, "Genuine")
        return f'''<div class="prod-card">
        <div class="prod-img">{img_slot(f"product:{pid}:main", imgpath, name, "prod-photo")}</div>
        <div class="prod-body">
          <div class="prod-badges">{b}</div>
          <h4>{esc(name)}</h4>
          <span class="mono">{esc(partno)}</span>
          <div class="prod-foot"><strong>{esc(amt)}</strong><span class="stock ok">In Stock</span></div>
          <button class="btn btn-primary btn-sm">Add to Cart</button>
        </div>
      </div>'''
    cards  = card(product["name"], product["partNo"], price, "clutch_plate", "assets/products/clutch_plate/main.jpg", True)
    cards += card("Air Filter Element", "JB 5610 07", money(9500, ds),  "air_filter", "assets/products/air_filter/main.jpg")
    cards += card("Spark Plug", "DP 0410 11", money(8000, ds),          "spark_plug", "assets/products/spark_plug/main.jpg", True)
    cards += card("Front Brake Assembly", "JB 5510 30", money(28000, ds),"front_brake","assets/products/front_brake/main.jpg")
    cards += card("Silencer Assembly", "JD 5910 02", money(62000, ds),  "exhaust",    "assets/products/exhaust/main.jpg")
    return f'''<section class="wrap block">
    <div class="block-head">
      <div><h2>Popular Parts</h2>
      <p class="muted">Fast-moving genuine parts for the Boxer family.</p></div>
      <a href="#" class="link">View all parts {icon("chevron-right",16)}</a>
    </div>
    <div class="prod-grid">{cards}</div>
  </section>'''


def render_ai(ds):
    return f'''<section class="wrap">
    <div class="ai-band">
      <div class="ai-copy">
        <span class="overline">AI VEHICLE ASSISTANT</span>
        <h2>Not sure which part you need?</h2>
        <p>Describe the symptom — "my bike is hard to start", "clicking noise when braking" — and the assistant diagnoses the likely cause, shows it on the exploded diagram, and links the exact genuine part.</p>
        <div class="ai-ctas">
          <button class="btn btn-accent">{icon("ai",18)} Ask the AI Assistant</button>
          <button class="btn btn-whatsapp">{icon("chat",18)} Chat on WhatsApp</button>
        </div>
      </div>
      <div class="ai-chat">
        <div class="ai-chat-head">{icon("ai",18)} AI Assistant</div>
        <div class="ai-msg bot">Hi! I'm your Bajaj AI Assistant. How can I help you today?</div>
        <div class="ai-msg user">My bike is hard to start in the morning.</div>
        <div class="ai-msg bot">That's often one of these. Tap to inspect the part:
          <div class="ai-sugg"><span>Weak battery</span><span>Faulty spark plug</span><span>Clogged air filter</span></div>
        </div>
        <div class="ai-input">{icon("chat",16)}<input placeholder="Type your message..."><button class="btn btn-primary btn-sm">Send</button></div>
      </div>
    </div>
  </section>'''


def render_footer(ds, models):
    cols = {
        "Motorcycles": [models[m]["name"] for m in ["boxer100","boxer125hd","boxer150","v15"]],
        "Parts & Systems": ["Engine", "Fuel System", "Electrical", "Brakes"],
        "Support": ["AI Assistant", "Manuals & Downloads", "Compatibility", "Find a Dealer"],
        "Company": ["About Bajaj", "Genuine Parts Guarantee", "Warranty", "Contact"],
    }
    colhtml = ""
    for title, items in cols.items():
        li = "".join(f'<a href="#">{esc(x)}</a>' for x in items)
        colhtml += f'<div class="foot-col"><h5>{esc(title)}</h5>{li}</div>'
    b = TENANT.get("brand", {})
    mkt = TENANT.get("market", {})
    return f'''<footer class="footer">
    <div class="wrap footer-grid">
      <div class="foot-brand">
        <strong>{esc(b.get("name","BAJAJ"))}</strong><span>{esc(b.get("wordmark","GENUINE PARTS"))}</span>
        <p class="muted">Genuine parts, diagrams, manuals and AI support — trusted across {esc(mkt.get("country","Uganda"))}.</p>
        <div class="foot-guarantee">{icon("shield-check",18)} 1 Year Warranty on all genuine parts</div>
      </div>
      {colhtml}
    </div>
    <div class="wrap foot-bar"><span>© 2026 {esc(b.get("name","EA Auto Part"))} · {esc(mkt.get("country","East Africa"))}</span><span>Prices in {esc(TENANT.get("market",{}).get("currency",{}).get("code","UGX"))} · Genuine Bajaj spares</span></div>
  </footer>'''


# --------------------------------------------------------------- overview page
def stars(rating):
    full = int(round(rating["value"]))
    s = "".join(icon("star", 15, "star " + ("on" if i < full else "off")) for i in range(5))
    return f'<span class="stars">{s}</span><span class="rate-num">{rating["value"]} ({rating["count"]} reviews)</span>'


def gallery(ds, model):
    mid = model["id"]
    g = model["gallery"]
    shots = list(g.get("angles", [])) + list(g.get("details", []))
    main_default = g.get("angles", [{}])[0].get("asset", g.get("hero"))
    main_slot = g.get("angles", [{}])[0].get("id", "hero")
    main = img_slot(f"{mid}:{main_slot}", main_default, model["name"], "gal-main-img", attrs='id="galMain"')
    thumbs = ""
    for i, sh in enumerate(shots):
        t = img_slot(f"{mid}:{sh['id']}", sh["asset"], sh["label"], "gal-thumb-img")
        thumbs += (f'<button class="gal-thumb{" active" if i==0 else ""}" '
                   f'onclick="setMain(this)" title="{esc(sh["label"])}">{t}</button>')
    return f'''<div class="gallery">
      <div class="gal-thumbs">{thumbs}</div>
      <div class="gal-main">{main}<span class="gal-360">{icon("diagram",15)} 360° View</span></div>
    </div>'''


def keyspec_row(model):
    cells = ""
    for k in model["keySpecs"]:
        desc = k["label"] + (f' · {k["sub"]}' if k.get("sub") else "")
        cells += (f'<div class="ks"><span class="ks-ic">{icon(k.get("icon","diamond"),20)}</span>'
                  f'<div><strong>{esc(k["value"])}</strong><em>{esc(desc)}</em></div></div>')
    return f'<div class="keyspecs">{cells}</div>'


def spec_table(model):
    blocks = ""
    for group, rows in model["specifications"].items():
        trs = "".join(f'<tr><td>{esc(k)}</td><td>{esc(v)}</td></tr>' for k, v in rows.items())
        blocks += f'<div class="spec-group"><h4>{esc(group)}</h4><table>{trs}</table></div>'
    return f'<div class="spec-wrap">{blocks}</div>'


def overview_sidebar(ds, model):
    specs = model["specifications"]
    dw = specs.get("Dimensions & Weight", {})
    perf = specs.get("Performance", {})
    info = [
        ("Mileage", perf.get("Mileage", "—")),
        ("Fuel Capacity", dw.get("Fuel Tank Capacity", "—")),
        ("Kerb Weight", dw.get("Kerb Weight", "—")),
        ("Top Speed", perf.get("Top Speed", model["keySpecs"][-1].get("value", "—"))),
    ]
    rows = "".join(f'<div class="vi-row"><span>{esc(k)}</span><strong>{esc(v)}</strong></div>' for k, v in info)
    common = [
        ("Clutch Plate Assembly", "assets/products/clutch_plate/main.jpg", "clutch_plate"),
        ("Air Filter Element", "assets/products/air_filter/main.jpg", "air_filter"),
        ("Spark Plug", "assets/products/spark_plug/main.jpg", "spark_plug"),
    ]
    ci = "".join(f'<a class="cm-part" href="boxer100-part.html">'
                 f'{img_slot(f"product:{pid}:main", ip, nm, "cm-img")}<span>{esc(nm)}</span></a>'
                 for nm, ip, pid in common)
    return f'''<aside class="ov-side">
      <div class="card vi-card">
        <h4>Vehicle Info</h4>
        {rows}
      </div>
      <div class="card intel-card">
        <div class="intel-head"><h4>Vehicle Intelligence</h4><span class="chip on">Active</span></div>
        <div class="intel-odo"><span>Odometer</span><strong>18,450 km</strong></div>
        <div class="intel-next"><span>Next Service</span><strong>1,550 km / 45 days</strong>
          <div class="bar"><i style="width:72%"></i></div></div>
        <p class="cm-title">Common at this mileage</p>
        <div class="cm-grid">{ci}</div>
      </div>
      <div class="card help-card">
        <strong>Need a hand?</strong>
        <p>Our experts help you find the exact genuine part.</p>
        <button class="btn btn-whatsapp">{icon("chat",16)} Chat on WhatsApp</button>
      </div>
    </aside>'''


def render_overview(ds, model, systems, logo_svg):
    tabs = ["Overview", "360° Explorer", "Systems & Parts", "Diagrams",
            "Specifications", f'Reviews ({model["rating"]["count"]})', "Manuals"]
    tabhtml = "".join(f'<a href="#" class="tab{" active" if i==0 else ""}">{esc(t)}</a>'
                      for i, t in enumerate(tabs))
    badges = "".join(badge_chip(b, ds) for b in model.get("badges", []))
    order = ["engine", "fuel_system", "electrical", "transmission",
             "brakes", "suspension", "frame_body", "controls"]
    return "\n".join([
        render_navbar(ds, logo_svg),
        f'''<div class="wrap breadcrumb"><a href="index.html">{icon("chevron-right",14,"flip")} Back to all motorcycles</a></div>''',
        f'''<section class="wrap ov-hero">
          <div class="ov-info">
            <h1 class="ov-name">{esc(model["name"])} {badges}</h1>
            <p class="ov-tag">{esc(model["tagline"])}</p>
            <div class="ratingline">{stars(model["rating"])}</div>
            {keyspec_row(model)}
            <p class="ov-summary">{esc(model["summary"])}</p>
            <div class="ov-ctas">
              <a class="btn btn-primary btn-lg" href="boxer100-parts.html">{icon("diagram",18)} Explore Parts &amp; Diagrams</a>
              <a class="btn btn-secondary btn-lg" href="#">{icon("ai",18)} 360° Explorer</a>
            </div>
          </div>
          <div class="ov-gallery">{gallery(ds, model)}</div>
        </section>''',
        f'<div class="wrap"><nav class="tabs">{tabhtml}</nav></div>',
        f'''<section class="wrap ov-body">
          <div class="ov-main">
            <div class="block-head compact"><div><h2>Browse by System</h2>
              <p class="muted">Select a system to view parts, diagrams and components.</p></div>
              <a href="boxer100-parts.html" class="link">View all systems {icon("chevron-right",16)}</a></div>
            {system_grid(ds, model, systems, order)}
            <div class="block-head compact" style="margin-top:36px"><div><h2>Specifications</h2>
              <p class="muted">Full technical specification for the {esc(model["name"])}.</p></div></div>
            {spec_table(model)}
          </div>
          {overview_sidebar(ds, model)}
        </section>''',
        render_popular_parts(ds, load("data/products/clutch_plate.json")),
        render_footer(ds, {m: model for m in [model["id"]]} | load_models()),
    ])


# ------------------------------------------------- parts catalogue page
def render_parts_page(ds, model, systems, logo_svg):
    sysmap = {s["id"]: s for s in systems["systems"]}
    counts = {s["id"]: s["partCount"] for s in model["systems"]}
    order = [s["id"] for s in model["systems"]]
    cards = ""
    for sid in order:
        s = sysmap[sid]
        cover = img_slot(f"system:{sid}", s["cover"], s["name"], "cat-bg")
        href = "boxer100-diagram.html" if sid == "engine" else "#"
        cards += f'''<a class="cat-card" href="{href}">
          <div class="cat-img">{cover}</div>
          <div class="cat-body"><strong>{esc(s["name"])}</strong><span>{counts.get(sid,0)} Parts</span></div>
        </a>'''
    return "\n".join([
        render_navbar(ds, logo_svg),
        f'''<div class="wrap breadcrumb"><a href="boxer100.html">{icon("chevron-right",14,"flip")} {esc(model["name"])}</a>
            <span class="crumb-sep">/</span><span class="crumb-here">Parts</span></div>''',
        f'''<section class="wrap block">
          <div class="parts-head">
            <div><h1>Parts for {esc(model["name"])}</h1>
            <p class="muted">Browse genuine parts by system, or search by part number.</p></div>
            <div class="parts-search">{icon("search",18)}<input placeholder="Search parts by name or number..."></div>
          </div>
          <div class="cat-grid">{cards}</div>
          <div class="parts-cta">
            <div class="pc-card"><span class="pc-ic">{icon("ai",22)}</span>
              <div><strong>Can't find the part?</strong><p>Ask the AI Assistant or send us the part number — we'll help you find the right fit.</p>
              <button class="btn btn-primary btn-sm">{icon("chat",16)} Start Chat</button></div></div>
            <div class="pc-card"><span class="pc-ic ok">{icon("shield-check",22)}</span>
              <div><strong>Genuine Parts Guarantee</strong><p>100% original Bajaj parts. 1 Year Warranty on all parts.</p></div></div>
          </div>
        </section>''',
        render_footer(ds, load_models()),
    ])


# ------------------------------------------------- exploded diagram page
def render_diagram_page(ds, model, diagrams, systems, logo_svg):
    sysmap = {s["id"]: s for s in systems["systems"]}
    plates = diagrams["plates"]
    # left nav: engine plates (real) + other systems as headers
    navitems = ""
    for i, pl in enumerate(plates):
        navitems += (f'<button class="pl-item{" active" if i==0 else ""}" onclick="showPlate({i})">'
                     f'{esc(pl["name"])}</button>')
    other = ""
    for s in systems["systems"]:
        if s["id"] == "engine":
            continue
        other += f'<div class="pl-sys">{icon(s["icon"],16)} {esc(s["name"])}</div>'
    # panels
    panels = ""
    for i, pl in enumerate(plates):
        rows = ""
        for p in pl["parts"]:
            rmk = f'<span class="rmk">{esc(p["remarks"])}</span>' if p.get("remarks") else ""
            addbtn = f'<button class="mini-add" title="Add to cart">{icon("cart", 15)}</button>'
            rows += (f'<tr><td class="c-no">{p["no"]}</td><td class="mono">{esc(p["partNo"])}</td>'
                     f'<td>{esc(p["description"])}{rmk}</td>'
                     f'<td class="c-qty">{p["qty"]["KS"]}</td><td class="c-price">{money(p["price"], ds)}</td>'
                     f'<td class="c-act">{addbtn}</td></tr>')
        dia = img_slot(f"{model['id']}:diagram:{pl['plate']}", pl["diagram"], pl["name"], "dia-img")
        panels += f'''<div class="dia-panel{" active" if i==0 else ""}" id="plate-{i}">
          <div class="dia-left">
            <div class="dia-frame">{dia}<span class="dia-plate">Plate {pl['plate']}</span></div>
            <div class="dia-actions"><button class="btn btn-secondary btn-sm">{icon("diagram",16)} Download Diagram</button>
              <button class="btn btn-secondary btn-sm">{icon("arrow-right",16)} Share</button></div>
          </div>
          <div class="dia-right">
            <div class="dia-title"><h3>Engine › {esc(pl['name'])}</h3><span class="mono">Plate {pl['plate']} · Page {pl['page']}</span></div>
            <table class="plist"><thead><tr><th>No.</th><th>Part No.</th><th>Description</th><th>Qty</th><th>Price</th><th></th></tr></thead>
            <tbody>{rows}</tbody></table>
          </div>
        </div>'''
    return "\n".join([
        render_navbar(ds, logo_svg),
        f'''<div class="wrap breadcrumb"><a href="boxer100-parts.html">{icon("chevron-right",14,"flip")} Parts</a>
            <span class="crumb-sep">/</span><span class="crumb-here">Diagrams · Engine</span></div>''',
        f'''<section class="wrap dia-wrap">
          <aside class="dia-nav">
            <h4>Engine</h4>
            <div class="pl-list">{navitems}</div>
            <h4 class="pl-more">Other systems</h4>
            {other}
          </aside>
          <div class="dia-stage">{panels}</div>
        </section>''',
        render_footer(ds, load_models()),
    ])


# ------------------------------------------------- part detail page
def render_part_page(ds, product, models, compat, logo_svg):
    price = money(product["price"]["amount"], ds)
    badges = "".join(badge_chip(b, ds, {"genuine": "Genuine Part", "bestseller": "Best Seller"}.get(b))
                     for b in product["badges"])
    imgs = product["images"]
    main = img_slot(f"product:{product['id']}:{imgs[0]['id']}", imgs[0]["asset"], product["name"], "pd-main-img", attrs='id="pdMain"')
    pid = product["id"]
    thumbs = "".join(f'<button class="pd-thumb{" active" if i==0 else ""}" onclick="setMain(this)" title="{esc(im["label"])}">'
                     f'{img_slot("product:" + pid + ":" + im["id"], im["asset"], im["label"], "pd-thumb-img")}</button>'
                     for i, im in enumerate(imgs))
    compat_chips = "".join(f'<span class="fit-chip">{icon("shield-check",13)} {esc(models[m]["name"])}</span>'
                           for m in product["compatibleModels"] if m in models)
    attrs = "".join(f'<div class="attr"><span>{esc(k)}</span><strong>{esc(v)}</strong></div>'
                    for k, v in product["attributes"].items())
    # compatibility cards
    yr = compat.get("modelYearRanges", {})
    fitcards = "".join(f'''<a class="fit-card" href="boxer100.html">
        {img_slot(f"{m}:hero", f"assets/motorcycles/{m}/hero.jpg", models[m]["name"], "fit-img")}
        <strong>{esc(models[m]["name"])}</strong><span>{esc(yr.get(m,""))}</span></a>'''
        for m in product["compatibleModels"] if m in models)
    # also bought
    ab_names = {"clutch_center": "Clutch Center", "clutch_spring": "Clutch Spring",
                "clutch_housing": "Clutch Housing", "clutch_gear": "Clutch Gear"}
    ab_price = {"clutch_center": 26000, "clutch_spring": 3800, "clutch_housing": 35000, "clutch_gear": 15800}
    ab_imgs = ["assets/products/clutch_plate/installed.jpg", "assets/products/clutch_plate/main.jpg",
               "assets/products/clutch_plate/packaging.jpg", "assets/products/clutch_plate/installed.jpg"]
    also = ""
    for i, x in enumerate(product.get("alsoBought", [])):
        photo = img_slot(f"product:{x}:main", ab_imgs[i % len(ab_imgs)], ab_names.get(x, x), "ab-photo")
        also += (f'<div class="ab-card"><div class="ab-img">{photo}</div>'
                 f'<strong>{esc(ab_names.get(x,x))}</strong><span>{money(ab_price.get(x,0), ds)}</span></div>')
    return "\n".join([
        render_navbar(ds, logo_svg),
        f'''<div class="wrap breadcrumb"><a href="boxer100-parts.html">{icon("chevron-right",14,"flip")} Parts</a>
            <span class="crumb-sep">/</span><span class="crumb-here">{esc(product["name"])}</span></div>''',
        f'''<section class="wrap pd-wrap">
          <div class="pd-gallery">
            <div class="pd-thumbs">{thumbs}</div>
            <div class="pd-main">{main}</div>
          </div>
          <div class="pd-info">
            <div class="pd-badges">{badges}</div>
            <h1>{esc(product["name"])}</h1>
            <span class="mono pd-partno">Part No: {esc(product["partNo"])}</span>
            <div class="pd-price">{price}</div>
            <div class="pd-stock"><span class="stock ok">{icon("shield-check",14)} In Stock ({product["stock"]["qty"]})</span></div>
            <p class="pd-desc">{esc(product["shortDescription"])}</p>
            <div class="pd-buy">
              <div class="qty"><button onclick="stepQty(-1)">−</button><input id="qty" value="1" readonly><button onclick="stepQty(1)">+</button></div>
              <button class="btn btn-primary btn-lg">{icon("cart",18)} Add to Cart</button>
              <button class="btn btn-secondary btn-lg">♡ Wishlist</button>
            </div>
            <div class="fit-box"><h4>Compatible With</h4><div class="fit-chips">{compat_chips}</div></div>
            <div class="attr-box"><h4>Product Details</h4><div class="attr-grid">{attrs}</div></div>
          </div>
        </section>''',
        f'''<section class="wrap block">
          <div class="block-head compact"><div><h2>This part is compatible with</h2>
            <p class="muted">Fitment resolved from the shared OEM part number across model catalogues.</p></div>
            <a href="#" class="link">View all compatible models {icon("chevron-right",16)}</a></div>
          <div class="fit-grid">{fitcards}</div>
        </section>''',
        f'''<section class="wrap block">
          <div class="block-head compact"><div><h2>Customers also bought</h2></div></div>
          <div class="ab-grid">{also}</div>
        </section>''',
        render_footer(ds, load_models()),
    ])


# ------------------------------------------------- 360 explorer page
def render_explorer(ds, model, explorer, systems, logo_svg):
    sysmap = {s["id"]: s for s in systems["systems"]}
    hrefmap = {h["system"]: h["href"] for h in explorer["hotspots"]}
    # frame sources (resolve portal overrides, then embed for preview)
    def frame_src(fr):
        key = f'{model["id"]}:{fr["id"]}'
        path = _MEDIA.get(key) or fr["asset"]
        return data_uri(path) or ""
    frames_js = "[" + ",".join('"' + frame_src(f) + '"' for f in explorer["frames"]) + "]"
    first = data_uri(explorer["frames"][0]["asset"]) or ""
    # sidebar system list
    side = ""
    for s in systems["systems"]:
        href = hrefmap.get(s["id"], "boxer100-parts.html")
        side += (f'<a class="ex-sys" href="{href}">{icon(s["icon"],18)}'
                 f'<span>{esc(s["name"])}</span>{icon("chevron-right",14,"ex-chev")}</a>')
    # hotspots
    spots = ""
    for h in explorer["hotspots"]:
        spots += (f'<a class="hotspot" href="{h["href"]}" style="left:{h["x"]}%;top:{h["y"]}%" '
                  f'title="{esc(h["label"])}"><span class="hs-dot"></span>'
                  f'<span class="hs-tip">{esc(h["label"])} · view parts</span></a>')
    return "\n".join([
        render_navbar(ds, logo_svg),
        f'''<div class="wrap breadcrumb"><a href="boxer100.html">{icon("chevron-right",14,"flip")} {esc(model["name"])}</a>
            <span class="crumb-sep">/</span><span class="crumb-here">360° Explorer</span></div>''',
        f'''<section class="wrap ex-wrap">
          <aside class="ex-nav">
            <h4>{esc(model["name"])}</h4><p class="muted ex-sub">360° Explorer — pick a system or a hotspot.</p>
            {side}
          </aside>
          <div class="ex-stage">
            <div class="ex-toolbar">
              <span class="ex-hint">{icon("ai",15)} Drag to rotate · click a hotspot</span>
              <div class="ex-tools">
                <button class="ex-btn" onclick="expZoom(0.15)">＋</button>
                <button class="ex-btn" onclick="expZoom(-0.15)">－</button>
                <button class="ex-btn" onclick="expReset()">{icon("arrow-right",14)} Reset</button>
              </div>
            </div>
            <div class="ex-viewer" id="exViewer">
              <div class="ex-canvas" id="exCanvas">
                <img id="exFrame" class="ex-frame" src="{first}" alt="{esc(model["name"])} 360 view" draggable="false">
                <div class="ex-spots" id="exSpots">{spots}</div>
              </div>
            </div>
            <div class="ex-dots" id="exDots"></div>
          </div>
        </section>''',
        f'<script>window.__EXFRAMES__={frames_js};</script>',
        render_footer(ds, load_models()),
    ])


# ------------------------------------------------- AI visual-diagnosis page
def render_ai_page(ds, model, tb, logo_svg):
    chips = "".join(f'<button class="dx-chip" onclick="showDx(\'{p["id"]}\')">{esc(p["label"])}</button>'
                    for p in tb["problems"])
    blocks = ""
    for i, p in enumerate(tb["problems"]):
        cards = ""
        for c in p["causes"]:
            part_href = "boxer100-part.html" if c.get("part") == "clutch_plate" else "boxer100-parts.html"
            thumb = img_slot(f'aidx:{p["id"]}:{c["system"]}', c["img"], c["name"], "dx-thumb")
            cards += f'''<div class="dx-card">
              <div class="dx-cimg">{thumb}</div>
              <div class="dx-cbody">
                <div class="dx-crow"><strong>{esc(c["name"])}</strong><span class="dx-prob">{c["probability"]}%</span></div>
                <div class="dx-bar"><i style="width:{c["probability"]}%"></i></div>
                <span class="dx-sys">{esc(c["partName"])} · {esc(money(c["price"], ds))}</span>
                <div class="dx-acts">
                  <a class="btn btn-primary btn-sm" href="{part_href}">{icon("cart",14)} View Part</a>
                  <a class="btn btn-secondary btn-sm" href="boxer100-diagram.html">{icon("diagram",14)} Show on Diagram</a>
                </div>
              </div>
            </div>'''
        steps = "".join(f'<li>{esc(s)}</li>' for s in p["steps"])
        show = " active" if i == 0 else ""
        blocks += f'''<div class="dx-block{show}" id="dx-{p["id"]}">
          <div class="msg user">{esc(p["query"])}</div>
          <div class="msg bot"><p>{esc(p["intro"])}</p><div class="dx-cards">{cards}</div>
            <div class="dx-steps"><strong>Suggested next steps</strong><ol>{steps}</ol></div>
            <p class="dx-note">{esc(tb["meta"]["disclaimer"])}</p>
          </div>
        </div>'''
    return "\n".join([
        render_navbar(ds, logo_svg),
        f'''<div class="wrap breadcrumb"><a href="boxer100.html">{icon("chevron-right",14,"flip")} {esc(model["name"])}</a>
            <span class="crumb-sep">/</span><span class="crumb-here">AI Assistant</span></div>''',
        f'''<section class="wrap ai-page">
          <div class="ai-col">
            <div class="ai-titlebar"><span class="ai-avatar">{icon("ai",20)}</span>
              <div><strong>{esc(TENANT.get("brand",{}).get("name","EA Auto Part"))} Assistant</strong><span class="ai-status">● Online · {esc(model["name"])}</span></div></div>
            <div class="msg bot">{esc(tb["greeting"])}</div>
            <div class="dx-chips"><span class="dx-chips-lbl">Common issues:</span>{chips}</div>
            <div class="dx-stream">{blocks}</div>
            <div class="ai-inputbar">{icon("chat",16)}<input placeholder="Describe the problem in your own words..."><button class="btn btn-primary btn-sm">Send</button></div>
          </div>
        </section>''',
        render_footer(ds, load_models()),
    ])


# ------------------------------------------------------------------------- page
def build(tenant_path="data/tenant.json", out_dir=None):
    global TENANT
    ds = load("design-system.json")
    TENANT = load(tenant_path) if (ROOT / tenant_path).exists() else {}
    apply_tenant(ds, TENANT)
    out = pathlib.Path(out_dir) if out_dir else DIST

    models = load_models()
    systems = load("data/systems/systems.json")
    product = load("data/products/clutch_plate.json")

    brand = TENANT.get("brand", {})
    bname = esc(brand.get("name", "BAJAJ"))
    bword = esc(brand.get("wordmark", "GENUINE PARTS"))
    logo_svg = f'<span class="logo-emblem">{motorcycle_emblem()}</span><span class="logo-word"><b>{bname}</b><i>{bword}</i></span>'
    hero_art = img_slot("boxer100:lifestyle_rural", "assets/motorcycles/boxer100/lifestyle_rural.jpg",
                        "Bajaj Boxer 100 KS", "hero-photo")

    body = "\n".join([
        render_navbar(ds, logo_svg),
        render_hero(ds, hero_art),
        render_trustbar(ds),
        render_models(ds, models),
        render_systems(ds, models, systems),
        render_popular_parts(ds, product),
        render_ai(ds),
        render_footer(ds, models),
    ])

    out.mkdir(parents=True, exist_ok=True)
    page = HTML_SHELL.format(vars=css_vars(ds), css=PAGE_CSS, body=body)
    (out / "index.html").write_text(page, encoding="utf-8")
    print(f"[{TENANT.get('id','tenant')}] Wrote {out/'index.html'}  ({len(page):,} bytes)")

    diagrams = load("data/diagrams/boxer100.json")
    compat = load("data/compatibility/compatibility.json")
    explorer = load("data/explorer/boxer100.json")
    tb = load("data/ai/troubleshooting.json")
    boxer = models["boxer100"]

    pages = {
        "boxer100.html":         render_overview(ds, boxer, systems, logo_svg),
        "boxer100-parts.html":   render_parts_page(ds, boxer, systems, logo_svg),
        "boxer100-diagram.html": render_diagram_page(ds, boxer, diagrams, systems, logo_svg),
        "boxer100-part.html":    render_part_page(ds, product, models, compat, logo_svg),
        "boxer100-360.html":     render_explorer(ds, boxer, explorer, systems, logo_svg),
        "boxer100-ai.html":      render_ai_page(ds, boxer, tb, logo_svg),
    }
    only = os.environ.get("ONLY_PAGES")
    for fn, bd in pages.items():
        if only and fn not in only.split(","):
            continue
        pg = HTML_SHELL.format(vars=css_vars(ds), css=PAGE_CSS, body=bd)
        (out / fn).write_text(pg, encoding="utf-8")
        print(f"[{TENANT.get('id','tenant')}] Wrote {out/fn}  ({len(pg):,} bytes)")
    print(f"Models rendered: {', '.join(render_models_order(models))}")


def render_models_order(models):
    order = [m for m in TENANT.get("models", list(models)) if m in models] or list(models)
    return [models[k]["name"] for k in order]


def motorcycle_emblem():
    th = TENANT.get("theme", {})
    accent = th.get("accent", "#1552CC")
    second = th.get("secondary", "#F5B301")
    return (f'<svg width="34" height="34" viewBox="0 0 40 40" aria-hidden="true">'
            f'<rect x="2" y="4" width="36" height="32" rx="9" fill="{accent}"/>'
            f'<path d="M11 12 L20 20 L11 28 L15 28 L24 20 L15 12 Z" fill="#ffffff"/>'
            f'<path d="M18 12 L27 20 L18 28 L22 28 L31 20 L22 12 Z" fill="{second}"/></svg>')


HTML_SHELL = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bajaj Genuine Parts — Find. Understand. Buy. Keep Riding.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
:root {{
      {vars}
}}
{css}
</style>
</head>
<body>
{body}
<script>
function setMain(btn){{
  var im=btn.querySelector('img'); if(!im)return;
  var main=document.getElementById('galMain')||document.getElementById('pdMain');
  if(main) main.src=im.src;
  var sib=btn.parentNode.children;
  for(var i=0;i<sib.length;i++) sib[i].classList.remove('active');
  btn.classList.add('active');
}}
function showPlate(i){{
  document.querySelectorAll('.dia-panel').forEach(function(p){{p.classList.remove('active');}});
  document.querySelectorAll('.pl-item').forEach(function(b){{b.classList.remove('active');}});
  var p=document.getElementById('plate-'+i); if(p)p.classList.add('active');
  var btns=document.querySelectorAll('.pl-item'); if(btns[i])btns[i].classList.add('active');
}}
function stepQty(d){{
  var q=document.getElementById('qty'); if(!q)return;
  q.value=Math.max(1,(parseInt(q.value)||1)+d);
}}
/* --- AI diagnosis --- */
function showDx(id){{
  document.querySelectorAll('.dx-block').forEach(function(b){{b.classList.remove('active');}});
  var el=document.getElementById('dx-'+id); if(el){{el.classList.add('active');el.scrollIntoView({{behavior:'smooth',block:'nearest'}});}}
  document.querySelectorAll('.dx-chip').forEach(function(c){{c.classList.remove('on');}});
  if(window.event&&window.event.target)window.event.target.classList.add('on');
}}
/* --- 360 explorer --- */
(function(){{
  var F=window.__EXFRAMES__; if(!F)return;
  var idx=0,zoom=1,drag=false,lastX=0,acc=0;
  var frame=document.getElementById('exFrame');
  var spots=document.getElementById('exSpots');
  var viewer=document.getElementById('exViewer');
  var dotsBox=document.getElementById('exDots');
  if(dotsBox){{for(var i=0;i<F.length;i++){{var d=document.createElement('span');d.className='ex-dot'+(i===0?' on':'');dotsBox.appendChild(d);}}}}
  var canvas=document.getElementById('exCanvas');
  function render(){{
    frame.src=F[((idx%F.length)+F.length)%F.length];
    if(canvas)canvas.style.transform='scale('+zoom+')';
    if(spots)spots.style.display=(idx%F.length===0)?'block':'none';
    if(dotsBox){{var ds=dotsBox.children;for(var i=0;i<ds.length;i++)ds[i].className='ex-dot'+(i===((idx%F.length)+F.length)%F.length?' on':'');}}
  }}
  window.expZoom=function(z){{zoom=Math.min(2.2,Math.max(1,zoom+z));render();}};
  window.expReset=function(){{idx=0;zoom=1;render();}};
  function down(x){{drag=true;lastX=x;acc=0;viewer.classList.add('grabbing');}}
  function move(x){{if(!drag)return;acc+=(x-lastX);lastX=x;if(Math.abs(acc)>34){{idx+=acc>0?1:-1;acc=0;render();}}}}
  function up(){{drag=false;viewer.classList.remove('grabbing');}}
  if(viewer){{
    viewer.addEventListener('mousedown',function(e){{down(e.clientX);e.preventDefault();}});
    window.addEventListener('mousemove',function(e){{move(e.clientX);}});
    window.addEventListener('mouseup',up);
    viewer.addEventListener('touchstart',function(e){{down(e.touches[0].clientX);}},{{passive:true}});
    viewer.addEventListener('touchmove',function(e){{move(e.touches[0].clientX);}},{{passive:true}});
    viewer.addEventListener('touchend',up);
  }}
  render();
}})();
</script>
</body>
</html>"""


PAGE_CSS = r"""
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--font-sans);color:var(--c-text-primary);background:var(--c-surface-page);
  font-size:var(--fs-body);line-height:var(--lh-body);-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.wrap{max-width:var(--maxw);margin:0 auto;padding:0 var(--gutter)}
.muted{color:var(--c-text-muted)}
.mono{font-family:var(--font-mono);color:var(--c-text-secondary);font-size:var(--fs-caption)}
h1{font-size:var(--fs-display);line-height:var(--lh-display);font-weight:var(--fw-display);letter-spacing:-.02em}
h2{font-size:var(--fs-h2);font-weight:var(--fw-h2);letter-spacing:-.01em}
h3{font-size:var(--fs-h3);font-weight:var(--fw-h3)}
h4{font-size:var(--fs-h4);font-weight:var(--fw-h4)}
.overline{font-size:var(--fs-overline);font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--c-brand-blue)}
.icn{flex:none}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:var(--radius-md);
  font-family:inherit;font-weight:700;font-size:var(--fs-body);cursor:pointer;padding:12px 22px;transition:all .18s ease}
.btn-sm{padding:8px 16px;font-size:var(--fs-small)}
.btn-primary{background:var(--c-brand-blue);color:#fff;box-shadow:var(--shadow-sm)}
.btn-primary:hover{background:var(--c-brand-blueDark)}
.btn-accent{background:var(--c-brand-accent);color:var(--c-brand-ink)}
.btn-accent:hover{background:var(--c-brand-accentDeep)}
.btn-whatsapp{background:#25D366;color:#fff}
.btn-whatsapp:hover{background:#1FB855}
.chip{display:inline-flex;align-items:center;gap:4px;font-size:var(--fs-caption);font-weight:700;
  padding:3px 9px;border-radius:var(--radius-pill);line-height:1}

/* navbar */
.nav{position:sticky;top:0;z-index:50;background:var(--c-surface-header);color:var(--c-text-onDark)}
.nav-inner{display:flex;align-items:center;gap:22px;height:var(--header-h)}
.brand{display:flex;align-items:center;gap:10px}
.logo-word{display:flex;flex-direction:column;line-height:1}
.logo-word b{font-size:16px;font-weight:800;letter-spacing:1.2px}
.logo-word i{font-size:8.5px;font-style:normal;letter-spacing:3px;color:var(--c-text-onDarkMuted);font-weight:600}
.nav-links{display:flex;flex-wrap:nowrap;gap:18px;font-size:var(--fs-small);font-weight:600;color:var(--c-text-onDarkMuted)}
.nav-links a{white-space:nowrap}
.nav-links a:hover{color:#fff}
.nav-search{margin-left:auto;display:flex;align-items:center;gap:8px;background:#1b2436;border:1px solid #29344a;
  border-radius:var(--radius-md);padding:9px 12px;width:320px;color:var(--c-text-onDarkMuted)}
.nav-search input{background:transparent;border:0;outline:0;color:#fff;font-family:inherit;font-size:var(--fs-small);width:100%}
.nav-search input::placeholder{color:#6b7690}
.nav-actions{display:flex;align-items:center;gap:6px}
.flag{display:flex;align-items:center;gap:6px;background:transparent;border:0;color:#fff;font-family:inherit;
  font-weight:600;font-size:var(--fs-small);cursor:pointer;padding:8px}
.iconbtn{position:relative;background:transparent;border:0;color:#fff;cursor:pointer;padding:8px;border-radius:8px}
.iconbtn:hover{background:#1b2436}
.cart-dot{position:absolute;top:2px;right:2px;background:var(--c-brand-accent);color:#000;font-size:10px;
  font-weight:800;border-radius:50%;width:15px;height:15px;display:grid;place-items:center}

/* hero */
.hero{background:var(--c-surface-header);color:#fff;position:relative;overflow:hidden}
.hero::after{content:"";position:absolute;right:-8%;top:-30%;width:640px;height:640px;border-radius:50%;
  background:radial-gradient(circle,rgba(21,82,204,.35),transparent 62%)}
.hero-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:32px;align-items:center;padding:56px var(--gutter) 60px;position:relative;z-index:1}
.hero-copy p{color:var(--c-text-onDarkMuted);font-size:1.06rem;margin:16px 0 26px;max-width:44ch}
.hero-search{display:flex;align-items:center;gap:10px;background:#fff;border-radius:var(--radius-md);
  padding:8px 8px 8px 16px;color:var(--c-text-muted);box-shadow:var(--shadow-lg);max-width:520px}
.hero-search input{flex:1;border:0;outline:0;font-family:inherit;font-size:1rem;color:var(--c-text-primary)}
.pop-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:16px;font-size:var(--fs-small);color:var(--c-text-onDarkMuted)}
.pop-chip{background:#1b2436;border:1px solid #29344a;padding:5px 12px;border-radius:var(--radius-pill);font-weight:600;color:#dfe5f0}
.pop-chip:hover{border-color:var(--c-brand-blue)}
.hero-art{display:grid;place-items:center}
.hero-photo{width:100%;max-width:560px;border-radius:var(--radius-xl);
  box-shadow:0 30px 60px rgba(0,0,0,.5);object-fit:cover;aspect-ratio:16/11}
.imgph{width:100%;aspect-ratio:4/3;background:var(--c-surface-sunken);border-radius:var(--radius-md)}

/* trust bar */
.trustbar{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;background:var(--c-surface-card);
  border:1px solid var(--c-line-subtle);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);
  padding:20px 24px;margin-top:-30px;position:relative;z-index:3}
.trust-item{display:flex;align-items:center;gap:12px}
.trust-ic{display:grid;place-items:center;width:42px;height:42px;border-radius:var(--radius-md);
  background:var(--c-state-infoBg);color:var(--c-brand-blue);flex:none}
.trust-item strong{display:block;font-size:var(--fs-small);font-weight:700}
.trust-item span{font-size:var(--fs-caption);color:var(--c-text-muted)}

/* blocks */
.block{padding:52px var(--gutter) 8px}
.block-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:22px}
.block-head .muted{margin-top:6px;font-size:var(--fs-small)}
.link{display:inline-flex;align-items:center;gap:4px;color:var(--c-text-link);font-weight:700;font-size:var(--fs-small);white-space:nowrap}

/* motorcycle cards */
.moto-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
.moto-card{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-lg);
  overflow:hidden;box-shadow:var(--shadow-sm);transition:transform .2s ease,box-shadow .2s ease}
.moto-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.moto-card-img{position:relative;background:var(--c-surface-sunken);aspect-ratio:4/3;display:grid;place-items:center;overflow:hidden}
.moto-card-img .chip{position:absolute;top:12px;left:12px;z-index:2}
.moto-photo{width:100%;height:100%;object-fit:cover}
.moto-card-body{padding:16px 18px 18px}
.moto-card-body p{color:var(--c-text-muted);font-size:var(--fs-small);margin:4px 0 12px}
.ghost-cta{display:inline-flex;align-items:center;gap:4px;color:var(--c-text-link);font-weight:700;font-size:var(--fs-small)}

/* system cards (image cover + overlay) */
.sys-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.sys-card{position:relative;display:block;height:132px;border-radius:var(--radius-md);overflow:hidden;
  background:var(--c-surface-ink);transition:transform .18s ease,box-shadow .18s ease}
.sys-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}
.sys-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .3s ease}
.sys-card:hover .sys-bg{transform:scale(1.06)}
.sys-card::after{content:"";position:absolute;inset:0;
  background:linear-gradient(180deg,rgba(12,18,32,.25) 0%,rgba(12,18,32,.55) 55%,rgba(12,18,32,.9) 100%)}
.sys-content{position:absolute;left:14px;bottom:12px;right:12px;z-index:2;display:flex;align-items:center;gap:10px;color:#fff}
.sys-ic{display:grid;place-items:center;width:38px;height:38px;border-radius:var(--radius-md);
  background:rgba(21,82,204,.9);color:#fff;flex:none;box-shadow:var(--shadow-sm)}
.sys-meta strong{display:block;font-size:var(--fs-h4);font-weight:700;line-height:1.1}
.sys-meta span{font-size:var(--fs-caption);color:#dfe5f0}

/* product cards */
.prod-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px}
.prod-card{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-md);
  overflow:hidden;box-shadow:var(--shadow-xs);transition:box-shadow .18s ease}
.prod-card:hover{box-shadow:var(--shadow-md)}
.prod-img{background:var(--c-surface-sunken);height:130px;display:grid;place-items:center;overflow:hidden}
.prod-photo{width:100%;height:100%;object-fit:cover}
.prod-body{padding:12px 14px 14px}
.prod-badges{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap}
.prod-body h4{font-size:var(--fs-small);font-weight:700;line-height:1.3;min-height:2.4em}
.prod-body .mono{display:block;margin:2px 0 10px}
.prod-foot{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.prod-foot strong{font-size:1.02rem}
.stock{font-size:var(--fs-caption);font-weight:700}
.stock.ok{color:var(--c-state-success)}
.prod-body .btn{width:100%;justify-content:center}

/* AI band */
.ai-band{display:grid;grid-template-columns:1fr .9fr;gap:32px;align-items:center;
  background:linear-gradient(135deg,#101a30,#1a2b52);border-radius:var(--radius-xl);
  padding:40px;margin:44px 0 8px;color:#fff}
.ai-copy h2{margin:10px 0 12px}
.ai-copy p{color:var(--c-text-onDarkMuted);max-width:52ch;margin-bottom:22px}
.ai-ctas{display:flex;gap:12px;flex-wrap:wrap}
.ai-chat{background:#fff;color:var(--c-text-primary);border-radius:var(--radius-lg);padding:16px;box-shadow:var(--shadow-lg)}
.ai-chat-head{display:flex;align-items:center;gap:8px;font-weight:700;color:var(--c-brand-blue);
  padding-bottom:12px;border-bottom:1px solid var(--c-line-subtle);margin-bottom:12px}
.ai-msg{font-size:var(--fs-small);padding:10px 12px;border-radius:12px;margin-bottom:8px;max-width:88%}
.ai-msg.bot{background:var(--c-surface-sunken)}
.ai-msg.user{background:var(--c-brand-blue);color:#fff;margin-left:auto}
.ai-sugg{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.ai-sugg span{background:#fff;border:1px solid var(--c-line-default);border-radius:var(--radius-pill);
  padding:4px 10px;font-size:var(--fs-caption);font-weight:600;color:var(--c-brand-blue)}
.ai-input{display:flex;align-items:center;gap:8px;border:1px solid var(--c-line-default);border-radius:var(--radius-pill);
  padding:6px 6px 6px 12px;color:var(--c-text-muted);margin-top:6px}
.ai-input input{flex:1;border:0;outline:0;font-family:inherit;font-size:var(--fs-small)}

/* footer */
.footer{background:var(--c-surface-footer);color:var(--c-text-onDarkMuted);margin-top:56px;padding:48px 0 24px}
.footer-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1fr;gap:28px;padding-bottom:28px;border-bottom:1px solid #1e2740}
.foot-brand strong{color:#fff;font-size:18px;letter-spacing:1.2px;font-weight:800}
.foot-brand span{display:block;font-size:9px;letter-spacing:3px;color:var(--c-text-onDarkMuted);margin-bottom:12px}
.foot-brand p{font-size:var(--fs-small);max-width:34ch}
.foot-guarantee{display:flex;align-items:center;gap:8px;margin-top:14px;color:#9fe3bd;font-size:var(--fs-small);font-weight:600}
.foot-col h5{color:#fff;font-size:var(--fs-small);margin-bottom:12px}
.foot-col a{display:block;font-size:var(--fs-small);padding:5px 0;color:var(--c-text-onDarkMuted)}
.foot-col a:hover{color:#fff}
.foot-bar{display:flex;justify-content:space-between;font-size:var(--fs-caption);padding-top:18px;color:#5f6a84;flex-wrap:wrap;gap:8px}

/* ---------------- overview page ---------------- */
.btn-lg{padding:15px 26px;font-size:var(--fs-h4)}
.btn-secondary{background:var(--c-surface-card);color:var(--c-text-primary);border:1px solid var(--c-line-default)}
.btn-secondary:hover{background:var(--c-surface-sunken)}
.breadcrumb{padding:18px var(--gutter) 0}
.breadcrumb a{display:inline-flex;align-items:center;gap:6px;color:var(--c-text-link);font-weight:700;font-size:var(--fs-small)}
.breadcrumb .flip{transform:rotate(180deg)}
.ov-hero{display:grid;grid-template-columns:1fr 1.02fr;gap:40px;padding:22px var(--gutter) 8px;align-items:start}
.ov-name{font-size:var(--fs-h1);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.ov-tag{color:var(--c-text-secondary);font-size:1.1rem;margin-top:4px}
.ratingline{display:flex;align-items:center;gap:10px;margin:12px 0 18px}
.stars{display:inline-flex;gap:1px}
.star.on{color:var(--c-brand-accent);fill:var(--c-brand-accent)}
.star.off{color:var(--c-line-strong)}
.rate-num{font-size:var(--fs-small);color:var(--c-text-muted);font-weight:600}
.keyspecs{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin:6px 0 20px}
.ks{display:flex;align-items:center;gap:10px;background:var(--c-surface-card);border:1px solid var(--c-line-subtle);
  border-radius:var(--radius-md);padding:12px 14px}
.ks-ic{display:grid;place-items:center;width:38px;height:38px;border-radius:var(--radius-md);
  background:var(--c-state-infoBg);color:var(--c-brand-blue);flex:none}
.ks strong{display:block;font-size:1.05rem;line-height:1.15}
.ks em{display:block;font-style:normal;font-size:var(--fs-caption);color:var(--c-text-muted);margin-top:2px}
.ov-summary{color:var(--c-text-secondary);max-width:52ch;margin-bottom:22px}
.ov-ctas{display:flex;gap:12px;flex-wrap:wrap}
/* gallery */
.gallery{display:grid;grid-template-columns:76px 1fr;gap:14px}
.gal-thumbs{display:flex;flex-direction:column;gap:10px;max-height:440px;overflow:auto}
.gal-thumb{border:2px solid var(--c-line-subtle);border-radius:var(--radius-sm);overflow:hidden;
  background:var(--c-surface-sunken);cursor:pointer;padding:0;aspect-ratio:1;flex:none}
.gal-thumb.active{border-color:var(--c-brand-blue)}
.gal-thumb-img{width:100%;height:100%;object-fit:cover;display:block}
.gal-main{position:relative;background:var(--c-surface-sunken);border-radius:var(--radius-lg);
  overflow:hidden;aspect-ratio:4/3;border:1px solid var(--c-line-subtle)}
.gal-main-img{width:100%;height:100%;object-fit:cover}
.gal-360{position:absolute;bottom:12px;right:12px;display:inline-flex;align-items:center;gap:6px;
  background:rgba(12,18,32,.8);color:#fff;font-size:var(--fs-caption);font-weight:700;
  padding:6px 12px;border-radius:var(--radius-pill)}
/* tabs */
.tabs{display:flex;gap:26px;border-bottom:1px solid var(--c-line-default);margin-top:24px;overflow:auto}
.tab{padding:14px 2px;font-size:var(--fs-small);font-weight:700;color:var(--c-text-muted);
  border-bottom:2px solid transparent;white-space:nowrap}
.tab.active{color:var(--c-brand-blue);border-color:var(--c-brand-blue)}
.tab:hover{color:var(--c-text-primary)}
/* body */
.ov-body{display:grid;grid-template-columns:1fr 320px;gap:32px;padding:32px var(--gutter) 8px;align-items:start}
.block-head.compact{margin-bottom:16px}
.block-head.compact h2{font-size:var(--fs-h3)}
/* spec table */
.spec-wrap{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.spec-group{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-md);overflow:hidden}
.spec-group h4{background:var(--c-surface-sunken);padding:10px 14px;font-size:var(--fs-small);border-bottom:1px solid var(--c-line-subtle)}
.spec-group table{width:100%;border-collapse:collapse}
.spec-group td{padding:9px 14px;font-size:var(--fs-small);border-bottom:1px solid var(--c-line-subtle)}
.spec-group td:first-child{color:var(--c-text-secondary);width:46%}
.spec-group td:last-child{font-weight:600}
.spec-group tr:last-child td{border-bottom:0}
/* sidebar */
.ov-side{display:flex;flex-direction:column;gap:16px;position:sticky;top:84px}
.card{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-lg);
  padding:18px;box-shadow:var(--shadow-xs)}
.card h4{font-size:var(--fs-h4);margin-bottom:12px}
.vi-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--c-line-subtle);font-size:var(--fs-small)}
.vi-row:last-child{border-bottom:0}
.vi-row span{color:var(--c-text-secondary)}
.intel-head{display:flex;justify-content:space-between;align-items:center}
.chip.on{background:var(--c-state-successBg);color:var(--c-state-success);font-size:var(--fs-caption);font-weight:700;padding:3px 9px;border-radius:var(--radius-pill)}
.intel-odo,.intel-next{display:flex;flex-direction:column;margin-top:10px;font-size:var(--fs-small)}
.intel-odo span,.intel-next span{color:var(--c-text-muted);font-size:var(--fs-caption)}
.intel-odo strong,.intel-next strong{font-size:1.05rem}
.bar{height:6px;background:var(--c-surface-sunken);border-radius:99px;margin-top:6px;overflow:hidden}
.bar i{display:block;height:100%;background:var(--c-brand-blue)}
.cm-title{font-size:var(--fs-caption);color:var(--c-text-muted);margin:14px 0 8px;font-weight:600}
.cm-grid{display:flex;gap:8px}
.cm-part{flex:1;text-align:center}
.cm-img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:var(--radius-sm);background:var(--c-surface-sunken)}
.cm-part span{display:block;font-size:10px;color:var(--c-text-secondary);margin-top:4px;line-height:1.2}
.help-card p{font-size:var(--fs-small);color:var(--c-text-muted);margin:6px 0 12px}
.help-card .btn{width:100%;justify-content:center}

/* ---------------- parts catalogue page ---------------- */
.parts-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:24px;flex-wrap:wrap}
.parts-head h1{font-size:var(--fs-h1)}
.parts-head .muted{margin-top:6px}
.parts-search{display:flex;align-items:center;gap:8px;background:var(--c-surface-card);border:1px solid var(--c-line-default);
  border-radius:var(--radius-md);padding:11px 14px;width:340px;color:var(--c-text-muted)}
.parts-search input{border:0;outline:0;font-family:inherit;font-size:var(--fs-small);width:100%}
.cat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.cat-card{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-lg);
  overflow:hidden;box-shadow:var(--shadow-sm);transition:transform .18s ease,box-shadow .18s ease}
.cat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.cat-img{aspect-ratio:16/11;background:var(--c-surface-sunken);overflow:hidden}
.cat-bg{width:100%;height:100%;object-fit:cover}
.cat-body{padding:14px 16px}
.cat-body strong{display:block;font-size:var(--fs-h4)}
.cat-body span{font-size:var(--fs-caption);color:var(--c-text-muted)}
.parts-cta{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:26px}
.pc-card{display:flex;gap:14px;background:var(--c-surface-card);border:1px solid var(--c-line-subtle);
  border-radius:var(--radius-lg);padding:20px}
.pc-ic{display:grid;place-items:center;width:46px;height:46px;border-radius:var(--radius-md);flex:none;
  background:var(--c-state-infoBg);color:var(--c-brand-blue)}
.pc-ic.ok{background:var(--c-state-successBg);color:var(--c-state-success)}
.pc-card strong{font-size:var(--fs-h4)}
.pc-card p{font-size:var(--fs-small);color:var(--c-text-muted);margin:4px 0 10px}

/* ---------------- exploded diagram page ---------------- */
.dia-wrap{display:grid;grid-template-columns:220px 1fr;gap:24px;padding:24px var(--gutter) 8px;align-items:start}
.dia-nav{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-lg);padding:16px;position:sticky;top:84px}
.dia-nav h4{font-size:var(--fs-small);margin-bottom:8px}
.pl-list{display:flex;flex-direction:column;gap:2px;margin-bottom:16px}
.pl-item{text-align:left;background:transparent;border:0;font-family:inherit;font-size:var(--fs-small);
  color:var(--c-text-secondary);padding:8px 10px;border-radius:var(--radius-sm);cursor:pointer;border-left:3px solid transparent}
.pl-item:hover{background:var(--c-surface-sunken)}
.pl-item.active{background:var(--c-state-infoBg);color:var(--c-brand-blue);font-weight:700;border-left-color:var(--c-brand-blue)}
.pl-more{color:var(--c-text-muted);border-top:1px solid var(--c-line-subtle);padding-top:12px}
.pl-sys{display:flex;align-items:center;gap:8px;font-size:var(--fs-small);color:var(--c-text-muted);padding:6px 10px}
.dia-panel{display:none;grid-template-columns:1fr 1.15fr;gap:24px}
.dia-panel.active{display:grid}
.dia-frame{position:relative;background:#fff;border:1px solid var(--c-line-default);border-radius:var(--radius-lg);
  overflow:hidden;display:grid;place-items:center;padding:10px}
.dia-img{width:100%;height:auto;object-fit:contain;mix-blend-mode:multiply}
.dia-plate{position:absolute;top:10px;left:10px;background:var(--c-brand-ink);color:#fff;font-size:var(--fs-caption);
  font-weight:700;padding:4px 10px;border-radius:var(--radius-pill)}
.dia-actions{display:flex;gap:10px;margin-top:12px}
.dia-title{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px;flex-wrap:wrap;gap:6px}
.dia-title h3{font-size:var(--fs-h3)}
.plist{width:100%;border-collapse:collapse;background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-md);overflow:hidden}
.plist th{background:var(--c-surface-sunken);text-align:left;font-size:var(--fs-caption);text-transform:uppercase;
  letter-spacing:.04em;color:var(--c-text-secondary);padding:10px 12px}
.plist td{padding:10px 12px;font-size:var(--fs-small);border-top:1px solid var(--c-line-subtle);vertical-align:top}
.plist tbody tr:hover,.plist tr:hover{background:var(--c-surface-sunken)}
.plist .mono{white-space:nowrap}
.plist .c-no{color:var(--c-text-muted);width:34px}
.plist .c-qty{text-align:center;width:44px}
.plist .c-price{font-weight:700;white-space:nowrap}
.plist .rmk{display:block;font-size:var(--fs-caption);color:var(--c-text-muted)}
.mini-add{background:var(--c-state-infoBg);color:var(--c-brand-blue);border:0;border-radius:var(--radius-sm);
  width:30px;height:30px;display:grid;place-items:center;cursor:pointer}
.mini-add:hover{background:var(--c-brand-blue);color:#fff}

/* ---------------- part detail page ---------------- */
.pd-wrap{display:grid;grid-template-columns:1fr 1fr;gap:36px;padding:24px var(--gutter) 8px;align-items:start}
.pd-gallery{display:grid;grid-template-columns:76px 1fr;gap:14px}
.pd-thumbs{display:flex;flex-direction:column;gap:10px}
.pd-thumb{border:2px solid var(--c-line-subtle);border-radius:var(--radius-sm);overflow:hidden;cursor:pointer;
  padding:0;aspect-ratio:1;background:var(--c-surface-sunken)}
.pd-thumb.active{border-color:var(--c-brand-blue)}
.pd-thumb-img{width:100%;height:100%;object-fit:cover;display:block}
.pd-main{background:var(--c-surface-sunken);border:1px solid var(--c-line-subtle);border-radius:var(--radius-lg);
  overflow:hidden;aspect-ratio:1;display:grid;place-items:center}
.pd-main-img{width:100%;height:100%;object-fit:contain}
.pd-badges{display:flex;gap:8px;margin-bottom:10px}
.pd-info h1{font-size:var(--fs-h1);margin-bottom:4px}
.pd-partno{color:var(--c-text-muted)}
.pd-price{font-size:2rem;font-weight:800;color:var(--c-brand-blue);margin:14px 0 6px}
.pd-stock{margin-bottom:14px}
.pd-stock .stock{display:inline-flex;align-items:center;gap:5px;background:var(--c-state-successBg);
  padding:5px 12px;border-radius:var(--radius-pill)}
.pd-desc{color:var(--c-text-secondary);margin-bottom:20px;max-width:52ch}
.pd-buy{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:24px}
.qty{display:flex;align-items:center;border:1px solid var(--c-line-default);border-radius:var(--radius-md);overflow:hidden}
.qty button{width:40px;height:46px;border:0;background:var(--c-surface-card);font-size:18px;cursor:pointer;color:var(--c-text-secondary)}
.qty button:hover{background:var(--c-surface-sunken)}
.qty input{width:46px;height:46px;border:0;text-align:center;font-family:inherit;font-weight:700;font-size:1rem;
  border-left:1px solid var(--c-line-subtle);border-right:1px solid var(--c-line-subtle)}
.fit-box,.attr-box{border-top:1px solid var(--c-line-subtle);padding-top:18px;margin-bottom:18px}
.fit-box h4,.attr-box h4{font-size:var(--fs-h4);margin-bottom:12px}
.fit-chips{display:flex;flex-wrap:wrap;gap:8px}
.fit-chip{display:inline-flex;align-items:center;gap:5px;background:var(--c-state-successBg);color:var(--c-state-success);
  font-size:var(--fs-small);font-weight:600;padding:6px 12px;border-radius:var(--radius-pill)}
.attr-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.attr{display:flex;justify-content:space-between;font-size:var(--fs-small);padding:8px 0;border-bottom:1px solid var(--c-line-subtle)}
.attr span{color:var(--c-text-muted)}
.fit-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.fit-card{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-lg);
  overflow:hidden;text-align:center;padding-bottom:14px;box-shadow:var(--shadow-xs);transition:box-shadow .18s ease}
.fit-card:hover{box-shadow:var(--shadow-md)}
.fit-img{width:100%;aspect-ratio:4/3;object-fit:cover;background:var(--c-surface-sunken);margin-bottom:10px}
.fit-card strong{display:block;font-size:var(--fs-small)}
.fit-card span{font-size:var(--fs-caption);color:var(--c-text-muted)}
.ab-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.ab-card{background:var(--c-surface-card);border:1px solid var(--c-line-subtle);border-radius:var(--radius-md);overflow:hidden;text-align:center;padding-bottom:12px}
.ab-img{aspect-ratio:1;background:var(--c-surface-sunken);overflow:hidden}
.ab-photo{width:100%;height:100%;object-fit:cover}
.ab-card strong{display:block;font-size:var(--fs-small);margin-top:8px}
.ab-card span{font-size:var(--fs-caption);color:var(--c-text-secondary);font-weight:700}

/* ---------------- 360 explorer ---------------- */
.ex-wrap{display:grid;grid-template-columns:260px 1fr;gap:24px;padding:24px var(--gutter) 8px;align-items:start}
.ex-nav{background:var(--c-surface-ink);color:#fff;border-radius:var(--radius-lg);padding:18px}
.ex-nav h4{font-size:var(--fs-h4)}
.ex-sub{color:var(--c-text-onDarkMuted)!important;font-size:var(--fs-caption);margin:4px 0 12px}
.ex-sys{display:flex;align-items:center;gap:10px;color:#cdd6e6;padding:11px 12px;border-radius:var(--radius-md);
  font-size:var(--fs-small);font-weight:600;margin-bottom:2px}
.ex-sys:hover{background:rgba(255,255,255,.08);color:#fff}
.ex-sys .ex-chev{margin-left:auto;opacity:.5}
.ex-stage{background:linear-gradient(180deg,#eef2f8,#dde5f0);border:1px solid var(--c-line-subtle);
  border-radius:var(--radius-lg);padding:16px;min-height:520px}
.ex-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px}
.ex-hint{display:inline-flex;align-items:center;gap:6px;font-size:var(--fs-caption);color:var(--c-text-secondary);
  background:var(--c-surface-card);padding:6px 12px;border-radius:var(--radius-pill);font-weight:600}
.ex-tools{display:flex;gap:6px}
.ex-btn{display:inline-flex;align-items:center;gap:5px;background:var(--c-surface-card);border:1px solid var(--c-line-default);
  border-radius:var(--radius-md);padding:7px 12px;font-family:inherit;font-weight:700;font-size:var(--fs-small);cursor:pointer}
.ex-btn:hover{background:var(--c-surface-sunken)}
.ex-viewer{position:relative;height:440px;display:grid;place-items:center;cursor:grab;overflow:hidden}
.ex-viewer.grabbing{cursor:grabbing}
.ex-canvas{position:relative;display:inline-block;transition:transform .12s ease;transform-origin:center}
.ex-frame{display:block;width:min(540px,80%);height:auto;object-fit:contain;user-select:none;-webkit-user-drag:none;margin:0 auto}
.ex-spots{position:absolute;inset:0}
.hotspot{position:absolute;transform:translate(-50%,-50%);z-index:3}
.hs-dot{display:block;width:18px;height:18px;border-radius:50%;background:var(--c-brand-blue);border:3px solid #fff;
  box-shadow:0 2px 8px rgba(21,82,204,.6);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(21,82,204,.5)}70%{box-shadow:0 0 0 12px rgba(21,82,204,0)}100%{box-shadow:0 0 0 0 rgba(21,82,204,0)}}
.hs-tip{position:absolute;left:50%;bottom:26px;transform:translateX(-50%);white-space:nowrap;background:var(--c-brand-ink);
  color:#fff;font-size:var(--fs-caption);font-weight:700;padding:6px 10px;border-radius:var(--radius-sm);opacity:0;
  pointer-events:none;transition:opacity .15s ease}
.hotspot:hover .hs-tip{opacity:1}
.ex-dots{display:flex;justify-content:center;gap:6px;margin-top:10px}
.ex-dot{width:8px;height:8px;border-radius:50%;background:var(--c-line-strong)}
.ex-dot.on{background:var(--c-brand-blue);width:22px;border-radius:99px}

/* ---------------- AI diagnosis ---------------- */
.ai-page{padding:24px var(--gutter) 8px;display:flex;justify-content:center}
.ai-col{width:100%;max-width:860px;background:var(--c-surface-card);border:1px solid var(--c-line-subtle);
  border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);padding:22px}
.ai-titlebar{display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--c-line-subtle);padding-bottom:14px;margin-bottom:16px}
.ai-avatar{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;background:var(--c-brand-blue);color:#fff}
.ai-titlebar strong{display:block}
.ai-status{font-size:var(--fs-caption);color:var(--c-state-success);font-weight:600}
.msg{font-size:var(--fs-small);padding:12px 14px;border-radius:14px;margin-bottom:10px;max-width:92%;line-height:1.55}
.msg.bot{background:var(--c-surface-sunken)}
.msg.user{background:var(--c-brand-blue);color:#fff;margin-left:auto}
.dx-chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:6px 0 14px}
.dx-chips-lbl{font-size:var(--fs-caption);color:var(--c-text-muted);font-weight:600}
.dx-chip{background:var(--c-surface-card);border:1px solid var(--c-line-default);border-radius:var(--radius-pill);
  padding:7px 14px;font-family:inherit;font-size:var(--fs-small);font-weight:600;color:var(--c-brand-blue);cursor:pointer}
.dx-chip:hover,.dx-chip.on{background:var(--c-brand-blue);color:#fff;border-color:var(--c-brand-blue)}
.dx-block{display:none}
.dx-block.active{display:block}
.dx-cards{display:flex;flex-direction:column;gap:10px;margin:12px 0}
.dx-card{display:flex;gap:12px;background:var(--c-surface-card);border:1px solid var(--c-line-subtle);
  border-radius:var(--radius-md);padding:10px;box-shadow:var(--shadow-xs)}
.dx-cimg{width:74px;height:74px;flex:none;border-radius:var(--radius-sm);overflow:hidden;background:var(--c-surface-sunken)}
.dx-thumb{width:100%;height:100%;object-fit:cover}
.dx-cbody{flex:1;min-width:0}
.dx-crow{display:flex;justify-content:space-between;align-items:center;gap:8px}
.dx-crow strong{font-size:var(--fs-small)}
.dx-prob{font-size:var(--fs-small);font-weight:800;color:var(--c-brand-blue)}
.dx-bar{height:6px;background:var(--c-surface-sunken);border-radius:99px;margin:6px 0;overflow:hidden}
.dx-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--c-brand-blue),var(--c-brand-accent))}
.dx-sys{font-size:var(--fs-caption);color:var(--c-text-muted)}
.dx-acts{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.dx-acts .btn{padding:6px 12px;font-size:var(--fs-caption)}
.dx-steps{background:var(--c-surface-card);border:1px dashed var(--c-line-default);border-radius:var(--radius-md);padding:12px 14px;margin-top:6px}
.dx-steps strong{font-size:var(--fs-small)}
.dx-steps ol{margin:8px 0 0 18px;font-size:var(--fs-small);color:var(--c-text-secondary);line-height:1.7}
.dx-note{font-size:var(--fs-caption);color:var(--c-text-muted);margin-top:10px;font-style:italic}
.ai-inputbar{display:flex;align-items:center;gap:8px;border:1px solid var(--c-line-default);border-radius:var(--radius-pill);
  padding:6px 6px 6px 14px;color:var(--c-text-muted);margin-top:14px}
.ai-inputbar input{flex:1;border:0;outline:0;font-family:inherit;font-size:var(--fs-small)}

/* responsive */
@media(max-width:1080px){
  .ov-hero,.ov-body,.spec-wrap,.pd-wrap,.dia-panel,.ex-wrap{grid-template-columns:1fr}
  .cat-grid,.fit-grid,.ab-grid{grid-template-columns:repeat(2,1fr)}
  .parts-cta{grid-template-columns:1fr}
  .dia-wrap{grid-template-columns:1fr}
  .dia-nav{position:static}
  .ov-side{position:static}
  .moto-grid,.prod-grid,.sys-grid{grid-template-columns:repeat(2,1fr)}
  .trustbar{grid-template-columns:repeat(2,1fr)}
  .hero-grid,.ai-band,.footer-grid{grid-template-columns:1fr}
  .footer-grid{gap:20px}
  h1{font-size:2.4rem}
  .nav-links,.nav-search{display:none}
}
@media(max-width:560px){
  .moto-grid,.prod-grid,.sys-grid,.trustbar{grid-template-columns:1fr}
}
"""

if __name__ == "__main__":
    tp = sys.argv[1] if len(sys.argv) > 1 else "data/tenant.json"
    od = sys.argv[2] if len(sys.argv) > 2 else None
    build(tp, od)
