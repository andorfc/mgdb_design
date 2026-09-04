#!/usr/bin/env python3
"""Extract the Bin Viewer's static data out of the legacy templates.

Two things the previous page held as hand-written markup are turned into data
here so the modern page can render them properly:

  bin geometry        templates/tools/bin_viewer-maps.bau is an HTML image map:
                      110 <area> rectangles over a 200x240 JPEG of the ten
                      chromosomes. Those rectangles *are* the idiogram -- one
                      per bin, plus one label box per chromosome. Parsed into
                      coordinates, they redraw as an SVG that scales, takes
                      keyboard focus, and can be shaded by real data.

  core bin markers    templates/tools/bin_viewer-content.bau holds ten hand
                      written <tbody> blocks, one per chromosome, of the core
                      markers that define each bin boundary. Parsed into rows
                      so the modern page can render one table component instead
                      of ten hand-formatted ones.

Both outputs go to src/data/bin_viewer/. Run this again only if the legacy
templates change; they have not been touched since 2014.
"""

import html
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "src/data/bin_viewer"

SRC = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else None


def strip_tags(value):
    value = re.sub(r"<br\s*/?>", " ", value, flags=re.I)
    value = re.sub(r"<[^>]+>", "", value)
    # Bauplan escapes literal parentheses as \( and \)
    value = value.replace("\\(", "(").replace("\\)", ")")
    return " ".join(html.unescape(value).split())


def first_link(cell):
    m = re.search(r'href="([^"]*)"', cell)
    if not m:
        return None
    href = m.group(1)
    # One marker link in the source is written relative
    # (data_center/locus?id=24942). It resolves by accident from /bin_viewer
    # and would break from anywhere else, so it is rooted here.
    if href and not href.startswith(("http://", "https://", "/", "#", "mailto:")):
        href = "/" + href
    return href


# --------------------------------------------------------------------------
# Geometry
# --------------------------------------------------------------------------

def parse_geometry(maps_bau):
    text = maps_bau.read_text(encoding="utf-8", errors="replace")
    block = re.search(r'<map NAME="data_map">(.*?)</map>', text, re.S)
    if not block:
        raise SystemExit("data_map not found in bin_viewer-maps.bau")

    chromosomes = {}
    for area in re.finditer(
        r'COORDS="(\d+),(\d+)\s+(\d+),(\d+)"\s+HREF="([^"]+)"\s+title="([^"]+)"',
        block.group(1),
    ):
        x1, y1, x2, y2 = (int(v) for v in area.groups()[:4])
        href, title = area.group(5), area.group(6)

        bin_href = re.search(r"bin=(\d+)&sub=(\d+)", href)
        chr_href = re.search(r"chrom=(\d+)$", href)

        if bin_href:
            chrom = int(bin_href.group(1))
            entry = chromosomes.setdefault(chrom, {"x1": x1, "x2": x2, "bins": [], "label": None})
            entry["bins"].append({
                "sub": int(bin_href.group(2)),
                "label": title.replace("Bin ", ""),
                "y1": y1,
                "y2": y2,
            })
        elif chr_href:
            chrom = int(chr_href.group(1))
            entry = chromosomes.setdefault(chrom, {"x1": x1, "x2": x2, "bins": [], "label": None})
            entry["label"] = {"y1": y1, "y2": y2}

    out = []
    for chrom in sorted(chromosomes):
        entry = chromosomes[chrom]
        bins = sorted(entry["bins"], key=lambda b: b["sub"])
        out.append({
            "chromosome": chrom,
            "x1": entry["x1"],
            "x2": entry["x2"],
            "top": min(b["y1"] for b in bins),
            "bottom": max(b["y2"] for b in bins),
            "label_y": entry["label"]["y1"] if entry["label"] else None,
            "bins": bins,
        })

    total = sum(len(c["bins"]) for c in out)
    # The bin system is defined as 100 segments; anything else means the parse
    # drifted or the source map changed.
    if total != 100:
        raise SystemExit("expected 100 bins, parsed %d" % total)

    return {"width": 200, "height": 248, "chromosomes": out}


