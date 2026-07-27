#!/usr/bin/env python3
"""
Build the deployable storefront: autoparts_spa.html (template)  +  build/*.json (data)
                                  -> resources/storefront/autoparts.html (single self-contained file)

The 6 DATA placeholders are baked in here at build time. The 3 runtime placeholders
(__AUTOPARTS_CONFIG__, __SEO_TITLE__, __SEO_META__) are left for StorefrontController::autoParts()
to inject per-tenant on each request.

Usage:  python3 build_storefront.py [--check]
  --check  : verify the build is reproducible (no unresolved data placeholders) without writing.
"""
import sys, pathlib

HOME = pathlib.Path(__file__).resolve().parent
TEMPLATE = HOME / "autoparts_spa.html"
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


def build(check_only=False):
    html = TEMPLATE.read_text()

    for ph, path in DATA.items():
        if ph not in html:
            raise SystemExit(f"ERROR: placeholder {ph} not found in template")
        data = path.read_text().strip()
        if not data:
            raise SystemExit(f"ERROR: data file {path} is empty")
        html = html.replace(ph, data)

    # No DATA placeholder may survive; only the runtime ones are allowed to remain.
    import re
    leftover = set(re.findall(r"__[A-Z_]+__", html)) - RUNTIME
    if leftover:
        raise SystemExit(f"ERROR: unresolved placeholders after build: {sorted(leftover)}")
    for r in RUNTIME:
        if r not in html:
            raise SystemExit(f"ERROR: runtime placeholder {r} missing (controller injects it)")

    size_mb = len(html.encode()) / 1e6
    if check_only:
        print(f"OK check: template resolves cleanly, output would be {size_mb:.2f} MB")
        return
    OUT.write_text(html)
    print(f"Built {OUT}  ({size_mb:.2f} MB)")


if __name__ == "__main__":
    build(check_only="--check" in sys.argv)
