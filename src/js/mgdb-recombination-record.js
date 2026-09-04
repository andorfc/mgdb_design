/* file: js/mgdb-recombination-record.js
 *
 * The recombination dataset record page. One request to
 * /api/v1/records/recombination/{id} fills every section.
 */
(function () {
  'use strict';

  var R = window.MGDBRecord;
  if (!R) { return; }

  var root = null, recordId = null, requested = null, ENDPOINT = null;
  var payload = null;

  var SECTION_LABELS = {
    'rc-record-overview': 'Overview',
    'rc-record-loci': 'Loci',
    'rc-record-frequencies': 'Frequencies',
    'rc-record-alleles': 'Alleles',
    'rc-record-classes': 'Classes',
    'rc-record-overlaps': 'Overlaps',
    'rc-record-references': 'References',
    'rc-record-resources': 'Related resources'
  };

  function renderOverview(data) {
    var out = R.byId('rc-record-overview-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview;
    if (!o) { return false; }
    var any = false;

    var pairs = [];
    if (o.cross_type) { pairs.push(['Cross type', R.escape(o.cross_type), o.cross_type_note]); }
    if (o.markers !== null && o.markers !== undefined) { pairs.push(['Markers scored', R.number(o.markers)]); }
    if (o.total_progeny !== null && o.total_progeny !== undefined) { pairs.push(['Total progeny', R.number(o.total_progeny)]); }
    if (o.quality) { pairs.push(['Quality', R.escape(o.quality)]); }
    if (o.ordering) { pairs.push(['Locus ordering', R.escape(o.ordering)]); }

    var factsHtml = R.facts(pairs);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); any = true; }

    if (R.notes(out, 'Curator notes', (o.comments || []).map(function (c) {
      return { text: c.text, meta: [c.kind] };
    }))) { any = true; }

    return any;
  }

  function renderLoci(data) {
    var out = R.byId('rc-record-loci-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Loci scored in this cross',
      items: data.loci || [],
      filename: 'recombination-loci.tsv',
      columns: [
        R.recordColumn('Locus', 'locus', function (l) { return l.ref; }),
        { key: 'full_name', label: 'Full name', get: function (l) { return l.full_name || ''; } },
        { key: 'type', label: 'Type', get: function (l) { return l.type || ''; } },
        R.urlColumn(function (l) { return l.ref ? l.ref.html : ''; })
      ]
    });
  }

  /* The pairwise table is the result of the experiment: a recombination
     fraction between two loci, its standard error, and the same distance under
     the two standard mapping functions.
  
     The numeric columns are added only when this record has values for them.
     Across mgdb.recomb_freq, 3,026 of 7,317 rows carry a frequency and just
     **3** carry Haldane or Kosambi distances -- a fixed six-column table would
     print a column of em dashes on almost every record and imply the data is
     missing rather than never collected. */
  function renderFrequencies(data) {
    var out = R.byId('rc-record-frequencies-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var rows = data.frequencies || [];
    if (!rows.length) { return false; }

    function has(field) {
      return rows.some(function (f) { return f[field] !== null && f[field] !== undefined && f[field] !== ''; });
    }

    var columns = [
      R.recordColumn('Locus A', 'before', function (f) { return f.before; }),
      R.recordColumn('Locus B', 'after', function (f) { return f.after; })
    ];
    var numeric = [
      ['frequency', 'Frequency', 'frequency'],
      ['standard_error', 'SE', 'se'],
      ['haldane_cm', 'Haldane cM', 'haldane'],
      ['kosambi_cm', 'Kosambi cM', 'kosambi']
    ].filter(function (spec) { return has(spec[0]); });

    numeric.forEach(function (spec) {
      columns.push({ key: spec[2], label: spec[1], numeric: true, sort: 'number',
        get: function (f) { return f[spec[0]] === null ? '' : String(f[spec[0]]); } });
    });

    R.collection(out, {
      title: 'Pairwise recombination frequencies',
      items: rows,
      filename: 'recombination-frequencies.tsv',
      pageSize: 25,
      columns: columns
    });

    /* Some datasets record only which pairs were scored, with no measured
       values at all. Saying so beats a table that looks broken. */
    if (!numeric.length) {
      out.insertAdjacentHTML('beforeend',
        '<p class="mgdb-rec-block-status">This dataset records which locus pairs were scored ' +
        'but carries no measured recombination values.</p>');
    }
    return true;
  }

  function renderAlleles(data) {
    var out = R.byId('rc-record-alleles-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Alleles carried by the parents',
      items: data.alleles || [],
      filename: 'recombination-alleles.tsv',
      columns: [
        R.recordColumn('Allele', 'variation', function (a) { return a.variation; }),
        { key: 'locus', label: 'Locus',
          get: function (a) { return a.locus ? a.locus.name : ''; },
          html: function (a) { return a.locus ? (R.refLink(a.locus) || R.escape(a.locus.name)) : '—'; } },
        { key: 'parent', label: 'Parent', numeric: true, sort: 'number',
          get: function (a) { return a.parent === null ? '' : String(a.parent); } },
        { key: 'chromosome', label: 'Chromosome', get: function (a) { return a.chromosome || ''; } },
        R.urlColumn(function (a) { return a.variation ? a.variation.html : ''; })
      ]
    });
  }

  function renderClasses(data) {
    var out = R.byId('rc-record-classes-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Observed genotype classes',
      items: data.classes || [],
      filename: 'recombination-classes.tsv',
      columns: [
        { key: 'genotype', label: 'Genotype', tile: true,
          get: function (c) { return c.genotype || ''; },
          html: function (c) { return '<code class="mgdb-sequence">' + R.escape(c.genotype || '') + '</code>'; } },
        { key: 'count', label: 'Progeny', numeric: true, sort: 'number',
          get: function (c) { return c.count === null ? '' : String(c.count); } }
      ]
    });
  }

  function renderOverlaps(data) {
    var out = R.byId('rc-record-overlaps-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Datasets this one overlaps',
      items: data.overlaps || [],
      filename: 'recombination-overlaps.tsv',
      columns: [
        R.recordColumn('Dataset', 'recombination', function (o) { return o.ref; }),
        { key: 'uncertain', label: 'Uncertain', get: function (o) { return o.uncertain || ''; } },
        R.urlColumn(function (o) { return o.ref ? o.ref.html : ''; })
      ]
    });
  }

  function renderReferences(data) {
    return R.references(R.byId('rc-record-references-body'), data.references || [],
                        'rc-record-references', 'rc-ref');
  }

  function render(doc) {
    var data = doc.data.sections || {};
    var counts = (doc.meta && doc.meta.counts) || {};
    payload = doc;

    var rendered = {
      'rc-record-overview': renderOverview(data),
      'rc-record-loci': renderLoci(data),
      'rc-record-frequencies': renderFrequencies(data),
      'rc-record-alleles': renderAlleles(data),
      'rc-record-classes': renderClasses(data),
      'rc-record-overlaps': renderOverlaps(data),
      'rc-record-references': renderReferences(data)
    };

    var order = [];
    Object.keys(SECTION_LABELS).forEach(function (id) {
      if (id === 'rc-record-resources') { order.push(id); return; }
      var el = R.byId(id);
      if (!el) { return; }
      if (rendered[id]) { R.show(el, true); order.push(id); }
      else { R.show(el, false); }
    });

    R.tabs({
      el: R.byId('rc-record-tabs'),
      order: order,
      labels: SECTION_LABELS,
      counts: counts,
      tabCounts: {
        'rc-record-loci': ['loci'],
        'rc-record-frequencies': ['frequencies'],
        'rc-record-alleles': ['alleles'],
        'rc-record-classes': ['classes'],
        'rc-record-overlaps': ['overlaps'],
        'rc-record-references': ['references']
      }
    });

    var truncated = (doc.meta && doc.meta.truncated) || [];
    if (truncated.length) {
      R.notice('rc-record-notice',
        'Some lists on this record are long and have been capped at ' +
        R.number(doc.meta.max_items) + ' rows: ' + truncated.join(', ') +
        '. The counts shown are the true totals, and the API returns everything.');
    }

    R.show(R.byId('rc-record-loading'), false);
  }

  function load() {
    R.show(R.byId('rc-record-error'), false);
    R.show(R.byId('rc-record-loading'), true);
    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(render)
      .catch(function () {
        R.show(R.byId('rc-record-loading'), false);
        R.show(R.byId('rc-record-error'), true);
      });
  }

  function init() {
    root = R.byId('rc-record-top');
    if (!root) { return; }
    recordId = root.getAttribute('data-record-id');
    requested = root.getAttribute('data-requested-id') || recordId;
    ENDPOINT = '/api/v1/records/recombination/' + encodeURIComponent(requested);

    var retry = R.byId('rc-record-retry');
    if (retry) { retry.addEventListener('click', load); }
    R.apiCard('rc-copy-json-btn', 'rc-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
