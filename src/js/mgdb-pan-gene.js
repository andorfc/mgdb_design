/* ==========================================================================
   Pan-Gene Center search — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-pan-gene.css and templates/static/mgdb_pan_gene.bau.
   Depends only on MGDB (js/mgdb-modern.js); the legacy jQuery in the shell is
   deliberately not used.

   Progressive enhancement: with this file missing the page still renders its
   server-side content — the summary figures, the annotation table, the
   downloads form, and the definitions. Only the search results, which were
   always fetched over the network, are lost.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/pan_gene/pan_gene_search_api.php';
  /* The endpoint caps page_size at 100, so "All results" asks for that. */
  var MAX_PAGE = 100;
  var VALUE_LIMIT = 3;      // list values shown per cell before "+n more"

  function byId(id) { return document.getElementById(id); }

  var els = {};

  /* State is the whole query. It is mirrored into the address bar so a result
     set can be linked to and the back button behaves. */
  var state = {
    mode: 'simple',
    term: '',
    sort: 'members',
    sortKey: 'members',
    sortDir: 'desc',
    page: 1,
    pageSize: 25,
    filter: '',
    view: 'table',
    searched: false,
    lastPayload: null,
    filters: {}
  };

  var ADVANCED_FIELDS = [
    /* Not 'pan-gene-analysis': that is the id of the section this page's
       tab bar links to, and two elements sharing an id sent the tab to the
       select instead of the section. */
    { key: 'analysis', id: 'pan-gene-analysis-filter', type: 'value' },
    { key: 'gene_models', id: 'pan-gene-gene-models', type: 'value' },
    { key: 'proteins', id: 'pan-gene-proteins', type: 'value' },
    { key: 'min', id: 'pan-gene-min', type: 'value' },
    { key: 'max', id: 'pan-gene-max', type: 'value' },
    { key: 'min_annots', id: 'pan-gene-min-annots', type: 'value' },
    { key: 'max_annots', id: 'pan-gene-max-annots', type: 'value' },
    { key: 'locus', id: 'pan-gene-locus', type: 'flag' },
    { key: 'trait', id: 'pan-gene-trait', type: 'flag' },
    { key: 'protein_any', id: 'pan-gene-protein-any', type: 'flag' },
    { key: 'appear', id: 'pan-gene-appear', type: 'multi' },
    { key: 'not_appear', id: 'pan-gene-not-appear', type: 'multi' }
  ];

  /* ------------------------------------------------------------------------
     Section tabs
     ------------------------------------------------------------------------ */

  /* Sticky section tabs, driven by scroll, IntersectionObserver and resize
     together: no single trigger fires everywhere, and the results section
     appears and disappears under the bar as searches run. */
  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

    var heldUntilScroll = null;
    var heldAtY = 0;

    function mark(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(barHeight + 8, margin + 4);
    }

    function update() {
      if (heldUntilScroll) {
        if (Math.abs(window.scrollY - heldAtY) < 4) { return; }
        heldUntilScroll = null;
      }
      var line = triggerLine();
      var current = pairs[0];
      pairs.forEach(function (pair) {
        if (pair.section.hasAttribute('hidden')) { return; }
        if (pair.section.getBoundingClientRect().top <= line) { current = pair; }
      });
      if ((window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }
      if (current) { mark(current.section); }
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        mark(pair.section);
        heldUntilScroll = pair.section;
        heldAtY = window.scrollY;
      });
    });

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { update(); },
        { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }

    var results = byId('pan-gene-results');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ------------------------------------------------------------------------
     Size distribution figure
     ------------------------------------------------------------------------ */

  function numbersFrom(element, attribute) {
    var raw = element.getAttribute(attribute) || '';
    if (!raw) { return []; }
    return raw.split(',').map(function (value) {
      return parseInt(value, 10);
    }).filter(function (value) { return !isNaN(value); });
  }

  function buildDistribution() {
    var target = byId('pan-gene-distribution-chart');
    if (!target) { return; }

    var sizes = numbersFrom(target, 'data-sizes');
    var counts = numbersFrom(target, 'data-counts');
    if (!sizes.length || sizes.length !== counts.length) { return; }

    // The accessible alternative to the canvas: every plotted value, in a
    // table that is in the DOM whether or not Plotly ever loads.
    var rows = byId('pan-gene-distribution-rows');
    if (rows) {
      var html = '';
      for (var i = 0; i < sizes.length; i++) {
        html += '<tr><td class="mgdb-numeric">' + sizes[i].toLocaleString() +
                '</td><td class="mgdb-numeric">' + counts[i].toLocaleString() + '</td></tr>';
      }
      rows.innerHTML = html;
    }

    MGDB.chart({
      target: target,
      traces: [{
        type: 'bar',
        x: sizes,
        y: counts,
        marker: { color: MGDB.CHART_COLORS[1] },
        hovertemplate: '%{x} members<br>%{y:,} pan-genes<extra></extra>'
      }],
      layout: {
        xaxis: { title: { text: 'Members in the pan-gene' }, dtick: 10 },
        yaxis: { title: { text: 'Pan-genes' } },
        bargap: 0.05
      }
    });
  }

  /* ------------------------------------------------------------------------
     Query building
     ------------------------------------------------------------------------ */

  function readAdvancedForm() {
    var filters = {};
    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) { return; }
      if (field.type === 'flag') {
        if (el.checked) { filters[field.key] = '1'; }
      } else if (field.type === 'multi') {
        var selected = Array.prototype.filter.call(el.options, function (option) {
          return option.selected;
        }).map(function (option) { return option.value; });
        if (selected.length) { filters[field.key] = selected.join(','); }
      } else if (el.value.trim() !== '') {
        filters[field.key] = el.value.trim();
      }
    });
    return filters;
  }

  function writeAdvancedForm(filters) {
    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) { return; }
      var value = filters[field.key];
      if (field.type === 'flag') {
        el.checked = (value === '1');
      } else if (field.type === 'multi') {
        var wanted = value ? value.split(',') : [];
        Array.prototype.forEach.call(el.options, function (option) {
          option.selected = wanted.indexOf(option.value) !== -1;
        });
      } else {
        el.value = value || '';
      }
    });
  }

  function clearAdvancedForm() {
    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) { return; }
      if (field.type === 'flag') { el.checked = false; }
      else if (field.type === 'multi') {
        Array.prototype.forEach.call(el.options, function (option) { option.selected = false; });
      } else { el.value = ''; }
    });
  }

  function buildQuery() {
    var params = new window.URLSearchParams();
    params.set('mode', state.mode);
    params.set('page', String(state.page));
    params.set('page_size', String(state.pageSize === 'all' ? MAX_PAGE : state.pageSize));
    params.set('sort', state.sort);
    if (state.mode === 'simple') {
      params.set('term', state.term);
    } else {
      Object.keys(state.filters).forEach(function (key) {
        params.set(key, state.filters[key]);
      });
    }
    return params;
  }

  function syncUrl() {
    if (!window.history || !window.history.replaceState) { return; }
    var params = new window.URLSearchParams();
    if (state.mode === 'simple') {
      if (state.term) { params.set('term', state.term); }
    } else {
      params.set('mode', 'advanced');
      Object.keys(state.filters).forEach(function (key) {
        params.set(key, state.filters[key]);
      });
    }
    if (state.sort !== 'members') { params.set('sort', state.sort); }
    if (state.page > 1) { params.set('page', String(state.page)); }
    if (state.view && state.view !== 'table') { params.set('view', state.view); }
    var query = params.toString();
    window.history.replaceState(null, '',
      window.location.pathname + (query ? '?' + query : '') + window.location.hash);
  }

  function readUrl() {
    if (!window.URLSearchParams) { return false; }
    var params = new window.URLSearchParams(window.location.search);

    var sort = params.get('sort');
    if (sort) {
      state.sort = sort;
      if (els.sort) { els.sort.value = sort; }
    }
    var page = parseInt(params.get('page'), 10);
    if (!isNaN(page) && page > 0) { state.page = page; }

    var v = params.get('view');
    if (v === 'card' || v === 'table') { state.view = v; }

    if (params.get('mode') === 'advanced') {
      state.mode = 'advanced';
      var filters = {};
      ADVANCED_FIELDS.forEach(function (field) {
        var value = params.get(field.key);
        if (value !== null && value !== '') { filters[field.key] = value; }
      });
      state.filters = filters;
      writeAdvancedForm(filters);
      if (els.advanced && Object.keys(filters).length) { els.advanced.open = true; }
      syncAdvancedBadge();
      return Object.keys(filters).length > 0;
    }

    // pan_gene_term is the parameter the legacy search form used; honour it so
    // existing links keep working.
    var term = params.get('term') || params.get('q') || params.get('pan_gene_term') || '';
    if (term) {
      state.mode = 'simple';
      state.term = term;
      if (els.term) {
        els.term.value = term;
        if (els.clear) { els.clear.hidden = false; }
      }
      return true;
    }
    return false;
  }

  /* ------------------------------------------------------------------------
     Rendering
     ------------------------------------------------------------------------ */

  function escape(value) { return MGDB.escapeHtml(value); }

  function show(el, visible) { if (el) { el.hidden = !visible; } }

  function valueList(values, className) {
    if (!values || !values.length) {
      return '<span class="pan-gene-none">&mdash;</span>';
    }
    var shown = values.slice(0, VALUE_LIMIT).map(escape).join(', ');
    var html = '<span class="pan-gene-values ' + (className || '') + '">' + shown;
    if (values.length > VALUE_LIMIT) {
      html += '<span class="pan-gene-values-more">and ' +
              (values.length - VALUE_LIMIT) + ' more</span>';
    }
    return html + '</span>';
  }

  function locusCell(row) {
    if (!row.loci || !row.loci.length) {
      return '<span class="pan-gene-none">&mdash;</span>';
    }

    var links = row.loci.map(function (locus) {
      return '<a href="/gene_center/gene/' + encodeURIComponent(locus) + '">' +
             escape(locus) + '</a>';
    }).join(', ');

    var html = '<span class="pan-gene-values">' + links + '</span>';

    if (row.locus_evidence && row.locus_evidence.length) {
      html += '<details class="pan-gene-evidence"><summary>Why this locus</summary><ul>';
      row.locus_evidence.forEach(function (item) {
        html += '<li>' + escape(item.locus) + ' via <a href="/gene_center/gene/' +
                encodeURIComponent(item.gene_model) + '">' + escape(item.gene_model) +
                '</a>, per ' + escape(item.source) + '</li>';
      });
      html += '</ul></details>';
    }
    return html;
  }

  function coverageCell(row) {
    var total = row.annotation_total || 0;
    var percent = total > 0 ? Math.round(100 * row.annotation_count / total) : 0;
    return '<span class="pan-gene-coverage">' + row.annotation_count +
           (total ? ' / ' + total : '') + '</span>' +
           '<span class="pan-gene-coverage-bar" aria-hidden="true"><span style="width:' +
           percent + '%"></span></span>';
  }

  function matchedCell(row) {
    if (!row.matched_as || !row.matched_as.length) {
      return '<span class="pan-gene-none">&mdash;</span>';
    }
    return row.matched_as.map(function (kind) {
      return '<span class="mgdb-pill mgdb-pill-info pan-gene-matched">' + escape(kind) + '</span>';
    }).join('');
  }

  function recordUrl(row) {
    return '/pan_gene_center/pan_gene/' + encodeURIComponent(row.exemplar);
  }

  function renderRows(results) {
    var html = '';
    results.forEach(function (row) {
      html += '<tr>' +
        '<th scope="row"><a class="pan-gene-id" href="' + recordUrl(row) + '">' +
          escape(row.exemplar) + '</a></th>' +
        '<td>' + locusCell(row) + '</td>' +
        '<td>' + valueList(row.proteins) + '</td>' +
        '<td>' + valueList(row.traits) + '</td>' +
        '<td class="mgdb-numeric" data-value="' + row.member_count + '">' +
          row.member_count.toLocaleString() + '</td>' +
        '<td class="mgdb-numeric" data-value="' + row.annotation_count + '">' +
          coverageCell(row) + '</td>' +
        '<td>' + matchedCell(row) + '</td>' +
        '</tr>';
    });
    els.rows.innerHTML = html;
  }

  function renderCards(results) {
    if (!els.cardsView) { return; }
    var html = '';
    results.forEach(function (row) {
      var exemplar = row.exemplar || '';
      var url = recordUrl(row);
      var panGeneName = row.pan_gene_name || '';

      var analysisBadge = row.analysis
        ? '<span class="pan-gene-analysis-badge">' + escape(row.analysis) + '</span>'
        : '';
      var memberBadge = '<span class="pan-gene-member-badge">' + (row.member_count || 0).toLocaleString() + ' members</span>';
      var annotBadge = '<span class="pan-gene-annot-badge">' + (row.annotation_count || 0) + (row.annotation_total ? ' / ' + row.annotation_total : '') + ' annots</span>';
      var matchedBadges = matchedCell(row);

      var subname = panGeneName
        ? '<div class="pan-gene-card-subname">Pan-gene: ' + escape(panGeneName) + '</div>'
        : '';

      var lociHtml = '<p><strong>Loci:</strong> ' + locusCell(row) + '</p>';
      var proteinsHtml = '<p><strong>Proteins:</strong> ' + valueList(row.proteins) + '</p>';
      var traitsHtml = '<p><strong>Traits:</strong> ' + valueList(row.traits) + '</p>';

      html += '<article class="pan-gene-result-card">' +
        '<div>' +
          '<div class="pan-gene-card-meta">' +
            analysisBadge + memberBadge + annotBadge + matchedBadges +
          '</div>' +
          '<h3><a href="' + url + '">' + escape(exemplar) + '</a></h3>' +
          subname +
          '<div class="pan-gene-card-details">' +
            lociHtml + proteinsHtml + traitsHtml +
          '</div>' +
        '</div>' +
        '<div class="pan-gene-card-links">' +
          '<a href="' + url + '">View Record &rarr;</a>' +
          '<button class="mgdb-button mgdb-button-quiet pan-gene-copy-btn" type="button" data-copy-value="' + escape(exemplar) + '">Copy Exemplar</button>' +
          (panGeneName ? '<button class="mgdb-button mgdb-button-quiet pan-gene-copy-btn" type="button" data-copy-value="' + escape(panGeneName) + '">Copy Pan-Gene ID</button>' : '') +
        '</div>' +
      '</article>';
    });
    els.cardsView.innerHTML = html;
  }

  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.pan-gene-copy-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy-value');
        if (!val) return;
        var original = btn.textContent;
        function finish(ok) {
          btn.textContent = ok ? 'Copied!' : 'Press Cmd+C';
          window.setTimeout(function () { btn.textContent = original; }, 1600);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(function () { finish(true); }).catch(function () { finish(false); });
        } else {
          finish(false);
        }
      });
    });
  }

  var STORAGE_VIEW_KEY = 'mgdb_pan_gene_view';

  function updateViewDisplay() {
    var isCard = state.view === 'card';
    if (els.cardsView) els.cardsView.hidden = !isCard;
    if (els.tableWrap) els.tableWrap.hidden = isCard;

    var btnCards = byId('pan-gene-view-cards');
    var btnTable = byId('pan-gene-view-table');

    if (btnCards) {
      btnCards.classList.toggle('is-active', isCard);
      btnCards.setAttribute('aria-pressed', isCard ? 'true' : 'false');
    }
    if (btnTable) {
      btnTable.classList.toggle('is-active', !isCard);
      btnTable.setAttribute('aria-pressed', !isCard ? 'true' : 'false');
    }
  }

  function initViewToggle() {
    var buttons = document.querySelectorAll('.pan-gene-results-view button[data-view]');
    if (!buttons.length) return;

    var savedView = 'table';
    try { savedView = localStorage.getItem(STORAGE_VIEW_KEY) || 'table'; } catch (e) {}
    if (state.view) savedView = state.view;

    function applyView(view) {
      state.view = view;
      try { localStorage.setItem(STORAGE_VIEW_KEY, view); } catch (e) {}
      updateViewDisplay();
      syncUrl();
    }

    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        applyView(btn.getAttribute('data-view'));
      });
    });

    applyView(savedView);
  }

  function getPanGeneHeaderAriaSort(key) {
    if (state.sortKey === key) {
      return state.sortDir === 'asc' ? 'ascending' : 'descending';
    }
    return 'none';
  }

  function sortPanGeneResults(results) {
    if (!results || !results.length) return [];
    var key = state.sortKey || 'members';
    var dir = state.sortDir === 'desc' ? -1 : 1;

    return results.slice().sort(function (a, b) {
      if (key === 'exemplar') {
        var aEx = (a.exemplar || '').toLowerCase();
        var bEx = (b.exemplar || '').toLowerCase();
        return aEx.localeCompare(bEx, undefined, { numeric: true }) * dir;
      } else if (key === 'locus') {
        var aLoc = (a.loci && a.loci.length ? a.loci.join(', ') : '').toLowerCase();
        var bLoc = (b.loci && b.loci.length ? b.loci.join(', ') : '').toLowerCase();
        if (aLoc && !bLoc) return -1 * dir;
        if (!aLoc && bLoc) return 1 * dir;
        var cmpLoc = aLoc.localeCompare(bLoc, undefined, { numeric: true });
        if (cmpLoc !== 0) return cmpLoc * dir;
      } else if (key === 'protein') {
        var aProt = (a.proteins && a.proteins.length ? a.proteins.join(', ') : '').toLowerCase();
        var bProt = (b.proteins && b.proteins.length ? b.proteins.join(', ') : '').toLowerCase();
        if (aProt && !bProt) return -1 * dir;
        if (!aProt && bProt) return 1 * dir;
        var cmpProt = aProt.localeCompare(bProt, undefined, { numeric: true });
        if (cmpProt !== 0) return cmpProt * dir;
      } else if (key === 'trait') {
        var aTrait = (a.traits && a.traits.length ? a.traits.join(', ') : '').toLowerCase();
        var bTrait = (b.traits && b.traits.length ? b.traits.join(', ') : '').toLowerCase();
        if (aTrait && !bTrait) return -1 * dir;
        if (!aTrait && bTrait) return 1 * dir;
        var cmpTrait = aTrait.localeCompare(bTrait, undefined, { numeric: true });
        if (cmpTrait !== 0) return cmpTrait * dir;
      } else if (key === 'members') {
        var aMem = parseInt(a.member_count, 10) || 0;
        var bMem = parseInt(b.member_count, 10) || 0;
        if (aMem !== bMem) return (aMem - bMem) * dir;
      } else if (key === 'annotations') {
        var aAnn = parseInt(a.annotation_count, 10) || 0;
        var bAnn = parseInt(b.annotation_count, 10) || 0;
        if (aAnn !== bAnn) return (aAnn - bAnn) * dir;
      } else if (key === 'matched') {
        var aMatch = (a.matched_as && a.matched_as.length ? a.matched_as.join(', ') : '').toLowerCase();
        var bMatch = (b.matched_as && b.matched_as.length ? b.matched_as.join(', ') : '').toLowerCase();
        if (aMatch && !bMatch) return -1 * dir;
        if (!aMatch && bMatch) return 1 * dir;
        var cmpMatch = aMatch.localeCompare(bMatch, undefined, { numeric: true });
        if (cmpMatch !== 0) return cmpMatch * dir;
      }
      var aName = (a.exemplar || '').toLowerCase();
      var bName = (b.exemplar || '').toLowerCase();
      return aName.localeCompare(bName, undefined, { numeric: true }) * dir;
    });
  }

  function updateSortHeaders() {
    var headers = document.querySelectorAll('#pan-gene-table thead th[aria-sort]');
    Array.prototype.forEach.call(headers, function (th) {
      var btn = th.querySelector('button[data-sort-key]');
      if (!btn) return;
      var key = btn.getAttribute('data-sort-key');
      th.setAttribute('aria-sort', getPanGeneHeaderAriaSort(key));
    });
  }

  function initSortButtons() {
    var buttons = document.querySelectorAll('#pan-gene-table thead button[data-sort-key]');
    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-sort-key');
        if (state.sortKey === key) {
          state.sortDir = (state.sortDir === 'asc') ? 'desc' : 'asc';
        } else {
          state.sortKey = key;
          state.sortDir = (key === 'members' || key === 'annotations') ? 'desc' : 'asc';
        }
        updateSortHeaders();
        if (state.lastPayload && state.lastPayload.results) {
          var sorted = sortPanGeneResults(state.lastPayload.results);
          renderRows(sorted);
          renderCards(sorted);
          initCopyButtons();
          applyResultsFilter();
        }
      });
    });
  }

  function updateExportTsv(results) {
    var btn = byId('pan-gene-export-tsv');
    if (!btn) return;
    if (!results || !results.length) {
      btn.href = '#';
      return;
    }
    var headers = ['Exemplar', 'Pan-Gene ID', 'Analysis', 'Loci', 'Proteins', 'Traits', 'Member Count', 'Annotation Count', 'Annotation Total', 'Matched On'];
    var lines = [headers.join('\t')];
    results.forEach(function (row) {
      var line = [
        row.exemplar || '',
        row.pan_gene_name || '',
        row.analysis || '',
        (row.loci || []).join('; '),
        (row.proteins || []).join('; '),
        (row.traits || []).join('; '),
        row.member_count || 0,
        row.annotation_count || 0,
        row.annotation_total || '',
        (row.matched_as || []).join(', ')
      ].map(function (val) {
        return String(val).replace(/[\t\r\n]+/g, ' ');
      });
      lines.push(line.join('\t'));
    });
    var blob = new Blob([lines.join('\n')], { type: 'text/tab-separated-values;charset=utf-8;' });
    btn.href = URL.createObjectURL(blob);
    btn.download = 'pan_genes.tsv';
  }

  function syncAdvancedBadge() {
    var badge = byId('pan-gene-advanced-count');
    if (!badge) return;
    var count = 0;
    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) return;
      if (field.type === 'flag') {
        if (el.checked) count++;
      } else if (field.type === 'multi') {
        var selected = Array.prototype.filter.call(el.options, function (opt) { return opt.selected; });
        if (selected.length > 0) count++;
      } else {
        if (el.value && el.value.trim().length > 0) count++;
      }
    });
    if (count > 0) {
      badge.textContent = count;
      badge.hidden = false;
    } else {
      badge.textContent = '0';
      badge.hidden = true;
    }
  }

  function renderSingle(row) {
    if (!els.single) { return; }
    els.single.innerHTML =
      '<div>' +
        '<span class="mgdb-eyebrow">Exactly one pan-gene matched</span>' +
        '<h3><span class="pan-gene-single-label">Pan-gene exemplar</span>' + escape(row.exemplar) + '</h3>' +
        '<p>' + row.member_count.toLocaleString() + ' member gene models across ' +
          row.annotation_count + ' of ' + row.annotation_total + ' annotations' +
          (row.loci && row.loci.length ? ', associated with ' + escape(row.loci.join(', ')) : '') +
        '.</p>' +
      '</div>' +
      '<a class="mgdb-button mgdb-button-primary" href="' + recordUrl(row) + '">Open the pan-gene record</a>';
    show(els.single, true);
  }

  function renderPagination(summary) {
    if (!els.pagination) { return; }
    if (summary.page_count <= 1) {
      els.pagination.innerHTML = '';
      show(els.pagination, false);
      return;
    }

    var current = summary.page;
    var last = summary.page_count;
    var wanted = [1, last, current, current - 1, current + 1, current - 2, current + 2];
    var pages = wanted.filter(function (page, index, all) {
      return page >= 1 && page <= last && all.indexOf(page) === index;
    }).sort(function (a, b) { return a - b; });

    var html = '';
    if (current > 1) {
      html += '<a href="#pan-gene-results" data-pan-gene-page="' + (current - 1) + '" rel="prev">Previous</a>';
    }
    var previous = 0;
    pages.forEach(function (page) {
      if (previous && page - previous > 1) { html += '<span aria-hidden="true">&hellip;</span>'; }
      if (page === current) {
        html += '<span aria-current="page">' + page + '</span>';
      } else {
        html += '<a href="#pan-gene-results" data-pan-gene-page="' + page + '">' + page + '</a>';
      }
      previous = page;
    });
    if (current < last) {
      html += '<a href="#pan-gene-results" data-pan-gene-page="' + (current + 1) + '" rel="next">Next</a>';
    }
    html += '<span class="mgdb-pagination-status">Page ' + current + ' of ' + last + '</span>';

    els.pagination.innerHTML = html;
    show(els.pagination, true);
  }

  /* The three "found nothing" cases the legacy page distinguished. Each sends
     the reader somewhere different, so they are worth keeping apart. */
  function renderEmpty(payload) {
    var title = 'No pan-genes found';
    var body = 'Check the spelling, or try a different identifier.';
    var actionLabel = '';
    var actionHref = '';

    if (payload.reason === 'no-term') {
      title = 'Enter a search term';
      body = 'Type a locus symbol, gene model, transcript, or protein identifier above.';
    } else if (payload.reason === 'no-filters') {
      title = 'No filters were set';
      body = 'Fill in at least one field in the advanced search before running it.';
    } else if (payload.reason === 'obsolete') {
      title = 'That gene model is obsolete';
      body = 'It was retired from its annotation, so it is not in a pan-gene. Look in the download directory for your assembly of interest to see whether a cross-reference file links preliminary and official gene model IDs.';
      actionLabel = 'Open the download directory';
      actionHref = 'https://download.maizegdb.org';
    } else if (payload.reason === 'singleton') {
      title = 'That gene model is a singleton';
      body = 'It exists, but the analysis did not place it in a pan-gene with any other gene model. The Gene Center holds the rest of what is known about it.';
      actionLabel = 'Open the Gene Center';
      actionHref = '/gene_center/gene';
    } else if (payload.mode === 'simple') {
      body = 'Nothing matched ' + payload.query.term +
             '. If you believe it is a valid gene model or locus, the Gene Center may still have information about it.';
      actionLabel = 'Search the Gene Center';
      actionHref = '/gene_center/gene';
    } else {
      body = 'No pan-gene matched every one of those filters. Try relaxing the narrowest one.';
    }

    els.emptyTitle.textContent = title;
    els.emptyBody.textContent = body;
    if (actionLabel) {
      els.emptyAction.textContent = actionLabel;
      els.emptyAction.href = actionHref;
      show(els.emptyAction, true);
    } else {
      show(els.emptyAction, false);
    }
    show(els.empty, true);
  }

  function renderCriteria(payload) {
    if (!els.criteria) { return; }
    if (!payload.criteria || !payload.criteria.length || payload.mode !== 'advanced') {
      show(els.criteria, false);
      return;
    }
    els.criteria.innerHTML = '<strong>Searching for pan-genes</strong> ' +
      escape(payload.criteria.join(', and ')) + '.';
    show(els.criteria, true);
  }

  function render(payload) {
    var summary = payload.summary || {};
    var total = summary.total || 0;

    show(els.loading, false);
    show(els.error, false);
    show(els.single, false);
    renderCriteria(payload);

    if (total === 0) {
      els.rows.innerHTML = '';
      if (els.cardsView) { els.cardsView.innerHTML = ''; }
      show(els.tableWrap, false);
      show(els.cardsView, false);
      show(els.pagination, false);
      if (els.filterCount) { els.filterCount.textContent = ''; }
      updateExportTsv([]);
      renderEmpty(payload);
      els.status.textContent = payload.reason === 'no-term' || payload.reason === 'no-filters'
        ? '' : 'No matching pan-genes.';
      MGDB.announce('No matching pan-genes.');
      return;
    }

    show(els.empty, false);
    var sorted = sortPanGeneResults(payload.results || []);
    renderRows(sorted);
    renderCards(sorted);
    updateSortHeaders();
    updateViewDisplay();
    updateExportTsv(sorted);
    initCopyButtons();
    renderPagination(summary);

    // Exactly one match: the legacy page jumped straight to the record. Offer
    // the same destination as the primary action instead of navigating away
    // from the search the reader just ran.
    if (total === 1 && payload.mode === 'simple') {
      renderSingle(payload.results[0]);
    }

    var first = (summary.page - 1) * summary.page_size + 1;
    var last = first + payload.results.length - 1;
    var message = total.toLocaleString() + ' matching pan-gene' + (total === 1 ? '' : 's');
    if (summary.page_count > 1) {
      message += ' &mdash; showing ' + first.toLocaleString() + ' to ' + last.toLocaleString();
    }
    if (state.pageSize === 'all' && total > summary.page_size) {
      /* "All results" is capped by the endpoint, and saying so is better than
         a count that quietly stops short. */
      message = 'Showing the first ' + summary.page_size.toLocaleString() + ' of '
        + total.toLocaleString() + ' matching pan-genes, which is as many as the '
        + 'search returns at once';
    }
    els.status.innerHTML = message;
    MGDB.announce(total.toLocaleString() + ' matching pan-genes');

    state.lastPayload = payload;
    /* Re-applied last so paging and a re-sort do not silently drop a filter
       the box still shows. */
    applyResultsFilter();
  }

  /* Narrows the page already rendered. The search pages server side, so this
     filters what is on screen and the status line says so. */
  function applyResultsFilter() {
    var filterTerm = (state.filter || '').toLowerCase().trim();
    var filterCountEl = els.filterCount || byId('pan-gene-filter-count');

    var rows = els.rows ? els.rows.querySelectorAll('tr') : [];
    var cards = els.cardsView ? els.cardsView.querySelectorAll('.pan-gene-result-card') : [];
    var terms = filterTerm.split(/\s+/).filter(Boolean);
    var matched = 0;
    var total = rows.length;

    function matches(text) {
      if (!terms.length) return true;
      var lower = text.toLowerCase();
      for (var i = 0; i < terms.length; i++) {
        if (lower.indexOf(terms[i]) === -1) return false;
      }
      return true;
    }

    Array.prototype.forEach.call(rows, function (row) {
      var match = matches(row.textContent || '');
      row.hidden = !match;
      if (match) matched++;
    });

    Array.prototype.forEach.call(cards, function (card) {
      card.hidden = !matches(card.textContent || '');
    });

    if (filterCountEl) {
      if (terms.length) {
        filterCountEl.textContent = matched + ' / ' + total;
      } else {
        filterCountEl.textContent = '';
      }
    }

    if (terms.length && els.status) {
      var searchTotal = state.lastPayload && state.lastPayload.summary
        ? state.lastPayload.summary.total : 0;
      els.status.innerHTML = matched === 0
        ? 'Nothing on this page matches the filter &ldquo;' + MGDB.escapeHtml(state.filter)
          + '&rdquo;. ' + searchTotal.toLocaleString() + ' pan-genes matched the search.'
        : 'Showing ' + matched.toLocaleString() + ' of the ' + total.toLocaleString()
          + ' pan-genes on this page matching &ldquo;' + MGDB.escapeHtml(state.filter)
          + '&rdquo;, out of ' + searchTotal.toLocaleString() + ' matched by the search.';
    }
  }

  /* ------------------------------------------------------------------------
     Running a search
     ------------------------------------------------------------------------ */

  function runSearch() {
    /* The results section is hidden until there is something to show. */
    var section = byId('pan-gene-results');
    if (section) { section.hidden = false; }
    state.searched = true;

    show(els.empty, false);
    show(els.error, false);
    show(els.single, false);
    show(els.loading, true);
    els.status.textContent = 'Searching…';

    MGDB.request(API + '?' + buildQuery().toString(), { key: 'pan-gene-search' })
      .then(function (payload) {
        if (!payload || !payload.ok) { throw new Error('search failed'); }
        render(payload);
        syncUrl();
      })
      .catch(function (error) {
        // An aborted request is a newer search superseding this one, not a
        // failure the reader needs to hear about.
        if (error && error.name === 'AbortError') { return; }
        show(els.loading, false);
        show(els.tableWrap, false);
        show(els.cardsView, false);
        show(els.empty, false);
        show(els.error, true);
        els.status.textContent = 'The search could not be completed.';
      });
  }

  function searchSimple(term) {
    state.mode = 'simple';
    state.term = term;
    state.page = 1;
    runSearch();
  }

  function searchAdvanced() {
    state.mode = 'advanced';
    state.filters = readAdvancedForm();
    state.page = 1;
    runSearch();
  }

  /* ------------------------------------------------------------------------
     Wiring
     ------------------------------------------------------------------------ */

  function init() {
    els = {
      form: byId('pan-gene-form'),
      term: byId('pan-gene-term'),
      clear: byId('pan-gene-clear'),
      advanced: byId('pan-gene-advanced'),
      advancedForm: byId('pan-gene-advanced-form'),
      advancedClear: byId('pan-gene-advanced-clear'),
      pageSize: byId('pan-gene-page-size'),
      resultsFilter: byId('pan-gene-results-filter'),
      filterCount: byId('pan-gene-filter-count'),
      status: byId('pan-gene-status'),
      criteria: byId('pan-gene-criteria'),
      loading: byId('pan-gene-loading'),
      single: byId('pan-gene-single'),
      tableWrap: byId('pan-gene-table-wrap'),
      rows: byId('pan-gene-rows'),
      cardsView: byId('pan-gene-cards-view'),
      empty: byId('pan-gene-empty'),
      emptyTitle: byId('pan-gene-empty-title'),
      emptyBody: byId('pan-gene-empty-body'),
      emptyAction: byId('pan-gene-empty-action'),
      error: byId('pan-gene-error'),
      pagination: byId('pan-gene-pagination')
    };

    buildTabs();
    buildDistribution();
    initViewToggle();
    initSortButtons();
    syncAdvancedBadge();

    if (!els.form || !els.rows) { return; }

    if (els.term) {
      if (els.clear) { els.clear.hidden = !els.term.value; }
      els.term.addEventListener('input', function () {
        if (els.clear) { els.clear.hidden = !els.term.value; }
      });
    }

    els.form.addEventListener('submit', function (event) {
      event.preventDefault();
      var val = els.term ? els.term.value.trim() : '';
      if (val) {
        state.filter = '';
        if (els.resultsFilter) { els.resultsFilter.value = ''; }
        if (els.filterCount) { els.filterCount.textContent = ''; }
        searchSimple(val);
      }
    });

    if (els.clear && els.term) {
      els.clear.addEventListener('click', function () {
        els.term.value = '';
        els.clear.hidden = true;
        els.term.focus();
        state.term = '';
        state.mode = 'simple';
        state.filter = '';
        if (els.resultsFilter) { els.resultsFilter.value = ''; }
        if (els.filterCount) { els.filterCount.textContent = ''; }
        els.rows.innerHTML = '';
        if (els.cardsView) { els.cardsView.innerHTML = ''; }
        show(els.tableWrap, false);
        show(els.cardsView, false);
        show(els.pagination, false);
        show(els.single, false);
        show(els.empty, false);
        show(els.criteria, false);
        updateExportTsv([]);
        var section = byId('pan-gene-results');
        if (section) { section.hidden = true; }
        state.searched = false;
        els.status.textContent = 'Enter an identifier above, or open the advanced search, to begin.';
        syncUrl();
      });
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('[data-pan-gene-example]'), function (button) {
        button.addEventListener('click', function () {
          var term = button.getAttribute('data-pan-gene-example');
          if (els.term) {
            els.term.value = term;
            if (els.clear) { els.clear.hidden = false; }
          }
          state.filter = '';
          if (els.resultsFilter) { els.resultsFilter.value = ''; }
          if (els.filterCount) { els.filterCount.textContent = ''; }
          searchSimple(term);
        });
      });

    var advSubmit = byId('pan-gene-adv-submit');
    if (advSubmit) {
      advSubmit.addEventListener('click', function (e) {
        e.preventDefault();
        state.filter = '';
        if (els.resultsFilter) { els.resultsFilter.value = ''; }
        if (els.filterCount) { els.filterCount.textContent = ''; }
        searchAdvanced();
      });
    }

    if (els.advancedClear) {
      els.advancedClear.addEventListener('click', function () {
        clearAdvancedForm();
        state.filters = {};
        syncAdvancedBadge();
      });
    }

    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) return;
      el.addEventListener('input', syncAdvancedBadge);
      el.addEventListener('change', syncAdvancedBadge);
    });

    if (els.pageSize) {
      els.pageSize.addEventListener('change', function () {
        state.pageSize = this.value === 'all' ? 'all' : parseInt(this.value, 10) || 25;
        state.page = 1;
        if (state.searched) { runSearch(); }
      });
    }

    if (els.resultsFilter) {
      els.resultsFilter.addEventListener('input', function () {
        state.filter = this.value.trim();
        if (state.filter === '' && state.lastPayload) {
          /* Re-render rather than un-hiding: the status line has to go back to
             what the search said, not what the filter said. */
          render(state.lastPayload);
          return;
        }
        applyResultsFilter();
      });
    }

    if (els.pagination) {
      els.pagination.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-pan-gene-page]') : null;
        if (!link) { return; }
        var page = parseInt(link.getAttribute('data-pan-gene-page'), 10);
        if (isNaN(page)) { return; }
        state.page = page;
        runSearch();
      });
    }

    var downloadExample = byId('pan-gene-download-example');
    if (downloadExample) {
      downloadExample.addEventListener('click', function () {
        var field = byId('pan-gene-download-list');
        if (field) {
          field.value = ['Zm00001eb269630', 'Zm00001eb156130', 'Zm00001eb124920',
                         'Zm00001eb127100', 'Zm00001eb047750'].join('\n');
          field.focus();
        }
      });
    }

    if (readUrl()) {
      runSearch();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
