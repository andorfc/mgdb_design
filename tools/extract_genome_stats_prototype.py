#!/usr/bin/env python3
"""Extract the data contract from the standalone genome statistics prototype.

The prototype is treated as an input artifact, never as executable code.  Its
four JSON-compatible constants are decoded and written as one compact data
asset for the Genome Data Hub.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path


def between(source: str, start: str, end: str) -> str:
    left = source.find(start)
    if left < 0:
        raise ValueError(f"missing marker: {start}")
    left += len(start)
    right = source.find(end, left)
    if right < 0:
        raise ValueError(f"missing marker: {end}")
    return source[left:right]


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("prototype", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--script-output", type=Path)
    args = parser.parse_args()

    source = args.prototype.read_text(encoding="utf-8")
    payload = json.loads(between(source, "const PAYLOAD=", ", DICT="))
    dictionary = json.loads(between(source, ", DICT=", ", DEFAULT="))
    default_columns = json.loads(between(source, ", DEFAULT=", ", NUMERIC=new Set("))
    numeric_columns = json.loads(between(source, ", NUMERIC=new Set(", ");\nconst ROWS="))

    rows = [row for row in payload["data"] if not row.get("excluded_reason")]
    declared_total = int(payload["meta"]["total"])
    if len(rows) != declared_total:
        raise ValueError(
            f"prototype declares {declared_total} assemblies but contains {len(rows)} usable rows"
        )

    output = {
        "meta": payload["meta"],
        "data": rows,
        "dictionary": dictionary,
        "default_columns": default_columns,
        "numeric_columns": numeric_columns,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(output, ensure_ascii=False, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )
    if args.script_output:
        args.script_output.parent.mkdir(parents=True, exist_ok=True)
        args.script_output.write_text(
            "window.MGDB_GENOME_STATS_DEMO="
            + json.dumps(output, ensure_ascii=True, separators=(",", ":"))
            + ";\n",
            encoding="utf-8",
        )
    print(
        f"wrote {len(rows)} assemblies, {len(dictionary['columns'])} columns "
        f"to {args.output}"
        + (f" and {args.script_output}" if args.script_output else "")
    )


if __name__ == "__main__":
    main()
