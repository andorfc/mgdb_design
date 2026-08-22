/* Locus Data Center JavaScript (/data_center/locus)
 * Handles search submissions, dropdown filters, live results rendering,
 * example chips, and scrollspy navigation tabs.
 */

(function () {
  'use strict';

  var API_URL = '/search/locus/locus_search_api.php';
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
    var form = byId('locus-search-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      runSearch();
    });

    var resetBtn = byId('locus-reset-btn');
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
    var exampleBtns = document.querySelectorAll('.locus-example-btn');
    exampleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('locus-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          termInput.focus();
        }
      });
    });
  }

  function buildParams() {
    var params = new URLSearchParams();
    var term = (byId('locus-search-term') && byId('locus-search-term').value.trim()) || '';
    var type = (byId('locus-filter-type') && byId('locus-filter-type').value) || '';
    var chr = (byId('locus-filter-chr') && byId('locus-filter-chr').value) || '';
    var pheno = (byId('locus-filter-pheno') && byId('locus-filter-pheno').value) || '';

    if (term) params.set('term', term);
    if (type) params.set('type', type);
    if (chr) params.set('chromosome', chr);
    if (pheno) params.set('phenotype', pheno);

    return params;
  }

  function runSearch() {
    var params = buildParams();
    var statusEl = byId('locus-results-status');
    var notesEl = byId('locus-notes');
    var resultsEl = byId('locus-results');
    var emptyEl = byId('locus-empty');
    var exportLink = byId('locus-export-tsv');

    notesEl.innerHTML = '';
    emptyEl.hidden = true;
    exportLink.hidden = true;

    resultsEl.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching genetic loci&hellip;</div>';
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
    var statusEl = byId('locus-results-status');
    var resultsEl = byId('locus-results');
    var emptyEl = byId('locus-empty');
    var exportLink = byId('locus-export-tsv');

    var total = data.summary && data.summary.total ? data.summary.total : 0;
    var returned = data.summary && data.summary.returned ? data.summary.returned : 0;
    var elapsed = data.summary && data.summary.elapsed_ms != null ? data.summary.elapsed_ms : null;

    if (!total || !data.results || !data.results.length) {
      resultsEl.innerHTML = '';
      emptyEl.hidden = false;
      statusEl.textContent = 'No genetic loci matched your query.';
      return;
    }

    emptyEl.hidden = true;
    statusEl.textContent = number(total) + ' genetic loc' + (total === 1 ? 'us' : 'i') + ' found'
      + (returned < total ? ' (showing top ' + number(returned) + ')' : '')
      + (elapsed != null ? ' (' + elapsed + ' ms)' : '') + '.';

    resultsEl.innerHTML = buildTableHtml(data.results);
    exportLink.href = API_URL + '?' + lastQuery + '&format=tsv';
    exportLink.hidden = false;
  }

  function buildTableHtml(results) {
    var rows = results.map(function (item) {
      return '<tr>'
        + buildSymbolCell(item)
        + buildTypeCell(item)
        + buildCoordCell(item)
        + buildGeneModelCell(item)
        + buildPhenoCell(item)
        + '</tr>';
    }).join('');

    return '<div class="mgdb-table-scroll" tabindex="0">'
      + '<table class="mgdb-table locus-table">'
      + '<caption>Matching genetic loci<span class="mgdb-muted">' + number(results.length) + ' shown</span></caption>'
      + '<thead><tr>'
      + '<th scope="col">Locus Symbol &amp; Name</th>'
      + '<th scope="col">Type</th>'
      + '<th scope="col">Position / Bin</th>'
      + '<th scope="col">Mapped Gene Model(s)</th>'
      + '<th scope="col">Phenotypes &amp; Alleles</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  function buildSymbolCell(item) {
    var name = item.name ? esc(item.name) : '(unnamed)';
    var link = item.url ? '<a href="' + esc(item.url) + '"><strong><em>' + name + '</em></strong></a>' : '<strong><em>' + name + '</em></strong>';
    var fullName = item.full_name ? '<div class="locus-fullname">' + esc(item.full_name) + '</div>' : '';
    var syns = '';
    if (item.synonyms && item.synonyms.length) {
      var filtered = item.synonyms.filter(function (s) {
        return s.toLowerCase() !== (item.name || '').toLowerCase() && s.toLowerCase() !== (item.full_name || '').toLowerCase();
      });
      if (filtered.length) {
        syns = '<div class="locus-syn-list">Synonyms&#58; ' + esc(filtered.slice(0, 3).join(', ')) + (filtered.length > 3 ? '…' : '') + '</div>';
      }
    }
    return '<td scope="row" class="locus-name-cell">' + link + fullName + syns + '</td>';
  }

  function buildTypeCell(item) {
    var type = item.type ? esc(item.type) : 'Unclassified';
    var pillClass = 'mgdb-pill-info';
    if (type.toLowerCase() === 'gene') pillClass = 'mgdb-pill-ok';
    else if (type.toLowerCase() === 'qtl') pillClass = 'mgdb-pill-warn';
    return '<td><span class="mgdb-pill ' + pillClass + '">' + type + '</span></td>';
  }

  function buildCoordCell(item) {
    var items = [];
    if (item.chromosome) {
      items.push('<strong>' + esc(item.chromosome) + '</strong>');
    }
    if (item.bin) {
      items.push('<span class="mgdb-muted">Bin ' + esc(item.bin) + '</span>');
    }
    if (!items.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    return '<td>' + items.join('<br>') + '</td>';
  }

  function buildGeneModelCell(item) {
    var gms = item.gene_models || [];
    if (!gms.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    var links = gms.slice(0, 3).map(function (gm) {
      return '<a href="/gene_center/gene/' + encodeURIComponent(gm) + '">' + esc(gm) + '</a>';
    });
    if (gms.length > 3) {
      links.push('<span class="mgdb-muted">+' + (gms.length - 3) + ' more</span>');
    }
    return '<td><ul class="locus-attr-list"><li>' + links.join(' &middot; ') + '</li></ul></td>';
  }

  function buildPhenoCell(item) {
    var phenos = item.phenotypes || [];
    var alleleCount = item.allele_count || 0;

    var parts = [];
    if (phenos.length) {
      parts.push('<strong>Phenotype&#58;</strong> ' + esc(phenos.slice(0, 2).join(', ')) + (phenos.length > 2 ? '…' : ''));
    }
    if (alleleCount > 0) {
      parts.push('<span class="mgdb-muted">' + number(alleleCount) + ' known allele' + (alleleCount === 1 ? '' : 's') + '</span>');
    }

    if (!parts.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    return '<td><ul class="locus-attr-list"><li>' + parts.join('<br>') + '</li></ul></td>';
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
    if (urlParams.has('term') || urlParams.has('locus_term')) {
      var termVal = urlParams.get('term') || urlParams.get('locus_term');
      var termInput = byId('locus-search-term');
      if (termInput && termVal) {
        termInput.value = termVal;
        hasQuery = true;
      }
    }
    if (urlParams.has('type')) {
      var typeSelect = byId('locus-filter-type');
      if (typeSelect) {
        typeSelect.value = urlParams.get('type');
        hasQuery = true;
      }
    }
    if (urlParams.has('chromosome')) {
      var chrSelect = byId('locus-filter-chr');
      if (chrSelect) {
        chrSelect.value = urlParams.get('chromosome');
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
