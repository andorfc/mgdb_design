#!/usr/bin/env python3
"""Build data/download_catalog.json for the guided genome file finder on /download.

The finder on /download is driven entirely by this one static JSON file: the page
fetches it once, and every assembly search, file-type filter and wget/curl snippet
is computed in the browser from it. Nothing else reads it.

The catalog is a crawl of the Apache directory index at download.maizegdb.org --
that host is `ftp.maizegdb.org/downloads/` under a second name, so the two are the
same tree and either hostname yields the same result.

Only *assembly* directories are indexed. The thematic collections that sit beside
them (All_gene_model_GFF/, SNPs_and_Maps/, Genomes/, Archive/, Tutorials/ ...) are
deliberately skipped -- they are cross-assembly groupings that the page links
directly, and folding them in would make "choose an assembly" meaningless.

Two things worth knowing before editing:

  * `bytes` is Apache's *rounded* size ("2.5K") expanded against 1024, not a true
    byte count -- the index is the only size source, and a HEAD per file would be
    3,000 extra requests. So `total_bytes` is an estimate and is presented as one.

  * The species for a wild relative is not derivable from its ID. `Cr-AUB069-...`
    only tells you the genus/species code `Cr`. The real name lives in the
    directory's own README ("... accession of Cymbopogon refractus (Barbed wire
    grass) draft assembly"), so unknown prefixes are resolved by reading it. The
    prior hand-built catalog left these as the bare two-letter code, which made
    21 wild relatives unsearchable by species name.

Usage:
    tools/gen_download_catalog.py [-o src/data/download_catalog.json] [--jobs 8]
    tools/gen_download_catalog.py --compare old.json   # diff against a previous run
"""

from __future__ import annotations

import argparse
import concurrent.futures
import datetime
import html
import json
import os
import re
import sys
import urllib.error
import urllib.request

SOURCE = "https://download.maizegdb.org/"
SCHEMA_VERSION = 1
USER_AGENT = "MaizeGDB-download-catalog/1.0 (+https://www.maizegdb.org/)"

# An assembly directory is either a modern MaizeGDB assembly ID or one of the three
# historical B73 RefGen releases, which predate the naming convention.
#
# The status token is not always bare REFERENCE/DRAFT -- PH207 ships as
# `Zm-PH207-REFERENCE_NS-UIUC_UMN-1.0` -- and a few assemblies omit it entirely
# (`Zm-B73_B_CHROMOSOME-MBSC-1.0`), so both forms are matched. The leading
# two-letter species code plus a trailing version is what makes a directory an
# assembly; the thematic collections beside them (Pan-genes, SNPs_and_Maps,
# All_gene_model_GFF) match neither pattern.
RE_ASSEMBLY = re.compile(
    r"^(?P<prefix>[A-Z][a-z])-(?P<name>.+)-(?P<status>REFERENCE|DRAFT)(?:_[A-Z]+)?"
    r"-(?P<project>.+)-(?P<version>\d+(?:\.\d+)*)$"
)
RE_ASSEMBLY_NO_STATUS = re.compile(
    r"^(?P<prefix>[A-Z][a-z])-(?P<name>[^-]+)-(?P<project>[^-]+)-(?P<version>\d+(?:\.\d+)*)$"
)
RE_LEGACY_B73 = re.compile(r"^B73_RefGen_v(?P<version>\d+)$")

# `_Zm00001ab.1.` -- the annotation release a file belongs to. Used to keep the
# recommended list to one file per role instead of one per annotation version.
RE_ANNOTATION = re.compile(r"_([A-Z][a-z]\d{5}[a-z]{2})\.(\d+)\.")

# Apache index row: link, then last-modified, then size.
RE_ROW = re.compile(
    r'<a href="(?P<href>[^"?/][^"]*)"[^>]*>.*?</a>\s*'
    r"(?P<modified>\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s+"
    r"(?P<size>[\d.]+[KMGT]?|-)",
    re.IGNORECASE | re.DOTALL,
)

# Species codes seen in assembly IDs. Anything absent is read from the README.
SPECIES_BY_PREFIX = {
    "Zm": "Zea mays",
    "Zd": "Zea diploperennis",
    "Zh": "Zea mays ssp. huehuetenangensis",
    "Zl": "Zea luxurians",
    "Zn": "Zea nicaraguensis",
    "Zv": "Zea mays ssp. parviglumis",
    "Zx": "Zea mays ssp. mexicana",
    "Td": "Tripsacum dactyloides",
    "Av": "Anatherum virginicum",
}

