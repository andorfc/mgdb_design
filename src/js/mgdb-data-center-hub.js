/* MaizeGDB Data Hubs JavaScript
   Handles interactive directory search & filtering, Plotly charts rendering,
   and sticky section tabs with scrollspy navigation. */

(function () {
  'use strict';

  var activeCategory = 'all';

  function byId(id) { return document.getElementById(id); }
  function normalize(value) { return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim(); }

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

  /* ── Directory Search & Filter ──────────────────────────────────────────── */

  function updateDirectory() {
    var queryInput = byId('data-hub-query');
    var query = queryInput ? normalize(queryInput.value) : '';
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-hub-center]'));
    var visible = 0;

    cards.forEach(function (card) {
      var categoryMatch = activeCategory === 'all' || card.getAttribute('data-category') === activeCategory;
      var textMatch = !query || query.split(' ').every(function (term) {
        return normalize(card.getAttribute('data-search') + ' ' + card.textContent).indexOf(term) !== -1;
      });
      card.hidden = !(categoryMatch && textMatch);
      if (!card.hidden) visible += 1;
    });

    var countEl = byId('data-hub-result-count');
    if (countEl) {
      countEl.textContent = visible + (visible === 1 ? ' center shown' : ' centers shown');
    }

    var emptyEl = byId('data-hub-empty');
    if (emptyEl) {
      emptyEl.hidden = visible !== 0;
    }

    var clearBtn = byId('data-hub-query-clear');
    if (clearBtn) {
      clearBtn.hidden = !query;
    }
  }

  function setCategory(category) {
    activeCategory = category || 'all';
    document.querySelectorAll('.data-hub-filter-btn').forEach(function (button) {
      var active = button.getAttribute('data-hub-filter') === activeCategory;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    updateDirectory();
  }

  function resetDirectory() {
    var input = byId('data-hub-query');
    if (input) {
      input.value = '';
      input.focus();
    }
    setCategory('all');
  }

  /* ── Plotly Visualizations ──────────────────────────────────────────────── */

  function chartLayout() {
    return {
      paper_bgcolor: 'rgba(0,0,0,0)',
      plot_bgcolor: 'rgba(0,0,0,0)',
      font: { family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', size: 11, color: '#3d4d42' },
      hoverlabel: { bgcolor: '#ffffff', bordercolor: '#c3d5c6', font: { size: 12, color: '#173824' } },
      showlegend: false
    };
  }

  function renderCharts() {
    if (!window.Plotly) return;

    document.querySelectorAll('.data-hub-chart-fallback').forEach(function (fallback) {
      fallback.remove();
    });

    var config = { displayModeBar: false, responsive: true };
    var metricCards = Array.prototype.slice.call(document.querySelectorAll('[data-hub-metric]'));

    var metrics = metricCards.map(function (card) {
      return {
        label: card.getAttribute('data-chart-label'),
        value: Number(card.getAttribute('data-chart-value'))
      };
    }).filter(function (m) { return m.value > 0; }).sort(function (a, b) { return a.value - b.value; });

    // 1. Horizontal Log Bar Chart
    var scaleContainer = byId('data-hub-scale-chart');
    if (scaleContainer && metrics.length) {
      var scaleLayout = chartLayout();
      scaleLayout.margin = { l: 140, r: 24, t: 10, b: 40 };
      scaleLayout.xaxis = {
        type: 'log',
        dtick: 1,
        title: 'Collection size (logarithmic scale)',
        fixedrange: true,
        gridcolor: '#e5eee7',
        zerolinecolor: '#c3d5c6'
      };
      scaleLayout.yaxis = { fixedrange: true, automargin: true };

      Plotly.react('data-hub-scale-chart', [{
        type: 'bar',
        orientation: 'h',
        y: metrics.map(function (row) { return row.label; }),
        x: metrics.map(function (row) { return row.value; }),
        text: metrics.map(function (row) { return row.value.toLocaleString('en-US'); }),
        textposition: 'none',
        marker: {
          color: '#31613d',
          line: { color: '#235c37', width: 1 }
        },
        hovertemplate: '<b>%{y}</b><br>%{x:,} records<extra></extra>'
      }], scaleLayout, config);
    }

    // 2. Domain Coverage Donut Chart
    var domainContainer = byId('data-hub-domain-chart');
    if (domainContainer) {
      var labels = {
        'genomes-variation': 'Genomes & variation',
        'genes-function': 'Genes & function',
        'phenotype-germplasm': 'Phenotype & germplasm',
        'literature-media': 'Literature & media'
      };

      var domainCounts = {};
      document.querySelectorAll('[data-hub-center]').forEach(function (card) {
        var category = card.getAttribute('data-category');
        domainCounts[category] = (domainCounts[category] || 0) + 1;
      });

      var domainKeys = Object.keys(labels);
      var domainLayout = chartLayout();
      domainLayout.margin = { l: 10, r: 10, t: 10, b: 40 };
      domainLayout.showlegend = true;
      domainLayout.legend = {
        orientation: 'h',
        x: 0.5,
        xanchor: 'center',
        y: -0.12,
        font: { size: 10 }
      };

      Plotly.react('data-hub-domain-chart', [{
        type: 'pie',
        hole: 0.55,
        labels: domainKeys.map(function (key) { return labels[key]; }),
        values: domainKeys.map(function (key) { return domainCounts[key] || 0; }),
        marker: {
          colors: ['#235c37', '#1b4d75', '#d99a0b', '#156d64']
        },
        textinfo: 'value',
        textfont: { size: 12, color: '#fff' },
        hovertemplate: '<b>%{label}</b><br>%{value} data hubs (%{percent})<extra></extra>'
      }], domainLayout, config);
    }
  }

  /* ── Bootstrap ──────────────────────────────────────────────────────────── */

  function initialize() {
    buildTabs();

    var queryInput = byId('data-hub-query');
    if (queryInput) {
      queryInput.addEventListener('input', updateDirectory);
    }

    var clearBtn = byId('data-hub-query-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (queryInput) {
          queryInput.value = '';
          queryInput.focus();
        }
        updateDirectory();
      });
    }

    var resetBtn = byId('data-hub-reset');
    if (resetBtn) {
      resetBtn.addEventListener('click', resetDirectory);
    }

    document.querySelectorAll('.data-hub-filter-btn').forEach(function (button) {
      button.addEventListener('click', function () {
        setCategory(button.getAttribute('data-hub-filter'));
      });
    });

    setCategory('all');
    renderCharts();

    window.addEventListener('resize', function () {
      if (window.Plotly) {
        var scaleEl = byId('data-hub-scale-chart');
        if (scaleEl) Plotly.Plots.resize(scaleEl);
        var domainEl = byId('data-hub-domain-chart');
        if (domainEl) Plotly.Plots.resize(domainEl);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
