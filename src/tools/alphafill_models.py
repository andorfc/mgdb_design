#!/usr/bin/env python3
"""Extract the AlphaFold models the AlphaFill page serves, from the run's own
input archive.

    python3 tools/alphafill_models.py --archive B73.tar.gz \
            --need needed_proteins.txt --dest <staging>

Writes models/<bucket>/<protein>.pdb.gz plus two sidecars the index build
consumes: model_index.json (bucket, size, sha1 per protein) and
model_plddt.json (mean pLDDT and residue count per protein).

Why this exists rather than serving what MaizeGDB already hosts
--------------------------------------------------------------
images.maizegdb.org/esm/b73/ holds ESMFold models, which are a different
prediction of the same sequence: for Zm00001eb000660_P001 the two agree on
sequence and disagree by 1.53 A CA RMSD after optimal superposition. The
transplanted ligand coordinates are in the AlphaFold model's frame, and 1.53 A
is the same magnitude as AlphaFill's entire benchmarked accuracy (median 1.59 A
pocket RMSD), so overlaying them on the ESMFold model would add error as large
as the signal -- silently, and it would look plausible. The models here are the
exact files the run was performed on.

Two passes' worth of work in one
--------------------------------
The archive is 6.7 GB of gzip and can only be read forwards, so extracting one
model at a time would rescan it per file. Everything the payload needs comes out
of the single streaming pass.

Hydrogens are dropped. These are Amber-relaxed models, so 51% of their atoms are
hydrogens that no cartoon, stick or surface representation ever draws, and the
AlphaFill viewer's own protein blocks are heavy-atom only. Dropping them halves
the payload for no visual difference.

Mean pLDDT is measured here rather than taken from the published table, because
the published value is the canonical isoform's -- see the note in
tools/alphafill_index.py.
"""

import argparse
import gzip
import hashlib
import json
import os
import sys
import tarfile
import time


def strip_hydrogens(raw):
    """Drop hydrogens and renumber atom serials. Element is columns 77-78."""
    out, serial = [], 0
    for line in raw.decode("ascii", "replace").splitlines():
        if line.startswith(("ATOM", "HETATM")):
            if line[76:78].strip().upper() == "H":
                continue
            serial += 1
            out.append("%s%5d%s" % (line[:6], serial, line[11:].rstrip()))
        elif line.startswith(("TER", "END")):
            out.append(line.rstrip())
    return ("\n".join(out) + "\n").encode("ascii")


def mean_plddt(text):
    """pLDDT is per-residue constant in the B-factor column of these files, so
    the mean over one atom per residue is the definition of the model's score."""
    total, count = 0.0, 0
    for line in text.decode("ascii", "replace").splitlines():
        if line.startswith("ATOM") and line[12:16].strip() == "CA":
            total += float(line[60:66])
            count += 1
    return (round(total / count, 2), count) if count else (None, 0)


def bucket_for(protein):
    stem = protein.split("_")[0]
    return stem[9:12] if stem.startswith("Zm00001eb") and len(stem) >= 12 else "misc"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--archive", required=True)
    parser.add_argument("--need", required=True,
                        help="one protein isoform per line")
    parser.add_argument("--dest", required=True)
    args = parser.parse_args()

    started = time.time()
    need = set(open(args.need).read().split())
    out_root = os.path.join(args.dest, "models")
    sys.stderr.write("need %d models\n" % len(need))

    index, plddt = {}, {}
    written = kept = dropped = 0
    raw_bytes = gz_bytes = 0

    with tarfile.open(args.archive, "r|gz") as archive:   # streaming, no seeking
        for member in archive:
            if not member.isfile() or not member.name.endswith(".pdb"):
                continue
            parts = member.name.split("/")
            if len(parts) < 4:
                continue
            protein = parts[2]
            if protein not in need:
                continue

            raw = archive.extractfile(member).read()
            before = raw.count(b"\nATOM") + raw.count(b"\nHETATM")
            slim = strip_hydrogens(raw)
            after = slim.count(b"\nATOM") + slim.count(b"\nHETATM")
            kept += after
            dropped += before - after

            bucket = bucket_for(protein)
            directory = os.path.join(out_root, bucket)
            os.makedirs(directory, exist_ok=True)
            path = os.path.join(directory, protein + ".pdb.gz")
            with gzip.open(path, "wb", compresslevel=9) as handle:
                handle.write(slim)

            size = os.path.getsize(path)
            index[protein] = {"b": bucket, "n": after, "raw": len(slim),
                              "gz": size, "sha1": hashlib.sha1(slim).hexdigest()}
            plddt[protein] = list(mean_plddt(slim))
            raw_bytes += len(slim)
            gz_bytes += size
            written += 1
            if written % 2000 == 0:
                sys.stderr.write("  %d written, %.0f MB gz\n" % (written, gz_bytes / 1e6))
                sys.stderr.flush()

    json.dump(index, open(os.path.join(args.dest, "model_index.json"), "w"))
    json.dump(plddt, open(os.path.join(args.dest, "model_plddt.json"), "w"))
    missing = sorted(need - set(index))
    json.dump(missing, open(os.path.join(args.dest, "model_missing.json"), "w"))

    sys.stderr.write(
        "DONE written=%d missing=%d heavy_atoms=%d hydrogens_dropped=%d "
        "raw=%.0f MB gz=%.0f MB in %.0fs\n"
        % (written, len(missing), kept, dropped,
           raw_bytes / 1e6, gz_bytes / 1e6, time.time() - started))


if __name__ == "__main__":
    main()
