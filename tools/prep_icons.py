"""Prepare quick-link icons for the web.

The source art is 2048x2048 JPEG/PNG, 21 MB for the set, with inconsistent
amounts of white around the mark -- so at a fixed tile size some icons would
look large and others small. Each one is trimmed to its ink, padded back to a
square with a consistent margin, and written at 128px. The tile shows the art at 56px on
desktop and 34px on mobile, so 128px covers a 2x display with room to spare;
256px was 393 KB of eager icons for no visible gain.
"""
import os
from PIL import Image, ImageChops

SRC = "/Users/carsonandorf/Documents/ClaudeCode/icons-selected"
OUT = "/Users/carsonandorf/Documents/ClaudeCode/maizegdb-redesign/src/images/quicklinks"
SIZE = 128
MARGIN = 0.06        # share of the final tile left as white on each side

# Per-icon corrections. Trimming to the ink bounding box makes every icon the
# same *bounding* size, which is not the same as making them look the same size:
# art built from thin strokes with white between them reads much smaller than
# solid art at identical dimensions.
#
#   crop  fractions of the source to cut before trimming, (left, top, right,
#         bottom). Used where the source carries a caption that is not wanted
#         at tile size.
#   zoom  scale applied after fitting. Above 1.0 the art is allowed to run
#         closer to -- or past -- the tile edge, which is what sparse drawings
#         need to hold their own beside the dense ones.
TUNE = {
    # The source has "Pan-genes" set beneath the diagram. At 56px that caption
    # is an illegible smudge, and it steals about a quarter of the height from
    # the part that matters.
    "pan_genes": {"crop": (0.0, 0.0, 0.0, 0.28), "zoom": 1.10},

    # Three thin plants with wide gaps between them; the bounding box is full
    # but the ink is not.
    "pannad":    {"zoom": 1.28},

    # A hairline V with a dashed bar. Still the weakest of the set -- the source
    # needs a heavier stroke -- but this stops it reading as an empty tile.
    "qteller":   {"zoom": 1.45},

    # Fine strokes over a wide, mostly empty field.
    "new_genes": {"zoom": 1.12},
    "blast":     {"zoom": 1.10},
}

os.makedirs(OUT, exist_ok=True)

def trim(im):
    """Crop uniform white border away, whatever its exact shade."""
    rgb = im.convert("RGB")
    bg = Image.new("RGB", rgb.size, (255, 255, 255))
    diff = ImageChops.difference(rgb, bg)
    # tolerate JPEG noise near white
    diff = diff.point(lambda p: 255 if p > 12 else 0)
    box = diff.convert("L").getbbox()
    return im.crop(box) if box else im

rows = []
seen = {}
for name in sorted(os.listdir(SRC)):
    if not name.lower().endswith((".jpeg", ".jpg", ".png")):
        continue
    stem = os.path.splitext(name)[0]
    src = os.path.join(SRC, name)
    im = Image.open(src)
    before = os.path.getsize(src)

    tune = TUNE.get(stem, {})

    crop = tune.get("crop")
    if crop:
        w, h = im.size
        l, t, r, b = crop
        im = im.crop((int(w * l), int(h * t), int(w * (1 - r)), int(h * (1 - b))))

    im = trim(im).convert("RGB")

    # square canvas, mark centred, consistent breathing room
    inner = int(SIZE * (1 - 2 * MARGIN) * tune.get("zoom", 1.0))
    im.thumbnail((inner, inner), Image.LANCZOS)
    canvas = Image.new("RGB", (SIZE, SIZE), (255, 255, 255))
    canvas.paste(im, ((SIZE - im.width) // 2, (SIZE - im.height) // 2))

    dest = os.path.join(OUT, stem + ".png")
    canvas.save(dest, "PNG", optimize=True)
    after = os.path.getsize(dest)
    rows.append((stem, before, after))

total_before = sum(r[1] for r in rows)
total_after = sum(r[2] for r in rows)
print(f"{'ICON':<22}{'SOURCE':>10}{'WEB':>9}")
print("-" * 41)
for stem, b, a in rows:
    print(f"{stem:<22}{b/1024:>9.0f}K{a/1024:>8.0f}K")
print("-" * 41)
print(f"{'total':<22}{total_before/1024/1024:>8.1f}M{total_after/1024:>8.0f}K")
print(f"\n{len(rows)} icons -> {OUT}")
