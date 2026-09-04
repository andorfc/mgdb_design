/* file: js/mgdb-primer-record.js
 *
 * The primer record page. One request to /api/v1/records/primer/{id} fills
 * every section.
 */
(function () {
  'use strict';

  var R = window.MGDBRecord;
  if (!R) { return; }

  var root = null, recordId = null, requested = null, ENDPOINT = null;
  var payload = null;

  var SECTION_LABELS = {
    'primer-record-overview': 'Overview',
    'primer-record-probes': 'Probes',
    'primer-record-isoschizomers': 'Isoschizomers',
    'primer-record-references': 'References',
    'primer-record-resources': 'Related resources'
  };

  function renderOverview(data) {
    var out = R.byId('primer-record-overview-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var o = data.overview;
    if (!o) { return false; }
    var any = false;

    if (o.sequence) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Sequence' +
        (o.length ? '<span class="mgdb-rec-block-count">' + R.number(o.length) + ' nt</span>' : '') +
        '</h3><div class="mgdb-rec-toolbar">' +
        '<button class="mgdb-rec-tsv mgdb-ref-copy" type="button" data-copy-value="' +
        R.escape(o.sequence) + '">Copy sequence</button></div></div>' +
        '<pre class="primer-sequence"><code>' + R.escape(o.sequence) + '</code></pre></div>');
      any = true;
    }

    var pairs = [];
    if (o.type) { pairs.push(['Type', R.escape(o.type)]); }
    if (o.melting_temperature) {
      pairs.push(['Melting temperature', R.escape(String(o.melting_temperature).replace(/0+$/, '').replace(/\.$/, '')) + ' &deg;C']);
    }
    if (o.submitted_by) { pairs.push(['Submitted by', R.refLink(o.submitted_by) || R.escape(o.submitted_by.name)]); }
    if (o.submitted_on) { pairs.push(['Submitted on', R.escape(o.submitted_on)]); }

    var factsHtml = R.facts(pairs);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); any = true; }

    if (R.notes(out, 'Comments', o.comments ? [{ text: o.comments, meta: [] }] : [])) { any = true; }

    if (window.MGDB && MGDB.initCopyButtons) { MGDB.initCopyButtons(); }
    return any;
  }

  function renderProbes(data) {
    var out = R.byId('primer-record-probes-body');
    if (!out) { return false; }
    out.innerHTML = '';
    var p = data.probes || {};
    var LABELS = { ssr: 'SSRs', overgo: 'Overgos', est: 'ESTs', bac: 'BACs', probe: 'Other probes' };
    var any = false;
    ['ssr', 'overgo', 'est', 'bac', 'probe'].forEach(function (kind) {
      any = R.collection(out, {
        title: LABELS[kind],
        items: p[kind] || [],
        filename: 'primer-' + kind + '.tsv',
        columns: [
          R.recordColumn(LABELS[kind].replace(/s$/, ''), kind, function (x) { return x.ref; }),
          { key: 'type', label: 'Type', get: function (x) { return x.type || ''; } },
          R.urlColumn(function (x) { return x.ref ? x.ref.html : ''; })
        ]
      }) || any;
    });
    return any;
  }

  function renderIsoschizomers(data) {
    var out = R.byId('primer-record-isoschizomers-body');
    if (!out) { return false; }
    out.innerHTML = '';
    return R.collection(out, {
      title: 'Enzymes recognising the same site',
      items: data.isoschizomers || [],
      filename: 'primer-isoschizomers.tsv',
      columns: [
        R.recordColumn('Enzyme', 'primer', function (i) { return i.ref; }),
        { key: 'sequence', label: 'Sequence',
          get: function (i) { return i.sequence || ''; },
          html: function (i) { return '<code class="mgdb-sequence">' + R.escape(i.sequence || '') + '</code>'; } },
        R.urlColumn(function (i) { return i.ref ? i.ref.html : ''; })
      ]
    });
  }

  function renderReferences(data) {
    return R.references(R.byId('primer-record-references-body'), data.references || [],
                        'primer-record-references', 'primer-ref');
  }

  function fillSynonyms(attributes) {
    var el = R.byId('primer-record-synonyms');
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

    var rendered = {
      'primer-record-overview': renderOverview(data),
      'primer-record-probes': renderProbes(data),
      'primer-record-isoschizomers': renderIsoschizomers(data),
      'primer-record-references': renderReferences(data)
    };

    var order = [];
    Object.keys(SECTION_LABELS).forEach(function (id) {
      if (id === 'primer-record-resources') { order.push(id); return; }
      var el = R.byId(id);
      if (!el) { return; }
      if (rendered[id]) { R.show(el, true); order.push(id); }
      else { R.show(el, false); }
    });

    R.tabs({
      el: R.byId('primer-record-tabs'),
      order: order,
      labels: SECTION_LABELS,
      counts: counts,
      tabCounts: {
        'primer-record-probes': ['probes_ssr', 'probes_overgo', 'probes_est', 'probes_bac', 'probes_probe'],
        'primer-record-isoschizomers': ['isoschizomers'],
        'primer-record-references': ['references']
      }
    });

    var truncated = (doc.meta && doc.meta.truncated) || [];
    if (truncated.length) {
      R.notice('primer-record-notice',
        'Some lists on this record are long and have been capped at ' +
        R.number(doc.meta.max_items) + ' rows: ' + truncated.join(', ') +
        '. The counts shown are the true totals, and the API returns everything.');
    }

    R.show(R.byId('primer-record-loading'), false);
  }

  function load() {
    R.show(R.byId('primer-record-error'), false);
    R.show(R.byId('primer-record-loading'), true);
    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(render)
      .catch(function () {
        R.show(R.byId('primer-record-loading'), false);
        R.show(R.byId('primer-record-error'), true);
      });
  }

  function init() {
    root = R.byId('primer-record-top');
    if (!root) { return; }
    recordId = root.getAttribute('data-record-id');
    requested = root.getAttribute('data-requested-id') || recordId;
    ENDPOINT = '/api/v1/records/primer/' + encodeURIComponent(requested);

    var retry = R.byId('primer-record-retry');
    if (retry) { retry.addEventListener('click', load); }
    R.apiCard('primer-copy-json-btn', 'primer-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
