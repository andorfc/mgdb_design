/* MaizeGDB Genomes page.

   Data below reproduces the figures supplied for the redesign. In production
   the assembly table and the collection counts would come from the assembly
   database; the growth series is a curated historical record and would more
   likely stay in a small server-rendered payload. */

(function () {
  'use strict';

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

  var COLLECTIONS = [
    { name: 'PanAnd (Andropogoneae)', count: 54, key: 'PanAnd',
      description: 'Wild relatives across the grass tribe, including teosintes and Tripsacum.' },
    { name: 'Highland and lowland', count: 40, key: 'Highland/Lowland',
      description: 'Adaptation panel of highland and lowland maize landraces, added in 2026.' },
    { name: 'Other Zea mays inbreds', count: 29, key: 'Inbred',
      description: 'W22, PH207, Mo17, A188, and further temperate and tropical inbreds.' },
    { name: 'NAM founders', count: 26, key: 'NAM founders',
      description: 'The 26 nested association mapping parent lines.' },
    { name: 'B73 reference versions', count: 5, key: 'Reference',
      description: 'RefGen_v1 through v5, the community reference lineage.' },
    { name: 'European flint', count: 4, key: 'European flint',
      description: 'EP1, F7, DK105, and related flint germplasm.' }
  ];

  var ASSEMBLIES = [
    ['B73 RefGen_v5', 'B73', 'Zea mays mays', 'Reference', 'Gold · chromosome', 2020, 'Current'],
    ['B73 RefGen_v4', 'B73', 'Zea mays mays', 'Reference', 'Chromosome', 2017, 'Superseded'],
    ['B73 RefGen_v3', 'B73', 'Zea mays mays', 'Reference', 'Chromosome', 2013, 'Legacy'],
    ['B73 RefGen_v2', 'B73', 'Zea mays mays', 'Reference', 'Scaffold', 2010, 'Legacy'],
    ['B73 RefGen_v1', 'B73', 'Zea mays mays', 'Reference', 'Draft', 2009, 'Legacy'],
    ['W22', 'W22', 'Zea mays mays', 'Inbred', 'Chromosome', 2018, 'Current'],
    ['PH207', 'PH207', 'Zea mays mays', 'Inbred', 'Chromosome', 2016, 'Current'],
    ['Mo17 (CAU)', 'Mo17', 'Zea mays mays', 'Inbred', 'Chromosome', 2018, 'Current'],
    ['A188', 'A188', 'Zea mays mays', 'Inbred', 'Chromosome', 2020, 'Current'],
    ['CML247', 'CML247', 'Zea mays mays', 'NAM founders', 'Chromosome', 2020, 'Current'],
    ['Ki3', 'Ki3', 'Zea mays mays', 'NAM founders', 'Chromosome', 2020, 'Current'],
    ['Oh43', 'Oh43', 'Zea mays mays', 'NAM founders', 'Chromosome', 2020, 'Current'],
    ['Tzi8', 'Tzi8', 'Zea mays mays', 'NAM founders', 'Chromosome', 2020, 'Current'],
    ['P39', 'P39', 'Zea mays mays', 'NAM founders', 'Chromosome', 2020, 'Current'],
    ['NC350', 'NC350', 'Zea mays mays', 'NAM founders', 'Chromosome', 2020, 'Current'],
    ['EP1', 'EP1', 'Zea mays mays', 'European flint', 'Chromosome', 2018, 'Current'],
    ['F7', 'F7', 'Zea mays mays', 'European flint', 'Chromosome', 2018, 'Current'],
    ['DK105', 'DK105', 'Zea mays mays', 'European flint', 'Chromosome', 2019, 'Current'],
    ['Zea mays parviglumis', 'TIL01', 'Zea mays parviglumis', 'PanAnd', 'Chromosome', 2022, 'Current'],
    ['Zea diploperennis', 'Zdip', 'Zea diploperennis', 'PanAnd', 'Chromosome', 2022, 'Current'],
    ['Zea luxurians', 'Zlux', 'Zea luxurians', 'PanAnd', 'Chromosome', 2022, 'Current'],
    ['Tripsacum dactyloides', 'Tdac', 'Tripsacum dactyloides', 'PanAnd', 'Chromosome', 2023, 'Current'],
    ['Andropogon gerardii', 'Ager', 'Andropogon gerardii', 'PanAnd', 'Chromosome', 2023, 'Current'],
    ['Sorghum bicolor', 'BTx623', 'Sorghum bicolor', 'PanAnd', 'Chromosome', 2024, 'Current'],
    ['Palomero Toluqueño', 'Palomero', 'Zea mays mays', 'Highland/Lowland', 'Chromosome', 2026, 'New'],
    ['Tuxpeño', 'Tuxpeno', 'Zea mays mays', 'Highland/Lowland', 'Chromosome', 2026, 'New'],
    ['Conico', 'Conico', 'Zea mays mays', 'Highland/Lowland', 'Chromosome', 2026, 'New'],
    ['Tabloncillo', 'Tabloncillo', 'Zea mays mays', 'Highland/Lowland', 'Chromosome', 2026, 'New']
  ].map(function (row) {
    return {
      name: row[0], line: row[1], species: row[2], collection: row[3],
      quality: row[4], year: row[5], status: row[6]
    };
  });

  /* Status is conveyed by the pill's text, with the tone as reinforcement. */
  var STATUS_TONE = {
    Current: 'mgdb-pill-ok',
    New: 'mgdb-pill-ok',
    Superseded: 'mgdb-pill-info',
    Legacy: 'mgdb-pill-warn'
  };

  function byId(id) { return document.getElementById(id); }

  function text(tag, value, className) {
    var node = document.createElement(tag);
    node.textContent = value;
    if (className) { node.className = className; }
    return node;
  }

  /* ---- growth chart + its data table ---- */

  function buildGrowth() {
    var tbody = document.querySelector('#genomes-growth-table tbody');
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
      target: 'genomes-growth-chart',
      traces: function () {
        var years = GROWTH.map(function (p) { return p[0]; });
        var counts = GROWTH.map(function (p) { return p[1]; });
        var milestoneYears = Object.keys(MILESTONES).map(Number);

        return [
          {
            type: 'scatter', mode: 'lines', name: 'Assemblies hosted',
            x: years, y: counts,
            line: { color: window.MGDB.CHART_COLORS[3], width: 3, shape: 'linear' },
            fill: 'tozeroy', fillcolor: 'rgba(0,158,115,.12)',
            hovertemplate: '%{x}: %{y} assemblies<extra></extra>'
          },
          {
            // Landmark releases carry a distinct symbol as well as a distinct
            // colour, so they remain identifiable without colour vision.
            type: 'scatter', mode: 'markers', name: 'Landmark release',
            x: milestoneYears,
            y: milestoneYears.map(function (year) {
              var found = GROWTH.filter(function (p) { return p[0] === year; })[0];
              return found ? found[1] : null;
            }),
            marker: { symbol: 'diamond', size: 12, color: window.MGDB.CHART_COLORS[2],
                      line: { color: '#5a3c05', width: 1.5 } },
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

  /* ---- collections ---- */

  function buildCollections() {
    var cards = byId('genomes-collection-cards');
    if (cards) {
      COLLECTIONS.forEach(function (collection) {
        var card = document.createElement('article');
        card.className = 'mgdb-card mgdb-collection-card';
        card.appendChild(text('h3', collection.name));
        var count = text('p', collection.count + ' assemblies', 'mgdb-collection-count');
        card.appendChild(count);
        card.appendChild(text('p', collection.description));
        cards.appendChild(card);
      });
    }

    if (!window.MGDB) { return; }

    var ordered = COLLECTIONS.slice().sort(function (a, b) { return a.count - b.count; });

    window.MGDB.chart({
      target: 'genomes-collections-chart',
      traces: function () {
        return [{
          type: 'bar', orientation: 'h',
          x: ordered.map(function (c) { return c.count; }),
          y: ordered.map(function (c) { return c.name; }),
          marker: { color: window.MGDB.CHART_COLORS[1], line: { color: '#123', width: 1 } },
          hovertemplate: '%{y}: %{x} assemblies<extra></extra>'
        }];
      },
      layout: {
        xaxis: { title: { text: 'Assemblies' }, rangemode: 'tozero' },
        yaxis: { automargin: true },
        showlegend: false,
        margin: { l: 200, r: 24, t: 12, b: 56 }
      }
    });
  }

  /* ---- assembly explorer ---- */

  function buildTable() {
    var tbody = document.querySelector('#genomes-table tbody');
    var collectionSelect = byId('genomes-collection');
    if (!tbody || !collectionSelect) { return; }

    var seen = {};
    ASSEMBLIES.forEach(function (assembly) {
      if (!seen[assembly.collection]) {
        seen[assembly.collection] = true;
        var option = document.createElement('option');
        option.value = assembly.collection;
        option.textContent = assembly.collection;
        collectionSelect.appendChild(option);
      }

      var row = document.createElement('tr');
      row.setAttribute('data-collection', assembly.collection);
      row.setAttribute('data-status', assembly.status);
      row.setAttribute('data-search', [assembly.name, assembly.line, assembly.species, assembly.collection].join(' '));

      var name = text('th', assembly.name);
      name.setAttribute('scope', 'row');
      row.appendChild(name);
      row.appendChild(text('td', assembly.line));

      var species = document.createElement('td');
      var italic = text('i', assembly.species);
      species.appendChild(italic);
      row.appendChild(species);

      row.appendChild(text('td', assembly.collection));
      row.appendChild(text('td', assembly.quality));

      var year = text('td', String(assembly.year), 'mgdb-numeric');
      year.setAttribute('data-value', String(assembly.year));
      row.appendChild(year);

      var status = document.createElement('td');
      status.appendChild(text('span', assembly.status, 'mgdb-pill ' + (STATUS_TONE[assembly.status] || 'mgdb-pill-info')));
      row.appendChild(status);

      tbody.appendChild(row);
    });

    if (!window.MGDB) { return; }

    var statusSelect = byId('genomes-status');

    var list = window.MGDB.filterList({
      items: tbody.querySelectorAll('tr'),
      input: byId('genomes-query'),
      count: byId('genomes-count'),
      empty: byId('genomes-empty'),
      reset: byId('genomes-reset'),
      noun: 'assemblies',
      urlKeys: { query: 'q' },
      filterOn: function (row) {
        var wantCollection = collectionSelect.value;
        var wantStatus = statusSelect ? statusSelect.value : '';
        if (wantCollection && row.getAttribute('data-collection') !== wantCollection) { return false; }
        if (wantStatus && row.getAttribute('data-status') !== wantStatus) { return false; }
        return true;
      }
    });

    // The two selects are additional filters outside filterList's chip model,
    // so re-run the filter when either changes.
    [collectionSelect, statusSelect].forEach(function (select) {
      if (select) { select.addEventListener('change', function () { list.refresh(); }); }
    });

    var resetButton = byId('genomes-reset');
    if (resetButton) {
      resetButton.addEventListener('click', function () {
        collectionSelect.value = '';
        if (statusSelect) { statusSelect.value = ''; }
        list.refresh();
      });
    }

    var emptyReset = byId('genomes-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        var query = byId('genomes-query');
        if (query) { query.value = ''; }
        collectionSelect.value = '';
        if (statusSelect) { statusSelect.value = ''; }
        list.refresh();
        if (query) { query.focus(); }
      });
    }
  }

  /* ---- section tabs ---- */

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

    // Start on the section named in the URL, else the first one, so the tab
    // strip always shows a sensible state even where scroll tracking never runs.
    var initial = sections[0];
    if (window.location.hash) {
      sections.forEach(function (pair) {
        if ('#' + pair.section.id === window.location.hash) { initial = pair; }
      });
    }
    if (initial) { markCurrent(initial.section); }

    // Clicking a tab marks it immediately rather than waiting for the observer.
    sections.forEach(function (pair) {
      pair.tab.addEventListener('click', function () { markCurrent(pair.section); });
    });

    if (!window.IntersectionObserver) { return; }

    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        // aria-current marks the section in view for assistive technology.
        if (entry.isIntersecting) { markCurrent(entry.target); }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(function (pair) { observer.observe(pair.section); });
  }

  function init() {
    buildTable();
    buildGrowth();
    buildCollections();
    buildTabs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
