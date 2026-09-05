#!/usr/bin/env python3
"""Inventory every externally reachable MaizeGDB URL and report its interface age.

One script, two roles.

  Orchestrator (default, run from the workstation)
      Copies itself to the development instance over ssh, runs itself there in
      --scan mode against the web root, and writes the results back into this
      repository:

          REDESIGN_STATUS.md          the human-readable status report
          src/data/redesign_status.json  the same data, for /redesign_status

  Scanner (--scan <webroot>, runs on the server)
      Walks the codebase, works out which URLs the site exposes, resolves each
      one to the file that actually serves it, and classifies the interface as
      modern, partial, or legacy. With --probe it also fetches each URL over
      HTTP and classifies the response, which is ground truth rather than
      inference.

The scan has to happen on the server because git is not installed there: the
codebase is not in this repository, only the files the redesign has replaced.
The scanner is stdlib-only and targets Python 3.6+ so it runs under the
server's 3.9.

Usage
    tools/redesign_status.py                 scan, probe, and write everything
    tools/redesign_status.py --no-probe      static analysis only, no HTTP
    tools/redesign_status.py --scan <dir>    scanner mode; prints JSON to stdout

The probe is a GET of each URL with no cookies, and it skips anything that
logs in or out, mutates state, or needs a record identifier. Those rows fall
back to static analysis and say so.
"""

import argparse
import concurrent.futures
import http.client
import json
import os
import re
import socket
import subprocess
import sys
import time

VERSION = "1.0"

# --------------------------------------------------------------------------
# What counts as a route
# --------------------------------------------------------------------------

# Files under controllers/<dir>/ that are includes rather than pages. They are
# pulled in by the controller that owns the route; they never answer a URL of
# their own.
HELPER_SUFFIXES = (
    "_functions.php", "_lib.php", "_tasks.php", "_form.php", "_run.php",
    "_data.php", "_js.php", "_visual_alignment.php", "_utils.php",
)

# redirect.php's search order for a single-segment URL that has no top-level
# controller. First hit wins, so a page in an earlier directory shadows a file
# of the same name in a later one. See redirect.php in the web root.
FALLBACK_DIRS = [
    "controllers/static",
    "dynamic",
    "controllers/about",
    "controllers/community",
    "controllers/tools",
    "controllers/genome",
    "controllers/documentation",
]

# Directories in the web root that are their own applications, served by Apache
# rather than routed through controller.php.
WEBROOT_SKIP_DIRS = {
    "css", "js", "images", "icon", "ie", "lib", "include", "templates",
    "controllers", "temp", "tmp", "backups", "data", "record_data", "docs",
    "search", "dynamic", ".git", "node_modules",
}

# Never probed: these sign in or out, send mail, write to the database, or are
# machine endpoints where a GET is meaningless.
PROBE_SKIP = re.compile(
    r"(^/(BLAST|curation|api)\b)"
    r"|(login|logout|create_account|forgot_password|unsubscribe|preferences"
    r"|feedback|autocomplete|populate_table|update_person|stock_decryption"
    r"|coming_soon|challenge)",
    re.I,
)

# Categories, in the order they appear in the report.
CATEGORY_ORDER = [
    "Data hubs",
    "Gene and pan-gene centers",
    "Genome",
    "Tools",
    "Information pages",
    "Community",
    "Documentation",
    "Analysis projects",
    "Standalone apps in the web root",
    "Search and API",
    "Curation and internal",
]

# --------------------------------------------------------------------------
# Interface markers
# --------------------------------------------------------------------------

MARKERS = [
    ("modern_shell", re.compile(r"->\s*modern\s*\(")),
    ("design_system", re.compile(r"mgdb-modern\.css")),
    ("modern_chrome", re.compile(r"maizegdb-main-modern\.bau|maizegdb_header_modern\.bau")),
    ("legacy_shell", re.compile(r"templates/maizegdb-main\.bau|maizegdb_header\.bau")),
]

# The same question asked of a live response instead of the source.
LIVE_MARKERS = [
    ("body_class", re.compile(r"<body[^>]*class=['\"][^'\"]*mgdb-modern", re.I)),
    ("design_system", re.compile(r"mgdb-modern\.css", re.I)),
    ("html5_doctype", re.compile(r"^\s*<!DOCTYPE html>", re.I)),
    ("viewport", re.compile(r"name=['\"]viewport['\"]", re.I)),
    ("mgdb_page", re.compile(r"class=['\"][^'\"]*mgdb-page", re.I)),
    ("jquery_latest", re.compile(r"jquery-latest", re.I)),
]


def read_text(path, limit=1500000):
    try:
        with open(path, "rb") as handle:
            raw = handle.read(limit)
    except (IOError, OSError):
        return ""
    return raw.decode("utf-8", "replace")


def classify_source(text):
    """Score one PHP file's markers into modern / partial / legacy."""
    found = [name for name, pattern in MARKERS if pattern.search(text)]
    if "modern_shell" in found and "design_system" in found:
        status = "modern"
    elif "modern_shell" in found or "design_system" in found or "modern_chrome" in found:
        status = "partial"
    else:
        status = "legacy"
    return status, found


def classify_live(body):
    """Score one live HTML response. This overrides static analysis when present."""
    found = [name for name, pattern in LIVE_MARKERS if pattern.search(body)]
    modern = "body_class" in found and "design_system" in found
    if modern:
        return "modern", found
    if "design_system" in found or "body_class" in found:
        return "partial", found
    return "legacy", found


# --------------------------------------------------------------------------
# Scanner
# --------------------------------------------------------------------------


