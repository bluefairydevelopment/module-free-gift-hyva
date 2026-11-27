#!/usr/bin/env python3
"""
Build each package's shipped PDF guide from the Markdown that is already the source of
truth, so the guide cannot drift from the documentation beside it.

WHY THIS EXISTS. The one PDF this repository shipped was written by hand on 12 August and
committed at v1.0.2. By the time anyone looked at it again its own cover page still read
"v1.0.0 - Magento 2.4.x - PHP 8.1+", it embedded the four byte-identical wizard screenshots
that were already known to be wrong, and NOTHING IN THE REPOSITORY LINKED TO IT -- 1.8 MB of
stale guide shipping to every merchant with no reader and no way to notice it had rotted.

A hand-made artefact has no way to go red. This one is generated from README.md and
FEATURES.md, which are tested: specs/40-graphql-docs.spec.js executes every GraphQL example
in them, and specs/90-docs.spec.js re-shoots every image they embed only after asserting the
behaviour the image illustrates. So the guide inherits those guarantees instead of being a
separate copy of the same claims that nobody checks.

    python3 docs-build/build-guides.py            # all three packages
    python3 docs-build/build-guides.py FreeGift   # one
    python3 docs-build/build-guides.py --version 1.2.0   # override the CHANGELOG's top heading

Needs pandoc and Google Chrome. Both are already on this machine; neither is a runtime
dependency of the module.
"""

from __future__ import annotations

import html
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

CHROME = Path('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')

# This repository IS one package: the Hyvä companion. It was a package inside the paid
# module-free-gift monorepo, where ROOT / <pkg> addressed each one; here the package is the root.
PACKAGES = {
    'FreeGiftHyva': 'Free Gift — Hyvä Companion',
}

CSS = """
@page { size: A4; margin: 18mm 16mm 20mm; }
* { box-sizing: border-box; }
body {
  font: 10.5pt/1.6 -apple-system, "Helvetica Neue", Arial, sans-serif;
  color: #1f2430; margin: 0;
}
h1, h2, h3, h4 { color: #101725; line-height: 1.25; page-break-after: avoid; }
h1 { font-size: 20pt; margin: 0 0 .4em; }
h2 { font-size: 15pt; margin: 1.8em 0 .5em; padding-bottom: .25em; border-bottom: 2px solid #dfe4ec; }
h3 { font-size: 12pt; margin: 1.4em 0 .4em; }
p, li { orphans: 3; widows: 3; }
h1, h2, h3, h4 { break-after: avoid; }
p:has(> img:only-child) { break-inside: avoid; break-before: auto; }
h2 + p, h3 + p { break-after: avoid; }
p:has(+ pre) { break-after: avoid; page-break-after: avoid; }
p:has(+ div) { break-after: avoid; page-break-after: avoid; }
p:has(+ table) { break-after: avoid; page-break-after: avoid; }
p:has(+ img) { break-after: avoid; page-break-after: avoid; }
p:has(+ p > img) { break-after: avoid; page-break-after: avoid; }
p:has(+ blockquote) { break-after: avoid; page-break-after: avoid; }
p:has(+ ul) { break-after: avoid; page-break-after: avoid; }
p:has(+ ol) { break-after: avoid; page-break-after: avoid; }
p:has(> strong:only-child) { break-after: avoid; page-break-after: avoid; }
p:has(+ figure) { break-after: avoid; page-break-after: avoid; }
p.keepnext { break-after: avoid; page-break-after: avoid; }
code { font: 9pt/1.5 ui-monospace, Menlo, monospace; background: #f2f4f8; padding: .1em .35em; border-radius: 3px; }
.sourceCode { break-inside: avoid; }
pre { background: #f2f4f8; border: 1px solid #e1e6ef; border-radius: 5px; padding: .8em 1em; white-space: pre-wrap; overflow-wrap: anywhere; page-break-inside: avoid; }
pre code { background: none; padding: 0; }
table { border-collapse: collapse; width: 100%; margin: 1em 0; font-size: 9.5pt; page-break-inside: avoid; }
th, td { border: 1px solid #dfe4ec; padding: .45em .6em; text-align: left; vertical-align: top; }
th { background: #f2f4f8; font-weight: 600; }
img { max-width: 100%; max-height: 205mm; width: auto; object-fit: contain; break-inside: avoid; height: auto; border: 1px solid #dfe4ec; border-radius: 4px; margin: .6em 0; page-break-inside: avoid; }
blockquote { margin: 1em 0; padding: .6em 1em; border-left: 3px solid #c9a227; background: #fdfaf0; }
a { color: #1d4ed8; text-decoration: none; word-break: break-word; }

.cover { page-break-after: always; padding-top: 55mm; }
.cover .brand { font-size: 9pt; letter-spacing: .22em; text-transform: uppercase; color: #6b7488; }
.cover h1 { font-size: 30pt; margin: .35em 0 .1em; }
.cover .sub { font-size: 13pt; color: #47506a; }
.cover .meta { margin-top: 2.2em; font-size: 9.5pt; color: #6b7488; line-height: 1.9; }
.cover .rule { height: 4px; width: 68px; background: #c9a227; margin: 1.4em 0; }

.toc { page-break-after: always; }
.toc h2 { border: none; margin-top: 0; }
/* Room for two-digit numbers: at 1.2em, sections 10-19 printed as "0."-"9.". */
.toc ol { padding-left: 2.6em; }
.toc li { margin: .25em 0; }

.docfoot {
  position: fixed; bottom: 0; left: 0; right: 0;
  font-size: 7.5pt; color: #8891a4; border-top: 1px solid #e6eaf1;
  padding-top: 3px;
}
"""


