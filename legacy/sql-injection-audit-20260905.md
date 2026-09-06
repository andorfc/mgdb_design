# SQL injection audit, 2026-09-05

Started from three endpoints recorded in earlier sessions and one found while
converting `/traits_ibm_nam`. Rather than work from that list, the whole class
was swept: every PHP file under the web root that captures a request value
(`getCGIParam`, `$_GET`, `$_POST`, `$_REQUEST`) and then pastes that variable
into a SQL string.

## Method

Two passes, because the first was too broad and the second too narrow.

1. **Any concatenation into SQL** — 128 files. Almost all are integer record
   ids, and that swamped the signal.
2. **String context only** — the variable pasted *between quotes*, which is the
   shape that lets an attacker close the literal. 11 sites in 11 files, one of
   them already escaped.

Each was then probed with a single quote and the result measured as
**`grep -c SQLSTATE logs/mgdb.log` either side of the request**, not by reading
the response. Both a vulnerable and a fixed endpoint return a page; only the log
tells them apart.

**A probe that finds nothing proves nothing.** Four candidates returned no SQL
error at first because the request never reached the filter — these endpoints
gate each criterion behind a separate checkbox parameter (`box1`, `use2`) whose
name is nothing like the field it controls, and one takes the search term in a
parameter called `namebox` while a *different* parameter called `name` is
ignored. Read the gate before concluding an endpoint is safe.

## Fixed

All now go through `PDO::quote`, which supplies the surrounding quotes and
escapes the content. Verified inert: `%' OR '1'='1` returns no results and adds
no SQL error, while a real term still returns its results.

| File | Parameter | Was reachable |
| --- | --- | --- |
| `controllers/static/forgot_password.php` | `term` | **yes, demonstrated** |
| `search/locus/locus_adv_results.php` | `mapname` (2 sites) | **yes, demonstrated** |
| `search/phenotype/phenotype_adv_results.php` | `namebox` | yes, once gated correctly |
| `search/variation/variation_adv_results.php` | `locus` (2), `stock` | yes, once gated correctly |
| `record_data/fpc_data.php` | `id` (3), `idrb` | executes |
| `tools/ajax/getGeneReview.php` | `name` | executes |
| `tools/car/examples/stocks_ajax_php.php` | `name`, `id` | **yes, demonstrated** |

Regression evidence: `locus mapname=IBM` returns results and `mapname=zzzz`
returns none; `phenotype namebox=dwarf` returns a complete list; `variation
locus=a1` returns 10,163 bytes of results. All three return nothing and log no
error for a quote or an OR-injection.

### `forgot_password.php` was the worst of them

It is the unauthenticated password-reminder flow, and its query reads
`USERNAME, EMAIL, PASSWORD_REMINDER` from `ANNOTATION_AUTHOR`. It also carried
a bare **`echo $query;`** that printed the assembled statement to the browser on
every submission — the schema for free, and with the injection, a channel for
whatever the attacker appended. Both fixed.

One behaviour deliberately left alone: the lookup is `LIKE`, not `=`, so `%`
still matches the first account and mails its hint **to that account's own
address**. Not a disclosure to the sender, but it is an unauthenticated
mail-sending primitive. Changing it is a behaviour decision, not a fix.

## Not fixed, and why

- ~~**~40 numeric interpolations** across `record_data/` and `search/`.~~
  **Done — see "The numeric pass" below.**

Superseded note, kept for the record: this was originally estimated at ~40 sites.
It was closer to 300, because the first scan only saw one of the three ways a
value reaches SQL. The old text said:

- **~40 numeric interpolations** of the shape `WHERE ID = " . $id` across
  `record_data/` and `search/`. These are injectable too — `1 OR 1=1` is valid
  where an integer is expected — but it is a sweep of its own and every one
  needs its own regression check. Highest-value first: `search/locus`,
  `search/variation`, `search/gene_product` and `search/reference` each take
  several.
- **`legacy_curation/`** — 651 files of pre-2012 CGI. `curationIndex_test.php`
  builds a **login query** by concatenation
  (`where trim(USERNAME) = '" . trim($userName) . "'`) and answers on the web at
  83 KB. It fatals before reaching that query today, so it is not exploitable as
  it stands, but a whole curation tree in the web root is a scope decision for
  the group, not something to patch file by file.
