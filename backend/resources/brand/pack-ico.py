"""Packs public/favicon.ico from the 48px brand PNG.

    python3 resources/brand/pack-ico.py

Run it after build-icons.cjs, which produces the source. Kept separate because
Chromium cannot write the ICO container and Pillow can, and neither can do the
other half.

The .ico still matters in 2026 even though every current browser prefers the SVG:
a bare request for /favicon.ico is what crawlers, feed readers, link unfurlers and
older clients make, and this file used to be zero bytes, which is worse than
absent (a 0-byte icon is served with 200 and renders as a broken image rather than
falling back).
"""

import pathlib

from PIL import Image

BRAND = pathlib.Path(__file__).parent
PUBLIC = BRAND.parent.parent / "public"

source = Image.open(BRAND.parent.parent / "public/brand/icon-48.png").convert("RGBA")

# 16 is the tab, 32 the bookmark bar and the taskbar, 48 the Windows desktop.
# Pillow downsamples from the 48px master for each entry in one container.
source.save(PUBLIC / "favicon.ico", format="ICO", sizes=[(16, 16), (32, 32), (48, 48)])

written = (PUBLIC / "favicon.ico").stat().st_size
print(f"wrote favicon.ico ({written} bytes, 16/32/48)")
