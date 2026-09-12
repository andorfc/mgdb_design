<?PHP
/* file: api/v1/docs.php
 *
 * purpose: the documentation page for the API, at /api (for a browser) and
 *          /api/docs (for anyone). Included by controllers/api.php before the
 *          JSON machinery starts, so it can render a page on the modern shell.
 *
 *          The page reads the same registry the service index and the OpenAPI
 *          document read -- api_record_registry() in controllers/api.php -- so
 *          the record types, their sections and the identifiers they accept
 *          cannot drift from what the API serves. The code samples are built
 *          here, in PHP, for two reasons: they carry this instance's base URL,
 *          so a copied example runs against the host it was copied from; and
 *          Bauplan parses parentheses in a .bau file as block delimiters,
 *          which a code sample is full of. Text that reaches the template from
 *          PHP is never parsed.
 *
 *          No database call is made. Everything on the page is static apart
 *          from the Try it panel, which the browser drives.
 *
 * history:
 *  09/11/26  claude  created
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  include_once('./include/api/v1/lib/mgdb_jsonld.php');

  $system = getSystemInfo('mgdb.conf');
  $base = MgdbApi::baseUrl();
  $registry = api_record_registry();
  $ld_types = MgdbJsonLd::typeMap();
  $esc = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

  logMessage('Starting api docs page');

  /////
  // Record types, identifiers, linked-data mapping, Try it options
  /////

  $type_rows = '';
  $identifier_rows = '';
  $ld_rows = '';
  $try_options = '';
  foreach ($registry as $entry) {
    $type = $entry['type'];
    $example = $entry['example'];
    $api_url = $base . '/api/v1/records/' . $type . '/' . rawurlencode($example);
    $page_url = $base . str_replace('{id}', rawurlencode($example), $entry['html']);

    $sections = '';
    foreach ($entry['sections'] as $section) {
      $sections .= '<code>' . $esc($section) . '</code> ';
    }
    $type_rows .= '<tr>'
      . '<th scope="row"><code>' . $esc($type) . '</code><span class="api-type-label">' . $esc($entry['label']) . '</span></th>'
      . '<td>' . $esc($entry['description'])
      . ($entry['notes'] !== null ? '<span class="api-note">' . $esc($entry['notes']) . '</span>' : '') . '</td>'
      . '<td class="api-nowrap"><code>' . $esc($example) . '</code><br>'
        . '<a href="' . $esc($api_url) . '">JSON</a> &middot; '
        . '<a href="' . $esc($api_url . '?format=jsonld') . '">JSON-LD</a> &middot; '
        . '<a href="' . $esc($page_url) . '">Page</a></td>'
      . '<td><span class="api-sections">' . trim($sections) . '</span></td>'
      . '</tr>' . "\n";

    $forms = '';
    foreach ($entry['identifiers'] as $form) {
      $forms .= '<li>' . $esc($form) . '</li>';
    }
    $identifier_rows .= '<tr><th scope="row"><code>' . $esc($type) . '</code></th>'
      . '<td><ul class="api-plain-list">' . $forms . '</ul></td></tr>' . "\n";

    $map = isset($ld_types[$type]) ? $ld_types[$type] : array('type' => 'Thing', 'profile' => null, 'note' => '');
    $profile = $map['profile'] === null
      ? '<span class="mgdb-muted">schema.org only</span>'
      : '<a href="' . $esc($map['profile']) . '">' . $esc(basename(dirname($map['profile'])) . ' ' . basename($map['profile'])) . '</a>';
    $ld_rows .= '<tr><th scope="row"><code>' . $esc($type) . '</code></th>'
      . '<td><code>' . $esc($map['type']) . '</code></td>'
      . '<td>' . $profile . '</td>'
      . '<td>' . $esc($map['note']) . '</td></tr>' . "\n";

    $try_options .= '<option value="' . $esc($type) . '" data-example="' . $esc($example) . '"'
      . ' data-sections="' . $esc(implode(',', $entry['sections'])) . '">'
      . $esc($entry['label']) . ' &mdash; ' . $esc($example) . '</option>';
  }

  /////
  // Code samples. {base} is this instance.
  /////

  function api_docs_example($slug, $title, $intro, $samples, $base) {
    $labels = array('curl' => 'curl', 'python' => 'Python', 'r' => 'R', 'javascript' => 'JavaScript');
    $esc = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $html = '<div class="api-example" data-api-example id="api-example-' . $esc($slug) . '">'
          . '<h3>' . $esc($title) . '</h3>'
          . '<p>' . $intro . '</p>'
          . '<div class="api-example-tabs" role="tablist" aria-label="Language for this example">';
    foreach ($labels as $lang => $label) {
      $html .= '<button type="button" role="tab" data-lang="' . $lang . '" aria-selected="' . ($lang === 'curl' ? 'true' : 'false') . '"'
             . ' aria-controls="api-example-' . $esc($slug) . '-' . $lang . '" tabindex="' . ($lang === 'curl' ? '0' : '-1') . '">'
             . $esc($label) . '</button>';
    }
    $html .= '</div>';
    foreach ($labels as $lang => $label) {
      $code = isset($samples[$lang]) ? str_replace('{base}', $base, $samples[$lang]) : '';
      $pre_id = 'api-example-' . $esc($slug) . '-' . $lang . '-code';
      $html .= '<div class="api-code" role="tabpanel" data-lang="' . $lang . '" id="api-example-' . $esc($slug) . '-' . $lang . '"'
             . ($lang === 'curl' ? '' : ' hidden') . '>'
             . '<pre id="' . $pre_id . '" tabindex="0">' . $esc(trim($code)) . '</pre>'
             . '<button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm api-copy mgdb-ref-copy" data-copy-target="' . $pre_id . '">Copy</button>'
             . '</div>';
    }
    return $html . '</div>';
  }

  $examples = '';

  $examples .= api_docs_example('record', 'Get a record',
    'One request, the whole record. The gene <code>lg1</code> by its gene model name; the symbol, the full name or a transcript name would resolve to the same record.',
    array(
      'curl' => <<<'EOT'
curl -s -H "Accept: application/json" \
  "{base}/api/v1/records/gene/Zm00001eb067740"
EOT
      , 'python' => <<<'EOT'
import requests

url = "{base}/api/v1/records/gene/Zm00001eb067740"
r = requests.get(url, headers={"Accept": "application/json"}, timeout=30)
r.raise_for_status()
record = r.json()

a = record["data"]["attributes"]
print(a["symbol"], a["full_name"], a["assembly"])
for term in record["data"]["sections"]["function"]["ontology"]:
    print(term["term"], term["name"])
EOT
      , 'r' => <<<'EOT'
library(httr2)

record <- request("{base}/api/v1/records/gene/Zm00001eb067740") |>
  req_headers(Accept = "application/json") |>
  req_perform() |>
  resp_body_json()

a <- record$data$attributes
cat(a$symbol, a$full_name, a$assembly, "\n")
sapply(record$data$sections$`function`$ontology, function(t) t$name)
EOT
      , 'javascript' => <<<'EOT'
const url = "{base}/api/v1/records/gene/Zm00001eb067740";
const response = await fetch(url, { headers: { Accept: "application/json" } });
if (!response.ok) throw new Error(`HTTP ${response.status}`);
const record = await response.json();

const a = record.data.attributes;
console.log(a.symbol, a.full_name, a.assembly);
for (const term of record.data.sections.function.ontology) {
  console.log(term.term, term.name);
}
EOT
    ), $base);

  $examples .= api_docs_example('fields', 'Ask for only the sections you need',
    '<code>fields</code> names the sections to build; every other section&#39;s queries are skipped. <code>meta.sections_returned</code> says what came back and <code>meta.partial</code> is true.',
    array(
      'curl' => <<<'EOT'
curl -s "{base}/api/v1/records/stock/CML277?fields=overview,pedigree"
EOT
      , 'python' => <<<'EOT'
import requests

r = requests.get("{base}/api/v1/records/stock/CML277",
                 params={"fields": "overview,pedigree"}, timeout=30)
record = r.json()
print(record["meta"]["query_count"], "queries;", record["meta"]["sections_returned"])
for parent in record["data"]["sections"]["pedigree"]["parents"]:
    print("parent:", parent["name"], parent["contribution_percent"])
EOT
      , 'r' => <<<'EOT'
library(httr2)

record <- request("{base}/api/v1/records/stock/CML277") |>
  req_url_query(fields = "overview,pedigree") |>
  req_perform() |>
  resp_body_json()

unlist(record$meta$sections_returned)
sapply(record$data$sections$pedigree$parents, function(p) p$name)
EOT
      , 'javascript' => <<<'EOT'
const url = new URL("{base}/api/v1/records/stock/CML277");
url.searchParams.set("fields", "overview,pedigree");
const record = await (await fetch(url)).json();

console.log(record.meta.sections_returned);
console.log(record.data.sections.pedigree.parents.map((p) => p.name));
EOT
    ), $base);

  $examples .= api_docs_example('etag', 'Re-check a record you already have',
    'Keep the <code>ETag</code> with the record. Sending it back costs one small request, and a <code>304</code> means what you have is still current. The data changes on a curation cycle, so most re-checks are a <code>304</code>.',
    array(
      'curl' => <<<'EOT'
# First request: save the record and note its ETag
curl -s -D - -o record.json \
  "{base}/api/v1/records/reference/9043389" | grep -i '^etag'

# Later: send the ETag back. 304 means record.json is still current.
curl -s -o /dev/null -w "%{http_code}\n" \
  -H 'If-None-Match: "<the etag from above>"' \
  "{base}/api/v1/records/reference/9043389"
EOT
      , 'python' => <<<'EOT'
import requests

url = "{base}/api/v1/records/reference/9043389"
first = requests.get(url, timeout=30)
etag = first.headers["ETag"]

again = requests.get(url, headers={"If-None-Match": etag}, timeout=30)
print(again.status_code)   # 304: unchanged, and no body was sent
EOT
      , 'r' => <<<'EOT'
library(httr2)

url <- "{base}/api/v1/records/reference/9043389"
first <- request(url) |> req_perform()
etag <- resp_header(first, "ETag")

again <- request(url) |>
  req_headers(`If-None-Match` = etag) |>
  req_error(is_error = function(resp) FALSE) |>
  req_perform()
resp_status(again)   # 304 while the record is unchanged
EOT
      , 'javascript' => <<<'EOT'
const url = "{base}/api/v1/records/reference/9043389";
const first = await fetch(url);
const etag = first.headers.get("ETag");

const again = await fetch(url, { headers: { "If-None-Match": etag } });
console.log(again.status);   // 304 while the record is unchanged
EOT
    ), $base);

  $examples .= api_docs_example('jsonld', 'The same record as linked data',
    'Add <code>format=jsonld</code>, or ask for <code>application/ld+json</code>. The document uses schema.org and Bioschemas types; see <a href="#api-linked-data">Linked data</a> for what each record type becomes.',
    array(
      'curl' => <<<'EOT'
curl -s -H "Accept: application/ld+json" \
  "{base}/api/v1/records/stock/CML277"

# the same thing, as a query parameter
curl -s "{base}/api/v1/records/stock/CML277?format=jsonld"
EOT
      , 'python' => <<<'EOT'
import requests

r = requests.get("{base}/api/v1/records/stock/CML277",
                 params={"format": "jsonld"}, timeout=30)
doc = r.json()
print(doc["@type"], doc["name"])
print(doc["taxonomicRange"]["name"], doc["taxonomicRange"]["identifier"])
print(doc.get("sameAs", []))
EOT
      , 'r' => <<<'EOT'
library(httr2)

doc <- request("{base}/api/v1/records/stock/CML277") |>
  req_url_query(format = "jsonld") |>
  req_perform() |>
  resp_body_json()

doc$`@type`
doc$taxonomicRange$name
unlist(doc$sameAs)
EOT
      , 'javascript' => <<<'EOT'
const url = "{base}/api/v1/records/stock/CML277?format=jsonld";
const doc = await (await fetch(url)).json();
console.log(doc["@type"], doc.name, doc.taxonomicRange?.identifier);
EOT
    ), $base);

  $examples .= api_docs_example('list', 'A list of records',
    'One request per record, in turn rather than all at once, with the sections you need and a <code>User-Agent</code> that says who you are. A <code>404</code> is a record that did not resolve, not a failure of the loop. For a whole collection use the <a href="/download">download site</a> instead.',
    array(
      'curl' => <<<'EOT'
for id in Zm00001eb067740 Zm00001eb000010 Zm00001eb000020; do
  curl -s -A "my-lab-script/1.0 (name@example.edu)" \
    "{base}/api/v1/records/gene/$id?fields=overview,function" > "$id.json"
done
EOT
      , 'python' => <<<'EOT'
import requests

session = requests.Session()
session.headers["User-Agent"] = "my-lab-script/1.0 (name@example.edu)"

for gene in ["Zm00001eb067740", "Zm00001eb000010", "Zm00001eb000020"]:
    r = session.get(f"{base}/api/v1/records/gene/{gene}",
                    params={"fields": "overview,function"}, timeout=30)
    if r.status_code == 404:
        print(gene, "not found")
        continue
    r.raise_for_status()
    d = r.json()["data"]
    print(gene, d["attributes"]["symbol"], d["sections"]["function"]["summary"])
EOT
      , 'r' => <<<'EOT'
library(httr2)

nz <- function(x) if (is.null(x)) NA else x
genes <- c("Zm00001eb067740", "Zm00001eb000010", "Zm00001eb000020")

rows <- lapply(genes, function(g) {
  resp <- request(paste0("{base}/api/v1/records/gene/", g)) |>
    req_url_query(fields = "overview,function") |>
    req_user_agent("my-lab-script/1.0 (name@example.edu)") |>
    req_error(is_error = function(resp) FALSE) |>
    req_perform()
  if (resp_status(resp) != 200) return(data.frame(gene = g, symbol = NA, summary = NA))
  d <- resp_body_json(resp)$data
  data.frame(gene = g, symbol = nz(d$attributes$symbol),
             summary = nz(d$sections$`function`$summary))
})
do.call(rbind, rows)
EOT
      , 'javascript' => <<<'EOT'
const genes = ["Zm00001eb067740", "Zm00001eb000010", "Zm00001eb000020"];
for (const gene of genes) {   // in turn: the server is shared
  const r = await fetch(`{base}/api/v1/records/gene/${gene}?fields=overview,function`);
  if (r.status === 404) { console.log(gene, "not found"); continue; }
  const { data } = await r.json();
  console.log(gene, data.attributes.symbol, data.sections.function.summary);
}
EOT
    ), $base);

  /////
  // The envelope and error examples. Real values from this instance on
  // 2026-09-11, with every list cut to its first item.
  /////

  $envelope_example = <<<'EOT'
{
  "api_version": "1.0",
  "meta": {
    "request_id": "ad3d15637b19e4fe",
    "generated": "2026-09-11T13:24:45Z",
    "elapsed_ms": 403,
    "query_count": 26,
    "resolved_from": "lg1",
    "id_type": "locus_name",
    "other_matches": [
      { "name": "Zm00001d002005", "version": "Zm00001d.2",
        "assembly": "Zm-B73-REFERENCE-GRAMENE-4.0", "locus_name": "lg1" }
    ],
    "sections_returned": ["overview", "structure", "function", "expression",
      "variation", "pan_gene", "orthologs", "locus", "references", "xrefs", "sequences"],
    "sections_available": ["overview", "structure", "function", "expression",
      "variation", "pan_gene", "orthologs", "locus", "references", "xrefs", "sequences"],
    "partial": false,
    "max_items": 500,
    "truncated": [],
    "counts": { "transcripts": 1, "protein_domains": 1, "ontology": 7,
      "insertions": 6, "snp_traits": 36, "references": 111, "xrefs": 12,
      "pan_gene_members": 65, "map_positions": 13, "alleles": 13 }
  },
  "links": {
    "self": "{base}/api/v1/records/gene/lg1",
    "html": "{base}/gene_center/gene/Zm00001eb067740",
    "search": "{base}/gene_center/gene",
    "json_ld": "{base}/api/v1/records/gene/Zm00001eb067740?format=jsonld",
    "documentation": "{base}/api"
  },
  "data": {
    "type": "gene",
    "id": "Zm00001eb067740",
    "attributes": {
      "name": "Zm00001eb067740",
      "symbol": "lg1",
      "full_name": "liguleless1",
      "kind": "gene_model_and_locus",
      "assembly": "Zm-B73-REFERENCE-NAM-5.0",
      "annotation": "Zm00001eb.1",
      "locus_id": 12386,
      "feature_id": 6674039,
      "is_current": true
    },
    "sections": {
      "overview": {
        "chromosome": "chr2", "start": 4493424, "end": 4497434, "span_bp": 4010,
        "strand": null, "strand_note": "Strand is not recorded in this annotation load.",
        "model_type": "protein_coding", "line": "B73", "species": "Zea mays ssp. mays"
      },
      "structure": {
        "transcripts": [
          { "name": "Zm00001eb067740_T001", "protein": "Zm00001eb067740_P001", "canonical": true }
        ]
      },
      "function": {
        "summary": "metal ion binding",
        "ontology": [
          { "scope": "locus", "term": "GO:0003677", "name": "DNA binding",
            "ontology": "Gene Ontology", "evidence_code": "COMP",
            "evidence_label": "inferred computationally", "source": "MaizeCyc - Pathway",
            "url": "https://amigo.geneontology.org/amigo/term/GO%3A0003677" }
        ]
      },
      "pan_gene": {
        "pan_gene": { "name": "pan-zea.v4.pan02070", "analysis": "Pan-Zea, Aug 2025",
          "member_count": 65, "exemplar": "Zm00023ab070050_T001", "chromosome": "chr2" }
      },
      "references": {
        "references": [
          { "id": 10749003, "year": 2026, "doi": null,
            "title": "Leveraging Genome Editing to Revive Multi-Ear Traits for Climate Resilient Maize: Systematic Review" }
        ]
      },
      "xrefs": {
        "xrefs": [
          { "key": "69", "database": "AGI WebFPC v2",
            "url": "http://www.genome.arizona.edu/cgi-bin/WebAGCoL/WebFPC/WebFPC_Direct_v2.1.cgi?name=maize&contig=69" }
        ]
      }
    }
  }
}
EOT;
  $envelope_example = str_replace('{base}', $base, $envelope_example);

  $error_example = <<<'EOT'
HTTP/1.1 404 Not Found
Content-Type: application/problem+json; charset=utf-8
Cache-Control: no-store

{
  "type": "https://maizegdb.org/api/v1/problems/record-not-found",
  "title": "Gene not found",
  "status": 404,
  "detail": "No gene model or locus matches that identifier.",
  "instance": "/api/v1/records/gene/zzznotagene",
  "request_id": "0cf125aff41ee932",
  "identifier": "zzznotagene"
}
EOT;

  /* The block the gene page for lg1 embeds, built by the same function the
     page uses, with the page's own summary sentence. */
  $jsonld_doc = MgdbJsonLd::identity('gene', 'Zm00001eb067740', array(
    'name' => 'lg1',
    'description' => 'lg1 (liguleless1) is a maize gene model Zm00001eb067740 in B73 (Zm-B73-REFERENCE-NAM-5.0) '
                   . 'at chr2:4,493,424-4,497,434. Function, protein domains, expression, pan-gene membership, '
                   . 'orthologs, insertions, variation and references.',
    'attributes' => array('name' => 'Zm00001eb067740', 'symbol' => 'lg1', 'full_name' => 'liguleless1',
                          'assembly' => 'Zm-B73-REFERENCE-NAM-5.0', 'annotation' => 'Zm00001eb.1',
                          'kind' => 'gene_model_and_locus')
  ));
  $jsonld_example = '<script type="application/ld+json">' . "\n"
                  . json_encode($jsonld_doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                  . "\n" . '</script>';

  /////
  // The page
  /////

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $v = function ($path) use ($doc_root) {
    return file_exists($doc_root . $path) ? filemtime($doc_root . $path) : time();
  };

  $bauplan = new Bauplan('MaizeGDB API | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The Data Hub shell: the pale ground, white section cards with coloured
     top edges, the green Related resources panel. Loaded before the page's
     own sheet; mgdb-hub-page on <main> opts in. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v('/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-api.css?v=' . $v('/css/mgdb-api.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-api.js?v=' . $v('/js/mgdb-api.js'));
  $bauplan->head('<meta name="description" content="How to read MaizeGDB records as JSON or JSON-LD: '
    . 'endpoints, the seventeen record types, identifiers, the response envelope, caching, errors, '
    . 'and examples in curl, Python, R and JavaScript.">');
  $bauplan->head('<link rel="alternate" type="application/json" href="' . $esc($base . '/api/v1/') . '">');
  $bauplan->head('<link rel="service-desc" type="application/vnd.oai.openapi+json;version=3.1" href="'
    . $esc($base . '/api/v1/openapi') . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_api.bau');
  $content->get('api_base')->replace($esc($base));
  $content->get('openapi_url')->replace($esc($base . '/api/v1/openapi'));
  $content->get('examples')->replace($examples);
  $content->get('type_rows')->replace($type_rows);
  $content->get('identifier_rows')->replace($identifier_rows);
  $content->get('ld_rows')->replace($ld_rows);
  $content->get('try_options')->replace($try_options);
  $content->get('envelope_example')->replace($esc($envelope_example));
  $content->get('error_example')->replace($esc($error_example));
  $content->get('jsonld_example')->replace($esc($jsonld_example));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
