/**
 * file: js/mgdb-expression.js
 *
 * purpose: AJAX search, example launchers, and scrollspy for Expression Data Center
 */

(function () {
  'use strict';

  var form = document.getElementById('expression-search-form');
  var termInput = document.getElementById('expression-search-term');
  var assemblySelect = document.getElementById('expression-filter-assembly');
  var resetBtn = document.getElementById('expression-reset-btn');
  var exportBtn = document.getElementById('expression-export-btn');
  var resultsContainer = document.getElementById('expression-results-container');
  var resultsCount = document.getElementById('expression-results-count');
  var resultsActions = document.getElementById('expression-results-actions');

  var currentController = null;
  var debounceTimer = null;

  function init() {
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        runSearch();
      });
    }

    if (termInput) {
      termInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          if (termInput.value.trim().length >= 2) {
            runSearch();
          } else if (termInput.value.trim().length === 0) {
            clearResults();
          }
        }, 350);
      });
    }

    if (assemblySelect) {
      assemblySelect.addEventListener('change', function () {
        if (termInput && termInput.value.trim().length > 0) {
          runSearch();
        }
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        setTimeout(function () {
          clearResults();
        }, 10);
      });
    }

    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        var term = termInput ? termInput.value.trim() : '';
        var assembly = assemblySelect ? assemblySelect.value : '';
        var url = '/search/expression/expression_search_api.php?format=tsv'
          + '&term=' + encodeURIComponent(term)
          + '&assembly=' + encodeURIComponent(assembly);
        window.location.href = url;
      });
    }

    // Example button triggers
    var exampleBtns = document.querySelectorAll('.expression-example-btn');
    exampleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var term = btn.getAttribute('data-term');
        if (termInput) {
          termInput.value = term;
          runSearch();
          var resultsSec = document.getElementById('expression-results-section');
          if (resultsSec) {
            resultsSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    });

    initScrollSpy();
  }

  function clearResults() {
    if (resultsContainer) {
      resultsContainer.innerHTML = '<div class="expression-empty-state"><p>Enter a gene model ID or locus symbol above to view expression profiles, qTeller data, and browser tracks.</p></div>';
    }
    if (resultsCount) {
      resultsCount.textContent = 'Search to view results';
      resultsCount.classList.remove('has-results');
    }
    if (resultsActions) {
      resultsActions.style.display = 'none';
    }
  }

  function runSearch() {
    var term = termInput ? termInput.value.trim() : '';
    var assembly = assemblySelect ? assemblySelect.value : '';

    if (!term && !assembly) {
      clearResults();
      return;
    }

    if (currentController) {
      currentController.abort();
    }
    currentController = new AbortController();

    if (resultsCount) {
      resultsCount.textContent = 'Searching...';
      resultsCount.classList.remove('has-results');
    }

    if (resultsContainer) {
      resultsContainer.innerHTML = '<div class="expression-empty-state"><p>Loading matching expression models...</p></div>';
    }

    var url = '/search/expression/expression_search_api.php?'
      + 'term=' + encodeURIComponent(term)
      + '&assembly=' + encodeURIComponent(assembly)
      + '&limit=100';

    fetch(url, { signal: currentController.signal })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data.ok) {
          renderError(data.error || 'Failed to search expression data');
          return;
        }
        renderResults(data);
      })
      .catch(function (err) {
        if (err.name !== 'AbortError') {
          renderError('Error querying expression database: ' + err.message);
        }
      });
  }

  function renderError(msg) {
    if (resultsContainer) {
      resultsContainer.innerHTML = '<div class="expression-empty-state" style="color:#b91c1c;"><p>' + escapeHtml(msg) + '</p></div>';
    }
    if (resultsCount) {
      resultsCount.textContent = 'Error';
      resultsCount.classList.remove('has-results');
    }
    if (resultsActions) {
      resultsActions.style.display = 'none';
    }
  }

  function renderResults(data) {
    var total = data.summary ? data.summary.total : 0;
    var results = data.results || [];

    if (resultsCount) {
      resultsCount.textContent = total.toLocaleString() + ' matching model' + (total === 1 ? '' : 's');
      if (total > 0) {
        resultsCount.classList.add('has-results');
      } else {
        resultsCount.classList.remove('has-results');
      }
    }

    if (resultsActions) {
      resultsActions.style.display = total > 0 ? 'block' : 'none';
    }

    if (results.length === 0) {
      resultsContainer.innerHTML = '<div class="expression-empty-state"><p>No gene models found matching your query. Try searching by classic symbol like <code>adh1</code> or gene ID <code>Zm00001eb056510</code>.</p></div>';
      return;
    }

    var html = '<div class="expression-table-wrap"><table class="expression-table"><thead><tr>';
    html += '<th>Gene Model</th>';
    html += '<th>Assembly</th>';
    html += '<th>Mapped Locus</th>';
    html += '<th>Coordinates</th>';
    html += '<th>Expression Tools &amp; Actions</th>';
    html += '</tr></thead><tbody>';

    for (var i = 0; i < results.length; i++) {
      var r = results[i];
      html += '<tr>';

      // Gene Model
      html += '<td><a class="expression-gene-link" href="' + escapeHtml(r.gene_center_url) + '">' + escapeHtml(r.gene_name) + '</a></td>';

      // Assembly
      html += '<td><span class="expression-asm-pill">' + escapeHtml(r.assembly_version || 'Reference') + '</span></td>';

      // Mapped Locus
      var locusStr = r.locus_name ? ('<strong>' + escapeHtml(r.locus_name) + '</strong>') : '&mdash;';
      if (r.locus_full_name) {
        locusStr += '<br><small style="color:#6b7280;">' + escapeHtml(r.locus_full_name) + '</small>';
      }
      html += '<td>' + locusStr + '</td>';

      // Coordinates
      html += '<td><small>' + escapeHtml(r.coordinates || '&mdash;') + '</small></td>';

      // Action buttons
      html += '<td><div class="expression-actions-cell">';
      if (r.qteller_url) {
        html += '<a class="expression-tool-btn expression-tool-btn-qteller" href="' + escapeHtml(r.qteller_url) + '" target="_blank" rel="noopener">qTeller</a>';
      }
      html += '<a class="expression-tool-btn expression-tool-btn-gene" href="' + escapeHtml(r.gene_center_url) + '">Gene Profile</a>';
      if (r.jbrowse_url) {
        html += '<a class="expression-tool-btn expression-tool-btn-jbrowse" href="' + escapeHtml(r.jbrowse_url) + '" target="_blank" rel="noopener">JBrowse RNA-seq</a>';
      }
      if (r.efp_url && r.gene_name.indexOf('GRMZM') !== -1) {
        html += '<a class="expression-tool-btn" style="background:#fce7f3; color:#9d174d; border-color:#fbcfe8;" href="' + escapeHtml(r.efp_url) + '" target="_blank" rel="noopener">eFP Browser</a>';
      }
      html += '<a class="expression-tool-btn expression-tool-btn-feta" href="https://feta.maizegdb.org/" target="_blank" rel="noopener">FETA</a>';
      html += '</div></td>';

      html += '</tr>';
    }

    html += '</tbody></table></div>';
    resultsContainer.innerHTML = html;
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

  function initScrollSpy() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) return;

    var sections = [];
    tabs.forEach(function (tab) {
      var id = tab.getAttribute('href');
      if (id && id.startsWith('#')) {
        var el = document.querySelector(id);
        if (el) sections.push({ tab: tab, el: el });
      }
    });

    if (!sections.length || !('IntersectionObserver' in window)) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          tabs.forEach(function (t) { t.classList.remove('is-current'); });
          var match = sections.find(function (s) { return s.el === entry.target; });
          if (match) {
            match.tab.classList.add('is-current');
          }
        }
      });
    }, { rootMargin: '-20% 0px -60% 0px' });

    sections.forEach(function (s) {
      observer.observe(s.el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
