/* Genome Center (/genome).

   The assembly table and the taxon counts are rendered server-side from the
   database, so the page is complete before this runs. This adds the charts and
   the client-side filtering over rows that are already present. */

(function () {
  'use strict';

  /* Curated historical record, not derived from the assembly table: the
     database stores a release date for only some assemblies and in
     inconsistent formats ("2008", "19-Nov-25", "1st of February 2017
     (pre-release)"), so a year-by-year count cannot be computed from it.
     The page states this next to the chart. */
  var GROWTH = [
    [2008, 1], [2009, 1], [2010, 2], [2011, 1], [2012, 1], [2013, 3], [2014, 1],
    [2015, 1], [2016, 5], [2017, 10], [2018, 15], [2019, 25], [2020, 51],
    [2021, 52], [2022, 75], [2023, 76], [2024, 101], [2025, 123], [2026, 158]
  ];

  var MILESTONES = {
    2008: 'B73',
    2015: 'W22, PH207',
    2018: 'Mo17, A188, European flints',
    2020: 'NAM founders',
    2022: 'PanAnd v1',
    2024: 'PanAnd v2',
    2026: 'Highland and lowland landraces'
  };

  function byId(id) { return document.getElementById(id); }

  function text(tag, value, className) {
    var node = document.createElement(tag);
    node.textContent = value;
    if (className) { node.className = className; }
    return node;
  }

  function buildGrowth() {
    var tbody = document.querySelector('#genome-growth-table tbody');
    if (tbody) {
      GROWTH.forEach(function (point) {
        var row = document.createElement('tr');
        var year = text('th', String(point[0]));
        year.setAttribute('scope', 'row');
        row.appendChild(year);
        row.appendChild(text('td', point[1].toLocaleString(), 'mgdb-numeric'));
        row.appendChild(text('td', MILESTONES[point[0]] || '—'));
        tbody.appendChild(row);
      });
    }

    if (!window.MGDB) { return; }

    window.MGDB.chart({
      target: 'genome-growth-chart',
      traces: function () {
        var years = GROWTH.map(function (p) { return p[0]; });
        var counts = GROWTH.map(function (p) { return p[1]; });
        var milestoneYears = Object.keys(MILESTONES).map(Number);

        return [
          {
            type: 'scatter', mode: 'lines', name: 'Assemblies hosted',
            x: years, y: counts,
            line: { color: window.MGDB.CHART_COLORS[3], width: 3 },
            fill: 'tozeroy', fillcolor: 'rgba(0,158,115,.12)',
            hovertemplate: '%{x}: %{y} assemblies<extra></extra>'
          },
          {
            // Landmarks carry a distinct symbol as well as a distinct colour so
            // they stay identifiable without colour vision.
            type: 'scatter', mode: 'markers', name: 'Landmark release',
            x: milestoneYears,
            y: milestoneYears.map(function (year) {
              var found = GROWTH.filter(function (p) { return p[0] === year; })[0];
              return found ? found[1] : null;
            }),
            marker: {
              symbol: 'diamond', size: 12, color: window.MGDB.CHART_COLORS[2],
              line: { color: '#5a3c05', width: 1.5 }
            },
            text: milestoneYears.map(function (year) { return MILESTONES[year]; }),
            hovertemplate: '%{x}: %{text}<extra></extra>'
          }
        ];
      },
      layout: {
        xaxis: { title: { text: 'Year' }, dtick: 2 },
        yaxis: { title: { text: 'Assemblies hosted' }, rangemode: 'tozero' },
        margin: { l: 76, r: 24, t: 12, b: 56 },
        legend: { orientation: 'h', y: -0.24, x: 0 }
      }
    });
  }

  function buildTaxa() {
    var node = byId('genome-group-data');
    if (!node || !window.MGDB) { return; }

    var groups;
    try {
      groups = JSON.parse(node.textContent || '[]');
    } catch (error) {
      return;
    }
    if (!groups.length) { return; }

    // Ascending so the largest bar sits at the top of a horizontal chart.
    var ordered = groups.slice().sort(function (a, b) { return a.count - b.count; });

    window.MGDB.chart({
      target: 'genome-taxa-chart',
      traces: function () {
        return [{
          type: 'bar', orientation: 'h',
          x: ordered.map(function (g) { return g.count; }),
          y: ordered.map(function (g) { return g.label; }),
          marker: { color: window.MGDB.CHART_COLORS[1], line: { color: '#123', width: 1 } },
          hovertemplate: '%{y}: %{x} assemblies<extra></extra>'
        }];
      },
      layout: {
        xaxis: { title: { text: 'Assemblies' }, rangemode: 'tozero' },
        yaxis: { automargin: true },
        showlegend: false,
        margin: { l: 230, r: 24, t: 12, b: 56 }
      }
    });
  }

  function buildFilters() {
    if (!window.MGDB) { return; }

    var rows = document.querySelectorAll('#genome-table tbody tr');
    if (!rows.length) { return; }

    var statusSelect = byId('genome-status');

    var list = window.MGDB.filterList({
      items: rows,
      input: byId('genome-query'),
      chips: document.querySelectorAll('.mgdb-chip[data-filter]'),
      count: byId('genome-count'),
      empty: byId('genome-empty'),
      reset: byId('genome-reset'),
      noun: 'assemblies',
      urlKeys: { query: 'q', filter: 'taxon' },
      filterOn: function (row, value) {
        if (value !== 'all' && row.getAttribute('data-group') !== value) { return false; }
        var status = statusSelect ? statusSelect.value : '';
        if (status && row.getAttribute('data-status') !== status) { return false; }
        return true;
      }
    });

    if (statusSelect) {
      statusSelect.addEventListener('change', function () { list.refresh(); });
    }

    var reset = byId('genome-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        if (statusSelect) { statusSelect.value = ''; }
        list.refresh();
      });
    }

    var emptyReset = byId('genome-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (statusSelect) { statusSelect.value = ''; }
        if (reset) { reset.click(); }
        else { list.refresh(); }
      });
    }
  }

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var sections = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var section = document.querySelector(tab.getAttribute('href'));
      if (section) { sections.push({ tab: tab, section: section }); }
    });

    function markCurrent(target) {
      sections.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    var initial = sections[0];
    if (window.location.hash) {
      sections.forEach(function (pair) {
        if ('#' + pair.section.id === window.location.hash) { initial = pair; }
      });
    }
    if (initial) { markCurrent(initial.section); }

    sections.forEach(function (pair) {
      pair.tab.addEventListener('click', function () { markCurrent(pair.section); });
    });

    if (!window.IntersectionObserver) { return; }

    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { markCurrent(entry.target); }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(function (pair) { observer.observe(pair.section); });
  }

  function init() {
    buildFilters();
    buildGrowth();
    buildTaxa();
    buildTabs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
