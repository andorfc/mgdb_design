#!/usr/bin/env python3
"""Build the Fowler Ds-GFP line table from the legacy Bauplan partials.

    python3 tools/fowler_lines.py
    deploy/deploy.sh src/data/projects/fowler_insertion_validation/lines.json
    deploy/deploy.sh src/data/projects/fowler_insertion_validation/fowler_ds_gfp_lines.tsv

Reads the two tables that were the whole of the legacy
/documentation/fowler_insertion_validation page, archived under
legacy/fowler-insertion-validation/, and writes the JSON the page controller
renders from plus a TSV for download.

WHY THIS IS A SCRIPT AND NOT A HAND-TYPED TABLE
-----------------------------------------------
The partials are an Excel "save as HTML" export. Excel split 47 of the cells so
that their last one to three characters sat inside a hidden span:

    <td>GCAGCTGCAGTTGTACACAGTACA<span style='display:none'>GAG</span></td>

The browser showed GCAGCTGCAGTTGTACACAGTACA and hid GAG, and text inside a
display:none element is not copied, so every reader of that page got primer
sequences up to three bases short of the real ones. 46 of the 47 are in the two
primer columns; the 47th made an expression class read `vegetative_cell_hig`.

Stripping tags and keeping the text -- which is what this script does -- is what
restores them. Anything hand-typed off the rendered page would carry the bug
forward, which is the whole reason the data is generated rather than copied.

CHECKS
------
The script asserts what the page's prose has always claimed: 64 verified lines,
19 unverified, 83 in total, 10 with a male transmission rate significantly
different from 50%, and every primer a run of A, C, G and T. It exits non-zero
if any of that stops being true, so a re-run after an edit to the source cannot
quietly produce a different table.
"""

import datetime
import html
import json
import os
import re
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(REPO, 'legacy', 'fowler-insertion-validation')
OUT = os.path.join(REPO, 'src', 'data', 'projects', 'fowler_insertion_validation')

COLUMNS = ['allele', 'rate_raw', 'gene_v4', 'gene_v3', 'expression', 'primer1', 'primer2']


def cells(table_html):
    """Every row of a table as a list of plain-text cells."""
    rows = []
    for tr in re.findall(r'<tr[^>]*>(.*?)</tr>', table_html, re.S):
        row = []
        for td in re.findall(r'<td[^>]*>(.*?)</td>', tr, re.S):
            # Tags out, text kept -- including the text Excel hid.
            text = re.sub(r'<[^>]+>', '', td)
            text = html.unescape(text)
            text = re.sub(r'\s+', ' ', text).strip()
            # The partials are Bauplan, so their literal parentheses are escaped.
            text = text.replace('\\)', ')').replace('\\(', '(')
            row.append(text)
        if row:
            rows.append(row)
    return rows


def parse(filename, status):
    with open(os.path.join(SRC, filename), encoding='utf-8') as handle:
        rows = cells(handle.read())
    out = []
    for row in rows[1:]:                       # row 0 is the header
        if len(row) != len(COLUMNS):
            sys.exit('%s: expected %d cells, found %d: %r'
                     % (filename, len(COLUMNS), len(row), row))
        line = dict(zip(COLUMNS, row))
        rate_raw = line.pop('rate_raw')
        # "43.8% **" -- the ** marked significance and was explained only in the
        # column heading. It becomes a field so the page can say it in words.
        match = re.match(r'([\d.]+)%', rate_raw)
        line['rate'] = float(match.group(1)) if match else None
        line['rate_raw'] = rate_raw.replace('**', '').strip()
        line['significant'] = '**' in rate_raw
        line['status'] = status
        out.append(line)
    return out


def main():
    lines = parse('fowler_TableA.bau', 'verified') + parse('fowler_TableB.bau', 'unverified')
    lines.sort(key=lambda line: line['allele'])

    counts = {
        'total': len(lines),
        'verified': sum(1 for line in lines if line['status'] == 'verified'),
        'unverified': sum(1 for line in lines if line['status'] == 'unverified'),
        'transmission_defect': sum(1 for line in lines if line['significant']),
    }

    expected = {'total': 83, 'verified': 64, 'unverified': 19, 'transmission_defect': 10}
    if counts != expected:
        sys.exit('counts changed: got %r, the page says %r' % (counts, expected))

    alleles = [line['allele'] for line in lines]
    if len(set(alleles)) != len(alleles):
        sys.exit('duplicate allele names')

    for line in lines:
        for key in ('primer1', 'primer2'):
            if not re.fullmatch(r'[ACGT]+', line[key]):
                sys.exit('%s %s is not a DNA sequence: %r' % (line['allele'], key, line[key]))

    os.makedirs(OUT, exist_ok=True)

    payload = {
        'generated': datetime.datetime.now(datetime.timezone.utc)
                       .replace(microsecond=0).isoformat(),
        'generator': 'tools/fowler_lines.py',
        'source': ('legacy/fowler-insertion-validation/fowler_TableA.bau and '
                   'fowler_TableB.bau, the tables on the legacy '
                   '/documentation/fowler_insertion_validation page. They '
                   'reproduce S6 Table of Warman et al. 2020, PLoS Genet '
                   '16(4):e1008462.'),
        'note': ("47 cells of the source carried their last one to three "
                 "characters inside a display:none span, so the legacy page "
                 "displayed primers up to three bases short and one expression "
                 "class as vegetative_cell_hig. Those characters are restored "
                 "here."),
        'counts': counts,
        'expression_classes': sorted({line['expression'] for line in lines}),
        'lines': lines,
    }

    json_path = os.path.join(OUT, 'lines.json')
    with open(json_path, 'w', encoding='utf-8') as handle:
        json.dump(payload, handle, indent=1)
        handle.write('\n')

    header = ['ds_gfp_allele', 'status', 'male_transmission_rate',
              'significantly_different_from_50pc', 'gene_v4', 'gene_v3',
              'expression_class', 'primer_1', 'primer_2']
    tsv_path = os.path.join(OUT, 'fowler_ds_gfp_lines.tsv')
    with open(tsv_path, 'w', encoding='utf-8') as handle:
        handle.write('\t'.join(header) + '\n')
        for line in lines:
            handle.write('\t'.join([
                line['allele'], line['status'], line['rate_raw'],
                'yes' if line['significant'] else 'no',
                line['gene_v4'], line['gene_v3'], line['expression'],
                line['primer1'], line['primer2'],
            ]) + '\n')

    print('wrote %s  (%d lines)' % (json_path, len(lines)))
    print('wrote %s' % tsv_path)
    print('%(total)d lines: %(verified)d verified, %(unverified)d not recovered, '
          '%(transmission_defect)d with a transmission defect' % counts)


if __name__ == '__main__':
    main()
