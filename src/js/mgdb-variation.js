/* ==========================================================================
   Variations & Alleles Data Hub (/data_center/variation)
   Client-side search, faceted filtering, pagination, and view switching.
   ========================================================================== */

(function () {
  'use strict';

  var API_URL = '/search/variation/variation_search_api.php';

  var state = {
    term: '',
    type: 0,
    dominance: 0,
    viability: 0,
    mutagen: 0,
    phenotype: 0,
    has_stock: '',
    view: 'table',
    sort: 'relevance',
    page: 1,
    pageSize: 24,
    totalPages: 1,
    totalRecords: 0,
    loading: false
  };

  var debounceTimer = null;

  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /* ── Sticky Section Tabs & Scrollspy ────────────────────────────────────── */

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
      pair.tab.addEventListener('click', function () {
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

  /* ── Query Execution ────────────────────────────────────────────────────── */

  function fetchResults(scrollToResults) {
    if (state.loading) return;
    state.loading = true;

    var statusEl = byId('variation-results-status');
    if (statusEl) {
      statusEl.textContent = 'Searching variations…';
    }

    var params = new URLSearchParams({
      term: state.term,
      type: state.type,
      dominance: state.dominance,
      viability: state.viability,
      mutagen: state.mutagen,
      phenotype: state.phenotype,
      has_stock: state.has_stock,
      sort: state.sort,
      page: state.page,
      page_size: state.pageSize
    });

    fetch(API_URL + '?' + params.toString())
      .then(function (response) {
        if (!response.ok) throw new Error('Network error');
        return response.json();
      })
      .then(function (data) {
        state.loading = false;
        if (!data || !data.ok) {
          renderError();
          return;
        }

        state.totalRecords = data.summary.total;
        state.totalPages = data.summary.page_count || 1;
        renderResults(data);
        renderPagination();
        updateExportLinks();
        syncUrlParams();

        if (scrollToResults) {
          var resultsSec = byId('variation-results-section');
          if (resultsSec) {
            resultsSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      })
      .catch(function (error) {
        state.loading = false;
        renderError();
      });
  }

  /* ── Rendering ──────────────────────────────────────────────────────────── */

  function renderResults(data) {
    var container = byId('variation-results');
    var emptyEl = byId('variation-empty');
    var statusEl = byId('variation-results-status');
    var rows = data.results || [];

    if (!rows.length) {
      if (container) container.innerHTML = '';
      if (emptyEl) emptyEl.hidden = false;
      if (statusEl) {
        statusEl.textContent = 'No variations matched your query.';
      }
      return;
    }

    if (emptyEl) emptyEl.hidden = true;

    var start = (state.page - 1) * state.pageSize + 1;
    var end = Math.min(start + rows.length - 1, state.totalRecords);
    if (statusEl) {
      var criteriaText = data.criteria && data.criteria.length ? ' (' + data.criteria.join(', ') + ')' : '';
      statusEl.textContent = 'Showing ' + start.toLocaleString() + '–' + end.toLocaleString() + ' of ' + state.totalRecords.toLocaleString() + ' variations' + criteriaText + ' · ' + data.summary.elapsed_ms + ' ms';
    }

    if (state.view === 'card') {
      container.className = 'variation-results-container variation-view-card';
      container.innerHTML = rows.map(renderCard).join('');
    } else {
      container.className = 'variation-results-container variation-view-table';
      container.innerHTML = renderTable(rows);
    }
  }

  function renderCard(row) {
    var typeBadge = row.type_name ? '<span class="mgdb-pill mgdb-pill-ok">' + escapeHtml(row.type_name) + '</span>' : '';
    var domBadge = row.dominance_name ? '<span class="mgdb-pill mgdb-pill-info">' + escapeHtml(row.dominance_name) + '</span>' : '';
    var stockBadge = row.stock_count > 0 || row.prog_stock_name ? '<span class="mgdb-pill mgdb-pill-warn">Stock available</span>' : '';

    var locusLink = row.locus_name
      ? '<p class="variation-card-locus">Locus: <strong><a href="/data_center/locus?id=' + encodeURIComponent(row.locus_id || row.locus_name) + '">' + escapeHtml(row.locus_name) + '</a></strong>' + (row.locus_full_name ? ' <em>(' + escapeHtml(row.locus_full_name) + ')</em>' : '') + '</p>'
      : '<p class="variation-card-locus"><span class="mgdb-muted">Unmapped or standalone allele</span></p>';

    var metaItems = [];
    if (row.type_name) metaItems.push('<dt>Type</dt><dd>' + escapeHtml(row.type_name) + '</dd>');
    if (row.dominance_name) metaItems.push('<dt>Dominance</dt><dd>' + escapeHtml(row.dominance_name) + '</dd>');
    if (row.viability_name) metaItems.push('<dt>Viability</dt><dd>' + escapeHtml(row.viability_name) + '</dd>');
    if (row.mutagens) metaItems.push('<dt>Mutagen</dt><dd>' + escapeHtml(row.mutagens) + '</dd>');
    if (row.prog_stock_name) metaItems.push('<dt>Progenitor</dt><dd><a href="/data_center/stock?id=' + encodeURIComponent(row.prog_stock_id || row.prog_stock_name) + '">' + escapeHtml(row.prog_stock_name) + '</a></dd>');

    var metaHtml = metaItems.length ? '<dl class="variation-card-meta">' + metaItems.join('') + '</dl>' : '';

    var phenoHtml = row.phenotypes
      ? '<p class="variation-card-pheno"><strong>Phenotypic effects:</strong> ' + escapeHtml(row.phenotypes) + '</p>'
      : '';

    var descHtml = row.alleledescriptor
      ? '<p class="variation-card-pheno"><strong>Descriptor:</strong> ' + escapeHtml(row.alleledescriptor) + '</p>'
      : '';

    return '<article class="variation-card" data-variation-id="' + row.id + '">' +
      '<div>' +
        '<div class="variation-card-header">' +
          '<h3><a href="/data_center/variation?id=' + row.id + '">' + escapeHtml(row.name) + '</a></h3>' +
          '<div style="display:flex;gap:4px;flex-wrap:wrap;">' + typeBadge + domBadge + stockBadge + '</div>' +
        '</div>' +
        locusLink +
        metaHtml +
        phenoHtml +
        descHtml +
      '</div>' +
      '<div class="variation-card-actions">' +
        '<a href="/data_center/variation?id=' + row.id + '">Variation record &rarr;</a>' +
        (row.locus_name ? '<a href="/data_center/locus?id=' + encodeURIComponent(row.locus_id || row.locus_name) + '">Locus page &rarr;</a>' : '') +
        (row.prog_stock_id ? '<a href="/data_center/stock?id=' + row.prog_stock_id + '">Stock order &nearr;</a>' : '') +
      '</div>' +
    '</article>';
  }

  function renderTable(rows) {
    var thead = '<thead><tr>' +
      '<th>Variation Name</th>' +
      '<th>Locus / Gene</th>' +
      '<th>Type</th>' +
      '<th>Dominance</th>' +
      '<th>Viability</th>' +
      '<th>Mutagen / Origin</th>' +
      '<th>Phenotypic Effects</th>' +
      '<th>Actions</th>' +
    '</tr></thead>';

    var tbody = rows.map(function (row) {
      var nameCell = '<strong><a href="/data_center/variation?id=' + row.id + '">' + escapeHtml(row.name) + '</a></strong>';
      var locusCell = row.locus_name
        ? '<a href="/data_center/locus?id=' + encodeURIComponent(row.locus_id || row.locus_name) + '">' + escapeHtml(row.locus_name) + '</a>'
        : '<span class="mgdb-muted">—</span>';
      var typeCell = row.type_name ? '<span class="mgdb-pill mgdb-pill-ok">' + escapeHtml(row.type_name) + '</span>' : '<span class="mgdb-muted">—</span>';
      var domCell = row.dominance_name ? escapeHtml(row.dominance_name) : '<span class="mgdb-muted">—</span>';
      var viabCell = row.viability_name ? escapeHtml(row.viability_name) : '<span class="mgdb-muted">—</span>';
      var mutCell = row.mutagens ? escapeHtml(row.mutagens) : '<span class="mgdb-muted">—</span>';
      var phenoCell = row.phenotypes ? escapeHtml(row.phenotypes) : '<span class="mgdb-muted">—</span>';
      var actionCell = '<a href="/data_center/variation?id=' + row.id + '">Record &rarr;</a>';

      return '<tr>' +
        '<td>' + nameCell + '</td>' +
        '<td>' + locusCell + '</td>' +
        '<td>' + typeCell + '</td>' +
        '<td>' + domCell + '</td>' +
        '<td>' + viabCell + '</td>' +
        '<td>' + mutCell + '</td>' +
        '<td>' + phenoCell + '</td>' +
        '<td>' + actionCell + '</td>' +
      '</tr>';
    }).join('');

    return '<table class="variation-table">' + thead + '<tbody>' + tbody + '</tbody></table>';
  }

  function renderPagination() {
    var nav = byId('variation-pagination');
    if (!nav) return;

    if (state.totalPages <= 1) {
      nav.innerHTML = '';
      return;
    }

    var html = '';
    html += '<button type="button" data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + '>&lsaquo; Prev</button>';

    var maxPagesToShow = 7;
    var startPage = Math.max(1, state.page - 3);
    var endPage = Math.min(state.totalPages, startPage + maxPagesToShow - 1);
    if (endPage - startPage < maxPagesToShow - 1) {
      startPage = Math.max(1, endPage - maxPagesToShow + 1);
    }

    if (startPage > 1) {
      html += '<button type="button" data-page="1">1</button>';
      if (startPage > 2) html += '<span class="mgdb-muted" style="padding:0 4px">…</span>';
    }

    for (var p = startPage; p <= endPage; p++) {
      var isCurrent = p === state.page;
      html += '<button type="button" data-page="' + p + '" ' + (isCurrent ? 'aria-current="page"' : '') + '>' + p + '</button>';
    }

    if (endPage < state.totalPages) {
      if (endPage < state.totalPages - 1) html += '<span class="mgdb-muted" style="padding:0 4px">…</span>';
      html += '<button type="button" data-page="' + state.totalPages + '">' + state.totalPages + '</button>';
    }

    html += '<button type="button" data-page="' + (state.page + 1) + '" ' + (state.page >= state.totalPages ? 'disabled' : '') + '>Next &rsaquo;</button>';
    nav.innerHTML = html;

    Array.prototype.forEach.call(nav.querySelectorAll('button[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var targetPage = parseInt(btn.getAttribute('data-page'), 10);
        if (targetPage && targetPage >= 1 && targetPage <= state.totalPages && targetPage !== state.page) {
          state.page = targetPage;
          fetchResults(true);
        }
      });
    });
  }

  function renderError() {
    var container = byId('variation-results');
    var statusEl = byId('variation-results-status');
    if (container) container.innerHTML = '';
    if (statusEl) {
      statusEl.textContent = 'An error occurred while fetching variation records. Please try again.';
    }
  }

  function updateExportLinks() {
    var tsvBtn = byId('variation-export-tsv');
    var csvBtn = byId('variation-export-csv');

    var params = new URLSearchParams({
      term: state.term,
      type: state.type,
      dominance: state.dominance,
      viability: state.viability,
      mutagen: state.mutagen,
      phenotype: state.phenotype,
      has_stock: state.has_stock
    });

    if (tsvBtn) {
      params.set('format', 'tsv');
      tsvBtn.href = API_URL + '?' + params.toString();
    }
    if (csvBtn) {
      params.set('format', 'csv');
      csvBtn.href = API_URL + '?' + params.toString();
    }
  }

  function syncUrlParams() {
    if (!window.history || !window.history.replaceState) return;
    var params = new URLSearchParams();
    if (state.term) params.set('q', state.term);
    if (state.type) params.set('type', state.type);
    if (state.dominance) params.set('dominance', state.dominance);
    if (state.viability) params.set('viability', state.viability);
    if (state.mutagen) params.set('mutagen', state.mutagen);
    if (state.phenotype) params.set('phenotype', state.phenotype);
    if (state.has_stock) params.set('has_stock', state.has_stock);
    if (state.sort !== 'relevance') params.set('sort', state.sort);
    if (state.view !== 'table') params.set('view', state.view);
    if (state.page > 1) params.set('page', state.page);

    var queryString = params.toString();
    var newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.replaceState({}, '', newUrl);
  }

  function readUrlParams() {
    var params = new URLSearchParams(window.location.search);
    if (params.has('q')) state.term = params.get('q');
    else if (params.has('term')) state.term = params.get('term');
    if (params.has('type')) state.type = parseInt(params.get('type'), 10) || 0;
    if (params.has('dominance')) state.dominance = parseInt(params.get('dominance'), 10) || 0;
    if (params.has('viability')) state.viability = parseInt(params.get('viability'), 10) || 0;
    if (params.has('mutagen')) state.mutagen = parseInt(params.get('mutagen'), 10) || 0;
    if (params.has('phenotype')) state.phenotype = parseInt(params.get('phenotype'), 10) || 0;
    if (params.has('has_stock')) state.has_stock = params.get('has_stock');
    if (params.has('sort')) state.sort = params.get('sort');
    if (params.has('view')) state.view = params.get('view') === 'card' ? 'card' : 'table';
    if (params.has('page')) state.page = parseInt(params.get('page'), 10) || 1;

    var queryInput = byId('variation-query');
    if (queryInput && state.term) queryInput.value = state.term;

    var typeSelect = byId('variation-type');
    if (typeSelect && state.type) typeSelect.value = state.type;

    var domSelect = byId('variation-dominance');
    if (domSelect && state.dominance) domSelect.value = state.dominance;

    var viabSelect = byId('variation-viability');
    if (viabSelect && state.viability) viabSelect.value = state.viability;

    var mutSelect = byId('variation-mutagen');
    if (mutSelect && state.mutagen) mutSelect.value = state.mutagen;

    var phenoSelect = byId('variation-phenotype');
    if (phenoSelect && state.phenotype) phenoSelect.value = state.phenotype;

    var stockCheck = byId('variation-has-stock');
    if (stockCheck) stockCheck.checked = state.has_stock === '1' || state.has_stock === 'true';

    var sortSelect = byId('variation-sort');
    if (sortSelect && state.sort) sortSelect.value = state.sort;

    updateViewButtons();
  }

  function updateViewButtons() {
    var buttons = document.querySelectorAll('.variation-view-btn');
    Array.prototype.forEach.call(buttons, function (btn) {
      var isCurrent = btn.getAttribute('data-view') === state.view;
      btn.classList.toggle('is-active', isCurrent);
      btn.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
    });
  }

  /* ── Initialization ────────────────────────────────────────────────────── */

  function initialize() {
    buildTabs();
    readUrlParams();

    var form = byId('variation-search-form');
    var queryInput = byId('variation-query');
    var clearBtn = byId('variation-query-clear');
    var resetBtn = byId('variation-empty-reset');
    var typeSelect = byId('variation-type');
    var domSelect = byId('variation-dominance');
    var viabSelect = byId('variation-viability');
    var mutSelect = byId('variation-mutagen');
    var phenoSelect = byId('variation-phenotype');
    var stockCheck = byId('variation-has-stock');
    var sortSelect = byId('variation-sort');

    if (queryInput) {
      queryInput.addEventListener('input', function () {
        state.term = queryInput.value.trim();
        if (clearBtn) clearBtn.hidden = !state.term;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          state.page = 1;
          fetchResults(false);
        }, 300);
      });
      if (clearBtn) clearBtn.hidden = !queryInput.value;
    }

    if (clearBtn && queryInput) {
      clearBtn.addEventListener('click', function () {
        queryInput.value = '';
        state.term = '';
        clearBtn.hidden = true;
        state.page = 1;
        fetchResults(false);
        queryInput.focus();
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        state.page = 1;
        fetchResults(true);
      });
    }

    if (typeSelect) {
      typeSelect.addEventListener('change', function () {
        state.type = parseInt(typeSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (domSelect) {
      domSelect.addEventListener('change', function () {
        state.dominance = parseInt(domSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (viabSelect) {
      viabSelect.addEventListener('change', function () {
        state.viability = parseInt(viabSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (mutSelect) {
      mutSelect.addEventListener('change', function () {
        state.mutagen = parseInt(mutSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (phenoSelect) {
      phenoSelect.addEventListener('change', function () {
        state.phenotype = parseInt(phenoSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (stockCheck) {
      stockCheck.addEventListener('change', function () {
        state.has_stock = stockCheck.checked ? '1' : '';
        state.page = 1;
        fetchResults(false);
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        state.sort = sortSelect.value;
        state.page = 1;
        fetchResults(false);
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.variation-view-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var view = btn.getAttribute('data-view');
        if (view && (view === 'card' || view === 'table')) {
          state.view = view;
          updateViewButtons();
          fetchResults(false);
        }
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-var-example]'), function (btn) {
      btn.addEventListener('click', function () {
        var ex = btn.getAttribute('data-var-example');
        if (ex && queryInput) {
          queryInput.value = ex;
          state.term = ex;
          if (clearBtn) clearBtn.hidden = false;
          state.page = 1;
          fetchResults(true);
        }
      });
    });

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (queryInput) queryInput.value = '';
        if (typeSelect) typeSelect.value = '0';
        if (domSelect) domSelect.value = '0';
        if (viabSelect) viabSelect.value = '0';
        if (mutSelect) mutSelect.value = '0';
        if (phenoSelect) phenoSelect.value = '0';
        if (stockCheck) stockCheck.checked = false;
        if (clearBtn) clearBtn.hidden = true;

        state.term = '';
        state.type = 0;
        state.dominance = 0;
        state.viability = 0;
        state.mutagen = 0;
        state.phenotype = 0;
        state.has_stock = '';
        state.page = 1;
        fetchResults(false);
      });
    }

    // Default search on load: search with default parameters or whatever was passed in URL
    fetchResults(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
