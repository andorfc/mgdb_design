<?php
/* file: api/v1/lib/mgdb_jsonld.php
 *
 * purpose: one mapping from a MaizeGDB record to JSON-LD, so a record can be
 *          read by machines that do not know this database's own JSON.
 *
 *          The same mapping serves three callers:
 *
 *          1. GET /api/v1/records/{type}/{id}?format=jsonld (or a request with
 *             Accept: application/ld+json) -- the full envelope becomes a
 *             linked-data document, served as application/ld+json.
 *          2. Every modern record page embeds a <script type="application/ld+json">
 *             block built from the identity the controller already has, plus a
 *             <link rel="alternate"> to the JSON and a <link rel="describedby">
 *             to the JSON-LD, and sends the same two pointers as an HTTP Link
 *             header (FAIR Signposting, signposting.org). headMarkup() and
 *             signpost() are those two.
 *          3. The /api documentation page lists the type each record maps to.
 *
 *          Types: schema.org where it has one (Gene, Protein, BioChemEntity,
 *          Taxon, ScholarlyArticle, DefinedTerm, Dataset, Thing), and the
 *          Bioschemas-only BioSample under a bs: prefix, because BioSample is
 *          not a schema.org term and writing it bare would silently expand to
 *          a URL that does not exist. Where a Bioschemas profile exists the
 *          document says which one with dct:conformsTo, which is what the
 *          Bioschemas harvesters key on.
 *
 *          Everything is built from the API payload, never retyped, so the
 *          linked data cannot drift from the record. A property with nothing
 *          behind it is dropped rather than emitted empty.
 *
 *          No MGDB_API guard: this file has no side effects and is included by
 *          the record page controllers as well as by the API.
 *
 * history:
 *  09/11/26  claude  created
 */

class MgdbJsonLd {

  const LICENSE_URL = 'https://www.usa.gov/government-works';

  /* What each record type becomes. `type` is the JSON-LD @type; `profile` is
     the Bioschemas profile the document declares conformance to, or null when
     none applies; `note` is what the documentation page prints. locus and
     gene_product pick between two types at run time (see the mappers). */
  public static function typeMap() {
    return array(
      'gene' => array('type' => 'Gene', 'profile' => 'https://bioschemas.org/profiles/Gene/1.0-RELEASE',
        'note' => 'Bioschemas Gene. GO terms become hasMolecularFunction, isInvolvedInBiologicalProcess and isLocatedInSubcellularLocation; the canonical protein is encodesBioChemEntity; the pan-gene is isPartOfBioChemEntity; external database entries are sameAs.'),
      'gene_product' => array('type' => 'Protein', 'profile' => 'https://bioschemas.org/profiles/Protein/0.11-RELEASE',
        'note' => 'Bioschemas Protein, or BioChemEntity for an RNA product. Encoding loci are isEncodedByBioChemEntity; UniProt and EC entries are sameAs.'),
      'pan_gene' => array('type' => 'BioChemEntity', 'profile' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
        'note' => 'Bioschemas BioChemEntity whose hasBioChemEntityPart lists the member gene models (first 100).'),
      'locus' => array('type' => 'BioChemEntity', 'profile' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
        'note' => 'Bioschemas Gene when the locus type is Gene, otherwise BioChemEntity with the locus type as a property.'),
      'variation' => array('type' => 'BioChemEntity', 'profile' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
        'note' => 'BioChemEntity; an allele carries additionalType SO:0001023 and a SNP SO:0000694. The locus is isPartOfBioChemEntity.'),
      'marker' => array('type' => 'BioChemEntity', 'profile' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
        'note' => 'BioChemEntity with additionalType SO:0000051 (probe) or SO:0001645 (genetic marker).'),
      'primer' => array('type' => 'BioChemEntity', 'profile' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
        'note' => 'BioChemEntity with additionalType SO:0000112 for a primer; the sequence and melting temperature are properties.'),
      'linkage_group' => array('type' => 'BioChemEntity', 'profile' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
        'note' => 'BioChemEntity with additionalType SO:0000340 (chromosome) where the linkage group is one.'),
      'stock' => array('type' => 'bs:BioSample', 'profile' => 'https://bioschemas.org/profiles/BioSample/0.1-RELEASE',
        'note' => 'Bioschemas BioSample. The provider is custodian; GRIN and other accessions are sameAs.'),
      'reference' => array('type' => 'ScholarlyArticle', 'profile' => null,
        'note' => 'schema.org ScholarlyArticle: authors, journal, year, DOI and PubMed identifiers, the abstract, and the records the paper describes as about.'),
      'term' => array('type' => 'DefinedTerm', 'profile' => null,
        'note' => 'schema.org DefinedTerm with the definition, the term type as its DefinedTermSet, and any ontology accession as termCode.'),
      'phenotype' => array('type' => 'Thing', 'profile' => null,
        'note' => 'schema.org Thing: no vocabulary in common use describes a phenotype record; trait, value and inheritance are properties.'),
      'map' => array('type' => 'Dataset', 'profile' => 'https://bioschemas.org/profiles/Dataset/1.0-RELEASE',
        'note' => 'schema.org Dataset in the MaizeGDB DataCatalog, with the map author as creator and the JSON record as its distribution.'),
      'gel' => array('type' => 'Dataset', 'profile' => 'https://bioschemas.org/profiles/Dataset/1.0-RELEASE',
        'note' => 'schema.org Dataset; the gel images are image.'),
      'recombination' => array('type' => 'Dataset', 'profile' => 'https://bioschemas.org/profiles/Dataset/1.0-RELEASE',
        'note' => 'schema.org Dataset.'),
      'map_scores' => array('type' => 'Dataset', 'profile' => 'https://bioschemas.org/profiles/Dataset/1.0-RELEASE',
        'note' => 'schema.org Dataset.'),
      'qtl' => array('type' => 'Dataset', 'profile' => 'https://bioschemas.org/profiles/Dataset/1.0-RELEASE',
        'note' => 'schema.org Dataset; the traits evaluated are variableMeasured.')
    );
  }

  /* ---------------------------------------------------------------------
     URLs
     --------------------------------------------------------------------- */

