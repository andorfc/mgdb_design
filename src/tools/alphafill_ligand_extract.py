#!/usr/bin/env python3
"""Split AlphaFill's filled mmCIFs into the two things MaizeGDB actually serves.

Runs on the cluster that holds the AlphaFill output, not on the web host:

    python3 alphafill_ligand_extract.py --out <prod/out> --dest <staging> \
            [--shard I --of N] [--limit N]

For every protein it writes two small files beside each other:

    lig/<bucket>/<protein>.cif.gz    the HETATM records only, a parseable mmCIF
    meta/<bucket>/<protein>.json    ligand, donor, metrics and pocket residues

Both are written pre-compressed where it pays: mmCIF text gzips about 5:1, and
Apache serves the stored bytes with Content-Encoding: gzip rather than
compressing per request, so the browser decompresses transparently and the
server spends no CPU on it. That is the difference between ~650 MB and ~130 MB
on disk and on the wire.

Why split at all
----------------
Ligands are 4.6% of the atoms in a filled AlphaFill mmCIF. Serving whole filled
structures is ~20x more bytes than the science needs, and the protein half is
byte-identical to the AlphaFold model that was fed in -- which MaizeGDB serves
already. Splitting takes the archive from 4.9 GB to ~266 MB with no loss: the
viewer loads the model and the ligand file as two 3Dmol models, so pLDDT
colouring on the protein and per-transplant colouring on the ligands both work
and individual transplants toggle without re-fetching anything.

The label_alt_id trap
---------------------
AlphaFill writes the transplant's chain label into _atom_site.label_alt_id,
which is not what that column means. Two things go wrong.

Once a protein exceeds ~25 transplants the label becomes multi-character
(`AA`, `AB`, ...) and the spec limits that field to one character, so strict
parsers reject the whole file with `Not a single character: AA`. Because it only
happens to transplant-rich proteins it silently removes exactly the interesting
cases.

Below that threshold the label is a single character and parses -- but it now
reads as an *alternate conformation* identifier, and a viewer is entitled to
render only one altLoc per site and drop the rest. That failure is worse than
the first because nothing errors; ligands simply do not appear.

So every value in the column is replaced with `.`, not just the long ones. The
column carries no information here: the real chain label is in label_asym_id,
which is what the JSON's asym_id points at.
"""

import argparse
import gzip
import json
import os
import sys
import time

def cif_value(value):
    """Quote a value that contains a quote character, as mmCIF requires.

    AlphaFill writes atom names bare, including the ones with a prime in them --
    N1', C4', C1' -- and in CIF an unquoted apostrophe opens a quoted string
    that never closes, so the parser swallows the rest of the line and hands
    back undefined fields. 3Dmol dies on it with a bare
    `Cannot read properties of undefined (reading 'toUpperCase')`.

    Primes are the atom-naming convention for sugars and nucleotides, so this
    is not an edge case: measured over a 700-protein sample it silently removed
    13.1% of all ligand copies and touched half of all proteins, and the
    casualties were ADP, ATP, AMP, GDP, GTP, FAD, UDP, SAH, FMN and SAM -- the
    cofactors a reader is most likely to have come for.
    """
    if "'" in value:
        return '"%s"' % value.replace('"', "")
    if '"' in value:
        return "'%s'" % value
    return value


ATOM_SITE_COLUMNS = [
    "group_PDB", "id", "type_symbol", "label_atom_id", "label_alt_id",
    "label_comp_id", "label_asym_id", "label_entity_id", "label_seq_id",
    "pdbx_PDB_ins_code", "Cartn_x", "Cartn_y", "Cartn_z", "occupancy",
    "B_iso_or_equiv", "pdbx_formal_charge", "auth_seq_id", "auth_comp_id",
    "auth_asym_id", "auth_atom_id", "pdbx_PDB_model_num",
]

# Column offsets in the source loop, resolved per file rather than assumed.
IDX_ALT, IDX_ASYM = 4, 6


def ligand_cif(protein, cif_path):
    """Return (mmCIF text, atom count, {asym: atom count}) for the HETATMs."""
    header, rows = None, []
    per_asym = {}
    with open(cif_path, "r", errors="replace") as handle:
        for line in handle:
            if line.startswith("_atom_site."):
                if header is None:
                    header = []
                header.append(line.strip().split(".", 1)[1])
                continue
            if not line.startswith("HETATM"):
                continue
            field = line.split()
            if len(field) < len(ATOM_SITE_COLUMNS):
                continue
            # Not "if it is too long": always. See the note above -- a
            # single-character value parses and then reads as an altLoc.
            field[IDX_ALT] = "."
            per_asym[field[IDX_ASYM]] = per_asym.get(field[IDX_ASYM], 0) + 1
            rows.append(field[:len(ATOM_SITE_COLUMNS)])

    if not rows:
        return None, 0, {}

    columns = header[:len(ATOM_SITE_COLUMNS)] if header else ATOM_SITE_COLUMNS
    out = ["data_%s_ligands" % protein, "#", "loop_"]
    out += ["_atom_site." + name for name in columns]
    for index, field in enumerate(rows, 1):
        field[1] = str(index)                     # renumber, the file stands alone
        out.append(" ".join(cif_value(value) for value in field))
    out.append("#")
    return "\n".join(out) + "\n", len(rows), per_asym


