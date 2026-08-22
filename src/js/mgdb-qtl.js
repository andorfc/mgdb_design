/* QTL Data Center JavaScript (/data_center/qtl)
 * Handles search submissions, dropdown filters, live results rendering,
 * example chips, trait shortcuts, and scrollspy navigation tabs.
 */

(function () {
  'use strict';

  var API_URL = '/search/qtl/qtl_search_api.php';
  var lastQuery = '';

  function byId(id) { return document.getElementById(id); }

  function esc(str) {
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function number(n) { return Number(n || 0).toLocaleString(); }

  /* ── Search Form & AJAX ─────────────────────────────────────────────────── */

  function initSearchForm() {
    var form = byId('qtl-search-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      runSearch();
    });

    var resetBtn = byId('qtl-reset-btn');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        setTimeout(function () {
          runSearch();
        }, 10);
      });
    }

    // Auto-search on dropdown filter changes
    var selects = form.querySelectorAll('select');
    selects.forEach(function (sel) {
      sel.addEventListener('change', function () {
        runSearch();
      });
    });
  }

  function initExamples() {
    var exampleBtns = document.querySelectorAll('.qtl-example-btn');
    exampleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('qtl-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          termInput.focus();
        }
      });
    });

    var traitShortcuts = document.querySelectorAll('.qtl-trait-shortcut');
    traitShortcuts.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('qtl-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          var searchSection = byId('qtl-search-panel');
          if (searchSection) {
            searchSection.scrollIntoView({ behavior: 'smooth' });
          }
        }
      });
    });

    var parentShortcuts = document.querySelectorAll('.qtl-parent-shortcut');
    parentShortcuts.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('qtl-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          var searchSection = byId('qtl-search-panel');
          if (searchSection) {
            searchSection.scrollIntoView({ behavior: 'smooth' });
          }
        }
      });
    });
  }

  function buildParams() {
    var params = new URLSearchParams();
    var term = (byId('qtl-search-term') && byId('qtl-search-term').value.trim()) || '';
    var trait = (byId('qtl-filter-trait') && byId('qtl-filter-trait').value) || '';
    var parent = (byId('qtl-filter-parent') && byId('qtl-filter-parent').value) || '';

    if (term) params.set('term', term);
    if (trait) params.set('trait', trait);
    if (parent) params.set('parent', parent);

    return params;
  }

  function runSearch() {
    var params = buildParams();
    var statusEl = byId('qtl-results-status');
    var notesEl = byId('qtl-notes');
    var resultsEl = byId('qtl-results');
    var emptyEl = byId('qtl-empty');
    var exportLink = byId('qtl-export-tsv');

    notesEl.innerHTML = '';
    emptyEl.hidden = true;
    exportLink.hidden = true;

    resultsEl.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching QTL analyses&hellip;</div>';
    statusEl.textContent = 'Searching…';

    lastQuery = params.toString();

    fetch(API_URL + '?' + lastQuery)
      .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data.ok) {
          var msg = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          resultsEl.innerHTML = '';
          notesEl.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' + esc(msg) + '</div>';
          statusEl.textContent = 'Search failed.';
          return;
        }
        renderResults(wrap.data);
      })
      .catch(function () {
        resultsEl.innerHTML = '';
        notesEl.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">The search request failed. Please try again.</div>';
        statusEl.textContent = 'Search failed.';
      });
  }

  function renderResults(data) {
    var statusEl = byId('qtl-results-status');
    var resultsEl = byId('qtl-results');
    var emptyEl = byId('qtl-empty');
    var exportLink = byId('qtl-export-tsv');

    var total = data.summary && data.summary.total ? data.summary.total : 0;
    var returned = data.summary && data.summary.returned ? data.summary.returned : 0;
    var elapsed = data.summary && data.summary.elapsed_ms != null ? data.summary.elapsed_ms : null;

    if (!total || !data.results || !data.results.length) {
      resultsEl.innerHTML = '';
      emptyEl.hidden = false;
      statusEl.textContent = 'No QTL analyses matched your query.';
      return;
    }

    emptyEl.hidden = true;
    statusEl.textContent = number(total) + ' QTL analys' + (total === 1 ? 'is' : 'es') + ' found'
      + (returned < total ? ' (showing top ' + number(returned) + ')' : '')
      + (elapsed != null ? ' (' + elapsed + ' ms)' : '') + '.';

    resultsEl.innerHTML = buildTableHtml(data.results);
    exportLink.href = API_URL + '?' + lastQuery + '&format=tsv';
    exportLink.hidden = false;
  }

  function buildTableHtml(results) {
    var rows = results.map(function (item) {
      return '<tr>'
        + buildNameCell(item)
        + buildTraitCell(item)
        + buildParentsCell(item)
        + buildExpCell(item)
        + buildDesignCell(item)
        + '</tr>';
    }).join('');

    return '<div class="mgdb-table-scroll" tabindex="0">'
      + '<table class="mgdb-table qtl-table">'
      + '<caption>Matching QTL analyses<span class="mgdb-muted">' + number(results.length) + ' shown</span></caption>'
      + '<thead><tr>'
      + '<th scope="col">Analysis Symbol</th>'
      + '<th scope="col">Trait Evaluated</th>'
      + '<th scope="col">Mapping Parents</th>'
      + '<th scope="col">Experiment Study</th>'
      + '<th scope="col">Design &amp; Detections</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  function buildNameCell(item) {
    var name = item.name ? esc(item.name) : '(unnamed)';
    var link = item.url ? '<a href="' + esc(item.url) + '"><strong>' + name + '</strong></a>' : '<strong>' + name + '</strong>';
    return '<td scope="row" class="qtl-name-cell">' + link + '</td>';
  }

  function buildTraitCell(item) {
    var trait = item.trait_name ? esc(item.trait_name) : 'Unspecified';
    return '<td><span class="mgdb-pill mgdb-pill-ok">' + trait + '</span></td>';
  }

  function buildParentsCell(item) {
    var parents = item.parents || [];
    if (!parents.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    var cross = parents.map(esc).join(' &times; ');
    return '<td><span class="qtl-parents-badge">' + cross + '</span></td>';
  }

  function buildExpCell(item) {
    if (!item.experiment_name) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    var link = item.exp_id ? '<a href="/data_center/qtl_exp?id=' + item.exp_id + '">' + esc(item.experiment_name) + '</a>' : esc(item.experiment_name);
    return '<td>' + link + '</td>';
  }

  function buildDesignCell(item) {
    var parts = [];
    if (item.qtl_count > 0) {
      parts.push('<strong>' + number(item.qtl_count) + ' QTL loci mapped</strong>');
    }
    if (item.method) {
      var shortMethod = item.method.length > 80 ? item.method.slice(0, 80) + '…' : item.method;
      parts.push('<span class="qtl-desc">' + esc(shortMethod) + '</span>');
    }
    if (!parts.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    return '<td><ul class="qtl-attr-list"><li>' + parts.join('<br>') + '</li></ul></td>';
  }

  /* ── Section Navigation Tabs & Scrollspy ────────────────────────────────── */

  function initSectionTabs() {
    var nav = document.querySelector('.mgdb-section-tabs');
    if (!nav) return;
    var links = nav.querySelectorAll('a[href^="#"]');
    if (!links.length) return;

    var sections = [];
    Array.prototype.forEach.call(links, function (link) {
      var id = link.getAttribute('href').slice(1);
      var el = document.getElementById(id);
      if (el) sections.push({ id: id, link: link, el: el });
    });

    if (!('IntersectionObserver' in window)) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          sections.forEach(function (s) {
            var current = s.el === entry.target;
            s.link.classList.toggle('is-current', current);
            if (current) {
              s.link.setAttribute('aria-current', 'true');
            } else {
              s.link.removeAttribute('aria-current');
            }
          });
        }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(function (s) { observer.observe(s.el); });
  }

  /* ── Init ───────────────────────────────────────────────────────────────── */

  function init() {
    initSectionTabs();
    initSearchForm();
    initExamples();

    // Check URL parameters
    var urlParams = new URLSearchParams(window.location.search);
    var hasQuery = false;
    if (urlParams.has('term') || urlParams.has('qtl_term')) {
      var termVal = urlParams.get('term') || urlParams.get('qtl_term');
      var termInput = byId('qtl-search-term');
      if (termInput && termVal) {
        termInput.value = termVal;
        hasQuery = true;
      }
    }
    if (urlParams.has('trait')) {
      var traitSelect = byId('qtl-filter-trait');
      if (traitSelect) {
        traitSelect.value = urlParams.get('trait');
        hasQuery = true;
      }
    }

    if (hasQuery) {
      runSearch();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