class Scanner(object):
    def __init__(self, root, site):
        self.root = root.rstrip("/")
        self.site = site.rstrip("/")
        self.source = {}      # repo-relative path -> file text
        self.pages = {}       # serving file -> page record
        self.links = {}       # normalized internal path -> count
        self.menu_links = set()
        self.offsite = {}     # host -> {count, examples, linked_from}
        self.notes = []
        self._interceptors = None   # '/url' -> file a top-level route displaced

    # -- file access -------------------------------------------------------

    def path(self, rel):
        return os.path.join(self.root, rel)

    def exists(self, rel):
        return os.path.isfile(self.path(rel))

    def text(self, rel):
        if rel not in self.source:
            self.source[rel] = read_text(self.path(rel))
        return self.source[rel]

    def listing(self, rel):
        try:
            return sorted(os.listdir(self.path(rel)))
        except OSError:
            return []

    # -- shell ownership ---------------------------------------------------

    def shell_owner(self, chain):
        """The file in a serving chain that builds the document shell.

        A sub-controller that never calls `new Bauplan` renders into whatever
        shell its parent already created, so its interface age is the parent's.
        Walking the chain from the leaf backwards finds the file that actually
        decides.
        """
        for rel in reversed(chain):
            if "new Bauplan(" in self.text(rel):
                return rel
        return chain[0] if chain else None

    # -- guards ------------------------------------------------------------

    GUARD_KEY = re.compile(r"\b(PAGE|CONTROLLER)\s*==\s*[\"']([^\"']*)[\"']")
    GUARD_INCLUDE = re.compile(r"include\s*\(\s*'(controllers/[^']+\.php)'")
    ALIAS = re.compile(r"CONTROLLER\s*==\s*[\"']([^\"']+)[\"']")

    def guards(self, controller_rel):
        """Modern pages swapped in by a conditional at the top of a controller.

        Matches the pattern the redesign uses throughout:

            if (PAGE == 'stock' && !getCGIParam('id', 'G', ID)) {
              include('controllers/data_center/stock_search_modern.php');

        Returns [(constant, value, include_path, variant, condition_text)] where
        constant is PAGE or CONTROLLER, and variant is 'search', 'record', or
        'any' depending on whether the condition tests for a record identifier.
        """
        out = []
        lines = self.text(controller_rel).splitlines()
        for index, line in enumerate(lines):
            key_match = self.GUARD_KEY.search(line)
            if not key_match:
                continue
            window = "\n".join(lines[index:index + 6])
            include_match = self.GUARD_INCLUDE.search(window)
            if not include_match:
                continue
            has_id = "getCGIParam('id'" in line
            negated = "!getCGIParam('id'" in line or "!trim(" in line
            if has_id and negated:
                variant = "search"
            elif has_id:
                variant = "record"
            else:
                variant = "any"
            out.append((key_match.group(1), key_match.group(2),
                        include_match.group(1), variant, line.strip()))
        return out

    def controller_aliases(self):
        """URL segments controller.php hands to a controller of another name.

            else if (CONTROLLER == "person" || CONTROLLER == "annotator")
              include ("./controllers/community.php");

        Without this, /person looks like it does not exist: there is no
        controllers/person.php and redirect.php never sees the request.
        """
        aliases = {}
        text = self.text("controller.php")
        for match in re.finditer(
                r"CONTROLLER\s*==[^)]*\)\s*(?:\{[^}]*?)?include\s*\(\s*[\"']\./?(controllers/[^\"']+\.php)",
                text, re.S):
            target = match.group(1)
            for name in self.ALIAS.findall(match.group(0)):
                aliases[name] = target
        return aliases

    # -- route discovery ---------------------------------------------------

    def add_page(self, url, chain, category, kind, note=None):
        """Record one page. Keyed by the file that serves it, so a file reachable
        at two URLs is one row with two URLs rather than two rows."""
        leaf = chain[-1]
        key = leaf
        record = self.pages.get(key)
        if record is None:
            owner = self.shell_owner(chain)
            status, markers = classify_source(self.text(owner) if owner else "")
            record = {
                "urls": [],
                "serves": leaf,
                "shell_owner": owner,
                "chain": chain,
                "category": category,
                "kind": kind,
                "static_status": status,
                "static_markers": markers,
                "notes": [],
            }
            self.pages[key] = record
        if url not in record["urls"]:
            record["urls"].append(url)
        if note and note not in record["notes"]:
            record["notes"].append(note)
        return record

    def release_url(self, serving_file, url):
        """Take a URL away from a page that no longer answers it, and drop the
        page entirely when that was its only URL."""
        record = self.pages.get(serving_file)
        if not record or url not in record["urls"]:
            return
        record["urls"].remove(url)
        if not record["urls"]:
            del self.pages[serving_file]

    def interceptors(self):
        """`/name` -> the file that answered it before a route interceptor took it.

        Modernizing a page in place adds a top-level `controllers/<name>.php`,
        because `controller.php` has to answer the URL itself or the request
        falls through to `redirect.php`, which reaches the old file and loads
        the legacy chrome with it. The interceptor is a routing detail: the
        page is the same page it was, so its category has to come from the file
        the interceptor displaced rather than from its own top-level path.

        Only URLs that really are intercepted are listed, and the search order
        is `redirect.php`'s own, so the displaced file is the one that would
        have answered.
        """
        if self._interceptors is None:
            mapping = {}
            for directory in FALLBACK_DIRS:
                for name in self.listing(directory):
                    if not name.endswith(".php"):
                        continue
                    if name.endswith(HELPER_SUFFIXES) or name.endswith("_modern.php"):
                        continue
                    url = "/" + name[:-4]
                    if url in mapping:
                        continue
                    if self.exists("controllers/" + name):
                        mapping[url] = "%s/%s" % (directory, name)
            self._interceptors = mapping
        return self._interceptors

    def category_for(self, url, chain):
        leaf = chain[-1]
        # A top-level controller that intercepted a route is categorised on the
        # file it displaced, so a page does not change category by being
        # modernized. Anything reached through a sub-controller already names
        # its own directory and is left alone.
        if leaf.count("/") == 1 and leaf.startswith("controllers/"):
            leaf = self.interceptors().get(url, leaf)
        if url.startswith("/data_center"):
            return "Data hubs"
        if url.startswith("/gene_center") or url.startswith("/pan_gene_center"):
            return "Gene and pan-gene centers"
        if url.startswith("/genome") or leaf.startswith("controllers/genome/"):
            return "Genome"
        if url.startswith("/projects"):
            return "Analysis projects"
        if url.startswith("/curation") or url.startswith("/BLAST") or leaf.startswith("controllers/curation/"):
            return "Curation and internal"
        if url.startswith("/api") or url.startswith("/search_engine") or url.startswith("/searchall"):
            return "Search and API"
        if leaf.startswith("controllers/tools/"):
            return "Tools"
        if leaf.startswith("controllers/community/"):
            return "Community"
        if leaf.startswith("controllers/documentation/"):
            return "Documentation"
        if leaf.startswith("controllers/about/") or leaf.startswith("controllers/static/"):
            return "Information pages"
        if url.startswith("/insertion") or url.startswith("/ordering"):
            return "Data hubs"
        return "Information pages"

    def discover(self):
        claimed_single = {}   # '/name' -> serving file, for shadow detection
        shadowed = []

        # A real directory in the web root is served by Apache before any
        # rewrite happens, so it takes the URL away from whatever controller
        # was named after it.
        webroot_dirs = set(
            name for name in self.listing("")
            if os.path.isdir(self.path(name)) and not name.startswith(".")
        )

        # 1. Top-level controllers. controller.php checks these first.
        for name in self.listing("controllers"):
            if not name.endswith(".php"):
                continue
            base = name[:-4]
            rel = "controllers/" + name
            url = "/" + base
            if base in webroot_dirs:
                shadowed.append({"url": url + "/", "unreachable": rel,
                                 "answered_by": "%s/ in the web root" % base})
                continue
            claimed_single[url] = rel
            chain = [rel]
            self.add_page(url, chain, self.category_for(url, chain), "controller")

        # 2. Sub-controllers under a directory whose parent controller exists.
        #    controllers/<X>.php dispatches to controllers/<X>/<PAGE>.php, and
        #    where a <PAGE>_search.php also exists the bare URL is the search
        #    page while an id turns it into a record page.
        for entry in self.listing("controllers"):
            sub = "controllers/" + entry
            if not os.path.isdir(self.path(sub)):
                continue
            parent = "controllers/%s.php" % entry
            if not self.exists(parent):
                continue

            files = [f for f in self.listing(sub) if f.endswith(".php")]
            search_pages = set()
            plain_pages = set()
            for f in files:
                if f.endswith(HELPER_SUFFIXES) or f.endswith("_modern.php"):
                    continue
                base = f[:-4]
                if base.endswith("_search"):
                    search_pages.add(base[:-7])
                else:
                    plain_pages.add(base)

            guard_map = {}
            for constant, value, include_path, variant, condition in self.guards(parent):
                if constant != "PAGE":
                    continue
                guard_map.setdefault(value, []).append((include_path, variant, condition))

            # A guard on the empty PAGE replaces the parent route itself, so
            # the parent controller no longer answers its own URL.
            for include_path, variant, condition in guard_map.get("", []):
                url = "/" + entry
                self.release_url(parent, url)
                chain = [parent, include_path]
                self.add_page(url, chain, self.category_for(url, chain), "controller",
                              "routed by a guard in %s" % parent)

            for page in sorted(search_pages | plain_pages):
                guards = guard_map.get(page, [])

                def guarded(variant):
                    for include_path, guard_variant, condition in guards:
                        if guard_variant in (variant, "any"):
                            return include_path
                    return None

                if page in search_pages:
                    url = "/%s/%s" % (entry, page)
                    swap = guarded("search")
                    chain = [parent, swap] if swap else [parent, "%s/%s_search.php" % (sub, page)]
                    self.add_page(url, chain, self.category_for(url, chain), "search",
                                  "modern page swapped in by a guard" if swap else None)
                    if page in plain_pages:
                        url = "/%s/%s/{id}" % (entry, page)
                        swap = guarded("record")
                        chain = [parent, swap] if swap else [parent, "%s/%s.php" % (sub, page)]
                        self.add_page(url, chain, self.category_for(url, chain), "record",
                                      "modern page swapped in by a guard" if swap else None)
                elif page in plain_pages:
                    url = "/%s/%s" % (entry, page)
                    swap = guarded("any") or guarded("search")
                    chain = [parent, swap] if swap else [parent, "%s/%s.php" % (sub, page)]
                    self.add_page(url, chain, self.category_for(url, chain), "page",
                                  "modern page swapped in by a guard" if swap else None)

        # 3. URL segments controller.php aliases to a controller of another
        #    name. /person is the one that matters: it is served by
        #    controllers/community.php, and the guard there keys on CONTROLLER
        #    rather than PAGE because 'community' is implicit for that route.
        for alias, target in sorted(self.controller_aliases().items()):
            url = "/" + alias
            if url in claimed_single:
                continue
            claimed_single[url] = target
            alias_guards = [g for g in self.guards(target)
                            if g[0] == "CONTROLLER" and g[1] == alias]

            search_swap = next((g[2] for g in alias_guards if g[3] in ("search", "any")), None)
            record_swap = next((g[2] for g in alias_guards if g[3] == "record"), None)

            chain = [target, search_swap] if search_swap else [target]
            self.add_page(url, chain, self.category_for(url, chain), "search",
                          "aliased in controller.php to %s" % target)

            # For these routes the identifier arrives as the second segment.
            record_file = record_swap or "controllers/community/%s.php" % alias
            if self.exists(record_file) or record_swap:
                chain = [target, record_file]
                self.add_page("/%s/{id}" % alias, chain,
                              self.category_for(url, chain), "record")

        # 4. Single-segment URLs answered by redirect.php's fallback search.
        for directory in FALLBACK_DIRS:
            for name in self.listing(directory):
                if not name.endswith(".php"):
                    continue
                if name.endswith(HELPER_SUFFIXES) or name.endswith("_modern.php"):
                    continue
                base = name[:-4]
                url = "/" + base
                rel = "%s/%s" % (directory, name)
                if base in webroot_dirs:
                    shadowed.append({"url": url + "/", "unreachable": rel,
                                     "answered_by": "%s/ in the web root" % base})
                    continue
                if url in claimed_single:
                    shadowed.append({
                        "url": url,
                        "unreachable": rel,
                        "answered_by": claimed_single[url],
                    })
                    continue
                claimed_single[url] = rel
                chain = ["redirect.php", rel]
                self.add_page(url, chain, self.category_for(url, chain), "fallback")

        # 5. Applications sitting in the web root as real directories. Apache
        #    serves these directly; .htaccess only rewrites paths that are
        #    neither a file nor a directory.
        for name in self.listing(""):
            full = self.path(name)
            if not os.path.isdir(full) or name in WEBROOT_SKIP_DIRS or name.startswith("."):
                continue
            for index in ("index.php", "index.html"):
                if os.path.isfile(os.path.join(full, index)):
                    url = "/%s/" % name
                    chain = ["%s/%s" % (name, index)]
                    self.add_page(url, chain, "Standalone apps in the web root", "directory")
                    break

        # 6. The home page.
        if self.exists("index.php"):
            self.add_page("/", ["index.php"], "Information pages", "home")

        # 7. Modern controllers nobody routes to. A file written against the
        #    design system that no guard includes and no URL reaches is work
        #    already done and switched off, which is the cheapest thing on the
        #    list to finish.
        routed = set()
        for record in self.pages.values():
            routed.update(record["chain"])
        orphans = []
        for base, _dirs, files in os.walk(self.path("controllers")):
            for name in files:
                if not name.endswith(".php"):
                    continue
                rel = os.path.relpath(os.path.join(base, name), self.root)
                if rel in routed:
                    continue
                status, markers = classify_source(self.text(rel))
                if status == "modern":
                    referenced = any(
                        rel in self.text(other) for other in
                        ["controllers/%s" % f for f in self.listing("controllers") if f.endswith(".php")]
                    )
                    orphans.append({
                        "file": rel,
                        "referenced_anywhere": referenced,
                        "markers": markers,
                    })
        self.shadowed = shadowed
        self.orphans = sorted(orphans, key=lambda item: item["file"])

    # -- link inventory ----------------------------------------------------

    HREF = re.compile(r"""(?:href|src|action)\s*=\s*["']([^"'#][^"']*)["']""", re.I)
    ABSOLUTE = re.compile(r"""https?://[A-Za-z0-9._~:/?#\[\]@!$&*+,;=%-]+""")
    TEMPLATE_VAR = re.compile(r"\$\((?:server-url|root_url)\)")

    def scan_links(self):
        """Every URL the codebase hands to a browser.

        Internal links become an inbound-link count per route, which is the
        signal for how exposed a page is. Links to another host are collected
        separately: those are the offsite destinations MaizeGDB sends people to,
        and they are not this codebase's to modernize.
        """
        roots = ["controllers", "templates", "js", "include", "dynamic", "search", "."]
        seen_files = set()
        for start in roots:
            base_path = self.path(start)
            if not os.path.isdir(base_path):
                continue
            for base, dirs, files in os.walk(base_path):
                dirs[:] = [d for d in dirs
                           if d not in {"backups", "temp", "tmp", ".git", "node_modules", "lib", "images"}]
                if start == ".":
                    dirs[:] = []
                for name in files:
                    if not name.endswith((".php", ".bau", ".js", ".html", ".htm")):
                        continue
                    full = os.path.join(base, name)
                    rel = os.path.relpath(full, self.root)
                    if rel in seen_files:
                        continue
                    seen_files.add(rel)
                    try:
                        if os.path.getsize(full) > 600000:
                            continue
                    except OSError:
                        continue
                    self.harvest(rel, read_text(full))

    def harvest(self, rel, text):
        in_menu = (
            "megamenu" in rel
            or "maizegdb_header" in rel
            or "maizegdb-home" in rel
            or rel.endswith("right-menu.bau")
            or "sitemap" in rel
        )
        candidates = set(self.HREF.findall(text)) | set(self.ABSOLUTE.findall(text))
        for raw in candidates:
            url = raw.strip().rstrip("'\";,.").replace("&amp;", "&")
            url = self.TEMPLATE_VAR.sub("", url)
            if not url or url.startswith(("#", "javascript:", "mailto:", "data:", "tel:", "$(")):
                continue

            host = None
            if url.startswith("http://") or url.startswith("https://"):
                rest = url.split("//", 1)[1]
                host = rest.split("/", 1)[0].split(":")[0].lower()
                path = "/" + rest.split("/", 1)[1] if "/" in rest else "/"
                if self.site.split("//")[-1] != host and not host.endswith("maizegdb.org"):
                    self.record_offsite(host, url, rel)
                    continue
                if host != self.site.split("//")[-1]:
                    # A maizegdb.org subdomain: a separate application, not a
                    # route in this codebase.
                    self.record_offsite(host, url, rel)
                    continue
                url = path
            elif not url.startswith("/"):
                continue

            path = url.split("?")[0].split("#")[0]
            if re.search(r"\.(css|js|png|jpg|jpeg|gif|svg|ico|pdf|zip|gz|txt|xml|json|csv|tsv|fa|fasta)$", path, re.I):
                continue
            path = path.rstrip("/") or "/"
            self.links[path] = self.links.get(path, 0) + 1
            if in_menu:
                self.menu_links.add(path)

    def record_offsite(self, host, url, rel):
        entry = self.offsite.setdefault(host, {"count": 0, "examples": [], "linked_from": []})
        entry["count"] += 1

        # An example exists to show what kind of link this is, and the query
        # string is never part of that. It is, however, where a share token or
        # a password ends up: the JBrowse 2 session links in the templates carry
        # both. Dropping the query keeps this report publishable.
        example = url.split("?")[0].split("#")[0]
        if len(entry["examples"]) < 3 and example not in entry["examples"]:
            entry["examples"].append(example)
        if len(entry["linked_from"]) < 5 and rel not in entry["linked_from"]:
            entry["linked_from"].append(rel)

    def attach_links(self):
        for record in self.pages.values():
            total = 0
            in_menu = False

            # A file answering more than one URL is listed under the one the
            # site actually uses: in the mega menu first, then most linked to,
            # then shortest.
            record["urls"].sort(key=lambda url: (
                (url.rstrip("/") or "/") not in self.menu_links,
                -self.links.get(url.rstrip("/") or "/", 0),
                len(url),
            ))

            for url in record["urls"]:
                key = url.rstrip("/") or "/"
                total += self.links.get(key, 0)
                if key in self.menu_links:
                    in_menu = True
                # /data_center/stock/{id} inherits the exposure of its search page
                if key.endswith("/{id}"):
                    parent = key[: -len("/{id}")]
                    total += self.links.get(parent, 0) // 4
            record["links_in"] = total
            record["in_menu"] = in_menu

    # -- live probe --------------------------------------------------------

    def probe(self, address, host_header, workers=6, timeout=30):
        targets = []
        for key, record in sorted(self.pages.items()):
            url = record["urls"][0]
            if "{id}" in url or PROBE_SKIP.search(url):
                record["probe"] = {"skipped": True, "reason": "not safe or needs an identifier"}
                continue
            targets.append((key, url))

        def get(url):
            connection = http.client.HTTPConnection(address, 80, timeout=timeout)
            connection.putrequest("GET", url, skip_host=True, skip_accept_encoding=True)
            connection.putheader("Host", host_header)
            connection.putheader("User-Agent", "maizegdb-redesign-status/%s" % VERSION)
            connection.putheader("Accept", "text/html")
            connection.putheader("Connection", "close")
            connection.endheaders()
            response = connection.getresponse()
            body = response.read(400000).decode("utf-8", "replace")
            location = response.getheader("Location")
            connection.close()
            return response.status, body, location

        def fetch(item):
            key, url = item
            started = time.time()
            try:
                http_status, body, location = get(url)
                redirected = None

                # Two very different things arrive as a 3xx here.
                #
                # Apache redirects a directory URL missing its trailing slash;
                # following that hop keeps the row pointed at the page a visitor
                # actually lands on. But a *retirement* redirect goes somewhere
                # else entirely, and following it made the retired route inherit
                # the destination's title and "modern" classification -- so
                # /faq reported itself as modern and titled "Contact MaizeGDB".
                # Eighteen retired routes were counted as modern pages that way.
                # Only the normalising hop is followed; a move is reported as a
                # move.
                moved_to = None
                if http_status in (301, 302, 303, 307, 308) and location:
                    target = location
                    if "://" in target:
                        rest = target.split("//", 1)[1]
                        if rest.split("/", 1)[0].split(":")[0] != host_header:
                            target = None
                        else:
                            target = "/" + rest.split("/", 1)[1] if "/" in rest else "/"
                    if target and target != url:
                        bare = target.split("?")[0].split("#")[0]
                        # Two kinds of hop are not a retirement and must not be
                        # counted as one:
                        #   the trailing slash Apache adds to a directory URL,
                        #   and an index alias -- /gene_center sending a reader
                        #   to /gene_center/gene, which is a real URL landing on
                        #   its own hub. Calling those "retired" took 24 pages
                        #   out of the modern count on the first run.
                        normalising = (bare.rstrip("/") == url.split("?")[0].rstrip("/")
                                       or bare.startswith(url.split("?")[0].rstrip("/") + "/"))
                        if normalising:
                            redirected = target
                            http_status, body, _ = get(target)
                        else:
                            moved_to = target

                title = re.search(r"<title[^>]*>(.*?)</title>", body, re.I | re.S)
                status, markers = classify_live(body)
                return key, {
                    "http": http_status,
                    "redirected_to": redirected,
                    "moved_to": moved_to,
                    "bytes": len(body),
                    "ms": int((time.time() - started) * 1000),
                    # A moved route has no page of its own, so it takes neither
                    # the destination's title nor its classification.
                    "title": None if moved_to else (title.group(1).strip()[:140] if title else None),
                    "markers": [] if moved_to else markers,
                    "status": "retired" if moved_to else (status if http_status == 200 else None),
                }
            except Exception as error:  # a probe failure must not lose the row
                return key, {"error": "%s: %s" % (type(error).__name__, error)}

        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
            for key, result in pool.map(fetch, targets):
                self.pages[key]["probe"] = result

    # -- result ------------------------------------------------------------

    def finish(self):
        rows = []
        for key, record in self.pages.items():
            probe = record.get("probe") or {}
            live = probe.get("status")
            if live:
                status, source = live, "probe"
            else:
                status, source = record["static_status"], "source"
            rows.append({
                "url": record["urls"][0],
                "also": record["urls"][1:],
                "category": record["category"],
                "kind": record["kind"],
                "status": status,
                "evidence": source,
                "static_status": record["static_status"],
                "static_markers": record["static_markers"],
                "serves": record["serves"],
                "shell_owner": record["shell_owner"],
                "links_in": record.get("links_in", 0),
                "in_menu": record.get("in_menu", False),
                "title": probe.get("title"),
                "moved_to": probe.get("moved_to"),
                "http": probe.get("http"),
                "probe_error": probe.get("error"),
                "probed": bool(live) or bool(probe.get("http")),
                "notes": record["notes"],
            })

        rows.sort(key=lambda row: (
            CATEGORY_ORDER.index(row["category"]) if row["category"] in CATEGORY_ORDER else 99,
            -row["links_in"],
            row["url"],
        ))

        counts = {"modern": 0, "partial": 0, "legacy": 0, "retired": 0}
        for row in rows:
            counts[row["status"]] = counts.get(row["status"], 0) + 1

        by_category = {}
        for row in rows:
            bucket = by_category.setdefault(row["category"], {"modern": 0, "partial": 0, "legacy": 0, "retired": 0, "total": 0})
            bucket[row["status"]] += 1
            bucket["total"] += 1

        # What to do next: legacy pages, most exposed first. A page in the mega
        # menu is reachable from every page on the site, so it outranks a page
        # with more inbound links but no menu entry.
        # A retired route is finished work, not outstanding work: it has no
        # page left to modernize.
        next_up = [row for row in rows
                   if row["status"] not in ("modern", "retired")
                   and row["category"] != "Curation and internal"]
        next_up.sort(key=lambda row: (not row["in_menu"], -row["links_in"], row["url"]))

        # Offsite links split three ways. The maizegdb.org subdomains are
        # separate applications that a visitor still reads as MaizeGDB, so they
        # belong in the picture even though this codebase cannot restyle them.
        # A link written against the production host is a different problem: on
        # a development instance it silently leaves the instance.
        mgdb_hosts, self_hosts, third_party = [], [], []
        production = {"www.maizegdb.org", "maizegdb.org"}
        for host, values in sorted(self.offsite.items(), key=lambda kv: -kv[1]["count"]):
            entry = dict(host=host, **values)
            if host in production:
                self_hosts.append(entry)
            elif host.endswith("maizegdb.org"):
                mgdb_hosts.append(entry)
            else:
                third_party.append(entry)

        return {
            "generated": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "generator": "tools/redesign_status.py %s" % VERSION,
            "site": self.site,
            "counts": counts,
            "total": len(rows),
            # Retired routes are excluded from the denominator: they are not
            # pages waiting to be modernized, so counting them drags the
            # figure down for work that is done.
            "percent_modern": round(100.0 * counts["modern"] / (len(rows) - counts["retired"]), 1) if (len(rows) - counts["retired"]) else 0.0,
            "by_category": by_category,
            "category_order": [c for c in CATEGORY_ORDER if c in by_category],
            "rows": rows,
            "next_up": [row["url"] for row in next_up[:30]],
            "orphan_modern": self.orphans,
            "shadowed": self.shadowed,
            "mgdb_hosts": mgdb_hosts,
            "self_hosts": self_hosts,
            "third_party_hosts": third_party[:20],
            "third_party_total": len(third_party),
            "probed": any(row["probed"] for row in rows),
        }