- ~~**Dead code left as-is**: `tools/ajax/getCalendar.php`'s site is inside a
  `/* no longer supported */` block~~ — **wrong, and fixed 2026-09-06. See
  "getCalendar.php was not dead code" below.**
- **Dead code left as-is**:
  `tools/ajax/person_search/displaypersonrecord.php` cannot run at all — it uses
  Oracle `OCILogon` (the oci8 extension is not loaded) and PHP 4 call-time
  pass-by-reference, so it answers with a parse error. **A patch was written for
  it and then reverted**: `$DBConn` there is an Oracle resource, not PDO, so
  `->quote()` would have been a new fatal in place of an unreachable one.
- **`tools/car/examples/`** is example code shipped inside the web root. It was
  fixed because it executes, but it should probably not be published at all.

## Adopting them into the repo

None of these seven files were in `src/` or `deploy/manifest.txt` — they were
server-only, like `/FAIRpractices` before it. Each is now in both, so the fix
cannot be silently reverted by an upstream deploy.


---

# The numeric pass, later the same day

`search/` first, then `record_data/` and the layers underneath it.

## Three ways a value reaches SQL, and the first scan saw one

Each of these needed its own scan, and each turned up sites the previous pass
had reported as clean:

1. **Concatenation** — `" . $id` . The obvious one. 96 sites in 19 `search/`
   files, 140 in `record_data/`.
2. **In-string interpolation** — `"WHERE id=$id"` . Invisible to a scan looking
   for a concatenation dot. **156 more sites in 44 files**, and this is the
   class that kept `/data_center/environment` and `/data_center/species`
   injectable after the first pass reported them fixed.
3. **Variables that are not assigned from the request in the same file** —
   function parameters (`$lg` in `getMap()`), and values *derived* from a
   request variable (`$idtemp = $id`). Both invisible to a scan keyed on
   `getCGIParam` assignments.

**A scan that reports zero is evidence about the scan, not the code.** Three
separate passes each ended with "0 remaining" and each was wrong.

## Fixed

| Layer | Sites | Note |
| --- | ---: | --- |
| `search/*` | 96 | 19 files |
| `record_data/*` | 140 | 30 files, plus 10 string-context sites quoted |
| `controllers/data_center/*_functions.php` | 15 | the record-page resolvers |
| `include/` shared helpers | 24 | `db-api.php`, `counts_lib.php`, `api_tools.php`, `annotation_lib.php` |

**`read_synonyms()` in `include/db-api.php` was the highest-value single fix**
— `WHERE id=$id`, called from most record pages. One line protected every
caller.

**The resolvers were the reason fixing `record_data` alone did not work.** A
record page runs `check_id()` in `controllers/data_center/<type>_functions.php`
first; nine of those already computed `$iid = intval($id)`, returned it, and
then used the raw `$id` in the query anyway. The fix was to use the variable
already sitting there.

## Two mistakes worth recording

- **A blanket transform broke two files.** `gene_model_id='$id'` was cast to
  `(int)` — gene model ids are strings like `Zm00001eb000010`, so that would have
  sent `0`. And a line that was *already* a concatenation got wrapped again,
  producing `" . " . (int) $id . "`. Both were caught by reading the diff rather
  than by the linter, which passed on both. **Read every diff of a mechanical
  pass; a syntax check does not know what the column holds.**
- **The verification took the server down.** Probing `?id=1 OR 1=1` against a
  page whose query was still injectable matched every row; the page tried to
  render all of them and php-fpm was **OOM-killed** (`Result: oom-kill`,
  16 hours uptime lost). Restarted with `systemctl restart php-fpm`; the site
  was down for about two minutes. **An injection probe on an unfixed page is not
  a read-only operation** — a boolean-true injection is a denial of service
  against yourself. Probe with something that cannot match broadly (a quote, to
  provoke a syntax error) rather than `OR 1=1`.

# The string pass, finishing `record_data`

Carson: *"Finish the remaining record_data string ids."* The numeric pass had
cast the columns it could prove were numeric and left every varchar site behind,
because a blanket transform over those is precisely what broke two files earlier
in the day.

## Decide each site from `information_schema`, not from the name

The column types were read out of the catalogue first and the fix chosen per
site from that list. It is the only way to tell `variation.id` (bigint, so
`(int)`) from `z_sequence.seq_id` (varchar, so `quote()`), and the two look
identical in the source — both are written `WHERE X = '$id'`.