def slim_meta(protein, json_path, per_asym):
    """The AlphaFill JSON, reduced to what a transplant card needs.

    The clash block names every polymer residue within contact distance of the
    transplant, so the pocket residue list is already computed -- it just has to
    be pulled out of poly_atom.seq_id and deduplicated. No separate computation
    and no extra store.
    """
    with open(json_path, "r", errors="replace") as handle:
        data = json.load(handle)

    transplants = []
    for hit in data.get("hits") or []:
        alignment = hit.get("alignment") or {}
        for entry in hit.get("transplants") or []:
            clash = entry.get("clash") or {}
            residues = sorted({
                d["poly_atom"]["seq_id"]
                for d in (clash.get("distances") or [])
                if isinstance(d.get("poly_atom"), dict)
                and d["poly_atom"].get("seq_id") is not None
            })
            asym = entry.get("asym_id")
            transplants.append({
                "a": asym,
                "ccd": entry.get("analogue_id") or entry.get("compound_id"),
                "dccd": entry.get("compound_id"),
                "pdb": hit.get("pdb_id"),
                "dasym": entry.get("pdb_asym_id"),
                "id": round(alignment.get("identity") or 0, 4),
                "alen": alignment.get("length"),
                "grmsd": round(hit.get("global_rmsd") or 0, 3),
                "lrmsd": round(entry.get("local_rmsd") or 0, 4),
                "tcs": round(clash.get("score") or 0, 4),
                "nclash": clash.get("clash_count"),
                # Transplants can carry metadata and no coordinates. Saying so
                # is the difference between "not drawn" and "not there".
                "nat": per_asym.get(asym, 0),
                "res": residues,
            })
    return {
        "protein": protein,
        "version": data.get("alphafill_version"),
        "date": data.get("date"),
        "n": len(transplants),
        "n_no_coords": sum(1 for t in transplants if not t["nat"]),
        "tr": transplants,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True, help="AlphaFill prod/out directory")
    parser.add_argument("--dest", required=True)
    parser.add_argument("--shard", type=int, default=0)
    parser.add_argument("--of", type=int, default=1)
    parser.add_argument("--limit", type=int, default=0)
    args = parser.parse_args()

    started = time.time()
    buckets = sorted(os.listdir(args.out))
    done = ligands = skipped = 0
    cif_bytes = meta_bytes = 0
    no_coord_total = 0

    for index, bucket in enumerate(buckets):
        if index % args.of != args.shard:
            continue
        source = os.path.join(args.out, bucket)
        if not os.path.isdir(source):
            continue
        for name in sorted(os.listdir(source)):
            if not name.endswith(".cif"):
                continue
            protein = name[:-4]
            cif_path = os.path.join(source, name)
            json_path = os.path.join(source, protein + ".json")

            text, count, per_asym = ligand_cif(protein, cif_path)
            done += 1
            if not count:
                skipped += 1          # ran, no qualifying donor -- a real answer
                continue

            lig_dir = os.path.join(args.dest, "lig", bucket)
            meta_dir = os.path.join(args.dest, "meta", bucket)
            os.makedirs(lig_dir, exist_ok=True)
            os.makedirs(meta_dir, exist_ok=True)

            lig_path = os.path.join(lig_dir, protein + ".cif.gz")
            with gzip.open(lig_path, "wt", compresslevel=9) as handle:
                handle.write(text)
            cif_bytes += os.path.getsize(lig_path)
            ligands += 1

            if os.path.isfile(json_path):
                try:
                    meta = slim_meta(protein, json_path, per_asym)
                except (ValueError, KeyError) as error:
                    sys.stderr.write("meta failed for %s: %s\n" % (protein, error))
                    continue
                no_coord_total += meta["n_no_coords"]
                blob = json.dumps(meta, separators=(",", ":"))
                with open(os.path.join(meta_dir, protein + ".json"), "w") as handle:
                    handle.write(blob)
                meta_bytes += len(blob)

            if args.limit and ligands >= args.limit:
                break
        if args.limit and ligands >= args.limit:
            break

    sys.stderr.write(
        "shard %d/%d: %d proteins, %d with ligands, %d without, "
        "cif %.1f MB, meta %.1f MB, %d metadata-only transplants, %.0fs\n"
        % (args.shard, args.of, done, ligands, skipped,
           cif_bytes / 1e6, meta_bytes / 1e6, no_coord_total, time.time() - started))


if __name__ == "__main__":
    main()