def run_scan(root, site, do_probe):
    scanner = Scanner(root, site)
    scanner.discover()
    scanner.scan_links()
    scanner.attach_links()
    if do_probe:
        host_header = site.split("//")[-1].rstrip("/")
        try:
            address = socket.gethostbyname(socket.getfqdn())
        except socket.error:
            address = "127.0.0.1"
        scanner.probe(address, host_header)
    return scanner.finish()


# --------------------------------------------------------------------------
# Report
# --------------------------------------------------------------------------

STATUS_LABEL = {
    "modern": "Modern",
    "partial": "Partial",
    "legacy": "Legacy",
    "retired": "Retired",
}


def bar(fraction, width=28):
    filled = int(round(fraction * width))
    return "#" * filled + "." * (width - filled)


def write_markdown(data, path):
    counts = data["counts"]
    total = data["total"]
    out = []
    add = out.append

    add("# Redesign status")
    add("")
    add("Every URL MaizeGDB exposes to the web, and whether it is on the modern")
    add("interface or still on the old one. Generated by `tools/redesign_status.py`;")
    add("do not edit this file by hand.")
    add("")
    add("| | |")
    add("| --- | --- |")
    add("| Generated | %s |" % data["generated"])
    add("| Site scanned | %s |" % data["site"])
    add("| URLs found | %d |" % total)
    add("| Modern | %d (%.1f%%) |" % (counts["modern"], data["percent_modern"]))
    add("| Partial | %d |" % counts["partial"])
    add("| Legacy | %d |" % counts["legacy"])
    add("| Retired | %d |" % counts.get("retired", 0))
    add("| Classified by live response | %s |" % ("yes" if data["probed"] else "no, source analysis only"))
    add("")
    add("```")
    add("modern   %s %d" % (bar(counts["modern"] / float(total or 1)), counts["modern"]))
    add("partial  %s %d" % (bar(counts["partial"] / float(total or 1)), counts["partial"]))
    add("legacy   %s %d" % (bar(counts["legacy"] / float(total or 1)), counts["legacy"]))
    add("retired  %s %d" % (bar(counts.get("retired", 0) / float(total or 1)), counts.get("retired", 0)))
    add("```")
    add("")
    add("**Modern** is a page on the shared design system: it calls `modern()` for the")
    add("document shell and loads `mgdb-modern.css`. **Partial** has one of the two and")
    add("not the other, which usually means a page borrowing components inside the old")
    add("shell. **Legacy** is everything untouched.")
    add("")

    # ---- progress by category
    add("## By category")
    add("")
    add("| Category | Modern | Partial | Legacy | Total | Progress |")
    add("| --- | ---: | ---: | ---: | ---: | --- |")
    for category in data["category_order"]:
        bucket = data["by_category"][category]
        fraction = bucket["modern"] / float(bucket["total"] or 1)
        add("| %s | %d | %d | %d | %d | %s %.0f%% |" % (
            category, bucket["modern"], bucket["partial"], bucket["legacy"],
            bucket["total"], bar(fraction, 14), fraction * 100))
    add("")

    # ---- what to work on next
    add("## Work on these next")
    add("")
    add("Not-yet-modern pages ranked by how exposed they are: everything reachable from")
    add("the mega menu first, then by how many places in the codebase link to it.")
    add("Curation and internal pages are left out.")
    add("")
    add("| # | URL | Category | Status | In menu | Inbound links | Serving file |")
    add("| ---: | --- | --- | --- | :---: | ---: | --- |")
    index = 0
    by_url = dict((row["url"], row) for row in data["rows"])
    for url in data["next_up"]:
        row = by_url.get(url)
        if not row:
            continue
        index += 1
        add("| %d | `%s` | %s | %s | %s | %d | `%s` |" % (
            index, url, row["category"], STATUS_LABEL[row["status"]],
            "yes" if row["in_menu"] else "", row["links_in"], row["serves"]))
    add("")

    # ---- already modern
    modern_rows = [row for row in data["rows"] if row["status"] == "modern"]
    add("## Already modern")
    add("")
    add("| URL | Category | Serving file |")
    add("| --- | --- | --- |")
    for row in modern_rows:
        add("| `%s` | %s | `%s` |" % (row["url"], row["category"], row["serves"]))
    add("")

    # ---- built but not routed
    if data["orphan_modern"]:
        add("## Built on the design system but not routed")
        add("")
        add("These files are already written against the design system, and nothing")
        add("reaches them. Wiring one up is a guard in its parent controller, not a")
        add("rebuild.")
        add("")
        add("| File | Referenced by a controller |")
        add("| --- | :---: |")
        for orphan in data["orphan_modern"]:
            add("| `%s` | %s |" % (orphan["file"], "yes" if orphan["referenced_anywhere"] else "no"))
        add("")

    # ---- shadowed
    if data["shadowed"]:
        add("## Unreachable files")
        add("")
        add("A top-level controller takes a single-segment URL before `redirect.php`")
        add("looks anywhere else, so these files cannot be reached at the URL they were")
        add("written for. Some of this is deliberate: replacing a page in place works")
        add("exactly this way.")
        add("")
        add("| URL | Answered by | Never reached |")
        add("| --- | --- | --- |")
        for item in data["shadowed"]:
            add("| `%s` | `%s` | `%s` |" % (item["url"], item["answered_by"], item["unreachable"]))
        add("")

    # ---- full inventory
    add("## Full inventory")
    add("")
    for category in data["category_order"]:
        rows = [row for row in data["rows"] if row["category"] == category]
        if not rows:
            continue
        add("### %s" % category)
        add("")
        add("| URL | Status | How classified | Links | Menu | Serving file |")
        add("| --- | --- | --- | ---: | :---: | --- |")
        for row in rows:
            url_cell = "`%s`" % row["url"]
            if row["also"]:
                url_cell += "<br>%s" % " ".join("`%s`" % other for other in row["also"])
            evidence = "live response" if row["evidence"] == "probe" else "source"
            add("| %s | %s | %s | %d | %s | `%s` |" % (
                url_cell, STATUS_LABEL[row["status"]], evidence,
                row["links_in"], "yes" if row["in_menu"] else "", row["serves"]))
        add("")

    # ---- offsite
    if data["mgdb_hosts"]:
        add("## MaizeGDB applications on their own subdomain")
        add("")
        add("These are linked from the site and a visitor reads them as MaizeGDB, but")
        add("they are separate applications with their own interfaces. Nothing in this")
        add("repository styles them, so they are outside the counts above and each one")
        add("is its own piece of work.")
        add("")
        add("| Host | Links from the codebase | Example |")
        add("| --- | ---: | --- |")
        for entry in data["mgdb_hosts"][:30]:
            add("| `%s` | %d | %s |" % (
                entry["host"], entry["count"],
                entry["examples"][0] if entry["examples"] else ""))
        if len(data["mgdb_hosts"]) > 30:
            add("")
            add("%d more MaizeGDB subdomains are linked from the codebase; the full list is"
                % (len(data["mgdb_hosts"]) - 30))
            add("in `src/data/redesign_status.json`.")
        add("")

    if data["self_hosts"]:
        add("### Links written against the production host")
        add("")
        add("Absolute links to the production site. On a development instance these")
        add("leave the instance without saying so, which makes a page look finished when")
        add("the link is still going somewhere else.")
        add("")
        add("| Host | Occurrences | Files |")
        add("| --- | ---: | --- |")
        for entry in data["self_hosts"]:
            add("| `%s` | %d | %s |" % (
                entry["host"], entry["count"],
                ", ".join("`%s`" % f for f in entry["linked_from"])))
        add("")

    if data["third_party_hosts"]:
        add("### Third-party destinations")
        add("")
        add("%d other hosts are linked from the codebase. The most-linked:" % data["third_party_total"])
        add("")
        add("| Host | Links |")
        add("| --- | ---: |")
        for entry in data["third_party_hosts"]:
            add("| `%s` | %d |" % (entry["host"], entry["count"]))
        add("")

    # ---- method
    add("## How this is measured")
    add("")
    add("`tools/redesign_status.py` copies itself to the development instance, walks the")
    add("web root, and works out which URLs exist the same way the site does:")
    add("")
    add("1. `controllers/<name>.php` answers `/<name>`; `controller.php` checks these first.")
    add("2. `controllers/<name>/<page>.php` answers `/<name>/<page>`. Where a")
    add("   `<page>_search.php` also exists, the bare URL is the search page and the URL")
    add("   with an identifier is the record page, so both are listed.")
    add("3. A single-segment URL with no top-level controller falls through to")
    add("   `redirect.php`, which searches `controllers/static`, `dynamic`,")
    add("   `controllers/about`, `controllers/community`, `controllers/tools`,")
    add("   `controllers/genome`, and `controllers/documentation` in that order.")
    add("4. A real directory in the web root with an `index.php` is served by Apache")
    add("   directly, because `.htaccess` only rewrites paths that are neither a file")
    add("   nor a directory.")
    add("5. Guards of the form `if (PAGE == 'x' && ...) include('..._modern.php')` are")
    add("   read out of each controller, so a page swapped in for one route without")
    add("   touching its siblings is attributed to that route alone.")
    add("6. A page modernized in place gains a `controllers/<name>.php` interceptor so")
    add("   that `controller.php` answers the URL rather than `redirect.php`. Those")
    add("   routes are categorised on the file the interceptor displaced, so a page")
    add("   keeps its category when it is modernized.")
    add("")
    add("A file reachable at more than one URL is one row carrying both, so the totals")
    add("count pages rather than paths.")
    add("")
    add("Each URL is then fetched over HTTP from the server itself and the response is")
    add("classified: `mgdb-modern.css` plus `class=\"mgdb-modern\"` on the body is modern.")
    add("The live response is the verdict where there is one. Pages that sign in or out,")
    add("send mail, write to the database, or need a record identifier are not fetched;")
    add("those rows say `source` in the *How classified* column and fall back to reading")
    add("the controller.")
    add("")
    add("Rerun it with:")
    add("")
    add("```bash")
    add("tools/redesign_status.py")
    add("```")
    add("")

    with open(path, "w") as handle:
        handle.write("\n".join(out))