# "... accession of Cymbopogon refractus (Barbed wire grass) draft assembly".
# Matched against whitespace-collapsed text, because the name routinely wraps
# across the README's fixed-width lines ("Andropogon \nburmanicus"). Exactly two
# tokens are taken -- genus and epithet -- so the trailing "draft assembly" does
# not become part of the species name. Sub-species (Zea mays ssp. mexicana) come
# from SPECIES_BY_PREFIX, never from here.
RE_README_SPECIES = re.compile(
    r"accession\s+of\s+(?P<genus>[A-Z][a-z]+)\s+(?P<epithet>[a-z][a-z-]+)"
)
RE_README_STOPWORD = re.compile(
    r"^(draft|assembly|and|the|reference|genome|annotation|accession)$"
)


def fetch(url: str, timeout: int = 60) -> str:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read().decode("utf-8", errors="replace")


def size_to_bytes(size: str) -> int:
    """Expand Apache's rounded size against 1024. '-' and junk become 0."""
    size = (size or "").strip()
    if not size or size == "-":
        return 0
    scale = {"K": 1024, "M": 1024 ** 2, "G": 1024 ** 3, "T": 1024 ** 4}
    suffix = size[-1].upper()
    try:
        if suffix in scale:
            return int(round(float(size[:-1]) * scale[suffix]))
        return int(float(size))
    except ValueError:
        return 0


def parse_index(body: str) -> list[dict]:
    """Rows of an Apache directory index, excluding the parent link."""
    entries = []
    for match in RE_ROW.finditer(body):
        href = html.unescape(match.group("href"))
        if href.startswith("..") or href.startswith("?"):
            continue
        entries.append(
            {
                "href": href,
                "name": href.rstrip("/"),
                "is_dir": href.endswith("/"),
                "modified": match.group("modified"),
                "size": match.group("size").strip(),
            }
        )
    return entries


# --- file classification -------------------------------------------------------
#
# Role, human label, sort priority, and whether the file is shown before the user
# turns off "recommended only". Roughly: one obvious file per biological question,
# with variants and machine-support files behind the toggle.

def classify(filename: str, assembly_id: str) -> tuple[str, str, int, bool]:
    name = filename
    low = name.lower()
    canonical = ".canonical." in low
    noncoding = ".nc." in low

    if low.endswith(".readme") or "readme" in low:
        return "documentation", "README / usage notes", 5, name == assembly_id + ".README"
    if low.endswith(".metadata"):
        return "metadata", "Assembly metadata", 8, False
    if low.endswith(".md5"):
        return "checksum", "Checksums", 9, True
    if re.search(r"\.(fai|gzi|tbi|csi)$", low):
        if canonical:
            return "index", "Canonical sequence or tabix index", 69, False
        return "index", "Sequence or tabix index", 70, False
    if re.search(r"\.te\.gff3?", low) or "edta" in low or "repeat" in low:
        return "transposable_elements", "Transposable-element annotation", 35, False
    if "xref" in low:
        return "cross_reference", "Gene-model cross references", 40, False

    # Annotation. Non-coding gene sets are a specialized track, not the main GFF.
    if re.search(r"\.gff3?(\.gz)?$", low):
        if noncoding or "helixer" in low:
            return "special_annotation", "Specialized annotation", 55, False
        return "gene_annotation", "Gene annotation", 15, True

    # Sequence sets. `.nc.` variants keep their biological role but stay behind
    # the toggle, matching how the previous catalog presented them.
    # Sequence sets. The type marker is matched anywhere in the name because
    # Ensembl's v3 files spell it differently (`.pep.all.fa.gz`,
    # `.cdna.all.fa.gz`) -- but only for actual FASTA, or `cds_sauron.csv.gz`
    # (an analysis table) would be read as coding sequence.
    is_fasta = bool(re.search(r"\.(fa|fasta)(\.gz)?$", low))
    if is_fasta:
        if re.search(r"[._](protein|pep)[._]", low):
            if canonical:
                return "protein", "Canonical protein sequences", 24, True
            return "protein", "Protein sequences", 25, not noncoding
        if re.search(r"[._]cds[._]", low):
            if canonical:
                return "cds", "Canonical coding sequences", 20, True
            return "cds", "Coding sequences", 21, not noncoding
        if re.search(r"[._](cdna|transcripts?)[._]", low):
            if canonical:
                return "transcript", "Canonical transcript sequences", 21, True
            return "transcript", "Transcript sequences", 22, not noncoding
        if re.search(r"[._]gene[._]", low):
            return "gene_sequence", "Gene genomic sequences", 28, False

    # Whole-genome sequence. Only the assembly's own FASTA is recommended; the
    # per-chromosome and gene-region cuts are variants.
    if is_fasta:
        primary = low in (assembly_id.lower() + ".fa.gz", assembly_id.lower() + ".fa")
        return "assembly", "Genome assembly FASTA", 10, primary

    if re.search(r"\.(tsv|csv|txt|tab)(\.gz)?$", low):
        if canonical:
            return "table", "Canonical annotation or analysis table", 49, False
        return "table", "Annotation or analysis table", 50, False

    if canonical:
        return "other", "Canonical supporting file", 89, False
    return "other", "Supporting file", 90, False


