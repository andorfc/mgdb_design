/* mgdb-project-pathway-explorer.js
 *
 * Behaviour for /projects/pathway_explorer.
 *
 * Bauplan emits page scripts into <head>, so this file runs while the document
 * is still parsing and every element lookup at module scope would return null.
 * Everything therefore happens inside init(), behind the readyState guard at
 * the bottom.
 *
 * What is fetched, and when
 * ------------------------
 *   manifest.json   on load, for the figures. Every value it plots is already
 *                   rendered as a table in the same section, so a failure here
 *                   costs the reader the picture and nothing else.
 *   index.json      the 694 pathway rows and the class tree: the corpus that
 *                   browse, the heatmap, the gap list and the gene lookup all
 *                   name. Loaded once, shared, and prefetched when the browser
 *                   is idle so the first interaction is usually instant.
 *   matrix.json     the heatmap only.
 *   gaps.json       the gap list only.
 *   pathway/<ID>    one pathway, when the reader opens it.
 *
 * All of those are static files. Only the gene lookup goes through PHP, at
 * search/pathway_explorer/pathway_explorer_api.php, because resolving a pasted
 * list statically would be one request per gene.
 *
 * The four numbers this file must not confuse
 * -------------------------------------------
 *   1. The CornCyc track is not one of the 26 genomes. NAM_ONLY is the index
 *      set every per-genome statistic here runs over.
 *   2. "Absent" is two populations. The absent chip filters on pan === 'absent'
 *      AND e2p2, so it returns the 17 pathways E2P2 tested and did not find,
 *      not the 121 the raw field gives. The CornCyc-only chip is the other 104.
 *   3. A sub-pathway reference is not a reaction step. Steps with sub === 1
 *      carry no enzyme and are excluded from every step count.
 *   4. Completeness is over reaction steps, so a pathway of only sub-pathway
 *      references has no completeness rather than a completeness of zero.
 */