  /* Same derivation as MgdbApi::baseUrl(), repeated here because the record
     page controllers include this file without the API framework. Not
     $system['root_url']: include/gp_lib.php builds that as http:// whatever
     the request was. */
  public static function baseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      $scheme = (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http';
    }
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'maizegdb.org';
    $host = preg_replace('/[^A-Za-z0-9\.\-:]/', '', $host);
    return $scheme . '://' . $host;
  }

  /* The human page for a record, absolute. Mirrors the links.html each API
     resource returns; kept here so the head markup and the JSON-LD agree
     without a database call. */
  public static function htmlUrl($type, $id) {
    $id = (string) $id;
    switch ($type) {
      case 'gene':          $path = '/gene_center/gene/' . rawurlencode($id); break;
      case 'pan_gene':      $path = '/pan_gene_center/pan_gene/' . rawurlencode($id); break;
      case 'map':           $path = '/data_center/map/' . rawurlencode($id); break;
      case 'linkage_group': $path = '/data_center/lg/' . rawurlencode($id); break;
      default:              $path = '/data_center/' . $type . '?id=' . rawurlencode($id);
    }
    return self::baseUrl() . $path;
  }

  /* The API record. A gene with no gene model is identified as locus:{id}
     inside the API envelope; the resource resolves the bare locus id, so that
     is what goes in the URL. */
  public static function apiUrl($type, $id) {
    $id = (string) $id;
    if ($type === 'gene' && strpos($id, 'locus:') === 0) {
      $id = substr($id, 6);
    }
    return self::baseUrl() . '/api/v1/records/' . $type . '/' . rawurlencode($id);
  }

  public static function jsonLdUrl($type, $id) {
    return self::apiUrl($type, $id) . '?format=jsonld';
  }

  /* ---------------------------------------------------------------------
     Entry points
     --------------------------------------------------------------------- */

  /* The full document from an API envelope (the array MgdbApi::send builds). */
  public static function fromEnvelope($env) {
    $data = (isset($env['data']) && is_array($env['data'])) ? $env['data'] : array();
    $type = isset($data['type']) ? (string) $data['type'] : '';
    $id = isset($data['id']) ? (string) $data['id'] : '';
    $a = (isset($data['attributes']) && is_array($data['attributes'])) ? $data['attributes'] : array();
    $sec = (isset($data['sections']) && is_array($data['sections'])) ? $data['sections'] : array();
    $meta = (isset($env['meta']) && is_array($env['meta'])) ? $env['meta'] : array();
    $links = (isset($env['links']) && is_array($env['links'])) ? $env['links'] : array();
    $hint = (isset($env['jsonld_hint']) && is_array($env['jsonld_hint'])) ? $env['jsonld_hint'] : array();

    $page = (isset($links['html']) && is_string($links['html']) && $links['html'] !== '')
          ? $links['html'] : self::htmlUrl($type, $id);
    // links.html is relative on one resource and absolute on the rest.
    if (strpos($page, '/') === 0) { $page = self::baseUrl() . $page; }

    $mapper = 'map_' . $type;
    if (!method_exists(__CLASS__, $mapper)) { $mapper = 'map_generic'; }
    $doc = self::$mapper($type, $id, $a, $sec, $meta, $page, $hint);

    // Every document carries the same frame, in the same order.
    $frame = array(
      '@context' => array(
        '@vocab' => 'https://schema.org/',
        'bs' => 'https://bioschemas.org/',
        'dct' => 'http://purl.org/dc/terms/'
      ),
      '@type' => null, '@id' => $page, 'dct:conformsTo' => null,
      'identifier' => null, 'name' => null, 'alternateName' => null,
      'description' => null, 'url' => $page, 'mainEntityOfPage' => $page
    );
    $out = array_merge($frame, $doc);
    if (!isset($out['identifier']) || $out['identifier'] === null) {
      $out['identifier'] = self::identifier($id);
    }
    return self::clean($out);
  }

  /* A document from what a page controller has before any section is fetched:
     the record's name and the one-sentence summary it already writes into
     <meta name="description">, plus any attributes it holds. The result is
     the same shape as the full document, with fewer properties. */
  public static function identity($type, $id, $fields) {
    $fields = is_array($fields) ? $fields : array();
    $attributes = (isset($fields['attributes']) && is_array($fields['attributes'])) ? $fields['attributes'] : array();
    if (isset($fields['name']) && self::s($fields['name']) !== null) {
      $key = ($type === 'reference') ? 'title' : (($type === 'pan_gene') ? 'pan_gene_name' : 'name');
      if (!isset($attributes[$key])) { $attributes[$key] = $fields['name']; }
    }
    $hint = array();
    if (isset($fields['description'])) { $hint['description'] = $fields['description']; }
    if (isset($fields['alternate_names'])) { $hint['alternate_names'] = (array) $fields['alternate_names']; }
    return self::fromEnvelope(array(
      'data' => array('type' => $type, 'id' => (string) $id, 'attributes' => $attributes, 'sections' => array()),
      'links' => array('html' => self::htmlUrl($type, $id)),
      'jsonld_hint' => $hint
    ));
  }

  /* The three head elements a record page adds. The JSON is written with
     JSON_HEX_TAG so a "</script>" inside a database string cannot close the
     block: < and > become \u003C and \u003E, which is still valid JSON. */
  public static function headMarkup($type, $id, $fields = array()) {
    $doc = self::identity($type, $id, $fields);
    $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                              | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) { return ''; }
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    return "\n\t\t<link rel=\"alternate\" type=\"application/json\" href=\"" . $h(self::apiUrl($type, $id)) . "\">"
         . "\n\t\t<link rel=\"describedby\" type=\"application/ld+json\" href=\"" . $h(self::jsonLdUrl($type, $id)) . "\">"
         . "\n\t\t<script type=\"application/ld+json\">" . $json . "</script>";
  }

  /* The same two pointers as an HTTP Link header, so a client that only does
     a HEAD request, or one that reads headers before parsing HTML, finds the
     machine-readable record. Appended, never replacing another Link header. */
  public static function signpost($type, $id) {
    if (headers_sent()) { return; }
    header('Link: <' . self::apiUrl($type, $id) . '>; rel="alternate"; type="application/json", '
         . '<' . self::jsonLdUrl($type, $id) . '>; rel="describedby"; type="application/ld+json"', false);
  }

  /* ---------------------------------------------------------------------
     Mappers, one per record type
     --------------------------------------------------------------------- */

  private static function map_gene($type, $id, $a, $sec, $meta, $page, $hint) {
    $model = self::s(self::g($a, 'name'));
    $symbol = self::s(self::g($a, 'symbol'));
    $full = self::s(self::g($a, 'full_name'));
    $ov = self::arr(self::g($sec, 'overview'));
    $name = $symbol !== null ? $symbol : ($model !== null ? $model : $id);

    $alt = array();
    if ($model !== null && strcasecmp($model, $name) !== 0) { $alt[] = $model; }
    if ($full !== null && strcasecmp($full, $name) !== 0) { $alt[] = $full; }
    foreach (self::arr(self::g(self::arr(self::g($sec, 'locus')), 'synonyms')) as $row) {
      $alt[] = self::s(self::g($row, 'name'));
    }
    $alt = self::merge($alt, self::hintAlt($hint));

    $description = self::hintDesc($hint);
    if ($description === null) {
      $fn = self::arr(self::g($sec, 'function'));
      $description = self::s(self::g($fn, 'summary'));
    }
    if ($description === null) {
      $parts = array($name . ($full !== null && strcasecmp($full, $name) !== 0 ? ' (' . $full . ')' : ''));
      $parts[] = ($model !== null) ? 'is a maize gene model' : 'is a classical maize gene';
      if ($model !== null && $name !== $model) { $parts[] = $model; }
      if (self::s(self::g($ov, 'line')) !== null) { $parts[] = 'in ' . self::g($ov, 'line'); }
      if (self::s(self::g($a, 'assembly')) !== null) { $parts[] = '(' . self::g($a, 'assembly') . ')'; }
      if (self::s(self::g($ov, 'chromosome')) !== null && self::g($ov, 'start') !== null) {
        $parts[] = 'at ' . self::g($ov, 'chromosome') . ':' . self::g($ov, 'start') . '-' . self::g($ov, 'end');
      }
      $description = implode(' ', $parts) . '.';
    }

    $doc = array(
      '@type' => 'Gene',
      'dct:conformsTo' => 'https://bioschemas.org/profiles/Gene/1.0-RELEASE',
      'identifier' => self::identifier($model !== null ? $model : $id),
      'name' => $name,
      'alternateName' => $alt,
      'description' => $description,
      'taxonomicRange' => self::taxon(self::s(self::g($ov, 'species')) !== null ? self::g($ov, 'species') : 'Zea mays')
    );

    // Where it is. Bioschemas has no settled coordinate property for a gene,
    // so the placement is plain PropertyValues a client can read without a
    // profile.
    $props = array(
      self::prop('assembly', self::g($a, 'assembly')),
      self::prop('annotation', self::g($a, 'annotation')),
      self::prop('chromosome', self::g($ov, 'chromosome')),
      self::prop('start', self::g($ov, 'start')),
      self::prop('end', self::g($ov, 'end')),
      self::prop('strand', self::g($ov, 'strand')),
      self::prop('gene model type', self::g($ov, 'model_type')),
      self::prop('record kind', self::g($a, 'kind'))
    );
    // The proteins the transcripts encode.
    $proteins = array();
    $structure = self::arr(self::g($sec, 'structure'));
    foreach (self::arr(self::g($structure, 'transcripts')) as $t) {
      $p = self::s(self::g($t, 'protein'));
      if ($p !== null && !isset($proteins[$p])) {
        $proteins[$p] = array('@type' => 'Protein', 'identifier' => $p, 'name' => $p);
      }
    }
    $doc['encodesBioChemEntity'] = array_values(array_slice($proteins, 0, 25));

    // GO terms, by aspect. Only GO has the three-way split these properties
    // encode; terms from other ontologies are left to the JSON record. A
    // term whose aspect the record does not carry -- the classical-locus
    // annotations from MaizeCyc arrive with domain null -- is kept as a
    // PropertyValue naming the term, rather than guessed into an aspect or
    // dropped: 4 of lg1's 7 terms would otherwise vanish.
    $fn = self::arr(self::g($sec, 'function'));
    $mf = array(); $bp = array(); $cc = array(); $other = array();
    foreach (self::arr(self::g($fn, 'ontology')) as $row) {
      $term = self::s(self::g($row, 'term'));
      if ($term === null || strpos($term, 'GO:') !== 0) { continue; }
      $dt = self::definedTerm($term, self::g($row, 'name'), 'Gene Ontology', self::g($row, 'url'));
      $domain = strtolower(str_replace('_', ' ', (string) self::g($row, 'domain')));
      if ($domain === 'f' || strpos($domain, 'function') !== false)      { $mf[$term] = $dt; }
      elseif ($domain === 'p' || strpos($domain, 'process') !== false)   { $bp[$term] = $dt; }
      elseif ($domain === 'c' || strpos($domain, 'component') !== false || strpos($domain, 'location') !== false || strpos($domain, 'cellular') !== false) { $cc[$term] = $dt; }
      elseif (!isset($other[$term])) {
        $other[$term] = array('@type' => 'PropertyValue', 'name' => 'Gene Ontology term',
                              'value' => $term . ($dt['name'] !== null ? ' ' . $dt['name'] : ''),
                              'url' => $dt['url'], 'valueReference' => $dt);
      }
    }
    $doc['hasMolecularFunction'] = array_values($mf);
    $doc['isInvolvedInBiologicalProcess'] = array_values($bp);
    $doc['isLocatedInSubcellularLocation'] = array_values($cc);
    $doc['additionalProperty'] = array_merge($props, array_values($other));

    // The pan-gene the model belongs to.
    $pg = self::arr(self::g(self::arr(self::g($sec, 'pan_gene')), 'pan_gene'));
    $pgName = self::s(self::g($pg, 'name'));
    if ($pgName !== null) {
      $doc['isPartOfBioChemEntity'] = array(
        '@type' => 'BioChemEntity', 'identifier' => $pgName, 'name' => $pgName,
        'url' => self::htmlUrl('pan_gene', $pgName)
      );
    }

    // External entries the record already resolves to URLs.
    $same = array();
    foreach (self::arr(self::g(self::arr(self::g($sec, 'xrefs')), 'xrefs')) as $x) { $same[] = self::url(self::g($x, 'url')); }
    foreach (self::arr(self::g($fn, 'protein_accessions')) as $x) { $same[] = self::url(self::g($x, 'url')); }
    $doc['sameAs'] = self::merge($same, array(), 60);
    return $doc;
  }

  private static function map_gene_product($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $ptype = self::s(self::g($a, 'product_type'));
    $ov = self::arr(self::g($sec, 'overview'));
    $isRna = ($ptype !== null && stripos($ptype, 'rna') !== false);

    $description = self::hintDesc($hint);
    if ($description === null) { $description = self::s(self::g($ov, 'description')); }
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a maize ' . ($ptype !== null ? $ptype : 'gene product') . '.';
    }

    $doc = array(
      '@type' => $isRna ? 'BioChemEntity' : 'Protein',
      'dct:conformsTo' => $isRna ? 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE'
                                 : 'https://bioschemas.org/profiles/Protein/0.11-RELEASE',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge(self::names(self::g($a, 'synonyms')), self::hintAlt($hint)),
      'description' => $description
    );
    $species = self::s(self::g($a, 'species'));
    if ($species === null) { $species = self::s(self::g(self::arr(self::g($ov, 'species')), 'name')); }
    if ($species !== null) { $doc['taxonomicRange'] = self::taxon($species); }

    $genes = array();
    foreach (self::arr(self::g($ov, 'loci')) as $l) {
      $ln = self::s(self::g($l, 'name'));
      if ($ln === null) { continue; }
      $genes[] = array(
        '@type' => 'Gene', 'identifier' => $ln, 'name' => $ln,
        'alternateName' => self::s(self::g($l, 'full_name')),
        'url' => self::abs(self::g($l, 'html'))
      );
    }
    $doc['isEncodedByBioChemEntity'] = array_slice($genes, 0, 50);

    $same = array();
    foreach (self::arr(self::g($ov, 'uniprot')) as $u) { $same[] = self::url(self::g($u, 'url')); }
    $ecs = array();
    foreach (self::arr(self::g($ov, 'ec_numbers')) as $e) {
      $ec = self::s(self::g($e, 'ec_number'));
      if ($ec !== null && preg_match('/^\d+(\.[\d-]+){3}$/', $ec)) {
        $ecs[] = $ec;
        $same[] = 'https://enzyme.expasy.org/EC/' . $ec;
      }
    }
    $doc['sameAs'] = self::merge($same, array(), 60);
    $doc['additionalProperty'] = array(
      self::prop('gene product type', $ptype),
      self::prop('EC number', count($ecs) ? implode(', ', $ecs) : null)
    );
    return $doc;
  }

  private static function map_pan_gene($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'pan_gene_name'));
    if ($name === null) { $name = $id; }
    $loci = array();
    foreach ((array) self::g($a, 'loci') as $l) { $loci[] = self::s(is_array($l) ? self::g($l, 'name') : $l); }
    $exemplar = self::s(self::g($a, 'exemplar_gene_model'));
    $members = self::g($a, 'member_count');
    $assemblies = self::g($a, 'assembly_count');

    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = $name . ' is a pan-gene';
      if (self::s(self::g($a, 'analysis')) !== null) { $description .= ' from the ' . self::g($a, 'analysis') . ' analysis'; }
      if ($members !== null) { $description .= ' grouping ' . $members . ' gene models'; }
      if ($assemblies !== null) { $description .= ' across ' . $assemblies . ' maize assemblies'; }
      if ($exemplar !== null) { $description .= ', with exemplar gene model ' . $exemplar; }
      $description .= (count(array_filter($loci)) ? ', including the locus ' . implode(', ', array_filter($loci)) : '') . '.';
    }

    $parts = array();
    foreach (self::arr(self::g($sec, 'members')) as $m) {
      $mn = self::s(self::g($m, 'name'));
      if ($mn === null) { continue; }
      $parts[] = array(
        '@type' => 'Gene', 'identifier' => $mn, 'name' => $mn,
        'url' => self::abs(self::g($m, 'html')),
        'additionalProperty' => array(self::prop('assembly', self::g($m, 'assembly')))
      );
    }

    return array(
      '@type' => 'BioChemEntity',
      'dct:conformsTo' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
      'identifier' => self::identifier($name),
      'name' => $name,
      'alternateName' => self::merge(array_merge($loci, array($exemplar)), self::hintAlt($hint)),
      'description' => $description,
      'taxonomicRange' => self::taxon('Zea'),
      'hasBioChemEntityPart' => array_slice($parts, 0, 100),
      'additionalProperty' => array(
        self::prop('analysis', self::g($a, 'analysis')),
        self::prop('chromosome', self::g($a, 'chr')),
        self::prop('exemplar gene model', $exemplar),
        self::prop('member count', $members),
        self::prop('assembly count', $assemblies)
      )
    );
  }

  private static function map_locus($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $full = self::s(self::g($a, 'full_name'));
    $ltype = self::s(self::g($a, 'locus_type'));
    $ov = self::arr(self::g($sec, 'overview'));
    $isGene = ($ltype !== null && strcasecmp($ltype, 'gene') === 0);

    $description = self::hintDesc($hint);
    if ($description === null) {
      $rows = self::arr(self::g($ov, 'description'));
      $description = count($rows) ? self::s(self::g($rows[0], 'text')) : null;
    }
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ($full !== null ? ' (' . $full . ')' : '')
                   . ' is a maize ' . ($ltype !== null ? strtolower($ltype) . ' ' : '') . 'locus'
                   . (self::s(self::g($a, 'linkage_group')) !== null ? ' on chromosome ' . self::g($a, 'linkage_group') : '') . '.';
    }

    $alt = array($full, self::s(self::g($ov, 'plant_wide_gene_name')));
    $alt = array_merge($alt, self::names(self::g($a, 'synonyms')));

    $same = array();
    $off = self::arr(self::g($sec, 'offsite'));
    foreach (array('entries', 'ncbi_gene') as $k) {
      foreach (self::arr(self::g($off, $k)) as $x) { $same[] = self::url(self::g($x, 'url')); }
    }

    $doc = array(
      '@type' => $isGene ? 'Gene' : 'BioChemEntity',
      'dct:conformsTo' => $isGene ? 'https://bioschemas.org/profiles/Gene/1.0-RELEASE'
                                  : 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge($alt, self::hintAlt($hint)),
      'description' => $description,
      'sameAs' => self::merge($same, array(), 60),
      'additionalProperty' => array(
        self::prop('locus type', $ltype),
        self::prop('linkage group', self::g($a, 'linkage_group')),
        self::prop('bin', self::g($ov, 'bin')),
        self::prop('arm', self::g($ov, 'arm'))
      )
    );
    $species = self::s(self::g($a, 'species'));
    if ($species !== null) { $doc['taxonomicRange'] = self::taxon($species); }
    return $doc;
  }

  private static function map_variation($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $vtype = self::s(self::g($a, 'type'));
    $ov = self::arr(self::g($sec, 'overview'));
    $locus = self::arr(self::g($ov, 'locus'));
    $locusName = self::s(self::g($locus, 'name'));

    $description = self::hintDesc($hint);
    if ($description === null) { $description = self::s(self::g($ov, 'function')); }
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a maize ' . ($vtype !== null ? strtolower($vtype) : 'variation')
                   . ($locusName !== null ? ' of the locus ' . $locusName
                       . (self::s(self::g($ov, 'locus_full_name')) !== null ? ' (' . self::g($ov, 'locus_full_name') . ')' : '') : '') . '.';
    }

    $so = null;
    $lt = strtolower((string) $vtype);
    if ($lt === 'allele') { $so = 'http://purl.obolibrary.org/obo/SO_0001023'; }
    elseif (strpos($lt, 'single nucleotide') !== false) { $so = 'http://purl.obolibrary.org/obo/SO_0000694'; }

    $doc = array(
      '@type' => 'BioChemEntity',
      'additionalType' => $so,
      'dct:conformsTo' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge(self::names(self::g($a, 'synonyms')), self::hintAlt($hint)),
      'description' => $description,
      'additionalProperty' => array(
        self::prop('variation type', $vtype),
        self::prop('status', self::g($a, 'status')),
        self::prop('dominance', self::g(self::arr(self::g($ov, 'dominance')), 'name')),
        self::prop('viability', self::g(self::arr(self::g($ov, 'viability')), 'name')),
        self::prop('allele descriptor', self::g($ov, 'allele_descriptor'))
      )
    );
    if ($locusName !== null) {
      $doc['isPartOfBioChemEntity'] = array(
        '@type' => 'Gene', 'identifier' => $locusName, 'name' => $locusName,
        'url' => self::abs(self::g($locus, 'html'))
      );
    }
    $species = self::s(self::g(self::arr(self::g($ov, 'species')), 'name'));
    if ($species !== null) { $doc['taxonomicRange'] = self::taxon($species); }
    return $doc;
  }

  private static function map_marker($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $mtype = self::s(self::g($a, 'marker_type'));
    $loci = array();
    foreach (self::arr(self::g($sec, 'loci')) as $l) { $loci[] = self::s(self::g($l, 'name')); }
    $loci = array_values(array_filter($loci));

    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a maize ' . ($mtype !== null ? strtolower($mtype) : 'marker')
                   . (count($loci) ? ' detecting ' . implode(', ', array_slice($loci, 0, 6)) : '') . '.';
    }
    $so = (stripos((string) $mtype, 'probe') !== false)
        ? 'http://purl.obolibrary.org/obo/SO_0000051' : 'http://purl.obolibrary.org/obo/SO_0001645';

    $same = array();
    foreach (self::arr(self::g($sec, 'offsite')) as $x) { $same[] = self::url(self::g($x, 'url')); }

    $doc = array(
      '@type' => 'BioChemEntity',
      'additionalType' => $so,
      'dct:conformsTo' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge(self::names(self::g($a, 'synonyms')), self::hintAlt($hint)),
      'description' => $description,
      'sameAs' => self::merge($same, array(), 60),
      'additionalProperty' => array(self::prop('marker type', $mtype))
    );
    $species = self::s(self::g($a, 'species'));
    if ($species !== null) { $doc['taxonomicRange'] = self::taxon($species); }
    return $doc;
  }

  private static function map_primer($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $ptype = self::s(self::g($a, 'primer_type'));
    $seq = self::s(self::g($a, 'sequence'));
    $ov = self::arr(self::g($sec, 'overview'));
    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a MaizeGDB ' . ($ptype !== null ? strtolower($ptype) : 'primer')
                   . ($seq !== null ? ' with sequence ' . $seq : '') . '.';
    }
    return array(
      '@type' => 'BioChemEntity',
      'additionalType' => (stripos((string) $ptype, 'primer') !== false) ? 'http://purl.obolibrary.org/obo/SO_0000112' : null,
      'dct:conformsTo' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge(self::names(self::g($a, 'synonyms')), self::hintAlt($hint)),
      'description' => $description,
      'additionalProperty' => array(
        self::prop('type', $ptype),
        self::prop('sequence', $seq),
        self::prop('length', self::g($ov, 'length')),
        self::prop('melting temperature', self::g($ov, 'melting_temperature'))
      )
    );
  }

  private static function map_linkage_group($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $ltype = self::s(self::g($a, 'type'));
    $species = self::s(self::g($a, 'species'));
    $ov = self::arr(self::g($sec, 'overview'));
    $isChromosome = ($ltype !== null && strcasecmp($ltype, 'chromosome') === 0);
    $display = ($isChromosome && $name !== null) ? 'Chromosome ' . $name : ($name !== null ? $name : $id);

    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = $display . ' is a MaizeGDB linkage group'
                   . ($ltype !== null ? ' of type ' . strtolower($ltype) : '')
                   . ($species !== null ? ' in ' . $species : '');
      if (self::g($a, 'locus_count') !== null) { $description .= ', carrying ' . self::g($a, 'locus_count') . ' loci'; }
      if (self::g($a, 'map_count') !== null) { $description .= ' on ' . self::g($a, 'map_count') . ' maps'; }
      $description .= '.';
    }
    $alt = array();
    foreach ((array) self::g($ov, 'synonyms') as $s) { $alt[] = self::s(is_array($s) ? self::g($s, 'name') : $s); }
    if ($display !== $name) { $alt[] = $name; }

    $doc = array(
      '@type' => 'BioChemEntity',
      'additionalType' => $isChromosome ? 'http://purl.obolibrary.org/obo/SO_0000340' : null,
      'dct:conformsTo' => 'https://bioschemas.org/profiles/BioChemEntity/0.8-RELEASE',
      'name' => $display,
      'alternateName' => self::merge($alt, self::hintAlt($hint)),
      'description' => $description,
      'additionalProperty' => array(
        self::prop('type', $ltype),
        self::prop('chromosome', self::g($a, 'chromosome')),
        self::prop('locus count', self::g($a, 'locus_count')),
        self::prop('map count', self::g($a, 'map_count')),
        self::prop('length (cM)', self::g($ov, 'length_cm')),
        self::prop('length (kb)', self::g($ov, 'length_kb'))
      )
    );
    if ($species !== null) { $doc['taxonomicRange'] = self::taxon($species); }
    return $doc;
  }

  private static function map_stock($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $ov = self::arr(self::g($sec, 'overview'));
    $stype = self::s(self::g(self::arr(self::g($ov, 'type')), 'name'));
    $classification = self::s(self::g($ov, 'classification'));
    $developer = self::s(self::g(self::arr(self::g($ov, 'developer')), 'name'));
    $provider = self::arr(self::g($ov, 'provider'));
    $origin = self::arr(self::g($ov, 'origin'));

    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a maize ' . ($stype !== null ? strtolower($stype) : 'genetic stock')
                   . ($classification !== null ? ' (' . $classification . ')' : '')
                   . ($developer !== null ? ' developed by ' . $developer : '');
      $ped = self::s(self::g($ov, 'pedigree_text'));
      if ($ped !== null) { $description .= '. Pedigree: ' . (strlen($ped) > 200 ? rtrim(substr($ped, 0, 200)) . '…' : $ped); }
      $description .= '.';
    }

    $same = array();
    foreach (self::arr(self::g($sec, 'offsite')) as $x) { $same[] = self::url(self::g($x, 'url')); }
    $grin = self::arr(self::g(self::arr(self::g($sec, 'grin')), 'details'));
    $same[] = self::url(self::g($grin, 'grin_url'));

    $doc = array(
      '@type' => 'bs:BioSample',
      'dct:conformsTo' => 'https://bioschemas.org/profiles/BioSample/0.1-RELEASE',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge(self::names(self::g($a, 'synonyms')), self::hintAlt($hint)),
      'description' => $description,
      'taxonomicRange' => self::taxon(self::s(self::g(self::arr(self::g($ov, 'species')), 'name')) !== null
                                      ? self::g(self::arr(self::g($ov, 'species')), 'name') : 'Zea mays'),
      'sameAs' => self::merge($same, array(), 30),
      'additionalProperty' => array(
        self::prop('status', self::g($a, 'status')),
        self::prop('stock type', $stype),
        self::prop('classification', $classification),
        self::prop('market class', self::g(self::arr(self::g($ov, 'market_class')), 'name')),
        self::prop('developer', $developer),
        self::prop('Stock Center id', self::g($a, 'stock_center_id')),
        self::prop('origin country', self::g($origin, 'country')),
        self::prop('origin state or province', self::g($origin, 'state_province')),
        self::prop('year', self::g($origin, 'year'))
      )
    );
    $pname = self::s(self::g($provider, 'name'));
    if ($pname !== null) {
      $doc['custodian'] = array('@type' => 'Organization', 'name' => $pname, 'url' => self::abs(self::g($provider, 'html')));
    }
    return $doc;
  }

  private static function map_reference($type, $id, $a, $sec, $meta, $page, $hint) {
    $title = self::s(self::g($a, 'title'));
    $citation = self::s(self::g($a, 'citation'));
    if ($title === null) { $title = $citation; }
    $ov = self::arr(self::g($sec, 'overview'));
    $cit = self::arr(self::g($sec, 'citation'));
    $doi = self::s(self::g($a, 'doi'));
    $pmid = self::s(self::g($a, 'pubmed_id'));

    $abstract = self::g($sec, 'abstract');
    $abstract = is_string($abstract) ? self::s($abstract) : null;
    $description = $abstract;
    if ($description === null) { $description = self::hintDesc($hint); }
    if ($description === null) { $description = $citation; }

    $authors = array();
    foreach (self::arr(self::g($sec, 'authors')) as $row) {
      $an = self::s(self::g($row, 'full_name'));
      if ($an === null) { $an = self::s(self::g($row, 'name')); }
      if ($an === null) { continue; }
      $authors[] = array('@type' => 'Person', 'name' => $an, 'url' => self::abs(self::g($row, 'html')));
    }

    $identifiers = array((string) $id, array('@type' => 'PropertyValue', 'propertyID' => 'MaizeGDB', 'value' => (string) $id));
    $same = array();
    if ($doi !== null) {
      $identifiers[] = array('@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => $doi);
      $same[] = self::url(self::g($cit, 'doi_url')) !== null ? self::g($cit, 'doi_url') : 'https://doi.org/' . $doi;
    }
    if ($pmid !== null) {
      $identifiers[] = array('@type' => 'PropertyValue', 'propertyID' => 'PMID', 'value' => $pmid);
      $same[] = self::url(self::g($cit, 'pubmed_url')) !== null ? self::g($cit, 'pubmed_url') : 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pmid) . '/';
    }

    $about = array();
    foreach (self::arr(self::g($sec, 'describes')) as $group) {
      foreach (self::arr(self::g($group, 'items')) as $item) {
        $iname = self::s(self::g($item, 'name'));
        if ($iname === null) { continue; }
        $about[] = array('@type' => 'Thing', 'name' => $iname, 'url' => self::abs(self::g($item, 'html')),
                         'additionalType' => self::s(self::g($group, 'record_type')));
        if (count($about) >= 40) { break 2; }
      }
    }

    $journal = self::s(self::g($a, 'journal'));
    $doc = array(
      '@type' => 'ScholarlyArticle',
      'identifier' => $identifiers,
      'name' => $title !== null ? $title : ('Reference ' . $id),
      'headline' => $title,
      'description' => $description,
      'abstract' => $abstract,
      'author' => $authors,
      'datePublished' => self::g($a, 'year') !== null ? (string) self::g($a, 'year') : null,
      'sameAs' => self::merge($same, array(), 10),
      'about' => $about,
      'additionalProperty' => array(
        self::prop('publication type', self::g($a, 'publication_type')),
        self::prop('citation', $citation)
      )
    );
    if ($journal !== null) {
      $doc['isPartOf'] = array('@type' => 'Periodical', 'name' => $journal, 'issn' => self::s(self::g($ov, 'issn')));
    }
    $publisher = self::s(self::g($ov, 'publisher'));
    if ($publisher !== null) { $doc['publisher'] = array('@type' => 'Organization', 'name' => $publisher); }
    return $doc;
  }

  private static function map_term($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $ttype = self::s(self::g($a, 'term_type'));
    $ov = self::arr(self::g($sec, 'overview'));
    $description = self::hintDesc($hint);
    if ($description === null) { $description = self::s(self::g($ov, 'definition')); }
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a MaizeGDB ' . ($ttype !== null ? strtolower($ttype) . ' ' : '') . 'term.';
    }
    $same = array();
    $code = null;
    foreach (self::arr(self::g($sec, 'offsite')) as $x) {
      $same[] = self::url(self::g($x, 'url'));
      $key = self::s(self::g($x, 'key'));
      if ($code === null && $key !== null && preg_match('/^[A-Za-z][A-Za-z0-9_]*:\d+$/', $key)) { $code = $key; }
    }
    $doc = array(
      '@type' => 'DefinedTerm',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge(self::names(self::g($a, 'synonyms')), self::hintAlt($hint)),
      'description' => $description,
      'termCode' => $code,
      'sameAs' => self::merge($same, array(), 30),
      'additionalProperty' => array(self::prop('is trait', self::g($a, 'is_trait') === null ? null : (self::g($a, 'is_trait') ? 'yes' : 'no')))
    );
    if ($ttype !== null) {
      $doc['inDefinedTermSet'] = array('@type' => 'DefinedTermSet', 'name' => 'MaizeGDB ' . $ttype . ' terms');
    }
    return $doc;
  }

  private static function map_phenotype($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $ov = self::arr(self::g($sec, 'overview'));
    $trait = self::s(self::g($a, 'trait'));
    $value = self::s(self::g($a, 'value'));
    $description = self::hintDesc($hint);
    if ($description === null) { $description = self::s(self::g($ov, 'description')); }
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a curated maize phenotype'
                   . ($trait !== null ? ' of the trait ' . $trait : '')
                   . ($value !== null ? ', recorded as ' . $value : '') . '.';
    }
    $counts = self::arr(self::g($meta, 'counts'));
    $same = array();
    foreach (self::arr(self::g($sec, 'offsite')) as $x) { $same[] = self::url(self::g($x, 'url')); }
    return array(
      '@type' => 'Thing',
      'name' => $name !== null ? $name : $id,
      'alternateName' => self::merge(self::names(self::g($a, 'synonyms')), self::hintAlt($hint)),
      'description' => $description,
      'disambiguatingDescription' => 'A maize phenotype record in MaizeGDB.',
      'sameAs' => self::merge($same, array(), 30),
      'additionalProperty' => array(
        self::prop('trait', $trait),
        self::prop('value', $value),
        self::prop('inheritance', self::g($ov, 'inheritance')),
        self::prop('intensity', self::g($ov, 'intensity')),
        self::prop('genes showing it', self::g($counts, 'genes')),
        self::prop('variations showing it', self::g($counts, 'variations')),
        self::prop('stocks carrying it', self::g($counts, 'stocks'))
      )
    );
  }

  private static function map_map($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $lg = self::s(self::g($a, 'linkage_group'));
    $units = self::s(self::g($a, 'coordinate_type'));
    $count = self::g($a, 'locus_count');
    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a MaizeGDB chromosome map'
                   . ($lg !== null ? ' of linkage group ' . $lg : '')
                   . ($count !== null ? ' carrying ' . $count . ' mapped loci' : '')
                   . ($units !== null ? ' in ' . $units : '') . '.';
    }
    $doc = self::dataset($type, $id, $name !== null ? $name : ('Map ' . $id), $description,
      array('maize', 'genetic map', $lg !== null ? 'linkage group ' . $lg : null, $units));
    $author = self::arr(self::g($a, 'author'));
    $an = self::s(self::g($author, 'name'));
    if ($an !== null) { $doc['creator'] = array('@type' => 'Person', 'name' => $an, 'url' => self::abs(self::g($author, 'html'))); }
    $doc['additionalProperty'] = array(
      self::prop('linkage group', $lg),
      self::prop('coordinate type', $units),
      self::prop('locus count', $count)
    );
    return $doc;
  }

  private static function map_gel($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a MaizeGDB gel pattern'
                   . (self::s(self::g($a, 'probe')) !== null ? ' from probe ' . self::g($a, 'probe') : '')
                   . (self::s(self::g($a, 'enzyme')) !== null ? ' cut with ' . self::g($a, 'enzyme') : '')
                   . (self::s(self::g($a, 'stock')) !== null ? ' on stock ' . self::g($a, 'stock') : '') . '.';
    }
    $doc = self::dataset($type, $id, $name !== null ? $name : ('Gel pattern ' . $id), $description,
      array('maize', 'gel pattern', 'RFLP'));
    $images = array();
    foreach (self::arr(self::g($sec, 'images')) as $im) { $images[] = self::url(self::g($im, 'url')); }
    $doc['image'] = self::merge($images, array(), 10);
    $doc['additionalProperty'] = array(
      self::prop('probe', self::g($a, 'probe')),
      self::prop('enzyme', self::g($a, 'enzyme')),
      self::prop('stock', self::g($a, 'stock'))
    );
    return $doc;
  }

  private static function map_recombination($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a MaizeGDB recombination dataset'
                   . (self::s(self::g($a, 'cross_type')) !== null ? ' from a ' . self::g($a, 'cross_type') . ' cross' : '')
                   . (self::g($a, 'total_progeny') !== null ? ' of ' . self::g($a, 'total_progeny') . ' progeny' : '') . '.';
    }
    $doc = self::dataset($type, $id, $name !== null ? $name : ('Recombination ' . $id), $description,
      array('maize', 'recombination frequency', 'linkage'));
    $doc['additionalProperty'] = array(
      self::prop('cross type', self::g($a, 'cross_type')),
      self::prop('total progeny', self::g($a, 'total_progeny'))
    );
    return $doc;
  }

  private static function map_map_scores($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a MaizeGDB map score'
                   . (self::s(self::g($a, 'probed_site')) !== null ? ' for ' . self::g($a, 'probed_site') : '')
                   . (self::s(self::g($a, 'linkage_group')) !== null ? ' on chromosome ' . self::g($a, 'linkage_group') : '') . '.';
    }
    $doc = self::dataset($type, $id, $name !== null ? $name : ('Map score ' . $id), $description,
      array('maize', 'map scores', 'mapping panel'));
    $doc['additionalProperty'] = array(
      self::prop('probed site', self::g($a, 'probed_site')),
      self::prop('linkage group', self::g($a, 'linkage_group'))
    );
    return $doc;
  }

  private static function map_qtl($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $panel = self::s(self::g($a, 'mapping_panel'));
    $description = self::hintDesc($hint);
    if ($description === null) {
      $description = ($name !== null ? $name : $id) . ' is a maize QTL mapping experiment'
                   . ($panel !== null ? ' on the ' . $panel . ' panel' : '') . '.';
    }
    $doc = self::dataset($type, $id, $name !== null ? $name : ('QTL experiment ' . $id), $description,
      array('maize', 'QTL', 'quantitative trait locus'));
    $traits = array();
    foreach (self::arr(self::g($sec, 'evaluations')) as $ev) {
      $tn = self::s(self::g($ev, 'trait'));
      if ($tn !== null && !isset($traits[$tn])) { $traits[$tn] = array('@type' => 'PropertyValue', 'name' => $tn); }
    }
    $doc['variableMeasured'] = array_values(array_slice($traits, 0, 50));
    $doc['additionalProperty'] = array(self::prop('mapping panel', $panel));
    return $doc;
  }

  private static function map_generic($type, $id, $a, $sec, $meta, $page, $hint) {
    $name = self::s(self::g($a, 'name'));
    $description = self::hintDesc($hint);
    return array(
      '@type' => 'Thing',
      'name' => $name !== null ? $name : $id,
      'description' => $description !== null ? $description : ('A MaizeGDB ' . str_replace('_', ' ', $type) . ' record.')
    );
  }

  /* ---------------------------------------------------------------------
     Shared pieces
     --------------------------------------------------------------------- */

  private static function dataset($type, $id, $name, $description, $keywords) {
    $base = self::baseUrl();
    return array(
      '@type' => 'Dataset',
      'dct:conformsTo' => 'https://bioschemas.org/profiles/Dataset/1.0-RELEASE',
      'name' => $name,
      'description' => $description,
      'keywords' => array_values(array_filter($keywords, function ($k) { return $k !== null && $k !== ''; })),
      'license' => self::LICENSE_URL,
      'isAccessibleForFree' => true,
      'includedInDataCatalog' => array('@type' => 'DataCatalog', '@id' => $base . '/', 'name' => 'MaizeGDB', 'url' => $base . '/'),
      'publisher' => array('@type' => 'Organization', 'name' => 'MaizeGDB', 'url' => $base . '/'),
      'distribution' => array(
        array('@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => self::apiUrl($type, $id)),
        array('@type' => 'DataDownload', 'encodingFormat' => 'application/ld+json', 'contentUrl' => self::jsonLdUrl($type, $id))
      )
    );
  }

  private static function taxon($name) {
    $name = self::s($name);
    if ($name === null) { $name = 'Zea mays'; }
    $key = strtolower(preg_replace('/\s+/', ' ', $name));
    $known = array(
      'zea mays' => array('4577', 'species'),
      'zea mays ssp. mays' => array('381124', 'subspecies'),
      'zea mays subsp. mays' => array('381124', 'subspecies'),
      'zea' => array('4575', 'genus')
    );
    $t = array('@type' => 'Taxon', 'name' => $name);
    if (isset($known[$key])) {
      $t['identifier'] = 'NCBI:txid' . $known[$key][0];
      $t['url'] = 'https://www.ncbi.nlm.nih.gov/Taxonomy/Browser/wwwtax.cgi?id=' . $known[$key][0];
      $t['taxonRank'] = $known[$key][1];
    }
    return $t;
  }

  private static function definedTerm($code, $name, $set, $url) {
    return array(
      '@type' => 'DefinedTerm', 'termCode' => $code, 'name' => self::s($name),
      'url' => self::url($url) !== null ? $url : 'http://purl.obolibrary.org/obo/' . str_replace(':', '_', $code),
      'inDefinedTermSet' => array('@type' => 'DefinedTermSet', 'name' => $set)
    );
  }

  private static function identifier($id) {
    $id = (string) $id;
    return array($id, array('@type' => 'PropertyValue', 'propertyID' => 'MaizeGDB', 'value' => $id));
  }

  private static function prop($name, $value) {
    if ($value === null || $value === '' || is_array($value)) { return null; }
    if (is_bool($value)) { $value = $value ? 'yes' : 'no'; }
    return array('@type' => 'PropertyValue', 'name' => $name, 'value' => is_int($value) || is_float($value) ? $value : (string) $value);
  }

  private static function hintDesc($hint) {
    return isset($hint['description']) ? self::s($hint['description']) : null;
  }

  private static function hintAlt($hint) {
    return isset($hint['alternate_names']) ? (array) $hint['alternate_names'] : array();
  }

  /* De-duplicated, non-empty strings, case-insensitively, in first-seen order. */
  private static function merge($a, $b, $cap = 40) {
    $seen = array();
    $out = array();
    foreach (array_merge((array) $a, (array) $b) as $v) {
      $v = self::s($v);
      if ($v === null) { continue; }
      $k = strtolower($v);
      if (isset($seen[$k])) { continue; }
      $seen[$k] = true;
      $out[] = $v;
      if (count($out) >= $cap) { break; }
    }
    return $out;
  }

  private static function names($rows) {
    $out = array();
    foreach ((array) $rows as $row) {
      $out[] = is_array($row) ? self::s(self::g($row, 'name')) : self::s($row);
    }
    return $out;
  }

  private static function g($arr, $key) {
    return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : null;
  }

  private static function arr($v) {
    return is_array($v) ? $v : array();
  }

  private static function s($v) {
    if ($v === null || is_array($v) || is_object($v)) { return null; }
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    $v = trim((string) $v);
    return $v === '' ? null : $v;
  }

  private static function url($v) {
    $v = self::s($v);
    return ($v !== null && preg_match('#^https?://#i', $v)) ? $v : null;
  }

  private static function abs($path) {
    $path = self::s($path);
    if ($path === null) { return null; }
    if (preg_match('#^https?://#i', $path)) { return $path; }
    return (strpos($path, '/') === 0) ? self::baseUrl() . $path : null;
  }

  /* Drop nulls, empty strings and empty arrays at every level, and re-index
     the lists that filtering left with holes. */
  private static function clean($v) {
    if (!is_array($v)) { return $v; }
    $isList = (count($v) > 0 && array_keys($v) === range(0, count($v) - 1));
    $out = array();
    foreach ($v as $k => $item) {
      $c = self::clean($item);
      if ($c === null || $c === '' || (is_array($c) && count($c) === 0)) { continue; }
      $out[$k] = $c;
    }
    return $isList ? array_values($out) : $out;
  }
}
?>