**56 varchar sites in 20 files** were wrapped in `$DBConn->quote()`. `quote()`
supplies its own surrounding quotes, so the call site drops the literal ones:

```php
- $query = "SELECT * from z_sequence WHERE SEQ_ID = '$id'";
+ $query = "SELECT * from z_sequence WHERE SEQ_ID = " . $DBConn->quote($id);
```

**A `LIKE` pattern folds its wildcards into the quoted value.** Leaving them
outside puts them inside the quotes `quote()` adds and they stop being
wildcards:

```php
- WHERE gm.assembly_name LIKE '%$pattern%'
+ WHERE gm.assembly_name LIKE " . $DBConn->quote('%' . $pattern . '%')
```

## `$DBConn` in scope is the check `php -l` cannot make

`->quote()` on a null is a fatal at runtime and lints clean, so the enclosing
function of all 56 sites was checked for a `$DBConn` parameter, a
`global $DBConn`, or a local assignment. **56 of 56 bound.** A file having
`$DBConn` somewhere is not the same as the *function* having it.

## A data bug found by fixing the injection

`pan_gene_data.php` built its CornCyc member list from two concatenated
`implode`s **with no separator between them**:

```php
AND f.name IN ('" . implode("','", $gm_names) . implode("','", $tr_names) . "')
```

The last gene-model name and the first transcript name fuse into one value.
Measured against the database with a five-name list: the old expression asks for
**4 names** — one of them the nonexistent `GRMZM2G457118Zm00001eb000010_T001` —
and matches 3 feature rows; merging the arrays first asks for 5 and matches 7.
**Every pan-gene record page has been silently dropping its last gene model and
first transcript from CornCyc pathways.** Fixed by merging before quoting, with
an empty-list guard because `IN ()` is a syntax error where `IN ('')` is not.

## The rest of the tail

- `gene_product_data.php` and `lg_data.php` each had `WHERE id = $type` on
  `term.id` (bigint). The `$type` there is `read_type()`'s parameter, which
  **shadows** the request's `$type` and carries a DB-derived value, so it was
  not exploitable; cast anyway.
- `bac_data.php` builds **Jira JQL**, not SQL: `project=ASMBLY AND text~'$acc'`.
  Different injection class, same shape. Escaped backslash-first, then quotes.
- `locus_data_lib.php`'s `$centimorgan` sites are PHP arithmetic, which coerces,
  so they were never injectable; given an explicit `(float)` to say so.
- `stock_catalog_data.php`'s four `$year` interpolations are already covered by
  the `^\d{4}$` validation added earlier in the day — verified that the
  validation really does precede line 37.

## Verification

Lint clean across all 25 touched files, and **every one of the 56 diffs read by
eye** rather than trusted to the linter. The API endpoints that reach this code
were exercised against real record ids pulled from the database
(`gene/GRMZM2G457118`, `gene/Zm00001eb000010`, `variation/10686504`,
`locus/41558`, `stock`, `map`, `reference`): all 200 with real payloads,
**0 new `SQLSTATE` lines and 0 new php-fpm errors**.

For safe input `quote()` emits exactly `'literal'`, so these rewrites are
provably equivalent to what they replace; the only behaviour that changes is for
input containing a quote or backslash, which is the case that was broken before.

**`z_sequence` and `zd_chr_v2_mo17snp` are both empty**, so `sequence_data.php`
and the SNP branch of `variation_data.php` could not be tested with data. That
matches the standing note that `/data_center/sequence` has been answering 200
over an empty table.

# The validate_string() pass

Carson: *"Fix the validate_string no-op across all 160 callers."* The previous
section had flagged this and declined to do it; asked directly, it was done
caller by caller, which is the only way it does not corrupt data.

## Why the function itself could not simply be made to escape

`validate_string()` was `return $input;` and its doc comment claimed it
"Checks the user's input for safety before sending it into an SQL query."
**156 call sites in 68 files trusted that.**

Making it escape is the obvious fix and it is wrong. The same values are also
bound as parameters, passed to `PDO::quote()`, and printed into HTML, so
escaping at the boundary double-escapes all three -- corrupting data quietly
rather than failing. Nor can it strip control characters: the POPcorn sequence
searches carry FASTA, where newlines are load-bearing.

What it does now is the only thing safe in every one of those contexts: **strip
NUL bytes**, which are never legitimate here and truncate strings in several
C-backed extensions. The doc comment now says plainly that it is not SQL
escaping and names the four constructs that are. A new
`mgdb_quote_list($DBConn, $values)` sits beside it for `IN ()` lists, returning
`''` for an empty array because `IN ()` is a syntax error where `IN ('')` is not.

