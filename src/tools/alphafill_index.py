#!/usr/bin/env python3
"""Build data/alphafill/ -- the payload behind /data_center/alphafill.

    python3 tools/alphafill_index.py \
        --source=<AlphaFill proteome outputs> \
        --models=<model_index.json from alphafill_models.py> \
        --gff=<Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.gff3.gz> \
        --ccd=<ccd.json> --meta=<meta/ from alphafill_ligand_extract.py> \
        --dest=<webroot>/data/alphafill

Unlike tools/protein_structure_index.php this runs on the workstation, not the
server, because its source is a one-off research output (six CSVs, 200 MB) that
has never lived on the web host. The result ships as a single tarball; see
README, "Rebuilding the AlphaFill payload".

What it writes
--------------
  manifest.json          counts and provenance; the controller reads this
  stats.json             the dashboard tables (hit rate by pLDDT band, tiers)
  index.json             one compact row per gene with a transplant  (tier 1)
  genes/<xxx>.json       full gene payload, sha1-sharded 4,096 ways  (tier 2)
  ligands/<xx>.json      per-CCD summary, sha1-sharded 256 ways
  ligand_genes/<CCD>.json  the genes predicted to bind one ligand
  detail/<xxx>.json      every raw transplant, by protein            (tier 3a)
                         built from the cluster's slim meta when --meta is
                         given, because only that carries asym_id and the
                         pocket residues; from the CSV otherwise
  pockets/<xxx>.json     P2Rank pockets, residues, genomic projection
  targets.json           the confident-pocket / no-donor target list
  benchmark.json         the solved-maize-structure validation cases
  routing.json + suggest/ + top/   the typeahead index

Three things here are easy to get wrong
---------------------------------------
1. **A gene's transplants may not be on its canonical isoform.** 3,216 of the
   16,933 genes carry their transplants on a non-canonical protein, and 642
   genes have no canonical-isoform hit at all. The model, the residue numbering
   and the ligand coordinates all belong to `prot`, so `prot` is what the viewer
   must load -- never the canonical isoform the gene page would otherwise show.
   The payload carries both and the UI says which it is showing.

2. **"No qualifying donor" is not "no ligand", and both must be reachable.**
   Every canonical gene goes into genes/, including the 21,427 with nothing
   transplanted, so a lookup can answer with the right one of three states
   rather than an empty result. The third state -- no model at all -- is real:
   protein-coding genes in the v5 GFF that AlphaFill never saw.

3. **P2Rank's nearest_transplant_comp is not "the ligand in this pocket".**
   It is the nearest transplant at any distance; the median is 12.4 A. Joining
   on it without a cutoff labels thousands of pockets with a compound 30 A away.
   POCKET_LIGAND_MAX_A applies the cutoff, and the count that survives it
   (5,152 of 132,378) is reported in the manifest so the claim stays auditable.
"""

import argparse
import csv
import math
import gzip
import hashlib
import json
import os
import re
import shutil
import sys
import time
from collections import Counter, defaultdict

csv.field_size_limit(1 << 24)

# --------------------------------------------------------------------------- #
# Tunables
# --------------------------------------------------------------------------- #

# Matches tools/protein_structure_index.php so the two typeaheads behave the
# same way. See that file for why an n-gram index cannot work on this corpus.
SHARD_CAP = 400
SHARD_MIN_DEPTH = 3
SHARD_MAX_DEPTH = 14
TOP_ROWS = 25

# Gene, transplant and pocket payloads are far larger per key than the protein
# structure records, so they shard three characters deep (4,096 ways) rather
# than two. A gene lookup reads ~4 KB instead of ~60 KB.
KEY_DEPTH = 3

# How close a P2Rank pocket centre has to be to a transplant before the pocket
# is called ligand-supported. See note 3 above.
POCKET_LIGAND_MAX_A = 5.0

# The published AlphaFill confidence bands, restated here so stats.json can be
# rebuilt without reading the report.
BAND_LRMSD = (0.92, 3.10)
BAND_TCS = (0.64, 1.27)

EVIDENCE_ORDER = ["strong", "moderate", "ion", "weak", "additive"]

GENE_RE = re.compile(r"^Zm\d+[a-z]+\d+$", re.I)
CCD_RE = re.compile(r"^[A-Z0-9]{1,5}$")


# --------------------------------------------------------------------------- #
# Small helpers
# --------------------------------------------------------------------------- #

def say(message):
    if not ARGS.quiet:
        sys.stdout.write(message + "\n")
        sys.stdout.flush()


def shard_key(value, depth=KEY_DEPTH):
    return hashlib.sha1(value.lower().encode()).hexdigest()[:depth]


