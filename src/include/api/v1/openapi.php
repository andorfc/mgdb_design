<?PHP
/* file: api/v1/openapi.php
 *
 * purpose: the OpenAPI 3.1 description of the v1 API, served at
 *          /api/v1/openapi.json.
 *
 *          Generated rather than static so the server URL matches the instance
 *          it is served from — the development, curation, and production hosts
 *          all serve this and a copied-out static file would point at the
 *          wrong one.
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $base = MgdbApi::baseUrl();

  $reference = array(
    'type' => 'object',
    'description' => 'A pointer to another MaizeGDB record. Null when the relationship is not recorded.',
    'nullable' => true,
    'properties' => array(
      'type' => array('type' => 'string', 'description' => 'Record type, e.g. stock, person, variation.'),
      'id' => array('type' => array('integer', 'null')),
      'name' => array('type' => array('string', 'null')),
      'html' => array('type' => 'string', 'description' => 'Path to the human-readable page, when one exists.')
    )
  );

  MgdbApi::sendDocument(array(
    'openapi' => '3.1.0',
    'info' => array(
      'title' => 'MaizeGDB API',
      'version' => MgdbApi::VERSION,
      'summary' => 'Read-only JSON access to MaizeGDB records.',
      'description' =>
        "One request returns a whole record. Sections can be selected with the "
        . "`fields` query parameter.\n\n"
        . "Every response carries a strong `ETag`; send it back as "
        . "`If-None-Match` and an unchanged record answers `304` with no body.\n\n"
        . "Errors are RFC 9457 problem details, served as "
        . "`application/problem+json`.",
      'contact' => array('name' => 'MaizeGDB', 'url' => 'https://www.maizegdb.org/contact'),
      'license' => array('name' => 'Public domain (U.S. Government work)')
    ),
    'servers' => array(array('url' => $base . '/api/v1', 'description' => 'This instance')),
    'tags' => array(
      array('name' => 'records', 'description' => 'Individual database records.'),
      array('name' => 'service', 'description' => 'Service description and schema.')
    ),
    'paths' => array(
      '/' => array(
        'get' => array(
          'tags' => array('service'),
          'summary' => 'Service description',
          'operationId' => 'getService',
          'responses' => array(
            '200' => array(
              'description' => 'What this version offers.',
              'content' => array('application/json' => array('schema' => array('type' => 'object')))
            )
          )
        )
      ),
      '/openapi' => array(
        'get' => array(
          'tags' => array('service'),
          'summary' => 'This document',
          'operationId' => 'getOpenApi',
          'responses' => array('200' => array('description' => 'OpenAPI 3.1 document.'))
        )
      ),
      '/records/reference/{id}' => array(
        'get' => array(
          'tags' => array('records'),
          'summary' => 'One reference record, fully assembled',
          'description' =>
            "Accepts a MaizeGDB reference id, a DOI, or a PubMed ID. A DOI "
            . "contains slashes and is given unencoded as the rest of the "
            . "path: /records/reference/10.1016/j.molp.2020.03.003.",
          'operationId' => 'getReference',
          'parameters' => array(
            array(
              'name' => 'id', 'in' => 'path', 'required' => true,
              'description' => 'Reference id, DOI, or PubMed ID.',
              'schema' => array('type' => 'string', 'maxLength' => 200),
              'examples' => array(
                'by id' => array('value' => '9043389'),
                'by DOI' => array('value' => '10.1016/j.molp.2020.03.003')
              )
            ),
            array(
              'name' => 'fields', 'in' => 'query', 'required' => false,
              'description' => 'Comma-separated sections: overview, authors, abstract, citation, describes, links, editorial.',
              'schema' => array('type' => 'string')
            )
          ),
          'responses' => array(
            '200' => array(
              'description' =>
                'The record. `sections.authors` carries each author\'s paper '
                . 'count in MaizeGDB and their first/last position; '
                . '`sections.describes` groups the curated records by type, '
                . 'with loci carrying their gene models per assembly; '
                . '`sections.citation` carries formatted, BibTeX, and RIS forms.',
              'content' => array('application/json' => array(
                'schema' => array('$ref' => '#/components/schemas/Envelope')
              ))
            ),
            '304' => array('description' => 'Unchanged since the supplied ETag.'),
            '404' => array('description' => 'No reference matches that identifier.',
                           'content' => array('application/problem+json' => array(
                             'schema' => array('$ref' => '#/components/schemas/Problem'))))
          )
        )
      ),
      '/records/stock/{id}' => array(
        'get' => array(
          'tags' => array('records'),
          'summary' => 'One stock record, fully assembled',
          'description' =>
            "Accepts a numeric stock id, a stock name, an alternate description, "
            . "or an external accession such as a GRIN PI number. The canonical "
            . "id is returned in `data.id` and the identifier that was supplied "
            . "in `meta.resolved_from`.",
          'operationId' => 'getStock',
          'parameters' => array(
            array(
              'name' => 'id', 'in' => 'path', 'required' => true,
              'description' => 'Stock id, name, or accession.',
              'schema' => array('type' => 'string', 'maxLength' => 200),
              'examples' => array(
                'by name' => array('value' => 'CML277'),
                'by id' => array('value' => '105132'),
                'by GRIN accession' => array('value' => 'PI 595550')
              )
            ),
            array(
              'name' => 'fields', 'in' => 'query', 'required' => false,
              'description' =>
                "Comma-separated sections to return. Omit for all of them. "
                . "Requesting only what is needed avoids the queries behind the "
                . "rest; `fields=grin` is the only section that calls an "
                . "external service.",
              'schema' => array(
                'type' => 'string',
                'examples' => array('overview,pedigree')
              )
            ),
            array(
              'name' => 'If-None-Match', 'in' => 'header', 'required' => false,
              'description' => 'An ETag from a previous response. Returns 304 when the record is unchanged.',
              'schema' => array('type' => 'string')
            )
          ),
          'responses' => array(
            '200' => array(
              'description' => 'The record.',
              'headers' => array(
                'ETag' => array('description' => 'Strong validator for the record.',
                                'schema' => array('type' => 'string')),
                'Cache-Control' => array('schema' => array('type' => 'string')),
                'X-Request-Id' => array('description' => 'Correlates this response with the server log.',
                                        'schema' => array('type' => 'string'))
              ),
              'content' => array('application/json' => array(
                'schema' => array('$ref' => '#/components/schemas/StockRecord')
              ))
            ),
            '304' => array('description' => 'Unchanged since the supplied ETag.'),
            '400' => array('description' => 'Malformed identifier, or an unknown value in fields.',
                           'content' => array('application/problem+json' => array(
                             'schema' => array('$ref' => '#/components/schemas/Problem')))),
            '404' => array('description' => 'No stock matches that identifier.',
                           'content' => array('application/problem+json' => array(
                             'schema' => array('$ref' => '#/components/schemas/Problem')))),
            '405' => array('description' => 'The API is read-only.'),
            '406' => array('description' => 'Only application/json can be produced.'),
            '503' => array('description' => 'The record store could not be reached.')
          )
        )
      ),
      '/records/gene/{id}' => array(
        'get' => array(
          'tags' => array('records'),
          'summary' => 'One gene record, fully assembled',
          'description' =>
            "A maize gene: the gene model as annotated in one assembly, and the "
            . "classical locus it represents, in one record. Accepts a gene model "
            . "name, a transcript or protein name, a GenBank or old GenBank name, "
            . "a classical gene symbol or full name, a synonym, or a numeric locus "
            . "id. Which arm matched is reported in `meta.id_type`, and any other "
            . "candidate the identifier could have meant is in `meta.other_matches`.\n\n"
            . "A withdrawn gene model answers `410` with its replacement rather "
            . "than `404`: the identifier was valid in an earlier annotation.\n\n"
            . "Two values are absent by fact rather than omission and say so: "
            . "`overview.strand` is always null because strand is not populated in "
            . "this database, and `structure.exon_structure` is always null because "
            . "no exon, CDS or UTR features exist in it.",
          'operationId' => 'getGene',
          'parameters' => array(
            array(
              'name' => 'id', 'in' => 'path', 'required' => true,
              'description' => 'Gene model name, transcript, protein, gene symbol, synonym, or locus id.',
              'schema' => array('type' => 'string', 'maxLength' => 200),
              'examples' => array(
                'by gene model' => array('value' => 'Zm00001eb067740'),
                'by gene symbol' => array('value' => 'lg1'),
                'by full name' => array('value' => 'liguleless1'),
                'by transcript' => array('value' => 'Zm00001eb067740_T001'),
                'by synonym' => array('value' => 'ZmSBP15'),
                'by locus id' => array('value' => '12386')
              )
            ),
            array(
              'name' => 'fields', 'in' => 'query', 'required' => false,
              'description' =>
                "Comma-separated sections to return. Omit for all of them. A full "
                . "record costs 23 queries; `fields=overview` costs 5. The `locus`, "
                . "`references` and `xrefs` sections are empty for a gene model "
                . "with no classical locus, which is about half of B73 v5.",
              'schema' => array(
                'type' => 'string',
                'examples' => array('overview,structure,function')
              )
            ),
            array(
              'name' => 'protein_length', 'in' => 'query', 'required' => false,
              'description' =>
                "Set to 1 to include `structure.protein.length_aa`. Off by default: "
                . "protein length is not held in this database and has to be read "
                . "from the sequence service, which costs about 470 ms — more than "
                . "the rest of the record together. On failure the field is null and "
                . "`meta.warnings` says why.",
              'schema' => array('type' => 'string', 'enum' => array('1'))
            ),
            array(
              'name' => 'max_items', 'in' => 'query', 'required' => false,
              'description' =>
                'Cap on any one embedded list, default 500, maximum 5000. Some gene '
                . 'models carry over 2,000 insertions. `meta.counts` keeps the true '
                . 'totals and `meta.truncated` names every list that was cut.',
              'schema' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 5000)
            ),
            array(
              'name' => 'If-None-Match', 'in' => 'header', 'required' => false,
              'description' => 'An ETag from a previous response. Returns 304 when the record is unchanged.',
              'schema' => array('type' => 'string')
            )
          ),
          'responses' => array(
            '200' => array(
              'description' =>
                'The record. `sections.structure.scores` carries the gene model and '
                . 'protein-structure scores with a plain-language reading of each; '
                . '`sections.pan_gene` carries every assembly the gene was found in, '
                . 'grouped by Zea species; `sections.locus` is present only when the '
                . 'gene model is matched to a classical gene.',
              'headers' => array(
                'ETag' => array('description' => 'Strong validator for the record.',
                                'schema' => array('type' => 'string')),
                'Cache-Control' => array('schema' => array('type' => 'string')),
                'X-Request-Id' => array('description' => 'Correlates this response with the server log.',
                                        'schema' => array('type' => 'string'))
              ),
              'content' => array('application/json' => array(
                'schema' => array('$ref' => '#/components/schemas/Envelope')
              ))
            ),
            '304' => array('description' => 'Unchanged since the supplied ETag.'),
            '400' => array('description' => 'Malformed identifier, or an unknown value in fields.',
                           'content' => array('application/problem+json' => array(
                             'schema' => array('$ref' => '#/components/schemas/Problem')))),
            '404' => array('description' => 'No gene model or locus matches that identifier.',
                           'content' => array('application/problem+json' => array(
                             'schema' => array('$ref' => '#/components/schemas/Problem')))),
            '410' => array('description' =>
                             'The gene model was withdrawn from its annotation. The body '
                             . 'carries `replacement` and `replacement_html` when one exists.',
                           'content' => array('application/problem+json' => array(
                             'schema' => array('$ref' => '#/components/schemas/Problem')))),
            '405' => array('description' => 'The API is read-only.'),
            '406' => array('description' => 'Only application/json can be produced.'),
            '503' => array('description' => 'The record store could not be reached.')
          )
        )
      )
    ),
    'components' => array(
      'schemas' => array(
        'Problem' => array(
          'type' => 'object',
          'description' => 'RFC 9457 problem details.',
          'required' => array('type', 'title', 'status'),
          'properties' => array(
            'type' => array('type' => 'string', 'format' => 'uri',
                            'description' => 'Stable identifier for the kind of failure.'),
            'title' => array('type' => 'string'),
            'status' => array('type' => 'integer'),
            'detail' => array('type' => 'string'),
            'instance' => array('type' => 'string'),
            'request_id' => array('type' => 'string')
          )
        ),
        'Reference' => $reference,
        'Envelope' => array(
          'type' => 'object',
          'required' => array('api_version', 'meta', 'links', 'data'),
          'properties' => array(
            'api_version' => array('type' => 'string'),
            'meta' => array(
              'type' => 'object',
              'properties' => array(
                'request_id' => array('type' => 'string'),
                'generated' => array('type' => 'string', 'format' => 'date-time'),
                'elapsed_ms' => array('type' => 'integer'),
                'query_count' => array('type' => 'integer',
                                       'description' => 'Database queries used to assemble this response.'),
                'warnings' => array(
                  'type' => 'array',
                  'description' => 'Present when part of the record could not be built. The response is still 200.',
                  'items' => array('type' => 'object', 'properties' => array(
                    'code' => array('type' => 'string'), 'detail' => array('type' => 'string')))
                )
              )
            ),
            'links' => array('type' => 'object'),
            'data' => array('type' => 'object')
          )
        ),
        'StockRecord' => array(
          'allOf' => array(
            array('$ref' => '#/components/schemas/Envelope'),
            array(
              'type' => 'object',
              'properties' => array(
                'meta' => array('type' => 'object', 'properties' => array(
                  'resolved_from' => array('type' => 'string'),
                  'sections_returned' => array('type' => 'array', 'items' => array('type' => 'string')),
                  'sections_available' => array('type' => 'array', 'items' => array('type' => 'string')),
                  'partial' => array('type' => 'boolean'),
                  'counts' => array('type' => 'object',
                    'description' => 'Row counts per section, so a client can label tabs and skip empty sections without fetching them.')
                )),
                'data' => array(
                  'type' => 'object',
                  'properties' => array(
                    'type' => array('const' => 'stock'),
                    'id' => array('type' => 'string'),
                    'attributes' => array(
                      'type' => 'object',
                      'properties' => array(
                        'name' => array('type' => array('string', 'null')),
                        'status' => array('type' => 'string', 'enum' => array('available', 'unavailable', 'discontinued')),
                        'curation_level' => array('type' => 'integer'),
                        'stock_center_id' => array('type' => array('string', 'null')),
                        'synonyms' => array('type' => 'array', 'items' => array(
                          'type' => 'object',
                          'properties' => array(
                            'name' => array('type' => 'string'),
                            'source' => array('type' => 'string', 'enum' => array('description', 'synonym')),
                            'authority' => array('$ref' => '#/components/schemas/Reference')
                          )
                        ))
                      )
                    ),
                    'sections' => array(
                      'type' => 'object',
                      'properties' => array(
                        'overview' => array('type' => 'object', 'description' =>
                          'Classification, provenance, ordering, assemblies, and curator comments.'),
                        'pedigree' => array('type' => 'object', 'description' =>
                          'Parents and progeny with coefficient of parentage, plus the pedigree network.'),
                        'related' => array('type' => 'object', 'description' =>
                          'Genotypic and karyotypic variations, phenotypes, stock relations, images, trait values.'),
                        'references' => array('type' => 'array', 'items' => array('type' => 'object')),
                        'offsite' => array('type' => 'array', 'items' => array('type' => 'object'),
                          'description' => 'External database accessions with resolved URLs.'),
                        'grin' => array('type' => 'object', 'description' =>
                          'USDA GRIN accession. `details` is fetched live and is null when GRIN does not answer.')
                      )
                    )
                  )
                )
              )
            )
          )
        )
      )
    )
  ), 86400);
?>