(function () {
  'use strict';

  var API = '/search/pathway_explorer/pathway_explorer_api.php';

  /* The ramp the heatmap draws, and the three stops the legend in
     mgdb-project-pathway-explorer.css repeats. Changing one means changing the
     other; they are checked against each other by eye at 0, 0.5 and 1. */
  var RAMP_LOW  = [244, 241, 234];
  var RAMP_MID  = [188, 208, 138];
  var RAMP_HIGH = [61, 107, 31];

  var PAN_LABEL = {
    'core': 'Core',
    'near-core': 'Near-core',
    'shell': 'Shell',
    'genome-specific': 'Genome-specific',
    'absent': 'Absent'
  };

  var GAP_LABEL = {
    'complete': 'Complete',
    'lost-from-corncyc': 'Lost from CornCyc',
    'orphan-step': 'Orphan',
    'variable': 'Variable'
  };

  var GENE_URL = '/gene_center/gene/';
  var METACYC_URL = 'https://metacyc.org/pathway?orgid=META&id=';
  var PLANTCYC_URL = 'https://pmn.plantcyc.org/pathway?orgid=PLANT&id=';
  var EXPASY_URL = 'https://enzyme.expasy.org/EC/';

  /* ---------------------------------------------------------------- helpers */

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    return (window.MGDB && MGDB.escapeHtml)
      ? MGDB.escapeHtml(value === null || value === undefined ? '' : String(value))
      : String(value === null || value === undefined ? '' : value)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* Pathway, enzyme and equation strings carry the presentational markup
     CornCyc and MetaCyc write into their own fields -- <i>, <sub>, <sup> and
     the arrow entities. Escaping everything shows the reader "&lt;i&gt;"; not
     escaping puts upstream content into the page unfiltered. Escape, then
     restore exactly those tags and those entities, bare. */
  function markup(value) {
    return esc(value)
      .replace(/&amp;(rarr|larr|harr|hArr|rArr|lArr|Delta|delta|alpha|beta|gamma|zeta|mu|nu|omega|prime|plusmn|minus|times|hellip|mdash|ndash|nbsp|deg|sup1|sup2|sup3|frac12|frac13|frac14);/g, '&$1;')
      .replace(/&lt;(\/?)(i|em|sub|sup|small|b|strong)&gt;/g, '<$1$2>');
  }

  /* A pathway summary is curated prose, already sanitized upstream to
     <i b em strong sub sup a p br>. markup() above restores bare tags only, so
     running a summary through it printed the tags as text: 553 of the 694
     detail panels showed "<p>General Background</p>" and a literal &amp;alpha;
     instead of two formatted paragraphs and an alpha.

     Anchors are rebuilt rather than restored, so only an http(s) href can
     survive and every link leaves with the rel the rest of the site uses. */
  function summaryMarkup(value) {
    return esc(value)
      /* (?:amp;)? because some summaries carry a double-encoded entity: the
         stored text of PWY-6556 contains the literal "&amp;beta;-Alanine",
         which single-escaping leaves on screen as "&amp;beta;" instead of a
         beta. Both depths collapse to the character. */
      .replace(/&amp;(?:amp;)?(rarr|larr|harr|hArr|rArr|lArr|alpha|beta|gamma|delta|Delta|epsilon|zeta|eta|theta|kappa|lambda|mu|nu|xi|pi|rho|sigma|tau|phi|chi|psi|omega|Omega|prime|Prime|plusmn|minus|times|divide|middot|hellip|mdash|ndash|nbsp|deg|sup1|sup2|sup3|frac12|frac13|frac14|le|ge|ne|asymp|infin|larr|harr);/g, '&$1;')
      .replace(/&lt;(\/?)(p|br|i|b|em|strong|sub|sup)&gt;/g, '<$1$2>')
      /* Anchors are dropped to their text rather than rebuilt.
         Rebuilding them from the escaped stream cannot be done safely with a
         regex: once < and > are entities, an attribute tail has no reliable
         terminator, and a pattern permissive enough to cross target=&quot;_blank&quot;
         walks on through the paragraph text and swallows the next tag -- which
         turned two anchors in PWY-6556 into four, three of them wrapping whole
         sentences. The links these carry are the PlantCyc SAVI-pipeline
         explainer, on 480 of the 553 summaries, and the panel's own header
         already links the pathway at MetaCyc and PlantCyc, so nothing is lost
         that the reader cannot reach from the same panel. */
      .replace(/&lt;\/?a\b(?:(?!&gt;)[\s\S])*?&gt;/g, '');
  }

  function num(value) {
    var n = Number(value);
    if (!isFinite(n)) { return '—'; }
    return n.toLocaleString('en-US');
  }

  function pct(value, places) {
    if (value === null || value === undefined || !isFinite(Number(value))) { return '—'; }
    return (Number(value) * 100).toFixed(places === undefined ? 1 : places) + '%';
  }

  function show(el, visible) { if (el) { el.hidden = !visible; } }

  function setText(el, text) { if (el) { el.textContent = text; } }

  /* A fetch that does NOT cache its rejection.
     Caching the promise itself means one transient network failure permanently
     breaks the section that asked for it, and every retry re-reads the same
     rejected promise. The entry is dropped on failure so the next attempt is a
     real attempt. */
  var cache = {};
  function getJSON(url) {
    if (cache[url]) { return cache[url]; }
    var promise = (window.MGDB && MGDB.request)
      ? MGDB.request(url, { key: 'pe:' + url })
      : window.fetch(url, { credentials: 'same-origin' }).then(function (response) {
          if (!response.ok) { throw new Error('HTTP ' + response.status); }
          return response.json();
        });
    cache[url] = promise.catch(function (error) {
      delete cache[url];
      throw error;
    });
    return cache[url];
  }

  /* Run `then` once, when `el` is near the viewport.
     Three triggers, not one: IntersectionObserver does not fire in every
     environment, a page opened at an anchor deep in the document never
     scrolls, and a reader who uses a control before scrolling to it has to
     work either way. This is the shape MGDB.chart() already uses for the same
     reason. */
  function whenNear(el, then) {
    var done = false;
    function fire() {
      if (done) { return; }
      done = true;
      window.removeEventListener('scroll', check);
      window.removeEventListener('resize', check);
      if (observer) { observer.disconnect(); }
      then();
    }
    function near() {
      var rect = el.getBoundingClientRect();
      var height = window.innerHeight || document.documentElement.clientHeight;
      return rect.top < height + 500 && rect.bottom > -500;
    }
    function check() { if (near()) { fire(); } }

    var observer = null;
    if (window.IntersectionObserver) {
      observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) { if (entry.isIntersecting) { fire(); } });
      }, { rootMargin: '500px' });
      observer.observe(el);
    }
    window.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', check);
    check();
    return fire;
  }

  function fail(el, message) {
    if (!el) { return; }
    el.textContent = message;
    el.hidden = false;
  }

  /* fail() needs a counterpart, or a section that recovers keeps its red
     "could not be loaded" banner above the rows it just loaded. */
  function clearFail(el) {
    if (!el) { return; }
    el.textContent = '';
    el.hidden = true;
  }

  /* A monotonic token per async section. A render checks its token is still
     the current one before writing to the DOM, so a slow earlier request
     cannot overwrite a newer one -- the failure where a second Run with a
     different gene list settles on the first list's answer while the status
     line describes the second. */
  var tokens = {};
  function nextToken(key) {
    tokens[key] = (tokens[key] || 0) + 1;
    return tokens[key];
  }
  function isCurrent(key, token) { return tokens[key] === token; }

  function download(name, text, mime) {
    var blob = new Blob([text], { type: (mime || 'text/csv') + ';charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function csv(columns, rows) {
    var lines = [columns.join(',')];
    rows.forEach(function (row) {
      lines.push(row.map(function (cell) {
        if (cell === null || cell === undefined) { return ''; }
        var value = String(cell);
        return /[",\n]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value;
      }).join(','));
    });
    return lines.join('\n');
  }

  /* A class path is hierarchical, so a prefix test must stop at a separator.
     'A > B' is not an ancestor of 'A > Bx', although one string starts with the
     other. No such collision exists in the current 353 classes, which is
     exactly why it would be found late. */
  function inClass(path, root) {
    if (!root) { return true; }
    path = path || '';
    return path === root || path.indexOf(root + ' > ') === 0;
  }

  function rampColor(t) {
    t = Math.max(0, Math.min(1, Number(t) || 0));
    var from, to, local;
    if (t < 0.5) { from = RAMP_LOW; to = RAMP_MID; local = t / 0.5; }
    else { from = RAMP_MID; to = RAMP_HIGH; local = (t - 0.5) / 0.5; }
    return 'rgb(' + from.map(function (channel, index) {
      return Math.round(channel + (to[index] - channel) * local);
    }).join(',') + ')';
  }

  /* The shared .mgdb-chart is a fixed height, so a passed layout.height is
     ignored. One variable feeds the element and Plotly, and it never drops
     below the sheet's own min-height for that element -- a computed 304 losing
     to a min-height of 320 puts the element and the figure back out of step. */
  function sizeChart(id, height, floor) {
    var el = byId(id);
    var value = Math.max(height, floor || 320);
    if (el) { el.style.height = value + 'px'; }
    return value;
  }

  /* Short forms for the top-level ontology classes, used only when the figure
     is too narrow to carry the full names.

     The rule has to shorten the LONGEST member or it buys nothing: at 375px the
     full labels left this figure 64px of plot inside a 259px box, and the
     longest of them -- "Generation of Precursor Metabolites and Energy" -- is
     the one setting that gutter. These are swapped through yaxis.ticktext, not
     by relabelling the bars: Plotly pins a category axis's values on the first
     draw, so short strings passed as y become NEW categories and the figure
     keeps the labels it was born with. */
  var CLASS_SHORT = {
    'Generation of Precursor Metabolites and Energy': 'Precursors',
    'Degradation/Utilization/Assimilation': 'Degradation',
    'Activation/Inactivation/Interconversion': 'Activation'
  };

  function isNarrow(id) {
    var el = byId(id);
    var width = el ? el.getBoundingClientRect().width : 0;
    return width > 0 && width < 560;
  }

  function chartMetrics(id, wide, narrow) {
    var el = byId(id);
    var width = el ? el.getBoundingClientRect().width : 0;
    /* Margins sized from the figure's measured width, not as constants:
       Plotly.Plots.resize keeps the margins a figure was drawn with, so a
       desktop gutter survives onto a phone and squeezes the plot to nothing. */
    return (width > 0 && width < 560) ? narrow : wide;
  }

  /* ------------------------------------------------------------------- state */

  var root = null;          /* /data/projects/pathway_explorer */
  var manifest = null;
  var indexPromise = null;
  var index = null;         /* { pathways, byId, classes, genomes, corncyc_track } */

  function ensureIndex() {
    if (!indexPromise) {
      indexPromise = getJSON(root + '/index.json').then(function (payload) {
        var rows = payload.pathways || [];
        var map = {};
        rows.forEach(function (row) { map[row.id] = row; });
        index = {
          pathways: rows,
          byId: map,
          classes: payload.classes || [],
          genomes: payload.genomes || [],
          track: payload.corncyc_track
        };
        /* The set every per-genome statistic runs over. The reference track is
           a curated set on another assembly by another pipeline; including it
           would move a mean by more than any real difference between founders. */
        index.namIds = index.genomes
          .filter(function (genome) { return !genome.track; })
          .map(function (genome) { return genome.id; });
        return index;
      });
      indexPromise.catch(function () { indexPromise = null; });
    }
    return indexPromise;
  }

  /* ====================================================================== *
   * Figures
   * ====================================================================== */

  function drawCharts() {
    if (!manifest || !manifest.figures || !window.MGDB || !MGDB.chart) { return; }
    var figures = manifest.figures;
    var colors = MGDB.CHART_COLORS || ['#D55E00', '#0072B2', '#E69F00', '#009E73'];
    var namCount = (manifest.counts && manifest.counts.nam_genomes) || 26;

    /* Pathways present in exactly k of the founder genomes. The empty steps are
       kept on the axis rather than dropped: the gap between 2 and 24 is the
       point of the figure, and a categorical axis of only the non-zero values
       would hide it. */
    var presence = (figures.presence || []).filter(function (point) { return point.k <= namCount; });
    var presenceHeight = sizeChart('pe-chart-presence', 360);
    MGDB.chart({
      target: 'pe-chart-presence',
      traces: [{
        type: 'bar',
        x: presence.map(function (point) { return point.k; }),
        y: presence.map(function (point) { return point.n; }),
        marker: { color: colors[3] || '#009E73' },
        hovertemplate: '%{y:,} pathways in %{x} of ' + namCount + ' genomes<extra></extra>'
      }],
      layout: {
        height: presenceHeight,
        showlegend: false,
        margin: chartMetrics('pe-chart-presence',
          { l: 64, r: 16, t: 8, b: 48 }, { l: 48, r: 8, t: 8, b: 44 }),
        /* The full title is 287px of text in a 259px box at 375, so it is
           clipped mid-word and runs 40px past the right edge. The figure's own
           <p> above it carries the full sentence either way. */
        xaxis: { title: { text: isNarrow('pe-chart-presence')
                            ? 'Founder genomes' : 'Founder genomes carrying the pathway' },
                 dtick: 2 },
        yaxis: { title: { text: 'Pathways' }, type: 'log',
                 tickvals: [1, 3, 10, 30, 100, 300], ticktext: ['1', '3', '10', '30', '100', '300'] }
      }
    });

    var bins = figures.completeness_bins || [];
    var completenessHeight = sizeChart('pe-chart-completeness', 360);
    MGDB.chart({
      target: 'pe-chart-completeness',
      traces: [{
        type: 'bar',
        x: bins.map(function (value, i) { return (i * 10) + '–' + (i * 10 + 10) + '%'; }),
        y: bins,
        marker: { color: colors[1] || '#0072B2' },
        hovertemplate: '%{y:,} pathways at %{x} mean completeness<extra></extra>'
      }],
      layout: {
        height: completenessHeight,
        showlegend: false,
        margin: chartMetrics('pe-chart-completeness',
          { l: 64, r: 16, t: 8, b: 68 }, { l: 48, r: 8, t: 8, b: 76 }),
        xaxis: { title: { text: isNarrow('pe-chart-completeness')
                            ? 'Mean completeness'
                            : 'Mean completeness across the ' + namCount + ' genomes' },
                 tickangle: -45 },
        yaxis: { title: { text: 'Pathways' } }
      }
    });

    /* Genes carrying a pathway assignment, per track. Genes rather than the
       protein-model assignment rows: those are counted once per alternative
       protein, and two founder annotations name about a quarter fewer of them
       without carrying fewer genes, so plotting them draws an outlier pair that
       does not exist. The reference track is a separate trace, in a different
       colour and named, because it is not comparable with the founders at all
       and one colour would invite the comparison. */
    var depth = figures.depth || [];
    var trackRow = null;
    (manifest.genomes || []).forEach(function (genome) { if (genome.track) { trackRow = genome; } });
    var depthLabels = depth.map(function (entry) { return entry.id; });
    var depthValues = depth.map(function (entry) { return entry.genes; });
    var depthTraces = [{
      type: 'bar', orientation: 'h', name: 'NAM founder genome',
      y: depthLabels, x: depthValues,
      marker: { color: colors[3] || '#009E73' },
      hovertemplate: '%{y}: %{x:,} genes with a pathway assignment<extra></extra>'
    }];
    if (trackRow) {
      depthTraces.push({
        type: 'bar', orientation: 'h', name: 'CornCyc reference, a different pipeline',
        y: [trackRow.id], x: [trackRow.n_genes],
        marker: { color: colors[0] || '#D55E00' },
        hovertemplate: '%{y}: %{x:,} genes with a pathway assignment<extra></extra>'
      });
    }
    var depthHeight = sizeChart('pe-chart-depth', depth.length * 21 + 130, 620);
    MGDB.chart({
      target: 'pe-chart-depth',
      traces: depthTraces,
      layout: {
        height: depthHeight,
        barmode: 'overlay',
        margin: chartMetrics('pe-chart-depth',
          { l: 96, r: 40, t: 8, b: 48 }, { l: 78, r: 12, t: 8, b: 48 }),
        xaxis: { title: { text: isNarrow('pe-chart-depth') ? 'Genes' : 'Genes with a pathway assignment' } },
        yaxis: { automargin: true, categoryorder: 'array',
                 categoryarray: depthLabels.concat(trackRow ? [trackRow.id] : []) }
      }
    });

    var classes = (figures.classes || []).slice().reverse();
    var classNames = classes.map(function (entry) { return entry.name; });
    var classShort = classNames.map(function (name) { return CLASS_SHORT[name] || name; });
    var classHeight = sizeChart('pe-chart-classes', classes.length * 34 + 120);

    /* automargin makes the gutter exactly as wide as the tick text, so the
       label length is the only lever on how much plot is left. At 375px the
       short set plus a 10px tick font leaves ~170px of the 259px box; the full
       set at 12px left 64px. */
    function classTicks(narrow) {
      return { tickmode: 'array', tickvals: classNames,
               ticktext: narrow ? classShort : classNames,
               tickfont: { size: narrow ? 10 : 12 } };
    }
    var classNarrow = isNarrow('pe-chart-classes');
    MGDB.chart({
      target: 'pe-chart-classes',
      traces: [{
        type: 'bar', orientation: 'h',
        y: classNames,
        x: classes.map(function (entry) { return entry.n; }),
        marker: { color: colors[2] || '#E69F00' },
        /* The bar keeps its full name in the hover even when the tick is short. */
        customdata: classNames,
        hovertemplate: '%{customdata}: %{x:,} pathways<extra></extra>'
      }],
      layout: {
        height: classHeight,
        showlegend: false,
        margin: chartMetrics('pe-chart-classes',
          { l: 200, r: 40, t: 8, b: 44 }, { l: 118, r: 16, t: 8, b: 44 }),
        xaxis: { title: { text: 'Pathways' } },
        yaxis: Object.assign({ automargin: true }, classTicks(classNarrow))
      }
    });

    /* Plotly.Plots.resize keeps the margins and ticks a figure was drawn with,
       so crossing the breakpoint has to be relaid out by hand. */
    window.addEventListener('resize', (window.MGDB && MGDB.debounce ? MGDB.debounce : function (fn) { return fn; })(function () {
      var narrow = isNarrow('pe-chart-classes');
      if (narrow === classNarrow) { return; }
      classNarrow = narrow;
      var target = byId('pe-chart-classes');
      if (!target || !window.Plotly || !target.classList.contains('js-plotly-plot')) { return; }
      window.Plotly.relayout(target, {
        margin: narrow ? { l: 118, r: 16, t: 8, b: 44 } : { l: 200, r: 40, t: 8, b: 44 },
        'yaxis.ticktext': narrow ? classShort : classNames,
        'yaxis.tickfont.size': narrow ? 10 : 12
      });
    }, 200));

    var evidence = (figures.evidence || []).slice().reverse();
    var evidenceHeight = sizeChart('pe-chart-evidence', evidence.length * 34 + 120);
    MGDB.chart({
      target: 'pe-chart-evidence',
      traces: [{
        type: 'bar', orientation: 'h',
        y: evidence.map(function (entry) { return entry.code; }),
        x: evidence.map(function (entry) { return entry.n; }),
        marker: { color: colors[4] || '#CC79A7' },
        hovertemplate: '%{y}: %{x:,} assignments<extra></extra>'
      }],
      layout: {
        height: evidenceHeight,
        showlegend: false,
        margin: chartMetrics('pe-chart-evidence',
          { l: 130, r: 40, t: 8, b: 44 }, { l: 104, r: 16, t: 8, b: 44 }),
        xaxis: { title: { text: isNarrow('pe-chart-evidence') ? 'Assignments' : 'Gene assignments' } },
        yaxis: { automargin: true }
      }
    });
  }

  /* Plotly sizes a figure from its container at render time and then keeps
     that size until something resizes it. A chart in the two-column grid can
     be measured before the grid has settled -- one was drawn 700px wide in a
     570px box -- and the figure then sits outside its own container.
     MGDB.chart() re-runs Plotly.Plots.resize on a window resize, but nothing
     fires one on load, so this checks the figures against their boxes a few
     times after render and corrects any that disagree. */
  function settleCharts() {
    if (!window.Plotly || !window.Plotly.Plots || !window.Plotly.Plots.resize) { return; }
    Array.prototype.forEach.call(document.querySelectorAll('.mgdb-pe-page .js-plotly-plot'),
      function (plot) {
        var box = plot.getBoundingClientRect().width;
        var svg = plot.querySelector('.main-svg');
        if (!box || !svg) { return; }
        if (Math.abs(svg.getBoundingClientRect().width - box) > 2) {
          window.Plotly.Plots.resize(plot);
        }
      });
  }

  /* ====================================================================== *
   * Browse
   * ====================================================================== */

  var browse = {
    cls: '', query: '', pan: '', flag: '',
    open: {}, sort: 'np', desc: false, rows: []
  };

  function browseMatches(row) {
    if (!inClass(row.cls, browse.cls)) { return false; }
    /* The absent chip means "E2P2 tested this and found it in no genome", which
       is 17 pathways. The raw field also says absent for the 104 CornCyc-only
       pathways, which were never tested; those are the CornCyc-only chip. */
    if (browse.pan === 'absent' && !(row.pan === 'absent' && row.e2p2)) { return false; }
    if (browse.pan && browse.pan !== 'absent' && row.pan !== browse.pan) { return false; }
    if (browse.flag === 'orph' && !(row.orph > 0)) { return false; }
    if (browse.flag === 'var' && !(row.nvar > 0)) { return false; }
    if (browse.flag === 'cconly' && !(row.cc && !row.e2p2)) { return false; }
    if (browse.flag === 'inf' && row.cs !== 'inferred-llm') { return false; }
    if (browse.query) {
      if (!row._hay) {
        /* Built once per row and cached. Every part is coerced through a guard,
           because an unguarded null joins as the string "null" and then typing
           "null" returns the rows that have none. */
        row._hay = [row.np, row.id, row.syn || '', (row.ec || []).join(' '), row.cls || '']
          .join(' ').toLowerCase();
      }
      if (row._hay.indexOf(browse.query) === -1) { return false; }
    }
    return true;
  }

  function browseSorted() {
    var rows = index.pathways.filter(browseMatches);
    var key = browse.sort;
    rows.sort(function (a, b) {
      var left = a[key], right = b[key];
      if (left === null || left === undefined) { left = (typeof right === 'number') ? -Infinity : ''; }
      if (right === null || right === undefined) { right = (typeof left === 'number') ? -Infinity : ''; }
      var order;
      if (typeof left === 'number' && typeof right === 'number') { order = left - right; }
      else { order = String(left).localeCompare(String(right)); }
      return browse.desc ? -order : order;
    });
    return rows;
  }

  function panTag(value) {
    return '<span class="pe-tag">' + esc(PAN_LABEL[value] || value || '—') + '</span>';
  }

  function renderBrowse() {
    var body = byId('pe-table-body');
    if (!body || !index) { return; }
    browse.rows = browseSorted();

    setText(byId('pe-count'),
      num(browse.rows.length) + ' of ' + num(index.pathways.length) + ' pathways');
    var exportButton = byId('pe-export-pathways');
    if (exportButton) { exportButton.disabled = browse.rows.length === 0; }

    show(byId('pe-empty'), browse.rows.length === 0);
    show(byId('pe-table-scroll'), browse.rows.length > 0);

    /* The whole filtered set is rendered rather than paged: 694 rows is the
       worst case and it is well inside what one innerHTML write costs, and a
       page control over a set the reader is already filtering is a second
       thing to operate for no gain. */
    var html = browse.rows.map(function (row) {
      var parts = (row.cls || '').split(' > ');
      var leaf = parts[parts.length - 1] || '—';
      return '<tr>'
        + '<th scope="row"><a href="#pe-detail" data-pe-open="' + esc(row.id) + '">'
          + markup(row.n) + '</a>'
          + '<span class="mgdb-small mgdb-muted pe-row-id">' + esc(row.id) + '</span>'
          + (row.cc && !row.e2p2 ? '<span class="pe-tag">CornCyc only</span>' : '')
        + '</th>'
        + '<td><span class="mgdb-small">' + esc(leaf) + '</span>'
          + (parts.length > 1
              ? '<span class="mgdb-small mgdb-muted pe-row-id">' + esc(parts.slice(0, -1).join(' › ')) + '</span>'
              : '')
          + (row.cs === 'inferred-llm' ? '<span class="pe-tag">class inferred</span>' : '')
        + '</td>'
        + '<td class="mgdb-numeric">' + num(row.nr) + '</td>'
        + '<td class="mgdb-numeric">' + num(row.orph) + '</td>'
        + '<td class="mgdb-numeric">' + num(row.nvar) + '</td>'
        + '<td>' + panTag(row.pan) + '</td>'
        + '<td class="mgdb-numeric">' + (row.e2p2 ? pct(row.mc) : '—') + '</td>'
        /* Gated the same way as completeness beside it. A CornCyc-only pathway
           has no per-genome completeness to vary, so its stored 0 is "not
           measured", and printing 0.000 says the completeness is identical in
           every genome -- the opposite of what the em dash next to it says. */
        + '<td class="mgdb-numeric">'
          + (row.e2p2 && row.sd !== null && row.sd !== undefined ? Number(row.sd).toFixed(3) : '—')
        + '</td>'
        + '<td class="mgdb-numeric">' + num(row.npres) + ' / ' + index.namIds.length + '</td>'
        + '</tr>';
    }).join('');
    body.innerHTML = html;
  }

  function renderTree() {
    var box = byId('pe-tree');
    if (!box || !index) { return; }

    var children = {};
    index.classes.forEach(function (node) {
      var parent = node.parent === null || node.parent === undefined ? '' : node.parent;
      if (!children[parent]) { children[parent] = []; }
      children[parent].push(node);
    });

    var html = '<div class="pe-tree-row">'
      + '<span class="pe-tree-twist" aria-hidden="true"></span>'
      + '<button class="pe-tree-node" type="button" data-pe-class=""'
      + ' aria-pressed="' + (browse.cls === '' ? 'true' : 'false') + '">'
      + '<span class="pe-tree-label">All classes</span>'
      + '<span class="pe-tree-count">' + num(index.pathways.length) + '</span></button></div>';

    function walk(parent, depth) {
      (children[parent] || []).forEach(function (node) {
        var hasKids = (children[node.id] || []).length > 0;
        var isOpen = !!browse.open[node.id];
        /* Two sibling controls, not a control inside a control. The twist was
           a <span> inside the <button>, which is invalid nesting and, more to
           the point, not focusable: a keyboard user could open a branch only as
           a side effect of filtering to it and could never close one. */
        html += '<div class="pe-tree-row" style="padding-left:' + (depth * 12) + 'px">'
          + (hasKids
              ? '<button class="pe-tree-twist" type="button" data-pe-twist="' + esc(node.id) + '"'
                + ' aria-expanded="' + (isOpen ? 'true' : 'false') + '"'
                + ' aria-label="' + esc((isOpen ? 'Collapse ' : 'Expand ') + node.name) + '">'
                + (isOpen ? '▾' : '▸') + '</button>'
              : '<span class="pe-tree-twist" aria-hidden="true"></span>')
          + '<button class="pe-tree-node" type="button" data-pe-class="' + esc(node.id) + '"'
          + ' title="' + esc(node.id) + '"'
          + ' aria-pressed="' + (browse.cls === node.id ? 'true' : 'false') + '">'
          + '<span class="pe-tree-label" title="' + esc(node.name) + '">' + esc(node.name) + '</span>'
          + '<span class="pe-tree-count">' + num(node.n) + '</span></button></div>';
        if (isOpen) { walk(node.id, depth + 1); }
      });
    }
    walk('', 0);
    box.innerHTML = html;
  }

  function wireBrowse() {
    var search = byId('pe-search');
    var section = byId('pe-pathways');
    if (!section) { return; }

    var treeDrawn = false;
    function ready(then) {
      return ensureIndex().then(function () {
        /* The tree is drawn on the first arrival of the index, not only on the
           path that scrolled here: a reader who types in the search box before
           the section is in view would otherwise get results beside an empty
           class panel, and nothing would ever fill it. */
        clearFail(byId('pe-browse-error'));
        if (!treeDrawn) { treeDrawn = true; renderTree(); }
        if (then) { then(); }
      }).catch(function () {
        fail(byId('pe-browse-error'),
          'The pathway index could not be loaded, so browsing is unavailable. '
          + 'The same data is in the pathway index CSV under Downloads.');
        setText(byId('pe-count'), '');
      });
    }

    if (search) {
      var run = (window.MGDB && MGDB.debounce)
        ? MGDB.debounce(function () { browse.query = search.value.trim().toLowerCase(); renderBrowse(); }, 200)
        : function () { browse.query = search.value.trim().toLowerCase(); renderBrowse(); };
      search.addEventListener('input', function () { ready(run); });
    }

    section.addEventListener('click', function (event) {
      var chip = event.target.closest ? event.target.closest('[data-pe-pan], [data-pe-flag]') : null;
      if (chip) {
        var isPan = chip.hasAttribute('data-pe-pan');
        var attribute = isPan ? 'data-pe-pan' : 'data-pe-flag';
        var value = chip.getAttribute(attribute);
        var group = section.querySelectorAll('[' + attribute + ']');
        Array.prototype.forEach.call(group, function (button) {
          button.setAttribute('aria-pressed', button === chip ? 'true' : 'false');
        });
        if (isPan) { browse.pan = value; } else { browse.flag = value; }
        ready(renderBrowse);
        return;
      }

      var twist = event.target.closest ? event.target.closest('[data-pe-twist]') : null;
      if (twist) {
        event.preventDefault();
        event.stopPropagation();
        var branch = twist.getAttribute('data-pe-twist');
        browse.open[branch] = !browse.open[branch];
        renderTree();
        /* renderTree() replaces the node the click came from, so focus would
           land back on <body> and a keyboard walk down the tree would restart. */
        var again = document.querySelector('[data-pe-twist="' + branch.replace(/"/g, '\\"') + '"]');
        if (again && again.focus) { again.focus(); }
        return;
      }

      var node = event.target.closest ? event.target.closest('[data-pe-class]') : null;
      if (node) {
        browse.cls = node.getAttribute('data-pe-class');
        if (browse.cls) { browse.open[browse.cls] = true; }
        ready(function () { renderTree(); renderBrowse(); });
        return;
      }

      var header = event.target.closest ? event.target.closest('th[data-pe-sort]') : null;
      if (header) {
        var key = header.getAttribute('data-pe-sort');
        if (browse.sort === key) { browse.desc = !browse.desc; }
        else { browse.sort = key; browse.desc = (key !== 'np' && key !== 'cls' && key !== 'pan'); }
        var heads = section.querySelectorAll('th[data-pe-sort]');
        Array.prototype.forEach.call(heads, function (th) {
          if (th === header) { th.setAttribute('aria-sort', browse.desc ? 'descending' : 'ascending'); }
          else { th.removeAttribute('aria-sort'); }
        });
        ready(renderBrowse);
        return;
      }

      var open = event.target.closest ? event.target.closest('[data-pe-open]') : null;
      if (open) {
        event.preventDefault();
        openPathway(open.getAttribute('data-pe-open'));
      }
    });

    var reset = byId('pe-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        browse.pan = ''; browse.flag = ''; browse.cls = ''; browse.query = '';
        if (search) { search.value = ''; }
        Array.prototype.forEach.call(section.querySelectorAll('[data-pe-pan], [data-pe-flag]'),
          function (button) {
            var value = button.getAttribute('data-pe-pan') || button.getAttribute('data-pe-flag');
            button.setAttribute('aria-pressed', value === '' ? 'true' : 'false');
          });
        ready(function () { renderTree(); renderBrowse(); });
      });
    }

    var exportButton = byId('pe-export-pathways');
    if (exportButton) {
      exportButton.addEventListener('click', function () {
        /* The export is what is on screen, in the order it is on screen. An
           export that silently reverts to source order is a different file from
           the one the reader is looking at. */
        download('maizegdb_pathway_index.csv', csv(
          ['pathway_id', 'pathway', 'class_path', 'class_source', 'n_reaction_steps',
           'n_steps_without_a_gene', 'n_variable_steps', 'pan_category', 'variability',
           'mean_completeness', 'sd_completeness', 'n_genomes_present', 'n_genomes_complete',
           'in_e2p2', 'in_corncyc', 'ec_numbers'],
          browse.rows.map(function (row) {
            /* Empty, not zero, wherever the table shows an em dash: a CSV that
               writes 0 for "not measured" is a value a reader will average. */
            return [row.id, row.np, row.cls, row.cs, row.nr, row.orph, row.nvar, row.pan,
                    row['var'],
                    row.e2p2 ? row.mc : null,
                    row.e2p2 ? row.sd : null,
                    row.npres, row.ncomp, row.e2p2, row.cc,
                    (row.ec || []).join(';')];
          })));
      });
    }

    /* Load when the section comes near, so a reader who jumps straight to a
       filter never waits and a reader who never scrolls here never pays for it. */
    whenNear(section, function () { ready(renderBrowse); });
  }

  /* ====================================================================== *
   * Pathway detail
   * ====================================================================== */

  function openPathway(id) {
    var panel = byId('pe-detail');
    if (!panel || !id) { return; }
    panel.hidden = false;
    panel.innerHTML = '<p class="pe-busy">Loading ' + esc(id) + '…</p>';
    panel.scrollIntoView({ block: 'nearest' });

    Promise.all([ensureIndex(), getJSON(root + '/pathway/' + encodeURIComponent(id) + '.json')])
      .then(function (results) { renderPathway(panel, results[1]); })
      .catch(function () {
        panel.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + 'No pathway <span class="mgdb-sequence">' + esc(id) + '</span> could be loaded. '
          + 'The pathway index CSV under Downloads lists every pathway in this build.</div>';
      });
  }

  function renderPathway(panel, data) {
    var namIds = index.namIds;
    var tracks = index.genomes.map(function (genome) { return genome.id; });
    var steps = data.steps || [];
    var reactionSteps = steps.filter(function (step) { return !step.sub; });

    var meta = [];
    meta.push('<span class="mgdb-sequence">' + esc(data.id) + '</span>');
    if (data.metacyc) {
      meta.push('<a href="' + METACYC_URL + encodeURIComponent(data.metacyc) + '" target="_blank" rel="noopener">MetaCyc</a>');
    }
    if (data.plantcyc) {
      meta.push('<a href="' + PLANTCYC_URL + encodeURIComponent(data.plantcyc) + '" target="_blank" rel="noopener">PlantCyc</a>');
    }
    meta.push(panTag(data.pan));
    if (data['var']) { meta.push('<span class="pe-tag">' + esc(data['var']) + ' variability</span>'); }
    if (!data.in_e2p2) { meta.push('<span class="mgdb-pill mgdb-pill-warn">Not recovered by E2P2</span>'); }
    if (data.cls_src === 'inferred-llm') {
      meta.push('<span class="pe-tag">class inferred, not from CornCyc</span>');
    }

    var html = '<div class="pe-detail-head">'
      + '<div><h3>' + markup(data.name) + '</h3>'
      + '<p class="mgdb-small mgdb-muted">' + esc(data.cls || 'No ontology class') + '</p></div>'
      + '<button class="mgdb-button mgdb-button-quiet" type="button" data-pe-close>Close</button>'
      + '</div>'
      + '<div class="pe-detail-meta">' + meta.join('') + '</div>';

    if (data.syn) {
      html += '<p class="mgdb-small mgdb-muted">Synonyms: ' + markup(data.syn) + '</p>';
    }

    html += '<div class="pe-detail-grid"><div>';

    if (data.summary) {
      html += '<h4 class="mgdb-leaf-heading">Summary</h4>'
        + '<div class="mgdb-prose pe-summary">' + summaryMarkup(data.summary) + '</div>'
        + '<p class="mgdb-small mgdb-muted">Summary text from CornCyc and MetaCyc.</p>';
    }

    if (!data.in_e2p2) {
      html += '<div class="mgdb-note"><p>This pathway is in CornCyc and E2P2 recovered it in none '
        + 'of the ' + namIds.length + ' founder genomes, so it has no completeness and no '
        + 'per-genome gene assignments here. That is a property of this annotation, not evidence '
        + 'that maize lacks the pathway.</p></div>';
    }

    html += '<h4 class="mgdb-leaf-heading">Reaction steps</h4>';
    if (!reactionSteps.length) {
      html += '<p class="mgdb-muted">This pathway has no reaction steps of its own; it is made up '
        + 'of the component pathways listed beside it.</p>';
    } else {
      html += '<div class="mgdb-table-scroll"><table class="mgdb-table">'
        + '<caption class="mgdb-visually-hidden">Reaction steps of ' + esc(data.name_plain) + '</caption>'
        + '<thead><tr><th scope="col">EC and reaction</th><th scope="col">Enzyme</th>'
        + '<th scope="col">Equation</th><th scope="col" class="mgdb-numeric">Genomes</th>'
        + '<th scope="col">Tracks</th><th scope="col">Evidence</th></tr></thead><tbody>';

      steps.forEach(function (step) {
        if (step.sub) {
          html += '<tr><th scope="row"><a href="#pe-detail" data-pe-open="' + esc(step.r) + '">'
            + esc(step.r) + '</a><span class="mgdb-small mgdb-muted pe-row-id">component pathway'
            + '</span></th><td colspan="5" class="mgdb-muted mgdb-small">A component pathway of this '
            + 'superpathway, not a reaction: it carries no enzyme and no genes of its own.</td></tr>';
          return;
        }

        var counts = step.counts || [];
        var namPresent = 0;
        var strip = '';
        tracks.forEach(function (trackId, position) {
          var count = counts[position] || 0;
          var isReference = trackId === index.track;
          if (!isReference && count > 0) { namPresent++; }
          var reason = (step.gaps && step.gaps[trackId]) ? step.gaps[trackId] : null;
          var className = count > 0 ? '' : (reason === 'annotation-gap' ? 'is-gap' : 'is-empty');
          /* The square is present or absent, not a shade of the row's own
             maximum: shading by count made a genome with one gene on a row
             whose maximum was six look absent. The count is in the title. */
          strip += '<span class="' + className + (isReference ? ' is-reference' : '') + '" title="'
            + esc(trackId + (isReference ? ' (reference)' : '') + ': '
                  + (count > 0 ? count + (count === 1 ? ' gene' : ' genes')
                               : 'no gene' + (reason ? ' — ' + reason : ''))) + '"></span>';
        });

        /* The strip is 27 empty spans, so a screen reader hears nothing at all
           for the column and a phone has no hover to reveal the titles. One
           sentence of real text carries the same fact, and colour stops being
           the only encoding. */
        var gapReasons = 0;
        tracks.forEach(function (trackId, position) {
          if ((counts[position] || 0) === 0 && step.gaps && step.gaps[trackId] === 'annotation-gap') {
            gapReasons++;
          }
        });
        var stripText = namPresent + ' of ' + namIds.length + ' founder genomes assign a gene'
          + ((counts[0] || 0) > 0 ? ', and so does the CornCyc reference' : ', the CornCyc reference does not')
          + (gapReasons ? '; ' + gapReasons + ' of the rest have the gene but not this annotation' : '')
          + '.';

        var ecLabel = step.ec ? String(step.ec).replace(/^EC-/, 'EC ') : null;
        html += '<tr>'
          + '<th scope="row">'
          + (step.ec
              ? '<a href="' + EXPASY_URL + encodeURIComponent(String(step.ec).replace(/^EC-/, ''))
                + '" target="_blank" rel="noopener">' + esc(ecLabel) + '</a>'
              : '<span class="mgdb-muted">No EC number</span>')
          + '<span class="mgdb-small mgdb-muted pe-row-id">' + esc(step.r) + '</span></th>'
          + '<td>' + (step.en || step.cn ? markup(step.en || step.cn) : '<span class="mgdb-muted">—</span>') + '</td>'
          + '<td class="mgdb-small">' + (step.eq ? markup(step.eq) : '—') + '</td>'
          + '<td class="mgdb-numeric">' + namPresent + ' / ' + namIds.length + '</td>'
          + '<td><span class="pe-strip" aria-hidden="true">' + strip + '</span>'
            + '<span class="mgdb-visually-hidden">' + esc(stripText) + '</span></td>'
          + '<td>' + (step.ev ? '<span class="pe-tag">' + esc(step.ev) + '</span>' : '—') + '</td>'
          + '</tr>';
      });
      html += '</tbody></table></div>'
        + '<p class="mgdb-small mgdb-muted">In the track column the first square is the CornCyc '
        + 'reference and the rest are the ' + namIds.length + ' founder genomes. A filled square '
        + 'means at least one gene is assigned; an amber square means the gene is present in that '
        + 'genome but not annotated for this function. Hover a square for its gene count.</p>'
        + '<p><button class="mgdb-button mgdb-button-quiet" type="button" data-pe-genes-csv="'
        + esc(data.id) + '">Download the gene assignments as CSV</button></p>';
    }

    html += '</div><div>';

    html += '<h4 class="mgdb-leaf-heading">Completeness by track</h4>';
    if (data.in_e2p2 && data.stats) {
      html += '<p class="mgdb-small mgdb-muted">The share of this pathway’s '
        + reactionSteps.length + ' reaction step' + (reactionSteps.length === 1 ? '' : 's')
        + ' with at least one assigned gene.</p><div class="pe-bars">';
      index.genomes.forEach(function (genome) {
        var stat = data.stats[genome.id] || [0, 0, 0];
        html += '<span class="mgdb-sequence mgdb-small">' + esc(genome.id) + '</span>'
          + '<span class="pe-bar-track"><span class="pe-bar-fill'
          + (genome.track ? ' is-reference' : '') + '" style="width:'
          + (Math.max(0, Math.min(1, stat[2])) * 100).toFixed(1) + '%"></span></span>'
          + '<span class="pe-bar-value">' + stat[1] + (stat[1] === 1 ? ' gene' : ' genes') + '</span>';
      });
      html += '</div>';
    } else {
      html += '<p class="mgdb-muted mgdb-small">Not applicable: E2P2 recovered this pathway in no '
        + 'genome, so there is no per-track completeness to report.</p>';
    }

    html += '<h4 class="mgdb-leaf-heading">Pathway facts</h4><dl class="mgdb-stack">'
      + '<div><dt>Reaction steps</dt><dd>' + reactionSteps.length + '</dd></div>'
      + '<div><dt>Genomes with a gene</dt><dd>'
        + namIds.filter(function (id) {
            return data.stats && data.stats[id] && data.stats[id][1] > 0;
          }).length + ' of ' + namIds.length + '</dd></div>'
      + '<div><dt>Recovered by E2P2</dt><dd>' + (data.in_e2p2 ? 'Yes' : 'No') + '</dd></div>'
      + '<div><dt>In CornCyc</dt><dd>' + (data.in_cc ? 'Yes' : 'No') + '</dd></div>';
    if (data.savi !== null && data.savi !== undefined) {
      html += '<div><dt>SAVI score</dt><dd>' + Number(data.savi).toFixed(3) + '</dd></div>';
    }
    ['super', 'sub'].forEach(function (field) {
      var value = data[field];
      if (!value) { return; }
      var links = String(value).split('; ').filter(Boolean).map(function (other) {
        return '<a href="#pe-detail" data-pe-open="' + esc(other) + '">' + esc(other) + '</a>';
      }).join(', ');
      html += '<div><dt>' + (field === 'super' ? 'Superpathways' : 'Component pathways')
        + '</dt><dd>' + links + '</dd></div>';
    });
    if (data.pmids && data.pmids.length) {
      html += '<div><dt>References</dt><dd>'
        + data.pmids.slice(0, 12).map(function (pmid) {
            return '<a href="https://pubmed.ncbi.nlm.nih.gov/' + encodeURIComponent(pmid)
              + '/" target="_blank" rel="noopener">' + esc(pmid) + '</a>';
          }).join(', ')
        + (data.pmids.length > 12 ? ' and ' + (data.pmids.length - 12) + ' more' : '')
        + '</dd></div>';
    }
    html += '</dl></div></div>';

    panel.innerHTML = html;
    panel.dataset.peId = data.id;
    panel._peData = data;
  }

  function pathwayGeneCsv(data) {
    var rows = [];
    (data.steps || []).forEach(function (step) {
      if (step.sub || !step.genes) { return; }
      Object.keys(step.genes).forEach(function (trackId) {
        (step.genes[trackId] || []).forEach(function (gene) {
          rows.push([data.id, data.name_plain, step.r, step.ec, step.en || step.cn,
                     trackId, gene.g, (gene.p || []).join(';'), gene.ev, gene.s, gene.nm, gene.v5]);
        });
      });
    });
    download(data.id + '_gene_assignments.csv', csv(
      ['pathway_id', 'pathway', 'reaction_id', 'ec', 'enzyme', 'track', 'gene_model',
       'protein_models', 'evidence', 'locus_symbol', 'gene_name', 'b73_v5_equivalent'], rows));
  }

  function wireDetail() {
    var panel = byId('pe-detail');
    if (!panel) { return; }
    panel.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('[data-pe-close]')) {
        panel.hidden = true;
        panel.innerHTML = '';
        return;
      }
      var csvButton = event.target.closest ? event.target.closest('[data-pe-genes-csv]') : null;
      if (csvButton && panel._peData) {
        pathwayGeneCsv(panel._peData);
        return;
      }
      var open = event.target.closest ? event.target.closest('[data-pe-open]') : null;
      if (open) {
        event.preventDefault();
        openPathway(open.getAttribute('data-pe-open'));
      }
    });

    /* The variable-pathways table in the figures section links into the same
       panel, so that link has to reach this handler too. */
    var figures = byId('pe-figures');
    if (figures) {
      figures.addEventListener('click', function (event) {
        var open = event.target.closest ? event.target.closest('[data-pe-open]') : null;
        if (open) {
          event.preventDefault();
          openPathway(open.getAttribute('data-pe-open'));
        }
      });
    }
  }

  /* ====================================================================== *
   * The completeness heatmap
   * ====================================================================== */

  var heat = {
    matrix: null, view: [], query: '', pan: '', cls: '', sort: 'name',
    left: 268, top: 96, cell: 24, rowHeight: 15,
    /* The reference column is drawn apart from the 26 founders, with a rule
       between, because the two are not the same measurement and a reader
       scanning a row should not have to remember which column is which. */
    split: 10
  };

  function heatBuild() {
    if (!heat.matrix || !index) { return; }
    var query = heat.query;
    heat.view = heat.matrix.rows.map(function (row) {
      return { row: row, p: index.byId[row.id] };
    }).filter(function (entry) {
      if (!entry.p) { return false; }
      if (heat.pan && entry.p.pan !== heat.pan) { return false; }
      if (!inClass(entry.p.cls, heat.cls)) { return false; }
      if (query && (entry.p.np + ' ' + entry.p.id).toLowerCase().indexOf(query) === -1) { return false; }
      return true;
    });

    var sort = heat.sort;
    heat.view.sort(function (a, b) {
      if (sort === 'name') { return a.p.np.localeCompare(b.p.np); }
      if (sort === 'mean') { return (a.p.mc || 0) - (b.p.mc || 0); }
      if (sort === 'sd') { return (b.p.sd || 0) - (a.p.sd || 0); }
      return (a.p.npres || 0) - (b.p.npres || 0);
    });

    setText(byId('pe-heat-count'),
      num(heat.view.length) + ' of ' + num(heat.matrix.rows.length) + ' pathways shown');
    heatDraw();
    heatTable();
  }

  /* The canvas conveys 15,930 values as colour and nothing else: no text for a
     screen reader, and on a phone no hover to reach the titles with. This is
     the same view as a table -- per pathway rather than per cell, which is the
     comparison the figure is for -- so the section is readable by keyboard and
     by voice, and colour stops being the only encoding. The per-track numbers
     behind any one row are in that pathway's own detail panel. */
  var HEAT_TABLE_ROWS = 200;
  function heatTable() {
    var body = byId('pe-heat-table-body');
    if (!body || !index) { return; }
    var shown = heat.view.slice(0, HEAT_TABLE_ROWS);
    body.innerHTML = shown.map(function (entry) {
      var values = entry.row.c.filter(function (value, position) {
        return heat.matrix.genomes[position] !== index.track;
      });
      var low = Math.min.apply(null, values);
      var high = Math.max.apply(null, values);
      return '<tr>'
        + '<th scope="row"><a href="#pe-detail" data-pe-open="' + esc(entry.p.id) + '">'
          + markup(entry.p.n) + '</a></th>'
        + '<td class="mgdb-numeric">' + pct(entry.p.mc) + '</td>'
        + '<td class="mgdb-numeric">' + pct(low) + '</td>'
        + '<td class="mgdb-numeric">' + pct(high) + '</td>'
        + '<td class="mgdb-numeric">' + num(entry.p.npres) + ' / ' + index.namIds.length + '</td>'
        + '<td class="mgdb-numeric">' + pct(entry.row.c[0]) + '</td>'
        + '</tr>';
    }).join('');
    var note = byId('pe-heat-table-note');
    if (note) {
      if (heat.view.length > HEAT_TABLE_ROWS) {
        note.textContent = 'The first ' + num(HEAT_TABLE_ROWS) + ' of ' + num(heat.view.length)
          + ' rows. Narrow the filters, or take all of them from the pathway by genome matrix '
          + 'under Downloads.';
        note.hidden = false;
      } else {
        note.hidden = true;
      }
    }
  }

  function heatDraw() {
    var canvas = byId('pe-heat');
    if (!canvas || !heat.matrix) { return; }
    var tracks = heat.matrix.genomes;
    var ratio = Math.min(2, window.devicePixelRatio || 1);
    var width = heat.left + heat.split + tracks.length * heat.cell + 16;
    var height = heat.top + heat.view.length * heat.rowHeight + 12;

    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
    canvas.style.width = width + 'px';
    canvas.style.height = height + 'px';

    var ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, width, height);

    ctx.font = '11px system-ui, -apple-system, Segoe UI, sans-serif';
    tracks.forEach(function (trackId, column) {
      var x = columnX(column) + heat.cell / 2;
      ctx.save();
      ctx.translate(x, heat.top - 8);
      ctx.rotate(-Math.PI / 3);
      ctx.textAlign = 'left';
      ctx.fillStyle = column === 0 ? '#7a4a10' : '#4a4f45';
      ctx.fillText(trackId, 0, 0);
      ctx.restore();
    });

    /* The rule between the reference column and the founders. */
    var ruleX = heat.left + heat.cell + heat.split / 2 - 1;
    ctx.strokeStyle = '#d8d2c4';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(ruleX + 0.5, heat.top - 6);
    ctx.lineTo(ruleX + 0.5, height - 8);
    ctx.stroke();

    ctx.font = '11px system-ui, -apple-system, Segoe UI, sans-serif';
    ctx.textAlign = 'left';
    heat.view.forEach(function (entry, rowIndex) {
      var y = heat.top + rowIndex * heat.rowHeight;
      /* Banding every fifth row: 590 rows of 15px is a long way for an eye to
         carry a name across to a cell. */
      if (rowIndex % 10 < 5) {
        ctx.fillStyle = '#faf8f4';
        ctx.fillRect(0, y, width, heat.rowHeight);
      }
      ctx.fillStyle = '#31352c';
      ctx.fillText(fitLabel(ctx, entry.p.np, heat.left - 14), 8, y + heat.rowHeight - 4);
      entry.row.c.forEach(function (value, column) {
        ctx.fillStyle = rampColor(value);
        ctx.fillRect(columnX(column), y + 1, heat.cell - 2, heat.rowHeight - 3);
      });
    });
  }

  function columnX(column) {
    return heat.left + column * heat.cell + (column === 0 ? 0 : heat.split);
  }

  /* Trim to the measured width rather than a character count: a name of 44
     narrow characters and one of 44 wide ones are not the same width, and the
     fixed count left some rows clipped and others ending 60px short. */
  function fitLabel(ctx, text, maxWidth) {
    if (ctx.measureText(text).width <= maxWidth) { return text; }
    var lo = 0, hi = text.length;
    while (lo < hi) {
      var mid = (lo + hi + 1) >> 1;
      if (ctx.measureText(text.slice(0, mid) + '…').width <= maxWidth) { lo = mid; }
      else { hi = mid - 1; }
    }
    return text.slice(0, lo) + '…';
  }

  function heatHit(event) {
    var canvas = byId('pe-heat');
    if (!canvas) { return null; }
    var rect = canvas.getBoundingClientRect();
    var x = event.clientX - rect.left;
    var y = event.clientY - rect.top;
    var rowIndex = Math.floor((y - heat.top) / heat.rowHeight);
    if (rowIndex < 0 || rowIndex >= heat.view.length) { return null; }
    var column = null;
    if (x >= heat.left) {
      for (var i = 0; i < heat.matrix.genomes.length; i++) {
        var start = columnX(i);
        if (x >= start && x < start + heat.cell) { column = i; break; }
      }
    }
    return { entry: heat.view[rowIndex], column: column, inLabel: x < heat.left };
  }

  function wireHeat() {
    var section = byId('pe-genomes');
    var canvas = byId('pe-heat');
    if (!section || !canvas) { return; }

    function load() {
      return Promise.all([ensureIndex(), getJSON(root + '/matrix.json')])
        .then(function (results) {
          heat.matrix = results[1];
          clearFail(byId('pe-heat-error'));
          var select = byId('pe-heat-class');
          if (select && select.options.length <= 1) {
            index.classes.filter(function (node) { return node.depth < 2; })
              .forEach(function (node) {
                var option = document.createElement('option');
                option.value = node.id;
                option.textContent = (node.depth ? ' ' : '') + node.name + ' (' + node.n + ')';
                select.appendChild(option);
              });
          }
          heatBuild();
        })
        .catch(function () {
          /* Release the latch. Setting it before the fetch and never clearing
             it meant one 503 disabled the heatmap and all four of its controls
             for the rest of the session: every later change found loaded=true
             and issued no request at all. */
          loaded = false;
          fail(byId('pe-heat-error'),
            'The genome matrix could not be loaded. Change a filter to try again, or read the '
            + 'same values in the pathway by genome matrix CSV under Downloads.');
          setText(byId('pe-heat-count'), '');
        });
    }

    var loaded = false;
    function ready(then) {
      if (loaded) { if (then) { then(); } return; }
      loaded = true;
      load().then(function () { if (then) { then(); } });
    }

    var search = byId('pe-heat-search');
    if (search) {
      var run = (window.MGDB && MGDB.debounce)
        ? MGDB.debounce(function () { heat.query = search.value.trim().toLowerCase(); heatBuild(); }, 200)
        : function () { heat.query = search.value.trim().toLowerCase(); heatBuild(); };
      search.addEventListener('input', function () { ready(run); });
    }
    [['pe-heat-pan', 'pan'], ['pe-heat-class', 'cls'], ['pe-heat-sort', 'sort']].forEach(function (pair) {
      var select = byId(pair[0]);
      if (!select) { return; }
      select.addEventListener('change', function () {
        heat[pair[1]] = select.value;
        ready(heatBuild);
      });
    });

    canvas.addEventListener('click', function (event) {
      var hit = heatHit(event);
      /* Clicking the label gutter used to navigate too, which made the labels
         impossible to read without leaving. Only a cell opens the pathway. */
      if (hit && !hit.inLabel) { openPathway(hit.entry.p.id); }
    });
    canvas.addEventListener('mousemove', function (event) {
      var hit = heatHit(event);
      if (!hit) { canvas.title = ''; return; }
      var text = hit.entry.p.np + ' (' + hit.entry.p.id + ')';
      if (hit.column !== null) {
        text += '\n' + heat.matrix.genomes[hit.column] + ': '
          + pct(hit.entry.row.c[hit.column]) + ' of steps have a gene, '
          + hit.entry.row.g[hit.column] + ' genes';
      }
      canvas.title = text;
    });

    whenNear(section, function () { ready(); });
  }

  /* ====================================================================== *
   * Reaction gaps
   * ====================================================================== */

  var gapState = { rows: null, view: [], gc: 'lost-from-corncyc', query: '', sort: 'p', desc: false };
  var GAP_PAGE = 300;

  function gapBuild() {
    if (!gapState.rows || !index) { return; }
    var query = gapState.query;
    gapState.view = gapState.rows.filter(function (row) {
      if (gapState.gc && row.gc !== gapState.gc) { return false; }
      if (!query) { return true; }
      if (!row._hay) {
        var pathway = index.byId[row.p];
        row._hay = [row.p, pathway ? pathway.np : '', row.r, row.ec || '', row.en || '', row.eq || '']
          .join(' ').toLowerCase();
      }
      return row._hay.indexOf(query) !== -1;
    });

    var key = gapState.sort;
    gapState.view.sort(function (a, b) {
      var left = key === 'pn' ? ((index.byId[a.p] || {}).np || a.p) : a[key];
      var right = key === 'pn' ? ((index.byId[b.p] || {}).np || b.p) : b[key];
      if (left === null || left === undefined) { left = ''; }
      if (right === null || right === undefined) { right = ''; }
      var order = (typeof left === 'number' && typeof right === 'number')
        ? left - right : String(left).localeCompare(String(right));
      return gapState.desc ? -order : order;
    });

    var body = byId('pe-gap-body');
    var shown = gapState.view.slice(0, GAP_PAGE);
    body.innerHTML = shown.map(function (row) {
      var pathway = index.byId[row.p];
      return '<tr>'
        + '<th scope="row"><a href="#pe-detail" data-pe-open="' + esc(row.p) + '">'
          + (pathway ? markup(pathway.n) : esc(row.p)) + '</a>'
          + '<span class="mgdb-small mgdb-muted pe-row-id">' + esc(row.p) + '</span></th>'
        + '<td><span class="mgdb-sequence mgdb-small">' + esc(row.r) + '</span></td>'
        + '<td>' + (row.ec
            ? '<a href="' + EXPASY_URL + encodeURIComponent(String(row.ec).replace(/^EC-/, ''))
              + '" target="_blank" rel="noopener">' + esc(String(row.ec).replace(/^EC-/, 'EC ')) + '</a>'
            : '<span class="mgdb-muted">—</span>') + '</td>'
        + '<td>' + (row.en ? markup(row.en) : '—') + '</td>'
        /* The equation is rendered as markup, not text: gaps.json mixes real
           Unicode arrows with named entities, and printing it as text shows the
           reader a literal "&Delta;" on the rows that use one. */
        + '<td class="mgdb-small">' + (row.eq ? markup(row.eq) : '—') + '</td>'
        + '<td class="mgdb-numeric">' + num(row.np) + ' / ' + index.namIds.length + '</td>'
        + '<td><span class="pe-tag">' + esc(GAP_LABEL[row.gc] || row.gc) + '</span></td>'
        /* ccg is the number of CornCyc genes on this step, which is what the
           gap class is about. The row's own `cc` is in_corncyc8 for the
           REACTION -- whether CornCyc knows the reaction at all -- and is 0 on
           17 of the 578 rows classed "lost from CornCyc", so a column built on
           it contradicts the class in the cell beside it. */
        + '<td class="mgdb-numeric">'
          + (row.ccg === null || row.ccg === undefined ? '<span class="mgdb-muted">—</span>' : num(row.ccg))
        + '</td>'
        + '</tr>';
    }).join('');

    setText(byId('pe-gap-count'),
      num(gapState.view.length) + ' of ' + num(gapState.rows.length) + ' incomplete steps');
    var more = byId('pe-gap-more');
    if (more) {
      if (gapState.view.length > GAP_PAGE) {
        more.textContent = 'Showing the first ' + num(GAP_PAGE) + '. Narrow the filters, or '
          + 'download the CSV, which carries all ' + num(gapState.view.length) + '.';
        more.hidden = false;
      } else {
        more.hidden = true;
      }
    }
    show(byId('pe-gap-scroll'), gapState.view.length > 0);
    show(byId('pe-gap-empty'), gapState.view.length === 0);
    var exportButton = byId('pe-export-gaps');
    if (exportButton) { exportButton.disabled = gapState.view.length === 0; }
  }

  function wireGaps() {
    var section = byId('pe-gaps');
    if (!section) { return; }

    var loaded = false;
    function ready(then) {
      if (loaded) { if (then) { then(); } return; }
      loaded = true;
      Promise.all([ensureIndex(), getJSON(root + '/gaps.json')])
        .then(function (results) {
          gapState.rows = results[1].gaps || [];
          clearFail(byId('pe-gap-error'));
          gapBuild();
          if (then) { then(); }
        })
        .catch(function () {
          loaded = false;
          fail(byId('pe-gap-error'),
            'The gap list could not be loaded. The same rows are in the reaction gap analysis CSV '
            + 'under Downloads.');
          setText(byId('pe-gap-count'), '');
        });
    }

    var select = byId('pe-gap-class');
    if (select) {
      select.addEventListener('change', function () {
        gapState.gc = select.value;
        ready(gapBuild);
      });
    }
    var search = byId('pe-gap-search');
    if (search) {
      var run = (window.MGDB && MGDB.debounce)
        ? MGDB.debounce(function () { gapState.query = search.value.trim().toLowerCase(); gapBuild(); }, 200)
        : function () { gapState.query = search.value.trim().toLowerCase(); gapBuild(); };
      search.addEventListener('input', function () { ready(run); });
    }

    section.addEventListener('click', function (event) {
      var header = event.target.closest ? event.target.closest('th[data-pe-gsort]') : null;
      if (header) {
        var key = header.getAttribute('data-pe-gsort');
        if (gapState.sort === key) { gapState.desc = !gapState.desc; }
        else { gapState.sort = key; gapState.desc = false; }
        Array.prototype.forEach.call(section.querySelectorAll('th[data-pe-gsort]'), function (th) {
          if (th === header) { th.setAttribute('aria-sort', gapState.desc ? 'descending' : 'ascending'); }
          else { th.removeAttribute('aria-sort'); }
        });
        ready(gapBuild);
        return;
      }
      var open = event.target.closest ? event.target.closest('[data-pe-open]') : null;
      if (open) {
        event.preventDefault();
        openPathway(open.getAttribute('data-pe-open'));
      }
    });

    var exportButton = byId('pe-export-gaps');
    if (exportButton) {
      exportButton.addEventListener('click', function () {
        download('maizegdb_reaction_gaps.csv', csv(
          ['pathway_id', 'pathway', 'reaction_id', 'ec', 'enzyme', 'equation',
           'n_founder_genomes_with_a_gene', 'n_corncyc_genes', 'gap_class', 'step_occupancy',
           'reaction_in_corncyc8', 'n_annotation_gap', 'n_gene_absent'],
          gapState.view.map(function (row) {
            var pathway = index.byId[row.p];
            return [row.p, pathway ? pathway.np : '', row.r, row.ec, row.en, row.eq,
                    row.np, row.ccg, row.gc, row.occ, row.cc, row.ag, row.ga];
          })));
      });
    }

    whenNear(section, function () { ready(); });
  }

  /* ====================================================================== *
   * Gene lookup
   * ====================================================================== */

  var geneRows = [];

  function postGenes(ids) {
    var body = new URLSearchParams();
    body.set('action', 'genes');
    body.set('ids', ids);
    return window.fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok || !payload.ok) {
          throw new Error(payload && payload.message ? payload.message : 'HTTP ' + response.status);
        }
        return payload;
      });
    });
  }

  function wireGenes() {
    var form = byId('pe-gene-form');
    if (!form) { return; }
    var input = byId('pe-gene-ids');
    var status = byId('pe-gene-status');
    var error = byId('pe-gene-error');

    var example = byId('pe-gene-example');
    if (example) {
      example.addEventListener('click', function () {
        input.value = 'Zm00001eb000080\nZm00001eb195860\nZm00001eb083110\n'
          + 'Zm00001eb119050\nZm00001eb152410\nZm00018ab085230';
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      show(error, false);
      var raw = (input.value || '').trim();
      if (!raw) { setText(status, 'Paste at least one gene model ID.'); return; }

      /* A visible busy state, and every rejection handled. An async submit
         handler that throws silently is a button that does nothing at all. */
      setText(status, 'Looking up…');
      var token = nextToken('genes');
      Promise.all([ensureIndex(), postGenes(raw)]).then(function (results) {
        if (!isCurrent('genes', token)) { return; }
        clearFail(error);
        var payload = results[1];
        geneRows = [];
        payload.genes.forEach(function (gene) {
          gene.a.forEach(function (assignment) {
            var pathway = index.pathways[assignment[0]];
            geneRows.push({
              gene: gene.id, track: gene.track,
              pid: pathway ? pathway.id : null,
              pathway: pathway ? pathway.np : null,
              html: pathway ? pathway.n : null,
              cls: pathway ? pathway.cls : '',
              reaction: assignment[1], evidence: assignment[2]
            });
          });
        });

        var trackNames = Object.keys(payload.tracks);
        var parts = [];
        parts.push(num(payload.genes.length) + ' of ' + num(payload.requested)
          + ' gene models carry a pathway assignment');
        if (payload.misses.length) {
          parts.push(num(payload.misses.length) + ' are not in the annotation at all');
        }
        if (trackNames.length > 1) {
          parts.push('the list spans ' + trackNames.length + ' tracks: '
            + trackNames.map(function (name) { return name + ' (' + payload.tracks[name] + ')'; }).join(', '));
        } else if (trackNames.length === 1) {
          parts.push('all from ' + trackNames[0]);
        }
        if (payload.truncated) {
          parts.push('only the first ' + num(payload.limit) + ' IDs were used');
        }
        setText(status, parts.join('; ') + '.');

        var missNote = payload.misses.length
          ? ' A gene the annotation has never seen is a different thing from a gene with no '
            + 'pathway: this index holds only genes that carry at least one assignment, so '
            + num(payload.misses.length) + ' of the IDs pasted are either from another assembly '
            + 'or misspelled.'
          : '';
        setText(byId('pe-gene-count'), num(geneRows.length) + ' assignments.' + missNote);
        show(byId('pe-gene-header'), true);
        show(byId('pe-gene-scroll'), geneRows.length > 0);

        byId('pe-gene-body').innerHTML = geneRows.map(function (row) {
          return '<tr>'
            + '<th scope="row"><a class="mgdb-sequence" href="' + GENE_URL + encodeURIComponent(row.gene)
              + '">' + esc(row.gene) + '</a></th>'
            + '<td><span class="mgdb-sequence mgdb-small">' + esc(row.track) + '</span></td>'
            + '<td>' + (row.pid
                ? '<a href="#pe-detail" data-pe-open="' + esc(row.pid) + '">' + markup(row.html) + '</a>'
                : '<span class="mgdb-muted">Unknown pathway index</span>') + '</td>'
            + '<td class="mgdb-small mgdb-muted">' + esc(row.cls || '—') + '</td>'
            + '<td><span class="mgdb-sequence mgdb-small">' + esc(row.reaction) + '</span></td>'
            + '<td>' + (row.evidence ? '<span class="pe-tag">' + esc(row.evidence) + '</span>' : '—') + '</td>'
            + '</tr>';
        }).join('');
      }).catch(function (err) {
        if (!isCurrent('genes', token)) { return; }
        setText(status, '');
        fail(error, 'The lookup could not be completed. ' + (err && err.message ? err.message : ''));
      });
    });

    form.addEventListener('click', function (event) {
      var open = event.target.closest ? event.target.closest('[data-pe-open]') : null;
      if (open) { event.preventDefault(); openPathway(open.getAttribute('data-pe-open')); }
    });
    var section = byId('pe-genes');
    if (section) {
      section.addEventListener('click', function (event) {
        var open = event.target.closest ? event.target.closest('[data-pe-open]') : null;
        if (open) { event.preventDefault(); openPathway(open.getAttribute('data-pe-open')); }
      });
    }

    var exportButton = byId('pe-export-genes');
    if (exportButton) {
      exportButton.addEventListener('click', function () {
        download('maizegdb_gene_pathway_lookup.csv', csv(
          ['gene_model', 'track', 'pathway_id', 'pathway', 'class_path', 'reaction_id', 'evidence'],
          geneRows.map(function (row) {
            return [row.gene, row.track, row.pid, row.pathway, row.cls, row.reaction, row.evidence];
          })));
      });
    }
  }

  /* ====================================================================== *
   * Enrichment
   *
   * One-sided hypergeometric test with Benjamini-Hochberg FDR control, run in
   * the browser. The gene resolution comes from the API; the background counts
   * come from a static file; the arithmetic never leaves the page.
   * ====================================================================== */

  function lgamma(x) {
    var c = [76.18009172947146, -86.50532032941677, 24.01409824083091,
             -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5];
    var y = x, tmp = x + 5.5, ser = 1.000000000190015;
    tmp -= (x + 0.5) * Math.log(tmp);
    for (var j = 0; j < 6; j++) { ser += c[j] / ++y; }
    return -tmp + Math.log(2.5066282746310005 * ser / x);
  }

  function lchoose(n, k) {
    if (k < 0 || k > n) { return -Infinity; }
    return lgamma(n + 1) - lgamma(k + 1) - lgamma(n - k + 1);
  }

  /* P(X >= k) for X ~ Hypergeometric(N, K, n). */
  function hyperUpper(N, K, n, k) {
    if (k <= 0) { return 1; }
    var limit = Math.min(n, K), denominator = lchoose(N, n), sum = 0;
    for (var i = k; i <= limit; i++) {
      sum += Math.exp(lchoose(K, i) + lchoose(N - K, n - i) - denominator);
    }
    return Math.max(0, Math.min(1, sum));
  }

  function benjaminiHochberg(values) {
    var m = values.length;
    var order = values.map(function (p, i) { return [p, i]; })
      .sort(function (a, b) { return a[0] - b[0]; });
    var q = new Array(m);
    var previous = 1;
    for (var rank = m - 1; rank >= 0; rank--) {
      previous = Math.min(previous, order[rank][0] * m / (rank + 1));
      q[order[rank][1]] = Math.min(1, previous);
    }
    return q;
  }

  var enrichState = { rows: [], sort: 'q', desc: false, last: null };

  function wireEnrichment() {
    var form = byId('pe-enrich-form');
    if (!form) { return; }
    var input = byId('pe-enrich-ids');
    var status = byId('pe-enrich-status');
    var error = byId('pe-enrich-error');
    var trackSelect = byId('pe-enrich-track');

    ensureIndex().then(function () {
      if (!trackSelect || trackSelect.options.length > 1) { return; }
      index.genomes.forEach(function (genome) {
        var option = document.createElement('option');
        option.value = genome.id;
        option.textContent = genome.id + (genome.track ? ' — CornCyc reference' : '');
        trackSelect.appendChild(option);
      });
    }).catch(function () { /* the form still reports its own failure on submit */ });

    var example = byId('pe-enrich-example');
    if (example) {
      example.addEventListener('click', function () {
        input.value = ['Zm00001eb000080', 'Zm00001eb195860', 'Zm00001eb083110',
                       'Zm00001eb119050', 'Zm00001eb152410', 'Zm00001eb072950',
                       'Zm00001eb112060', 'Zm00001eb345190', 'Zm00001eb366470',
                       'Zm00001eb030210', 'Zm00001eb146170', 'Zm00001eb170020'].join('\n');
      });
    }

    function render() {
      var alpha = parseFloat(byId('pe-enrich-alpha').value) || 0.05;
      var rows = enrichState.rows;
      if (!rows.length) { return; }

      var key = enrichState.sort;
      rows.sort(function (a, b) {
        var order = (a[key] === b[key]) ? 0 : (a[key] < b[key] ? -1 : 1);
        return enrichState.desc ? -order : order;
      });

      byId('pe-enrich-body').innerHTML = rows.map(function (row) {
        return '<tr>'
          + '<th scope="row"><a href="#pe-detail" data-pe-open="' + esc(row.pid) + '">'
            + markup(row.html) + '</a>'
            + '<span class="mgdb-small mgdb-muted pe-row-id">' + esc(row.cls || row.pid) + '</span></th>'
          + '<td class="mgdb-numeric">' + row.k + '</td>'
          + '<td class="mgdb-numeric">' + row.K + '</td>'
          + '<td class="mgdb-numeric">' + row.fold.toFixed(2) + '</td>'
          + '<td class="mgdb-numeric">' + row.p.toExponential(2) + '</td>'
          + '<td class="mgdb-numeric">' + (row.q <= alpha
              ? '<strong>' + row.q.toExponential(2) + '</strong>'
              : row.q.toExponential(2)) + '</td>'
          + '<td class="pe-genes-cell"><details><summary>' + row.k + ' genes</summary>'
            + '<p class="mgdb-sequence mgdb-small">' + esc(row.genes.join(', ')) + '</p></details></td>'
          + '</tr>';
      }).join('');

      var significant = rows.filter(function (row) { return row.q <= alpha; }).length;
      setText(byId('pe-enrich-count'),
        num(rows.length) + ' pathways with at least one gene from the list; '
        + num(significant) + ' significant at a false discovery rate of ' + alpha + '.');
      var method = byId('pe-enrich-method');
      if (method && enrichState.last) {
        method.textContent = 'One-sided hypergeometric test with Benjamini-Hochberg correction. '
          + 'The correction was applied over the ' + num(enrichState.last.tested)
          + ' pathways large enough to be tested in the ' + enrichState.last.track
          + ' background, not only over the ' + num(rows.length)
          + ' this list happened to hit — correcting over the hit set alone makes every q '
          + 'value smaller than it should be. The background is the ' + num(enrichState.last.N)
          + ' genes in ' + enrichState.last.track + ' that carry at least one pathway assignment, '
          + 'which is the universe this annotation can speak about; it is not the '
          + 'gene complement of the genome.';
        method.hidden = false;
      }
    }

    function run() {
      show(error, false);
      var raw = (input.value || '').trim();
      var minK = parseInt(byId('pe-enrich-mink').value, 10) || 2;
      if (raw.split(/[\s,;|]+/).filter(Boolean).length < 3) {
        setText(status, 'Paste at least three gene model IDs.');
        return;
      }
      setText(status, 'Resolving the gene list…');
      var token = nextToken('enrich');

      Promise.all([ensureIndex(), postGenes(raw)]).then(function (results) {
        if (!isCurrent('enrich', token)) { return; }
        var payload = results[1];
        var trackNames = Object.keys(payload.tracks);
        var chosen = trackSelect && trackSelect.value
          ? trackSelect.value
          : (trackNames.length ? trackNames[0] : 'B73');

        /* The control has to agree with what actually ran. Leaving it on a
           value the test did not use is a select that lies about its state. */
        if (trackSelect && !trackSelect.value) { trackSelect.value = chosen; }

        return getJSON(root + '/enrich/' + encodeURIComponent(chosen) + '.json')
          .then(function (background) {
            if (!isCurrent('enrich', token)) { return; }
            clearFail(error);
            var N = background.n_genes;
            var sizes = background.sizes || {};
            var hits = {};
            var n = 0;
            payload.genes.forEach(function (gene) {
              if (gene.track !== chosen) { return; }
              n++;
              var seen = {};
              gene.a.forEach(function (assignment) {
                var pi = assignment[0];
                if (seen[pi]) { return; }
                seen[pi] = true;
                if (!hits[pi]) { hits[pi] = []; }
                hits[pi].push(gene.id);
              });
            });

            if (!n) {
              setText(status, 'None of those gene models carry a pathway assignment in ' + chosen
                + '. Check that the IDs come from that track.');
              clearEnrichment();
              return;
            }

            /* The multiple-testing universe is every pathway large enough to be
               tested in this background, NOT only the pathways the list hit.
               Correcting over the hit set is what makes an eight-pathway result
               look far more significant than the same data in topGO or
               g:Profiler, which correct over all tested terms. */
            var tested = 0;
            Object.keys(sizes).forEach(function (pi) {
              if ((sizes[pi] || 0) >= minK) { tested++; }
            });

            var rows = [];
            Object.keys(hits).forEach(function (pi) {
              var K = sizes[pi] || 0;
              if (K < minK) { return; }
              var genes = hits[pi];
              var pathway = index.pathways[Number(pi)];
              if (!pathway) { return; }
              rows.push({
                pid: pathway.id, pathway: pathway.np, html: pathway.n, cls: pathway.cls,
                K: K, k: genes.length,
                p: hyperUpper(N, K, n, genes.length),
                fold: (genes.length / n) / (K / N),
                genes: genes.slice().sort()
              });
            });

            if (!rows.length) {
              setText(status, 'No pathway in the ' + chosen + ' background has at least ' + minK
                + ' genes and also appears in this list.');
              clearEnrichment();
              return;
            }

            /* BH over `tested`, with the untested pathways treated as p = 1:
               ranking is unchanged by adding p = 1 entries at the bottom, so
               scaling by the full m is enough and no dummy rows are needed. */
            var m = Math.max(tested, rows.length);
            var sorted = rows.map(function (row, i) { return [row.p, i]; })
              .sort(function (a, b) { return a[0] - b[0]; });
            var previous = 1;
            for (var rank = sorted.length - 1; rank >= 0; rank--) {
              previous = Math.min(previous, sorted[rank][0] * m / (rank + 1));
              rows[sorted[rank][1]].q = Math.min(1, previous);
            }

            enrichState.rows = rows;
            enrichState.last = { track: chosen, N: N, n: n, tested: m };
            setText(status, num(n) + ' of ' + num(payload.requested)
              + ' gene models are in the ' + chosen + ' pathway background of ' + num(N) + ' genes.');
            show(byId('pe-enrich-header'), true);
            show(byId('pe-enrich-scroll'), true);
            render();
          });
      }).catch(function (err) {
        if (!isCurrent('enrich', token)) { return; }
        setText(status, '');
        clearEnrichment();
        fail(error, 'The enrichment could not be run. ' + (err && err.message ? err.message : ''));
      });
    }

    /* Everything a previous run left on screen. The method statement is the one
       that misleads hardest if it is left: it names a track, a background size
       and a tested-pathway count, all of which describe the run before this
       one. */
    function clearEnrichment() {
      enrichState.rows = [];
      enrichState.last = null;
      show(byId('pe-enrich-header'), false);
      show(byId('pe-enrich-scroll'), false);
      var body = byId('pe-enrich-body');
      if (body) { body.innerHTML = ''; }
      show(byId('pe-enrich-method'), false);
      setText(byId('pe-enrich-count'), '');
    }

    form.addEventListener('submit', function (event) { event.preventDefault(); run(); });

    /* Changing the threshold or the smallest pathway must change the result.
       Leaving them to take effect only on the next Run leaves the highlighting
       and the "N significant" line describing settings that are no longer on
       screen. The threshold only re-renders; the minimum changes which
       pathways are tested, so it re-runs. */
    var alphaSelect = byId('pe-enrich-alpha');
    if (alphaSelect) {
      alphaSelect.addEventListener('change', function () { if (enrichState.rows.length) { render(); } });
    }
    ['pe-enrich-mink', 'pe-enrich-track'].forEach(function (id) {
      var control = byId(id);
      if (control) {
        control.addEventListener('change', function () { if (enrichState.rows.length) { run(); } });
      }
    });

    var section = byId('pe-enrichment');
    if (section) {
      section.addEventListener('click', function (event) {
        var header = event.target.closest ? event.target.closest('th[data-pe-esort]') : null;
        if (header) {
          var key = header.getAttribute('data-pe-esort');
          if (enrichState.sort === key) { enrichState.desc = !enrichState.desc; }
          else { enrichState.sort = key; enrichState.desc = (key !== 'p' && key !== 'q'); }
          Array.prototype.forEach.call(section.querySelectorAll('th[data-pe-esort]'), function (th) {
            if (th === header) { th.setAttribute('aria-sort', enrichState.desc ? 'descending' : 'ascending'); }
            else { th.removeAttribute('aria-sort'); }
          });
          render();
          return;
        }
        var open = event.target.closest ? event.target.closest('[data-pe-open]') : null;
        if (open) { event.preventDefault(); openPathway(open.getAttribute('data-pe-open')); }
      });
    }

    var exportButton = byId('pe-export-enrich');
    if (exportButton) {
      exportButton.addEventListener('click', function () {
        var last = enrichState.last || {};
        download('maizegdb_pathway_enrichment.csv', csv(
          ['pathway_id', 'pathway', 'class_path', 'track', 'genes_in_list', 'pathway_size',
           'list_size', 'background_size', 'pathways_tested', 'fold_enrichment',
           'p_hypergeometric', 'q_benjamini_hochberg', 'genes'],
          enrichState.rows.map(function (row) {
            return [row.pid, row.pathway, row.cls, last.track, row.k, row.K, last.n, last.N,
                    last.tested, row.fold.toFixed(4), row.p, row.q, row.genes.join(';')];
          })));
      });
    }
  }

  /* ====================================================================== *
   * Boot
   * ====================================================================== */

  function init() {
    var main = byId('pe-main');
    if (!main) { return; }
    root = main.getAttribute('data-root') || '/data/projects/pathway_explorer';

    /* One shared scrollspy, not a twelfth copy of one. */
    if (window.MGDB && MGDB.sectionTabs) {
      MGDB.sectionTabs({ watch: '#pe-detail' });
    }

    getJSON(main.getAttribute('data-payload') || (root + '/manifest.json'))
      .then(function (payload) {
        manifest = payload;
        drawCharts();
        /* Charts render lazily and asynchronously, so one pass is not enough;
           these catch a figure whichever frame it lands in. */
        [120, 500, 1500, 3500].forEach(function (delay) { setTimeout(settleCharts, delay); });
      })
      .catch(function () {
        Array.prototype.forEach.call(document.querySelectorAll('.mgdb-pe-page .mgdb-chart-fallback'),
          function (node) {
            node.textContent = 'This chart could not be loaded. Every value it shows is in the '
              + 'table under it.';
          });
      });

    wireBrowse();
    wireDetail();
    wireHeat();
    wireGaps();
    wireGenes();
    wireEnrichment();

    /* Warm the pathway index when the browser is otherwise idle, so the first
       filter, gap row or gene result does not wait on a 400 KB read. */
    window.addEventListener('load', settleCharts);
    if (window.MGDB && MGDB.debounce) {
      window.addEventListener('resize', MGDB.debounce(settleCharts, 250));
    }

    if (window.requestIdleCallback) {
      window.requestIdleCallback(function () { ensureIndex().catch(function () {}); },
        { timeout: 4000 });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
