import json, sys

D = json.load(open('menu.json'))
LIMIT = 110

rows = [('panel', 'menu_item', 'description', 'chars')]
for p in D:
    for it in p['items']:
        rows.append((p['panel'], it['label'], it['desc'], str(len(it['desc']))))

# TSV cannot survive a tab or a newline inside a field, and has no quoting to
# fall back on. Fail loudly rather than write a file that reads back misaligned.
for r in rows:
    for cell in r:
        if '\t' in cell or '\n' in cell or '\r' in cell:
            sys.exit('field contains a tab or newline: %r' % (cell,))

with open('megamenu_descriptions.tsv', 'w', encoding='utf-8', newline='\n') as fh:
    for r in rows:
        fh.write('\t'.join(r) + '\n')

over = [r for r in rows[1:] if int(r[3]) > LIMIT]
print('megamenu_descriptions.tsv: %d rows, %d panels' % (len(rows) - 1, len(D)))
print('longest: %s chars' % max(int(r[3]) for r in rows[1:]))
print('over the %d budget: %s' % (LIMIT, over if over else 'none'))