## The callers, in three passes, each finding what the last missed

**Pass one -- the variable on the same line as SQL keywords: 54 raw uses in 13
files.** Fixed by schema: `quote()` for assembly names, search terms and LIKE
patterns; `(int)` for `id_ontology.auto_num`.

**Pass two -- the variable anywhere inside an open `$sql = "` block: 144 more.**
Invisible to pass one because a line like `WHERE mm.ID = $id` sits three lines
below the assignment. This is where the bulk was, and every one of these files
takes `$id` straight from `getCGIParam` and passes it through the no-op.

25 of those files have a purely numeric `$id`, so the fix is one line each --
**cast at the boundary**, which protects every downstream query at once:

```php
- $id = validate_input($DBConn, $id);
+ $id = (int) $id;
```

Five files use `$id` as *both* a bigint and a varchar (`fpc_data` contigs,
`sequence_data` accessions, and the `map`/`reference`/`variation` pages that
compare it against a text column in one query), so a boundary cast would have
turned their `quote($id)` sites into `quote(0)`. Those were done per site.

**Pass three -- after both of those reported clean, 12 remained.** Eight were
false positives (`$adv_results['criteria']` is HTML, not SQL). Four were real,
including a second `auto_num` site in the same file as one already fixed.

## Two things the mechanical passes got wrong

- **A regex that casts `$id` in SQL will also cast it in a log line.**
  `logMessage("id=" . (int) $id)` hides exactly the malformed input you want the
  log to show. Reverted. A URL got the same treatment and was left, since it
  emits identical output for a numeric id.
- **Reading the changed lines is not the same as reading the file.**
  `gene_translate.php` builds four `IN ()` lists by `implode("','", ...)`, and
  after rewriting the assignments and the SQL lines I had seen, **two sibling
  lines using the same variable still had their own quotes** -- so the value
  arrived as `IN (''Zm00001eb000010'')`. The regression test caught it as
  `syntax error at or near "Zm00001eb000010"`. Grep the *variable* after
  patching, not just the lines you changed.

## `->quote()` is not always the right fix

`popcorn/search/sequence_search/startsearch.php` took a `quote()` like the
others and the scope check flagged it: POPcorn has its own `makeQuery($conn,
$query, $vals)` and `$DBConn` does not exist there. `$conn` is a PDO and
`makeQuery` **binds** when handed a values array, so that site is a bound
parameter instead. Same lesson as the Oracle `displaypersonrecord.php`: check
the driver and the variable before reaching for `->quote()`.

## A dead feature found by the regression test

Probing the stock advanced search turned up `SQLSTATE[42883]: operator does not
exist: stock_genotypic_var = bigint` on a **legitimate** term, before any
injection probe. The cause is in the joins, not the literals:

```sql
LEFT OUTER JOIN mgdb.stock_genotypic_var sgv1 ON sgv1=s.id      -- alias, not column
LEFT OUTER JOIN mgdb.variation v1 ON v1.id=svg1.variation       -- sgv/svg transposed
```

All three genotypic-variation criteria carried it, and genvar2/genvar3 join on
`svg2`/`svg3`, aliases that do not exist at all. **Those three criteria have
been throwing on every use**, over a 689,478-row table. Repaired to
`sgv1.id=s.id` / `sgv1.variation`; all three now return results.

## Verification

- Scans: 0 raw SQL uses, 0 variables unprotected inside an open SQL string
  (the 8 remaining hits are HTML criteria strings).
- Lint: 951 files, 14 failures, **all 14 last modified 2026-08-04** and all
  old-PHP syntax (`&` call-time pass-by-reference, curly-brace offsets,
  `unset($this)`). None are files touched here.
- `$DBConn` bound at all 25 new `quote()` sites.
- 26 record pages requested with real ids from the database: all render with
  real titles, **0 new SQLSTATE, 0 new php-fpm errors**.
- Searches return rows for a real term and nothing for the same term plus a
  quote, **adding zero SQLSTATE lines either way** -- stock 66, locus 66,
  image_mutant 75, overgo 70, genvar1/2/3 66 each, and gene_translate returning
  real translations.

44 files synced to `src/` with md5 verified against the server; 8 were
server-only. 8 new `deploy/manifest.txt` entries, 885 total, no duplicates.

