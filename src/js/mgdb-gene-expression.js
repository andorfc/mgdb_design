/* file: mgdb-gene-expression.js
 *
 * purpose: the expression profile figure on the gene record page.
 *
 *          MGDB.geneExpression(container, spec) draws, from the expression
 *          dataset of the API (qTeller's RNA and protein abundances, one
 *          precomputed profile per gene):
 *
 *            tiles      how many samples detect the gene, the mean and
 *                       median, where it is highest, and how tissue-specific
 *                       it is (tau, with a plain reading)
 *            chart      one bar per sample, grouped by study in the order the
 *                       catalogue lists them or sorted by value, coloured by
 *                       the tissue read from the sample label; the mean as a
 *                       dashed line and the maximum marked; linear or log2
 *            panels     the same numbers folded by tissue (mean and maximum
 *                       per organ) and the top samples
 *            sources    every study behind the figure, with its link
 *
 *            tools      three panels filled from Expression Tools' own
 *                       endpoint when they scroll into view: the genes that
 *                       move with this one, its state across the 26 NAM
 *                       genomes, and its rank among all genes in each sample
 *
 *          and the ways out: Expression Tools (/expression/tools), which
 *          analyzes the same release -- the gene report, co-expression, a
 *          comparison, the neighborhood, a heatmap of the co-expressed genes,
 *          and its basket, which lives in this origin's storage and so can be
 *          filled from here -- and qTeller, which still holds the interactive
 *          atlas; plus the JSON and TSV of exactly what is drawn.
 *
 *          spec = {
 *            gene:    { name, symbol }
 *            profile: expression.profile  ({attributes, sections, links})
 *            qteller: the qTeller URL for this gene, or null
 *            tools:   { api } -- the Expression Tools endpoint (optional)
 *          }
 *
 *          Nothing here reads the DOM at module scope.
 *
 * history:
 *  09/12/26  claude  created
 */