def describe_assembly(directory: str) -> dict | None:
    """Metadata implied by an assembly directory name, or None if it is not one."""
    match = RE_ASSEMBLY.match(directory)
    if match:
        return {
            "id": directory,
            "prefix": match.group("prefix"),
            "name": match.group("name").replace("_", " "),
            "species": SPECIES_BY_PREFIX.get(match.group("prefix"), ""),
            "status": match.group("status").capitalize(),
            "project": match.group("project").replace("_", " "),
            "version": match.group("version"),
            "legacy": False,
        }
    match = RE_LEGACY_B73.match(directory)
    if match:
        return {
            "id": directory,
            "prefix": "Zm",
            "name": "B73",
            "species": "Zea mays",
            "status": "Reference",
            "project": "RefGen",
            "version": match.group("version"),
            "legacy": True,
        }
    match = RE_ASSEMBLY_NO_STATUS.match(directory)
    if match:
        return {
            "id": directory,
            "prefix": match.group("prefix"),
            "name": match.group("name").replace("_", " "),
            "species": SPECIES_BY_PREFIX.get(match.group("prefix"), ""),
            "status": "Reference",
            "project": match.group("project").replace("_", " "),
            "version": match.group("version"),
            "legacy": False,
        }
    return None


def demote_superseded(files: list[dict]) -> None:
    """Keep the recommended list to one file per role.

    Two things crowd it otherwise:

    * Several assemblies carry two or three annotation releases side by side
      (`_Zm00001aa.1`, `_Zm00001ab.1`). Recommending every one shows the same
      question answered three times, so only the newest release stays.

    * Files that carry no annotation token at all are, in a role where the
      assembly's own annotation exists, something else wearing the same label --
      `conservatoryV10.ZmB73NAM5.gff` is a cross-species conservation track, not
      B73's gene annotation, and sat beside the real GFF as an equal.

    Both stay in the list; they are just behind the "recommended only" toggle.
    """
    newest: dict[str, tuple[str, int]] = {}
    for entry in files:
        match = RE_ANNOTATION.search(entry["name"])
        if not (match and entry["recommended"]):
            continue
        key = (match.group(1), int(match.group(2)))
        if key > newest.get(entry["role"], ("", -1)):
            newest[entry["role"]] = key

    for entry in files:
        if not entry["recommended"] or entry["role"] not in newest:
            continue
        match = RE_ANNOTATION.search(entry["name"])
        if not match:
            entry["recommended"] = False
        elif (match.group(1), int(match.group(2))) < newest[entry["role"]]:
            entry["recommended"] = False


def species_from_readme(assembly_id: str, filenames: list[str]) -> str:
    """Read the directory's README for a wild relative's real species name."""
    readme = assembly_id + ".README"
    if readme not in filenames:
        candidates = [f for f in filenames if f.lower().endswith(".readme")]
        if not candidates:
            return ""
        readme = candidates[0]
    try:
        text = fetch(SOURCE + assembly_id + "/" + readme, timeout=30)
    except (urllib.error.URLError, OSError, TimeoutError):
        return ""
    match = RE_README_SPECIES.search(re.sub(r"\s+", " ", text))
    if not match:
        return ""
    if RE_README_STOPWORD.match(match.group("epithet")):
        return ""
    return f"{match.group('genus')} {match.group('epithet')}"


