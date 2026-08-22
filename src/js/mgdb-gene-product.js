/* Gene Product Data Center JavaScript (/data_center/gene_product)
 * Handles search submissions, dropdown filters, live results rendering,
 * example buttons, functional class shortcuts, and scrollspy navigation tabs.
 */

(function () {
  'use strict';

  var API_URL = '/search/gene_product/gene_product_search_api.php';
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
    var form = byId('gp-search-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      runSearch();
    });

    var resetBtn = byId('gp-reset-btn');
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
    var exampleBtns = document.querySelectorAll('.gp-example-btn');
    exampleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('gp-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          termInput.focus();
        }
      });
    });

    var shortcutBtns = document.querySelectorAll('.gp-filter-shortcut');
    shortcutBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var typeSelect = byId('gp-filter-type');
        var termInput = byId('gp-search-term');
        if (typeSelect) {
          if (termInput) termInput.value = '';
          typeSelect.value = btn.dataset.type || '';
          runSearch();
          var searchSection = byId('gene-product-search-panel');
          if (searchSection) {
            searchSection.scrollIntoView({ behavior: 'smooth' });
          }
        }
      });
    });
  }

  function buildParams() {
    var params = new URLSearchParams();
    var term = (byId('gp-search-term') && byId('gp-search-term').value.trim()) || '';
    var type = (byId('gp-filter-type') && byId('gp-filter-type').value) || '';
    var ecNum = (byId('gp-filter-ec') && byId('gp-filter-ec').value.trim()) || '';
    var loc = (byId('gp-filter-loc') && byId('gp-filter-loc').value) || '';
    var pathway = (byId('gp-filter-pathway') && byId('gp-filter-pathway').value) || '';

    if (term) params.set('term', term);
    if (type) params.set('type', type);
    if (ecNum) params.set('ec_num', ecNum);
    if (loc) params.set('localization', loc);
    if (pathway) params.set('pathway', pathway);

    return params;
  }

  function runSearch() {
    var params = buildParams();
    var statusEl = byId('gp-results-status');
    var notesEl = byId('gp-notes');
    var resultsEl = byId('gp-results');
    var emptyEl = byId('gp-empty');
    var exportLink = byId('gp-export-tsv');

    notesEl.innerHTML = '';
    emptyEl.hidden = true;
    exportLink.hidden = true;

    resultsEl.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching gene products&hellip;</div>';
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
    var statusEl = byId('gp-results-status');
    var resultsEl = byId('gp-results');
    var emptyEl = byId('gp-empty');
    var exportLink = byId('gp-export-tsv');

    var total = data.summary && data.summary.total ? data.summary.total : 0;
    var returned = data.summary && data.summary.returned ? data.summary.returned : 0;
    var elapsed = data.summary && data.summary.elapsed_ms != null ? data.summary.elapsed_ms : null;

    if (!total || !data.results || !data.results.length) {
      resultsEl.innerHTML = '';
      emptyEl.hidden = false;
      statusEl.textContent = 'No gene products matched your query.';
      return;
    }

    emptyEl.hidden = true;
    statusEl.textContent = number(total) + ' gene product' + (total === 1 ? '' : 's') + ' found'
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
        + buildTypeCell(item)
        + buildEcCell(item)
        + buildEncodedCell(item)
        + buildPathwayCell(item)
        + '</tr>';
    }).join('');

    return '<div class="mgdb-table-scroll" tabindex="0">'
      + '<table class="mgdb-table gp-table">'
      + '<caption>Matching gene products<span class="mgdb-muted">' + number(results.length) + ' shown</span></caption>'
      + '<thead><tr>'
      + '<th scope="col">Gene Product</th>'
      + '<th scope="col">Class</th>'
      + '<th scope="col">EC Numbers</th>'
      + '<th scope="col">Encoded By (Loci / Genes)</th>'
      + '<th scope="col">Pathways &amp; Localizations</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  function buildNameCell(item) {
    var name = item.name ? esc(item.name) : '(unnamed)';
    var link = item.url ? '<a href="' + esc(item.url) + '"><strong>' + name + '</strong></a>' : '<strong>' + name + '</strong>';
    var syns = '';
    if (item.synonyms && item.synonyms.length) {
      var filteredSyns = item.synonyms.filter(function (s) { return s.toLowerCase() !== (item.name || '').toLowerCase(); });
      if (filteredSyns.length) {
        syns = '<div class="gp-syn-list">Synonyms&#58; ' + esc(filteredSyns.slice(0, 4).join(', ')) + (filteredSyns.length > 4 ? '…' : '') + '</div>';
      }
    }
    return '<td scope="row" class="gp-name-cell">' + link + syns + '</td>';
  }

  function buildTypeCell(item) {
    var type = item.type ? esc(item.type) : 'Unclassified';
    var pillClass = 'mgdb-pill-info';
    if (type.toLowerCase() === 'enzyme') pillClass = 'mgdb-pill-ok';
    else if (type.toLowerCase().indexOf('transcription') !== -1 || type.toLowerCase().indexOf('regulatory') !== -1) pillClass = 'mgdb-pill-warn';
    return '<td><span class="mgdb-pill ' + pillClass + '">' + type + '</span></td>';
  }

  function buildEcCell(item) {
    if (!item.ec_numbers || !item.ec_numbers.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    var badges = item.ec_numbers.map(function (ecNum) {
      var url = 'https://enzyme.expasy.org/EC/' + encodeURIComponent(ecNum);
      return '<a class="gp-ec-badge" href="' + esc(url) + '" target="_blank" rel="noopener" title="View in ExPASy ENZYME">' + esc(ecNum) + '</a>';
    }).join(' ');
    return '<td>' + badges + '</td>';
  }

  function buildEncodedCell(item) {
    var loci = item.encoded_by || [];
    var geneModels = item.gene_models || [];

    if (!loci.length && !geneModels.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }

    var items = [];
    loci.forEach(function (locus) {
      items.push('<a href="' + esc(locus.url) + '"><em>' + esc(locus.name) + '</em></a>');
    });

    geneModels.slice(0, 3).forEach(function (gm) {
      items.push('<a href="/gene_center/gene/' + encodeURIComponent(gm) + '" class="mgdb-muted">' + esc(gm) + '</a>');
    });

    if (geneModels.length > 3) {
      items.push('<span class="mgdb-muted">+' + (geneModels.length - 3) + ' more</span>');
    }

    return '<td><ul class="gp-attr-list"><li>' + items.join(' &middot; ') + '</li></ul></td>';
  }

  function buildPathwayCell(item) {
    var pathways = item.pathways || [];
    var locs = item.localizations || [];

    if (!pathways.length && !locs.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }

    var html = '<ul class="gp-attr-list">';
    if (pathways.length) {
      html += '<li><strong>Pathways&#58;</strong> ' + esc(pathways.join(', ')) + '</li>';
    }
    if (locs.length) {
      html += '<li><strong>Localization&#58;</strong> ' + esc(locs.join(', ')) + '</li>';
    }
    html += '</ul>';
    return '<td>' + html + '</td>';
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

    // Check for query parameters in URL (e.g., ?term=alcohol or ?type=1835)
    var urlParams = new URLSearchParams(window.location.search);
    var hasQuery = false;
    if (urlParams.has('term') || urlParams.has('gp_term')) {
      var termVal = urlParams.get('term') || urlParams.get('gp_term');
      var termInput = byId('gp-search-term');
      if (termInput && termVal) {
        termInput.value = termVal;
        hasQuery = true;
      }
    }
    if (urlParams.has('type')) {
      var typeSelect = byId('gp-filter-type');
      if (typeSelect) {
        typeSelect.value = urlParams.get('type');
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
