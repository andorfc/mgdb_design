/* file: mgdb-exptools-genes.js
 *
 * purpose: Expression Tools -- the gene tools and the drawing helpers the
 *          other tool files share: the overview, the gene report, genes in a
 *          region, a gene list, the expression plot, two genes compared, and
 *          the heatmap. Registers on MGDB.ET (mgdb-exptools-core.js, loaded
 *          first).
 *
 *          The gene report reads the gene model and its protein domains from
 *          /api/v1/data/gene-models and /api/v1/data/domains (B73 v5), the
 *          same datasets the gene record page draws from.
 *
 * history:
 *  09/24/26  claude  created
 */
(function (window, document) {
  'use strict';

  var ET = window.MGDB.ET;
  var U = ET.util, UI = ET.ui, C = ET.chart, S = ET.stats;
  var esc = U.esc, fmt = U.fmt, fmtInt = U.fmtInt, el = UI.el, qs = UI.qs, qsa = UI.qsa;

  /* ------------------------------------------------------------------------
     Shared drawing helpers
     ------------------------------------------------------------------------ */

  var EX = ET.expr = {};

  /* Samples in the order bars are drawn: by study, and inside a study by
     tissue in the palette's order, so only tissues next to each other in the
     validated order ever touch. 'value' sorts by the first gene; 'tissue'
     groups by tissue across studies. */
  EX.order = function (samples, mode, gene) {
    var list = samples.slice();
    var t = function (s) { return ET.TISSUES.indexOf(s.tissue); };
    if (mode === 'value' && gene && gene.values) {
      list.sort(function (a, b) {
        var x = gene.values[a.idx], y = gene.values[b.idx];
        if (x == null && y == null) { return 0; }
        if (x == null) { return 1; }
        if (y == null) { return -1; }
        return y - x;
      });
    } else if (mode === 'tissue') {
      list.sort(function (a, b) { return t(a) - t(b) || a.idx - b.idx; });
    } else {
      var studyPos = {};
      samples.forEach(function (s) { if (studyPos[s.study] == null) { studyPos[s.study] = s.idx; } });
      list.sort(function (a, b) { return studyPos[a.study] - studyPos[b.study] || t(a) - t(b) || a.idx - b.idx; });
    }
    return list;
  };

  /* Alternating study bands behind a bar chart drawn on positions 0..n-1,
     with the study named above its band where there is room. Room is
     measured: plotWidth (px) sets how wide a band is, a label is placed only
     if its band is at least half as wide as the label and it clears the
     label before it. Without a width, a band of a fortieth of the samples
     gets a label, which at 300 samples let neighboring labels overprint. */
  EX.studyBands = function (ordered, horizontal, plotWidth) {
    var shapes = [], annotations = [];
    var runs = [];
    ordered.forEach(function (s, i) {
      var last = runs[runs.length - 1];
      if (last && last.study === s.study) { last.end = i; } else { runs.push({ study: s.study, name: s.studyName, start: i, end: i }); }
    });
    if (runs.length < 2) { return { shapes: shapes, annotations: annotations, runs: runs }; }
    var pxPer = plotWidth ? plotWidth / Math.max(1, ordered.length) : 0;
    var lastRight = -Infinity;
    runs.forEach(function (r, k) {
      if (k % 2 === 0) {
        shapes.push(horizontal
          ? { type: 'rect', xref: 'paper', yref: 'y', x0: 0, x1: 1, y0: r.start - 0.5, y1: r.end + 0.5, fillcolor: '#f3f5f2', line: { width: 0 }, layer: 'below' }
          : { type: 'rect', xref: 'x', yref: 'paper', x0: r.start - 0.5, x1: r.end + 0.5, y0: 0, y1: 1, fillcolor: '#f3f5f2', line: { width: 0 }, layer: 'below' });
      }
      if (horizontal) { return; }
      var text = U.shortStudy(r.name);
      var fits;
      if (pxPer) {
        var labelW = text.length * 5.6 + 8, bandW = (r.end - r.start + 1) * pxPer;
        var center = (r.start + r.end + 1) / 2 * pxPer;
        fits = bandW >= labelW / 2 && center - labelW / 2 >= lastRight && center + labelW / 2 <= plotWidth + 8;
        if (fits) { lastRight = center + labelW / 2 + 6; }
      } else {
        fits = r.end - r.start >= Math.max(3, ordered.length / 40);
      }
      if (fits) {
        annotations.push({ xref: 'x', yref: 'paper', x: (r.start + r.end) / 2, y: 1, yanchor: 'bottom', showarrow: false, captureevents: true,
                           text: esc(text), font: { size: 10, color: ET.MUTED } });
      }
    });
    return { shapes: shapes, annotations: annotations, runs: runs };
  };

  /* Bars of one or more genes over the selected samples. One gene: colored
     by tissue, grouped by study. Several: grouped bars, one color per gene
     in the fixed series order. Missing is a gap and is counted, never a
     zero-height bar. -> {used, missing} */
  EX.bars = function (node, genes, opts) {
    opts = opts || {};
    var scale = opts.scale || 'linear';
    var samples = opts.samples || ET.SEL.list();
    var withValues = genes.filter(function (g) { return g.values; });
    if (!withValues.length) { C.clear(node); node.appendChild(UI.empty('No values', 'This gene has no expression values in this release.')); return Promise.resolve({ used: 0, missing: [] }); }
    var first = withValues[0];
    var ordered = EX.order(samples, opts.order || 'study', first);
    var present = ordered.filter(function (s) { return withValues.some(function (g) { return g.values[s.idx] != null; }); });
    var missing = ordered.filter(function (s) { return first.values[s.idx] == null; });
    if (!present.length) {
      C.clear(node);
      node.appendChild(UI.empty('Not measured', 'None of the selected samples measured this gene. Widen the selection.'));
      return Promise.resolve({ used: 0, missing: missing });
    }
    var tf = function (v) { return v == null ? null : (scale === 'log' ? S.log2p1(v) : v); };
    var unit = opts.unit || 'expression';
    var axisTitle = scale === 'log' ? 'log₂(' + unit + ' + 1)' : unit;
    var horizontal = present.length * withValues.length <= 40;
    var pos = present.map(function (_, i) { return i; });
    var hover = function (g, s) {
      return '<b>' + esc(fmt(g.values[s.idx])) + '</b> ' + esc(unit) + (withValues.length > 1 ? ' · ' + esc(g.symbol || g.gene) : '') +
        '<br>' + esc(s.label) + '<br>' + esc(U.shortStudy(s.studyName)) + ' · ' + esc(s.tissue) + (s.condition ? ' · ' + esc(s.condition) : '');
    };
    var traces = [];
    if (withValues.length === 1) {
      var byTissue = {};
      present.forEach(function (s, i) { (byTissue[s.tissue] = byTissue[s.tissue] || []).push(i); });
      ET.TISSUES.forEach(function (t) {
        var idx = byTissue[t];
        if (!idx) { return; }
        var vals = idx.map(function (i) { return tf(first.values[present[i].idx]); });
        traces.push({
          type: 'bar', name: t, orientation: horizontal ? 'h' : 'v',
          x: horizontal ? vals : idx, y: horizontal ? idx : vals,
          marker: { color: C.tissueColor(t), line: { width: 0 } },
          hovertext: idx.map(function (i) { return hover(first, present[i]); }), hoverinfo: 'text', hoverlabel: { namelength: 0 },
          customdata: idx.map(function (i) { return present[i].id; })
        });
      });
    } else {
      withValues.forEach(function (g, k) {
        var vals = present.map(function (s) { return tf(g.values[s.idx]); });
        traces.push({
          type: 'bar', name: g.symbol ? g.symbol + ' (' + g.gene + ')' : g.gene, orientation: horizontal ? 'h' : 'v',
          x: horizontal ? vals : pos, y: horizontal ? pos : vals,
          marker: { color: C.seriesColor(k), line: { width: 0 } },
          hovertext: present.map(function (s) { return hover(g, s); }), hoverinfo: 'text', hoverlabel: { namelength: 0 },
          customdata: present.map(function (s) { return s.id; })
        });
      });
    }
    /* The highest bars carry a marker (opts.marks: how many), numbered in
       the explorer's Highest row; hovering one names the sample. */
    if (opts.marks && withValues.length === 1 && !horizontal) {
      var ranked = present.map(function (s, i) { return { i: i, v: first.values[s.idx] }; })
        .filter(function (x) { return x.v != null && x.v > 0; }).sort(function (a, b) { return b.v - a.v; }).slice(0, opts.marks);
      if (ranked.length) {
        var lift = tf(ranked[0].v) * 0.04;
        traces.push({
          type: 'scatter', mode: 'markers', x: ranked.map(function (x) { return x.i; }), y: ranked.map(function (x) { return tf(x.v) + lift; }),
          marker: { symbol: 'triangle-down', size: 8, color: ET.INK }, cliponaxis: false, hoverinfo: 'text', showlegend: false,
          hovertext: ranked.map(function (x, k) { return '<b>' + (k + 1) + '</b> · ' + esc(present[x.i].label) + '<br>' + esc(fmt(x.v)) + ' ' + esc(unit); }),
          hoverlabel: { namelength: 0 }, customdata: ranked.map(function (x) { return present[x.i].id; })
        });
      }
    }
    var bands = EX.studyBands(present, horizontal, Math.max(200, (node.clientWidth || 800) - 76));
    var labels = present.map(function (s) { return s.label.length > 38 ? s.label.slice(0, 36) + '…' : s.label; });
    var catAxis = horizontal
      ? { tickmode: 'array', tickvals: pos, ticktext: labels, autorange: 'reversed', tickfont: { size: 11 }, showgrid: false }
      : { tickmode: 'array', tickvals: [], showticklabels: false, showgrid: false, range: [-0.6, present.length - 0.4],
          title: { text: fmtInt(present.length) + ' samples, ' + (opts.order === 'tissue' ? 'grouped by tissue' : opts.order === 'value' ? 'sorted by value' : 'grouped by study') +
                         (opts.explorer ? ' · drag the bracket, or click a study’s name' : ' (hover for names)'), font: { size: 11, color: ET.MUTED } } };
    var valAxis = { title: { text: axisTitle }, rangemode: 'tozero', zeroline: true };
    var height = horizontal ? Math.max(260, present.length * (withValues.length > 1 ? 10 + 12 * withValues.length : 22) + 90) : (opts.height || 380);
    var layout = {
      barmode: withValues.length > 1 ? 'group' : 'overlay',
      bargap: horizontal ? 0.25 : (present.length > 150 ? 0.08 : 0.2),
      xaxis: horizontal ? valAxis : catAxis, yaxis: horizontal ? catAxis : valAxis,
      shapes: bands.shapes, annotations: bands.annotations,
      margin: horizontal ? { l: 12, r: 16, t: 12, b: 44 } : { l: 64, r: 12, t: bands.annotations.length ? 24 : 12, b: 40 },
      showlegend: withValues.length > 1 && !opts.legendHost
    };
    /* Several genes: their key goes in the figure's legend slot above the
       plot, as HTML, where Plotly's own legend shared the top margin with
       the study names and overprinted them. */
    if (opts.legendHost) {
      opts.legendHost.innerHTML = '';
      if (withValues.length > 1) {
        var key = el('<ul class="et-legend" aria-label="Genes"></ul>');
        withValues.forEach(function (g, k) {
          var li = el('<li><span class="et-swatch"></span><span></span></li>');
          li.firstChild.style.background = C.seriesColor(k);
          li.lastChild.textContent = g.symbol ? g.symbol + ' (' + g.gene + ')' : g.gene;
          key.appendChild(li);
        });
        opts.legendHost.appendChild(key);
      }
    }
    /* The mean is a line; its value goes in the caption (result.mean), since
       a label on the line sat on top of the bars it crossed. */
    var m = null;
    if (opts.mean && withValues.length === 1) {
      var vals = present.map(function (s) { return first.values[s.idx]; }).filter(function (v) { return v != null; });
      m = S.mean(vals);
      var mv = tf(m);
      layout.shapes = layout.shapes.concat([horizontal
        ? { type: 'line', xref: 'x', yref: 'paper', x0: mv, x1: mv, y0: 0, y1: 1, line: { color: '#7a2c43', width: 1.5 } }
        : { type: 'line', xref: 'paper', yref: 'y', x0: 0, x1: 1, y0: mv, y1: mv, line: { color: '#7a2c43', width: 1.5 } }]);
    }
    return C.plot(node, traces, layout, { height: height, filename: opts.filename }).then(function () {
      if (opts.onClick && node.on) {
        if (node.removeAllListeners) { node.removeAllListeners('plotly_click'); }
        node.on('plotly_click', function (ev) {
          var p = ev.points && ev.points[0];
          if (p) { opts.onClick(present[horizontal ? p.y : p.x]); }
        });
      }
      if (opts.onStudy && node.on) {
        if (node.removeAllListeners) { node.removeAllListeners('plotly_clickannotation'); }
        node.on('plotly_clickannotation', function (ev) {
          var x = ev.annotation ? ev.annotation.x : null;
          var run = bands.runs.filter(function (r) { return x != null && Math.abs((r.start + r.end) / 2 - x) < 0.001; })[0];
          if (run) { opts.onStudy(run); }
        });
      }
      return { used: present.length, missing: missing, ordered: present, mean: m, horizontal: horizontal, runs: bands.runs };
    });
  };

  /* ------------------------------------------------------------------------
     The profile explorer: the samples of a bar strip as readable rows

     A strip of three hundred bars shows the pattern and hides the names.
     Under it, the explorer lists a window of the strip as horizontal bars
     with their labels and values, and marks that window on the strip with
     a bracket the reader drags by its grip, resizes by its edges, or moves
     with the arrow keys; a click on a bar centers the window on it, a click
     on a study's name snaps the window to that study. Two other readings:
     every sample in columns, and one small panel per study, each on its own
     scale, because units differ between studies. A find box narrows the
     rows and dims the other bars on the strip. The rows are also the
     figure's text alternative. Built once per figure; update(result,
     genes, opts) after every EX.bars() call. The strip's horizontal form
     (40 bars or fewer) already carries its labels, so the explorer stays
     hidden for it.
     ------------------------------------------------------------------------ */
  EX.explorer = function (fig, opts) {
    opts = opts || {};
    var node = fig.plotNode;
    var MIN = 6, MAX = 160, DEFAULT = 24;
    var st = { mode: opts.mode || 'window', start: Math.max(0, opts.start || 0), size: Math.max(MIN, Math.min(MAX, opts.size || DEFAULT)),
               filter: '', ordered: [], genes: [], scale: 'linear', order: 'study', unit: '', max: 0, heads: [], groups: [], top: [],
               focus: null, pending: null, auto: opts.start == null };
    var baseLabel = node.getAttribute('aria-label') || '';

    var block = el('<div class="et-px" hidden></div>');
    var bar = el('<div class="et-px-bar"></div>');
    var modeCtl = UI.segmented([
      { value: 'window', label: 'Window', title: 'The samples inside the bracket on the strip, with their names' },
      { value: 'all', label: 'All, in columns', title: 'Every sample of the strip, in columns' },
      { value: 'study', label: 'By study', title: 'One small panel per study, each on its own scale' },
      { value: 'none', label: 'Strip only', title: 'The strip alone' }
    ], st.mode, function (v) { st.mode = v; render(); place(); changed(); }, 'How the samples are listed');
    /* A phone gets the short form of each label; the long one stays for
       screen readers and wider screens. */
    qsa('button', modeCtl).forEach(function (b) {
      var short = { window: 'Window', all: 'Columns', study: 'By study', none: 'Strip' }[b.getAttribute('data-v')];
      b.innerHTML = '<span class="et-px-long">' + b.innerHTML + '</span><span class="et-px-short" aria-hidden="true">' + short + '</span>';
    });
    var find = el('<label class="et-px-find"><span class="mgdb-visually-hidden">Find a sample</span><input type="search" class="et-input" placeholder="Find a sample, tissue or study" autocomplete="off" spellcheck="false"></label>');
    var findIn = qs('input', find);
    findIn.addEventListener('input', U.debounce(function () { st.filter = findIn.value.trim().toLowerCase(); render(); dim(); }, 150));
    var status = el('<span class="et-px-status" role="status"></span>');
    var nav = el('<span class="et-px-nav"></span>');
    var prev = UI.button('Previous', { title: 'The window before this one', onClick: function () { move(st.start - st.size); } });
    var next = UI.button('Next', { title: 'The window after this one', onClick: function () { move(st.start + st.size); } });
    nav.appendChild(prev);
    nav.appendChild(next);
    bar.appendChild(modeCtl);
    bar.appendChild(find);
    bar.appendChild(status);
    bar.appendChild(nav);
    var marks = el('<div class="et-px-marks" hidden></div>');
    var body = el('<div class="et-px-body"></div>');
    block.appendChild(bar);
    block.appendChild(marks);
    block.appendChild(body);
    fig.insertBefore(block, qs('.et-figure-table', fig));

    /* The bracket over the strip: a grip below the plot area to move it,
       an edge on each side to resize it. The fill lets pointer events
       through, so the bars under the window keep their hover. */
    var brush = el('<div class="et-brush" aria-hidden="true" hidden><div class="et-brush-fill"></div><div class="et-brush-edge is-left"></div>' +
                   '<div class="et-brush-edge is-right"></div><div class="et-brush-grip" title="Drag to move the window; drag an edge to resize it"></div></div>');
    fig.appendChild(brush);

    function tfn() { return st.scale === 'log' ? function (v) { return S.log2p1(v); } : function (v) { return v; }; }
    function clampStart(s, size) { return Math.max(0, Math.min(Math.max(0, st.ordered.length - size), Math.round(s))); }
    function geometry() {
      var fl = node._fullLayout;
      if (!fl || !fl.xaxis || !fl._size || typeof fl.xaxis.c2p !== 'function') { return null; }
      return { xa: fl.xaxis, size: fl._size };
    }
    function place() {
      var g = geometry();
      if (!g || block.hidden || st.mode === 'none' || st.mode === 'study' || !st.ordered.length) { brush.hidden = true; return; }
      var x0 = g.xa._offset + g.xa.c2p(st.start - 0.5), x1 = g.xa._offset + g.xa.c2p(st.start + st.size - 0.5);
      brush.style.left = (node.offsetLeft + x0) + 'px';
      brush.style.width = Math.max(4, x1 - x0) + 'px';
      brush.style.top = (node.offsetTop + g.size.t) + 'px';
      brush.style.height = g.size.h + 'px';
      brush.hidden = false;
    }
    function changed() { if (opts.onChange) { opts.onChange({ mode: st.mode, start: st.start, size: st.size }); } }
    function toWindow() { if (st.mode !== 'window') { st.mode = 'window'; modeCtl.set('window'); } }
    function move(s) { st.start = clampStart(s, st.size); toWindow(); place(); render(); changed(); }
    function snapRange(start, end) {
      st.size = Math.min(MAX, Math.max(MIN, end - start + 1));
      st.start = clampStart(start, st.size);
      toWindow(); place(); render(); changed();
    }
    function runOfStudy(study) {
      var found = null;
      st.heads.forEach(function (h) { if (found === null && h.study != null && String(h.study) === String(study)) { found = h; } });
      return found;
    }
    function snapStudy(study) {
      if (st.order !== 'study' && opts.setOrder) { st.pending = study; opts.setOrder('study'); return; }
      var run = runOfStudy(study);
      if (run) { snapRange(run.start, run.end); }
    }
    function focusSample(sample) {
      var i = -1;
      st.ordered.forEach(function (s, k) { if (i < 0 && s.id === sample.id) { i = k; } });
      if (i < 0) { return; }
      st.focus = sample.id;
      if (i < st.start || i >= st.start + st.size) { st.start = clampStart(i - Math.floor(st.size / 2), st.size); }
      toWindow(); place(); render(); changed();
    }

    var drag = null;
    function pxPer() { var g = geometry(); return g ? Math.abs(g.xa.c2p(1) - g.xa.c2p(0)) : 0; }
    function onDown(kind) {
      return function (e) {
        if (e.button != null && e.button !== 0) { return; }
        drag = { kind: kind, x: e.clientX, start: st.start, size: st.size, per: pxPer() || 1 };
        try { e.target.setPointerCapture(e.pointerId); } catch (x) { /* older engines */ }
        e.preventDefault();
      };
    }
    function onMove(e) {
      if (!drag) { return; }
      var d = Math.round((e.clientX - drag.x) / drag.per);
      if (drag.kind === 'grip') {
        st.start = clampStart(drag.start + d, st.size);
      } else if (drag.kind === 'left') {
        var s = Math.max(0, Math.min(drag.start + d, drag.start + drag.size - MIN));
        st.size = Math.min(MAX, drag.start + drag.size - s);
        st.start = s;
      } else {
        st.size = Math.max(MIN, Math.min(MAX, drag.size + d));
        st.start = clampStart(drag.start, st.size);
      }
      place();
    }
    function onUp() { if (!drag) { return; } drag = null; render(); changed(); }
    qs('.et-brush-grip', brush).addEventListener('pointerdown', onDown('grip'));
    qs('.is-left', brush).addEventListener('pointerdown', onDown('left'));
    qs('.is-right', brush).addEventListener('pointerdown', onDown('right'));
    brush.addEventListener('pointermove', onMove);
    brush.addEventListener('pointerup', onUp);
    brush.addEventListener('pointercancel', onUp);
    function onKey(e) {
      if (block.hidden || st.mode === 'none' || !st.ordered.length) { return; }
      var step = e.shiftKey ? 1 : st.size;
      if (e.key === 'ArrowRight') { move(st.start + step); }
      else if (e.key === 'ArrowLeft') { move(st.start - step); }
      else if (e.key === 'Home') { move(0); }
      else if (e.key === 'End') { move(st.ordered.length); }
      else { return; }
      e.preventDefault();
    }
    node.addEventListener('keydown', onKey);
    window.addEventListener('resize', place);

    function headsOf(ordered, order) {
      var out = [];
      if (order === 'value') { return out; }
      ordered.forEach(function (s, i) {
        var key = order === 'tissue' ? s.tissue : s.study;
        var last = out[out.length - 1];
        if (last && last.key === key) { last.end = i; }
        else { out.push({ key: key, label: order === 'tissue' ? s.tissue : U.shortStudy(s.studyName), study: order === 'tissue' ? null : s.study, start: i, end: i }); }
      });
      return out;
    }
    function matcher() {
      var q = st.filter;
      return function (s) { return (s.label + ' ' + s.studyName + ' ' + s.tissue + ' ' + (s.condition || '')).toLowerCase().indexOf(q) >= 0; };
    }
    function setLabel() {
      var n = st.ordered.length;
      var extra = (!block.hidden && st.mode !== 'none' && n) ? '; the window covers samples ' + (st.start + 1) + ' to ' + Math.min(n, st.start + st.size) + ' of ' + n + ', listed below; the arrow keys move it' : '';
      node.setAttribute('aria-label', baseLabel + extra);
    }
    function rowHtml(i, compact) {
      var s = st.ordered[i], tf = tfn(), one = st.genes.length === 1;
      var bars = st.genes.map(function (g, k) {
        var v = g.values[s.idx];
        var w = (v == null || st.max <= 0) ? 0 : Math.max(v > 0 ? 0.6 : 0, tf(v) / st.max * 100);
        return '<i style="width:' + w.toFixed(2) + '%;background:' + (one ? C.tissueColor(s.tissue) : C.seriesColor(k)) + '"' + (v == null ? ' data-missing' : '') + ' title="' + esc(v == null ? 'not measured' : fmt(v) + ' ' + st.unit) + '"></i>';
      }).join('');
      var vals = st.genes.map(function (g) { var v = g.values[s.idx]; return v == null ? '—' : fmt(v); }).join(' · ');
      var title = s.label + ' · ' + U.shortStudy(s.studyName) + ' · ' + s.tissue + (s.condition ? ' · ' + s.condition : '');
      return '<li class="et-px-row' + (st.focus === s.id ? ' is-focus' : '') + '" data-i="' + i + '"><span class="et-px-label" title="' + esc(title) + '">' +
        '<span class="et-swatch" style="background:' + C.tissueColor(s.tissue) + '"></span>' + esc(s.label) + (compact ? '' : ' <span class="et-muted">' + esc(s.tissue) + '</span>') + '</span>' +
        '<span class="et-px-bars">' + bars + '</span><span class="et-px-val">' + esc(vals) + '</span></li>';
    }
    function headHtml(h) {
      var n = h.end - h.start + 1;
      return '<li class="et-px-hd"><button type="button" class="et-px-hdbtn" data-head="' + h.start + '" title="Fit the window to ' + esc(h.label) + '">' + esc(h.label) + ' <span>' + n + '</span></button></li>';
    }
    function panels() {
      var grid = el('<div class="et-px-panels"></div>');
      var tf = tfn(), one = st.genes.length === 1;
      st.groups.forEach(function (g) {
        var sm = 0, raw = 0, svg = '';
        g.idx.forEach(function (i) { st.genes.forEach(function (ge) { var v = ge.values[st.ordered[i].idx]; if (v != null) { sm = Math.max(sm, tf(v)); raw = Math.max(raw, v); } }); });
        var n = g.idx.length, slot = 150 / n, gw = slot / st.genes.length;
        g.idx.forEach(function (i, q) {
          st.genes.forEach(function (ge, gi) {
            var v = ge.values[st.ordered[i].idx];
            if (v == null || sm <= 0 || v <= 0) { return; }
            var hh = Math.max(1, tf(v) / sm * 36);
            svg += '<rect x="' + (q * slot + gi * gw).toFixed(2) + '" y="' + (40 - hh).toFixed(1) + '" width="' + Math.max(0.8, gw - 0.4).toFixed(2) + '" height="' + hh.toFixed(1) + '" fill="' + (one ? C.tissueColor(st.ordered[i].tissue) : C.seriesColor(gi)) + '"/>';
          });
        });
        grid.appendChild(el('<button type="button" class="et-px-panel" data-study="' + esc(String(g.study)) + '" title="Open ' + esc(U.shortStudy(g.name)) + ' in the window">' +
          '<span class="et-px-panel-head"><span class="et-px-panel-name">' + esc(U.shortStudy(g.name)) + '</span><span class="et-px-panel-n">' + n + '</span></span>' +
          '<svg viewBox="0 0 150 42" preserveAspectRatio="none" aria-hidden="true">' + svg + '<line x1="0" x2="150" y1="40.5" y2="40.5" stroke="' + ET.LINE + '" stroke-width="1"/></svg>' +
          '<span class="et-px-panel-max">max ' + esc(fmt(raw)) + (raw > 0 ? ' ' + esc(st.unit) : '') + '</span></button>'));
      });
      grid.addEventListener('click', function (e) { var b = e.target.closest('[data-study]'); if (b) { snapStudy(b.getAttribute('data-study')); } });
      return grid;
    }
    function render() {
      var n = st.ordered.length;
      if (!n) { return; }
      var match = st.filter ? matcher() : null;
      prev.disabled = st.start <= 0;
      next.disabled = st.start + st.size >= n;
      nav.hidden = st.mode !== 'window' || !!match;
      marks.hidden = !st.top.length || st.mode === 'none' || st.mode === 'study';
      body.innerHTML = '';
      if (st.mode === 'none') { status.textContent = ''; setLabel(); return; }
      if (st.mode === 'study') { body.appendChild(panels()); status.textContent = fmtInt(st.groups.length) + ' studies, each on its own scale'; setLabel(); return; }
      var list = [], i;
      if (match) { for (i = 0; i < n; i++) { if (match(st.ordered[i])) { list.push(i); } } }
      else if (st.mode === 'window') { for (i = st.start; i < Math.min(n, st.start + st.size); i++) { list.push(i); } }
      else { for (i = 0; i < n; i++) { list.push(i); } }
      var compact = st.mode === 'all' && !match;
      var html = [], h = 0, lastHead = null, names = [];
      list.forEach(function (i) {
        while (h < st.heads.length && st.heads[h].end < i) { h++; }
        var head = (h < st.heads.length && st.heads[h].start <= i) ? st.heads[h] : null;
        if (head && head !== lastHead) { html.push(headHtml(head)); lastHead = head; if (names.indexOf(head.label) < 0) { names.push(head.label); } }
        html.push(rowHtml(i, compact));
      });
      var ol = el('<ol class="et-px-list' + (compact ? ' et-px-cols' : '') + '"></ol>');
      ol.innerHTML = html.join('') || '<li class="et-muted">No sample matches.</li>';
      ol.addEventListener('click', function (e) {
        var b = e.target.closest('[data-head]');
        if (!b) { return; }
        var start = +b.getAttribute('data-head');
        st.heads.forEach(function (x) { if (x.start === start) { snapRange(x.start, x.end); } });
      });
      body.appendChild(ol);
      if (match) { status.textContent = fmtInt(list.length) + ' of ' + fmtInt(n) + ' samples match “' + findIn.value.trim() + '”'; }
      else if (st.mode === 'window') {
        var end = Math.min(n, st.start + st.size);
        status.textContent = 'Samples ' + fmtInt(st.start + 1) + '–' + fmtInt(end) + ' of ' + fmtInt(n) + (names.length ? ' · ' + names.slice(0, 3).join(', ') + (names.length > 3 ? ' and ' + (names.length - 3) + ' more' : '') : '');
      } else { status.textContent = 'Every one of the ' + fmtInt(n) + ' samples'; }
      setLabel();
    }
    function renderMarks() {
      marks.innerHTML = '';
      if (!st.top.length) { return; }
      marks.appendChild(el('<span>Highest</span>'));
      st.top.forEach(function (t, k) {
        var s = st.ordered[t.i];
        var b = el('<button type="button" class="mgdb-chip" data-i="' + t.i + '" title="' + esc(s.label + ' · ' + U.shortStudy(s.studyName) + ' · ' + fmt(t.v) + ' ' + st.unit) + '"><b>' + (k + 1) + '</b>' + esc(s.label.length > 22 ? s.label.slice(0, 20) + '…' : s.label) + ' <span class="et-muted">' + esc(U.shortStudy(s.studyName)) + ' · ' + esc(fmt(t.v)) + '</span></button>');
        b.addEventListener('click', function () { focusSample(s); });
        marks.appendChild(b);
      });
    }
    function dim() {
      if (!window.Plotly || !node.data || !node._fullLayout) { return; }
      var match = st.filter ? matcher() : null;
      var byId = {};
      st.ordered.forEach(function (s) { byId[s.id] = s; });
      var values = [], idx = [];
      node.data.forEach(function (t, k) {
        if (t.type !== 'bar' || !t.customdata) { return; }
        values.push(match ? t.customdata.map(function (id) { var s = byId[id]; return s && match(s) ? 1 : 0.12; }) : 1);
        idx.push(k);
      });
      if (idx.length) { try { window.Plotly.restyle(node, { 'marker.opacity': values }, idx); } catch (e) { /* redrawn meanwhile */ } }
    }

    function update(r, genes, o) {
      o = o || {};
      st.genes = (genes || []).filter(function (g) { return g && g.values; });
      st.scale = o.scale || 'linear';
      st.order = o.order || 'study';
      st.unit = o.unit || '';
      if (!r || r.horizontal || !r.ordered || r.ordered.length < 2 || !st.genes.length) {
        st.ordered = [];
        block.hidden = true;
        brush.hidden = true;
        node.removeAttribute('tabindex');
        node.setAttribute('aria-label', baseLabel);
        return;
      }
      st.ordered = r.ordered;
      st.heads = headsOf(r.ordered, st.order);
      var byStudy = {}, groups = [];
      r.ordered.forEach(function (s, i) {
        var g = byStudy[s.study];
        if (!g) { g = byStudy[s.study] = { study: s.study, name: s.studyName, idx: [] }; groups.push(g); }
        g.idx.push(i);
      });
      st.groups = groups;
      var tf = tfn(), max = 0;
      st.genes.forEach(function (g) { r.ordered.forEach(function (s) { var v = g.values[s.idx]; if (v != null) { max = Math.max(max, tf(v)); } }); });
      st.max = max;
      var first = st.genes[0];
      st.top = opts.marks && st.genes.length === 1
        ? r.ordered.map(function (s, i) { return { i: i, v: first.values[s.idx] }; }).filter(function (x) { return x.v != null && x.v > 0; })
            .sort(function (a, b) { return b.v - a.v; }).slice(0, opts.marks)
        : [];
      if (st.pending != null) {
        var run = runOfStudy(st.pending);
        st.pending = null;
        if (run) { st.size = Math.min(MAX, Math.max(MIN, run.end - run.start + 1)); st.start = run.start; toWindow(); }
      }
      /* A link that carries no window opens on the gene's highest sample,
         so the first rows are the ones worth reading; after that the
         window stays where the reader put it. */
      if (st.auto) { st.auto = false; if (st.top.length) { st.start = st.top[0].i - Math.floor(st.size / 2); } }
      st.start = clampStart(st.start, st.size);
      block.hidden = false;
      node.setAttribute('tabindex', '0');
      if (!node._etExplorerBound && node.on) { node._etExplorerBound = true; node.on('plotly_afterplot', place); }
      renderMarks();
      render();
      place();
      dim();
    }
    function destroy() {
      window.removeEventListener('resize', place);
      node.removeEventListener('keydown', onKey);
      brush.hidden = true;
    }
    return { update: update, focusSample: focusSample, snapRange: snapRange, snapStudy: snapStudy, destroy: destroy, state: function () { return st; } };
  };

  /* One row per gene over the selected samples. */
  EX.summarize = function (g, samples) {
    var vals = [];
    var top = null;
    samples.forEach(function (s) {
      var v = g.values ? g.values[s.idx] : null;
      if (v == null) { return; }
      vals.push(v);
      if (!top || v > top.v) { top = { s: s, v: v }; }
    });
    return {
      n: vals.length, mean: vals.length ? S.mean(vals) : null, median: vals.length ? S.median(vals) : null,
      max: top ? top.v : null, top: top ? top.s : null,
      detected: vals.filter(function (v) { return v >= 1; }).length,
      tau: vals.length > 1 ? S.tau(vals, 1) : null
    };
  };

  function niceStep(span) {
    var raw = span / 6, p = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
    var steps = [1, 2, 5, 10];
    for (var i = 0; i < steps.length; i++) { if (steps[i] * p >= raw) { return steps[i] * p; } }
    return p * 10;
  }
  function bpLabel(v, step) {
    if (step >= 1e6 || v >= 1e7) { return (v / 1e6).toFixed(step >= 1e6 ? 0 : (step >= 1e5 ? 1 : 2)) + ' Mb'; }
    if (v >= 1e3) { return (v / 1e3).toFixed(step >= 1e3 ? 0 : 1) + ' kb'; }
    return v + ' bp';
  }

  /* Genes as strand arrows along a coordinate axis, shaded by a value on the
     sequential ramp (log scale). Pure SVG. genes: [{gene, symbol, start,
     end, strand, value}]. A gene with a symbol is named under its arrow, and
     lanes are packed on the wider of arrow and name, so no two names
     overprint; an id is too long to fit and stays in the hover. */
  EX.locus = function (container, genes, opts) {
    var W = Math.max(320, container.clientWidth || 900);
    var pad = 14, laneH = 36, top = 30;
    var start = opts.start, end = opts.end, span = Math.max(1, end - start);
    var x = function (p) { return pad + ((p - start) / span) * (W - 2 * pad); };
    var sorted = genes.slice().sort(function (a, b) { return a.start - b.start; });
    var laneEnd;
    /* Names cost lanes; past six, only the focus gene keeps its name. */
    var pack = function (names) {
      laneEnd = [];
      sorted.forEach(function (g) {
        var x0 = x(Math.max(g.start, start)), x1 = Math.max(x0 + 4, x(Math.min(g.end, end)));
        g._label = g.gene === opts.focus ? (g.symbol || g.gene) : (names ? g.symbol || '' : '');
        var lw = g._label ? g._label.length * 5.6 + 4 : 0, cx = (x0 + x1) / 2;
        var left = Math.min(x0, cx - lw / 2), right = Math.max(x1, cx + lw / 2);
        var lane = -1;
        for (var i = 0; i < laneEnd.length; i++) { if (laneEnd[i] + 6 < left) { lane = i; break; } }
        if (lane < 0) { lane = laneEnd.length; laneEnd.push(0); }
        laneEnd[lane] = right;
        g._lane = lane;
      });
    };
    pack(true);
    if (laneEnd.length > 6) { pack(false); }
    var lanes = Math.max(1, laneEnd.length);
    var axisY = top + lanes * laneH + 4, H = axisY + 34;
    var max = 0;
    genes.forEach(function (g) { if (isFinite(g.value) && g.value > max) { max = g.value; } });
    var shade = function (v) { return isFinite(v) ? C.seqColor(max > 0 ? Math.log(v + 1) / Math.log(max + 1) : 0) : '#d9dbd6'; };
    var step = niceStep(span);
    var svg = '<svg class="et-locus" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="group" aria-label="' + esc(opts.label || 'Genes in the region') + '">';
    for (var t = Math.ceil(start / step) * step; t <= end; t += step) {
      var tx = x(t), anchor = tx < 40 ? 'start' : tx > W - 40 ? 'end' : 'middle';
      svg += '<line x1="' + tx + '" x2="' + tx + '" y1="' + (top - 8) + '" y2="' + axisY + '" class="et-locus-grid"/>' +
             '<text x="' + tx + '" y="' + (top - 12) + '" class="et-locus-tick" text-anchor="' + anchor + '">' + bpLabel(t, step) + '</text>';
    }
    svg += '<line x1="' + pad + '" x2="' + (W - pad) + '" y1="' + axisY + '" y2="' + axisY + '" class="et-locus-axis"/>';
    sorted.forEach(function (g) {
      var x0 = x(Math.max(g.start, start)), x1 = Math.max(x0 + 4, x(Math.min(g.end, end)));
      var y = top + g._lane * laneH + 3, h = 14, head = Math.min(7, (x1 - x0) / 2);
      var path = g.strand === '-'
        ? 'M' + x0 + ',' + (y + h / 2) + ' L' + (x0 + head) + ',' + y + ' L' + x1 + ',' + y + ' L' + x1 + ',' + (y + h) + ' L' + (x0 + head) + ',' + (y + h) + ' Z'
        : 'M' + x0 + ',' + y + ' L' + (x1 - head) + ',' + y + ' L' + x1 + ',' + (y + h / 2) + ' L' + (x1 - head) + ',' + (y + h) + ' L' + x0 + ',' + (y + h) + ' Z';
      var focus = g.gene === opts.focus;
      var title = g.gene + (g.symbol ? ' (' + g.symbol + ')' : '') + '\n' + (g.chr || '') + ':' + fmtInt(g.start) + '–' + fmtInt(g.end) + ' ' + (g.strand || '') +
        '\n' + (opts.what || 'mean') + ': ' + (isFinite(g.value) ? fmt(g.value) : 'not measured');
      svg += '<a href="' + esc(ET.href('gene', { id: g.gene })) + '" class="et-locus-gene' + (focus ? ' is-focus' : '') + '"><title>' + esc(title) + '</title>' +
             '<path d="' + path + '" fill="' + shade(g.value) + '"/></a>';
      if (g._label) {
        svg += '<text x="' + ((x0 + x1) / 2) + '" y="' + (y + h + 12) + '" class="et-locus-label' + (focus ? ' is-focus' : '') + '" text-anchor="middle">' + esc(g._label) + '</text>';
      }
    });
    var lx = W - 190;
    svg += '<g transform="translate(' + lx + ',' + (axisY + 22) + ')"><text x="-8" y="4" class="et-locus-tick" text-anchor="end">' + esc(opts.what || 'mean') + '</text>';
    for (var k = 0; k < 5; k++) { svg += '<rect x="' + (k * 26) + '" y="-5" width="24" height="10" rx="2" fill="' + C.seqColor(k / 4) + '"/>'; }
    svg += '<text x="0" y="-9" class="et-locus-tick">0</text><text x="128" y="-9" class="et-locus-tick" text-anchor="end">' + esc(fmt(max)) + '</text></g></svg>';
    container.innerHTML = svg;
  };

  /* The NAM genomes as a strip of 26 cells: expressed, low, silent, absent. */
  EX.namStrip = function (pg) {
    var nam = (ET.state.boot.pangenome && ET.state.boot.pangenome.nam_genomes) || [];
    var state = function (short) {
      if (pg.expressed_in.indexOf(short) !== -1) { return 'expressed'; }
      if (pg.low_in.indexOf(short) !== -1) { return 'low'; }
      if (pg.silent_in.indexOf(short) !== -1) { return 'silent'; }
      if (pg.absent_in.indexOf(short) !== -1) { return 'absent'; }
      return 'unmeasured';
    };
    var html = '<ul class="et-namstrip" aria-label="The 26 NAM genomes">';
    nam.forEach(function (g) {
      var st = state(g.short);
      var label = { expressed: 'expressed (reaches 1 FPKM)', low: 'low (0.1 to 1 FPKM)', silent: 'silent (under 0.1 FPKM)', absent: 'no member in this genome', unmeasured: 'present, not measured' }[st];
      html += '<li class="is-' + st + '" title="' + esc(g.short + ': ' + label) + '"><span class="et-namcell" aria-hidden="true"></span><span class="et-namname">' + esc(g.short) + '</span><span class="mgdb-visually-hidden">' + esc(label) + '</span></li>';
    });
    return html + '</ul>';
  };
  EX.namKey = function () {
    return '<ul class="et-namkey"><li class="is-expressed"><span class="et-namcell"></span>expressed, ≥ 1 FPKM</li><li class="is-low"><span class="et-namcell"></span>low, 0.1–1</li>' +
      '<li class="is-silent"><span class="et-namcell"></span>silent, &lt; 0.1</li><li class="is-absent"><span class="et-namcell"></span>no member</li>' +
      '<li class="is-unmeasured"><span class="et-namcell"></span>not measured</li></ul>';
  };

  /* A gene model: every transcript, UTRs thin and CDS thick, introns with
     strand chevrons; the canonical transcript starred. From the gene-models
     dataset payload (/api/v1/data/gene-models/{genome}/{id}). */
  EX.structure = function (container, gm, domains) {
    var W = Math.max(320, container.clientWidth || 900);
    var pad = 16, rowH = 26, labelW = Math.min(170, W * 0.24);
    var tx = gm.transcripts || [];
    var start = gm.start, end = gm.end, span = Math.max(1, end - start);
    var minus = gm.strand === '-';
    var x = function (p) { return labelW + pad + ((minus ? end - p : p - start) / span) * (W - labelW - 2 * pad); };
    var H = 26 + tx.length * rowH + (domains ? 64 : 10);
    var svg = '<svg class="et-structure" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Gene model of ' + esc(gm.id) + ', drawn 5′ to 3′">';
    svg += '<text x="' + (labelW + pad) + '" y="14" class="et-locus-tick">5′</text><text x="' + (W - pad) + '" y="14" class="et-locus-tick" text-anchor="end">3′ · ' + esc(fmtInt(span + 1)) + ' bp, drawn 5′ to 3′</text>';
    tx.forEach(function (t, i) {
      var y = 26 + i * rowH + rowH / 2;
      svg += '<text x="' + (labelW + pad - 8) + '" y="' + (y + 4) + '" class="et-structure-name" text-anchor="end">' + (t.canonical ? '★ ' : '') + esc(t.id.replace(gm.id, '')) + '</text>';
      svg += '<line x1="' + x(t.start) + '" x2="' + x(t.end) + '" y1="' + y + '" y2="' + y + '" class="et-structure-intron"/>';
      (t.exons || []).forEach(function (e) {
        var a = x(e.start), b = x(e.end);
        svg += '<rect x="' + Math.min(a, b) + '" y="' + (y - 4) + '" width="' + Math.max(1.5, Math.abs(b - a)) + '" height="8" class="et-structure-utr"/>';
      });
      (t.cds || []).forEach(function (c) {
        var a = x(c.start), b = x(c.end);
        svg += '<rect x="' + Math.min(a, b) + '" y="' + (y - 7) + '" width="' + Math.max(1.5, Math.abs(b - a)) + '" height="14" class="et-structure-cds"><title>CDS ' + fmtInt(c.start) + '–' + fmtInt(c.end) + '</title></rect>';
      });
    });
    if (domains && domains.length && gm.protein_length_aa) {
      var y0 = 26 + tx.length * rowH + 18;
      var L = gm.protein_length_aa;
      var px = function (aa) { return labelW + pad + (aa / L) * (W - labelW - 2 * pad); };
      svg += '<text x="' + (labelW + pad - 8) + '" y="' + (y0 + 10) + '" class="et-structure-name" text-anchor="end">protein, ' + fmtInt(L) + ' aa</text>';
      svg += '<rect x="' + px(0) + '" y="' + (y0 + 4) + '" width="' + (px(L) - px(0)) + '" height="8" rx="4" class="et-structure-protein"/>';
      domains.slice(0, 12).forEach(function (d, k) {
        var a = px(d.start), b = px(d.end);
        svg += '<rect x="' + a + '" y="' + (y0) + '" width="' + Math.max(3, b - a) + '" height="16" rx="3" fill="' + C.seriesColor(k % 6) + '"><title>' + esc(d.label + ' ' + d.start + '–' + d.end) + '</title></rect>';
        if (b - a > 50) { svg += '<text x="' + ((a + b) / 2) + '" y="' + (y0 + 30) + '" class="et-locus-label" text-anchor="middle">' + esc(d.short) + '</text>'; }
      });
    }
    container.innerHTML = svg + '</svg>';
  };

  function geneTitle(g) {
    return g.symbol ? g.symbol + ' · ' + g.gene : g.gene;
  }
  EX.geneTitle = geneTitle;

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'home', group: null, title: 'Overview', nav: 'Overview',
    summary: '',
    render: function (root, ctx) {
      var G = ET.state.genome, cat = ET.state.catalog, boot = ET.state.boot;
      var rna = cat.assays.rna;
      var usable = rna.samples.filter(function (s) { return s.usable; });
      var studies = cat.studies.filter(function (s) { return s.assay === 'rna'; });
      var examples = G.key === 'B73v5' ? ['lg1', 'tb1', 'kn1', 'adh1', 'mybr4', 'Zm00001d002005'] : ['tb1', 'kn1', 'adh1', 'lg1'];

      var start = UI.panel({ title: 'Start with a gene, a region or a list' });
      var b = UI.body(start);
      var big = UI.geneInput({ placeholder: 'e.g. lg1, Zm00001eb067740, chr2:4400000-4600000, or several ids', label: 'Gene, region or list', onPick: function (q) {
        var loc = U.parseLocus(q), ids = U.parseGeneList(q);
        if (loc) { ET.go('region', { chr: loc.chr, start: loc.start, end: loc.end }); }
        else if (ids.length > 1) { ET.go('list', { genes: ids.join(',') }); }
        else if (ids.length) { ET.go('gene', { id: ids[0] }); }
      } });
      big.classList.add('et-ta-big');
      b.appendChild(big);
      var ex = el('<p class="et-examples"><span>Examples</span></p>');
      examples.forEach(function (x) { ex.appendChild(el('<a class="mgdb-chip" href="' + esc(ET.href('gene', { id: x })) + '">' + esc(x) + '</a>')); });
      b.appendChild(ex);
      if (G.key !== 'B73v5') {
        b.appendChild(el('<p class="et-muted">' + esc(G.short) + ' genes can be found by their own ids or by the symbol of their B73 v5 counterpart in the same pan-gene.</p>'));
      }
      root.appendChild(start);

      var m = UI.panel({ title: esc(G.short) + ' at a glance' });
      var grid = el('<div class="mgdb-metric-grid et-metrics"></div>');
      var metric = function (title, value, desc) {
        return '<article class="mgdb-metric"><div class="mgdb-metric-top"><h3>' + title + '</h3></div><div class="mgdb-metric-stat"><strong class="mgdb-metric-value">' + value +
          '</strong></div><p class="mgdb-metric-description">' + desc + '</p></article>';
      };
      grid.innerHTML = metric('Genes', fmtInt(cat.genes), 'gene models of ' + esc(cat.annotation || G.genome) + ' with an expression profile') +
        metric('RNA samples', fmtInt(usable.length), 'from ' + U.plural(studies.length, 'study', 'studies') + (rna.samples.length > usable.length ? '; ' + (rna.samples.length - usable.length) + ' more were not measured' : '')) +
        (cat.assays.protein ? metric('Protein samples', fmtInt(cat.assays.protein.samples.length), 'Walley 2019 proteomes, the same tissues as its RNA') :
          metric('Shared samples', fmtInt((cat.shared_sample_ids || []).length), 'the tissues every NAM genome was measured in')) +
        metric('Pan-genes', fmtInt(boot.pangenome ? boot.pangenome.counts.pangenes_in_nam : 0), 'with members in the 26 NAM genomes, compared across them');
      UI.body(m).appendChild(grid);
      root.appendChild(m);

      /* The tools, as the hub's card grid, one grid per rail group in the
         rail's order: the group is a heading over its cards, not a label on
         each of them. */
      var t = UI.panel({ title: 'Tools' });
      var groups = [];
      ET.views.filter(function (v) { return v.id !== 'home' && !v.hidden; }).forEach(function (v) {
        var g = groups.filter(function (x) { return x.name === v.group; })[0];
        if (!g) { g = { name: v.group, views: [] }; groups.push(g); }
        g.views.push(v);
      });
      groups.forEach(function (g) {
        var block = el('<div class="et-toolgroup"><h3 class="et-subhead"></h3><div class="mgdb-card-grid et-toolgrid"></div></div>');
        qs('h3', block).textContent = g.name;
        g.views.forEach(function (v) {
          var reason = v.requires ? v.requires(G, cat) : null;
          var c = el('<a class="mgdb-card et-toolcard' + (reason ? ' is-unavailable' : '') + '"><h4></h4><p></p></a>');
          c.href = ET.href(v.id, {});
          qs('h4', c).textContent = v.title;
          qs('p', c).innerHTML = reason ? 'Not for ' + esc(G.short) + '. ' + reason : (v.card || v.summary);
          qs('.et-toolgrid', block).appendChild(c);
        });
        UI.body(t).appendChild(block);
      });
      root.appendChild(t);

      /* What the build found in this genome's data. */
      var checks = [];
      (cat.data_checks || []).forEach(function (d) {
        if (d.check === 'samples_identical_in_every_gene') {
          d.groups.forEach(function (grp) {
            checks.push('<strong>Identical samples.</strong> ' + grp.map(function (x) { return esc(x.label) + ' (' + esc(U.shortStudy(x.source)) + ')'; }).join(' and ') +
              ' carry the same value in every gene: one study’s data appears to have been loaded under the other as well. Both stay in the catalog; they will pair at r = 1.');
          });
        }
      });
      var notMeasured = rna.samples.filter(function (s) { return !s.usable; });
      if (notMeasured.length) {
        checks.push('<strong>' + U.plural(notMeasured.length, 'sample') + ' not measured.</strong> ' + notMeasured.map(function (s) { return esc(s.label) + ' (' + esc(U.shortStudy(s.studyName)) + ')'; }).join('; ') +
          '. These were zero in every gene in qTeller’s tables, a failed load rather than a measurement, and are left out of every analysis.');
      }
      if (checks.length) {
        var dc = UI.panel({ title: 'Data checks' });
        var ul = el('<ul class="et-checks"></ul>');
        checks.forEach(function (c) { ul.appendChild(el('<li>' + c + '</li>')); });
        UI.body(dc).appendChild(ul);
        UI.body(dc).appendChild(el('<p class="et-muted">' + esc(cat.units_note || '') + '</p>'));
        root.appendChild(dc);
      }
      void ctx;
    }
  });

  /* ------------------------------------------------------------------------
     Gene report
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'gene', group: 'Genes', title: 'Gene report', nav: 'Gene report',
    summary: 'One gene across the selected samples: where it is expressed, how specific that is, how it ranks among all genes, its pan-gene across the NAM genomes, and the genes that move with it.',
    card: 'Expression across every sample, tissue specificity, rank among all genes, the gene model, GO terms, the pan-gene across 26 genomes, co-expressed genes and neighbors.',
    selection: true,
    render: function (root, ctx) {
      var id = ctx.get('id');
      if (!id) { return pickGene(root, ctx, 'gene', 'id'); }
      root.appendChild(UI.loading('Loading ' + id + '…'));
      return ET.api('gene', { genome: ET.state.genome.key, id: id }, { signal: ctx.signal }).then(function (d) {
        if (!ctx.alive()) { return; }
        root.innerHTML = '';
        if (d.gene.gene !== id) { ctx.set({ id: d.gene.gene }); }
        if (d.ambiguous && d.ambiguous.length) {
          root.appendChild(UI.message('<strong>' + esc(id) + '</strong> also names ' + d.ambiguous.map(function (x) {
            return '<a class="et-mono" href="' + esc(ET.href('gene', { id: x.gene })) + '">' + esc(x.gene) + '</a>' + (x.symbol && x.symbol !== id ? ' (' + esc(x.symbol) + ')' : '');
          }).join(', ') + '; this report is the first match.', 'info'));
        }
        drawGeneReport(root, ctx, d);
      }, function (e) {
        root.innerHTML = '';
        root.appendChild(UI.errorBox(e));
        pickGene(root, ctx, 'gene', 'id');
      });
    }
  });

  /* A gene picker for a view opened without one. */
  function pickGene(root, ctx, view, param) {
    var p = UI.panel({ title: 'Which gene?' });
    var input = UI.geneInput({ label: 'Gene', onPick: function (g) { var o = {}; o[param] = g; ET.go(view, o); } });
    UI.body(p).appendChild(input);
    var ex = el('<p class="et-examples"><span>Examples</span></p>');
    (ET.state.genome.key === 'B73v5' ? ['lg1', 'tb1', 'kn1', 'adh1', 'mybr4'] : ['tb1', 'kn1', 'adh1']).forEach(function (x) {
      var o = {}; o[param] = x;
      ex.appendChild(el('<a class="mgdb-chip" href="' + esc(ET.href(view, o)) + '">' + esc(x) + '</a>'));
    });
    UI.body(p).appendChild(ex);
    root.appendChild(p);
    setTimeout(function () { input.input.focus(); }, 30);
  }
  ET.pickGene = pickGene;

  function drawGeneReport(root, ctx, d) {
    var G = ET.state.genome, g = d.gene;
    var rnaValues = d.values.rna;
    var rec = { gene: g.gene, symbol: g.symbol, values: rnaValues };
    var unit = G.nam && G.key !== 'B73v5' ? 'FPKM' : 'FPKM or TPM';

    /* ---- identity ---- */
    var head = UI.panel({ id: 'et-gene-head' });
    var hb = UI.body(head);
    var facts = [
      ['Genome', esc(G.short) + ' <span class="et-muted">' + esc(G.annotation || '') + '</span>'],
      ['Location', g.chr ? '<span class="et-mono">' + esc(U.locus(g)) + '</span>' + (g.strand ? ' (' + esc(g.strand) + ')' : '') : 'not placed'],
      g.biotype ? ['Type', esc(g.biotype.replace(/_/g, ' '))] : null,
      g.transcripts ? ['Transcripts', fmtInt(g.transcripts) + (g.protein_length ? ', canonical protein ' + fmtInt(g.protein_length) + ' aa' : '')] : null,
      /* The counterpart opens on B73 v5: a plain gene link keeps the current
         genome, and there the B73 id resolves back to this gene. */
      g.b73 ? ['B73 v5 counterpart', '<a class="et-gene-link" href="' + esc(ET.href('gene', { id: g.b73, g: 'B73v5' }, { keepSelection: false })) + '">' + esc(g.b73) + '</a>' +
        (g.b73_symbol ? ' <span class="et-sym">' + esc(g.b73_symbol) + '</span>' : '') + ' <span class="et-muted">same pan-gene</span>'] : null
    ].filter(Boolean);
    hb.innerHTML = '<div class="et-gene-id"><h3 class="et-gene-title"><span class="et-mono">' + esc(g.gene) + '</span>' + (g.symbol ? ' <span class="et-gene-symbol">' + esc(g.symbol) + '</span>' :
      g.b73_symbol ? ' <span class="et-gene-counterpart" title="The symbol of its B73 v5 counterpart in the same pan-gene">B73 v5 ' + esc(g.b73_symbol) + '</span>' : '') + '</h3>' +
      (g.name ? '<p class="et-gene-name">' + esc(g.name) + '</p>' : '') + (g.description && g.description !== g.name ? '<p class="et-muted">' + esc(g.description) + '</p>' : '') + '</div>' +
      '<dl class="et-facts">' + facts.map(function (f) { return '<div><dt>' + f[0] + '</dt><dd>' + f[1] + '</dd></div>'; }).join('') + '</dl><div class="et-gene-links"></div>';
    var links = qs('.et-gene-links', hb);
    var L = d.links || {};
    var addLink = function (href, label, primary) {
      if (!href) { return; }
      var a = el('<a class="mgdb-button ' + (primary ? 'mgdb-button-secondary' : 'mgdb-button-quiet') + ' mgdb-button-sm"></a>');
      a.href = href;
      a.textContent = label;
      links.appendChild(a);
    };
    addLink(L.record, 'Gene record', true);
    addLink(L.pan_gene_record, 'Pan-gene record');
    addLink(L.jbrowse, 'JBrowse RNA-seq');
    addLink(L.qteller, 'qTeller');
    addLink(L.efp, 'eFP browser');
    links.appendChild(ET.basketButton([g.gene]));
    var copy = UI.button('Copy id', { onClick: function () { U.copyText(g.gene).then(function () { UI.toast('Copied ' + g.gene); }); } });
    links.appendChild(copy);
    root.appendChild(head);

    if (!rnaValues) {
      root.appendChild(UI.message('<strong>' + esc(g.gene) + '</strong> has no RNA values in this release.', 'info'));
      return;
    }

    /* ---- tiles ---- */
    var tiles = el('<div class="et-tiles" aria-live="polite"></div>');
    root.appendChild(tiles);

    /* ---- the expression chart ---- */
    var scale = ctx.get('scale', 'linear'), order = ctx.get('order', 'study');
    var scaleCtl = UI.segmented([{ value: 'linear', label: 'Linear' }, { value: 'log', label: 'log₂' }], scale, function (v) { scale = v; ctx.set({ scale: v === 'linear' ? '' : v }); drawChart(); drawTissue(); }, 'Scale');
    var orderCtl = UI.segmented([{ value: 'study', label: 'By study' }, { value: 'tissue', label: 'By tissue' }, { value: 'value', label: 'By value' }], order, function (v) { order = v; ctx.set({ order: v === 'study' ? '' : v }); drawChart(); }, 'Order');
    var fig = C.figure({ label: 'Expression of ' + geneTitle(g) + ' in each selected sample', filename: function () { return g.gene + '_expression'; },
      controls: [scaleCtl, orderCtl], getTable: function () {
        var samples = ET.SEL.list();
        var q = function (s) { return S.percentile(rnaValues[s.idx], s.q); };
        return { header: ['sample', 'study', 'tissue', 'condition', 'value (' + unit + ')', 'percentile among genes'], numeric: [false, false, false, false, true, true],
                 rows: samples.map(function (s) { var p = q(s); return [s.label, s.studyName, s.tissue, s.condition || '', rnaValues[s.idx], isFinite(p) ? Math.round(p * 10) / 10 : null]; }),
                 notes: ['gene: ' + g.gene, 'samples: ' + ET.SEL.label()] };
      } });
    var chartPanel = UI.panel({ title: 'Expression in each sample' });
    UI.body(chartPanel).appendChild(fig);
    var legendHost = fig.legendHost;
    root.appendChild(chartPanel);
    /* The samples of the strip as readable rows, under it (EX.explorer);
       its window and mode live in the hash like the scale and the order. */
    var explorer = EX.explorer(fig, {
      mode: ctx.get('pv', 'window'), start: ctx.get('ws') === '' ? null : +ctx.get('ws') || 0, size: +ctx.get('wn', 24) || 24, marks: 8,
      setOrder: function (v) { order = v; orderCtl.set(v); ctx.set({ order: v === 'study' ? '' : v }); drawChart(); },
      onChange: function (s) { ctx.set({ pv: s.mode === 'window' ? '' : s.mode, ws: s.start || '', wn: s.size === 24 ? '' : s.size }); }
    });
    ctx.onDispose(explorer.destroy);

    /* ---- by tissue, and rank among genes ---- */
    var row2 = el('<div class="et-grid2"></div>');
    var tissueFig = C.figure({ label: 'Expression by tissue', filename: function () { return g.gene + '_by_tissue'; }, getTable: function () {
      var rows = [];
      ET.SEL.list().forEach(function (s) { if (rnaValues[s.idx] != null) { rows.push([s.tissue, s.label, s.studyName, rnaValues[s.idx]]); } });
      return { header: ['tissue', 'sample', 'study', 'value'], numeric: [false, false, false, true], rows: rows };
    } });
    var tp = UI.panel({ title: 'By tissue' });
    UI.body(tp).appendChild(tissueFig);
    var rankFig = C.figure({ label: 'Percentile among all genes in each sample', filename: function () { return g.gene + '_rank'; }, getTable: function () {
      return { header: ['sample', 'study', 'percentile among genes', 'value'], numeric: [false, false, true, true],
               rows: ET.SEL.list().filter(function (s) { return rnaValues[s.idx] != null; }).map(function (s) {
                 return [s.label, s.studyName, Math.round(S.percentile(rnaValues[s.idx], s.q) * 10) / 10, rnaValues[s.idx]]; }) };
    } });
    var rp = UI.panel({ title: 'Rank among all genes' });
    UI.body(rp).appendChild(rankFig);
    row2.appendChild(tp);
    row2.appendChild(rp);
    root.appendChild(row2);

    /* ---- the rest, each filled when its data arrives ---- */
    var panGenePanel = d.pangene ? UI.panel({ title: 'Across the 26 NAM genomes' }) : null;
    if (panGenePanel) { root.appendChild(panGenePanel); drawPanSummary(UI.body(panGenePanel), d.pangene, g); }

    /* Full width, one above the other: at half width 300 kb of neighborhood
       drew a 4 kb gene five pixels wide. */
    var coPanel = UI.panel({ title: 'Co-expressed genes' });
    var nbPanel = UI.panel({ title: 'Neighborhood' });
    root.appendChild(coPanel);
    root.appendChild(nbPanel);

    var fnPanel = UI.panel({ title: 'Gene model and function' });
    root.appendChild(fnPanel);
    drawFunction(UI.body(fnPanel), d, ctx);

    if (d.homeolog) {
      var hp = UI.panel({ title: 'Homeolog' });
      root.appendChild(hp);
      drawHomeolog(UI.body(hp), d, ctx);
    }

    var protPanel = null;
    if (d.values.protein && d.stats.protein && d.stats.protein.present > 0) {
      protPanel = UI.panel({ title: 'RNA and protein' });
      root.appendChild(protPanel);
    }

    function drawTiles() {
      var samples = ET.SEL.list();
      var sum = EX.summarize(rec, samples);
      var best = null;
      samples.forEach(function (s) {
        var p = S.percentile(rnaValues[s.idx], s.q);
        if (isFinite(p) && (!best || p > best.p)) { best = { s: s, p: p }; }
      });
      var tau = sum.tau;
      var reading = tau == null ? (sum.max != null && sum.max < 1 ? 'under 1 ' + unit + ' everywhere, too low to judge' : 'not computed')
        : tau >= 0.85 ? 'highly specific' : tau >= 0.6 ? 'moderately specific' : 'broadly expressed';
      var tile = function (label, value, note) {
        return '<div class="et-tile"><span class="et-tile-label">' + label + '</span><span class="et-tile-value">' + value + '</span><span class="et-tile-note">' + note + '</span></div>';
      };
      tiles.innerHTML =
        tile('Detected', fmtInt(sum.detected) + ' <small>of ' + fmtInt(sum.n) + '</small>', 'samples at ≥ 1 ' + esc(unit) + (samples.length > sum.n ? '; ' + (samples.length - sum.n) + ' selected samples did not measure it' : '')) +
        tile('Median', esc(fmt(sum.median)), 'mean ' + esc(fmt(sum.mean)) + ' ' + esc(unit)) +
        tile('Highest', esc(fmt(sum.max)), sum.top ? esc(sum.top.label) + ' · ' + esc(U.shortStudy(sum.top.studyName)) : '') +
        tile('Specificity', tau == null ? '—' : 'τ ' + tau.toFixed(2), esc(reading)) +
        tile('Best rank', best ? 'top ' + Math.max(0.1, 100 - best.p).toFixed(1) + '%' : '—', best ? 'of genes in ' + esc(best.s.label) : '');
    }
    function drawChart() {
      EX.bars(fig.plotNode, [rec], { scale: scale, order: order, unit: unit, mean: true, filename: g.gene + '_expression', marks: 8, explorer: true,
        onClick: function (s) { explorer.focusSample(s); },
        onStudy: function (run) { explorer.snapRange(run.start, run.end); } }).then(function (r) {
        var present = {};
        (r.ordered || []).forEach(function (s) { present[s.tissue] = (present[s.tissue] || 0) + 1; });
        legendHost.innerHTML = '';
        legendHost.appendChild(C.legend('tissue', present));
        fig.caption.innerHTML = '<strong>' + esc(ET.SEL.label()) + '</strong>: ' + fmtInt(r.used) + ' samples with a value' +
          (r.missing.length ? ', ' + fmtInt(r.missing.length) + ' that did not measure this gene (left out, not drawn as zero)' : '') +
          '. The dark red line is the mean, ' + esc(fmt(r.mean)) + ' ' + esc(unit) + '. Values are as each study published them; compare samples within a study.';
        fig.renderTable();
        explorer.update(r, [rec], { scale: scale, order: order, unit: unit });
      });
    }
    function drawTissue() {
      var samples = ET.SEL.list().filter(function (s) { return rnaValues[s.idx] != null; });
      var groups = ET.TISSUES.filter(function (t) { return samples.some(function (s) { return s.tissue === t; }); });
      var tf = function (v) { return scale === 'log' ? S.log2p1(v) : v; };
      var traces = groups.map(function (t) {
        var sub = samples.filter(function (s) { return s.tissue === t; });
        return { type: 'box', name: t, y: sub.map(function (s) { return tf(rnaValues[s.idx]); }), boxpoints: 'all', jitter: 0.45, pointpos: 0,
                 marker: { color: C.tissueColor(t), size: 7, line: { color: '#ffffff', width: 1.5 } }, line: { color: C.tissueColor(t), width: 1.5 },
                 fillcolor: 'rgba(0,0,0,0)', hoveron: 'points', hoverinfo: 'text',
                 hovertext: sub.map(function (s) { return '<b>' + fmt(rnaValues[s.idx]) + '</b> ' + esc(unit) + '<br>' + esc(s.label) + '<br>' + esc(U.shortStudy(s.studyName)); }) };
      });
      C.plot(tissueFig.plotNode, traces, { showlegend: false, yaxis: { title: { text: scale === 'log' ? 'log₂(' + unit + ' + 1)' : unit }, rangemode: 'tozero' },
                                           xaxis: { tickangle: groups.length > 5 ? -25 : 0 }, margin: { l: 64, r: 12, t: 8, b: 70 } }, { height: 340 });
      tissueFig.caption.textContent = 'Each dot is a sample; the box spans the middle half. Tissue is a keyword reading of the sample label.';
      tissueFig.renderTable();
    }
    function drawRank() {
      var pts = ET.SEL.list().filter(function (s) { return rnaValues[s.idx] != null && s.q && s.q.length; })
        .map(function (s) { return { s: s, p: S.percentile(rnaValues[s.idx], s.q) }; })
        .sort(function (a, b) { return b.p - a.p; });
      var show = pts.slice(0, 20);
      if (!show.length) { C.clear(rankFig.plotNode); return; }
      C.plot(rankFig.plotNode, [{ type: 'bar', orientation: 'h', x: show.map(function (p) { return p.p; }), y: show.map(function (_, i) { return i; }),
        marker: { color: show.map(function (p) { return C.tissueColor(p.s.tissue); }) }, hoverinfo: 'text',
        hovertext: show.map(function (p) { return '<b>higher than ' + p.p.toFixed(1) + '%</b> of genes<br>' + esc(p.s.label) + '<br>' + fmt(rnaValues[p.s.idx]) + ' ' + esc(unit); }) }],
        { xaxis: { range: [0, 100], title: { text: 'percentile among all genes in the sample' } },
          yaxis: { tickmode: 'array', tickvals: show.map(function (_, i) { return i; }), ticktext: show.map(function (p) { return p.s.label.length > 30 ? p.s.label.slice(0, 28) + '…' : p.s.label; }), autorange: 'reversed', tickfont: { size: 10.5 } },
          showlegend: false, margin: { l: 10, r: 12, t: 8, b: 44 } }, { height: Math.max(260, show.length * 17 + 70) });
      var shown = {};
      show.forEach(function (p) { shown[p.s.tissue] = (shown[p.s.tissue] || 0) + 1; });
      rankFig.legendHost.innerHTML = '';
      rankFig.legendHost.appendChild(C.legend('tissue', shown));
      rankFig.caption.textContent = 'The ' + show.length + ' samples where this gene ranks highest among all genes' + (pts.length > show.length ? ', of ' + pts.length : '') + '.';
      rankFig.renderTable();
    }
    function drawProtein() {
      if (!protPanel) { return; }
      var host = UI.body(protPanel);
      host.innerHTML = '';
      var pv = d.values.protein;
      var P = ET.state.catalog.assays.protein.samples;
      var R = ET.state.catalog.assays.rna.samples;
      var pairs = [];
      P.forEach(function (ps) {
        var rs = R.filter(function (r) { return r.label === ps.label && r.studyName.indexOf('Walley 2019') === 0; })[0];
        if (rs) { pairs.push({ label: ps.label, tissue: ps.tissue, rna: rnaValues[rs.idx], protein: pv[ps.idx] }); }
      });
      var f = C.figure({ label: 'RNA and protein of ' + geneTitle(g) + ' in the Walley 2019 tissues', filename: g.gene + '_rna_protein', getTable: function () {
        return { header: ['tissue', 'RNA', 'protein'], numeric: [false, true, true], rows: pairs.map(function (p) { return [p.label, p.rna, p.protein]; }) };
      } });
      host.appendChild(f);
      var xs = pairs.map(function (_, i) { return i; });
      C.plot(f.plotNode, [
        { type: 'bar', x: xs, y: pairs.map(function (p) { return p.rna; }), xaxis: 'x', yaxis: 'y', marker: { color: pairs.map(function (p) { return C.tissueColor(p.tissue); }) },
          hovertext: pairs.map(function (p) { return '<b>' + fmt(p.rna) + '</b> RNA<br>' + esc(p.label); }), hoverinfo: 'text' },
        { type: 'bar', x: xs, y: pairs.map(function (p) { return p.protein; }), xaxis: 'x2', yaxis: 'y2', marker: { color: pairs.map(function (p) { return C.tissueColor(p.tissue); }) },
          hovertext: pairs.map(function (p) { return '<b>' + (p.protein == null ? 'not measured' : fmt(p.protein)) + '</b> protein<br>' + esc(p.label); }), hoverinfo: 'text' }
      ], { grid: { rows: 2, columns: 1, pattern: 'independent', roworder: 'top to bottom' }, showlegend: false,
           yaxis: { title: { text: 'RNA' }, rangemode: 'tozero' }, yaxis2: { title: { text: 'protein' }, rangemode: 'tozero' },
           xaxis: { showticklabels: false }, xaxis2: { tickmode: 'array', tickvals: xs, ticktext: pairs.map(function (p) { return p.label; }), tickangle: -40, tickfont: { size: 10 } },
           margin: { l: 64, r: 12, t: 8, b: 130 } }, { height: 480 });
      var both = pairs.filter(function (p) { return p.rna != null && p.protein != null; });
      var r = S.spearman(both.map(function (p) { return p.rna; }), both.map(function (p) { return p.protein; }));
      f.caption.innerHTML = 'Two panels on their own scales: RNA and protein abundance are not in comparable units. Spearman ρ across ' + both.length + ' tissues = <strong>' + U.fmtR(r) + '</strong>.';
    }

    function drawCoexp() {
      var host = UI.body(coPanel);
      host.innerHTML = '';
      if (ET.SEL.size() < 5) { host.appendChild(UI.message('Co-expression needs at least 5 samples in the selection.', 'info')); return; }
      host.appendChild(UI.loading('Correlating ' + (g.symbol || g.gene) + ' with every gene…'));
      ET.api('coexpression', { genome: G.key, id: g.gene, s: ET.SEL.api(), n: 10 }, { signal: ctx.signal }).then(function (r) {
        if (!ctx.alive()) { return; }
        host.innerHTML = '';
        var list = function (title, rows) {
          return '<div><h3 class="et-subhead">' + title + '</h3><ol class="et-ranked">' + rows.map(function (x) {
            return '<li><span>' + UI.geneLabel(x) + '</span><span class="et-r ' + (x.r >= 0 ? 'is-pos' : 'is-neg') + '">' + x.r.toFixed(3) + '</span></li>';
          }).join('') + '</ol></div>';
        };
        host.appendChild(el('<div class="et-grid2 et-grid-tight">' + list('Most similar', r.positive) + list('Most opposite', r.negative) + '</div>'));
        var foot = el('<p class="et-caption">Pearson r on log₂(value + 1) over ' + fmtInt(r.samples_used) + ' samples; ' + fmtInt(r.tested) + ' genes compared. </p>');
        var a = el('<a></a>');
        a.href = ET.href('coexp', { id: g.gene });
        a.textContent = 'Open the full co-expression analysis';
        foot.appendChild(a);
        host.appendChild(foot);
      }, function (e) { if (ctx.alive()) { host.innerHTML = ''; host.appendChild(UI.errorBox(e)); } });
    }

    var nb = null;
    function drawNeighbors() {
      var host = UI.body(nbPanel);
      if (!g.chr) { host.innerHTML = ''; host.appendChild(UI.message('This gene has no position in the release.', 'info')); return; }
      var w = 150000, s0 = Math.max(1, g.start - w), s1 = g.end + w;
      var go = nb ? Promise.resolve(nb) : ET.api('interval', { genome: G.key, chr: g.chr, start: s0, end: s1, limit: 300 }, { signal: ctx.signal }).then(function (r) {
        return ET.values(r.genes.map(function (x) { return x.gene; }), { signal: ctx.signal }).then(function (v) {
          var byGene = {};
          v.genes.forEach(function (x) { byGene[x.gene] = x; });
          nb = r.genes.map(function (x) { return Object.assign({}, x, { values: byGene[x.gene] ? byGene[x.gene].values : null }); });
          return nb;
        });
      });
      host.innerHTML = '';
      host.appendChild(UI.loading('Reading the neighborhood…'));
      go.then(function (genes) {
        if (!ctx.alive()) { return; }
        var samples = ET.SEL.list();
        var list = genes.map(function (x) {
          var m = x.values ? EX.summarize(x, samples).mean : null;
          return Object.assign({}, x, { value: m == null ? NaN : m });
        });
        host.innerHTML = '';
        var track = el('<div class="et-locus-host"></div>');
        host.appendChild(track);
        EX.locus(track, list, { start: s0, end: s1, focus: g.gene, what: 'mean ' + unit, label: 'Genes within 150 kb of ' + g.gene });
        var foot = el('<p class="et-caption">' + U.plural(list.length, 'gene') + ' in ' + esc(g.chr) + ':' + fmtInt(s0) + '–' + fmtInt(s1) + ', shaded by mean expression over the selection. </p>');
        var a = el('<a></a>');
        a.href = ET.href('region', { chr: g.chr, start: s0, end: s1 });
        a.textContent = 'Open as a region';
        foot.appendChild(a);
        host.appendChild(foot);
      }, function (e) { if (ctx.alive()) { host.innerHTML = ''; host.appendChild(UI.errorBox(e)); } });
    }

    function drawAll() { drawTiles(); drawChart(); drawTissue(); drawRank(); drawProtein(); }
    ctx.on('selection', function () { drawAll(); drawCoexp(); drawNeighbors(); });
    drawAll();
    drawCoexp();
    drawNeighbors();
  }

  function drawPanSummary(host, pg, g) {
    var cls = { core: 'in all 26 NAM genomes', 'near-core': 'in 24 or 25 of them', dispensable: 'in 2 to 23 of them', 'private': 'in one of them' }[pg.class] || '';
    host.innerHTML = '<p class="et-pan-lead"><a class="et-mono" href="' + esc(ET.href('pangene', { id: pg.name })) + '">' + esc(pg.name.replace('pan-zea.v4.', '')) + '</a> ' +
      (pg.class ? '<span class="mgdb-pill mgdb-pill-info">' + esc(pg.class) + '</span> ' : '') + 'present ' + esc(cls) + ' · ' + fmtInt(pg.members) + ' gene models in ' + fmtInt(pg.annotations) + ' of 66 annotations</p>' +
      EX.namStrip(pg) + EX.namKey() +
      '<p class="et-caption">Expressed in <strong>' + pg.expressed + '</strong>, low in ' + pg.low + ', silent in <strong>' + pg.silent + '</strong> of the ' + pg.present + ' genomes that carry it, in the 23 samples they share' +
      (pg.conservation != null ? '. Tissue profiles agree at mean r = <strong>' + pg.conservation.toFixed(2) + '</strong>' + (pg.divergent ? ' (least alike: ' + esc(pg.divergent) + ', r = ' + pg.min_r.toFixed(2) + ')' : '') : '') + '.</p>';
    var a = el('<p><a class="mgdb-button mgdb-button-secondary mgdb-button-sm">Compare every copy across the 26 genomes</a></p>');
    qs('a', a).href = ET.href('pangene', { id: pg.name });
    host.appendChild(a);
    void g;
  }
  EX.panSummary = drawPanSummary;

  /* Gene model (B73 v5, from the gene-models dataset), protein domains (the
     domains dataset) and GO terms (the release's GO index). */
  function drawFunction(host, d, ctx) {
    var G = ET.state.genome, g = d.gene;
    host.innerHTML = '';
    var sHost = el('<div class="et-structure-host"></div>');
    host.appendChild(sHost);
    if (d.links && d.links.gene_model_api) {
      sHost.appendChild(UI.loading('Reading the gene model…'));
      fetch(d.links.gene_model_api, { headers: { 'Accept': 'application/json' }, signal: ctx.signal }).then(function (r) { return r.ok ? r.json() : null; }).then(function (gm) {
        if (!ctx.alive()) { return; }
        /* {data: {id, attributes: {start, end, strand, canonical_protein,
           protein_length_aa, ...}, sections: {transcripts: [...]}}} */
        var model = gm && gm.data && gm.data.attributes && gm.data.sections
          ? Object.assign({}, gm.data.attributes, { id: gm.data.id, transcripts: gm.data.sections.transcripts }) : null;
        if (!model || !model.transcripts || !model.transcripts.length) { sHost.innerHTML = ''; return; }
        var canon = model.canonical_protein;
        var drawIt = function (doms) {
          sHost.innerHTML = '';
          var fig = el('<figure class="et-figure"><div class="et-structure-svg"></div><figcaption class="et-caption"></figcaption></figure>');
          sHost.appendChild(fig);
          EX.structure(qs('.et-structure-svg', fig), model, doms);
          qs('figcaption', fig).innerHTML = 'From the gene-models dataset (<a href="' + esc(d.links.gene_model_api) + '">JSON</a>). UTRs thin, coding sequence thick; ★ marks the canonical transcript' +
            (doms && doms.length ? '; protein domains from the domains dataset' : '') + '.';
        };
        if (!canon) { drawIt(null); return; }
        fetch('/api/v1/data/domains/' + encodeURIComponent(G.genome) + '/' + encodeURIComponent(canon), { headers: { 'Accept': 'application/json' }, signal: ctx.signal })
          .then(function (r) { return r.ok ? r.json() : null; }).then(function (dm) {
            var doms = [];
            var sec = dm && dm.data && dm.data.sections ? dm.data.sections : null;
            var entries = sec && (sec.entries || sec.matches) ? (sec.entries && sec.entries.length ? sec.entries : sec.matches) : [];
            entries.forEach(function (e) {
              var locs = e.locations || (e.start ? [{ start: e.start, end: e.end }] : []);
              locs.forEach(function (l) {
                doms.push({ start: l.start, end: l.end, label: (e.accession || e.id || '') + ' ' + (e.name || e.description || ''), short: e.name || e.accession || '' });
              });
            });
            doms.sort(function (a, b) { return a.start - b.start; });
            drawIt(doms);
          }, function () { drawIt(null); });
      }, function () { sHost.innerHTML = ''; });
    } else {
      sHost.appendChild(el('<p class="et-muted">Transcript structures are published as a dataset for B73 v5' + (g.b73 ? '; the B73 v5 counterpart <a href="' + esc(ET.href('gene', { id: g.b73, g: 'B73v5' }, { keepSelection: false })) + '">' + esc(g.b73) + '</a> has one' : '') + '.</p>'));
    }
    var goHost = el('<div class="et-go"></div>');
    host.appendChild(goHost);
    if (!d.go || !d.go.length) {
      goHost.appendChild(el('<p class="et-muted">No GO annotations for this gene model' + (d.go === null ? ' are loaded for ' + esc(G.short) : '') + '.</p>'));
      return;
    }
    var aspects = { P: 'Biological process', F: 'Molecular function', C: 'Cellular component' };
    Object.keys(aspects).forEach(function (a) {
      var terms = d.go.filter(function (t) { return t.aspect === a; });
      if (!terms.length) { return; }
      var block = el('<div class="et-go-aspect"><h3 class="et-subhead"></h3><ul class="et-go-terms"></ul></div>');
      qs('h3', block).textContent = aspects[a] + ' (' + terms.length + ')';
      var ul = qs('ul', block);
      terms.forEach(function (t) {
        var li = el('<li><a></a> <span class="et-muted et-mono"></span></li>');
        qs('a', li).href = 'https://amigo.geneontology.org/amigo/term/' + encodeURIComponent(t.go);
        qs('a', li).textContent = t.name;
        qs('span', li).textContent = t.go;
        ul.appendChild(li);
      });
      goHost.appendChild(block);
    });
    goHost.appendChild(el('<p class="et-caption">Direct annotations of this gene model, as loaded at MaizeGDB. <a href="' + esc(ET.href('enrich', {})) + '">GO enrichment</a> tests a gene list against the same annotations.</p>'));
  }

  function drawHomeolog(host, d, ctx) {
    var h = d.homeolog, g = d.gene;
    host.innerHTML = '<p>' + esc(g.symbol || g.gene) + ' is the <strong>' + esc(h.subgenome) + '</strong> copy of a retained whole-genome duplicate; its ' + esc(h.partner_subgenome) +
      ' homeolog is ' + UI.geneLink(h.partner) + '. Both descend from sorghum ' + esc(h.sorghum || '') + ' (Ks ' + fmt(h.ks.maize1) + ' and ' + fmt(h.ks.maize2) + ').</p>';
    var out = el('<div class="et-homeolog-fig"></div>');
    host.appendChild(out);
    ET.values([h.partner], { signal: ctx.signal }).then(function (v) {
      if (!ctx.alive() || !v.genes.length) { return; }
      var other = v.genes[0];
      var samples = ET.SEL.list().filter(function (s) { return d.values.rna[s.idx] != null && other.values[s.idx] != null; });
      var x = samples.map(function (s) { return S.log2p1(d.values.rna[s.idx]); });
      var y = samples.map(function (s) { return S.log2p1(other.values[s.idx]); });
      var r = S.pearson(x, y);
      var m1 = S.mean(samples.map(function (s) { return d.values.rna[s.idx]; })), m2 = S.mean(samples.map(function (s) { return other.values[s.idx]; }));
      out.innerHTML = '<p class="et-caption">Over ' + samples.length + ' shared samples the two copies correlate at r = <strong>' + U.fmtR(r) + '</strong> (log scale); mean ' +
        esc(fmt(m1)) + ' here against ' + esc(fmt(m2)) + ' for the homeolog. </p>';
      var a = el('<a></a>');
      a.href = ET.href('compare', { g1: g.gene, g2: h.partner });
      a.textContent = 'Compare the two';
      qs('p', out).appendChild(a);
      var b = el('<a></a>');
      b.href = ET.href('homeologs', {});
      b.textContent = 'every homeolog pair';
      qs('p', out).appendChild(document.createTextNode(' · '));
      qs('p', out).appendChild(b);
    });
  }

  /* ------------------------------------------------------------------------
     Genes in a region
     ------------------------------------------------------------------------ */

  /* The expression table behind the region and list tools: one column per
     sample for a short list, one row of summaries for a long one. */
  EX.table = function (host, genes, opts) {
    opts = opts || {};
    var mode = 'auto';
    var wrap = el('<div class="et-exprtable"><div class="et-toolbar"></div><div class="et-exprtable-body"></div></div>');
    host.appendChild(wrap);
    var ctl = UI.segmented([{ value: 'auto', label: 'Automatic' }, { value: 'summary', label: 'Summary' }, { value: 'matrix', label: 'Every sample' }], mode, function (v) { mode = v; draw(); }, 'Detail');
    qs('.et-toolbar', wrap).appendChild(el('<span class="et-toolbar-label">Detail</span>'));
    qs('.et-toolbar', wrap).appendChild(ctl);
    var unit = ET.unit();
    function draw() {
      var samples = ET.SEL.list();
      var eff = mode === 'auto' ? (genes.length <= 25 && samples.length <= 40 ? 'matrix' : 'summary') : mode;
      var base = [
        { key: 'gene', label: 'Gene', type: 'str', render: function (r) { return UI.geneLink(r.gene); } },
        { key: 'symbol', label: 'Symbol', type: 'str', value: function (r) { return r.symbol || (r.b73_symbol ? 'B73 ' + r.b73_symbol : ''); } },
        { key: 'loc', label: 'Location', type: 'str', value: function (r) { return r.chr ? r.chr + ':' + r.start : ''; }, render: function (r) { return r.chr ? '<span class="et-nowrap">' + esc(U.locus(r)) + '</span>' : '<span class="et-muted">not placed</span>'; }, export: function (r) { return r.chr ? r.chr + ':' + r.start + '-' + r.end : ''; } }
      ];
      var columns, rows;
      if (eff === 'matrix') {
        columns = base.concat(samples.map(function (s) {
          return { key: 's' + s.id, label: esc(s.label.length > 18 ? s.label.slice(0, 16) + '…' : s.label), exportLabel: s.label, title: s.label + ' — ' + s.studyName, type: 'num', heat: true,
                   value: function (r) { return r.values ? r.values[s.idx] : null; },
                   render: function (r) { var v = r.values ? r.values[s.idx] : null; return v == null ? '<span class="et-muted" title="not measured">—</span>' : fmt(v); } };
        }));
        rows = genes;
      } else {
        rows = genes.map(function (g) { return Object.assign({}, g, { sum: EX.summarize(g, samples) }); });
        columns = base.concat([
          { key: 'mean', label: 'Mean', type: 'num', heat: true, value: function (r) { return r.sum.mean; } },
          { key: 'max', label: 'Highest', type: 'num', value: function (r) { return r.sum.max; } },
          { key: 'top', label: 'Highest in', type: 'str', value: function (r) { return r.sum.top ? r.sum.top.label : ''; } },
          { key: 'detected', label: 'Detected', type: 'num', title: 'Samples at 1 or more', value: function (r) { return r.sum.detected; }, render: function (r) { return r.sum.n ? r.sum.detected + ' / ' + r.sum.n : '—'; } },
          { key: 'tau', label: 'τ', type: 'num', title: 'Tissue specificity, 0 even to 1 one sample; blank below 1 ' + unit, value: function (r) { return r.sum.tau; }, render: function (r) { return r.sum.tau == null ? '—' : r.sum.tau.toFixed(2); } }
        ]);
      }
      var body = qs('.et-exprtable-body', wrap);
      body.innerHTML = '';
      new ET.DataTable(body, { columns: columns, rows: rows, selectable: true, exportName: (opts.filename || 'expression') + '_' + eff, pageSize: eff === 'matrix' ? 25 : 50,
        sort: eff === 'summary' ? { key: 'mean', dir: 'desc' } : null, notes: ['samples: ' + ET.SEL.label() + ' (' + samples.length + ')', 'values: ' + unit + ' as each study published them'].concat(opts.notes || []),
        caption: opts.caption || 'Expression of the genes' });
    }
    draw();
    return { redraw: draw };
  };

  ET.register({
    id: 'region', group: 'Genes', title: 'Genes in a region', nav: 'Genes in a region',
    summary: 'Every gene in an interval of the genome, drawn along it and listed with its expression.',
    card: 'An interval of a chromosome: its genes along the axis, shaded by expression, and their values in the selected samples.',
    selection: true,
    render: function (root, ctx) {
      var cat = ET.state.catalog;
      var seqs = (cat.sequences || []).filter(function (s) { return s.genes >= 20; });
      var chr = ctx.get('chr', seqs.length ? seqs[0].name : 'chr1'), start = +ctx.get('start', 1) || 1, end = +ctx.get('end', 1000000) || 1000000;
      var within = ctx.get('within') === '1';
      var form = UI.panel({});
      var row = el('<form class="et-form-row"></form>');
      var cs = UI.select(seqs.map(function (s) { return { value: s.name, label: s.name + ' (' + fmtInt(s.genes) + ' genes)' }; }), chr);
      var si = el('<input type="text" class="et-input et-mono" inputmode="numeric">'); si.value = fmtInt(start);
      var ei = el('<input type="text" class="et-input et-mono" inputmode="numeric">'); ei.value = fmtInt(end);
      var wi = el('<label class="et-check"><input type="checkbox"> <span>Only genes wholly inside</span></label>');
      qs('input', wi).checked = within;
      row.appendChild(UI.field('Chromosome', cs));
      row.appendChild(UI.field('Start', si));
      row.appendChild(UI.field('End', ei));
      var wf = el('<div class="mgdb-hub-field et-field et-field-check"></div>'); wf.appendChild(wi); row.appendChild(wf);
      var go = el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Show genes</button></div>');
      row.appendChild(go);
      UI.body(form).appendChild(row);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      var data = null;
      row.addEventListener('submit', function (e) {
        e.preventDefault();
        chr = cs.value;
        start = +String(si.value).replace(/[,\s]/g, '') || 1;
        end = +String(ei.value).replace(/[,\s]/g, '') || start + 100000;
        within = qs('input', wi).checked;
        run();
      });
      function run() {
        ctx.set({ chr: chr, start: start, end: end, within: within ? '1' : '' });
        out.innerHTML = '';
        out.appendChild(UI.loading('Finding genes in ' + chr + ':' + fmtInt(start) + '–' + fmtInt(end) + '…'));
        ET.api('interval', { genome: ET.state.genome.key, chr: chr, start: start, end: end, within: within ? 1 : '', limit: 2000 }, { signal: ctx.signal }).then(function (r) {
          return ET.values(r.genes.map(function (g) { return g.gene; }), { signal: ctx.signal }).then(function (v) {
            var byGene = {};
            v.genes.forEach(function (g) { byGene[g.gene] = g; });
            data = { r: r, genes: r.genes.map(function (g) { return Object.assign({}, g, { values: byGene[g.gene] ? byGene[g.gene].values : null }); }) };
            draw();
          });
        }).catch(function (e) { if (ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function draw() {
        if (!data) { return; }
        out.innerHTML = '';
        var r = data.r;
        var tp = UI.panel({ title: esc(r.chr) + ':' + fmtInt(r.start) + '–' + fmtInt(r.end), sub: U.plural(data.genes.length, 'gene') + (r.truncated ? ' (the first ' + fmtInt(r.limit) + ')' : '') + ' · ' + fmtInt(r.end - r.start + 1) + ' bp' });
        var track = el('<div class="et-locus-host"></div>');
        UI.body(tp).appendChild(track);
        out.appendChild(tp);
        var samples = ET.SEL.list();
        if (data.genes.length <= 400) {
          EX.locus(track, data.genes.map(function (g) { return Object.assign({}, g, { value: g.values ? (EX.summarize(g, samples).mean || NaN) : NaN }); }),
                   { start: r.start, end: r.end, what: 'mean', label: 'Genes in the region' });
        } else {
          track.appendChild(el('<p class="et-muted">Too many genes to draw legibly; narrow the interval to under 400 genes to see the track.</p>'));
        }
        var acts = [ET.basketButton(function () { return data.genes.map(function (g) { return g.gene; }); }, 'All to basket')];
        var hm = UI.button('Heatmap', { onClick: function () { ET.go('heatmap', { genes: data.genes.slice(0, 300).map(function (g) { return g.gene; }).join(',') }); } });
        acts.push(hm);
        var tb = UI.panel({ title: 'Expression', actions: acts });
        out.appendChild(tb);
        EX.table(UI.body(tb), data.genes, { filename: 'region_' + r.chr + '_' + r.start + '_' + r.end, notes: ['region: ' + r.chr + ':' + r.start + '-' + r.end] });
      }
      ctx.on('selection', draw);
      if (ctx.get('chr')) { run(); }
    }
  });

  /* ------------------------------------------------------------------------
     A list of genes
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'list', group: 'Genes', title: 'Gene list', nav: 'Gene list',
    summary: 'Paste ids or symbols—this assembly’s, older B73 ids, or B73 v5 symbols on the other genomes—and see every gene’s expression, with the ones that did not match listed.',
    card: 'Many genes at once: ids, symbols, older B73 ids. Expression as a table, with anything unmatched named.',
    selection: true,
    render: function (root, ctx) {
      var start = (ctx.get('genes') || '').split(',').filter(Boolean);
      var form = UI.panel({});
      var input = UI.geneListInput({ value: start, rows: 6, label: 'Genes', example: ET.state.genome.key === 'B73v5' ? ['lg1', 'lg2', 'lg3', 'lg4', 'sln1', 'kn1', 'gn1', 'rs1', 'lg1-R'] : ['tb1', 'kn1', 'lg1', 'adh1'] });
      ctx.onDispose(input.dispose);
      UI.body(form).appendChild(input.el);
      var go = UI.button('Look up', { kind: 'mgdb-button-primary' });
      UI.body(form).appendChild(el('<div class="et-toolbar"></div>')).appendChild(go);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      go.addEventListener('click', function () { run(input.get()); });
      var genes = null;
      function run(ids) {
        if (!ids.length) { out.innerHTML = ''; out.appendChild(UI.message('Enter at least one gene.', 'info')); return; }
        ctx.set({ genes: ids.length <= 2000 ? ids.join(',') : '' });
        out.innerHTML = '';
        if (ids.length > 2000) { out.appendChild(UI.message('A link carries at most 2,000 genes, so Copy link will not reopen this list; the table’s download and the basket keep it.', 'info')); }
        out.appendChild(UI.loading('Looking up ' + U.plural(ids.length, 'gene') + '…'));
        ET.api('resolve', { genome: ET.state.genome.key, ids: ids }, { post: true, signal: ctx.signal }).then(function (r) {
          return ET.values(r.genes.map(function (g) { return g.gene; }), { signal: ctx.signal }).then(function (v) {
            if (!ctx.alive()) { return; }
            genes = v.genes;
            out.innerHTML = '';
            if (r.missing.length) {
              out.appendChild(UI.message('<strong>' + U.plural(r.missing.length, 'identifier') + ' matched nothing in ' + esc(ET.state.genome.short) + ':</strong> <span class="et-mono">' +
                esc(r.missing.slice(0, 60).join(', ')) + (r.missing.length > 60 ? ', …' : '') + '</span>', 'info'));
            }
            if (r.ambiguous.length) {
              out.appendChild(UI.message('<strong>' + U.plural(r.ambiguous.length, 'symbol') + ' matched more than one gene</strong> and every match is listed: ' + esc(r.ambiguous.join(', ')), 'info'));
            }
            var via = {};
            Object.keys(r.via).forEach(function (k) { via[r.via[k]] = (via[r.via[k]] || 0) + 1; });
            var acts = [ET.basketButton(function () { return genes.map(function (g) { return g.gene; }); }, 'All to basket'),
              UI.button('Heatmap', { onClick: function () { ET.go('heatmap', { genes: genes.slice(0, 300).map(function (g) { return g.gene; }).join(',') }); } }),
              UI.button('GO enrichment', { onClick: function () { ET.go('enrich', { genes: genes.slice(0, 3000).map(function (g) { return g.gene; }).join(',') }); } })];
            /* Two identifiers can name one gene (lg1 and its v4 id), so the
               count of identifiers matched and of genes can differ; say so. */
            var matched = Object.keys(r.via).length;
            var sub = U.plural(matched, 'identifier') + ' matched by ' + Object.keys(via).map(function (k) { return k + ' (' + via[k] + ')'; }).join(', ') +
              (matched > genes.length ? '; ' + (matched - genes.length === 1 ? 'one named a gene already listed' : (matched - genes.length) + ' named genes already listed') : '');
            var p = UI.panel({ title: U.plural(genes.length, 'gene'), sub: sub, actions: acts });
            out.appendChild(p);
            EX.table(UI.body(p), genes, { filename: 'gene_list' });
          });
        }).catch(function (e) { if (ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      ctx.on('selection', function () { if (genes) { run(input.get()); } });
      if (start.length) { run(start); }
    }
  });

  /* ------------------------------------------------------------------------
     Expression plot: up to eight genes
     ------------------------------------------------------------------------ */

  function geneChips(opts) {
    var list = (opts.genes || []).slice(0, opts.max || 8);
    var w = el('<div class="et-chips"><ul class="et-chip-list"></ul></div>');
    var ul = qs('ul', w);
    var input = UI.geneInput({ placeholder: 'Add a gene', label: 'Add a gene', onPick: function (g) { add(g); input.value = ''; } });
    w.appendChild(input);
    var fromBasket = UI.button('From basket', { onClick: function () { ET.basket.list().slice(0, opts.max || 8).forEach(function (g) { add(g, true); }); render(); change(); } });
    w.appendChild(fromBasket);
    function add(g, silent) {
      g = String(g || '').trim();
      if (!g || list.indexOf(g) !== -1) { return; }
      if (list.length >= (opts.max || 8)) { UI.toast('At most ' + (opts.max || 8) + ' genes here; the heatmap takes up to 300.'); return; }
      list.push(g);
      if (!silent) { render(); change(); }
    }
    function change() { if (opts.onChange) { opts.onChange(list.slice()); } }
    function render() {
      ul.innerHTML = '';
      list.forEach(function (g, i) {
        var li = el('<li class="et-chip"><span class="et-swatch" style="background:' + C.seriesColor(i) + '"></span><span class="et-mono"></span><button type="button" class="et-chip-x">×</button></li>');
        qs('.et-mono', li).textContent = g;
        qs('button', li).setAttribute('aria-label', 'Remove ' + g);
        qs('button', li).addEventListener('click', function () { list.splice(list.indexOf(g), 1); render(); change(); });
        ul.appendChild(li);
      });
      fromBasket.disabled = !ET.basket.list().length;
    }
    render();
    return { el: w, get: function () { return list.slice(); } };
  }

  ET.register({
    id: 'plot', group: 'Visualize', title: 'Expression plot', nav: 'Expression plot',
    summary: 'Up to eight genes side by side across the selected samples.',
    card: 'Up to eight genes as grouped bars across the selected samples, linear or log.',
    selection: true,
    render: function (root, ctx) {
      var genes0 = (ctx.get('genes') || '').split(',').filter(Boolean);
      var scale = ctx.get('scale', 'linear'), order = ctx.get('order', 'study');
      var form = UI.panel({});
      var chips = geneChips({ genes: genes0, max: 8, onChange: function (l) { load(l); } });
      UI.body(form).appendChild(chips.el);
      root.appendChild(form);
      var scaleCtl = UI.segmented([{ value: 'linear', label: 'Linear' }, { value: 'log', label: 'log₂' }], scale, function (v) { scale = v; ctx.set({ scale: v === 'linear' ? '' : v }); draw(); }, 'Scale');
      var orderCtl = UI.segmented([{ value: 'study', label: 'By study' }, { value: 'tissue', label: 'By tissue' }], order, function (v) { order = v; ctx.set({ order: v === 'study' ? '' : v }); draw(); }, 'Order');
      var data = [], notFound = '';
      var fig = C.figure({ label: 'Expression of the genes in each selected sample', filename: 'expression_plot', controls: [scaleCtl, orderCtl], getTable: function () {
        var samples = ET.SEL.list();
        return { header: ['sample', 'study', 'tissue'].concat(data.map(function (g) { return g.gene; })), numeric: [false, false, false].concat(data.map(function () { return true; })),
                 rows: samples.map(function (s) { return [s.label, s.studyName, s.tissue].concat(data.map(function (g) { return g.values ? g.values[s.idx] : null; })); }) };
      } });
      var p = UI.panel({});
      UI.body(p).appendChild(fig);
      root.appendChild(p);
      var explorer = EX.explorer(fig, {
        mode: ctx.get('pv', 'window'), start: ctx.get('ws') === '' ? null : +ctx.get('ws') || 0, size: +ctx.get('wn', 24) || 24, marks: 8,
        setOrder: function (v) { order = v; orderCtl.set(v); ctx.set({ order: v === 'study' ? '' : v }); draw(); },
        onChange: function (s) { ctx.set({ pv: s.mode === 'window' ? '' : s.mode, ws: s.start || '', wn: s.size === 24 ? '' : s.size }); }
      });
      ctx.onDispose(explorer.destroy);
      /* A drawn plot is redrawn in place, never wiped first: Plotly keeps
         its layout on the node, and a wipe left it redrawing into nothing
         (a spinner that never ended when a second gene was added). */
      function load(list) {
        ctx.set({ genes: list.join(',') });
        if (!list.length) { C.clear(fig.plotNode); fig.plotNode.appendChild(UI.message('Add genes above.', 'info')); explorer.update(null); return; }
        fig.classList.add('is-busy');
        ET.values(list, { signal: ctx.signal }).then(function (v) {
          if (!ctx.alive()) { return; }
          data = list.map(function (id) { var c = v.map[id]; return v.genes.filter(function (g) { return g.gene === c; })[0]; }).filter(Boolean);
          notFound = v.missing.length ? ' <span class="et-warn">Not found: ' + esc(v.missing.join(', ')) + '.</span>' : '';
          fig.classList.remove('is-busy');
          draw();
        }, function (e) { if (ctx.alive()) { fig.classList.remove('is-busy'); C.clear(fig.plotNode); fig.plotNode.appendChild(UI.errorBox(e)); explorer.update(null); } });
      }
      function draw() {
        if (!data.length) { return; }
        EX.bars(fig.plotNode, data, { scale: scale, order: order, unit: ET.unit(), filename: 'expression_plot', legendHost: fig.legendHost,
          marks: data.length === 1 ? 8 : 0, explorer: true,
          onClick: function (s) { explorer.focusSample(s); },
          onStudy: function (run) { explorer.snapRange(run.start, run.end); } }).then(function (r) {
          fig.caption.innerHTML = '<strong>' + esc(ET.SEL.label()) + '</strong>: ' + fmtInt(r.used) + ' samples. Hover a bar for the sample; the table lists every value.' + notFound;
          if (data.length === 1) {
            var present = {};
            (r.ordered || []).forEach(function (s) { present[s.tissue] = (present[s.tissue] || 0) + 1; });
            fig.legendHost.innerHTML = '';
            fig.legendHost.appendChild(C.legend('tissue', present));
          }
          fig.renderTable();
          explorer.update(r, data, { scale: scale, order: order, unit: ET.unit() });
        });
      }
      ctx.on('selection', draw);
      load(chips.get());
    }
  });

  /* ------------------------------------------------------------------------
     Two genes compared
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'compare', group: 'Visualize', title: 'Compare two genes', nav: 'Compare two genes',
    summary: 'Two genes across the selected samples, one point per sample: how closely they follow each other, and where they part.',
    card: 'Two genes, one point per sample: Pearson and Spearman, and the samples where they differ most.',
    selection: true,
    render: function (root, ctx) {
      var g1 = ctx.get('g1'), g2 = ctx.get('g2');
      var scale = ctx.get('scale', 'log');
      /* Gene 2 can come from another NAM genome (g2g), which is how the Genome
         pairs table opens a pair here. Two genomes have only the 23 shared
         samples in common, so the points are those, within the selection:
         shared sample j of one genome is shared sample j of every other
         (the catalogs' shared_sample_ids, checked over all 26). */
      var G = ET.state.genome;
      var gb = ET.genomeByKey(ctx.get('g2g'));
      gb = G.nam && gb && gb.nam && gb.key !== G.key ? gb.key : '';
      var form = UI.panel({});
      var row = el('<form class="et-form-row"></form>');
      var i1 = UI.geneInput({ value: g1, label: 'Gene 1', onPick: function (v) { g1 = v; load(); } });
      var i2 = UI.geneInput({ value: g2, label: 'Gene 2', genome: function () { return gb; }, onPick: function (v) { g2 = v; load(); } });
      row.appendChild(UI.field('Gene 1 (x axis)', i1));
      row.appendChild(UI.field('Gene 2 (y axis)', i2));
      if (G.nam) {
        var gSel = UI.select([{ value: '', label: 'Same as gene 1 (' + G.short + ')' }].concat(ET.state.genomes.filter(function (x) { return x.nam && x.key !== G.key; })
          .map(function (x) { return { value: x.key, label: x.short }; })), gb);
        gSel.addEventListener('change', function () { gb = gSel.value; });
        row.appendChild(UI.field('Gene 2 genome', gSel));
      }
      var go = el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Compare</button></div>');
      row.appendChild(go);
      row.addEventListener('submit', function (e) { e.preventDefault(); g1 = i1.value; g2 = i2.value; load(); });
      UI.body(form).appendChild(row);
      root.appendChild(form);
      var scaleCtl = UI.segmented([{ value: 'linear', label: 'Linear' }, { value: 'log', label: 'log₂' }], scale, function (v) { scale = v; ctx.set({ scale: v }); draw(); }, 'Scale');
      var a = null, b = null, pts = [];
      var fig = C.figure({ label: 'Two genes, one point per sample', filename: function () { return (a ? a.gene : 'g1') + '_vs_' + (b ? b.gene : 'g2'); }, controls: [scaleCtl], getTable: function () {
        return { header: ['sample', 'study', 'tissue', a ? a.gene : 'gene 1', b ? b.gene : 'gene 2', 'log2 ratio'], numeric: [false, false, false, true, true, true],
                 rows: pts.map(function (p) { return [p.s.label, p.s.studyName, p.s.tissue, p.x, p.y, Math.round(Math.log((p.x + 1) / (p.y + 1)) / Math.LN2 * 1000) / 1000]; }) };
      } });
      var diff = [];
      var dfig = C.figure({ label: 'Where the two genes differ most', filename: function () { return (a ? a.gene : 'g1') + '_vs_' + (b ? b.gene : 'g2') + '_ratio'; }, getTable: function () {
        return { header: ['sample', 'study', 'tissue', a ? a.gene : 'gene 1', b ? b.gene : 'gene 2', 'log2 ratio'], numeric: [false, false, false, true, true, true],
                 rows: diff.map(function (v) { return [v.s.label, v.s.studyName, v.s.tissue, v.x, v.y, Math.round(v.r * 1000) / 1000]; }) };
      } });
      var grid = el('<div class="et-grid2"></div>');
      var p1 = UI.panel({ title: 'Scatter' }); UI.body(p1).appendChild(fig);
      var legendHost = fig.legendHost;
      var p2 = UI.panel({ title: 'Where they differ' }); UI.body(p2).appendChild(dfig);
      grid.appendChild(p1); grid.appendChild(p2);
      root.appendChild(grid);
      /* Gene 2's genome and its catalog as of the last load, so the genome
         select changes nothing until Compare runs. */
      var GB = null, catB = null, seq = 0;
      function load() {
        if (!g1 || !g2) { C.clear(fig.plotNode); fig.plotNode.appendChild(UI.message('Enter two genes.', 'info')); return; }
        ctx.set({ g1: g1, g2: g2, g2g: gb });
        var my = ++seq, want = gb;
        var got = want
          ? Promise.all([ET.values([g1], { signal: ctx.signal }), ET.values([g2], { genome: want, signal: ctx.signal }), ET.catalogOf(want)])
          : ET.values([g1, g2], { signal: ctx.signal }).then(function (v) { return [v, v, null]; });
        got.then(function (r) {
          if (my !== seq || !ctx.alive()) { return; }
          a = r[0].genes.filter(function (g) { return g.gene === r[0].map[g1]; })[0];
          b = r[1].genes.filter(function (g) { return g.gene === r[1].map[g2]; })[0];
          GB = want ? ET.genomeByKey(want) : null;
          catB = r[2];
          if (!a || !b) {
            var missing = !want ? r[0].missing : r[0].missing.map(function (m) { return m + ' in ' + G.short; }).concat(r[1].missing.map(function (m) { return m + ' in ' + GB.short; }));
            C.clear(fig.plotNode); fig.plotNode.appendChild(UI.message('Not found: ' + esc(missing.join(', ')), 'error')); return;
          }
          draw();
        }, function (e) { if (my === seq && ctx.alive()) { C.clear(fig.plotNode); fig.plotNode.appendChild(UI.errorBox(e)); } });
      }
      function draw() {
        if (!a || !b) { return; }
        if (catB) {
          var picked = {};
          ET.SEL.list().forEach(function (s) { picked[s.id] = true; });
          var byA = ET.state.catalog.assays.rna.byId, byB = catB.assays.rna.byId, idsB = catB.shared_sample_ids || [];
          pts = [];
          (ET.state.catalog.shared_sample_ids || []).forEach(function (id, j) {
            var s = byA[id], t = byB[idsB[j]];
            if (s && t && picked[id] && a.values[s.idx] != null && b.values[t.idx] != null) { pts.push({ s: s, x: a.values[s.idx], y: b.values[t.idx] }); }
          });
        } else {
          pts = ET.SEL.list().filter(function (s) { return a.values[s.idx] != null && b.values[s.idx] != null; }).map(function (s) { return { s: s, x: a.values[s.idx], y: b.values[s.idx] }; });
        }
        if (pts.length < 3) { C.clear(fig.plotNode); fig.plotNode.appendChild(UI.message('These genes share ' + pts.length + ' measured samples in the selection' + (catB ? ', of the 23 the NAM genomes share' : '') + '.', 'info')); return; }
        /* Across two genomes both genes can carry the same symbol. */
        var nameA = (a.symbol || a.gene) + (catB ? ' (' + G.short + ')' : ''), nameB = (b.symbol || b.gene) + (catB ? ' (' + GB.short + ')' : '');
        var tf = function (v) { return scale === 'log' ? S.log2p1(v) : v; };
        var X = pts.map(function (p) { return tf(p.x); }), Y = pts.map(function (p) { return tf(p.y); });
        var rp = S.pearson(X, Y), rs = S.spearman(pts.map(function (p) { return p.x; }), pts.map(function (p) { return p.y; }));
        var present = {};
        var traces = ET.TISSUES.map(function (t) {
          var sub = pts.filter(function (p) { return p.s.tissue === t; });
          if (!sub.length) { return null; }
          present[t] = sub.length;
          return { type: 'scatter', mode: 'markers', name: t, x: sub.map(function (p) { return tf(p.x); }), y: sub.map(function (p) { return tf(p.y); }),
                   marker: { size: 9, color: C.tissueColor(t), symbol: ET.TISSUE_SYMBOLS[t], line: { color: '#ffffff', width: 1.5 } }, hoverinfo: 'text',
                   hovertext: sub.map(function (p) { return esc(p.s.label) + '<br>' + esc(U.shortStudy(p.s.studyName)) + '<br>' + esc(nameA) + ' <b>' + fmt(p.x) + '</b> · ' + esc(nameB) + ' <b>' + fmt(p.y) + '</b>'; }) };
        }).filter(Boolean);
        var mx = Math.max.apply(null, X.concat(Y)) * 1.05 || 1;
        traces.push({ type: 'scatter', mode: 'lines', x: [0, mx], y: [0, mx], line: { color: '#9a9994', width: 1 }, hoverinfo: 'skip', showlegend: false });
        var fit = S.linfit(X, Y), x0 = Math.min.apply(null, X), x1 = Math.max.apply(null, X);
        traces.push({ type: 'scatter', mode: 'lines', x: [x0, x1], y: [fit.a + fit.b * x0, fit.a + fit.b * x1], line: { color: ET.INK, width: 2 }, hoverinfo: 'skip', showlegend: false });
        var unit = scale === 'log' ? 'log₂(value + 1)' : 'value';
        C.plot(fig.plotNode, traces, { showlegend: false, xaxis: { title: { text: EX.geneTitle(a) + (catB ? ' · ' + G.short : '') + ' · ' + unit }, rangemode: 'tozero' },
                                       yaxis: { title: { text: EX.geneTitle(b) + (catB ? ' · ' + GB.short : '') + ' · ' + unit }, rangemode: 'tozero' },
                                       margin: { l: 70, r: 12, t: 10, b: 56 } }, { height: 440 });
        legendHost.innerHTML = '';
        legendHost.appendChild(C.legend('tissue', present));
        fig.caption.innerHTML = 'n = <strong>' + pts.length + '</strong> samples · Pearson r = <strong>' + U.fmtR(rp) + '</strong> (' + (scale === 'log' ? 'log scale' : 'linear') + ') · Spearman ρ = <strong>' + U.fmtR(rs) + '</strong>' +
          '. Gray line: equal values; dark line: least squares.' +
          (catB ? ' Gene 1 is from ' + esc(G.short) + ' and gene 2 from ' + esc(GB.short) + '; two genomes have only the 23 samples the NAM genomes share in common, so the points are those, within the selection.' : '') +
          (pts.length < 10 ? ' <span class="et-warn">Few points; read r as a hint.</span>' : '');
        fig.renderTable();
        var dd = pts.map(function (p) { return { s: p.s, x: p.x, y: p.y, r: Math.log((p.x + 1) / (p.y + 1)) / Math.LN2 }; }).sort(function (u, v) { return Math.abs(v.r) - Math.abs(u.r); }).slice(0, 25);
        diff = dd;
        C.plot(dfig.plotNode, [{ type: 'bar', orientation: 'h', x: dd.map(function (v) { return v.r; }), y: dd.map(function (_, i) { return i; }),
          marker: { color: dd.map(function (v) { return v.r >= 0 ? ET.DIV[0] : ET.DIV[4]; }) }, hoverinfo: 'text',
          hovertext: dd.map(function (v) { return '<b>' + v.r.toFixed(2) + '</b> log₂ ratio<br>' + esc(v.s.label); }) }],
          { xaxis: { title: { text: '← ' + nameB + ' higher · ' + nameA + ' higher →' }, zeroline: true },
            yaxis: { tickmode: 'array', tickvals: dd.map(function (_, i) { return i; }), ticktext: dd.map(function (v) { return v.s.label.length > 30 ? v.s.label.slice(0, 28) + '…' : v.s.label; }), autorange: 'reversed', tickfont: { size: 10.5 } },
            showlegend: false, margin: { l: 10, r: 12, t: 8, b: 50 } }, { height: Math.max(300, dd.length * 17 + 80) });
        dfig.caption.textContent = 'log₂ of (gene 1 + 1) / (gene 2 + 1), the 25 samples where they differ most. Across studies the units differ, so read a ratio within one study.';
        dfig.renderTable();
      }
      ctx.on('selection', draw);
      load();
    }
  });

  /* ------------------------------------------------------------------------
     Heatmap and profiles
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'heatmap', group: 'Visualize', title: 'Heatmap', nav: 'Heatmap',
    summary: 'Up to 300 genes across the selected samples, clustered, as row z-scores or as expression levels, with their profiles.',
    card: 'Up to 300 genes clustered across the selected samples: z-scores or levels, and expression profiles.',
    selection: true,
    render: function (root, ctx) {
      var MAX = 300;
      var start = (ctx.get('genes') || '').split(',').filter(Boolean);
      var mode = ctx.get('scale', 'z'), clusterRows = ctx.get('cr') !== '0', clusterCols = ctx.get('cc') === '1';
      var form = UI.panel({});
      var input = UI.geneListInput({ value: start, rows: 4, label: 'Genes', example: ET.state.genome.key === 'B73v5' ? ['lg1', 'lg2', 'lg3', 'kn1', 'rs1', 'gn1', 'lg4', 'sln1', 'wus1', 'tb1', 'ra1', 'ra2'] : [] });
      ctx.onDispose(input.dispose);
      UI.body(form).appendChild(input.el);
      var bar = el('<div class="et-toolbar"></div>');
      var go = UI.button('Draw', { kind: 'mgdb-button-primary', onClick: function () { load(input.get()); } });
      bar.appendChild(go);
      UI.body(form).appendChild(bar);
      root.appendChild(form);
      var modeCtl = UI.segmented([{ value: 'z', label: 'Row z-score' }, { value: 'log', label: 'log₂(x + 1)' }], mode, function (v) { mode = v; ctx.set({ scale: v === 'z' ? '' : v }); draw(); }, 'Values');
      var rowsCtl = UI.segmented([{ value: '1', label: 'Cluster genes' }, { value: '0', label: 'Input order' }], clusterRows ? '1' : '0', function (v) { clusterRows = v === '1'; ctx.set({ cr: clusterRows ? '' : '0' }); draw(); }, 'Genes');
      var colsCtl = UI.segmented([{ value: '0', label: 'Samples by study' }, { value: '1', label: 'Cluster samples' }], clusterCols ? '1' : '0', function (v) { clusterCols = v === '1'; ctx.set({ cc: clusterCols ? '1' : '' }); draw(); }, 'Samples');
      var genes = [];
      var last = null;
      var fig = C.figure({ label: 'Heatmap of the genes across the selected samples', filename: function () { return 'heatmap_' + genes.length + '_genes'; }, controls: [modeCtl, rowsCtl, colsCtl], getTable: function () {
        if (!last) { return null; }
        return { header: ['gene', 'symbol'].concat(last.samples.map(function (s) { return s.label; })), numeric: [false, false].concat(last.samples.map(function () { return true; })),
                 rows: last.genes.map(function (g) { return [g.gene, g.symbol || ''].concat(last.samples.map(function (s) { return g.values[s.idx]; })); }) };
      } });
      var prof = C.figure({ label: 'Expression profiles', filename: 'profiles' });
      var p = UI.panel({ title: 'Heatmap' }); UI.body(p).appendChild(fig);
      var legendHost = fig.legendHost;
      var pp = UI.panel({ title: 'Profiles' }); UI.body(pp).appendChild(prof);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      function load(ids) {
        if (ids.length < 2) { out.innerHTML = ''; out.appendChild(UI.message('Enter at least two genes.', 'info')); return; }
        var capped = ids.length > MAX;
        ids = ids.slice(0, MAX);
        ctx.set({ genes: ids.join(',') });
        out.innerHTML = '';
        out.appendChild(UI.loading('Loading ' + U.plural(ids.length, 'gene') + '…'));
        ET.values(ids, { signal: ctx.signal }).then(function (v) {
          if (!ctx.alive()) { return; }
          genes = v.genes.filter(function (g) { return g.values; });
          out.innerHTML = '';
          if (v.missing.length) { out.appendChild(UI.message('Not found: <span class="et-mono">' + esc(v.missing.slice(0, 50).join(', ')) + '</span>', 'info')); }
          if (capped) { out.appendChild(UI.message('Only the first ' + MAX + ' genes are drawn.', 'info')); }
          out.appendChild(p);
          out.appendChild(pp);
          draw();
        }, function (e) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); });
      }
      function draw() {
        if (!genes.length) { return; }
        var samples = EX.order(ET.SEL.list(), 'study').filter(function (s) { return genes.some(function (g) { return g.values[s.idx] != null; }); });
        if (samples.length < 2) { C.clear(fig.plotNode); fig.plotNode.appendChild(UI.message('Select at least two samples with values.', 'info')); return; }
        var logs = genes.map(function (g) { return samples.map(function (s) { var x = g.values[s.idx]; return x == null ? NaN : S.log2p1(x); }); });
        var z = S.zscoreRows(logs);
        var M = mode === 'z' ? z : logs;
        var fill0 = function (r) { return r.map(function (x) { return isFinite(x) ? x : 0; }); };
        var rOrder = clusterRows && genes.length > 2 ? S.hclustOrder(z.map(fill0)) : genes.map(function (_, i) { return i; });
        var cOrder = samples.map(function (_, j) { return j; });
        if (clusterCols && samples.length > 2) { cOrder = S.hclustOrder(samples.map(function (_, j) { return z.map(function (r) { return isFinite(r[j]) ? r[j] : 0; }); })); }
        var ordSamples = cOrder.map(function (j) { return samples[j]; });
        last = { genes: rOrder.map(function (i) { return genes[i]; }), samples: ordSamples };
        var Z = rOrder.map(function (i) { return cOrder.map(function (j) { return isFinite(M[i][j]) ? M[i][j] : null; }); });
        var zmax = mode === 'z' ? 2.5 : undefined;
        var xs = ordSamples.map(function (_, k) { return k; });
        var shapes = ordSamples.map(function (s, k) { return { type: 'rect', xref: 'x', yref: 'paper', x0: k - 0.5, x1: k + 0.5, y0: 1.004, y1: 1.03, fillcolor: C.tissueColor(s.tissue), line: { width: 0 } }; });
        var ylab = rOrder.map(function (i) { return genes[i].symbol ? genes[i].symbol : genes[i].gene; });
        var height = Math.min(1600, Math.max(320, genes.length * (genes.length > 80 ? 9 : 18) + 140));
        C.plot(fig.plotNode, [{
          type: 'heatmap', z: Z, x: xs, y: rOrder.map(function (_, k) { return k; }), zmin: mode === 'z' ? -zmax : undefined, zmax: zmax,
          colorscale: mode === 'z' ? C.divScale() : C.seqScale(), hoverongaps: false, xgap: samples.length < 80 ? 1 : 0, ygap: genes.length < 80 ? 1 : 0,
          customdata: rOrder.map(function (i) { return ordSamples.map(function (s) { return [esc(s.label), esc(U.shortStudy(s.studyName)), fmt(genes[i].values[s.idx]), esc(genes[i].gene)]; }); }),
          hovertemplate: '<b>%{customdata[3]}</b><br>%{customdata[0]} · %{customdata[1]}<br>value %{customdata[2]}<br>' + (mode === 'z' ? 'z %{z:.2f}' : 'log₂ %{z:.2f}') + '<extra></extra>',
          colorbar: { title: { text: mode === 'z' ? 'z' : 'log₂', side: 'right' }, thickness: 12, len: 0.6 }
        }], { xaxis: { showticklabels: samples.length <= 60, tickmode: 'array', tickvals: xs, ticktext: ordSamples.map(function (s) { return s.label.length > 22 ? s.label.slice(0, 20) + '…' : s.label; }), tickangle: -60, tickfont: { size: 9 }, showgrid: false },
              yaxis: { tickmode: 'array', tickvals: rOrder.map(function (_, k) { return k; }), ticktext: ylab, autorange: 'reversed', tickfont: { size: genes.length > 60 ? 8 : 11 }, showgrid: false },
              shapes: shapes, margin: { l: 110, r: 12, t: 22, b: samples.length <= 60 ? 150 : 24 } }, { height: height });
        var present = {};
        ordSamples.forEach(function (s) { present[s.tissue] = (present[s.tissue] || 0) + 1; });
        legendHost.innerHTML = '';
        legendHost.appendChild(C.legend('tissue', present));
        fig.caption.innerHTML = fmtInt(genes.length) + ' genes × ' + fmtInt(samples.length) + ' samples (' + esc(ET.SEL.label()) + '). ' +
          (mode === 'z' ? 'Color is each gene’s z-score over the selection on log₂(value + 1): blue below the gene’s own mean, red above.' : 'Color is log₂(value + 1), light to dark.') +
          ' The strip above marks each sample’s tissue. Blank cells were not measured.';
        fig.renderTable();
        var many = genes.length > 8;
        var traces = rOrder.map(function (i, k) {
          return { type: 'scatter', mode: 'lines', name: genes[i].symbol || genes[i].gene, x: xs, y: cOrder.map(function (j) { return isFinite(z[i][j]) ? z[i][j] : null; }),
                   line: { width: many ? 1 : 2, color: many ? '#b9bdb6' : C.seriesColor(k) }, opacity: many ? 0.7 : 1, showlegend: !many, hoverinfo: 'name' };
        });
        if (many) {
          traces.push({ type: 'scatter', mode: 'lines', name: 'mean of the genes', x: xs, y: cOrder.map(function (j) { return S.mean(z.map(function (r) { return r[j]; }).filter(isFinite)); }), line: { width: 3, color: ET.SERIES[0] } });
        }
        C.plot(prof.plotNode, traces, { xaxis: { showticklabels: false, title: { text: 'samples in the heatmap’s order' } }, yaxis: { title: { text: 'z-score' }, zeroline: true }, margin: { l: 56, r: 12, t: 12, b: 40 } }, { height: 360 });
        prof.caption.textContent = many ? 'Every gene in gray, their mean in blue.' : 'One line per gene, in the heatmap’s sample order.';
      }
      ctx.on('selection', draw);
      if (start.length) { load(start); }
    }
  });
})(window, document);
