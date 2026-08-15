/* ==========================================================================
   Protein domain atlas (/projects/interpro_domain_atlas) — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-projects.css and
   templates/static/mgdb_project_interpro_domain_atlas.bau.

   Two jobs, in that order of importance:

     1. filtering the two large tables, over rows already rendered server-side
     2. drawing five charts from the analysis payload

   Every value in a chart here also appears in a server-rendered table in the
   same section, so a reader with scripting off, or one whose fetch fails, is
   not missing data — only the picture of it. That is why the payload is
   fetched rather than embedded: it costs nothing when it fails.

   The payload carries two measures that must never be combined. Functions
   below are named for the measure they read: inclusive counts come from
   counts_*, exclusive immunity calls from immunity_* and
   immunity_subclass_*. Nothing mixes them.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var COLORS  = MGDB.CHART_COLORS;
  var SYMBOLS = MGDB.CHART_SYMBOLS;

  /* Ontology groups, in the order used everywhere on the page. Each gets its
     own colour and its own marker shape, so the scatter plots do not depend on
     colour alone. */
  var GROUP_ORDER = [
    'Immunity', 'Transcription factor', 'Hormone signaling', 'Metabolism',
    'Transport', 'Protein fate', 'DNA/chromosome', 'RNA'
  ];

  /* Exclusive immunity classes, in precedence order. */
  var IMMUNITY_ORDER = ['NLR', 'NLR_partial', 'RLK', 'RLP', 'PR', 'IMMUNE_SIGNALING', 'IMMUNE_OTHER'];
  var IMMUNITY_LABEL = {
    NLR: 'NLR',
    NLR_partial: 'NLR, partial',
    RLK: 'Receptor kinase',
    RLP: 'Receptor-like protein',
    PR: 'PR / defense',
    IMMUNE_SIGNALING: 'Immune signaling',
    IMMUNE_OTHER: 'Other immune'
  };

  var NLR_ORDER = ['CNL', 'TNL', 'RNL', 'NL', 'N_only'];
  var NLR_LABEL = { CNL: 'CNL', TNL: 'TNL', RNL: 'RNL', NL: 'NL', N_only: 'N only' };

  /* Row order for anything plotted per genome. Matches the server-rendered
     tables, so a reader moving between a chart and its table is looking at the
     same sequence. */
  var TAXON_RANK = {
    'maize': 0, 'teosinte': 1, 'wild Zea': 2, 'Tripsacum': 3,
    'Andropogon': 4, 'Sorghum': 5, 'cereal outgroup (BOP)': 6, 'dicot outgroup': 7
  };

  var B73 = 'Zm-B73-REFERENCE-NAM-5.0';

  /* Legend placement.

     The shared default sits the legend below the plot at y: -0.22, which works
     for a figure with short category labels. These charts carry rotated
     40-character assembly names under the x axis, so a legend down there lands
     on top of them or is pushed off the figure entirely. Above the plot and
     right-aligned is clear of both the axis furniture and the y-axis title, and
     Plotly expands the top margin itself if the entries wrap to a second row. */
  var LEGEND_TOP = {
    orientation: 'h',
    x: 1, xanchor: 'right',
    y: 1.02, yanchor: 'bottom',
    font: { size: 12 },
    bgcolor: 'rgba(255, 255, 255, 0)'
  };

  function byId(id) { return document.getElementById(id); }

  function taxonRank(taxon) {
    return Object.prototype.hasOwnProperty.call(TAXON_RANK, taxon) ? TAXON_RANK[taxon] : 9;
  }

  function sortGenomes(names, genomes) {
    return names.slice().sort(function (a, b) {
      if (a === B73) { return -1; }
      if (b === B73) { return 1; }
      var ra = taxonRank((genomes[a] || {}).taxon);
      var rb = taxonRank((genomes[b] || {}).taxon);
      if (ra !== rb) { return ra - rb; }
      return a.toLowerCase() < b.toLowerCase() ? -1 : (a.toLowerCase() > b.toLowerCase() ? 1 : 0);
    });
  }

  function mean(values) {
    if (!values.length) { return 0; }
    var total = 0;
    for (var i = 0; i < values.length; i++) { total += values[i]; }
    return total / values.length;
  }

  function median(values) {
    if (!values.length) { return 0; }
    var sorted = values.slice().sort(function (a, b) { return a - b; });
    var mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
  }

  /* Class names run to 30 characters. Full names stay in the hover text; the
     axis gets a short form so the labels do not overlap. */
  function shortClass(name) {
    return String(name).replace(/^Immunity: /, '').replace(/^TF: /, '').replace(/^Hormone: /, '');
  }

  /* ------------------------------------------------------------------------
     Filtering — runs whether or not the payload ever arrives
     ------------------------------------------------------------------------ */

  function chipsIn(sectionId) {
    var section = byId(sectionId);
    return section ? section.querySelectorAll('.mgdb-chip[data-filter]') : [];
  }

  function wireVarianceTable() {
    var table = byId('pd-variance-table');
    if (!table || !table.tBodies[0]) { return; }

    MGDB.filterList({
      items: table.tBodies[0].rows,
      input: byId('pd-variance-query'),
      chips: chipsIn('pd-variance'),
      count: byId('pd-variance-count'),
      empty: byId('pd-variance-empty'),
      reset: byId('pd-variance-reset'),
      noun: 'classes',
      urlKeys: { query: 'class', filter: 'group' },
      /* Rows carry their ontology group as data-group, which is what the row
         means, rather than the data-filter the shared default looks for. */
      filterOn: function (row, value) {
        return value === 'all' || row.getAttribute('data-group') === value;
      }
    });

    var emptyReset = byId('pd-variance-empty-reset');
    var reset = byId('pd-variance-reset');
    if (emptyReset && reset) {
      emptyReset.addEventListener('click', function () { reset.click(); });
    }
  }

  function wireMatrix() {
    var table = byId('pd-matrix-table');
    if (!table) { return; }

    /* The matrix has one row group per annotation arm, so the rows to filter
       are gathered across all of them rather than from tBodies[0]. The arm
       banner rows are excluded: they are headings, not data. */
    var rows = table.querySelectorAll('tbody tr[data-arm]');
    if (!rows.length) { return; }

    var banners = table.querySelectorAll('.pd-matrix-arm-row');

    MGDB.filterList({
      items: rows,
      input: byId('pd-matrix-query'),
      chips: chipsIn('pd-matrix'),
      count: byId('pd-matrix-count'),
      empty: byId('pd-matrix-empty'),
      reset: byId('pd-matrix-reset'),
      noun: 'genomes',
      urlKeys: { query: 'genome', filter: 'arm' },
      filterOn: function (row, value) {
        return value === 'all' || row.getAttribute('data-arm') === value;
      },
      /* An arm banner with no rows left under it would read as an empty
         result set for that arm rather than as one that was filtered out. */
      onChange: function () {
        Array.prototype.forEach.call(banners, function (banner) {
          var body = banner.parentNode;
          var visible = false;
          Array.prototype.forEach.call(body.querySelectorAll('tr[data-arm]'), function (row) {
            if (!row.hidden) { visible = true; }
          });
          banner.hidden = !visible;
        });
      }
    });

    var emptyReset = byId('pd-matrix-empty-reset');
    var reset = byId('pd-matrix-reset');
    if (emptyReset && reset) {
      emptyReset.addEventListener('click', function () { reset.click(); });
    }
  }

  /* ------------------------------------------------------------------------
     Charts
     ------------------------------------------------------------------------ */

  /* 1. Mean copy number against variability, one trace per ontology group.
        INCLUSIVE measure: statistics come from class_stats, which is computed
        over the maize genomes of the curated arm. */
  function chartVariance(data) {
    var stats = data.class_stats || {};

    MGDB.chart({
      target: 'pd-chart-variance',
      traces: function () {
        var byGroup = {};
        Object.keys(stats).forEach(function (name) {
          var s = stats[name];
          var group = s.group || 'Other';
          if (!byGroup[group]) { byGroup[group] = { x: [], y: [], text: [] }; }
          byGroup[group].x.push(s.maize_mean);
          byGroup[group].y.push(s.maize_cv);
          byGroup[group].text.push(name);
        });

        var groups = GROUP_ORDER.filter(function (g) { return byGroup[g]; })
          .concat(Object.keys(byGroup).filter(function (g) { return GROUP_ORDER.indexOf(g) === -1; }));

        return groups.map(function (group, index) {
          var bucket = byGroup[group];
          return {
            type: 'scatter',
            mode: 'markers',
            name: group,
            x: bucket.x,
            y: bucket.y,
            text: bucket.text,
            marker: {
              size: 11,
              color: COLORS[index % COLORS.length],
              symbol: SYMBOLS[index % SYMBOLS.length],
              line: { width: 1, color: '#ffffff' }
            },
            hovertemplate: '%{text}<br>mean %{x:.0f} genes<br>CV %{y:.3f}<extra></extra>'
          };
        });
      },
      layout: {
        xaxis: { title: { text: 'Mean gene count in maize' }, type: 'log' },
        yaxis: { title: { text: 'Coefficient of variation' } },
        margin: { l: 70, r: 20, t: 72, b: 62 },
        legend: LEGEND_TOP
      }
    });
  }

  /* 2. Curated against Helixer, one point per class.
        Both axes are INCLUSIVE class means, but from different annotation
        arms — which is the whole point of the plot, and why the arms are
        never averaged into a single number anywhere else. */
  function chartArms(data) {
    var reference = data.counts_reference || {};
    var helixer   = data.counts_helixer || {};

    MGDB.chart({
      target: 'pd-chart-arms',
      traces: function () {
        var refGenomes = Object.keys(reference);
        var helGenomes = Object.keys(helixer);
        if (!refGenomes.length || !helGenomes.length) { return []; }

        var classes = Object.keys(data.class_groups || {});
        var byGroup = {};
        var extent = 0;

        classes.forEach(function (name) {
          var refMean = mean(refGenomes.map(function (g) { return reference[g][name] || 0; }));
          var helMean = mean(helGenomes.map(function (g) { return helixer[g][name] || 0; }));
          if (!refMean && !helMean) { return; }

          var group = (data.class_groups[name]) || 'Other';
          if (!byGroup[group]) { byGroup[group] = { x: [], y: [], text: [] }; }
          byGroup[group].x.push(refMean);
          byGroup[group].y.push(helMean);
          byGroup[group].text.push(name);
          extent = Math.max(extent, refMean, helMean);
        });

        var groups = GROUP_ORDER.filter(function (g) { return byGroup[g]; });
        var traces = groups.map(function (group, index) {
          var bucket = byGroup[group];
          return {
            type: 'scatter',
            mode: 'markers',
            name: group,
            x: bucket.x,
            y: bucket.y,
            text: bucket.text,
            marker: {
              size: 10,
              color: COLORS[index % COLORS.length],
              symbol: SYMBOLS[index % SYMBOLS.length],
              line: { width: 1, color: '#ffffff' }
            },
            hovertemplate: '%{text}<br>curated %{x:.0f}<br>Helixer %{y:.0f}<extra></extra>'
          };
        });

        var floor = 1;
        var ceiling = Math.max(extent * 1.1, 10);
        traces.push({
          type: 'scatter',
          mode: 'lines',
          name: 'Equal counts',
          x: [floor, ceiling],
          y: [floor, ceiling],
          line: { color: '#5b655e', width: 1.5, dash: 'dash' },
          hoverinfo: 'skip'
        });

        return traces;
      },
      layout: {
        xaxis: { title: { text: 'Mean genes per class, curated annotation' }, type: 'log' },
        yaxis: { title: { text: 'Mean genes per class, Helixer' }, type: 'log' },
        margin: { l: 70, r: 20, t: 72, b: 62 },
        legend: LEGEND_TOP
      }
    });
  }

  /* 3. Exclusive immunity classes per genome, stacked.
        EXCLUSIVE measure: one class per gene, so the stack is meaningful and
        its height is the genome's immunity gene total. */
  function chartImmunity(data) {
    var counts  = data.immunity_reference || {};
    var genomes = data.genomes || {};

    MGDB.chart({
      target: 'pd-chart-immunity',
      traces: function () {
        var names = sortGenomes(Object.keys(counts), genomes);
        if (!names.length) { return []; }

        return IMMUNITY_ORDER.map(function (key, index) {
          return {
            type: 'bar',
            name: IMMUNITY_LABEL[key],
            x: names,
            y: names.map(function (name) { return counts[name][key] || 0; }),
            marker: { color: COLORS[index % COLORS.length] },
            hovertemplate: '%{x}<br>' + IMMUNITY_LABEL[key] + ': %{y} genes<extra></extra>'
          };
        });
      },
      layout: {
        barmode: 'stack',
        xaxis: { title: { text: '' }, tickangle: -60, tickfont: { size: 9 }, automargin: true },
        yaxis: { title: { text: 'Genes' } },
        margin: { l: 70, r: 20, t: 56, b: 40 },
        legend: LEGEND_TOP
      }
    });
  }

  /* 4. NLR architecture subclasses, stacked. EXCLUSIVE measure. */
  function chartNlr(data) {
    var counts  = data.immunity_subclass_reference || {};
    var genomes = data.genomes || {};

    MGDB.chart({
      target: 'pd-chart-nlr',
      traces: function () {
        var names = sortGenomes(Object.keys(counts), genomes);
        if (!names.length) { return []; }

        return NLR_ORDER.map(function (key, index) {
          return {
            type: 'bar',
            name: NLR_LABEL[key],
            x: names,
            y: names.map(function (name) { return counts[name][key] || 0; }),
            marker: { color: COLORS[index % COLORS.length] },
            hovertemplate: '%{x}<br>' + NLR_LABEL[key] + ': %{y} genes<extra></extra>'
          };
        });
      },
      layout: {
        barmode: 'stack',
        xaxis: { tickangle: -60, tickfont: { size: 9 }, automargin: true },
        yaxis: { title: { text: 'NLR genes' } },
        margin: { l: 70, r: 20, t: 56, b: 40 },
        legend: LEGEND_TOP
      }
    });
  }

  /* 5. The comparison set across species, INCLUSIVE immunity classes.
        Wheat appears twice: raw, and divided by its monoploid count. Showing
        only the normalized value would leave a number on the page that nobody
        could reproduce from the downloadable table. */
  function chartSpecies(data) {
    var reference = data.counts_reference || {};
    var outgroup  = data.counts_outgroup || {};
    var genomes   = data.genomes || {};

    MGDB.chart({
      target: 'pd-chart-species',
      traces: function () {
        var classes = Object.keys(data.class_groups || {}).filter(function (name) {
          return data.class_groups[name] === 'Immunity';
        });
        if (!classes.length) { return []; }

        var categories = [];
        var values = {};
        classes.forEach(function (name) { values[name] = []; });

        function push(label, getter) {
          categories.push(label);
          classes.forEach(function (name) { values[name].push(getter(name)); });
        }

        /* Andropogoneae taxon medians. */
        var byTaxon = {};
        Object.keys(reference).forEach(function (assembly) {
          var taxon = (genomes[assembly] || {}).taxon || 'other';
          if (!byTaxon[taxon]) { byTaxon[taxon] = []; }
          byTaxon[taxon].push(assembly);
        });

        ['maize', 'teosinte', 'wild Zea', 'Tripsacum', 'Andropogon'].forEach(function (taxon) {
          if (!byTaxon[taxon]) { return; }
          push(taxon + ' (median)', function (name) {
            return median(byTaxon[taxon].map(function (a) { return reference[a][name] || 0; }));
          });
        });

        /* Outgroups, in the page's taxon order. */
        Object.keys(outgroup).sort(function (a, b) {
          var ra = taxonRank((genomes[a] || {}).taxon);
          var rb = taxonRank((genomes[b] || {}).taxon);
          return ra !== rb ? ra - rb : (a < b ? -1 : 1);
        }).forEach(function (species) {
          var label = species.replace(/_/g, ' ');
          var monoploid = ((genomes[species] || {}).monoploid) || 1;

          push(label, function (name) { return outgroup[species][name] || 0; });
          if (monoploid > 1) {
            push(label + ' (per monoploid)', function (name) {
              return (outgroup[species][name] || 0) / monoploid;
            });
          }
        });

        return classes.map(function (name, index) {
          return {
            type: 'bar',
            name: shortClass(name),
            x: categories,
            y: values[name],
            marker: { color: COLORS[index % COLORS.length] },
            hovertemplate: '%{x}<br>' + shortClass(name) + ': %{y:.0f} genes<extra></extra>'
          };
        });
      },
      layout: {
        barmode: 'group',
        xaxis: { tickangle: -35, automargin: true },
        yaxis: { title: { text: 'Genes' } },
        margin: { l: 70, r: 20, t: 56, b: 40 },
        legend: LEGEND_TOP
      }
    });
  }

  function failCharts(message) {
    Array.prototype.forEach.call(document.querySelectorAll('.mgdb-chart .mgdb-chart-fallback'), function (node) {
      node.textContent = message;
    });
  }

  /* ------------------------------------------------------------------------
     Start
     ------------------------------------------------------------------------ */

  function init() {
    var main = byId('pd-main');
    if (!main) { return; }

    wireVarianceTable();
    wireMatrix();

    var url = main.getAttribute('data-payload');
    if (!url) { return; }

    MGDB.request(url, { key: 'pd-payload' }).then(function (data) {
      chartVariance(data);
      chartArms(data);
      chartImmunity(data);
      chartNlr(data);
      chartSpecies(data);
    }).catch(function () {
      /* Every one of these charts has its values in a table in the same
         section, so this is a degraded page rather than an incomplete one —
         and it says so instead of leaving five boxes reading "Loading". */
      failCharts('This chart could not be loaded. The values it shows are in the table in this section.');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