def finite(value):
    """None for anything that is not a real number.

    Python writes float('nan') as a bare NaN, which is not JSON. Python's own
    json.loads accepts it back, so a NaN round-trips inside this tool and looks
    fine -- but PHP's json_decode and the browser's JSON.parse both reject the
    whole file, and a shard is thousands of genes. The upstream
    alphafill.by_gene.json has 1,259 of them, which silently broke a quarter of
    the gene shards before this existed.
    """
    if isinstance(value, float) and not math.isfinite(value):
        return None
    return value


def scrub(value):
    if isinstance(value, dict):
        return {k: scrub(v) for k, v in value.items()}
    if isinstance(value, list):
        return [scrub(v) for v in value]
    return finite(value)


def write_json(path, payload):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    # allow_nan=False turns a stray non-finite into a build failure rather than
    # a file that only some parsers reject. scrub() means it should never fire.
    blob = json.dumps(scrub(payload), separators=(",", ":"),
                      ensure_ascii=False, allow_nan=False)
    with open(path, "w") as handle:
        handle.write(blob)
    return len(blob)


def reset_dir(path):
    if os.path.isdir(path):
        shutil.rmtree(path)
    os.makedirs(path, exist_ok=True)


def as_float(value):
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        return None
    return parsed if math.isfinite(parsed) else None


def rounded(value, digits):
    return None if value is None else round(value, digits)


def tier(value, bands):
    """0 high, 1 medium, 2 low -- the published AlphaFill banding."""
    if value is None:
        return None
    return 0 if value < bands[0] else (1 if value <= bands[1] else 2)


# --------------------------------------------------------------------------- #
# 1. Sources
# --------------------------------------------------------------------------- #

def load_by_gene(source):
    """The collapsed gene x ligand table. This is the published classification;
    the evidence classes are not recomputed here so the site can never disagree
    with the report the numbers were published in."""
    with open(os.path.join(source, "alphafill.by_gene.json")) as handle:
        return json.load(handle)


def load_protein_summary(source):
    summary, canonical = {}, {}
    with open(os.path.join(source, "proteome_protein_summary.csv")) as handle:
        for row in csv.DictReader(handle):
            summary[row["protein"]] = row
            if row["is_canonical"] == "1":
                canonical.setdefault(row["gene"], row["protein"])
    return summary, canonical


def load_coverage_gap(source):
    gap = {}
    with open(os.path.join(source, "proteome_coverage_gap_full.csv")) as handle:
        for row in csv.DictReader(handle):
            gap[row["gene"]] = row
    return gap


def load_ccd(path):
    if not path or not os.path.isfile(path):
        say("  no CCD dictionary supplied -- ligand names will be codes only")
        return {}
    with open(path) as handle:
        return json.load(handle)


# --------------------------------------------------------------------------- #
# 2. Genomic projection of pocket residues
#
# residue p -> coding nucleotides [(p-1)*3, (p-1)*3+2], walked across the CDS
# segments in translation order. This is the same projection the Pfam envelopes
# use, so a ligand pocket becomes a genome-browser track exactly like a domain
# and a variant falling in one is queryable.
# --------------------------------------------------------------------------- #

def load_cds(gff_path):
    """protein_id -> (chrom, strand, [(start, end), ...] in translation order)."""
    if not gff_path or not os.path.isfile(gff_path):
        say("  no GFF supplied -- skipping the genomic projection")
        return {}, {}

    segments = defaultdict(list)
    gene_span = {}
    opener = gzip.open if gff_path.endswith(".gz") else open
    with opener(gff_path, "rt") as handle:
        for line in handle:
            if line.startswith("#"):
                continue
            field = line.rstrip("\n").split("\t")
            if len(field) < 9:
                continue
            if field[2] == "gene":
                match = re.search(r"ID=([^;]+)", field[8])
                if match:
                    gene_span[match.group(1)] = (field[0], int(field[3]),
                                                 int(field[4]), field[6])
                continue
            if field[2] != "CDS":
                continue
            match = re.search(r"protein_id=([^;]+)", field[8])
            if not match:
                continue
            phase = 0 if field[7] in (".", "") else int(field[7])
            segments[match.group(1)].append(
                (field[0], field[6], int(field[3]), int(field[4]), phase))

    cds = {}
    for protein, parts in segments.items():
        chrom, strand = parts[0][0], parts[0][1]
        # Translation order: ascending on +, descending on -.
        parts.sort(key=lambda p: p[2], reverse=(strand == "-"))
        cds[protein] = (chrom, strand,
                        [(p[2], p[3]) for p in parts], parts[0][4])
    return cds, gene_span