def run(cmd, **kw):
    return subprocess.run(cmd, check=True, capture_output=True, text=True, **kw)


# What the cover states the guide is for. The PHP range is read from each package's own
# composer.json, so it cannot drift from what Composer enforces (the hand-made guide said
# "PHP 8.1+" while the packages required 8.4). The
# Magento line is the one FreeGift/README.md "Requirements" states; change both together.
MAGENTO = 'Magento 2.4.8 or 2.4.9'


def version(pkg_dir: Path, override: str | None = None) -> str:
    """The release this guide describes: --version if given, else the package CHANGELOG's top heading.

    The version comes from the CHANGELOG, never from `git describe`: a guide built before the
    release tag exists would otherwise print "nearest tag + unreleased changes".
    """
    if override:
        return override if override.startswith('v') else f'v{override}'
    changelog = pkg_dir / 'CHANGELOG.md'
    for line in changelog.read_text(encoding='utf-8').splitlines():
        m = re.match(r'^##\s+\[?v?(\d+\.\d+\.\d+)\]?', line)
        if m:
            return f'v{m.group(1)}'
    raise SystemExit(f'{pkg_dir.name}: no "## X.Y.Z" heading found in {changelog}; pass --version')


def php_range(pkg_dir: Path) -> str:
    """'~8.4.0 || ~8.5.0' -> 'PHP 8.4 or 8.5', from the package's composer.json."""
    constraint = json.loads((pkg_dir / 'composer.json').read_text())['require']['php']
    versions = re.findall(r'(\d+\.\d+)', constraint)
    if not versions:
        raise SystemExit(f'{pkg_dir.name}: cannot read a PHP version from "{constraint}"')
    return 'PHP ' + ' or '.join(dict.fromkeys(versions))


def markdown_to_html(md_path: Path) -> str:
    """Render one Markdown file to an HTML fragment, dropping its H1 (the cover carries it)."""
    out = run(['pandoc', '--from=gfm', '--to=html5', str(md_path)]).stdout
    # A relative link would be written into the PDF as an absolute file:// URI on the build
    # machine, which is dead for the reader and leaks a local path. Keep the text, drop the link.
    out = re.sub(r'<a href="(?!https?:|mailto:|#)[^"]*"[^>]*>(.*?)</a>', r'\1', out, flags=re.S)
    out = re.sub(r'^\s*<h1[^>]*>.*?</h1>\s*', '', out, count=1, flags=re.S)
    # A lead-in paragraph stays on the page with the image that follows it.
    return re.sub(r'<p>([^<]*:(?:</strong>)?)</p>(\s*<p><img)', r'<p class="keepnext">\1</p>\2', out)


