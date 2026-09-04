/* file: js/mgdb-locus-record.js
 *
 * The locus record page. One request to /api/v1/records/locus/{id} fills every
 * section; the shared record shell (js/mgdb-record.js, window.MGDBRecord)
 * supplies the collections, the tabs, the figures and the API card.
 *
 * Twenty-five locus types share this page -- Points, Probed Sites, QTL,
 * centromeres, telomeres, transposable elements and the rest. Nothing below is
 * conditioned on type: a section appears when the record has rows for it, which
 * is exactly how the legacy page behaved. Loci of type 'Gene' never arrive
 * here; the controller redirects them to the gene record page.
 */
(function () {
  'use strict';

  var R = window.MGDBRecord;
  if (!R) { return; }

  /* Filled by init(). The shell emits page scripts in <head>, so at parse time
     none of these elements exist -- reading the root here returned null and the
     whole file returned early, silently, leaving the page on "Loading the full
     record". Every other record page guards this with the readyState check
     below; this one has to as well. */
  var root = null;
  var locusId = null;
  var requested = null;
  var ENDPOINT = null;

  var SECTION_LABELS = {
    'locus-record-overview': 'Overview',
    'locus-record-positions': 'Map positions',
    'locus-record-nearby': 'Nearby loci',
    'locus-record-alleles': 'Alleles',
    'locus-record-stocks': 'Stocks',
    'locus-record-detected': 'Detected by',
    'locus-record-genetic': 'Genetic',
    'locus-record-related': 'Related',
    'locus-record-offsite': 'Offsite',
    'locus-record-annotations': 'Annotations',
    'locus-record-references': 'References',
    'locus-record-metrics': 'Metrics',
    'locus-record-resources': 'Related resources'
  };

  var payload = null;

  /* ---------------------------------------------------------------------- *
   * Sections
   * ---------------------------------------------------------------------- */

  function renderOverview(data) {
    var out = R.byId('locus-record-overview-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview;
    if (!o) { return false; }
    var any = false;

    /* The description is the reader's first question -- what is this locus --
       so it leads, above the fact list. */
    if ((o.description || []).length) {
      var text = o.description.map(function (d) { return d.text; }).join(' ');
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block locus-description"><p>' + R.escape(text) + '</p></div>');
      any = true;
    }

    /* Critical comments are curator warnings about the record itself. They get
       the warning treatment rather than sitting in a list of ordinary notes. */
    (o.critical_comments || []).forEach(function (c) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-message mgdb-message-warn locus-critical"><div><strong>Critical comment.</strong> ' +
        R.escape(c.text) + '</div></div>');
      any = true;
    });

    var pairs = [];
    if (o.full_name) {
      pairs.push([o.names_are_gene_symbols ? 'Gene name' : 'Full name', R.escape(o.full_name)]);
    }
    if (o.plant_wide_gene_name) { pairs.push(['Plant-wide gene name', R.escape(o.plant_wide_gene_name)]); }
    if (o.type) { pairs.push(['Locus type', R.refLink(o.type) || R.escape(o.type.name), o.type_description]); }
    if (o.species) { pairs.push(['Species', '<em>' + R.escape(o.species.name) + '</em>']); }
    if (o.linkage_group) { pairs.push(['Chromosome', R.escape(o.linkage_group.name)]); }
    if (o.arm) { pairs.push(['Arm', R.escape(o.arm)]); }
    if (o.bin) { pairs.push(['Genetic bin', R.escape(o.bin)]); }
    if (o.length) {
      pairs.push(['Length', R.escape(o.length.value) + (o.length.units ? ' ' + R.escape(o.length.units) : '')]);
    }
    if ((o.properties || []).length) {
      pairs.push(['Properties', o.properties.map(function (p) {
        return p.note ? '<abbr title="' + R.escape(p.note) + '">' + R.escape(p.name) + '</abbr>' : R.escape(p.name);
      }).join(', ')]);
    }
    if ((o.expression_induced_by || []).length) {
      pairs.push(['Expression induced by', o.expression_induced_by.map(function (p) {
        return p.note ? '<abbr title="' + R.escape(p.note) + '">' + R.escape(p.name) + '</abbr>' : R.escape(p.name);
      }).join(' and ')]);
    }
    if ((o.gene_products || []).length) {
      pairs.push(['Gene products', o.gene_products.map(function (g) {
        return R.refLink(g.ref) || R.escape(g.ref.name);
      }).join(', ')]);
    }
    var factsHtml = R.facts(pairs);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); any = true; }

    /* On the gene review list. A curated marker of record quality, and the
       legacy page gives it a badge of its own. */
    if (o.gene_review) {
      var authors = (o.gene_review.authors || []).map(function (a) {
        return R.refLink(a) || R.escape(a.name);
      }).join(', ');
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block locus-review"><div class="mgdb-rec-block-head">' +
        '<h3>Maize Gene Review</h3></div><p>' +
        (R.refLink(o.gene_review.reference) || R.escape(o.gene_review.title || '')) +
        (authors ? '<br><span class="mgdb-muted">' + authors + '</span>' : '') + '</p></div>');
      any = true;
    }

    /* Functional statements carry their source, so they are notes with a
       reference rather than free text. */
    if (R.notes(out, 'Functional statements from the literature',
        (o.functional_statements || []).map(function (s) {
          return { text: s.text, meta: [s.kind,
            s.reference ? 'Source: ' + (R.refLink(s.reference) || R.escape(s.reference.name)) : '',
            s.year ? String(s.year) : ''] };
        }))) { any = true; }

    if (R.notes(out, 'Curator notes', (o.comments || []).map(function (c) {
      return { text: c.text, meta: [c.kind] };
    }))) { any = true; }

    /* Assembly and gene-model issues. Open ones lead. */
    if (R.collection(out, {
      title: 'Assembly and gene model issues',
      items: (o.issues || []).slice().sort(function (a, b) {
        return (b.open ? 1 : 0) - (a.open ? 1 : 0);
      }),
      filename: 'locus-issues.tsv',
      columns: [
        { key: 'title', label: 'Issue', tile: true,
          get: function (i) { return i.title || ''; },
          html: function (i) {
            return '<span class="locus-issue-flag locus-issue-' + (i.open ? 'open' : 'closed') +
                   '" aria-hidden="true"></span>' + R.escape(i.title || '');
          } },
        { key: 'status', label: 'Status', get: function (i) { return i.status || ''; } },
        { key: 'assembly', label: 'Assembly', get: function (i) { return i.assembly || ''; } },
        { key: 'annotation', label: 'Annotation', get: function (i) { return i.annotation || ''; } },
        { key: 'text', label: 'Detail', get: function (i) { return R.plainText(i.text || ''); } }
      ]
    })) { any = true; }

    return any;
  }

  function renderPositions(data) {
    var out = R.byId('locus-record-positions-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Curated map positions',
      items: data.positions || [],
      filename: 'locus-map-positions.tsv',
      columns: [
        R.recordColumn('Map', 'map', function (p) { return p.map; }),
        { key: 'value', label: 'Position', numeric: true, sort: 'number',
          get: function (p) {
            if (p.value === null || p.value === undefined) { return ''; }
            return p.value + (p.error ? ' ± ' + p.error : '');
          } },
        { key: 'bin', label: 'Bin', get: function (p) {
            if (!p.bin) { return ''; }
            return p.bin + (p.bin_end && p.bin_end !== p.bin ? '–' + p.bin_end : '');
          } },
        { key: 'backbone', label: 'Backbone',
          get: function (p) { return p.backbone ? 'Yes' : ''; } },
        R.urlColumn(function (p) { return p.map ? p.map.html : ''; })
      ],
      empty: 'This locus is not placed on any curated map.'
    });
  }

  /* Nearby loci: four mapsets, each its own block, and a window control that
     re-requests just this section. */
  function renderNearby(data) {
    var out = R.byId('locus-record-nearby-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var any = false;
    (data.nearby || []).forEach(function (set) {
      if (!(set.loci || []).length) { return; }
      any = R.collection(out, {
        title: set.mapset,
        items: set.loci,
        filename: 'nearby-' + set.mapset.replace(/[^A-Za-z0-9]+/g, '-').toLowerCase() + '.tsv',
        columns: [
          R.recordColumn('Locus', 'locus', function (l) { return l.ref; }),
          { key: 'full_name', label: 'Full name', get: function (l) { return l.full_name || ''; } },
          { key: 'type', label: 'Type', get: function (l) { return l.type || ''; } },
          { key: 'position', label: 'Position (cM)', numeric: true, sort: 'number',
            get: function (l) { return l.position === null ? '' : String(l.position); } },
          R.urlColumn(function (l) { return l.ref ? l.ref.html : ''; })
        ]
      }) || any;
    });
    if (!any) {
      out.innerHTML = '<p class="mgdb-rec-empty">This locus has no position on the four mapsets used for neighbours, so none can be listed.</p>';
    }
    return true;
  }

  function renderAlleles(data) {
    var out = R.byId('locus-record-alleles-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var a = data.alleles || {};
    var any = false;

    if (R.collection(out, {
      title: 'Variations and alleles',
      items: a.variations || [],
      filename: 'locus-variations.tsv',
      columns: [
        R.recordColumn('Variation', 'variation', function (v) { return v.ref; }),
        { key: 'type', label: 'Type', get: function (v) { return v.type || ''; } },
        { key: 'keys', label: 'External entries', numeric: true, sort: 'number',
          get: function (v) { return v.external_keys ? String(v.external_keys) : ''; } },
        R.urlColumn(function (v) { return v.ref ? v.ref.html : ''; })
      ],
      empty: 'No variations are recorded for this locus.'
    })) { any = true; }

    if ((a.phenotypes || []).length) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Phenotypes' +
        '<span class="mgdb-rec-block-count">' + R.number(a.phenotypes.length) + '</span></h3></div>' +
        '<p class="locus-pheno-list">' + a.phenotypes.map(function (p) {
          return R.refLink(p) || R.escape(p.name);
        }).join(', ') + '</p></div>');
      any = true;
    }

    /* Images belong to the variations, not to the locus, which is why the
       legacy page shows them in this part of the record. */
    if ((a.images || []).length) {
      R.images(out, a.images.map(function (i) {
        return {
          url: i.url,
          thumbnail: i.thumbnail,
          caption: i.caption,
          title: i.variation ? i.variation.name : '',
          record: i.variation ? i.variation.html : '',
          meta: i.type
        };
      }), 'locus-image-dialog', { title: 'Images' });
      any = true;
    }
    return any;
  }

  function renderStocks(data) {
    var out = R.byId('locus-record-stocks-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Stocks carrying a variation of this locus',
      items: data.stocks || [],
      filename: 'locus-stocks.tsv',
      columns: [
        R.recordColumn('Stock', 'stock', function (s) { return s.ref; }),
        { key: 'type', label: 'Type', get: function (s) { return s.type || ''; } },
        { key: 'available_from', label: 'Available from',
          get: function (s) { return s.available_from || ''; },
          html: function (s) {
            if (!s.available_from) { return '—'; }
            /* The legacy page bolds Stock Center holdings because those are the
               ones a reader can actually order. */
            return s.stock_center
              ? '<strong>' + R.escape(s.available_from) + '</strong>'
              : R.escape(s.available_from);
          } },
        { key: 'developer', label: 'Developer', get: function (s) { return s.developer || ''; } },
        R.urlColumn(function (s) { return s.ref ? s.ref.html : ''; })
      ]
    });
  }

  function renderDetected(data) {
    var out = R.byId('locus-record-detected-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var d = data.detected || {};
    var LABELS = { ssr: 'SSRs', overgo: 'Overgos', est: 'ESTs', bac: 'BACs', probe: 'Other probes' };
    var any = false;
    ['ssr', 'overgo', 'est', 'bac', 'probe'].forEach(function (kind) {
      any = R.collection(out, {
        title: LABELS[kind],
        items: d[kind] || [],
        filename: 'locus-' + kind + '.tsv',
        columns: [
          R.recordColumn(LABELS[kind].replace(/s$/, ''), kind, function (p) { return p.ref; }),
          { key: 'type', label: 'Type', get: function (p) { return p.type || ''; } },
          { key: 'method', label: 'Method', get: function (p) { return p.method || ''; } },
          R.urlColumn(function (p) { return p.ref ? p.ref.html : ''; })
        ]
      }) || any;
    });
    return any;
  }

  function renderGenetic(data) {
    var out = R.byId('locus-record-genetic-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var g = data.genetic || {};
    var any = false;

    if (R.collection(out, {
      title: 'Primers and enzymes',
      items: g.primers || [],
      filename: 'locus-primers.tsv',
      columns: [
        { key: 'sequence', label: 'Primer sequence', tile: true,
          get: function (p) { return p.sequence || ''; },
          html: function (p) {
            return '<code class="mgdb-sequence">' + R.escape(p.sequence || '') + '</code>';
          } },
        R.recordColumn('Probe', 'probe', function (p) { return p.probe; }),
        R.urlColumn(function (p) { return p.probe ? p.probe.html : ''; })
      ]
    })) { any = true; }

    [['related_bacs', 'Related BACs', 'BAC'],
     ['gel_patterns', 'Gel patterns', 'Gel pattern'],
     ['map_scores', 'Map scores', 'Map score'],
     ['recombination', 'Recombination data', 'Dataset']].forEach(function (spec) {
      if (R.collection(out, {
        title: spec[1],
        items: g[spec[0]] || [],
        filename: 'locus-' + spec[0].replace(/_/g, '-') + '.tsv',
        columns: [
          R.recordColumn(spec[2], spec[0], function (x) { return x; }),
          R.urlColumn(function (x) { return x ? x.html : ''; })
        ]
      })) { any = true; }
    });

    if (!any) {
      out.innerHTML = '<p class="mgdb-rec-empty">No primers, BACs, gel patterns, map scores, or recombination data are recorded for this locus.</p>';
    }
    return true;
  }

  function renderRelated(data) {
    var out = R.byId('locus-record-related-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var r = data.related || {};
    var any = false;

    if (R.collection(out, {
      title: 'Related loci',
      items: r.loci || [],
      filename: 'locus-related-loci.tsv',
      columns: [
        R.recordColumn('Locus', 'locus', function (l) { return l.ref; }),
        { key: 'full_name', label: 'Full name', get: function (l) { return l.full_name || ''; } },
        { key: 'type', label: 'Type', get: function (l) { return l.type || ''; } },
        { key: 'relation', label: 'Relationship', get: function (l) { return l.relation || ''; } },
        R.urlColumn(function (l) { return l.ref ? l.ref.html : ''; })
      ]
    })) { any = true; }

    if (R.collection(out, {
      title: 'Associated gene models',
      items: r.gene_models || [],
      filename: 'locus-gene-models.tsv',
      columns: [
        { key: 'gene_model', label: 'Gene model', tile: true,
          get: function (m) { return m.gene_model || ''; },
          html: function (m) { return R.link(m.html, m.gene_model); } },
        { key: 'source', label: 'Source', get: function (m) { return m.source || ''; } },
        { key: 'comment', label: 'Comment', get: function (m) { return m.comment || ''; } },
        { key: 'reference', label: 'Reference',
          get: function (m) { return m.reference ? m.reference.name : ''; },
          html: function (m) { return m.reference ? (R.refLink(m.reference) || R.escape(m.reference.name)) : '—'; } },
        R.urlColumn(function (m) { return m.html; })
      ]
    })) { any = true; }

    return any;
  }

  function renderOffsite(data) {
    var out = R.byId('locus-record-offsite-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.offsite || {};
    var any = false;

    function entryColumns(extra) {
      var cols = extra ? [extra] : [];
      return cols.concat([
        { key: 'key', label: 'Accession', tile: !extra,
          get: function (e) { return e.key || ''; },
          html: function (e) {
            return e.url ? R.link(e.url, e.key) : '<code class="mgdb-sequence">' + R.escape(e.key || '') + '</code>';
          } },
        { key: 'database', label: 'Database', get: function (e) { return e.database || ''; } },
        R.urlColumn(function (e) { return e.url || ''; })
      ]);
    }

    if (R.collection(out, {
      title: 'External database entries',
      items: o.entries || [],
      filename: 'locus-offsite.tsv',
      columns: entryColumns(null).concat([
        { key: 'comment', label: 'Note', get: function (e) { return e.comment || ''; } }
      ])
    })) { any = true; }

    if (R.collection(out, {
      title: 'NCBI Gene',
      items: o.ncbi_gene || [],
      filename: 'locus-ncbi-gene.tsv',
      columns: entryColumns(null)
    })) { any = true; }

    if (R.collection(out, {
      title: 'Entries for the probes that detect this locus',
      items: o.probe_keys || [],
      filename: 'locus-probe-keys.tsv',
      columns: entryColumns(R.recordColumn('Probe', 'probe', function (e) { return e.probe; }))
    })) { any = true; }

    if (R.collection(out, {
      title: 'Entries for this locus’s variations',
      items: o.variation_keys || [],
      filename: 'locus-variation-keys.tsv',
      columns: entryColumns(R.recordColumn('Variation', 'variation', function (e) { return e.variation; }))
    })) { any = true; }

    if (R.collection(out, {
      title: 'Entries for this locus’s gene products',
      items: o.gene_product_keys || [],
      filename: 'locus-gene-product-keys.tsv',
      columns: entryColumns(R.recordColumn('Gene product', 'gene_product', function (e) { return e.gene_product; }))
    })) { any = true; }

    if (!any) {
      out.innerHTML = '<p class="mgdb-rec-empty">No external database entries are recorded for this locus.</p>';
    }
    return true;
  }

  function renderAnnotations(data) {
    var out = R.byId('locus-record-annotations-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Ontology terms',
      items: data.annotations || [],
      filename: 'locus-annotations.tsv',
      columns: [
        R.recordColumn('Term', 'term', function (a) { return a.term; }),
        { key: 'ontology', label: 'Ontology', get: function (a) { return a.ontology || ''; } },
        { key: 'evidence', label: 'Evidence', get: function (a) { return a.evidence || ''; } },
        { key: 'reference', label: 'Reference',
          get: function (a) { return a.reference ? a.reference.name : ''; },
          html: function (a) { return a.reference ? (R.refLink(a.reference) || R.escape(a.reference.name)) : '—'; } },
        R.urlColumn(function (a) { return a.term ? a.term.html : ''; })
      ]
    });
  }

  function renderReferences(data) {
    return R.references(R.byId('locus-record-references-body'), data.references || [],
                        'locus-record-references', 'locus-ref');
  }

  /* Physical positions come from an outbound call to the GBrowse feature
     service, so they are fetched on their own once the record has rendered
     rather than holding it up. They join the Map positions section, which is
     where the legacy page shows them. */
  function loadPhysical() {
    var out = R.byId('locus-record-positions-body');
    if (!out) { return; }
    fetch(ENDPOINT + '?fields=physical',
          { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (doc) {
        var rows = (doc.data.sections || {}).physical || [];
        if (!rows.length) { return; }
        R.show(R.byId('locus-record-positions'), true);
        R.collection(out, {
          title: 'Physical positions',
          items: rows,
          filename: 'locus-physical-positions.tsv',
          columns: [
            { key: 'assembly', label: 'Assembly', tile: true,
              get: function (p) { return p.assembly || ''; } },
            { key: 'feature', label: 'Feature', get: function (p) { return p.feature || ''; } },
            { key: 'chromosome', label: 'Chromosome', get: function (p) { return p.chromosome || ''; } },
            { key: 'start', label: 'Start', numeric: true, sort: 'number',
              get: function (p) { return p.start === null ? '' : String(p.start); } },
            { key: 'end', label: 'End', numeric: true, sort: 'number',
              get: function (p) { return p.end === null ? '' : String(p.end); } },
            { key: 'source', label: 'Source', get: function (p) { return p.source || ''; } }
          ]
        });
      })
      .catch(function () { /* the service is optional; the record stands without it */ });
  }

  /* ---------------------------------------------------------------------- *
   * Metrics
   * ---------------------------------------------------------------------- */

  function renderMetrics(counts, data) {
    var body = R.byId('locus-record-metrics-body');
    if (!body) { return false; }

    var probes = (counts.detected_ssr || 0) + (counts.detected_overgo || 0) +
                 (counts.detected_est || 0) + (counts.detected_bac || 0) +
                 (counts.detected_probe || 0);
    var offsite = (counts.offsite_entries || 0) + (counts.offsite_ncbi_gene || 0);

    R.metrics(body, [
      ['Map positions', 'Placements', counts.positions || 0,
       'Curated maps this locus is placed on.', 'green'],
      ['Alleles', 'Variations', counts.alleles || 0,
       'Variations recorded against this locus.', 'amber'],
      ['Probes', 'Detected by', probes,
       'SSRs, overgos, ESTs, BACs, and other probes that detect it.', 'blue'],
      ['References', 'Literature', counts.references || 0,
       'Publications attached to this record.', 'burgundy']
    ]);

    var series = [
      ['Map positions', counts.positions || 0],
      ['Alleles', counts.alleles || 0],
      ['Stocks', counts.stocks || 0],
      ['Probes', probes],
      ['Related loci', counts.related_loci || 0],
      ['Gene models', counts.related_gene_models || 0],
      ['External entries', offsite],
      ['References', counts.references || 0]
    ].filter(function (row) { return row[1] > 0; });

    /* The shell's signature is (chartId, captionId, figureId, series, height)
       and it hides the figure itself when nothing is left after filtering. */
    R.connectionsChart('locus-record-connections-chart',
                       'locus-record-connections-caption',
                       'locus-record-connections-figure',
                       series, R.connectionsHeight(series));

    /* yearsChart takes the reference rows, not a list of years -- it counts
       the years itself and shows the figure only when there are at least two
       distinct ones. */
    R.yearsChart('locus-record-years-chart',
                 'locus-record-years-caption',
                 'locus-record-years-figure',
                 data.references || []);
    return true;
  }

  /* ---------------------------------------------------------------------- *
   * Load
   * ---------------------------------------------------------------------- */

  function fillSynonyms(attributes) {
    var el = R.byId('locus-record-synonyms');
    if (!el) { return; }
    var list = attributes.synonyms || [];
    if (!list.length) { return; }
    el.innerHTML = '<span class="mgdb-rec-synonyms-label">Also known as</span> ' +
      list.map(function (s) { return R.escape(s.name); }).join(', ');
    R.show(el, true);
  }

  function render(doc) {
    var data = doc.data.sections || {};
    var counts = (doc.meta && doc.meta.counts) || {};
    payload = doc;

    fillSynonyms(doc.data.attributes || {});

    var rendered = {};
    rendered['locus-record-overview'] = renderOverview(data);
    rendered['locus-record-positions'] = renderPositions(data);
    rendered['locus-record-nearby'] = renderNearby(data);
    rendered['locus-record-alleles'] = renderAlleles(data);
    rendered['locus-record-stocks'] = renderStocks(data);
    rendered['locus-record-detected'] = renderDetected(data);
    rendered['locus-record-genetic'] = renderGenetic(data);
    rendered['locus-record-related'] = renderRelated(data);
    rendered['locus-record-offsite'] = renderOffsite(data);
    rendered['locus-record-annotations'] = renderAnnotations(data);
    rendered['locus-record-references'] = renderReferences(data);
    rendered['locus-record-metrics'] = true;

    var order = [];
    Object.keys(SECTION_LABELS).forEach(function (id) {
      if (id === 'locus-record-resources') { order.push(id); return; }
      var el = R.byId(id);
      if (!el) { return; }
      if (rendered[id]) { R.show(el, true); order.push(id); }
      else { R.show(el, false); }
    });

    /* Figures are drawn after their section is visible, not before. Plotly
       measures the container at draw time, and a container inside a `hidden`
       section measures zero -- so the chart fell back to its own 700px default
       and overflowed a 533px column, taking the page with it. */
    renderMetrics(counts, data);
    loadPhysical();

    R.tabs({
      el: R.byId('locus-record-tabs'),
      order: order,
      labels: SECTION_LABELS,
      counts: counts,
      tabCounts: {
        'locus-record-positions': ['positions'],
        'locus-record-alleles': ['alleles'],
        'locus-record-stocks': ['stocks'],
        'locus-record-detected': ['detected_ssr', 'detected_overgo', 'detected_est',
                                  'detected_bac', 'detected_probe'],
        'locus-record-related': ['related_loci', 'related_gene_models'],
        'locus-record-offsite': ['offsite_entries', 'offsite_ncbi_gene'],
        'locus-record-annotations': ['annotations'],
        'locus-record-references': ['references']
      }
    });

    /* A truncated list is a fact the reader needs: the count in the tab is the
       true total, but the table is showing a capped slice. */
    var truncated = (doc.meta && doc.meta.truncated) || [];
    if (truncated.length) {
      R.notice('locus-record-notice',
        'Some lists on this record are long and have been capped at ' +
        R.number(doc.meta.max_items) + ' rows: ' + truncated.join(', ') +
        '. The counts shown are the true totals, and the API returns everything.');
    }

    R.show(R.byId('locus-record-loading'), false);
  }

  function load() {
    R.show(R.byId('locus-record-error'), false);
    R.show(R.byId('locus-record-loading'), true);
    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(render)
      .catch(function () {
        R.show(R.byId('locus-record-loading'), false);
        R.show(R.byId('locus-record-error'), true);
      });
  }

  function init() {
    root = R.byId('locus-record-top');
    if (!root) { return; }

    locusId = root.getAttribute('data-locus-id');
    requested = root.getAttribute('data-requested-id') || locusId;
    ENDPOINT = '/api/v1/records/locus/' + encodeURIComponent(requested);

    var retry = R.byId('locus-record-retry');
    if (retry) { retry.addEventListener('click', load); }

    /* The neighbour window re-requests only the nearby section, so changing it
       costs one small query rather than the whole record. */
    var cmSelect = R.byId('locus-nearby-cm');
    if (cmSelect) {
      cmSelect.addEventListener('change', function () {
        var status = R.byId('locus-nearby-status');
        if (status) { status.textContent = 'Loading\u2026'; }
        fetch(ENDPOINT + '?fields=nearby&cm=' + encodeURIComponent(cmSelect.value),
              { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (doc) {
            renderNearby(doc.data.sections || {});
            if (status) { status.textContent = ''; }
          })
          .catch(function () {
            if (status) { status.textContent = 'Could not reload the neighbours.'; }
          });
      });
    }

    R.apiCard('locus-copy-json-btn', 'locus-record-api-link', function () { return payload; });

    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