def project_residues(entry, residues):
    """Residue numbers -> merged genomic blocks. Returns [] if the residue set
    runs past the CDS, which is the honest answer for a model whose sequence and
    whose annotation have drifted apart."""
    chrom, strand, spans, phase = entry
    lengths = [end - start + 1 for start, end in spans]
    total = sum(lengths) - phase
    blocks = []

    for residue in residues:
        lo = (residue - 1) * 3 + phase
        hi = lo + 2
        if hi >= total + phase:
            continue
        # Walk the coding offsets to genomic positions.
        picked = []
        for coding in (lo, hi):
            walked = 0
            for index, (start, end) in enumerate(spans):
                length = lengths[index]
                if walked + length > coding:
                    within = coding - walked
                    picked.append(end - within if strand == "-" else start + within)
                    break
                walked += length
        if len(picked) == 2:
            blocks.append((min(picked), max(picked)))

    if not blocks:
        return []
    blocks.sort()
    merged = [list(blocks[0])]
    for start, end in blocks[1:]:
        if start <= merged[-1][1] + 1:
            merged[-1][1] = max(merged[-1][1], end)
        else:
            merged.append([start, end])
    return merged


# --------------------------------------------------------------------------- #
# 3. Typeahead index (the adaptive prefix split)
# --------------------------------------------------------------------------- #

def build_suggest(rows, keys_by_row, dest):
    postings = []
    for row_index, keys in keys_by_row.items():
        for key in keys:
            postings.append((key, row_index))
    postings.sort(key=lambda p: (p[0], -rows[p[1]]["w"], rows[p[1]]["l"]))
    say("  built %s postings" % f"{len(postings):,}")

    hot, shards = {}, {}
    pending = {"": postings}
    for depth in range(SHARD_MIN_DEPTH, SHARD_MAX_DEPTH + 1):
        nxt = {}
        for group in pending.values():
            buckets = defaultdict(list)
            for posting in group:
                if len(posting[0]) < depth:
                    continue
                buckets[posting[0][:depth]].append(posting)
            for prefix, bucket in buckets.items():
                if len(bucket) > SHARD_CAP and depth < SHARD_MAX_DEPTH:
                    hot[prefix] = True
                    nxt[prefix] = bucket
                else:
                    shards[prefix] = bucket
        if not nxt:
            break
        pending = nxt
    for prefix, bucket in pending.items():
        shards.setdefault(prefix, bucket)
        hot.pop(prefix, None)
    say("  split into %s shards, %s hot prefixes"
        % (f"{len(shards):,}", f"{len(hot):,}"))

    reset_dir(os.path.join(dest, "suggest"))
    reset_dir(os.path.join(dest, "top"))

    largest = 0
    for prefix, bucket in shards.items():
        largest = max(largest, len(bucket))
        payload = []
        for key, row_index in bucket:
            row = dict(rows[row_index])
            row["t"] = key
            payload.append(row)
        write_json(os.path.join(dest, "suggest", prefix + ".json"), payload)

    short = set()
    for key, _ in postings:
        for length in range(2, SHARD_MIN_DEPTH):
            if len(key) >= length:
                short.add(key[:length])
    answer_prefixes = list(hot.keys()) + list(short)

    def lower_bound(prefix):
        low, high = 0, len(postings)
        while low < high:
            mid = (low + high) >> 1
            if postings[mid][0] < prefix:
                low = mid + 1
            else:
                high = mid
        return low

    for prefix in answer_prefixes:
        matched = {}
        cursor = lower_bound(prefix)
        while cursor < len(postings) and postings[cursor][0].startswith(prefix):
            key, row_index = postings[cursor]
            if row_index not in matched or len(key) < len(matched[row_index]):
                matched[row_index] = key
            cursor += 1
        # Keep the best of each kind so a mixed answer never has to rank a gene
        # against a ligand -- two different things with two different weights.
        by_kind = defaultdict(list)
        for row_index, key in matched.items():
            by_kind[rows[row_index]["y"]].append(row_index)
        answer = []
        for kind, candidates in by_kind.items():
            candidates.sort(key=lambda r: (matched[r] != prefix,
                                           -rows[r]["w"], rows[r]["l"]))
            for row_index in candidates[:TOP_ROWS]:
                row = dict(rows[row_index])
                row["t"] = matched[row_index]
                answer.append(row)
        write_json(os.path.join(dest, "top", prefix + ".json"), answer)

    say("  wrote %s precomputed answers" % f"{len(answer_prefixes):,}")
    write_json(os.path.join(dest, "routing.json"), {
        "min_depth": SHARD_MIN_DEPTH,
        "max_depth": SHARD_MAX_DEPTH,
        "cap": SHARD_CAP,
        "hot": sorted(hot.keys()),
    })
    return {"shards": len(shards), "hot": len(hot), "largest": largest,
            "postings": len(postings)}


# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #

def main():
    started = time.time()
    source, dest = ARGS.source.rstrip("/"), ARGS.dest.rstrip("/")
    os.makedirs(dest, exist_ok=True)

    say("reading sources")
    by_gene = load_by_gene(source)
    summary, canonical = load_protein_summary(source)
    gap = load_coverage_gap(source)
    ccd_names = load_ccd(ARGS.ccd)
    models = {}
    if ARGS.models and os.path.isfile(ARGS.models):
        with open(ARGS.models) as handle:
            models = json.load(handle)
    model_plddt = {}
    if ARGS.plddt and os.path.isfile(ARGS.plddt):
        with open(ARGS.plddt) as handle:
            model_plddt = json.load(handle)
    cds, gene_span = load_cds(ARGS.gff)
    say("  %s genes with transplants, %s proteins, %s CCD names, %s models, %s CDS"
        % (f"{len(by_gene):,}", f"{len(summary):,}", f"{len(ccd_names):,}",
           f"{len(models):,}", f"{len(cds):,}"))

    # ---------------------------------------------------------------- pockets
    say("reading P2Rank pockets")
    pockets_by_protein = defaultdict(list)
    pocket_rows = 0
    pocket_ligand_hits = 0
    for row in csv.DictReader(open(os.path.join(source, "p2rank_pockets.csv"))):
        pocket_rows += 1
        residues = []
        for token in row["residue_ids"].split():
            _, _, number = token.partition("_")
            if number.isdigit():
                residues.append(int(number))
        residues.sort()
        distance = as_float(row["nearest_transplant_dist"])
        near = row["nearest_transplant_comp"] or None
        # The cutoff. Without it this field means "closest transplant anywhere".
        supported = bool(near and distance is not None
                         and distance <= POCKET_LIGAND_MAX_A)
        if supported:
            pocket_ligand_hits += 1
        pockets_by_protein[row["protein"]].append({
            "p": row["pocket"],
            "r": int(row["rank"]),
            "pr": rounded(as_float(row["probability"]), 3),
            "sc": rounded(as_float(row["score"]), 2),
            "pl": rounded(as_float(row["pocket_plddt"]), 1),
            "cf": 1 if row["confident"] == "1" else 0,
            "res": residues,
            "lig": near if supported else None,
            "d": rounded(distance, 1) if supported else None,
        })
    say("  %s pockets over %s proteins; %s within %.0f A of a transplant"
        % (f"{pocket_rows:,}", f"{len(pockets_by_protein):,}",
           f"{pocket_ligand_hits:,}", POCKET_LIGAND_MAX_A))

    # ------------------------------------------------------- genomic blocks
    projected, projection_failed = 0, 0
    if cds:
        say("projecting pocket residues to genomic coordinates")
        for protein, entries in pockets_by_protein.items():
            entry = cds.get(protein)
            if not entry:
                projection_failed += 1
                continue
            for pocket in entries:
                blocks = project_residues(entry, pocket["res"])
                if blocks:
                    pocket["gb"] = blocks
                    projected += 1
        say("  projected %s pockets; %s proteins had no CDS in the GFF"
            % (f"{projected:,}", f"{projection_failed:,}"))

    # ------------------------------------------------------------ transplants
    #
    # Two sources for the same 624,456 rows. The CSV is one fast pass and is
    # what the proteome statistics are computed from. The cluster's slim meta
    # additionally carries asym_id -- which is the only thing that maps a
    # transplant card to a ligand inside the coordinates file -- and the pocket
    # residues AlphaFill already computed in its clash block. When it is
    # available it wins, because without asym_id the viewer cannot colour or
    # toggle an individual transplant at all.
    say("reading raw transplants")
    detail = defaultdict(list)
    transplant_rows = 0
    tier_lrmsd, tier_tcs = Counter(), Counter()
    class_counts = Counter()
    identities = []
    for row in csv.DictReader(open(os.path.join(source, "proteome_transplants.csv"))):
        transplant_rows += 1
        identity = as_float(row["identity"])
        local = as_float(row["local_rmsd"])
        clash = as_float(row["tcs"])
        tier_lrmsd[tier(local, BAND_LRMSD)] += 1
        tier_tcs[tier(clash, BAND_TCS)] += 1
        class_counts[row["ligand_class"]] += 1
        if identity is not None:
            identities.append(identity)
        detail[row["protein"]].append([
            row["donor_pdb_id"],
            row["ligand_ccd"],
            row["donor_ccd"],
            rounded(identity, 4),
            int(row["alignment_length"] or 0),
            rounded(as_float(row["global_rmsd"]), 2),
            rounded(local, 3),
            int(row["clash_count"] or 0),
            rounded(clash, 3),
            row["ligand_class"],
        ])
    identities.sort()
    say("  %s transplants over %s proteins"
        % (f"{transplant_rows:,}", f"{len(detail):,}"))

    meta_proteins = 0
    meta_no_coords = 0
    lig_bucket = {}
    if ARGS.meta and os.path.isdir(ARGS.meta):
        say("merging cluster metadata (asym ids and pocket residues)")
        detail = {}
        for bucket in sorted(os.listdir(ARGS.meta)):
            bucket_dir = os.path.join(ARGS.meta, bucket)
            if not os.path.isdir(bucket_dir):
                continue
            for name in os.listdir(bucket_dir):
                if not name.endswith(".json"):
                    continue
                with open(os.path.join(bucket_dir, name)) as handle:
                    entry = json.load(handle)
                detail[entry["protein"]] = entry["tr"]
                # Record the bucket the extractor actually used rather than
                # re-deriving it: the layout mirrors AlphaFill's own output
                # directories and is not ours to guess at.
                lig_bucket[entry["protein"]] = bucket
                meta_proteins += 1
                meta_no_coords += entry.get("n_no_coords", 0)
        say("  %s proteins, %s transplants carry metadata but no coordinates"
            % (f"{meta_proteins:,}", f"{meta_no_coords:,}"))

    # ------------------------------------------------------------ gene payload
    say("building gene payload")
    release = ARGS.release or time.strftime("%Y%m%d", time.gmtime())
    reset_dir(os.path.join(dest, "genes"))
    gene_shards = defaultdict(dict)
    index_rows = []
    ligand_genes = defaultdict(list)
    ligand_stats = defaultdict(lambda: {"g": set(), "p": 0,
                                        "ev": Counter(), "cls": None})
    evidence_totals = Counter()
    best_evidence_totals = Counter()
    plddt_repaired = 0     # published value was NaN
    plddt_corrected = 0    # published value belonged to the canonical isoform

    # Structure files are served with a 30-day immutable cache, which is right
    # for a 100 KB file that never changes -- and wrong the moment a data
    # release rewrites one, because Cloudflare would go on serving the old
    # coordinates for a month. The release stamp makes each release a distinct
    # cache key, so the long cache and correct invalidation both hold. Same
    # trick the stylesheets use with filemtime.
    def model_url(protein):
        entry = models.get(protein)
        if not entry:
            return None
        return "/data/alphafill/models/%s/%s.pdb.gz?v=%s" % (entry["b"], protein, release)

    def ligand_url(protein):
        """The coordinates file exists only where a transplant was actually
        written, so this is keyed on the transplant detail rather than on the
        model list -- a gene with no ligand file and a gene with no ligands are
        different, and the viewer says so."""
        bucket = lig_bucket.get(protein)
        if not bucket:
            return None
        return "/data/alphafill/lig/%s/%s.cif.gz?v=%s" % (bucket, protein, release)

    for gene, record in by_gene.items():
        protein = record["prot"]
        row = summary.get(protein, {})

        # Mean pLDDT, taken from the model this page actually serves.
        #
        # The published table carries the *canonical* isoform's value, which is
        # a different protein for the 1,957 genes whose transplants sit on
        # another isoform -- 85.5% of them disagree, by up to 46 points -- and
        # NaN where the canonical lookup found nothing at all. Showing one
        # isoform's confidence beside another isoform's ligands is simply the
        # wrong number, so where the model is in hand the value is recomputed
        # from its B-factor column. The evidence classes are deliberately NOT
        # recomputed: they are the published classification and the site has to
        # agree with the report they appeared in.
        published = finite(record.get("plddt"))
        measured = model_plddt.get(protein)
        plddt = published
        residues = None
        if measured:
            plddt = measured[0]
            residues = measured[1]
            if published is None:
                plddt_repaired += 1
            elif abs(published - plddt) > 0.5:
                plddt_corrected += 1
        gap_row = gap.get(gene, {})
        counts = Counter(item["ev"] for item in record["lig"])
        evidence_totals.update(counts)
        best = next((e for e in EVIDENCE_ORDER if counts.get(e)), None)
        best_evidence_totals[best] += 1

        for item in record["lig"]:
            code = item["ccd"]
            stat = ligand_stats[code]
            stat["g"].add(gene)
            stat["p"] += 1
            stat["ev"][item["ev"]] += 1
            stat["cls"] = item["cls"]
            ligand_genes[code].append([
                gene, item["ev"], item["id"], item["lr"], item["tcs"],
                plddt, 1 if item["p2r"] else 0, item["pdb"],
            ])

        n_transplants = int(row.get("n_transplants") or 0)
        payload = {
            "g": gene,
            "p": protein,
            "can": 1 if canonical.get(gene) == protein else 0,
            "canp": canonical.get(gene),
            "c": record["chr"],
            "pl": plddt,
            # Only surfaced when it is a different protein's number, not when
            # it differs by rounding.
            "plpub": published if (published is not None
                                   and abs(published - plddt) > 0.5) else None,
            "aa": residues,
            "nt": n_transplants,
            "nh": int(row.get("n_donor_hits") or 0),
            "np": int(gap_row.get("n_conf") or 0),
            "tp": as_float(gap_row.get("top_prob")),
            "ev": {k: counts.get(k, 0) for k in EVIDENCE_ORDER if counts.get(k)},
            "best": best,
            "state": "transplant",
            "m": model_url(protein),
            "lc": ligand_url(protein),
            "npk": len(pockets_by_protein.get(protein, [])),
            "lig": record["lig"],
        }
        gene_shards[shard_key(gene)][gene.lower()] = payload
        index_rows.append([gene, record["chr"], plddt,
                           len(record["lig"]), counts.get("strong", 0),
                           EVIDENCE_ORDER.index(best) if best else -1,
                           int(gap_row.get("n_conf") or 0)])

    # Genes that ran and found nothing, and genes with no model at all. Both are
    # real answers and neither is "not found".
    no_donor = 0
    for gene, protein in canonical.items():
        if gene in by_gene:
            continue
        no_donor += 1
        gap_row = gap.get(gene, {})
        gene_shards[shard_key(gene)][gene.lower()] = {
            "g": gene, "p": protein, "can": 1, "canp": protein,
            "c": gap_row.get("chrom"),
            "pl": as_float(gap_row.get("mean_plddt")),
            "nt": 0, "nh": 0,
            "np": int(gap_row.get("n_conf") or 0),
            "tp": as_float(gap_row.get("top_prob")),
            "ev": {}, "best": None,
            "state": "no_donor",
            "m": model_url(protein),
            "lc": None,
            "npk": len(pockets_by_protein.get(protein, [])),
            "lig": [],
        }

    no_model = 0
    for gene in gene_span:
        if gene in canonical or gene in by_gene:
            continue
        chrom, start, end, strand = gene_span[gene]
        no_model += 1
        gene_shards[shard_key(gene)][gene.lower()] = {
            "g": gene, "p": None, "can": 1, "canp": None, "c": chrom,
            "pl": None, "nt": 0, "nh": 0, "np": 0, "tp": None,
            "ev": {}, "best": None, "state": "no_model",
            "m": None, "lc": None, "npk": 0, "lig": [],
        }

    gene_bytes = 0
    for shard, payload in gene_shards.items():
        gene_bytes += write_json(os.path.join(dest, "genes", shard + ".json"), payload)
    say("  %s genes with transplants, %s ran with no qualifying donor, "
        "%s with no model (%s KB over %s shards)"
        % (f"{len(by_gene):,}", f"{no_donor:,}", f"{no_model:,}",
           f"{gene_bytes // 1024:,}", f"{len(gene_shards):,}"))

    write_json(os.path.join(dest, "index.json"), index_rows)

    # ---------------------------------------------------------------- ligands
    say("building ligand payload")
    reset_dir(os.path.join(dest, "ligands"))
    reset_dir(os.path.join(dest, "ligand_genes"))
    ligand_shards = defaultdict(dict)
    for code, stat in ligand_stats.items():
        meta = ccd_names.get(code, {})
        ligand_shards[shard_key(code, 2)][code.lower()] = {
            "ccd": code,
            "name": meta.get("name") or "",
            "formula": meta.get("formula") or "",
            "mw": meta.get("mw"),
            "cls": stat["cls"],
            "ng": len(stat["g"]),
            "np": stat["p"],
            "ev": dict(stat["ev"]),
        }
        rows = ligand_genes[code]
        # Rank so the first page of "every gene predicted to bind NAD" is the
        # part of the answer worth reading: strongest evidence, then the best
        # donor identity behind it.
        rows.sort(key=lambda r: (EVIDENCE_ORDER.index(r[1]), -(r[2] or 0)))
        write_json(os.path.join(dest, "ligand_genes", code.upper() + ".json"), rows)
    ligand_bytes = 0
    for shard, payload in ligand_shards.items():
        ligand_bytes += write_json(os.path.join(dest, "ligands", shard + ".json"), payload)
    say("  %s ligands (%s KB)" % (f"{len(ligand_stats):,}", f"{ligand_bytes // 1024:,}"))

    # ------------------------------------------------------- detail + pockets
    say("writing transplant detail and pockets")
    reset_dir(os.path.join(dest, "detail"))
    reset_dir(os.path.join(dest, "pockets"))
    detail_shards = defaultdict(dict)
    for protein, rows in detail.items():
        detail_shards[shard_key(protein)][protein] = rows
    detail_bytes = 0
    for shard, payload in detail_shards.items():
        detail_bytes += write_json(os.path.join(dest, "detail", shard + ".json"), payload)

    pocket_shards = defaultdict(dict)
    for protein, rows in pockets_by_protein.items():
        pocket_shards[shard_key(protein)][protein] = rows
    pocket_bytes = 0
    for shard, payload in pocket_shards.items():
        pocket_bytes += write_json(os.path.join(dest, "pockets", shard + ".json"), payload)
    say("  detail %s MB over %s shards, pockets %s MB over %s shards"
        % (detail_bytes // 1048576, f"{len(detail_shards):,}",
           pocket_bytes // 1048576, f"{len(pocket_shards):,}"))

    # ---------------------------------------------------------------- targets
    say("building the pocket-but-no-donor target list")
    targets = []
    for gene, row in gap.items():
        if int(row["n_transplants"]) != 0 or int(row["n_conf"]) < 1:
            continue
        plddt = as_float(row["mean_plddt"])
        if plddt is None or plddt < 70:
            continue
        targets.append([gene, row["protein"], row["chrom"], round(plddt, 1),
                        int(row["n_conf"]), as_float(row["top_prob"])])
    targets.sort(key=lambda t: (-(t[5] or 0), -t[3]))
    write_json(os.path.join(dest, "targets.json"), targets)
    say("  %s target genes" % f"{len(targets):,}")

    # -------------------------------------------------------------- benchmark
    benchmark = []
    bench_path = os.path.join(source, "maize_benchmark_all_scored.csv")
    if os.path.isfile(bench_path):
        with open(bench_path) as handle:
            benchmark = list(csv.DictReader(handle))
    write_json(os.path.join(dest, "benchmark.json"), benchmark)

    # ------------------------------------------------------------ suggest set
    say("building the typeahead index")
    rows, keys_by_row = [], {}
    for gene, record in by_gene.items():
        counts = Counter(item["ev"] for item in record["lig"])
        index = len(rows)
        rows.append({
            "y": "g", "k": gene, "l": gene, "c": record["chr"],
            "n": len(record["lig"]), "s": counts.get("strong", 0),
            "pl": finite(model_plddt.get(record["prot"], [None])[0]
                         if model_plddt.get(record["prot"]) else record.get("plddt")),
            "w": counts.get("strong", 0) * 100 + len(record["lig"]),
        })
        keys = {gene.lower()}
        tail = re.sub(r"^zm\d+[a-z]+", "", gene.lower())
        if tail:
            keys.add(tail)
        keys_by_row[index] = list(keys)
    for code, stat in ligand_stats.items():
        meta = ccd_names.get(code, {})
        index = len(rows)
        rows.append({
            "y": "l", "k": code, "l": code, "nm": meta.get("name") or "",
            "n": len(stat["g"]), "cls": stat["cls"], "w": len(stat["g"]),
        })
        keys = {code.lower()}
        for word in re.split(r"[^a-z0-9]+", (meta.get("name") or "").lower()):
            if len(word) >= 3:
                keys.add(word)
        keys_by_row[index] = list(keys)
    suggest_stats = build_suggest(rows, keys_by_row, dest)

    # ------------------------------------------------------------------ stats
    say("writing stats and manifest")
    canonical_by_band = Counter()
    hit_by_band = Counter()
    for gene, protein in canonical.items():
        gap_row = gap.get(gene, {})
        plddt = as_float(gap_row.get("mean_plddt"))
        if plddt is None:
            continue
        band = ("<50" if plddt < 50 else "50-70" if plddt < 70
                else "70-85" if plddt < 85 else ">=85")
        canonical_by_band[band] += 1
        if int(gap_row.get("n_transplants") or 0) > 0:
            hit_by_band[band] += 1

    median_identity = identities[len(identities) // 2] if identities else None
    below_030 = sum(1 for value in identities if value < 0.30)
    stats = {
        "evidence": dict(evidence_totals),
        "best_evidence": {k: v for k, v in best_evidence_totals.items() if k},
        "class": dict(class_counts),
        "hit_rate_by_plddt": [
            {"band": band, "n": canonical_by_band[band],
             "hits": hit_by_band[band],
             "rate": round(hit_by_band[band] / canonical_by_band[band], 4)
                     if canonical_by_band[band] else None}
            for band in ("<50", "50-70", "70-85", ">=85")
        ],
        "tier_lrmsd": {"high": tier_lrmsd[0], "medium": tier_lrmsd[1],
                       "low": tier_lrmsd[2], "bands": list(BAND_LRMSD)},
        "tier_tcs": {"high": tier_tcs[0], "medium": tier_tcs[1],
                     "low": tier_tcs[2], "bands": list(BAND_TCS)},
        "median_donor_identity": rounded(median_identity, 3),
        "fraction_below_030": round(below_030 / len(identities), 4) if identities else None,
        "pocket_ligand_max_a": POCKET_LIGAND_MAX_A,
        "pockets_ligand_supported": pocket_ligand_hits,
        "pockets_total": pocket_rows,
    }
    write_json(os.path.join(dest, "stats.json"), stats)

    manifest = {
        "generated": time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime()),
        "generated_by": "tools/alphafill_index.py",
        "source": source,
        "alphafill_version": "2.3.0",
        "pdb_redo_databank_date": "2024-03-08",
        "models_processed": len(summary),
        "transplants": transplant_rows,
        "gene_ligand_pairs": sum(len(r["lig"]) for r in by_gene.values()),
        "genes_with_transplant": len(by_gene),
        "genes_no_donor": no_donor,
        "genes_no_model": no_model,
        "canonical_genes": len(canonical),
        "distinct_ligands": len(ligand_stats),
        "ccd_named": sum(1 for c in ligand_stats if ccd_names.get(c, {}).get("name")),
        "target_genes": len(targets),
        "pockets": pocket_rows,
        "pockets_ligand_supported": pocket_ligand_hits,
        "models_available": len(models),
        "plddt_from_model": len(model_plddt),
        "plddt_repaired_from_nan": plddt_repaired,
        "plddt_corrected_wrong_isoform": plddt_corrected,
        "ligand_coordinates_available": meta_proteins,
        "transplants_without_coordinates": meta_no_coords,
        "evidence": dict(evidence_totals),
        "suggest": suggest_stats,
        "release": release,
        "build_seconds": round(time.time() - started, 1),
    }
    write_json(os.path.join(dest, "manifest.json"), manifest)

    # index.json is the one JSON the browser fetches directly -- the browse
    # table is 16,933 rows and pulling it through PHP would buy nothing. Every
    # other JSON here is read server-side by search/alphafill/*.php and is not
    # served at all.
    #
    # Models and ligand coordinates are stored gzipped and served as-is, so
    # Apache must declare the encoding rather than compress per request. Do not
    # replace this with mod_deflate: the bytes are already compressed on disk
    # and re-compressing them costs CPU on every structure view.
    with open(os.path.join(dest, ".htaccess"), "w") as handle:
        handle.write(
            "Options -Indexes\n"
            "\n"
            '<FilesMatch "\\.json$">\n'
            "  Require all denied\n"
            "</FilesMatch>\n"
            "\n"
            '<Files "index.json">\n'
            "  Require all granted\n"
            "  Header set Cache-Control \"public, max-age=3600\"\n"
            "</Files>\n"
            "\n"
            '<FilesMatch "\\.(pdb|cif)\\.gz$">\n'
            "  ForceType text/plain\n"
            "  Header set Content-Encoding gzip\n"
            "  Header append Vary Accept-Encoding\n"
            "  Header set Cache-Control \"public, max-age=2592000, immutable\"\n"
            "</FilesMatch>\n")

    say("done in %.1fs" % (time.time() - started))
    say(json.dumps(manifest, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--dest", required=True)
    parser.add_argument("--models", default="")
    parser.add_argument("--gff", default="")
    parser.add_argument("--ccd", default="")
    parser.add_argument("--meta", default="",
                        help="meta/ from alphafill_ligand_extract.py")
    parser.add_argument("--plddt", default="",
                        help="protein -> [mean pLDDT, residues], from the models")
    parser.add_argument("--release", default="",
                        help="cache-busting stamp on structure URLs; defaults to "
                             "today's date. Set it explicitly when rebuilding on "
                             "the same day as a release you need to invalidate -- "
                             "structure files are served immutable for 30 days, so "
                             "an unchanged stamp means the CDN keeps the old bytes.")
    parser.add_argument("--quiet", action="store_true")
    ARGS = parser.parse_args()
    main()