# --------------------------------------------------------------------------
# Orchestrator
# --------------------------------------------------------------------------


def load_deploy_config(repo_root):
    config = os.path.join(repo_root, "deploy", "config.local.sh")
    if not os.path.isfile(config):
        sys.exit("missing %s — copy deploy/config.example.sh and fill it in" % config)
    values = {}
    for line in open(config):
        match = re.match(r'\s*(HOST|WEBROOT)\s*=\s*"?([^"\n#]+)"?', line)
        if match:
            values[match.group(1)] = match.group(2).strip()
    for key in ("HOST", "WEBROOT"):
        if key not in values:
            sys.exit("%s not set in %s" % (key, config))
    return values


def remote_scan(host, webroot, site, do_probe):
    me = os.path.abspath(__file__)
    remote = "/tmp/mgdb_redesign_status.py"
    subprocess.check_call(["scp", "-q", me, "%s:%s" % (host, remote)])
    command = "cd %s && python3 %s --scan %s --site %s" % (webroot, remote, webroot, site)
    if do_probe:
        command += " --probe"
    result = subprocess.run(
        ["ssh", "-n", host, command],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if result.returncode != 0:
        sys.stderr.write(result.stderr.decode("utf-8", "replace"))
        sys.exit("remote scan failed")
    warnings = result.stderr.decode("utf-8", "replace").strip()
    if warnings:
        sys.stderr.write(warnings + "\n")
    return json.loads(result.stdout.decode("utf-8", "replace"))


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--scan", metavar="WEBROOT",
                        help="scanner mode: walk this web root and print JSON")
    parser.add_argument("--site", default="https://claude.maizegdb.org",
                        help="site the web root serves")
    parser.add_argument("--probe", action="store_true",
                        help="scanner mode: also fetch each URL over HTTP")
    parser.add_argument("--no-probe", action="store_true",
                        help="orchestrator mode: skip the HTTP pass")
    parser.add_argument("--local", metavar="WEBROOT",
                        help="scan a web root on this machine instead of over ssh")
    args = parser.parse_args()

    if args.scan:
        json.dump(run_scan(args.scan, args.site, args.probe), sys.stdout)
        sys.stdout.write("\n")
        return

    repo_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    do_probe = not args.no_probe

    if args.local:
        data = run_scan(args.local, args.site, do_probe)
    else:
        config = load_deploy_config(repo_root)
        sys.stderr.write("scanning %s on %s\n" % (config["WEBROOT"], config["HOST"]))
        data = remote_scan(config["HOST"], config["WEBROOT"], args.site, do_probe)

    markdown_path = os.path.join(repo_root, "REDESIGN_STATUS.md")
    json_path = os.path.join(repo_root, "src", "data", "redesign_status.json")

    write_markdown(data, markdown_path)
    if not os.path.isdir(os.path.dirname(json_path)):
        os.makedirs(os.path.dirname(json_path))
    with open(json_path, "w") as handle:
        json.dump(data, handle, indent=1, sort_keys=True)
        handle.write("\n")

    counts = data["counts"]
    sys.stderr.write(
        "%d URLs: %d modern, %d partial, %d legacy (%.1f%% modern)\n"
        % (data["total"], counts["modern"], counts["partial"], counts["legacy"],
           data["percent_modern"]))
    sys.stderr.write("wrote %s\n" % os.path.relpath(markdown_path, repo_root))
    sys.stderr.write("wrote %s\n" % os.path.relpath(json_path, repo_root))


if __name__ == "__main__":
    main()