# --------------------------------------------------------------------------
# Core bin markers
# --------------------------------------------------------------------------

def parse_markers(content_bau):
    text = content_bau.read_text(encoding="utf-8", errors="replace")

    chromosomes = []
    for body in re.finditer(r'<tbody id="chr(\d+)"[^>]*>(.*?)</tbody>', text, re.S):
        chrom = int(body.group(1))
        rows = []
        image = None
        download = None

        for tr in re.finditer(r"<tr\b([^>]*)>(.*?)</tr>", body.group(2), re.S):
            attrs, inner = tr.group(1), tr.group(2)
            cells = re.findall(r"<td\b([^>]*)>(.*?)</td>", inner, re.S)
            if not cells:
                continue

            joined = " ".join(c[1] for c in cells)

            # The closing rows of each tbody carry the chromosome image and the
            # sequence download link rather than a marker.
            img = re.search(r'<img[^>]+src="([^"]+)"', joined)
            link = re.search(r'href="(http[^"]*cbm[^"]*)"', joined)
            if img or link:
                if img:
                    image = img.group(1)
                if link:
                    download = link.group(1)
                continue

            first = strip_tags(cells[0][1])
            if first.lower() == "bin" or first == "":
                continue

            highlighted = "yellow" in attrs.lower()

            # A row can carry a red note cell in place of a start position.
            note = None
            for cattrs, cvalue in cells:
                if "red" in cattrs.lower():
                    note = strip_tags(cvalue)

            values = [strip_tags(c[1]) for c in cells]
            links = [first_link(c[1]) for c in cells]

            # The .00 bin of each chromosome has no core marker -- it is the
            # start of the chromosome -- and is written as
            #   <td>bin</td><td colspan=6></td><td>position</td>
            # with an occasional empty cell after it.
            if len(cells) in (3, 4) and "colspan" in cells[1][0]:
                row = {
                    "bin": values[0],
                    "marker": "", "marker_url": None,
                    "probe": "", "probe_url": None,
                    "type": "", "insert": "", "enzyme": "",
                    "sequence": "", "sequence_url": None,
                    "position": values[2],
                }
            elif len(cells) >= 8:
                row = {
                    "bin": values[0],
                    "marker": values[1], "marker_url": links[1],
                    "probe": values[2], "probe_url": links[2],
                    "type": values[3],
                    "insert": values[4],
                    "enzyme": values[5],
                    "sequence": values[6], "sequence_url": links[6],
                    "position": "" if note else values[7],
                }
            else:
                # Anything else is a shape this parser has not seen; fail loudly
                # rather than dropping a core marker silently.
                raise SystemExit(
                    "chr%d: unhandled row with %d cells: %r" % (chrom, len(cells), values)
                )

            row["note"] = note
            row["alternate"] = highlighted
            rows.append(row)

        chromosomes.append({
            "chromosome": chrom,
            "image": image,
            "download": download,
            "rows": rows,
        })

    chromosomes.sort(key=lambda c: c["chromosome"])
    return chromosomes


def main():
    if SRC is None:
        raise SystemExit("usage: gen_bin_data.py <directory holding the legacy .bau files>")

    geometry = parse_geometry(SRC / "bin_viewer-maps.bau")
    markers = parse_markers(SRC / "bin_viewer-content.bau")

    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "bin_geometry.json").write_text(json.dumps(geometry, indent=1), encoding="utf-8")
    (OUT / "core_bin_markers.json").write_text(json.dumps(markers, indent=1), encoding="utf-8")

    print("geometry : %d chromosomes, %d bins"
          % (len(geometry["chromosomes"]), sum(len(c["bins"]) for c in geometry["chromosomes"])))
    print("markers  : %d chromosomes, %d rows, %d notes, %d alternates"
          % (len(markers),
             sum(len(c["rows"]) for c in markers),
             sum(1 for c in markers for r in c["rows"] if r["note"]),
             sum(1 for c in markers for r in c["rows"] if r["alternate"])))
    missing = [c["chromosome"] for c in markers if not c["image"] or not c["download"]]
    if missing:
        print("chromosomes missing an image or download link:", missing)


if __name__ == "__main__":
    main()