(function (window, document) {
  'use strict';

  var MGDB = window.MGDB = window.MGDB || {};
  var SVG_NS = 'http://www.w3.org/2000/svg';
  var LEFT = 56, RIGHT = 16, TOP = 34, BOTTOM = 22, CHART_H = 300;
  var TISSUE_CLASS = {
    'root': 'root', 'leaf': 'leaf', 'stem': 'stem', 'shoot apex': 'apex', 'floral': 'floral',
    'seed': 'seed', 'seedling / whole plant': 'whole', 'other': 'other'
  };
  var TISSUE_ORDER = ['root', 'seedling / whole plant', 'leaf', 'stem', 'shoot apex', 'floral', 'seed', 'other'];
  var CONDITION_CLASS = { 'abiotic stress': 'abiotic', 'biotic stress': 'biotic', 'control': 'control', 'stress study': 'stress' };
  var CONDITION_ORDER = ['abiotic stress', 'biotic stress', 'control', 'stress study'];
  var BANDS_NOTE = 'Study bands are displayed in the figure as alternating strips of background color.';

  function esc(value) {
    return MGDB.escapeHtml ? MGDB.escapeHtml(value == null ? '' : String(value))
         : String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
             return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
           });
  }
  function fmt(v) {
    if (v == null) { return '—'; }
    var n = Number(v);
    if (n === 0) { return '0'; }
    if (n >= 1000) { return Math.round(n).toLocaleString(); }
    if (n >= 100) { return n.toFixed(0); }
    if (n >= 10) { return n.toFixed(1); }
    if (n >= 1) { return n.toFixed(2); }
    return n.toPrecision(2);
  }
  function el(name, attrs, text) {
    var node = document.createElementNS(SVG_NS, name);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    if (text != null) { node.textContent = text; }
    return node;
  }
  function html(tag, className, inner) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (inner != null) { node.innerHTML = inner; }
    return node;
  }
  function tissueClass(t) { return 'ge-t-' + (TISSUE_CLASS[t] || 'other'); }
  function conditionClass(c) { return 'ge-c-' + (CONDITION_CLASS[c] || 'stress'); }
  function shortStudy(name) {
    /* "Maize Atlas Stelpflug 2015 [Kaeppler Lab]" -> "Stelpflug 2015" */
    var m = String(name || '').replace(/\s*\[.*?\]\s*/g, '').match(/([A-Z][\w-]+(?:-[A-Z][\w-]+)?)\s+(\d{4})/);
    return m ? m[1] + ' ' + m[2] : String(name || '').replace(/\s*\[.*?\]\s*/g, '').slice(0, 22);
  }
  function tickStep(span, target) {
    var raw = span / target;
    var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
    var c = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < c.length; i++) { if (c[i] * mag >= raw) { return c[i] * mag; } }
    return 10 * mag;
  }

  function geneExpression(container, spec) {
    if (!container || !spec || !spec.profile || !spec.profile.sections) { return false; }
    var profile = spec.profile;
    var summaries = profile.sections.summary || {};
    var allSamples = profile.sections.samples || [];
    var sources = profile.sections.sources || [];
    var assays = (profile.attributes && profile.attributes.assays) || Object.keys(summaries);
    if (!assays.length || !allSamples.length) { return false; }

    var state = {
      assay: assays.indexOf('rna') !== -1 ? 'rna' : assays[0],
      scale: 'linear',
      order: 'study',
      tissueFilter: null,
      conditionFilter: null
    };

    container.innerHTML = '';
    container.classList.add('ge');
    var tiles = html('div', 'ge-tiles');
    var toolbar = html('div', 'ge-toolbar');
    var stage = html('div', 'ge-stage');
    var tip = html('div', 'ge-tip');
    tip.hidden = true;
    var legend = html('ul', 'ge-legend');
    var panels = html('div', 'ge-panels');
    var toolsEl = html('div', 'ge-tools');
    var sourcesEl = html('details', 'ge-sources');
    var footer = html('div', 'ge-footer');
    [tiles, toolbar, stage, tip, legend, panels, toolsEl, sourcesEl, footer].forEach(function (n) { container.appendChild(n); });

    function samplesFor(assay) { return allSamples.filter(function (s) { return s.assay === assay; }); }
    function summaryFor(assay) { return summaries[assay] || null; }

    /* ---- tiles ---- */
    function renderTiles() {
      var s = summaryFor(state.assay);
      tiles.innerHTML = '';
      if (!s) { return; }
      var unit = state.assay === 'protein' ? 'abundance' : 'expression';
      var measured = s.samples_with_value || s.samples || 0;
      var frac = measured ? s.detected / measured : (s.detected_fraction == null ? 0 : s.detected_fraction);
      var r = 16, c = 2 * Math.PI * r;
      var ring = '<svg class="ge-ring" viewBox="0 0 40 40" aria-hidden="true">' +
        '<circle cx="20" cy="20" r="' + r + '" fill="none" stroke="#e6ebe7" stroke-width="6"/>' +
        '<circle cx="20" cy="20" r="' + r + '" fill="none" stroke="#285d46" stroke-width="6" stroke-linecap="round" ' +
        'stroke-dasharray="' + (c * frac).toFixed(1) + ' ' + c.toFixed(1) + '" transform="rotate(-90 20 20)"/></svg>';
      tiles.appendChild(html('div', 'ge-tile',
        '<span class="ge-tile-label">Detected</span>' +
        '<div class="ge-tile-row">' + ring + '<div><span class="ge-tile-value">' + s.detected + ' <small>of ' + measured + '</small></span>' +
        '<div class="ge-tile-note">samples with ' + esc(s.detected_rule || 'a value') +
        (s.samples > measured ? '; ' + (s.samples - measured) + ' more were not measured' : '') + '</div></div></div>'));
      tiles.appendChild(html('div', 'ge-tile',
        '<span class="ge-tile-label">Mean ' + unit + '</span>' +
        '<span class="ge-tile-value">' + fmt(s.mean) + '</span>' +
        '<span class="ge-tile-note">median <strong>' + fmt(s.median) + '</strong> across ' + s.samples_with_value + ' samples with a value</span>'));
      var mx = s.max_sample || {};
      tiles.appendChild(html('div', 'ge-tile',
        '<span class="ge-tile-label">Highest</span>' +
        '<span class="ge-tile-value">' + fmt(s.max) + '</span>' +
        '<span class="ge-tile-note"><strong>' + esc(mx.sample || '—') + '</strong>' + (mx.source ? ' · ' + esc(shortStudy(mx.source)) : '') +
        (mx.tissue ? ' · ' + esc(mx.tissue) : '') + '</span>'));
      tiles.appendChild(html('div', 'ge-tile',
        '<span class="ge-tile-label">Specificity</span>' +
        '<span class="ge-tile-value">' + (s.tau == null ? '—' : 'τ ' + Number(s.tau).toFixed(2)) + '</span>' +
        '<span class="ge-tile-note"><strong>' + esc(s.specificity || 'not computed') + '</strong>' +
        (s.tau == null ? '' : ' · 0 is even everywhere, 1 is one sample only') + '</span>'));
    }

    /* ---- toolbar ---- */
    function chipGroup(label, options, current, onPick) {
      var group = html('div', 'ge-toolbar-group');
      group.appendChild(html('span', 'ge-toolbar-label', label));
      var chips = html('div', 'ge-chips');
      options.forEach(function (o) {
        var chip = html('button', 'ge-chip', esc(o[1]));
        chip.type = 'button';
        chip.setAttribute('aria-pressed', current === o[0] ? 'true' : 'false');
        chip.addEventListener('click', function () { onPick(o[0]); });
        chips.appendChild(chip);
      });
      group.appendChild(chips);
      return group;
    }
    function renderToolbar() {
      toolbar.innerHTML = '';
      if (assays.length > 1) {
        toolbar.appendChild(chipGroup('Assay', assays.map(function (a) {
          var sm = summaryFor(a);
          var n = (sm && sm.samples_with_value) || samplesFor(a).length;
          return [a, (a === 'rna' ? 'RNA' : a === 'protein' ? 'Protein' : a) + ' · ' + n];
        }), state.assay, function (v) { state.assay = v; state.tissueFilter = null; state.conditionFilter = null; renderAll(); }));
      }
      toolbar.appendChild(chipGroup('Scale', [['linear', 'Linear'], ['log', 'log₂']], state.scale, function (v) { state.scale = v; drawChart(); }));
      toolbar.appendChild(chipGroup('Order', [['study', 'By study'], ['value', 'By value']], state.order, function (v) { state.order = v; drawChart(); }));
      var links = html('div', 'ge-toolbar-links');
      var parts = [];
      if (profile.links && profile.links.api) { parts.push('<a href="' + esc(profile.links.api) + '">JSON</a>'); }
      if (profile.links && profile.links.tsv) { parts.push('<a href="' + esc(profile.links.tsv) + '">TSV</a>'); }
      links.innerHTML = parts.join(' <span class="ge-tip-muted">·</span> ');
      toolbar.appendChild(links);
    }

    /* ---- chart ---- */
    function yScale(items) {
      var max = 0;
      items.forEach(function (s) { if (s.value != null && s.value > max) { max = s.value; } });
      if (state.scale === 'log') {
        var lmax = Math.log(max + 1) / Math.LN2;
        return { max: max, lmax: lmax, y: function (v) { return v == null ? null : (Math.log(v + 1) / Math.LN2) / (lmax || 1); } };
      }
      return { max: max, y: function (v) { return v == null ? null : (max ? v / max : 0); } };
    }

    function drawChart() {
      var items = samplesFor(state.assay);
      var s = summaryFor(state.assay);
      stage.innerHTML = '';
      if (!items.length) { stage.appendChild(html('div', 'ge-empty', 'No samples for this assay.')); return; }
      var ordered = items.slice();
      if (state.order === 'value') {
        ordered.sort(function (a, b) { return (b.value == null ? -1 : b.value) - (a.value == null ? -1 : a.value); });
      }
      var n = ordered.length;
      var barW = Math.max(3, Math.min(14, Math.floor((Math.max(640, stage.clientWidth || 800) - LEFT - RIGHT) / n)));
      var width = LEFT + RIGHT + n * barW;
      var height = TOP + CHART_H + BOTTOM;
      var svg = el('svg', { viewBox: '0 0 ' + width + ' ' + height, width: width, height: height, role: 'img' });
      var scale = yScale(items);
      var y0 = TOP + CHART_H;
      var yOf = function (v) { var f = scale.y(v); return f == null ? y0 : y0 - f * CHART_H; };
      var unit = state.assay === 'protein' ? 'protein abundance' : 'expression';
      svg.setAttribute('aria-label', unit + ' of ' + (spec.gene.symbol || spec.gene.name) + ' across ' + n + ' samples' +
        (s && s.max_sample ? ', highest in ' + s.max_sample.sample : ''));

      // study bands
      if (state.order === 'study') {
        var x = LEFT, i = 0, bandIndex = 0;
        while (i < n) {
          var j = i;
          while (j < n && ordered[j].source === ordered[i].source) { j++; }
          var w = (j - i) * barW;
          if (bandIndex % 2 === 1) { svg.appendChild(el('rect', { x: x, y: TOP - 18, width: w, height: CHART_H + 18, 'class': 'ge-band' })); }
          var label = shortStudy(ordered[i].source);
          if (w >= label.length * 5.8 + 6) {
            svg.appendChild(el('text', { x: x + w / 2, y: TOP - 6, 'text-anchor': 'middle', 'class': 'ge-band-label' }, label));
          }
          var hit = el('rect', { x: x, y: TOP - 18, width: w, height: 16, 'class': 'ge-band-hit' });
          hit.appendChild(el('title', {}, ordered[i].source + ' · ' + (j - i) + ' sample' + (j - i === 1 ? '' : 's')));
          svg.appendChild(hit);
          x += w; i = j; bandIndex++;
        }
      }

      // axis and grid
      var axis = el('g', { 'class': 'ge-axis' });
      axis.appendChild(el('line', { x1: LEFT, x2: LEFT, y1: TOP, y2: y0, 'class': 'ge-axis-base' }));
      axis.appendChild(el('line', { x1: LEFT, x2: width - RIGHT, y1: y0, y2: y0, 'class': 'ge-axis-base' }));
      var grid = el('g', { 'class': 'ge-grid' });
      if (state.scale === 'log') {
        for (var p = 0; Math.pow(2, p) - 1 <= scale.max || p === 0; p++) {
          var v = Math.pow(2, p) - 1;
          var yy = yOf(v);
          if (yy < TOP - 1) { break; }
          axis.appendChild(el('line', { x1: LEFT - 4, x2: LEFT, y1: yy, y2: yy }));
          axis.appendChild(el('text', { x: LEFT - 7, y: yy + 3.5, 'text-anchor': 'end' }, fmt(v)));
          if (v > 0) { grid.appendChild(el('line', { x1: LEFT, x2: width - RIGHT, y1: yy, y2: yy })); }
          if (p > 40) { break; }
        }
      } else {
        var step = tickStep(scale.max || 1, 5);
        for (var t = 0; t <= scale.max + 1e-9; t += step) {
          var ty = yOf(t);
          axis.appendChild(el('line', { x1: LEFT - 4, x2: LEFT, y1: ty, y2: ty }));
          axis.appendChild(el('text', { x: LEFT - 7, y: ty + 3.5, 'text-anchor': 'end' }, fmt(t)));
          if (t > 0) { grid.appendChild(el('line', { x1: LEFT, x2: width - RIGHT, y1: ty, y2: ty })); }
        }
      }
      svg.appendChild(grid);
      svg.appendChild(axis);
      svg.appendChild(el('text', { x: 4, y: TOP - 6, 'class': 'ge-y-title' }, state.scale === 'log' ? 'log₂ axis' : 'value'));

      // bars
      var maxSample = s && s.max_sample ? s.max_sample.sample : null;
      ordered.forEach(function (smp, idx) {
        var x = LEFT + idx * barW;
        var g = el('g', { 'class': 'ge-bar' + (isFiltered(smp) ? ' is-dim' : ''), tabindex: 0, role: 'img' });
        var top = yOf(smp.value);
        var h = Math.max(smp.value == null ? 0 : 1, y0 - top);
        if (smp.value != null) {
          g.appendChild(el('rect', { x: x + (barW > 4 ? 1 : 0), y: y0 - h, width: Math.max(1, barW - (barW > 4 ? 2 : 0)), height: h, rx: barW > 6 ? 1.5 : 0, 'class': tissueClass(smp.tissue) }));
        }
        g.appendChild(el('rect', { x: x, y: TOP, width: barW, height: CHART_H, 'class': 'ge-bar-hit' }));
        var title = smp.label + ' (' + smp.source + '): ' + (smp.value == null ? 'no value' : fmt(smp.value)) + '; tissue reading ' + smp.tissue;
        g.appendChild(el('title', {}, title));
        g.setAttribute('aria-label', title);
        attachTip(g, function () {
          return '<strong>' + esc(smp.label) + '</strong>' +
            '<span class="ge-tip-value">' + (smp.value == null ? 'no value' : fmt(smp.value)) + '</span>' +
            '<br><span class="ge-tip-muted">' + esc(smp.source) + (smp.condition ? ' · ' + esc(smp.condition) : '') + '</span>' +
            '<br>tissue reading: ' + esc(smp.tissue);
        });
        svg.appendChild(g);
        if (maxSample && smp.label === maxSample && smp.value != null && smp.value === s.max) {
          svg.appendChild(el('path', { d: 'M' + (x + barW / 2 - 5) + ',' + (top - 12) + ' L' + (x + barW / 2 + 5) + ',' + (top - 12) + ' L' + (x + barW / 2) + ',' + (top - 3) + ' Z', 'class': 'ge-max-marker' }));
        }
      });

      // mean line
      if (s && s.mean != null && scale.max > 0) {
        var my = yOf(s.mean);
        svg.appendChild(el('line', { x1: LEFT, x2: width - RIGHT, y1: my, y2: my, 'class': 'ge-mean-line' }));
        svg.appendChild(el('text', { x: width - RIGHT - 2, y: my - 4, 'text-anchor': 'end', 'class': 'ge-mean-label' }, 'mean ' + fmt(s.mean)));
      }
      stage.appendChild(svg);
    }

    /* ---- tooltip ---- */
    function attachTip(node, build) {
      function show(e) { tip.innerHTML = build(); tip.hidden = false; place(e); }
      function place(e) {
        var rect = container.getBoundingClientRect();
        var x = (e && e.clientX != null ? e.clientX : rect.left + 40) - rect.left + 14;
        var y = (e && e.clientY != null ? e.clientY : rect.top + 40) - rect.top + 14;
        if (x + 330 > rect.width) { x = Math.max(0, x - 350); }
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
      }
      node.addEventListener('mouseenter', show);
      node.addEventListener('mousemove', place);
      node.addEventListener('mouseleave', function () { tip.hidden = true; });
      node.addEventListener('focus', function () { show(null); });
      node.addEventListener('blur', function () { tip.hidden = true; });
    }

    /* A bar is dimmed when it fails either filter; the two combine. */
    function isFiltered(smp) {
      return (state.tissueFilter && smp.tissue !== state.tissueFilter) ||
             (state.conditionFilter && smp.condition !== state.conditionFilter);
    }
    function toggleTissue(t) { state.tissueFilter = state.tissueFilter === t ? null : t; renderLegend(); renderPanels(); drawChart(); }
    function toggleCondition(c) { state.conditionFilter = state.conditionFilter === c ? null : c; renderLegend(); renderPanels(); drawChart(); }

    /* ---- legend: tissues and stress conditions as filters ---- */
    function renderLegend() {
      var items = samplesFor(state.assay);
      var counts = {};
      var condCounts = {};
      items.forEach(function (s) {
        counts[s.tissue] = (counts[s.tissue] || 0) + 1;
        if (s.condition) { condCounts[s.condition] = (condCounts[s.condition] || 0) + 1; }
      });
      legend.innerHTML = '';
      TISSUE_ORDER.forEach(function (t) {
        if (!counts[t]) { return; }
        var li = html('li');
        var b = html('button', null, '<span class="ge-swatch ' + tissueClass(t) + '"></span>' + esc(t) + ' <span class="ge-legend-n">' + counts[t] + '</span>');
        b.type = 'button';
        b.setAttribute('aria-pressed', state.tissueFilter === t ? 'true' : 'false');
        b.title = 'Show only samples read as ' + t;
        b.addEventListener('click', function () { toggleTissue(t); });
        li.appendChild(b);
        legend.appendChild(li);
      });
      var firstCond = true;
      CONDITION_ORDER.forEach(function (c) {
        if (!condCounts[c]) { return; }
        var li = html('li', firstCond ? 'ge-legend-cond ge-legend-cond-first' : 'ge-legend-cond');
        firstCond = false;
        var b = html('button', null, '<span class="ge-swatch ge-swatch-cond ' + conditionClass(c) + '"></span>' + esc(c) + ' <span class="ge-legend-n">' + condCounts[c] + '</span>');
        b.type = 'button';
        b.setAttribute('aria-pressed', state.conditionFilter === c ? 'true' : 'false');
        b.title = 'Show only stress-study samples read as ' + c;
        b.addEventListener('click', function () { toggleCondition(c); });
        li.appendChild(b);
        legend.appendChild(li);
      });
      legend.appendChild(html('li', null, '<span class="ge-swatch ge-swatch-mean"></span>mean'));
      legend.appendChild(html('li', null, '<span class="ge-swatch ge-swatch-max"></span>highest sample'));
    }

    /* ---- by tissue, by stress condition, and top samples ---- */
    function hbarRow(t, cls, scaleMax, pressed, onClick, hint) {
      var li = html('li', 'ge-hbar');
      var b = html('button', 'ge-hbar-btn',
        '<span class="ge-hbar-name">' + esc(t.name) + ' <small>' + t.samples + '</small></span>' +
        '<span class="ge-hbar-track">' +
          '<span class="ge-hbar-max ' + cls + '" style="width:' + (100 * t.max / scaleMax).toFixed(1) + '%"></span>' +
          '<span class="ge-hbar-mean ' + cls + '" style="width:' + (100 * t.mean / scaleMax).toFixed(1) + '%"></span>' +
        '</span>' +
        '<span class="ge-hbar-value">' + fmt(t.mean) + ' <small>/ ' + fmt(t.max) + '</small></span>');
      b.type = 'button';
      b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
      b.title = t.name + ': mean ' + fmt(t.mean) + ' over ' + t.samples + ' sample' + (t.samples === 1 ? '' : 's') +
                '; highest ' + fmt(t.max) + ' in ' + (t.max_sample || '') + '. ' + hint;
      b.addEventListener('click', onClick);
      li.appendChild(b);
      return li;
    }
    function renderPanels() {
      var s = summaryFor(state.assay);
      panels.innerHTML = '';
      if (!s) { return; }
      var byTissue = s.by_tissue || [];
      var byCondition = s.by_condition || [];
      var maxOfMax = byTissue.concat(byCondition).reduce(function (m, t) { return t.max > m ? t.max : m; }, 0) || 1;
      var left = html('div', 'ge-panel');
      left.innerHTML = '<h4>By tissue <small>mean and highest sample</small></h4>';
      var ul = html('ul', 'ge-hbars');
      byTissue.forEach(function (t) {
        ul.appendChild(hbarRow(t, tissueClass(t.name), maxOfMax, state.tissueFilter === t.name,
          function () { toggleTissue(t.name); }, 'Click to show only these samples in the chart.'));
      });
      left.appendChild(ul);
      left.appendChild(html('p', 'ge-panel-key',
        'Solid bar: the mean across the samples read as that tissue. Faded bar behind it: the highest of those samples. ' +
        'Every row is on one scale, so the bars compare across tissues and conditions. Select a row to show only its samples in the chart.'));
      if (byCondition.length) {
        left.appendChild(html('h5', 'ge-panel-sub', 'Stress studies <small>condition read from the sample label; controls are the same studies\u2019 untreated samples</small>'));
        var ulc = html('ul', 'ge-hbars');
        byCondition.forEach(function (c) {
          ulc.appendChild(hbarRow(c, conditionClass(c.name), maxOfMax, state.conditionFilter === c.name,
            function () { toggleCondition(c.name); }, 'Click to show only these samples in the chart.'));
        });
        left.appendChild(ulc);
      }
      panels.appendChild(left);

      var right = html('div', 'ge-panel');
      right.innerHTML = '<h4>Highest samples</h4>';
      var ol = html('ol', 'ge-top');
      (s.top || []).forEach(function (t) {
        var li = html('li');
        li.innerHTML = '<span class="ge-top-sample"><span class="ge-top-tissue ' + tissueClass(t.tissue) + '"></span>' + esc(t.sample) +
          '<small>' + esc(shortStudy(t.source)) + ' · ' + esc(t.tissue) + '</small></span>' +
          '<span class="ge-top-value">' + fmt(t.value) + '</span>';
        ol.appendChild(li);
      });
      right.appendChild(ol);
      panels.appendChild(right);
    }

    function renderSources() {
      var list = sources.filter(function (src) { return src.assay === state.assay; });
      sourcesEl.innerHTML = '<summary>' + list.length + ' stud' + (list.length === 1 ? 'y' : 'ies') + ' behind this profile</summary>';
      var ul = html('ul');
      list.forEach(function (src) {
        var li = html('li', null,
          (src.link ? '<a href="' + esc(src.link) + '" target="_blank" rel="noopener">' + esc(src.name) + '</a>' : esc(src.name)) +
          ' <span class="ge-src-n">' + src.sample_count + ' sample' + (src.sample_count === 1 ? '' : 's') + '</span>' +
          (src.stress ? '<span class="ge-src-stress">stress study</span>' : ''));
        ul.appendChild(li);
      });
      sourcesEl.appendChild(ul);
    }

    /* ---- Expression Tools: the endpoint, the links, the basket ----
       The tools read this same release, so every number they show for the
       gene matches this figure; the genome goes by its assembly name, which
       the tools accept as well as their short keys. */
    var toolsApi = (spec.tools && spec.tools.api) || '/search/expression_tools/expression_tools_api.php';
    var geneId = (spec.gene && spec.gene.name) || profile.attributes.gene;
    var genome = profile.attributes.genome || '';
    var toolsData = { coexp: null, pan: null, rank: null };
    function toolsHref(view, params) {
      var q = ['g=' + encodeURIComponent(genome)];
      Object.keys(params || {}).forEach(function (k) {
        if (params[k] !== '' && params[k] != null) { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); }
      });
      return '/expression/tools#' + view + '?' + q.join('&');
    }
    function toolsGet(action, params) {
      var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
      return window.fetch(toolsApi + '?action=' + encodeURIComponent(action) + '&' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (b) {
          if (!b || !b.ok) { throw new Error((b && b.error) || 'The expression tools did not answer.'); }
          return b.data;
        });
    }
    /* The tools keep their basket per genome under this origin's storage,
       keyed by their short genome name. */
    function toolsKey(g) {
      var m;
      if (/^Zm-B73-REFERENCE-NAM-5/.test(g)) { return 'B73v5'; }
      if (/^Zm-B73-REFERENCE-GRAMENE-4/.test(g)) { return 'B73v4'; }
      if ((m = /^Zm-([A-Za-z0-9]+)-REFERENCE-NAM-1/.exec(g))) { return m[1]; }
      return null;
    }
    function basketList() {
      var key = toolsKey(genome);
      if (!key || !window.localStorage) { return null; }
      try {
        var v = JSON.parse(window.localStorage.getItem('mgdb-exptools-basket-' + key) || '[]');
        return Array.isArray(v) ? v : [];
      } catch (e) { return null; }
    }
    function basketAdd() {
      var list = basketList();
      if (!list) { return null; }
      if (list.indexOf(geneId) === -1) { list.push(geneId); }
      try { window.localStorage.setItem('mgdb-exptools-basket-' + toolsKey(genome), JSON.stringify(list)); } catch (e) { return null; }
      return list;
    }
    function chip(href, label, external) {
      return '<a class="mgdb-button mgdb-button-secondary mgdb-button-sm" href="' + esc(href) + '"' + (external ? ' target="_blank" rel="noopener"' : '') + '>' + label + '</a>';
    }

    function renderFooter() {
      footer.innerHTML = '';
      var tissueNote = profile.attributes.tissue_note || '';
      if (tissueNote) { tissueNote = ' ' + tissueNote.charAt(0).toUpperCase() + tissueNote.slice(1); }
      var note = html('p', null, esc(profile.attributes.units_note || '') + esc(tissueNote) + ' ' + esc(BANDS_NOTE));
      footer.appendChild(note);
      var links = html('div', 'ge-footer-links');
      var parts = [];
      if (geneId && genome) {
        parts.push('<a class="mgdb-button mgdb-button-primary mgdb-button-sm" href="' + esc(toolsHref('gene', { id: geneId })) + '">Gene report in Expression Tools</a>');
        parts.push(chip(toolsHref('coexp', { id: geneId }), 'Co-expression'));
        parts.push(chip(toolsHref('compare', { g1: geneId }), 'Compare with another gene'));
        var q = toolsData.coexp && toolsData.coexp.query;
        if (q && q.chr && q.start && q.end) {
          parts.push(chip(toolsHref('region', { chr: q.chr, start: Math.max(1, q.start - 150000), end: q.end + 150000 }), 'Genes nearby, shaded by expression'));
        }
        if (toolsData.coexp && toolsData.coexp.positive && toolsData.coexp.positive.length) {
          var set = [geneId].concat(toolsData.coexp.positive.slice(0, 20).map(function (x) { return x.gene; }));
          parts.push(chip(toolsHref('heatmap', { genes: set.join(',') }), 'Heatmap of the co-expressed genes'));
        }
      }
      if (spec.qteller) { parts.push(chip(spec.qteller, 'Open in qTeller', true)); }
      links.innerHTML = parts.join('');
      var list = basketList();
      if (list && geneId) {
        var inBasket = list.indexOf(geneId) !== -1;
        var b = html('button', 'mgdb-button mgdb-button-secondary mgdb-button-sm ge-basket-btn', inBasket ? 'In the Expression Tools basket' : 'Add to the Expression Tools basket');
        b.type = 'button';
        b.disabled = inBasket;
        b.title = 'The basket is kept in this browser; every Expression Tools view can use it';
        b.addEventListener('click', function () { if (basketAdd()) { renderFooter(); } });
        links.appendChild(b);
        if (inBasket) {
          links.insertAdjacentHTML('beforeend', '<a class="ge-basket-open" href="' + esc(toolsHref('list', { genes: list.join(',') })) + '">' +
            list.length + ' gene' + (list.length === 1 ? '' : 's') + ' in it · open as a list</a>');
        }
      }
      footer.appendChild(links);
    }

    /* ---- what Expression Tools knows about this gene ----
       Three panels, fetched when they come into view so the record's first
       paint never waits on them; each fails on its own with a link to the
       tools. */
    function whenVisible(node, fn) {
      var done = false;
      var go = function () { if (!done) { done = true; fn(); } };
      var near = function () {
        var r = node.getBoundingClientRect();
        return r.top < (window.innerHeight || 800) * 1.5 && r.bottom > -200;
      };
      if (near()) { go(); return; }
      var onScroll = function () { if (near()) { window.removeEventListener('scroll', onScroll); go(); } };
      window.addEventListener('scroll', onScroll, { passive: true });
      /* The record scrolls itself to a section named in the hash after it
         has rendered, which is after this check; look again shortly. */
      [800, 2500].forEach(function (ms) { window.setTimeout(onScroll, ms); });
      if (window.IntersectionObserver) {
        var io = new window.IntersectionObserver(function (entries) {
          if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); window.removeEventListener('scroll', onScroll); go(); }
        }, { rootMargin: '400px 0px' });
        io.observe(node);
      }
    }
    function toolCard(title, sub) {
      var c = html('div', 'ge-panel ge-tool');
      c.innerHTML = '<h4>' + title + (sub ? ' <small>' + sub + '</small>' : '') + '</h4><p class="ge-tool-wait">Loading from Expression Tools…</p>';
      return c;
    }
    function toolFail(c, why, href, label) {
      var w = c.querySelector('.ge-tool-wait');
      if (w) { w.outerHTML = '<p class="ge-panel-key">' + esc(why) + (href ? ' <a href="' + esc(href) + '">' + esc(label) + '</a>' : '') + '</p>'; }
    }
    function geneLink(g) {
      return '<a class="ge-co-name" href="/gene_center/gene/' + encodeURIComponent(g.gene) + '">' +
        (g.symbol ? esc(g.symbol) + ' <small>' + esc(g.gene) + '</small>' : esc(g.gene)) + '</a>';
    }
    var STATE_TEXT = { expressed: 'expressed, 1 FPKM or more', low: 'low, 0.1 to 1 FPKM', silent: 'silent, under 0.1 FPKM', absent: 'no member', 'not measured': 'a member that was not measured' };
    function drawCo(c, d) {
      toolsData.coexp = d;
      var w = c.querySelector('.ge-tool-wait');
      var pos = (d.positive || []).slice(0, 6), neg = (d.negative || []).slice(0, 1);
      if (!pos.length) {
        w.outerHTML = '<p class="ge-panel-key">No gene keeps pace with it: over the ' + fmt(d.samples_used) + ' samples that measured it, it never reaches the level the comparison needs.</p>';
        return;
      }
      var max = Math.max.apply(null, pos.concat(neg).map(function (x) { return Math.abs(x.r); })) || 1;
      var rows = pos.concat(neg).map(function (x) {
        return '<li' + (x.r < 0 ? ' class="is-neg"' : '') + '>' + geneLink(x) +
          '<span class="ge-co-track"><i style="width:' + (100 * Math.abs(x.r) / max).toFixed(1) + '%"></i></span>' +
          '<span class="ge-co-r">' + (x.r < 0 ? '−' : '') + Math.abs(x.r).toFixed(3) + '</span></li>';
      }).join('');
      w.outerHTML = '<ol class="ge-co">' + rows + '</ol>' +
        '<p class="ge-panel-key">The ' + pos.length + ' closest of ' + fmt(d.tested) + ' genes compared over ' + fmt(d.samples_used) + ' samples' +
        (neg.length ? ', and the most opposite' : '') + '. Pearson r on log₂(value + 1); no p-value, since the samples are not independent.</p>' +
        '<a class="ge-more" href="' + esc(toolsHref('coexp', { id: geneId })) + '">All 50 each way, with their profiles</a>';
      renderFooter();
    }
    function drawPan(c, d) {
      toolsData.pan = d;
      var w = c.querySelector('.ge-tool-wait');
      var pg = d.pangene || {}, genomes = (d.nam && d.nam.genomes) || [];
      if (!genomes.length) { w.outerHTML = '<p class="ge-panel-key">This gene is in no Pan-Zea v4 pan-gene.</p>'; return; }
      var counts = {};
      var cells = genomes.map(function (g) {
        var st = g.state || 'not measured';
        counts[st] = (counts[st] || 0) + 1;
        var copies = g.members && g.members.length > 1 ? ', ' + g.members.length + ' copies' : '';
        return '<i class="ge-nam-cell is-' + esc(st.replace(' ', '-')) + '" title="' + esc(g.short + ': ' + (STATE_TEXT[st] || st) + copies) + '"></i>';
      }).join('');
      var key = ['expressed', 'low', 'silent', 'absent', 'not measured'].filter(function (k) { return counts[k]; }).map(function (k) {
        return '<li><i class="ge-nam-cell is-' + esc(k.replace(' ', '-')) + '"></i>' + esc(STATE_TEXT[k]) + ' <span>' + counts[k] + '</span></li>';
      }).join('');
      var h4 = c.querySelector('h4');
      if (h4 && pg.name) { h4.innerHTML = 'Across the 26 NAM genomes <small>' + esc(String(pg.name).replace('pan-zea.v4.', '')) + (pg['class'] ? ' · ' + esc(pg['class']) : '') + '</small>'; }
      var sentence = 'Expressed in ' + (pg.expressed || 0) + ', low in ' + (pg.low || 0) + ', silent in ' + (pg.silent || 0) + ' of the ' + (pg.present || 0) +
        ' genomes that carry it, in the 23 samples they share' +
        (pg.conservation != null ? '; tissue profiles agree at mean r = ' + Number(pg.conservation).toFixed(2) + (pg.divergent ? ' (least alike: ' + esc(pg.divergent) + ')' : '') : '') + '.';
      w.outerHTML = '<div class="ge-nam" role="img" aria-label="' + esc(genomes.length + ' NAM genomes: ' + Object.keys(counts).map(function (k) { return counts[k] + ' ' + k; }).join(', ')) + '">' + cells + '</div>' +
        '<ul class="ge-key">' + key + '</ul>' +
        '<p class="ge-panel-key">' + sentence + '</p>' +
        '<span class="ge-more-row"><a class="ge-more" href="' + esc(toolsHref('pangene', { id: geneId })) + '">Every copy’s profile</a>' +
        (pg.exemplar_gene || pg.name ? ' <span class="ge-tip-muted">·</span> <a class="ge-more" href="/pan_gene_center/pan_gene/' + encodeURIComponent(pg.exemplar_gene || pg.name) + '">Pan-gene record</a>' : '') + '</span>';
    }
    function drawRank(c, d) {
      toolsData.rank = d;
      var w = c.querySelector('.ge-tool-wait');
      if (!d.best) { w.outerHTML = '<p class="ge-panel-key">No sample measured it.</p>'; return; }
      var top = (d.top || []).slice(0, 5), best = d.best;
      var rows = top.map(function (t) {
        return '<li><span class="ge-co-name"><span class="ge-top-tissue ' + tissueClass(t.tissue) + '"></span>' + esc(t.label) + ' <small>' + esc(shortStudy(t.source)) + '</small></span>' +
          '<span class="ge-co-track"><i style="width:' + Number(t.percentile).toFixed(1) + '%"></i></span><span class="ge-co-r">' + Number(t.percentile).toFixed(1) + '</span></li>';
      }).join('');
      w.outerHTML = '<div class="ge-rank-best"><b>top ' + Math.max(0.1, 100 - best.percentile).toFixed(1) + '%</b><span>of genes in ' + esc(best.label) + ' <small>' + esc(shortStudy(best.source)) + '</small></span></div>' +
        '<ol class="ge-co ge-rank">' + rows + '</ol>' +
        '<p class="ge-panel-key">Percentile among all genes in the sample: the ' + top.length + ' samples where it ranks highest, of ' + fmt(d.samples) + '.</p>' +
        '<a class="ge-more" href="' + esc(toolsHref('gene', { id: geneId })) + '">Its rank in every sample</a>';
    }
    function renderTools() {
      toolsEl.innerHTML = '';
      if (!geneId || !genome || !window.fetch) { return; }
      var co = toolCard('Co-expressed genes', 'genes that move with it');
      var pan = toolCard('Across the 26 NAM genomes', 'in the 23 samples they share');
      var rank = toolCard('Rank among all genes', 'percentile in each sample');
      [co, pan, rank].forEach(function (x) { toolsEl.appendChild(x); });
      whenVisible(toolsEl, function () {
        toolsGet('coexpression', { genome: genome, id: geneId, n: 8 }).then(function (d) { drawCo(co, d); }, function (e) {
          toolFail(co, 'Co-expression is not available right now.', toolsHref('coexp', { id: geneId }), 'Try it in Expression Tools'); });
        toolsGet('pangene', { id: geneId }).then(function (d) { drawPan(pan, d); }, function (e) {
          toolFail(pan, e && e.message ? e.message : 'The pan-genome view is not available right now.', null, ''); });
        toolsGet('rank', { genome: genome, id: geneId, n: 5 }).then(function (d) { drawRank(rank, d); }, function (e) {
          toolFail(rank, 'The rank is not available right now.', toolsHref('gene', { id: geneId }), 'See it in Expression Tools'); });
      });
    }

    function renderAll() {
      renderTiles();
      renderToolbar();
      renderLegend();
      drawChart();
      renderPanels();
      renderSources();
      renderFooter();
    }
    renderAll();
    renderTools();

    if (window.ResizeObserver) {
      var lastW = stage.clientWidth;
      new window.ResizeObserver(function () {
        if (stage.clientWidth && stage.clientWidth !== lastW) { lastW = stage.clientWidth; drawChart(); }
      }).observe(stage);
    }
    return true;
  }

  MGDB.geneExpression = geneExpression;
})(window, document);
