#!/usr/bin/env python3
"""
Build the deployable storefront:
    autoparts_spa.html (template)  +  src/*.js (app modules)  +  build/*.json (data)
        -> resources/storefront/autoparts.html (single self-contained file)

Three stages:
  1. Assemble the app bundle by concatenating src/*.js in filename order (they share one
     runtime scope by design; 12-a11y-boot.js must sort last as it boots the app) and inject
     it at __APP_BUNDLE__. Optional --minify runs esbuild if available.
  2. Bake the 6 DATA placeholders (img/gal/etc.) from build/*.json.
  3. Leave the 3 runtime placeholders (__AUTOPARTS_CONFIG__, __SEO_TITLE__, __SEO_META__)
     for StorefrontController::autoParts() to inject per-tenant on each request.

Usage:  python3 build_storefront.py [--check] [--minify]
  --check   : verify the build resolves cleanly without writing.
  --minify  : minify the app bundle with esbuild (falls back to plain concat if unavailable).
"""
import sys, pathlib, subprocess, shutil

HOME = pathlib.Path(__file__).resolve().parent
TEMPLATE = HOME / "autoparts_spa.html"
SRC = HOME / "src"
BUILD = HOME / "bajaj-genuine-parts" / "build"
OUT = HOME / "shopcode" / "shopping-main" / "resources" / "storefront" / "autoparts.html"

# placeholder  ->  data file (raw JSON, inlined verbatim as a JS literal)
DATA = {
    "__IMG_DATA__":      BUILD / "img_data.json",
    "__TB_DATA__":       BUILD / "tb_data.json",
    "__MODELS_DATA__":   BUILD / "models_data.json",
    "__DIAG_DATA__":     BUILD / "diag_extracted.json",
    "__GAL_DATA__":      BUILD / "gal_data.json",
    "__SYSCOVER_DATA__": BUILD / "syscover_data.json",
}

# Left for the controller to inject at request time.
RUNTIME = {"__AUTOPARTS_CONFIG__", "__SEO_TITLE__", "__SEO_META__"}

# Non-placeholder tokens that legitimately match the __NAME__ shape and stay in the output
# (e.g. the vendored-fonts CSS comment markers). Not injected, not an error.
ALLOWED_TOKENS = {"__VENDORED_FONTS_START__", "__VENDORED_FONTS_END__"}


def assemble_bundle(minify=False):
    """Concatenate src/*.js (filename order) into the single inline app bundle."""
    files = sorted(SRC.glob("*.js"))
    if not files:
        raise SystemExit(f"ERROR: no source modules found in {SRC}")
    parts = [f.read_text() for f in files]
    bundle = "\n".join(parts)

    if minify and shutil.which("esbuild"):
        try:
            bundle = subprocess.run(
                ["esbuild", "--minify", "--loader=js"],
                input=bundle, capture_output=True, text=True, check=True,
            ).stdout
        except subprocess.CalledProcessError as e:
            raise SystemExit(f"ERROR: esbuild minify failed:\n{e.stderr}")
    return bundle, [f.name for f in files]


def build(check_only=False, minify=False):
    html = TEMPLATE.read_text()

    # Stage 1: the app bundle.
    if "__APP_BUNDLE__" not in html:
        raise SystemExit("ERROR: __APP_BUNDLE__ placeholder not found in template")
    bundle, names = assemble_bundle(minify=minify)
    html = html.replace("__APP_BUNDLE__", bundle)

    # Stage 2: data.
    for ph, path in DATA.items():
        if ph not in html:
            raise SystemExit(f"ERROR: placeholder {ph} not found in template")
        data = path.read_text().strip()
        if not data:
            raise SystemExit(f"ERROR: data file {path} is empty")
        html = html.replace(ph, data)

    # No DATA placeholder may survive; only the runtime ones are allowed to remain.
    import re
    leftover = set(re.findall(r"__[A-Z_]+__", html)) - RUNTIME - ALLOWED_TOKENS
    if leftover:
        raise SystemExit(f"ERROR: unresolved placeholders after build: {sorted(leftover)}")
    for r in RUNTIME:
        if r not in html:
            raise SystemExit(f"ERROR: runtime placeholder {r} missing (controller injects it)")

    size_mb = len(html.encode()) / 1e6
    if check_only:
        print(f"OK check: {len(names)} modules + data resolve cleanly, output would be {size_mb:.2f} MB")
        return
    OUT.write_text(html)
    print(f"Built {OUT}  ({size_mb:.2f} MB) from {len(names)} modules: {', '.join(names)}")


if __name__ == "__main__":
    build(check_only="--check" in sys.argv, minify="--minify" in sys.argv)