def build_toc(body: str) -> str:
    """A contents list from the H2s actually present, so it cannot list a section that is gone."""
    items = re.findall(r'<h2[^>]*>(.*?)</h2>', body, flags=re.S)
    rows = ''.join(f'<li>{re.sub("<[^>]+>", "", i).strip()}</li>' for i in items)
    return f'<section class="toc"><h2>Contents</h2><ol>{rows}</ol></section>' if rows else ''


def build(pkg: str, title: str, ver: str) -> Path:
    pkg_dir = ROOT  # the package is this repository's root
    parts = []
    for name in ('README.md', 'FEATURES.md'):
        src = pkg_dir / name
        if src.exists():
            parts.append(markdown_to_html(src))
    if not parts:
        raise SystemExit(f'{pkg}: neither README.md nor FEATURES.md exists; nothing to build')
    body = '\n'.join(parts)

    # The page footer lives in the @page bottom margin box so body text can never run under it.
    footer_text = f"{title} \\00b7 {ver} \\00b7 \\a9 2026 Blue Fairy Development \\00b7 support@bluefairydevelopment.com"
    page = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>{html.escape(title)}</title>
<style>{CSS}</style>
<style>@page {{ @bottom-left {{ content: "{footer_text}"; font: 7.5pt -apple-system, Helvetica, Arial, sans-serif; color: #8891a4; border-top: 1px solid #e6eaf1; padding-top: 3px; width: 100%; vertical-align: top; }} }}</style></head><body>
<section class="cover">
  <div class="brand">Blue Fairy Development</div>
  <h1>{html.escape(title)}</h1>
  <div class="rule"></div>
  <div class="sub">Installation &amp; Usage Guide</div>
  <div class="meta">
    {html.escape(ver)} &nbsp;·&nbsp; {html.escape(MAGENTO)} &nbsp;·&nbsp; {html.escape(php_range(pkg_dir))}<br>
    Generated from this package's own README.md and FEATURES.md<br>
    © 2026 Blue Fairy Development &nbsp;·&nbsp; support@bluefairydevelopment.com
  </div>
</section>
{build_toc(body)}
{body}
</body></html>"""

    # Written INSIDE the package so that docs/images/... resolves relative to it, exactly as
    # it does for a reader of the Markdown. Writing it to a temp directory silently produces
    # a guide with every image missing.
    tmp_html = pkg_dir / '.guide-build.html'
    tmp_html.write_text(page, encoding='utf-8')
    out_pdf = pkg_dir / 'docs' / f'{pkg}Guide.pdf'
    out_pdf.parent.mkdir(parents=True, exist_ok=True)

    try:
        run([
            str(CHROME), '--headless=new', '--disable-gpu', '--no-sandbox',
            '--no-pdf-header-footer', '--virtual-time-budget=10000',
            f'--print-to-pdf={out_pdf}', tmp_html.as_uri(),
        ])
    finally:
        tmp_html.unlink(missing_ok=True)

    if not out_pdf.exists() or out_pdf.stat().st_size < 5000:
        raise SystemExit(f'{pkg}: the PDF was not produced, or is too small to be real')
    return out_pdf


def main() -> None:
    if not shutil.which('pandoc'):
        raise SystemExit('pandoc is required: brew install pandoc')
    if not CHROME.exists():
        raise SystemExit(f'Google Chrome not found at {CHROME}')

    args = sys.argv[1:]
    override = None
    if '--version' in args:
        i = args.index('--version')
        try:
            override = args[i + 1]
        except IndexError:
            raise SystemExit('--version needs a value, for example --version 1.2.0')
        del args[i:i + 2]
    wanted = args or list(PACKAGES)
    for pkg in wanted:
        if pkg not in PACKAGES:
            raise SystemExit(f'unknown package "{pkg}"; expected one of {", ".join(PACKAGES)}')
        pdf = build(pkg, PACKAGES[pkg], version(ROOT, override))
        print(f'{pkg}: {pdf.relative_to(ROOT)}  ({pdf.stat().st_size // 1024} KB)')


if __name__ == '__main__':
    main()