def build_assembly(meta: dict, modified: str) -> dict:
    assembly_id = meta["id"]
    url = SOURCE + assembly_id + "/"
    rows = [row for row in parse_index(fetch(url)) if not row["is_dir"]]

    files = []
    for row in rows:
        role, label, priority, recommended = classify(row["name"], assembly_id)
        files.append(
            {
                "name": row["name"],
                "url": url + row["href"],
                "modified": row["modified"],
                "size": row["size"],
                "bytes": size_to_bytes(row["size"]),
                "role": role,
                "label": label,
                "priority": priority,
                "recommended": recommended,
                "tags": [],
            }
        )
    demote_superseded(files)
    files.sort(key=lambda f: (f["priority"], f["name"]))

    species = meta["species"] or species_from_readme(assembly_id, [f["name"] for f in files])

    return {
        "id": assembly_id,
        "name": meta["name"],
        "species": species or meta["prefix"],
        "status": meta["status"],
        "project": meta["project"],
        "version": meta["version"],
        "legacy": meta["legacy"],
        "url": url,
        "modified": modified,
        "file_count": len(files),
        "total_bytes": sum(f["bytes"] for f in files),
        "files": files,
    }


def build_catalog(jobs: int) -> dict:
    root = parse_index(fetch(SOURCE))
    targets = []
    for row in root:
        if not row["is_dir"]:
            continue
        meta = describe_assembly(row["name"])
        if meta:
            targets.append((meta, row["modified"]))

    print(f"{len(targets)} assembly directories to index", file=sys.stderr)

    assemblies, warnings = [], 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=jobs) as pool:
        futures = {pool.submit(build_assembly, meta, mod): meta for meta, mod in targets}
        for done in concurrent.futures.as_completed(futures):
            meta = futures[done]
            try:
                assemblies.append(done.result())
            except Exception as error:  # noqa: BLE001 - one bad directory must not stop the crawl
                warnings += 1
                print(f"  warning: {meta['id']}: {error}", file=sys.stderr)

    assemblies.sort(key=lambda a: (a["name"].lower(), a["id"]))
    generated = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)

    roles: dict[str, int] = {}
    for assembly in assemblies:
        for entry in assembly["files"]:
            roles[entry["role"]] = roles.get(entry["role"], 0) + 1

    return {
        "schema_version": SCHEMA_VERSION,
        "generated_at": generated.isoformat().replace("+00:00", "+00:00"),
        "source": SOURCE,
        "assembly_count": len(assemblies),
        "file_count": sum(a["file_count"] for a in assemblies),
        "total_bytes": sum(a["total_bytes"] for a in assemblies),
        "warning_count": warnings,
        "roles": roles,
        "assemblies": assemblies,
    }


def compare(new: dict, old_path: str) -> None:
    """Report how a fresh crawl differs from a previous catalog."""
    with open(old_path, encoding="utf-8") as handle:
        old = json.load(handle)

    new_by_id = {a["id"]: a for a in new["assemblies"]}
    old_by_id = {a["id"]: a for a in old["assemblies"]}

    added = sorted(set(new_by_id) - set(old_by_id))
    removed = sorted(set(old_by_id) - set(new_by_id))
    print(f"assemblies: {len(old_by_id)} -> {len(new_by_id)}")
    for entry in added:
        print(f"  + {entry}")
    for entry in removed:
        print(f"  - {entry}")

    role_changes, rec_changes, checked = 0, 0, 0
    for assembly_id in sorted(set(new_by_id) & set(old_by_id)):
        old_files = {f["name"]: f for f in old_by_id[assembly_id]["files"]}
        for entry in new_by_id[assembly_id]["files"]:
            previous = old_files.get(entry["name"])
            if not previous:
                continue
            checked += 1
            if previous["role"] != entry["role"]:
                role_changes += 1
                if role_changes <= 25:
                    print(f"  role  {entry['name']}: {previous['role']} -> {entry['role']}")
            elif previous["recommended"] != entry["recommended"]:
                rec_changes += 1
                if rec_changes <= 15:
                    print(
                        f"  rec   {entry['name']}: "
                        f"{previous['recommended']} -> {entry['recommended']}"
                    )
    print(f"\n{checked} shared files compared: {role_changes} role changes, "
          f"{rec_changes} recommendation changes")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("-o", "--output", default="src/data/download_catalog.json")
    parser.add_argument("--jobs", type=int, default=8)
    parser.add_argument("--compare", help="diff the fresh crawl against this catalog")
    args = parser.parse_args()

    catalog = build_catalog(args.jobs)

    if args.compare:
        compare(catalog, args.compare)

    os.makedirs(os.path.dirname(os.path.abspath(args.output)), exist_ok=True)
    with open(args.output, "w", encoding="utf-8") as handle:
        json.dump(catalog, handle, separators=(",", ":"))
        handle.write("\n")

    print(
        f"wrote {args.output}: {catalog['assembly_count']} assemblies, "
        f"{catalog['file_count']} files, {catalog['warning_count']} warnings",
        file=sys.stderr,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
