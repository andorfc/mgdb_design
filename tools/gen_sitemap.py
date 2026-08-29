#!/usr/bin/env python3
"""Emit the three /sitemap partials from sitemap_data.py.

  templates/about/sitemap-featured.bau   the "New tools" band, above the search
  templates/about/sitemap-tabs.bau       the sticky section tab bar
  templates/about/sitemap-content.bau    the collapsible directory, below it

They are separate files because they land in three different places on the
page, and Bauplan has no way to split one loaded partial across several. The
tab bar is generated rather than hand-written so it can never drift out of
sync with the sections it points at.

Bauplan's tokenizer treats '(' and ')' as block delimiters and '\\' as the
escape character, so every literal parenthesis in the emitted markup has to be
written '\\(' and '\\)'. The tokenizer does not understand HTML, so this applies
inside comments too. Getting it wrong reports the *last* ')' in the file as the
error, which makes the line number useless -- hence doing it mechanically here.
"""
import html
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sitemap_data as D


def bau(text):
    """Escape a string for Bauplan: parens first, then leave HTML entities alone."""
    return text.replace('(', r'\(').replace(')', r'\)')


def esc(text):
    """HTML-escape, then Bauplan-escape. For anything that came from content."""
    return bau(html.escape(text, quote=True))


def is_external(url):
    return url.startswith('http')


def item(name, url, desc, indent='        '):
    ext = is_external(url)
    attrs = ' target="_blank" rel="noopener"' if ext else ''
    arrow = ' <span class="sitemap-item-ext" aria-hidden="true">&nearr;</span>' if ext else ''
    out = [f'{indent}<li class="sitemap-item">',
           f'{indent}  <a class="sitemap-item-link" href="{bau(url)}"{attrs}>{esc(name)}{arrow}</a>']
    if desc:
        out.append(f'{indent}  <p class="sitemap-item-desc">{esc(desc)}</p>')
    out.append(f'{indent}</li>')
    return out


def balanced(text, label):
    """Bauplan counts block opens against unescaped ')'. Warn when they differ."""
    opens = sum(text.count(t) for t in ('*(', '$(', '@(', '&('))
    closes = len([i for i, c in enumerate(text)
                  if c == ')' and (i == 0 or text[i - 1] != '\\')])
    print(f'{label}: block opens {opens}, unescaped ) {closes}', file=sys.stderr)
    if opens != closes:
        print(f'WARNING: {label} parens do not balance -- Bauplan will fail to parse',
              file=sys.stderr)


# ---- new tools band -------------------------------------------------------
# No eyebrow and no explanatory paragraph: the review asked for the heading and
# the cards only. Eight entries fall into two rows at desktop widths, which is
# the shape the group liked, and the grid absorbs more without changes here.
F = ['*(featured-content',
     '<section id="sitemap-featured" class="sitemap-featured" data-section-kind="tools" aria-labelledby="sitemap-featured-title">',
     '  <h2 id="sitemap-featured-title" class="sitemap-featured-title">New tools</h2>',
     '  <ul class="sitemap-featured-grid">']
for name, url, desc, slug in D.FEATURED:
    F.extend(item(name, url, desc, indent='    '))
F.append('  </ul>')
F.append('</section>')
F.append(')')
featured = '\n'.join(F) + '\n'

# ---- sticky tab bar -------------------------------------------------------
# Same component as the data hub pages: a sticky row of in-page jump links,
# scrollspy-highlighted by js/sitemap.js. Two fixed entries for the parts of
# the page that are not directory sections, then one per section in order.
missing = [sid for sid, _, _, _, _ in D.SECTIONS if sid not in D.TAB_LABELS]
if missing:
    sys.exit(f'sitemap_data.TAB_LABELS is missing a label for: {", ".join(missing)}')

T = ['*(tabs-content',
     '<nav class="mgdb-section-tabs sitemap-tabs" aria-label="Sections on this page">',
     '  <a href="#sitemap-featured">New tools</a>',
     '  <a href="#sitemap-search-panel">Search</a>']
for sid, _, _, _, _ in D.SECTIONS:
    T.append(f'  <a href="#sm-{sid}" data-tab-section="sm-{sid}">{esc(D.TAB_LABELS[sid])}</a>')
T.append('</nav>')
T.append(')')
tabs = '\n'.join(T) + '\n'

# ---- directory sections ---------------------------------------------------
L = ['*(doc-content', '<div id="sitemap_content" aria-label="MaizeGDB directory">', '']
for sid, kind, title, blurb, items in D.SECTIONS:
    panel = f'sm-{sid}-panel'
    entries = D.DATA_CENTERS if sid == 'data_center' else items
    L.append(f'  <section id="sm-{sid}" class="sitemap-section" data-section-kind="{kind}">')
    L.append('    <h2 class="sitemap-section-heading">')
    L.append(f'      <button type="button" class="sitemap-section-toggle" aria-controls="{panel}" aria-expanded="true">')
    L.append(f'        <span class="sitemap-section-name">{esc(title)}</span>')
    L.append(f'        <span class="sitemap-section-count" data-total="{len(entries)}">{len(entries)}</span>')
    L.append('      </button>')
    L.append('    </h2>')
    if blurb:
        L.append(f'    <p class="sitemap-section-blurb">{esc(blurb)}</p>')
    L.append(f'    <div id="{panel}" class="sitemap-panel open">')
    L.append('      <ul class="sitemap-grid">')
    for name, url, desc in entries:
        L.extend(item(name, url, desc))
    L.append('      </ul>')
    L.append('    </div>')
    L.append('  </section>')
    L.append('')

L.append('  <p id="sitemap-empty" class="mgdb-empty" hidden>')
L.append('    <strong>No resources match that search.</strong>')
L.append('    <span>Try a shorter word, or choose a different resource group.</span>')
L.append('  </p>')
L.append('</div>')
L.append(')')
content = '\n'.join(L) + '\n'

balanced(featured, 'sitemap-featured.bau')
balanced(tabs, 'sitemap-tabs.bau')
balanced(content, 'sitemap-content.bau')

repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for rel, text in (('src/templates/about/sitemap-featured.bau', featured),
                  ('src/templates/about/sitemap-tabs.bau', tabs),
                  ('src/templates/about/sitemap-content.bau', content)):
    target = os.path.join(repo, rel)
    open(target, 'w').write(text)
    print(f'wrote {rel}  ({len(text.splitlines())} lines)', file=sys.stderr)

n_items = sum(len(i) for _, _, _, _, i in D.SECTIONS) + len(D.DATA_CENTERS)
print(f'{len(D.SECTIONS)} sections, {n_items} directory entries, '
      f'{len(D.FEATURED)} featured', file=sys.stderr)

# Duplicate URLs across sections are legitimate (BLAST is a tool and a starting
# point) but duplicates *within* one section are always a mistake.
for sid, _, title, _, items in D.SECTIONS:
    entries = D.DATA_CENTERS if sid == 'data_center' else items
    seen = {}
    for name, url, _ in entries:
        if url in seen:
            print(f'WARNING: {title}: "{name}" and "{seen[url]}" both point at {url}',
                  file=sys.stderr)
        seen[url] = name
