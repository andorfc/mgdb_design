/* file: js/mgdb-term-record.js
 *
 * The term / trait record page. One request to /api/v1/records/term/{id} fills
 * every section; the shared record shell (js/mgdb-record.js) supplies the
 * collections, the tabs, the figures and the API card.
 *
 * /data_center/term and /data_center/trait are the same record over mgdb.term,
 * so this file draws both section sets and lets the data decide which appear.
 * 105 term types share it.
 */
(function () {
  'use strict';

  var R = window.MGDBRecord;
  if (!R) { return; }

  /* Filled by init(). Page scripts are emitted in <head>, so at parse time
     none of these elements exist yet. */
  var root = null, termId = null, requested = null, ENDPOINT = null;
  var payload = null;

  var SECTION_LABELS = {
    'term-record-overview': 'Overview',
    'term-record-values': 'Values',
    'term-record-phenotypes': 'Phenotypes',
    'term-record-analyses': 'QTL analyses',
    'term-record-related': 'Related terms',
    'term-record-images': 'Images',
    'term-record-offsite': 'Offsite',
    'term-record-references': 'References',
    'term-record-metrics': 'Metrics',
    'term-record-resources': 'Related resources'
  };

  /* ---------------------------------------------------------------------- */

  function renderOverview(data) {
    var out = R.byId('term-record-overview-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview;
    if (!o) { return false; }
    var any = false;

    /* The definition is what a vocabulary record is for, so it leads at body
       size rather than sitting in a fact row. */
    if (o.definition) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block term-definition"><p>' + R.escape(o.definition) + '</p></div>');
      any = true;
    }

    var pairs = [];
    if (o.type) { pairs.push(['Term type', R.refLink(o.type) || R.escape(o.type.name)]); }
    var factsHtml = R.facts(pairs);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); any = true; }

    if (R.notes(out, 'Curator notes', (o.comments || []).map(function (c) {
      return { text: c.text, meta: [c.kind,
        c.reference ? 'Source: ' + (R.refLink(c.reference) || R.escape(c.reference.name)) : '',
        c.year ? String(c.year) : ''] };
    }))) { any = true; }

    return any;
  }

  /* The measured-value summary. The legacy trait page offered a download link
     driven by an endpoint that answers with a PHP fatal, so the numbers are
     given here and the bulk files linked instead. */
  function renderValues(data) {
    var out = R.byId('term-record-values-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var v = data.values;
    if (!v || !v.values) { return false; }

    var pairs = [
      ['Values recorded', R.number(v.values)],
      ['Stocks measured', R.number(v.stocks)],
      ['Range', v.min !== null && v.max !== null ? R.escape(v.min) + ' to ' + R.escape(v.max) : ''],
      ['Mean', v.mean !== null ? R.escape(v.mean) : ''],
      ['Units', (v.units || []).map(R.escape).join(', ')]
    ];
    out.insertAdjacentHTML('beforeend', R.facts(pairs));
    out.insertAdjacentHTML('beforeend',
      '<p class="mgdb-rec-block-status">Per-line values for this trait are in the ' +
      R.link('/traits_ibm_nam', 'IBM and NAM trait viewer') + ', and as files at ' +
      R.link(v.bulk_download, 'download.maizegdb.org') + '.</p>');
    return true;
  }

  function renderPhenotypes(data) {
    var out = R.byId('term-record-phenotypes-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Phenotypes classified by this term',
      items: data.phenotypes || [],
      filename: 'term-phenotypes.tsv',
      columns: [
        R.recordColumn('Phenotype', 'phenotype', function (p) { return p; }),
        R.urlColumn(function (p) { return p ? p.html : ''; })
      ]
    });
  }

  function renderAnalyses(data) {
    var out = R.byId('term-record-analyses-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'QTL trait analyses',
      items: data.analyses || [],
      filename: 'term-qtl-analyses.tsv',
      columns: [
        R.recordColumn('Analysis', 'analysis', function (a) { return a.analysis; }),
        { key: 'experiment', label: 'Experiment',
          get: function (a) { return a.experiment ? a.experiment.name : ''; },
          html: function (a) { return a.experiment ? (R.refLink(a.experiment) || R.escape(a.experiment.name)) : '—'; } },
        R.urlColumn(function (a) { return a.analysis ? a.analysis.html : ''; })
      ]
    });
  }

  function renderRelated(data) {
    var out = R.byId('term-record-related-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Related terms',
      items: data.related || [],
      filename: 'term-related.tsv',
      columns: [
        R.recordColumn('Term', 'term', function (t) { return t.ref; }),
        { key: 'type', label: 'Type', get: function (t) { return t.type || ''; } },
        { key: 'relation', label: 'Relationship', get: function (t) { return t.relation || ''; } },
        R.urlColumn(function (t) { return t.ref ? t.ref.html : ''; })
      ]
    });
  }

  function renderImages(data) {
    var out = R.byId('term-record-images-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var items = data.images || [];
    if (!items.length) { return false; }
    R.images(out, items.map(function (i) {
      return { url: i.url, thumbnail: i.thumbnail, caption: i.caption, title: i.caption || '' };
    }), 'term-image-dialog', { title: 'Images' });
    return true;
  }

  function renderOffsite(data) {
    var out = R.byId('term-record-offsite-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'External database entries',
      items: data.offsite || [],
      filename: 'term-offsite.tsv',
      columns: [
        { key: 'key', label: 'Accession', tile: true,
          get: function (e) { return e.key || ''; },
          html: function (e) {
            return e.url ? R.link(e.url, e.key) : '<code class="mgdb-sequence">' + R.escape(e.key || '') + '</code>';
          } },
        { key: 'database', label: 'Database', get: function (e) { return e.database || ''; } },
        { key: 'comment', label: 'Note', get: function (e) { return e.comment || ''; } },
        R.urlColumn(function (e) { return e.url || ''; })
      ]
    });
  }

  function renderReferences(data) {
    return R.references(R.byId('term-record-references-body'), data.references || [],
                        'term-record-references', 'term-ref');
  }

  function renderMetrics(counts, data) {
    var body = R.byId('term-record-metrics-body');
    if (!body) { return false; }

    R.metrics(body, [
      ['Phenotypes', 'Classified', counts.phenotypes || 0,
       'Phenotype records this term classifies.', 'green'],
      ['QTL analyses', 'Measured by', counts.analyses || 0,
       'Trait analyses that measured this term.', 'amber'],
      ['Related terms', 'Vocabulary', counts.related || 0,
       'Other terms this one is related to.', 'blue'],
      ['References', 'Literature', counts.references || 0,
       'Publications attached to this record.', 'burgundy']
    ]);

    var series = [
      ['Phenotypes', counts.phenotypes || 0],
      ['QTL analyses', counts.analyses || 0],
      ['Related terms', counts.related || 0],
      ['Images', counts.images || 0],
      ['External entries', counts.offsite || 0],
      ['References', counts.references || 0]
    ];

    R.connectionsChart('term-record-connections-chart',
                       'term-record-connections-caption',
                       'term-record-connections-figure',
                       series, R.connectionsHeight(series));

    R.yearsChart('term-record-years-chart', 'term-record-years-caption',
                 'term-record-years-figure', data.references || []);
    return true;
  }

  /* ---------------------------------------------------------------------- */

  function fillSynonyms(attributes) {
    var el = R.byId('term-record-synonyms');
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
    rendered['term-record-overview'] = renderOverview(data);
    rendered['term-record-values'] = renderValues(data);
    rendered['term-record-phenotypes'] = renderPhenotypes(data);
    rendered['term-record-analyses'] = renderAnalyses(data);
    rendered['term-record-related'] = renderRelated(data);
    rendered['term-record-images'] = renderImages(data);
    rendered['term-record-offsite'] = renderOffsite(data);
    rendered['term-record-references'] = renderReferences(data);
    rendered['term-record-metrics'] = true;

    var order = [];
    Object.keys(SECTION_LABELS).forEach(function (id) {
      if (id === 'term-record-resources') { order.push(id); return; }
      var el = R.byId(id);
      if (!el) { return; }
      if (rendered[id]) { R.show(el, true); order.push(id); }
      else { R.show(el, false); }
    });

    /* Figures after their section is visible: Plotly measures the container at
       draw time and a hidden one measures zero, which makes it fall back to a
       700px default and overflow the column. */
    renderMetrics(counts, data);

    R.tabs({
      el: R.byId('term-record-tabs'),
      order: order,
      labels: SECTION_LABELS,
      counts: counts,
      tabCounts: {
        'term-record-phenotypes': ['phenotypes'],
        'term-record-analyses': ['analyses'],
        'term-record-related': ['related'],
        'term-record-images': ['images'],
        'term-record-offsite': ['offsite'],
        'term-record-references': ['references']
      }
    });

    var truncated = (doc.meta && doc.meta.truncated) || [];
    if (truncated.length) {
      R.notice('term-record-notice',
        'Some lists on this record are long and have been capped at ' +
        R.number(doc.meta.max_items) + ' rows: ' + truncated.join(', ') +
        '. The counts shown are the true totals, and the API returns everything.');
    }

    R.show(R.byId('term-record-loading'), false);
  }

  function load() {
    R.show(R.byId('term-record-error'), false);
    R.show(R.byId('term-record-loading'), true);
    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(render)
      .catch(function () {
        R.show(R.byId('term-record-loading'), false);
        R.show(R.byId('term-record-error'), true);
      });
  }

  function init() {
    root = R.byId('term-record-top');
    if (!root) { return; }

    termId = root.getAttribute('data-term-id');
    requested = root.getAttribute('data-requested-id') || termId;
    ENDPOINT = '/api/v1/records/term/' + encodeURIComponent(requested);

    var retry = R.byId('term-record-retry');
    if (retry) { retry.addEventListener('click', load); }

    R.apiCard('term-copy-json-btn', 'term-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