# The reflected XSS in the criteria strings

Carson: *"Now fix the reflected XSS in the criteria strings."* Flagged at the
end of the previous section; this is that work.

## Confirmed live before it was fixed, and the WAF hid it

Every advanced search builds a "Search Criteria" summary that mixes authored
markup with the user's own term, and Bauplan's `replace()` inserts the finished
string raw -- which it has to, or the `<b>` and `<i>` in that summary would
print rather than render. So the term was going into the page unescaped.

**The first probe found nothing and that was misleading.** `<script>` in the
query string comes back **HTTP 403 from Cloudflare**, so the naive payload never
reaches the origin. A plain marker showed the reflection immediately
(`descriptor</b> <i>ZZMARKERZZ</i>`), and the payloads that matter pass the WAF
untouched:

```
<b>X</b>              -> 200, reflected raw
<img src=x>           -> 200, reflected raw
"><img onerror=1>     -> 200, reflected raw
<svg onload=1>        -> 200, reflected raw
```

`onerror` and `onload` fire without a `<script>` tag, so this was live and
exploitable. **A WAF 403 is not evidence that a page is safe** -- same shape as
the `.bak` 403 earlier in the week, where the edge blocked what the origin
happily served.

## The database stores pre-encoded entities, so plain escaping is wrong

The obvious fix -- `htmlspecialchars()` on every spliced value -- would have
been a visible regression. Checking the corpus first:

```
person.name       51 rows   "A&#223;mann, D"          -> Assmann
variation.name     3 rows   "ms26-&#916;E5"           -> ms26-DeltaE5
locus.name        12 rows   "nad2-D&E(1)(mtNA)"
synonyms          483 rows  "Soil Biology & Biochemistry"
                            "Agrisure Artesian&#8482;"
```

**557 rows store HTML entities as a display convention.** Escaping those
directly prints the literal `&#223;` on the page.

So `mgdb_html()` in `include/gp_lib.php` **decodes once, then escapes**:

```php
$decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
```

Because `htmlspecialchars()` still runs last, nothing executable survives --
`&lt;script&gt;` decodes to `<script>` and is escaped straight back, and there
is only one decode pass so it cannot be double-decoded. Verified against all
nine cases: legacy entities render, every XSS vector is escaped, plain terms are
untouched, and `&amp;lt;script&amp;gt;` stays literal.

`ENT_QUOTES` is load-bearing rather than decorative: several of these terms land
inside `href="..."`, and `"><img onerror=1>` was one of the working payloads.

## 95 sites, in two groups

- **45 request-derived** -- the reflected XSS proper. 41 concatenation
  (`. $genvar1 .`) and 4 interpolation (`<b><i>$gp_name</i></b>`), across 11
  files.
- **50 DB-derived** in the same criteria strings (`. trim($arrLGName['name']) .`).
  That is stored XSS rather than reflected, so strictly outside the ask -- but
  leaving half of each line escaped is a worse trap than either state, and with
  decode-then-escape they are provably safe to wrap.

`include/stock_adv_functions.php` is a near-duplicate of the stock criteria
builder used by `/breeders_toolbox`; it needed the identical eight fixes, and a
sweep that only reads `search/` would have missed it.

## What was already safe, and what is not a sink

- **The modern JS pages already escape.** `mgdb-gene.js` and `mgdb-pan-gene.js`
  render `criteria` through their own `esc()` / `escape()`.
- **`variation_search_lib.php` builds a criteria array that nothing renders** --
  it is returned in the API payload and no JS reads it. Left alone.
- `gene_product_adv_results.php` escapes its SQL by hand with
  `str_replace("'", "''")`, which is valid Postgres quoting, so those two
  statements were not injectable. Crude, but defended.
- The term is reflected **exactly once** per page, checked with a marker -- the
  criteria block is the only sink.

## Verification

Lint clean across all 11 files (206 files in `search/` + `include/`, 2 failures,
both pre-existing). Row counts unchanged: stock 66, genvar1 66, locus 66,
reference 226. `/breeders_toolbox` and the variation hub, which include the two
library files, both render. 0 new SQLSTATE. The 10 new php-fpm warnings are all
the pre-existing `.bauc` SELinux ones.

