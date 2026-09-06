#!/usr/bin/env python3
"""Rebuild the status index at the top of ADMIN_DEPENDENCIES.md.

The register is hand-written prose and stays that way. Only the index is
generated, because an index maintained by hand goes stale on the first entry
somebody appends without scrolling back up -- which is how three AD numbers
came to be issued twice before 2026-09-06.

The index sits between two HTML comment markers and nothing outside them is
touched. Every field is read back out of the entries themselves:

    ## AD-0NN — <title>
    - **Required administrator:** <who>
    - **Status:** <state>

An entry is closed when its Status opens with "applied", "implemented",
"resolved", or says it is not an administrator request. Everything else is
open. The grouping is by administrator, because that is how the list gets
worked: a DBA wants the database rows, not all 53.

Usage
    tools/admin_index.py            rewrite the index in place
    tools/admin_index.py --check    exit 1 if the index is out of date
"""

import argparse
import re
import sys
from collections import Counter

DOC = "ADMIN_DEPENDENCIES.md"
BEGIN = "<!-- BEGIN GENERATED INDEX — tools/admin_index.py -->"
END = "<!-- END GENERATED INDEX -->"

ROLES = ["Database", "System", "Network", "Curation", "Application",
         "No administrator"]


FIELD = re.compile(r"^\s*[-*]\s*\*\*(Status|Required administrator):\*\*\s*(.*)$")


def parse(text):
    """Read every entry's title, Status and Required administrator.

    Both fields wrap across lines in most entries, so a continuation -- an
    indented, non-empty line that does not start a new field, list item or
    heading -- is folded back in. Reading only the first line was what turned
    AD-057's state into "applied and verified — five 301s, five pages still
    served, site map", which stops mid-thought.
    """
    entries, cur, field = [], None, None
    for line in text.split("\n"):
        m = re.match(r"^## (AD-\d{3}) — (.*)$", line)
        if m:
            cur = {"id": m.group(1), "title": m.group(2),
                   "status": None, "admin": None}
            entries.append(cur)
            field = None
            continue
        if cur is None:
            continue
        mf = FIELD.match(line)
        if mf:
            field = "status" if mf.group(1) == "Status" else "admin"
            if cur[field] is None:
                cur[field] = mf.group(2).strip()
            else:
                field = None
            continue
        if field:
            if line.strip() and line.startswith((" ", "\t")) \
                    and not line.lstrip().startswith(("-", "*", "#", "|")):
                cur[field] += " " + line.strip()
            else:
                field = None
    return entries


def is_closed(entry):
    s = (entry["status"] or "").lower().lstrip("*")
    return s.startswith(("applied", "implemented", "resolved",
                         "not an administrator", "the feedback half"))


def role_of(entry):
    """Which administrator the entry is waiting on.

    The field is free text and frequently names two people -- "MaizeGDB
    curator, with application maintainer if the query is widened", "MaizeGDB
    curator (annotation data) plus DBA for the index". **The role named first
    wins**, because that is the one who has to move before anything else can.
    Matching on a keyword anywhere in the string instead put those under
    whichever role the keyword list happened to reach first, and filed an
    application change as a sysadmin one whenever the field mentioned where the
    file lives ("application maintainer (web root `.htaccess`)").
    """
    a = (entry["admin"] or "").lower()
    if not a or a == "none":
        return "No administrator"
    markers = [
        ("Network", ("cloudflare", "network administrator")),
        ("System", ("system administrator", "server administrator", "root on",
                    "root ", "web server administrator", "ftp.maizegdb.org",
                    "web root", "selinux")),
        ("Database", ("database", "dba", "postgres")),
        ("Curation", ("curator", "curation")),
        ("Application", ("application maintainer", "content maintainer")),
    ]
    best, at = "Application", len(a) + 1
    for role, keys in markers:
        for k in keys:
            i = a.find(k)
            if i != -1 and i < at:
                best, at = role, i
    return best


def cell(s):
    """A table cell. Strikethrough is the register's way of marking a heading
    resolved; inside a cell in the closed table it is noise, so it comes off."""
    return s.replace("~~", "").replace("|", "\\|")


def clip(s, limit=88):
    """Shorten at a word boundary. Cutting mid-word produced 'the gen'."""
    s = s.strip()
    if len(s) <= limit:
        return s
    cut = s[:limit].rsplit(" ", 1)[0].rstrip(",;:")
    return cut + "…"


def build(entries, generated_on):
    open_e = [e for e in entries if not is_closed(e)]
    done_e = [e for e in entries if is_closed(e)]
    out = [BEGIN, "", "## What is outstanding", ""]
    out.append(
        "%d entries have been raised. **%d are open** and **%d are closed** — "
        "applied, implemented, or recorded as needing no administrator at all. "
        "Regenerated by `tools/admin_index.py` on %s; the entry is always the "
        "authority, this table is only a way in."
        % (len(entries), len(open_e), len(done_e), generated_on))
    out += ["", "Grouped by who has to act, because that is how the list gets "
                "worked. An entry whose fix is already written and is waiting "
                "only on a privilege says so in its own **Status**.", ""]
    for role in ROLES:
        rows = [e for e in open_e if role_of(e) == role]
        if not rows:
            continue
        out += ["### %s — %d open" % (role, len(rows)), "",
                "| | What it needs |", "| --- | --- |"]
        out += ["| **%s** | %s |" % (e["id"], cell(e["title"])) for e in rows]
        out.append("")
    out += ["## What is closed", "", "| | Outcome |", "| --- | --- |"]
    for e in done_e:
        state = re.sub(r"\*\*", "", e["status"] or "")
        # Keep the whole first clause: several of these say "implemented, with
        # one table outstanding", and the outstanding half is the useful half.
        state = state.split(". ")[0].strip().rstrip(".")
        out.append("| %s | %s — %s |" % (e["id"], cell(clip(state)),
                                         cell(e["title"])))
    out += ["", END]
    return "\n".join(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--check", action="store_true",
                    help="exit 1 if the index is out of date")
    ap.add_argument("--date", default=None,
                    help="date to stamp the index with (default: keep)")
    args = ap.parse_args()

    text = open(DOC, encoding="utf-8").read()
    if BEGIN not in text or END not in text:
        sys.exit("markers not found in %s" % DOC)

    entries = parse(text)

    ids = [e["id"] for e in entries]
    dupes = sorted(k for k, v in Counter(ids).items() if v > 1)
    if dupes:
        sys.exit("duplicate AD numbers: %s" % ", ".join(dupes))
    missing = [e["id"] for e in entries if not e["status"]]
    if missing:
        sys.exit("entries with no Status line: %s" % ", ".join(missing))

    start = text.index(BEGIN)
    stop = text.index(END) + len(END)
    old = text[start:stop]
    stamped = args.date
    if not stamped:
        m = re.search(r"on (\d{4}-\d{2}-\d{2});", old)
        stamped = m.group(1) if m else "an unrecorded date"
    new = build(entries, stamped)

    if args.check:
        if old != new:
            sys.exit("index is out of date — run tools/admin_index.py")
        print("index is current (%d entries)" % len(entries))
        return

    open(DOC, "w", encoding="utf-8").write(text[:start] + new + text[stop:])
    n_open = sum(1 for e in entries if not is_closed(e))
    print("wrote index: %d entries, %d open, %d closed"
          % (len(entries), n_open, len(entries) - n_open))


if __name__ == "__main__":
    main()
