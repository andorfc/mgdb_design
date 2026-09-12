#!/usr/bin/env python3
"""Refuse a .bau file whose Bauplan blocks do not balance.

Why this exists
---------------
Bauplan's tokenizer treats `*( $( @( &( #(` as block opens and any `)` as a
block close. It strips its OWN comments -- whole lines beginning `;;` -- before
looking for tokens, but it does not understand HTML, so a literal `)` inside an
`<!-- ... -->` comment closes a block just as surely as one in markup.

On 2026-09-10 that took /gene_center/gene down, the site's second most
requested URL. The entire change was a comment rewording -- no markup, no code
-- that happened to contain `retired (2026-09-10)`. The page then served 124
bytes of "Bauplan Parse Error" with status **200**, so nothing watching status
codes noticed, and the template's own error names the LAST `)` in the file
rather than the offending one, which makes its line number useless.

What it checks
--------------
Running block depth, line by line, reporting the first line where a `)` closes
something that was never opened, and any file that ends still open.

This is necessary, not sufficient: two opposing errors can cancel out, and this
says nothing about whether the tokens name templates that exist. It catches the
single stray paren, which is the failure that has actually happened.

Usage
-----
    deploy/check_bau.py templates/static/mgdb_gene.bau [...]

Exit status is 0 when every file balances, 1 otherwise.

Every path given is checked, whatever it is called. Filtering by extension
belongs to the caller: a checker that silently skips what it was handed
reports success for a file it never looked at, which is the failure mode this
script exists to prevent.
"""
import re
import sys

# A block open is one of these sigils followed by '(' -- unless the sigil is
# itself backslash-escaped, which is how a template writes literal jQuery:
#   \$("#menu_bar", document\).removeClass("menu"\);
OPEN = re.compile(r'[*$@&#]\(')
CLOSE = re.compile(r'\)')


def _unescaped(pattern, line):
    """Offsets of matches whose first character is not backslash-escaped."""
    return [m.start() for m in pattern.finditer(line)
            if m.start() == 0 or line[m.start() - 1] != '\\']


def check(path):
    """Return a list of human-readable problems with one .bau file."""
    try:
        with open(path, encoding='utf-8', errors='replace') as handle:
            lines = handle.read().split('\n')
    except OSError as error:
        return ['cannot be read: %s' % error]

    depth = 0
    closed_out = []       # lines where the outermost block fell back to depth 0
    for number, line in enumerate(lines, 1):
        # A Bauplan comment is a whole line. Every `;;` in the 200 templates
        # here starts its line, so this does not have to guess at an inline
        # form the tokenizer may or may not honour.
        if line.lstrip().startswith(';;'):
            continue

        delta = len(_unescaped(OPEN, line)) - len(_unescaped(CLOSE, line))
        if delta == 0:
            continue                       # e.g. a whole `$(token)` on one line
        was = depth
        depth += delta
        if was > 0 and depth <= 0:
            closed_out.append((number, line))

    if depth == 0 and len(closed_out) < 2:
        return []

    # Name the line that closed the outermost block, not the one the counter
    # happened to end on. A stray ")" mid-file is absorbed as if it closed the
    # template, and it is the file's own final ")" that then goes negative --
    # which is exactly the useless line number Bauplan itself reports. Every
    # one of the 200 templates here returns to depth 0 exactly once, at its
    # last line, so an earlier return to 0 IS the stray paren.
    if closed_out:
        number, line = closed_out[0]
        if number < len(lines) - 1:
            return ['line %d closes the template early:\n'
                    '      %s\n'
                    '    If that ")" is literal -- prose, a date, an HTML comment --\n'
                    '    write it "&#41;" or "\\)", or reword so there is no paren.'
                    % (number, line.strip()[:100])]

    if depth > 0:
        return ['ends with %d block(s) still open -- an opening "*(" or "$(" '
                'is missing its ")".' % depth]
    return ['has %d more ")" than it has block opens; the extra one is literal '
            'text that needs to be "&#41;" or "\\)".' % -depth]


def main(argv):
    failed = 0
    for path in argv:
        for problem in check(path):
            print('  %s: %s' % (path, problem), file=sys.stderr)
            failed += 1
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