After the fix, `<svg onload=1>` comes back `&lt;svg onload=1&gt;`,
`"><img onerror=1>` comes back `&quot;&gt;&lt;img onerror=1&gt;`, `a&b` comes
back `a&amp;b`, and plain terms (`B73`, `a1`, `maize`) are byte-identical to
before -- so nothing is double-escaped. The DB-derived synonym list still
renders (`(also known as , a1-sh2)`).

11 files synced to `src/` with md5 verified; `include/gp_lib.php` and
`include/stock_adv_functions.php` were **not in the repo at all**. 2 new
manifest entries, 888 total, no duplicates.

# The results tables: sanitise, do not escape

Carson: *"Now sweep the results tables for output escaping."* The scoping came
back with an answer that changed the job.

## Escaping the results tables would break the site

**58,733 rows carry deliberate HTML**, and the pipeline is built around it:

```
memo               58,656 rows   curator-written <a href> links
reference.title        71 rows   "A cpSSR survey of <I>Zea</I>"  -- species italics
synonyms                3 rows   "P1-pr<sup>TP</sup>"            -- allele nomenclature
web_image.caption       3 rows   "...ps1.<BR> The albino seedlings..."
```

It is not only the data. `read_synonyms()` joins its values with `<br>`, and the
row builders wrap them: `"(also known as: <i>" . read_synonyms(...) . "</i>)"`.
Bauplan's `loop()` then inserts the finished row raw. So `mgdb_html()` here would
print the tags, and italicised species names and superscript allele designations
are publishing conventions, not decoration.

**So the fix is an allowlist sanitiser, not escaping.** `mgdb_safe_html()` in
`include/gp_lib.php`, built on DOMDocument rather than regex.

## The allowlist was read off the corpus, not guessed

```
tags actually present   a, b, br, div, i, p, sup
href schemes            http 31,316   https 7,411   mailto 5
style values            "margin-left: 40px" only, 157 rows
```

And **nothing dangerous is stored today**: 0 script tags, 0 `on*` handlers,
0 `javascript:`/`data:` URLs, 0 iframes. This is hardening, not an incident.

**Unknown tags are unwrapped, not escaped.** The tag scan also turned up
`mpolacco`, `umc85`, `Aug`, `www` -- curator initials, dates and bare URLs in
angle brackets that a browser already swallows silently. Unwrapping keeps every
page rendering exactly as it does now. Escaping them instead would surface that
text, which is arguably better but is a content decision, not a security one.

## Two DOMDocument traps

- **`loadHTML` with `NOIMPLIED` and a leading `<meta>` charset takes the meta as
  the entire document** and drops the content. Every test returned empty. Wrap
  the fragment in a known container div, sanitise inside it, and serialise the
  container's *children*.
- **Without a charset declaration libxml assumes ISO-8859-1.** Since the meta
  trick is unavailable, non-ASCII is pre-encoded to numeric entities -- which is
  the convention the database already uses -- and pure ASCII is parsed.

## Applied at the choke points

`read_comment()` (19 callers) and `read_synonyms()` (20 callers) in
`include/db-api.php` are the shared accessors behind the legacy results tables
and record pages, so two edits covered ~39 files. `read_comment` gets both
treatments: `mgdb_safe_html()` on the memo, `mgdb_html()` on the type and
authority names beside it, which are plain text.

Then 25 sites in 13 files that bypass those accessors -- image captions, the
QTL comments block, the overgo sequence highlight, and
`metabolic_pathway_results.php`, which splices annotator `FIRST_NAME` /
`LAST_NAME`. Those two columns are written by **`controllers/static/create_account.php`,
a public form**, which makes them the one genuinely untrusted input in this set.

**The modern pages were checked, not assumed, and left alone.** `mgdb-gene.js`,
`mgdb-pan-gene.js`, `mgdb-map.js`, `mgdb-alphafill.js`, `mgdb-stock.js` and
`mgdb-hot-new-papers.js` all define an escaping helper and use it
(`escapeHtml(r.memo)`). Sanitising server-side as well would have changed
nothing there and risked double-handling.

## Verification

14 attack vectors, all neutralised, 0 surviving: script tags, `<img onerror>`,
`<svg onload>`, `javascript:` / `data:` / `vbscript:` hrefs, a tab-obfuscated
`java&#9;script:`, `style="expression(...)"`, `url(javascript:)`, iframe, form,
nested script, `onmouseover`, and uppercase `<SCRIPT>`. Eleven legitimate cases
all preserved.

