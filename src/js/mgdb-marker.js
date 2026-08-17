/* Molecular Marker & Probe Data Center JavaScript
   Handles search input, debounced live queries, card/table view switching,
   sticky section tabs with scrollspy, pagination, clipboard actions, and export URL synchronization. */

(function () {
  'use strict';

  var API_URL = '/search/marker/marker_search_api.php';
  var STORAGE_VIEW_KEY = 'mgdb-marker-view';

  var state = {
    term: '',
    type: 0,
    bin: '',
    page: 1,
    pageSize: 24,
    sort: 'relevance',
    view: 'card',
    currentData: null,
    loading: false
  };

  function byId(id) { return document.getElementById(id); }

  function esc(str) {
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function number(n) {
    return Number(n || 0).toLocaleString();
  }

  /* ── Section Tabs & Scrollspy ───────────────────────────────────────────── */

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) return;

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.startsWith('#')) {
        var section = document.querySelector(href);
        if (section) {
          pairs.push({ tab: tab, section: section });
        }
      }
    });

    function markCurrent(target) {
      pairs.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) {
          pair.tab.setAttribute('aria-current', 'true');
        } else {
          pair.tab.removeAttribute('aria-current');
        }
      });
    }

    var initial = pairs[0];
    if (window.location.hash) {
      pairs.forEach(function (pair) {
        if ('#' + pair.section.id === window.location.hash) {
          initial = pair;
        }
      });
    }
    if (initial) {
      markCurrent(initial.section);
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function (e) {
        markCurrent(pair.section);
      });
    });

    if (!window.IntersectionObserver) return;

    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          markCurrent(entry.target);
        }
      });
    }, { rootMargin: '-20% 0px -60% 0px' });

    pairs.forEach(function (pair) {
      observer.observe(pair.section);
    });
  }

  /* ── URL Parameter Sync ─────────────────────────────────────────────────── */

  function readUrlParams() {
    var params = new URLSearchParams(window.location.search);
    if (params.has('q') || params.has('term')) {
      state.term = params.get('q') || params.get('term') || '';
    }
    if (params.has('type')) {
      state.type = parseInt(params.get('type'), 10) || 0;
    }
    if (params.has('bin')) {
      state.bin = params.get('bin') || '';
    }
    if (params.has('page')) {
      state.page = parseInt(params.get('page'), 10) || 1;
    }
    if (params.has('view')) {
      var v = params.get('view');
      if (v === 'card' || v === 'table') state.view = v;
    }
  }

  function updateUrlParams() {
    var params = new URLSearchParams();
    if (state.term) params.set('q', state.term);
    if (state.type) params.set('type', state.type);
    if (state.bin) params.set('bin', state.bin);
    if (state.page > 1) params.set('page', state.page);
    if (state.view && state.view !== 'card') params.set('view', state.view);

    var queryString = params.toString();
    var newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.replaceState({}, '', newUrl);
  }

  /* ── Export Links Sync ──────────────────────────────────────────────────── */

  function updateExportLinks() {
    var params = new URLSearchParams();
    if (state.term) params.set('term', state.term);
    if (state.type) params.set('type', state.type);
    if (state.bin) params.set('bin', state.bin);

    var tsvLink = byId('marker-export-tsv');
    var csvLink = byId('marker-export-csv');

    if (tsvLink) {
      var tsvParams = new URLSearchParams(params.toString());
      tsvParams.set('format', 'tsv');
      tsvLink.href = API_URL + '?' + tsvParams.toString();
    }

    if (csvLink) {
      var csvParams = new URLSearchParams(params.toString());
      csvParams.set('format', 'csv');
      csvLink.href = API_URL + '?' + csvParams.toString();
    }
  }

  /* ── Search Fetcher ─────────────────────────────────────────────────────── */

  function executeSearch(scrollToResults) {
    if (state.loading) return;
    state.loading = true;

    var status = byId('marker-results-status');
    var container = byId('marker-results');
    var empty = byId('marker-empty');
    var pagination = byId('marker-pagination');

    if (status) {
      status.textContent = 'Searching molecular markers…';
    }

    var params = new URLSearchParams();
    if (state.term) params.set('term', state.term);
    if (state.type) params.set('type', state.type);
    if (state.bin) params.set('bin', state.bin);
    params.set('page', state.page);
    params.set('page_size', state.pageSize);
    params.set('sort', state.sort);

    updateUrlParams();
    updateExportLinks();

    fetch(API_URL + '?' + params.toString())
      .then(function (res) { return res.json(); })
      .then(function (data) {
        state.loading = false;
        state.currentData = data;

        if (!data || !data.ok) {
          if (status) status.textContent = 'Search failed. Please try again.';
          return;
        }

        renderResults(data);
        renderPagination(data.summary.page, data.summary.page_count);

        if (scrollToResults && container) {
          var target = byId('marker-results-section');
          if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      })
      .catch(function (err) {
        state.loading = false;
        if (status) status.textContent = 'An error occurred while fetching markers.';
      });
  }

  /* ── Render Results (Card or Table) ─────────────────────────────────────── */

  function renderResults(data) {
    var container = byId('marker-results');
    var empty = byId('marker-empty');
    var status = byId('marker-results-status');
    var summary = data.summary;

    if (!summary.total || summary.total === 0) {
      if (container) container.innerHTML = '';
      if (empty) empty.hidden = false;
      if (status) {
        var qText = data.query.term ? ' for “' + esc(data.query.term) + '”' : '';
        status.textContent = 'No markers matched your query' + qText + '.';
      }
      return;
    }

    if (empty) empty.hidden = true;

    var start = (summary.page - 1) * summary.page_size + 1;
    var end = Math.min(summary.total, summary.page * summary.page_size);
    var queryText = data.query.term ? ' for “' + esc(data.query.term) + '”' : '';

    if (status) {
      status.textContent = 'Showing ' + number(start) + '–' + number(end) + ' of ' + number(summary.total)
        + ' markers' + queryText + ' · ' + number(summary.elapsed_ms) + ' ms';
    }

    if (!container) return;

    if (state.view === 'table') {
      renderTableView(container, data.results);
    } else {
      renderCardView(container, data.results);
    }

    initCopyButtons();
  }

  function renderCardView(container, results) {
    container.className = 'marker-results-container marker-view-card';
    container.innerHTML = results.map(function (row) {
      var name = row.name || 'Untitled marker';
      var recordUrl = '/data_center/marker?id=' + encodeURIComponent(row.id);

      var typeBadge = row.type_name
        ? '<span class="marker-type-badge">' + esc(row.type_name) + '</span>'
        : '';
      var binBadge = row.bin
        ? '<span class="marker-bin-badge">Bin ' + esc(row.bin) + '</span>'
        : '';

      var lociHtml = row.loci
        ? '<p><strong>Linked Loci:</strong> ' + esc(row.loci) + '</p>'
        : '';
      var synHtml = row.synonyms
        ? '<p><strong>Synonyms:</strong> ' + esc(row.synonyms) + '</p>'
        : '';
      var commentsHtml = row.comments
        ? '<div class="marker-card-comments">' + esc(row.comments) + '</div>'
        : '';

      return '<article class="marker-result-card" data-marker-id="' + row.id + '">'
        + '  <div>'
        + '    <div class="marker-card-meta">' + typeBadge + binBadge + '</div>'
        + '    <h3><a href="' + recordUrl + '">' + esc(name) + '</a></h3>'
        + '    <div class="marker-card-details">' + lociHtml + synHtml + '</div>'
        +      commentsHtml
        + '  </div>'
        + '  <div class="marker-card-links">'
        + '    <a href="' + recordUrl + '">View Record &rarr;</a>'
        + '    <button class="marker-copy-btn" type="button" data-copy-value="' + esc(name) + '">Copy Name</button>'
        + '    <button class="marker-copy-btn" type="button" data-copy-value="' + esc(row.id) + '">Copy ID</button>'
        + '  </div>'
        + '</article>';
    }).join('');
  }

  function renderTableView(container, results) {
    container.className = 'marker-results-container marker-view-table';
    var rows = results.map(function (row) {
      var name = row.name || 'Untitled marker';
      var recordUrl = '/data_center/marker?id=' + encodeURIComponent(row.id);
      var binDisplay = row.bin ? '<span class="marker-bin-badge">' + esc(row.bin) + '</span>' : '—';
      var lociDisplay = row.loci ? esc(row.loci) : '—';
      var synDisplay = row.synonyms ? esc(row.synonyms) : '—';

      return '<tr>'
        + '  <td><strong><a href="' + recordUrl + '">' + esc(name) + '</a></strong></td>'
        + '  <td><span class="marker-type-badge">' + esc(row.type_name || '—') + '</span></td>'
        + '  <td>' + binDisplay + '</td>'
        + '  <td>' + lociDisplay + '</td>'
        + '  <td>' + synDisplay + '</td>'
        + '  <td><button class="marker-copy-btn" type="button" data-copy-value="' + esc(name) + '">Copy</button></td>'
        + '</tr>';
    }).join('');

    container.innerHTML = '<table class="marker-table">'
      + '  <thead>'
      + '    <tr>'
      + '      <th>Marker Name</th>'
      + '      <th>Type</th>'
      + '      <th>Bin Position</th>'
      + '      <th>Linked Loci</th>'
      + '      <th>Synonyms</th>'
      + '      <th>Action</th>'
      + '    </tr>'
      + '  </thead>'
      + '  <tbody>' + rows + '</tbody>'
      + '</table>';
  }

  /* ── Pagination ─────────────────────────────────────────────────────────── */

  function renderPagination(page, pageCount) {
    var nav = byId('marker-pagination');
    if (!nav) return;

    if (pageCount <= 1) {
      nav.innerHTML = '';
      return;
    }

    var pages = [];
    var start = Math.max(1, page - 2);
    var end = Math.min(pageCount, page + 2);

    pages.push('<button type="button" data-page="' + Math.max(1, page - 1) + '"' + (page === 1 ? ' disabled' : '') + '>Previous</button>');
    if (start > 1) pages.push('<button type="button" data-page="1">1</button>');
    if (start > 2) pages.push('<button type="button" disabled>&hellip;</button>');

    for (var i = start; i <= end; i += 1) {
      pages.push('<button type="button" data-page="' + i + '" class="' + (i === page ? 'is-active' : '') + '" aria-current="' + (i === page ? 'page' : 'false') + '">' + i + '</button>');
    }

    if (end < pageCount - 1) pages.push('<button type="button" disabled>&hellip;</button>');
    if (end < pageCount) pages.push('<button type="button" data-page="' + pageCount + '">' + pageCount + '</button>');
    pages.push('<button type="button" data-page="' + Math.min(pageCount, page + 1) + '"' + (page === pageCount ? ' disabled' : '') + '>Next</button>');

    nav.innerHTML = pages.join('');

    Array.prototype.forEach.call(nav.querySelectorAll('[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var targetPage = parseInt(btn.getAttribute('data-page'), 10);
        if (targetPage && targetPage !== state.page) {
          state.page = targetPage;
          executeSearch(true);
        }
      });
    });
  }

  /* ── Clipboard Copy Helper ──────────────────────────────────────────────── */

  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.marker-copy-btn'), function (btn) {
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

  /* ── Form Controls & View Switcher ──────────────────────────────────────── */

  function initForm() {
    var form = byId('marker-search-form');
    var input = byId('marker-query');
    var clearBtn = byId('marker-query-clear');
    var typeSelect = byId('marker-type');

    if (input) {
      input.value = state.term;
      if (clearBtn) clearBtn.hidden = !state.term;

      input.addEventListener('input', function () {
        if (clearBtn) clearBtn.hidden = !input.value;
      });
    }

    if (typeSelect && state.type) {
      typeSelect.value = String(state.type);
    }

    if (clearBtn && input) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        clearBtn.hidden = true;
        state.term = '';
        state.page = 1;
        executeSearch(false);
      });
    }

    if (form && input) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        state.term = input.value.trim();
        state.type = typeSelect ? (parseInt(typeSelect.value, 10) || 0) : 0;
        state.page = 1;
        executeSearch(true);
      });
    }

    if (typeSelect) {
      typeSelect.addEventListener('change', function () {
        state.type = parseInt(typeSelect.value, 10) || 0;
        state.page = 1;
        executeSearch(true);
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-marker-example]'), function (btn) {
      btn.addEventListener('click', function () {
        var ex = btn.getAttribute('data-marker-example');
        if (input) {
          input.value = ex;
          if (clearBtn) clearBtn.hidden = false;
        }
        state.term = ex;
        state.page = 1;
        executeSearch(true);
      });
    });

    var emptyReset = byId('marker-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (input) { input.value = ''; if (clearBtn) clearBtn.hidden = true; }
        if (typeSelect) typeSelect.value = '0';
        state.term = '';
        state.type = 0;
        state.page = 1;
        executeSearch(false);
      });
    }
  }

  function initViewToggle() {
    var buttons = document.querySelectorAll('.marker-view-btn[data-view]');
    if (!buttons.length) return;

    var savedView = 'card';
    try { savedView = localStorage.getItem(STORAGE_VIEW_KEY) || 'card'; } catch (e) {}
    if (state.view) savedView = state.view;

    function applyView(view) {
      state.view = view;
      Array.prototype.forEach.call(buttons, function (btn) {
        btn.setAttribute('aria-pressed', btn.getAttribute('data-view') === view ? 'true' : 'false');
      });
      try { localStorage.setItem(STORAGE_VIEW_KEY, view); } catch (e) {}
      if (state.currentData) {
        renderResults(state.currentData);
      }
      updateUrlParams();
    }

    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        applyView(btn.getAttribute('data-view'));
      });
    });

    applyView(savedView);
  }

  /* ── Bootstrap ──────────────────────────────────────────────────────────── */

  function init() {
    readUrlParams();
    buildTabs();
    initForm();
    initViewToggle();
    updateExportLinks();

    // If query term or type was in URL on load, execute search immediately
    if (state.term || state.type || state.bin) {
      executeSearch(false);
    } else {
      // Default initial query with featured popular markers (e.g. bnlg)
      state.term = 'bnlg%';
      var input = byId('marker-query');
      if (input) {
        input.value = state.term;
        var clearBtn = byId('marker-query-clear');
        if (clearBtn) clearBtn.hidden = false;
      }
      executeSearch(false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
