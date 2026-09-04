/* ==========================================================================
   Stock record page — /data_center/stock?id={id}
   --------------------------------------------------------------------------
   Glue over js/mgdb-record.js, the same engine the gene product, variation,
   map, marker, phenotype, pan-gene and reference record pages use. This file
   maps one call to /api/v1/records/stock/{id} onto it.

   Three things are this page's own and are kept as they were, because they
   are not lists and the shell has nothing better to offer them:

     the pedigree viewer  a two-generation graph, with a table of the same
                          relationships beside it, a search over that table
                          and a CSV of every recorded link.
     TYPSimSelector       the genetic-similarity card and its score bars.
     the ordering panel   the Stock Center / GRIN cart. Its button is
                          deliberately disabled -- the cart handoff is not
                          built yet, and a control that looks live but does
                          nothing is worse than one that says so.

   GRIN is fetched separately. It is a live call out to the USDA BrAPI service
   and costs about 460 ms of the record's 720; asking for it in a second
   request lets everything else paint in a quarter of a second.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  var R = window.MGDBRecord;
  if (!MGDB || !R) { return; }

  var PEDIGREE_PROGENY_LIMIT = 12;

  var els = {};
  var payload = null;
  var stockName = 'Stock';

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderHeader(data) {
    var synonyms = (data.attributes || {}).synonyms || [];
    if (!synonyms.length) { return; }
    els.synonyms.innerHTML = 'Also known as ' + synonyms.map(function (s) {
      return '<strong>' + R.escape(typeof s === 'string' ? s : (s.name || s)) + '</strong>';
    }).join(' <span class="mgdb-muted" aria-hidden="true">&middot;</span> ') + '.';
    R.show(els.synonyms, true);
  }

  /* ------------------------------------------------------------------------
     Ordering panel

     A stock is distributed by one place, so exactly one panel is offered: the
     Maize Genetics Cooperation Stock Center when it is the provider, GRIN when
     the record resolves to a GRIN accession, and nothing at all when neither
     holds it.
     ------------------------------------------------------------------------ */

  var CART_SOURCES = {
    coop: {
      logo: '/images/stock/mgcsc-ear.png',
      alt: 'Maize Genetics Cooperation Stock Center',
      lead: 'Order from the Stock Center',
      button: 'Add to cart'
    },
    grin: {
      logo: '/images/grin/GGlogo-color-sm.gif',
      alt: 'GRIN-Global',
      lead: 'Order through USDA-ARS GRIN',
      button: 'Add to cart'
    }
  };

  function cartSource(sections) {
    var overview = sections.overview || {};
    var provider = overview.provider || {};

    /* Stock Center first: when it is the named provider it is the distributor,
       whatever GRIN also happens to hold. */
    if (provider.is_stock_center || overview.stock_center_id) { return 'coop'; }

    /* The GRIN accession is a MaizeGDB fact, and the offsite list already
       carries it. Reading it from there rather than from the GRIN section
       means the ordering panel is decided by the first response instead of
       waiting on a round trip to the USDA service. */
    var offsite = sections.offsite || [];
    for (var i = 0; i < offsite.length; i++) {
      var database = offsite[i].database;
      var name = (database && database.name) ? database.name : database;
      if (String(name || '').toUpperCase().indexOf('GRIN') !== -1) { return 'grin'; }
    }

    var grin = sections.grin || {};
    var details = grin.details || {};
    if (details.grin_id || details.accession_number || grin.accession) { return 'grin'; }
    return null;
  }

  function renderCart(sections) {
    var slot = R.byId('stock-cart-slot');
    if (!slot) { return; }
    var which = cartSource(sections);
    if (!which) { slot.innerHTML = ''; return; }

    var src = CART_SOURCES[which];
    slot.innerHTML =
      '<div class="stock-cart" id="stock-cart" data-cart-source="' + which + '">' +
        '<img class="stock-cart-logo" src="' + src.logo + '" alt="' + R.escape(src.alt) + '" />' +
        '<div class="stock-cart-body">' +
          '<p class="stock-cart-lead">' + R.escape(src.lead) + '</p>' +
          '<button class="mgdb-button stock-cart-add" id="stock-cart-add" type="button" disabled ' +
            'aria-describedby="stock-cart-note"><span aria-hidden="true">&#43;</span> ' +
            R.escape(src.button) + '</button>' +
          '<p class="stock-cart-note" id="stock-cart-note">Cart handoff coming soon.</p>' +
        '</div>' +
      '</div>';
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview) {
    if (!overview) { return false; }
    var out = els.overviewBody;
    out.innerHTML = '';

    var origin = overview.origin || {};
    var place = [origin.country, origin.state_province].filter(Boolean).join(', ');

    var factsHtml = R.facts([
      ['Stock type', overview.type ? R.escape(overview.type.name) : '', overview.type_definition || ''],
      ['Species', overview.species ? '<em>' + R.escape(overview.species.name) + '</em>' : ''],
      ['Classification', overview.classification ? R.escape(overview.classification) : ''],
      ['Developed by', overview.developer ? (R.refLink(overview.developer) || R.escape(overview.developer.name)) : ''],
      ['Available from', overview.provider ? (R.refLink(overview.provider) || R.escape(overview.provider.name)) : ''],
      ['Market class', overview.market_class ? R.escape(overview.market_class.name) : ''],
      ['Focus linkage group', overview.focus_linkage_group
        ? (R.refLink(overview.focus_linkage_group) || R.escape(overview.focus_linkage_group.name)) : ''],
      ['Pedigree', overview.pedigree_text ? R.escape(overview.pedigree_text) : ''],
      ['Stock Center ID', overview.stock_center_id ? R.escape(overview.stock_center_id) : ''],
      ['Origin', place ? R.escape(place) : ''],
      ['Year', origin.year ? String(origin.year) : '']
    ]);
    if (factsHtml) { out.insertAdjacentHTML('beforeend', factsHtml); }

    R.collection(out, {
      title: 'Genome assemblies',
      items: overview.assemblies,
      filename: 'stock-assemblies.tsv',
      columns: [
        { key: 'name', label: 'Assembly', tile: true,
          html: function (a) { return a.html ? R.link(a.html, a.name) : R.escape(a.name); } },
        R.urlColumn(function (a) { return a.html; })
      ]
    });

    R.notes(out, 'Curator notes', (overview.comments || []).map(function (comment) {
      return { text: comment.text, meta: [comment.label] };
    }));

    return true;
  }

  /* ------------------------------------------------------------------------
     Pedigree viewer

     This page's own, and kept as it was: a two-generation graph with the same
     relationships as a sortable, searchable table beside it, and a CSV of
     every recorded link. It is not a shell collection -- the graph is the
     point, and the table is a second reading of the same rows rather than an
     alternative layout of a list.
     ------------------------------------------------------------------------ */

  function renderPedigree(pedigree, stockData, counts) {
    if (!pedigree) { return false; }
    var parents = pedigree.parents || [];
    var progeny = pedigree.progeny || [];
    var network = pedigree.network || {};
    if (!parents.length && !progeny.length && !network.available) { return false; }

    var attributes = stockData && stockData.attributes ? stockData.attributes : {};
    var selectedName = attributes.name || ('Stock ' + (stockData ? stockData.id : ''));
    var selectedHref = '/data_center/stock?id=' + encodeURIComponent(stockData ? stockData.id : '');
    var parentCount = (counts && counts.parents !== null && counts.parents !== undefined)
      ? counts.parents : parents.length;
    var progenyCount = (counts && counts.progeny !== null && counts.progeny !== undefined)
      ? counts.progeny : progeny.length;
    var relationshipCount = parentCount + progenyCount;
    var visibleProgeny = progeny.slice(0, PEDIGREE_PROGENY_LIMIT);

    function contribution(item, missingLabel) {
      if (item.contribution_percent === null || item.contribution_percent === undefined) {
        return missingLabel || '';
      }
      return item.contribution_percent + '%';
    }

    function mapNode(item, direction) {
      var note = contribution(item, 'Contribution not reported');
      return '<a class="stock-pedigree-node stock-pedigree-node-' + direction + '" href="' +
        R.escape(item.html || '#') + '"><strong>' + R.escape(item.name) + '</strong>' +
        '<span>' + R.escape(note) + '</span></a>';
    }

    function emptyGroup(message) {
      return '<p class="stock-pedigree-empty">' + R.escape(message) + '</p>';
    }

    function relationshipRow(item, direction) {
      var label = direction === 'parent' ? 'Parent' : 'Progeny';
      var percent = contribution(item, 'Not reported');
      var search = (label + ' ' + item.name + ' ' + percent).toLowerCase();
      return '<tr data-pedigree-row data-search="' + R.escape(search) + '">' +
        '<td><span class="mgdb-pill ' + (direction === 'parent' ? 'mgdb-pill-info' : 'mgdb-pill-ok') +
        '">' + label + '</span></td>' +
        '<th scope="row"><a href="' + R.escape(item.html || '#') + '">' + R.escape(item.name) + '</a></th>' +
        '<td class="mgdb-numeric" data-value="' +
        (item.contribution_percent === null || item.contribution_percent === undefined
          ? '' : R.escape(item.contribution_percent)) + '">' + R.escape(percent) + '</td>' +
        '</tr>';
    }

    var parentNodes = parents.length
      ? parents.map(function (item) { return mapNode(item, 'parent'); }).join('')
      : emptyGroup('No parents are recorded for this stock.');
    var progenyNodes = visibleProgeny.length
      ? visibleProgeny.map(function (item) { return mapNode(item, 'progeny'); }).join('')
      : emptyGroup('No direct progeny are recorded for this stock.');
    var graphNote = progenyCount > PEDIGREE_PROGENY_LIMIT
      ? '<p class="stock-pedigree-map-note">Showing the first ' + PEDIGREE_PROGENY_LIMIT + ' of ' +
        progenyCount.toLocaleString() + ' direct progeny. The table includes every recorded relationship.</p>'
      : '';
    var rows = parents.map(function (item) { return relationshipRow(item, 'parent'); })
      .concat(progeny.map(function (item) { return relationshipRow(item, 'progeny'); })).join('');

    var html = '<div class="stock-pedigree-summary">' +
      '<dl class="stock-pedigree-metrics">' +
        '<div><dt>Parents</dt><dd>' + parentCount.toLocaleString() + '</dd><span>All shown in the map</span></div>' +
        '<div><dt>Direct progeny</dt><dd>' + progenyCount.toLocaleString() + '</dd><span>First ' +
          Math.min(progenyCount, PEDIGREE_PROGENY_LIMIT).toLocaleString() + ' shown in the map</span></div>' +
        '<div><dt>Known relationships</dt><dd>' + relationshipCount.toLocaleString() +
          '</dd><span>Complete table available</span></div>' +
      '</dl>' +
      '<div class="stock-pedigree-toolbar">' +
        '<div class="stock-pedigree-view-toggle" role="group" aria-label="Pedigree presentation">' +
          '<button type="button" class="is-active" data-pedigree-view="map" aria-pressed="true" ' +
            'aria-controls="stock-pedigree-map-panel">Graph</button>' +
          '<button type="button" data-pedigree-view="table" aria-pressed="false" ' +
            'aria-controls="stock-pedigree-table-panel">Table</button>' +
        '</div>' +
        '<div class="stock-pedigree-actions">' +
          (network.interactive ? '<a class="mgdb-button mgdb-button-primary" href="' +
            R.escape(network.interactive) + '">Explore full pedigree</a>' : '') +
          '<button class="mgdb-button mgdb-button-secondary" type="button" data-pedigree-download>' +
            'Download relationships</button>' +
        '</div>' +
      '</div>' +
      '<div id="stock-pedigree-map-panel" data-pedigree-panel="map">' +
        '<div class="stock-pedigree-map" aria-label="Direct parents and progeny of ' + R.escape(selectedName) + '">' +
          '<section class="stock-pedigree-generation stock-pedigree-parents" aria-labelledby="stock-pedigree-parents-title">' +
            '<h3 id="stock-pedigree-parents-title">Parents <span>' + parentCount.toLocaleString() + '</span></h3>' +
            '<div class="stock-pedigree-node-grid">' + parentNodes + '</div>' +
          '</section>' +
          '<div class="stock-pedigree-flow" aria-hidden="true"><span>contributes to</span><b>&darr;</b></div>' +
          '<div class="stock-pedigree-selected">' +
            '<span>Selected stock</span><a href="' + R.escape(selectedHref) + '">' + R.escape(selectedName) + '</a>' +
          '</div>' +
          '<div class="stock-pedigree-flow" aria-hidden="true"><span>recorded parent of</span><b>&darr;</b></div>' +
          '<section class="stock-pedigree-generation stock-pedigree-progeny" aria-labelledby="stock-pedigree-progeny-title">' +
            '<h3 id="stock-pedigree-progeny-title">Direct progeny <span>' + progenyCount.toLocaleString() + '</span></h3>' +
            '<div class="stock-pedigree-node-grid">' + progenyNodes + '</div>' + graphNote +
          '</section>' +
        '</div>' +
      '</div>' +
      '<div id="stock-pedigree-table-panel" data-pedigree-panel="table" hidden>' +
        '<div class="stock-pedigree-table-tools">' +
          '<label for="stock-pedigree-search">Search relationships</label>' +
          '<input id="stock-pedigree-search" type="search" placeholder="Filter by stock name or relationship" ' +
            'autocomplete="off">' +
          '<span id="stock-pedigree-result-count" role="status">' + relationshipCount.toLocaleString() +
            (relationshipCount === 1 ? ' relationship' : ' relationships') + '</span>' +
        '</div>' +
        '<div class="mgdb-table-scroll" tabindex="0" role="region" aria-label="Pedigree relationships table">' +
          '<table class="mgdb-table stock-pedigree-table" id="stock-pedigree-table">' +
            '<caption class="mgdb-visually-hidden">All recorded parent and progeny relationships for ' +
              R.escape(selectedName) + '</caption>' +
            '<thead><tr>' +
              '<th scope="col" data-sort="text"><button type="button">Relationship</button></th>' +
              '<th scope="col" data-sort="text"><button type="button">Stock</button></th>' +
              '<th scope="col" class="mgdb-numeric" data-sort="number"><button type="button">Contribution</button></th>' +
            '</tr></thead><tbody>' + rows + '</tbody>' +
          '</table>' +
        '</div>' +
        '<p class="stock-pedigree-table-note">Contribution is shown only where it is currently recorded in MaizeGDB.</p>' +
      '</div>' +
    '</div>';

    els.pedigreeBody.innerHTML = html;
    initPedigreeEvents(parents, progeny, selectedName);
    return true;
  }

  function initPedigreeEvents(parents, progeny, selectedName) {
    var buttons = els.pedigreeBody.querySelectorAll('[data-pedigree-view]');
    var panels = els.pedigreeBody.querySelectorAll('[data-pedigree-panel]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        var view = button.getAttribute('data-pedigree-view');
        Array.prototype.forEach.call(buttons, function (candidate) {
          var active = candidate === button;
          candidate.classList.toggle('is-active', active);
          candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        Array.prototype.forEach.call(panels, function (panel) {
          panel.hidden = panel.getAttribute('data-pedigree-panel') !== view;
        });
        MGDB.announce('Showing pedigree as ' + view + '.');
      });
    });

    var table = R.byId('stock-pedigree-table');
    if (table) { MGDB.sortTable(table); }

    var search = R.byId('stock-pedigree-search');
    var resultCount = R.byId('stock-pedigree-result-count');
    if (search && table && table.tBodies.length) {
      search.addEventListener('input', MGDB.debounce(function () {
        var query = search.value.toLowerCase().replace(/\s+/g, ' ').trim();
        var shown = 0;
        Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
          var match = !query || (row.getAttribute('data-search') || '').indexOf(query) !== -1;
          row.hidden = !match;
          if (match) { shown += 1; }
        });
        if (resultCount) {
          resultCount.textContent = shown.toLocaleString() + (shown === 1 ? ' relationship' : ' relationships');
        }
      }, 120));
    }

    var download = els.pedigreeBody.querySelector('[data-pedigree-download]');
    if (download) {
      download.addEventListener('click', function () {
        function cell(value) { return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"'; }
        var csvRows = [['Relationship', 'Stock', 'Contribution percent', 'Stock URL']];
        parents.forEach(function (item) {
          csvRows.push(['Parent', item.name, item.contribution_percent, item.html || '']);
        });
        progeny.forEach(function (item) {
          csvRows.push(['Progeny', item.name, item.contribution_percent, item.html || '']);
        });
        var csv = csvRows.map(function (row) { return row.map(cell).join(','); }).join('\r\n') + '\r\n';
        var blob = new window.Blob([csv], { type: 'text/csv;charset=utf-8' });
        var url = window.URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = selectedName.replace(/[^A-Za-z0-9._-]+/g, '-') + '-pedigree.csv';
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 0);
      });
    }
  }

  /* ------------------------------------------------------------------------
     TYPSimSelector similarity

     Also this page's own: identity-by-state scores against the USDA Ames
     Diversity Panel, with a bar per score.
     ------------------------------------------------------------------------ */

  function renderTypsim(typsim) {
    if (!typsim || !typsim.available) { return false; }

    var matches = typsim.top_matches || [];
    var tableRows = matches.map(function (m) {
      var percent = m.similarity_percent.toFixed(2);
      var selfClass = m.is_self ? ' class="is-self"' : '';
      var selfBadge = m.is_self ? ' <span class="mgdb-pill mgdb-pill-ok">Self</span>' : '';
      var lineLink = '<a href="' + R.escape(m.html) + '"><strong>' + R.escape(m.line) + '</strong></a>' +
                     (m.accession ? ' <small class="mgdb-muted">(' + R.escape(m.accession) + ')</small>' : '') +
                     selfBadge;

      return '<tr' + selfClass + '>' +
        '<td>#' + m.rank + '</td>' +
        '<td>' + lineLink + '</td>' +
        '<td class="stock-typsim-bar-cell">' +
          '<div class="stock-typsim-bar"><i style="width:' + percent + '%"></i></div>' +
        '</td>' +
        '<td><strong>' + percent + '%</strong></td>' +
        '<td><small class="mgdb-muted">' + (m.divergence * 100).toFixed(2) + '%</small></td>' +
        '<td><a href="' + R.escape(m.html) + '">Stock record &rarr;</a></td>' +
      '</tr>';
    }).join('');

    var tableHtml = '<div class="stock-typsim-table-wrap">' +
      '<table class="stock-typsim-table">' +
        '<thead><tr>' +
          '<th>Rank</th>' +
          '<th>Accession / Line</th>' +
          '<th>IBS Similarity</th>' +
          '<th>Score</th>' +
          '<th>Divergence</th>' +
          '<th>Action</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
      '</table>' +
    '</div>';

    var html = '<div class="stock-typsim-card">' +
      '<div class="stock-typsim-header">' +
        '<div>' +
          '<h3>Ames Diversity Panel Genetic Similarity</h3>' +
          '<p>Identity-by-state &#40;IBS&#41; genetic relationships scored across ' +
          typsim.total_compared.toLocaleString() + ' panel accessions. Showing top closest relatives.</p>' +
        '</div>' +
        '<a class="mgdb-button mgdb-button-primary" href="' + R.escape(typsim.tool_url) + '" target="_blank" rel="noopener">' +
          'Open in TYPSimSelector &nearr;' +
        '</a>' +
      '</div>' +
      tableHtml +
    '</div>';

    els.typsimBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     GRIN, fetched on its own

     A live BrAPI call to npgsWeb.ars-grin.gov. It is the slowest part of the
     record by a factor of four and nothing else waits on it.
     ------------------------------------------------------------------------ */

  function renderGrin(grin) {
    if (!grin || !grin.accession) { return false; }
    var out = els.grinBody;
    var details = grin.details;

    if (!details) {
      out.innerHTML = R.facts([['Accession', R.escape(grin.accession)]]) +
        '<p class="mgdb-rec-empty">The GRIN service did not return details for this accession ' +
        'just now. The accession number above is from MaizeGDB and is unaffected.</p>';
      return true;
    }

    out.innerHTML = R.facts([
      ['Accession', R.escape(details.accession_number || grin.accession)],
      ['Improvement status', details.improvement ? R.escape(details.improvement) : ''],
      ['Reproductive uniformity', details.reproductive_uniformity ? R.escape(details.reproductive_uniformity) : ''],
      ['Acquired', details.acquired ? R.escape(details.acquired) : ''],
      ['Seed source', details.seed_source ? R.escape(details.seed_source) : ''],
      ['Collection', details.collection ? R.escape(details.collection) : ''],
      ['Availability', details.is_available === true ? 'Available'
        : (details.is_available === false ? 'Not currently available' : '')],
      ['GRIN record', details.grin_url ? R.link(details.grin_url, 'Open at GRIN-Global', true) : '']
    ]);

    R.notes(out, 'GRIN pedigree', details.pedigree ? [{ text: details.pedigree }] : []);
    R.notes(out, 'GRIN description', details.note ? [{ text: details.note }] : []);
    return true;
  }

  function loadGrin() {
    var requested = R.byId('stock-record-top').getAttribute('data-requested-id');
    return MGDB.request('/api/v1/records/stock/' + encodeURIComponent(requested) + '?fields=grin',
      { key: 'stock-record-grin' })
      .then(function (response) {
        var grin = ((response.data || {}).sections || {}).grin;
        if (renderGrin(grin)) {
          R.show(R.byId('stock-record-grin'), true);
          var tab = els.tabs.querySelector('a[href="#stock-record-grin"]');
          if (tab) { tab.hidden = false; }
        }
        /* The ordering panel reads GRIN when the Stock Center is not the
           provider, so it is settled once and only once GRIN has answered. */
        if (payload && payload.data) {
          payload.data.sections.grin = grin;
          renderCart(payload.data.sections);
        }
      })
      .catch(function () { /* GRIN is optional; the rest of the record stands */ });
  }

  /* ------------------------------------------------------------------------
     Metrics and figures
     ------------------------------------------------------------------------ */

  function renderMetrics(counts, sections) {
    var related = sections.related || {};
    var variations = (counts.genotypic_variations || 0) + (counts.karyotypic_variations || 0);
    var images = (counts.images || 0) + (counts.variation_images || 0);

    R.metrics(els.metricsBody, [
      ['Variations', 'Genotype', variations, 'Alleles and karyotypic variations this stock carries.', 'green'],
      ['Phenotypes', 'Traits', counts.phenotypes, 'Traits observed in this stock.', 'amber'],
      ['Direct progeny', 'Pedigree', counts.progeny, 'Stocks recorded as having this one as a parent.', 'blue'],
      ['References', 'Literature', counts.references, 'Curated publications associated with this stock.', 'burgundy']
    ]);

    var series = [
      ['Genotypic variations', counts.genotypic_variations],
      ['Karyotypic variations', counts.karyotypic_variations],
      ['Phenotypes', counts.phenotypes], ['Direct progeny', counts.progeny],
      ['Parents', counts.parents], ['Trait values', counts.trait_values],
      ['Images', images], ['References', counts.references],
      ['Collections', counts.relations], ['External entries', counts.offsite],
      ['Synonyms', counts.synonyms]
    ];
    var height = R.connectionsHeight(series);

    /* When a stock was worked on. Sixty-three papers spread over four decades
       is a different record from sixty-three in one year, and the year axis is
       the only place that shows it. */
    if (R.yearsChart('stock-record-years-chart', 'stock-record-years-caption',
                     'stock-record-years-figure', sections.references, height)) {
      R.watchChartWidth('stock-record-years-chart');
    }

    R.connectionsChart('stock-record-connections-chart', 'stock-record-connections-caption',
                       'stock-record-connections-figure', series, height);
    void related;
    return true;
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  var TAB_COUNTS = {
    'stock-record-pedigree': ['parents', 'progeny'],
    'stock-record-related': ['genotypic_variations', 'karyotypic_variations', 'phenotypes', 'relations'],
    'stock-record-images': ['images', 'variation_images'],
    'stock-record-references': ['references'],
    'stock-record-offsite': ['offsite']
  };

  var LABELS = {
    'stock-record-overview': 'Overview',
    'stock-record-pedigree': 'Pedigree and relationships',
    'stock-record-related': 'Related records',
    'stock-record-images': 'Images',
    'stock-record-typsim': 'TYPSimSelector similarity',
    'stock-record-references': 'References',
    'stock-record-offsite': 'Offsite resources',
    'stock-record-grin': 'GRIN accession details',
    'stock-record-metrics': 'Metrics',
    'stock-record-resources': 'Related resources',
    'stock-record-api': 'API'
  };

  /* Columns shared by the four chip-style lists in Related records. */
  function nameColumns(label, qualifierKey, qualifierLabel) {
    var columns = [
      { key: 'name', label: label, tile: true,
        html: function (i) { return i.html ? R.link(i.html, i.name) : R.escape(i.name); } }
    ];
    if (qualifierKey) { columns.push({ key: qualifierKey, label: qualifierLabel }); }
    columns.push(R.urlColumn(function (i) { return i.html; }));
    return columns;
  }

  function renderRelated(related) {
    if (!related) { return false; }
    var out = els.relatedBody;
    out.innerHTML = '';
    var rendered = false;

    rendered = R.collection(out, {
      title: 'Genotypic variations',
      items: related.genotypic_variations,
      filename: 'stock-genotypic-variations.tsv',
      pageSize: 25,
      columns: nameColumns('Variation')
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Karyotypic variations',
      items: related.karyotypic_variations,
      filename: 'stock-karyotypic-variations.tsv',
      pageSize: 25,
      columns: nameColumns('Variation')
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Phenotypes',
      items: related.phenotypes,
      filename: 'stock-phenotypes.tsv',
      pageSize: 25,
      columns: nameColumns('Phenotype', 'attributable_to', 'Attributable to')
    }) || rendered;

    rendered = R.collection(out, {
      title: 'Part of',
      items: related.relations,
      filename: 'stock-collections.tsv',
      columns: nameColumns('Collection', 'relationship', 'Relationship')
    }) || rendered;

    var traits = related.trait_values || {};
    if (traits.count > 0 && traits.html) {
      out.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head"><h3>Trait values' +
        '<span class="mgdb-rec-block-count">' + R.number(traits.count) + '</span></h3></div>' +
        '<p class="mgdb-rec-block-status">Measured trait values are held in the trait search rather ' +
        'than on this record.</p><div class="mgdb-rec-linkrow">' +
        '<a class="mgdb-button mgdb-button-secondary" href="' + R.escape(traits.html) +
        '">Search trait values</a></div></div>');
      rendered = true;
    }

    return rendered;
  }

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    stockName = (data.attributes || {}).name || ('Stock ' + data.id);

    R.show(els.loading, false);
    R.show(els.error, false);

    renderHeader(data);
    renderCart(sections);

    var rendered = [];
    if (renderOverview(sections.overview)) { rendered.push('stock-record-overview'); }
    if (renderPedigree(sections.pedigree, data, counts)) { rendered.push('stock-record-pedigree'); }
    if (renderRelated(sections.related)) { rendered.push('stock-record-related'); }

    /* The gallery the record shell settled on came from this page, so the
       cards, the three buttons and the lightbox are the ones that were here;
       what is new is the table view, the filter and the TSV beside them. */
    var related = sections.related || {};
    var images = (related.images || []).concat(related.variation_images || []);
    if (R.images(els.imagesBody, images.map(function (image) {
      var isVar = (image.subject === 'variation' && image.variation && image.variation.name);
      return {
        url: image.url,
        caption: image.caption || '',
        title: isVar ? image.variation.name : stockName,
        category: isVar ? 'Variation / Mutant' : 'Stock & Germplasm',
        record: isVar
          ? (image.variation.html || ('/data_center/variation?id=' + encodeURIComponent(image.variation.id)))
          : ('/data_center/stock?id=' + encodeURIComponent(data.id))
      };
    }), 'stock-record-image-dialog', {
      title: 'Images of this stock and of the variations it carries',
      filename: 'stock-images.tsv'
    })) { rendered.push('stock-record-images'); }

    if (renderTypsim(sections.typsim)) { rendered.push('stock-record-typsim'); }

    if (R.references(els.referencesBody, sections.references, els.referencesSection, 'stock-ref')) {
      rendered.push('stock-record-references');
    }

    if (R.collection(els.offsiteBody, {
      title: 'External database entries',
      items: sections.offsite,
      filename: 'stock-offsite-resources.tsv',
      columns: [
        { key: 'accession', label: 'Accession', tile: true,
          html: function (x) { return (x.url ? R.link(x.url, x.accession, true) : R.escape(x.accession)) +
                 (x.obsolete ? ' <span class="mgdb-pill mgdb-pill-warn">Obsolete</span>' : ''); } },
        { key: 'database', label: 'Database', get: function (x) { return x.database ? x.database.name : ''; } },
        { key: 'url', label: 'URL', sort: false, get: function (x) { return x.url || ''; },
          html: function (x) { return x.url ? R.link(x.url, x.url, true) : '—'; } }
      ]
    })) { rendered.push('stock-record-offsite'); }

    rendered.forEach(function (id) { R.show(R.byId(id), true); });

    // Revealed before the charts are drawn: Plotly sizes a figure to its
    // container, and a hidden container has no width.
    R.show(R.byId('stock-record-metrics'), true);
    if (renderMetrics(counts, sections)) { rendered.push('stock-record-metrics'); }

    /* GRIN has not answered yet, so its tab is built now and unhidden when it
       does. Putting it in the order here keeps the tab bar from reflowing. */
    R.tabs({
      el: els.tabs,
      order: rendered.concat(['stock-record-grin', 'stock-record-resources', 'stock-record-api']),
      labels: LABELS, counts: counts, tabCounts: TAB_COUNTS
    });
    var grinTab = els.tabs.querySelector('a[href="#stock-record-grin"]');
    if (grinTab) { grinTab.hidden = true; }

    R.notice(els.notice, meta, counts);
    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');

    loadGrin();
  }

  function load() {
    var main = R.byId('stock-record-top');
    if (!main) { return; }
    var requested = main.getAttribute('data-requested-id') || main.getAttribute('data-stock-id');
    if (!requested) { return; }

    R.show(els.error, false);
    R.show(els.loading, true);

    /* Everything except GRIN, which follows on its own. */
    MGDB.request('/api/v1/records/stock/' + encodeURIComponent(requested) +
                 '?fields=overview,pedigree,related,typsim,references,offsite',
                 { key: 'stock-record' })
      .then(function (response) {
        if (!response || !response.data) { throw new Error('unexpected payload'); }
        render(response);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        R.show(els.loading, false);
        R.show(els.error, true);
      });
  }

  function init() {
    els = {
      synonyms: R.byId('stock-record-synonyms'),
      facts: R.byId('stock-record-facts'),
      tabs: R.byId('stock-record-tabs'),
      loading: R.byId('stock-record-loading'),
      error: R.byId('stock-record-error'),
      retry: R.byId('stock-record-retry'),
      notice: R.byId('stock-record-notice'),
      overviewBody: R.byId('stock-record-overview-body'),
      pedigreeBody: R.byId('stock-record-pedigree-body'),
      relatedBody: R.byId('stock-record-related-body'),
      imagesBody: R.byId('stock-record-images-body'),
      typsimBody: R.byId('stock-record-typsim-body'),
      referencesBody: R.byId('stock-record-references-body'),
      referencesSection: R.byId('stock-record-references'),
      offsiteBody: R.byId('stock-record-offsite-body'),
      grinBody: R.byId('stock-record-grin-body'),
      metricsBody: R.byId('stock-record-metrics-body')
    };
    if (els.retry) { els.retry.addEventListener('click', load); }
    R.apiCard('stock-copy-json-btn', 'stock-record-api-link', function () { return payload; });
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