**Ten record pages are byte-identical to their pre-change baselines** -- stock,
locus, reference, term, map, bac, variation, gene, bin_viewer, stock_catalog,
delta +0 on every one, 0 new SQLSTATE, 0 new php-fpm errors.

Byte-identical could equally mean the sanitiser never ran, so it was proved
separately on five records whose memo really does carry a link (stock 3530882,
locus 9023235, variation 77503, phenotype 9020738, map 940880): every link
survives, and `<a href ="...">` normalises to `<a href="...">`, which is the
sanitiser running.

14 files synced with md5 verified, 4 server-only. 4 new manifest entries, 896
total, no duplicates.

# Finishing the remaining sites

Carson: *"Now finish the remaining 166 memo/caption/description sites."*
The scan came back at 173. **42 of them should not be touched**, and working out
which was most of the job:

```
29  JSON payload, JS escapes at render   include/api/v1/**
 6  SQL write, not output                popcorn ':description' bind params
 2  plain-text email body                FreeText.php $message .= ...
 1  CSV export + JS escapes              map_search_lib.php
 1  GFF attribute, text/plain download   downloadGeneModelIssues.php
 3  already escaped                      pdEsc / peEsc / mgdb_project_esc
```

A further 25 were conditions (`if (isset($x['description']))`), selections, or
values already wrapped in `urlencode`. **106 were real sinks**, and 7 of those
were skipped for cause (a vendored Jira library, a literal `"(none)"`, an
already-escaped video description, a mistyped key, and two whose sink is in JS).

## The JS side was checked, not assumed

Scanning `js/**` for these fields spliced into markup found **3** unescaped
sites. One was a false positive (`table.caption.innerHTML` reads a DOM
`<caption>`). One was `image_table.js`, already covered server-side. The third
was real:

```js
document.getElementById('obo_description').innerHTML = data.description;
```

`data.description` comes from an **external OBO web service**. Fixed in
`js/OBO.js` by moving both it and `data.domain` to `textContent` -- an ontology
definition is plain text, so the sink is the right place and no server change
was needed. That is why `OBO.php` and `EC.php` were left alone.

Everything else escapes: `escapeHtml(r.memo)` and friends across
`mgdb-gene.js`, `mgdb-pan-gene.js`, `mgdb-map.js`, `mgdb-alphafill.js`,
`mgdb-stock.js` and `mgdb-hot-new-papers.js`. **`MgdbApi::text()` only
normalises whitespace** -- it is not an escaper -- so the API returning raw and
the JS escaping at render is the architecture, and it is correct.

## The batch broke 18 lines and the linter passed all of them

The patcher wrapped the **first** array access on each line, which on an
assignment is the *left-hand side*:

```php
mgdb_safe_html($img[$count]['caption']) = $arrImage['caption'];   // not assignable
mgdb_safe_html($arrComments['memo']) = "";
'description' => isset(mgdb_safe_html($row['description'])) ? ...
```

**`php -l` reported no syntax errors on any of them** -- `f(x) = y` is a parse
error only at runtime in PHP, and the `isset()` one is valid syntax with wrong
meaning. Found by a structural scan for `helper(...) =`, not by the linter.
18 sites in 12 files, repaired by moving the wrap to the right-hand side, with
a bare `= ""` reset simply unwrapped. **Third time a mechanical pass has done
this; the lesson is not "read the diffs" but "write a structural check for the
specific way the transform can be wrong, and run it".**

That structural check then raised 15 more "unbalanced parens" which were all
false positives -- multi-line `array(...)` calls and parens inside string
literals. `php -l` is authoritative for paren balance; the per-line heuristic is
not.

## One ordering bug worth keeping

```php
- truncate(mgdb_safe_html($row['DESCRIPTION']), 45)
+ mgdb_safe_html(truncate($row['DESCRIPTION'], 45))
```

Sanitising and *then* truncating can cut an HTML tag in half and emit broken
markup. Truncate first; the sanitiser repairs whatever the cut left behind.

## Two shapes that needed a different helper

- **`FreeText.php:100` renders into a `<textarea>`** for a curator to edit.
  That needs `htmlspecialchars()` **without** the entity decode `mgdb_html()`
  does -- decoding would turn a stored `A&#223;mann` into `Aßmann` and the next
  save would silently rewrite the record.
- **`lib_curation_UI.php` splices into `value="..."`**, an attribute, so
  `mgdb_html()` rather than the sanitiser.

## Verification

