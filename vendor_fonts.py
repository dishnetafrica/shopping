#!/usr/bin/env python3
"""
Self-host Font Awesome 4.7 + Inter + JetBrains Mono into the storefront template.

Removes the 3 third-party origins (cdnjs, fonts.googleapis.com, fonts.gstatic.com) by
inlining the FA icon CSS with an embedded woff2, plus @font-face rules for the two text
fonts as base64 woff2 data URIs. Keeps the storefront fully self-contained (works with a
blocked/slow CDN — important for South Sudan connectivity), degrading nothing.

Idempotent: re-running replaces the injected block rather than stacking copies.
"""
import base64, re, pathlib

HOME = pathlib.Path(__file__).resolve().parent
FONTS = pathlib.Path("/tmp/fonts")
TEMPLATE = HOME / "autoparts_spa.html"

MARK_START = "/* __VENDORED_FONTS_START__ */"
MARK_END = "/* __VENDORED_FONTS_END__ */"


def b64(path):
    return base64.b64encode(pathlib.Path(path).read_bytes()).decode()


def datauri(path):
    return f"data:font/woff2;base64,{b64(path)}"


def build_css():
    # Font Awesome: take the shipped min.css but swap its multi-format @font-face for one
    # embedded woff2 (every modern browser we target supports woff2).
    fa = (FONTS / "fa/package/css/font-awesome.min.css").read_text()
    fa_font = datauri(FONTS / "fa/package/fonts/fontawesome-webfont.woff2")
    fa_face = ("@font-face{font-family:'FontAwesome';src:url('" + fa_font +
               "') format('woff2');font-weight:normal;font-style:normal;font-display:swap}")
    fa = re.sub(r"@font-face\{.*?\}", fa_face, fa, count=1, flags=re.S)

    faces = [fa]

    # Inter (5 weights) + JetBrains Mono (500), latin subset, embedded woff2.
    inter = FONTS / "inter/package/files"
    for w in (400, 500, 600, 700, 800):
        faces.append(
            "@font-face{font-family:'Inter';font-style:normal;font-weight:%d;font-display:swap;"
            "src:url('%s') format('woff2')}" % (w, datauri(inter / f"inter-latin-{w}-normal.woff2"))
        )
    jbm = FONTS / "jbm/package/files/jetbrains-mono-latin-500-normal.woff2"
    faces.append(
        "@font-face{font-family:'JetBrains Mono';font-style:normal;font-weight:500;font-display:swap;"
        "src:url('%s') format('woff2')}" % datauri(jbm)
    )

    return MARK_START + "\n" + "\n".join(faces) + "\n" + MARK_END


def main():
    html = TEMPLATE.read_text()
    css = build_css()

    # 1) Remove the three CDN <link> lines (FA + Google Fonts preconnect + css2).
    html = re.sub(r'\s*<link rel="preconnect"[^>]*>\s*<link rel="preconnect"[^>]*crossorigin>', "", html)
    html = re.sub(r'\s*<link rel="stylesheet" href="https://cdnjs\.cloudflare\.com/[^"]*font-awesome[^"]*">', "", html)
    html = re.sub(r'\s*<link href="https://fonts\.googleapis\.com/css2[^"]*"[^>]*>', "", html)

    # 2) Inject (or replace) the vendored block at the very start of the first <style>.
    if MARK_START in html:
        html = re.sub(re.escape(MARK_START) + r".*?" + re.escape(MARK_END), css, html, flags=re.S)
    else:
        html = html.replace("<style>", "<style>\n" + css, 1)

    TEMPLATE.write_text(html)

    # Report.
    remaining = re.findall(r'https://(?:cdnjs\.cloudflare\.com|fonts\.googleapis\.com|fonts\.gstatic\.com)\S*', html)
    print("Injected vendored fonts. Size now: %.2f MB" % (len(html.encode()) / 1e6))
    print("Remaining third-party font/CDN origins:", remaining or "NONE")


if __name__ == "__main__":
    main()
