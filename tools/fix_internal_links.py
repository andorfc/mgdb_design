#!/usr/bin/env python3
"""Stop treating MaizeGDB's own subdomains as external links.

MaizeGDB's tools live on subdomains -- jbrowse, wgs, feta, snptools, qteller,
download and a dozen more -- and page after page marked them with a "leaves
this site" arrow and opened them in a new tab. The arrow is simply wrong on
any maizegdb.org host. The new tab is a separate question, and the answer is
not the same for every link: opening JBrowse over the top of the record you
were reading loses your place, while a link to a file on download.maizegdb.org
is not launching anything.

So, on every anchor whose host is maizegdb.org or a subdomain of it:

  - the external-link arrow is removed, always;
  - target="_blank" / rel="noopener" are removed unless the link opens one of
    the interactive applications listed in APP_HOSTS below.

Usage:
    python3 tools/fix_internal_links.py            # report what would change
    python3 tools/fix_internal_links.py --write    # make the changes

Re-runnable: a file already correct is left untouched, so this can be run
again after new pages are written to check that none of them reintroduced it.
"""
import argparse
import os
import re
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(REPO, 'src')
ROOTS = ('templates', 'controllers', 'include', 'js')
EXTS = ('.bau', '.php', '.js')

INTERNAL_HOST = 'maizegdb.org'

# Interactive tools: things you drive, where losing the page behind you costs
# the reader their place. These keep target="_blank".
APP_HOSTS = {
    'jbrowse.maizegdb.org', 'jbrowse2.maizegdb.org', 'gbrowse.maizegdb.org',
    'qteller.maizegdb.org', 'snptools.maizegdb.org', 'snpversity.maizegdb.org',
    'wgs.maizegdb.org', 'feta.maizegdb.org', 'gcv.maizegdb.org',
    'phylostrata.maizegdb.org', 'pangenome-viewer.maizegdb.org',
    'genomeqc.maizegdb.org', 'mfs.maizegdb.org', 'reelgene.maizegdb.org',
    'fusarium.maizegdb.org', 'maizemine.maizegdb.org', 'foldseek.maizegdb.org',
    'nomenclature.maizegdb.org',
    'past1.maizegdb.org', 'past2.maizegdb.org', 'past3.maizegdb.org',
}

# Everything else on a maizegdb.org host is a page, a document or a file:
# download, ftp, documents, images, archive, mnl, mutants, community,
# maizemeeting -- and the main site itself. Those open in the same tab.
#
# The exception is the main site's own /gbrowse mounts. The host is
# www.maizegdb.org, but the path is a genome browser, so it is an application
# by the same reasoning as the rest of this list.
MAIN_HOSTS = {'maizegdb.org', 'www.maizegdb.org'}
MAIN_APP_PREFIXES = ('/gbrowse',)

ANCHOR = re.compile(r'<a\b[^>]*?href=["\']((?:https?)\\?://[^"\']+)["\'](.*?)</a>', re.S | re.I)

# The three spellings in use. A <span> wrapper is removed whole; a bare entity
# takes the space in front of it so "Download &#8599;" does not leave a
# trailing gap inside the link text.
ARROW_PATTERNS = (
    re.compile(r'\s*<span[^>]*>\s*(?:&nearr;|&#8599;|↗)\s*</span>'),
    re.compile(r'\s*(?:&nearr;|&#8599;|↗)'),
)

TARGET_RE = re.compile(r'\s+target=["\']_blank["\']', re.I)
REL_RE = re.compile(r'\s+rel=["\']noopener(?:\s+noreferrer)?["\']', re.I)


def host_of(href):
    m = re.match(r'https?\\?://([^/?#\'"]+)', href)
    if not m:
        return None
    return m.group(1).lower().split('@')[-1].split(':')[0].rstrip('.')


def path_of(href):
    bare = href.replace('\\', '')
    return re.sub(r'^https?://[^/]*', '', bare).split('?')[0] or '/'


def is_internal(host):
    return host == INTERNAL_HOST or (host or '').endswith('.' + INTERNAL_HOST)


def is_application(host, href):
    if host in APP_HOSTS:
        return True
    if host in MAIN_HOSTS:
        return path_of(href).startswith(MAIN_APP_PREFIXES)
    return False


# An anchor built by concatenating PHP or JS string fragments has no single
# opening tag to edit: `'<a class="..." rel="noopener" ' + 'href="..." ...'`
# reads to a regex as one tag whose href arrives three fragments later. The
# first run of this tool stripped a `rel` out of exactly that shape in
# js/mgdb-protein-structure.js -- a link that had no target="_blank" and
# nothing for this sweep to do. Attributes are only rewritten when the opening
# tag is one literal piece of markup.
CONCAT = re.compile(r'''["']\s*[.+]|[.+]\s*["']''')


def fix_anchor(whole, href):
    """Return the rewritten anchor, and what changed."""
    host = host_of(href)
    opening_end = whole.index('>') + 1
    opening, inner = whole[:opening_end], whole[opening_end:]

    dropped_arrow = False
    for pat in ARROW_PATTERNS:
        new_inner, n = pat.subn('', inner)
        if n:
            inner, dropped_arrow = new_inner, True

    dropped_target = False
    if not is_application(host, href) and not CONCAT.search(opening):
        new_opening, n1 = TARGET_RE.subn('', opening)
        new_opening, n2 = REL_RE.subn('', new_opening)
        if n1 or n2:
            opening, dropped_target = new_opening, True

    return opening + inner, dropped_arrow, dropped_target


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--write', action='store_true', help='apply the changes')
    args = ap.parse_args()

    files = []
    for root in ROOTS:
        for dirpath, _, names in os.walk(os.path.join(SRC, root)):
            for name in names:
                if name.endswith(EXTS):
                    files.append(os.path.join(dirpath, name))

    total_arrow = total_target = 0
    touched = []

    for path in sorted(files):
        original = open(path, errors='replace').read()
        arrows = targets = 0

        def repl(m):
            nonlocal arrows, targets
            href = m.group(1)
            if not is_internal(host_of(href)):
                return m.group(0)
            new, a, t = fix_anchor(m.group(0), href)
            arrows += a
            targets += t
            return new

        updated = ANCHOR.sub(repl, original)
        if updated == original:
            continue
        touched.append((os.path.relpath(path, REPO), arrows, targets))
        total_arrow += arrows
        total_target += targets
        if args.write:
            open(path, 'w').write(updated)

    width = max((len(t[0]) for t in touched), default=10)
    print('%-*s %7s %8s' % (width, 'FILE', 'arrows', 'newtabs'))
    for name, a, t in touched:
        print('%-*s %7d %8d' % (width, name, a, t))
    print('%-*s %7d %8d   (%d files)' % (width, 'TOTAL', total_arrow, total_target, len(touched)))
    if not args.write:
        print('\nDry run. Re-run with --write to apply.', file=sys.stderr)


if __name__ == '__main__':
    main()