99 sites wrapped, 0 no-match, 18 repaired. 1,062 files linted with **the same 14
pre-existing failures and no new ones**.

Every record page grew by **exactly +6,411 bytes**, including `/bin_viewer` and
`/stock_catalog`, which this batch never touched -- a peer edited
`templates/home/megamenu_modern/*` at 17:46 and 18:02 and `css/mgdb-modern.css`
at 23:09, all after my 17:28 edits. The clean proof that my own contribution is
zero bytes: **the two advanced-search results pages carry no megamenu, go
through the changed code, and are byte-identical to before the batch** (84,127
and 876,327).

`read_comment()` was checked directly rather than through a page: 679 chars for
stock 3530882 with its `<a href` intact, and well-formed output for
recombination 9017569 (`<b>Annotation:</b> This linkage data ...`), whose text
renders on the live page. 0 SQLSTATE, 0 new php-fpm errors.

52 files synced with md5 verified, 14 server-only. 15 new manifest entries, 913
total, no duplicates.

## Still open

- **`popcorn/curation/lib_curation_utils.php:112` reads `$row['deescription']`**
  -- a typo, the only occurrence in the tree, so `$result['DESCRIPTION']` has
  always been null there. Not fixed: correcting it would surface descriptions
  that have been blank for years, which is a content decision.
- **`tools/ajax/person_search/displaypersonrecord.php` cannot run** (PHP 4
  call-time pass-by-reference, one of the 14 lint failures). Its four sites are
  fixed but inert.
- `controllers/curation/FreeText.php` still escapes its SQL with `addslashes()`.
- `legacy_curation/` (651 files); `tools/car/examples/`.
- **The Bauplan template cache is disabled by SELinux** -- `templates/` is
  `httpd_sys_content_t`, which httpd cannot write. Free performance.


---

# getCalendar.php was not dead code

Found 2026-09-06, while checking which untracked files under `src/` were absent
from the deploy manifest. `tools/ajax/getCalendar.php` was the only one, which
is what drew attention to it: every other file the audit adopted had been put in
both places, and this one had been put in neither.

**The triage above is wrong.** The file has four interpolations of
`$_GET['id']`. One is inside the `/* no longer supported */` block, and that is
the one the audit read. The other three are ordinary live code, one per branch
of the `$type` switch:

| Line | Branch | Query fragment |
|---:|---|---|
| 26 | `allele` | `where B.VARIATIONOF = " . $id` |
| 36 | `reference` | `WHERE a.ID = " . $id` |
| 43 | `overview` | `from ID_NUM WHERE ID = " . $id` |

**Confirmed live before fixing**, on the development instance, with a value that
provokes a syntax error rather than a broad match — the lesson from the numeric
pass, where `?id=1 OR 1=1` OOM-killed php-fpm. `?id=zzz&type=overview` returned
HTTP 200 and an empty body, and `logs/mgdb.log` recorded what actually reached
the database:

```
Query =
      select TO_CHAR(max(mod_date),'mm/dd/yyyy') as VDATE
      from ID_NUM WHERE ID = zzz

PDOException: SQLSTATE[42703]: Undefined column: 7
  ERROR:  column "zzz" does not exist
```

Unauthenticated, no session, no referer check.

**Why the empty body hid it.** The endpoint can never print anything, in any
circumstance: it fills `$arr_date["VDATE"]` and then tests `$arr_date['vdate']`,
lowercase, before echoing. So an operator probing it sees 200 and nothing at
all, whether or not the query ran — and the request never appears in the access
log as an error. The blank response is not evidence the code is dead. **This bug
is left in place**: fixing the key would turn a silent endpoint into one that
emits an `<img>` pointing at `/tools/calendar/calendar_date.php`, which is a
behaviour change on an endpoint nothing calls, and is not what a security fix
should do on its way past.

**The fix** is the numeric-pass idiom. `id_num.id`, `locus.id` and
`variation.variationof` are all `bigint` in `information_schema`, so `$id` is
cast once to `$iid = (int) $id` where it is read and the three sites use that.
Re-probed after: all three branches log `WHERE ID = 0` and throw nothing.

The file is now in `src/` and `deploy/manifest.txt`, like the other seven.

**What this says about the method.** The audit's scan found this file — it is in
the "not fixed" list, not missing from it. It was lost at the triage step, by
reading one match in a file with four and generalising from it. A file that
appears in a scan with several hits needs every hit classified, not the first
one read.
