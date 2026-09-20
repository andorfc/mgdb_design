/* file: mgdb-genome-figure.js
 *
 * purpose: the genome stewardship timeline at /genome_figure, in two views,
 *          drawn so that the picture on screen and the file that downloads are
 *          the same object.
 *
 * Two things about how this is built are deliberate.
 *
 * 1. The SVG is built with DOM calls and every colour, width and font is a
 *    presentation attribute on the element itself. No CSS styles the drawing.
 *    An exported SVG carries no stylesheet, so a chart that depends on one
 *    downloads as black-on-white line art -- and the export is the whole point
 *    of this page. Building it this way also means exporting is a clone and a
 *    serialize, with nothing to go wrong in between. (The pan-gene figures
 *    take the other road, inlining computed styles at export time, and an
 *    unescaped font name in a hand-built XML attribute silently produced an
 *    unparseable file. Nothing here is hand-built XML.)
 *
 * 2. The crosshair, the hover dot and the pointer hitbox live in one group,
 *    #gf-interactive, which the exporter removes from its clone. A figure in a
 *    talk should not carry an invisible rectangle and a stray dot.
 *
 * Nothing about the data is decided here. The points, the landmark labels,
 * their heights and the projection all arrive as JSON from
 * controllers/genome_figure.php, which reads them from
 * include/genome_growth_lib.php -- the same file /genome reads.
 *
 * history:
 *  09/20/26  claude  created
 */
(function () {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';

  /* The canvas. 960x470 for both views, so the two exports drop into the same
     placeholder in a deck without resizing. The plot area is 876x356; the hub
     chart's is 885x356, so the curve has the same shape and the same height
     here as it does on /genome. The extra left margin is for three-digit tick
     labels, which the hub never needs. */
  var W = 960, H = 470;
  var M = { left: 56, right: 28, top: 58, bottom: 56 };
  var PW = W - M.left - M.right;
  var PH = H - M.top - M.bottom;

  /* The hub chart's own colours, plus one blue for the projection. */
  var INK       = '#23281c';
  var MUTED     = '#697065';
  var GRID      = '#eceee6';
  var GREEN     = '#4d8a1c';
  var GREEN_DK  = '#3a6b12';
  var AREA      = '#5fa028';
  var GOLD      = '#d99a0b';
  var GOLD_EDGE = '#7a5600';
  var GOLD_INK  = '#5b4a12';
  var BLUE      = '#1f6fd0';
  var BLUE_DK   = '#14509b';

  /* Named fonts rather than system-ui: the file has to render the same in
     PowerPoint, Illustrator and a plain browser, and system-ui resolves to
     nothing outside a browser. */
  var FONT = "'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif";

  var CREDIT = 'MaizeGDB · maizegdb.org/genome';

  var state = { view: 'current', withTitle: true, data: null };

  /* ------------------------------------------------------------------------
     Small helpers
     ------------------------------------------------------------------------ */

  function byId(id) { return document.getElementById(id); }

  function el(name, attrs) {
    var node = document.createElementNS(NS, name);
    if (attrs) {
      for (var key in attrs) {
        if (Object.prototype.hasOwnProperty.call(attrs, key) && attrs[key] !== null) {
          node.setAttribute(key, attrs[key]);
        }
      }
    }
    return node;
  }

  function label(x, y, value, opts) {
    opts = opts || {};
    var node = el('text', {
      x: x, y: y,
      'font-family': FONT,
      'font-size': opts.size || 12,
      'font-weight': opts.weight || 400,
      fill: opts.fill || INK,
      'text-anchor': opts.anchor || 'start'
    });
    node.appendChild(document.createTextNode(value));
    return node;
  }

  /* ------------------------------------------------------------------------
     Scales
     ------------------------------------------------------------------------ */

  /* The current view's axis is the hub's formula unchanged -- a floor of 175
     and a step of 25 -- because "the same timeline" has to mean the same
     drawing, gridlines included.

     The projected view runs past 500, where a step of 25 would draw twenty-odd
     gridlines, so a step is chosen that gives at most seven intervals and
     leaves at least 8% headroom above the highest point for its label. */
  function axisFor(dataMax, projected) {
    if (!projected) {
      return { max: Math.max(175, Math.ceil(dataMax / 25) * 25), step: 25 };
    }
    var steps = [25, 50, 75, 100, 125, 150, 200, 250, 500, 1000];
    var needed = dataMax * 1.08;
    for (var i = 0; i < steps.length; i++) {
      var max = Math.ceil(needed / steps[i]) * steps[i];
      if (max / steps[i] <= 7) { return { max: max, step: steps[i] }; }
    }
    return { max: Math.ceil(needed / 1000) * 1000, step: 1000 };
  }

  /* Where the landmark labels sit.

     The controller gives each landmark a height in assemblies, chosen against
     the hub's 175-assembly axis so the stems step up the curve. In the current
     view those heights are used as they are. In the projected view the axis is
     three times taller and the historical curve sits in its lower third, so
     using them unchanged would leave every label hanging far above its pin.
     They are re-spread over a band in the lower two-thirds instead, keeping
     their order and their relative spacing. */
  function levelsFor(milestoneYears, levels, axis, histAxis, projected) {
    var out = {};
    var i, year, fractions = [], lo = Infinity, hi = -Infinity;

    for (i = 0; i < milestoneYears.length; i++) {
      year = milestoneYears[i];
      var f = (levels[year] !== undefined ? levels[year] : 0) / histAxis.max;
      fractions.push(f);
      if (f < lo) { lo = f; }
      if (f > hi) { hi = f; }
    }
    for (i = 0; i < milestoneYears.length; i++) {
      year = milestoneYears[i];
      if (!projected) {
        out[year] = fractions[i] * axis.max;
      } else {
        var n = (hi > lo) ? (fractions[i] - lo) / (hi - lo) : 0.5;
        out[year] = axis.max * (0.10 + n * 0.52);
      }
    }
    return out;
  }

  /* ------------------------------------------------------------------------
     The drawing
     ------------------------------------------------------------------------ */

  function draw() {
    var svg = byId('gf-chart');
    var data = state.data;
    if (!svg || !data) { return; }

    var projected = state.view === 'projected';
    var proj = data.projection || {};
    var history = data.points.map(function (row) { return [row[0], row[1]]; });
    var lastPoint = history[history.length - 1];
    var endValue = lastPoint[1] + (proj.added || 0);

    var firstYear = history[0][0];
    var lastYear = projected ? proj.year : lastPoint[0];
    var dataMax = projected ? endValue : lastPoint[1];
    var histAxis = axisFor(Math.max.apply(null, history.map(function (r) { return r[1]; })), false);
    var axis = axisFor(dataMax, projected);

    var x = function (year) { return M.left + ((year - firstYear) / (lastYear - firstYear)) * PW; };
    var y = function (value) { return M.top + (1 - value / axis.max) * PH; };

    while (svg.firstChild) { svg.removeChild(svg.firstChild); }
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

    /* A real white background. Transparent exports come out black on a dark
       slide master, and PowerPoint gives no way to fix that afterwards. */
    svg.appendChild(el('rect', { x: 0, y: 0, width: W, height: H, fill: '#ffffff' }));

    /* ---- titles ---------------------------------------------------------- */
    if (state.withTitle) {
      svg.appendChild(label(M.left - 8, 26, 'Genome datasets hosted at MaizeGDB',
                            { size: 17, weight: 700, fill: INK }));
      svg.appendChild(label(M.left - 8, 45, projected
        ? 'Assemblies by year, ' + firstYear + '–' + lastPoint[0] +
          ', with ' + (proj.label || 'projected') + ' additions to ' + lastYear
        : 'Assemblies by year, ' + firstYear + '–' + lastYear,
        { size: 12.5, fill: MUTED }));
    }

    /* ---- gridlines and the value axis ------------------------------------ */
    for (var value = 0; value <= axis.max; value += axis.step) {
      svg.appendChild(el('line', {
        x1: M.left, y1: y(value), x2: W - M.right, y2: y(value),
        stroke: GRID, 'stroke-width': 1
      }));
      svg.appendChild(label(M.left - 9, y(value) + 4, String(value),
                            { size: 12, fill: MUTED, anchor: 'end' }));
    }

    /* ---- the year axis --------------------------------------------------- */
    for (var year = firstYear; year <= lastYear; year++) {
      svg.appendChild(label(x(year), M.top + PH + 20, "’" + String(year).slice(2),
                            { size: 11, fill: MUTED, anchor: 'middle' }));
    }
    /* The first year in full, so a reader is not left to expand an apostrophe.
       The last year is not repeated: the subtitle states the span, and the
       source credit goes in that corner instead. */
    svg.appendChild(label(M.left, M.top + PH + 38, String(firstYear), { size: 12, fill: MUTED }));
    if (state.withTitle) {
      svg.appendChild(label(W - M.right, M.top + PH + 38, CREDIT,
                            { size: 11, fill: MUTED, anchor: 'end' }));
    }

    /* ---- the historical curve -------------------------------------------- */
    var pts = history.map(function (row) { return [x(row[0]), y(row[1])]; });
    var line = 'M' + pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' L');

    /* A flat translucent fill rather than the hub's gradient. Gradients with
       stop-opacity are the first thing to break in a slide program's SVG
       importer, and the difference at this size is not visible. */
    svg.appendChild(el('path', {
      d: line + ' L' + pts[pts.length - 1][0].toFixed(1) + ',' + y(0) +
         ' L' + pts[0][0].toFixed(1) + ',' + y(0) + ' Z',
      fill: AREA, 'fill-opacity': 0.16, stroke: 'none'
    }));
    svg.appendChild(el('path', {
      d: line, fill: 'none', stroke: GREEN, 'stroke-width': 3,
      'stroke-linecap': 'round', 'stroke-linejoin': 'round'
    }));

    /* ---- the projected segment ------------------------------------------- */
    if (projected) {
      var from = pts[pts.length - 1];
      var to = [x(lastYear), y(endValue)];
      svg.appendChild(el('path', {
        d: 'M' + from[0].toFixed(1) + ',' + from[1].toFixed(1) +
           ' L' + to[0].toFixed(1) + ',' + to[1].toFixed(1) +
           ' L' + to[0].toFixed(1) + ',' + y(0) +
           ' L' + from[0].toFixed(1) + ',' + y(0) + ' Z',
        fill: BLUE, 'fill-opacity': 0.09, stroke: 'none'
      }));
      svg.appendChild(el('path', {
        d: 'M' + from[0].toFixed(1) + ',' + from[1].toFixed(1) +
           ' L' + to[0].toFixed(1) + ',' + to[1].toFixed(1),
        fill: 'none', stroke: BLUE, 'stroke-width': 3,
        'stroke-dasharray': '9 7', 'stroke-linecap': 'round'
      }));
    }

    /* ---- landmark pins --------------------------------------------------- */
    var milestones = data.milestones || {};
    var years = Object.keys(milestones).map(Number).sort(function (a, b) { return a - b; });
    var heights = levelsFor(years, data.levels || {}, axis, histAxis, projected);

    years.forEach(function (year) {
      var row = history.filter(function (entry) { return entry[0] === year; })[0];
      if (!row) { return; }
      var pointX = x(year), pointY = y(row[1]);
      var labelY = y(Math.min(heights[year], axis.max - 2));
      var anchor = year >= lastPoint[0] - 2 ? 'end' : (year <= firstYear + 2 ? 'start' : 'middle');
      /* 'start' labels sit just inside the plot. The hub starts them 4px to
         the LEFT of the pin, which is outside its own axis; at this taller
         scale that dropped "B73" on top of a value-axis tick label. */
      var labelX = year >= lastPoint[0] - 2 ? pointX + 4 : (year <= firstYear + 2 ? pointX + 2 : pointX);

      svg.appendChild(el('line', {
        x1: pointX, y1: pointY - 6, x2: pointX, y2: labelY + 4,
        stroke: GOLD, 'stroke-width': 1.5, opacity: 0.85
      }));
      svg.appendChild(el('circle', {
        cx: pointX, cy: pointY, r: 4.7, fill: GOLD, stroke: GOLD_EDGE, 'stroke-width': 1.5
      }));
      svg.appendChild(label(labelX, labelY, milestones[year],
                            { size: 12, weight: 700, fill: GOLD_INK, anchor: anchor }));
    });

    /* ---- data points and the end value ----------------------------------- */
    pts.forEach(function (point) {
      svg.appendChild(el('circle', {
        cx: point[0].toFixed(1), cy: point[1].toFixed(1), r: 3.5,
        fill: GREEN, stroke: '#ffffff', 'stroke-width': 2
      }));
    });

    if (projected) {
      var endX = x(lastYear), endY = y(endValue);
      /* Hollow, so the end of the curve reads as a target rather than a
         measurement even in a black-and-white printout. */
      svg.appendChild(el('circle', {
        cx: endX, cy: endY, r: 6, fill: '#ffffff', stroke: BLUE, 'stroke-width': 3
      }));
      svg.appendChild(label(endX, endY - 34, proj.label || 'Projected',
                            { size: 13, weight: 700, fill: BLUE_DK, anchor: 'end' }));
      svg.appendChild(label(endX, endY - 16,
                            '+' + (proj.added || 0) + ' projected → ' + endValue,
                            { size: 12, weight: 600, fill: BLUE, anchor: 'end' }));
      svg.appendChild(label(x(lastPoint[0]) - 7, y(lastPoint[1]) - 12, String(lastPoint[1]),
                            { size: 14, weight: 800, fill: GREEN_DK, anchor: 'end' }));
    } else {
      svg.appendChild(label(x(lastPoint[0]) - 7, y(lastPoint[1]) - 10, String(lastPoint[1]),
                            { size: 14, weight: 800, fill: GREEN_DK, anchor: 'end' }));
    }

    drawLegend(svg, projected);
    drawInteractive(svg, history, x, y);

    var alt = byId('gf-chart-alt');
    if (alt) {
      alt.textContent = projected
        ? 'Line chart of genome assemblies hosted at MaizeGDB from ' + firstYear + ', rising to ' +
          lastPoint[1] + ' in ' + lastPoint[0] + ', continued by a dashed projected segment to ' +
          endValue + ' in ' + lastYear + ' with the addition of ' + (proj.added || 0) +
          ' ' + (proj.label || 'projected') + ' assemblies. Landmark releases are annotated.'
        : 'Line chart of genome assemblies hosted at MaizeGDB from ' + firstYear + ' to ' +
          lastPoint[0] + ', rising from ' + history[0][1] + ' to ' + lastPoint[1] +
          '. Landmark releases are annotated.';
    }

    var caption = byId('gf-caption');
    if (caption) {
      caption.textContent = projected
        ? 'The record to ' + lastPoint[0] + ' in green, and the ' + (proj.label || 'projected') +
          ' target in blue. The dashed segment and hollow end point mark the projection; no value is' +
          ' drawn for ' + (lastPoint[0] + 1) + ', because the ' + (proj.added || 0) +
          ' assemblies are a target for ' + lastYear + ' rather than a year-by-year forecast.'
        : 'Assemblies hosted at MaizeGDB from ' + firstYear + ' to ' + lastPoint[0] +
          ', with landmark releases marked. This is the chart under Metrics on the Genome Data Hub,' +
          ' drawn from the same numbers.';
    }

    buildTable(history, milestones, projected ? { year: lastYear, value: endValue, label: proj.label } : null);
  }

  /* The legend is laid out left to right from zero, measured, then moved to
     the right edge in one translate. Measuring is only possible once the text
     is in the document, which is why it is built in place rather than sized by
     guesswork. */
  function drawLegend(svg, projected) {
    var group = el('g', {});
    svg.appendChild(group);

    var items = [
      { kind: 'line', color: GREEN, dash: null, text: 'Assemblies hosted' },
      { kind: 'dot', color: GOLD, edge: GOLD_EDGE, text: 'Landmark release' }
    ];
    if (projected) {
      items.splice(1, 0, { kind: 'line', color: BLUE, dash: '7 5', text: 'Projected' });
    }

    var cursor = 0, baseline = 30;
    items.forEach(function (item) {
      if (item.kind === 'line') {
        group.appendChild(el('line', {
          x1: cursor, y1: baseline - 4, x2: cursor + 22, y2: baseline - 4,
          stroke: item.color, 'stroke-width': 3, 'stroke-linecap': 'round',
          'stroke-dasharray': item.dash
        }));
      } else {
        group.appendChild(el('circle', {
          cx: cursor + 11, cy: baseline - 4, r: 4.7,
          fill: item.color, stroke: item.edge, 'stroke-width': 1.5
        }));
      }
      var text = label(cursor + 28, baseline, item.text, { size: 12, fill: MUTED });
      group.appendChild(text);
      cursor += 28 + textWidth(text, item.text) + 20;
    });

    var width = Math.max(0, cursor - 20);
    group.setAttribute('transform', 'translate(' + ((W - M.right) - width) + ',0)');
  }

  /* getComputedTextLength() throws in some engines when the element is not
     rendered, and returns 0 in others, so a character estimate stands in. At
     12px in this stack a character averages a shade under 6.2px. */
  function textWidth(node, value) {
    try {
      var measured = node.getComputedTextLength();
      if (measured > 0) { return measured; }
    } catch (error) { /* fall through */ }
    return value.length * 6.2;
  }

  /* The crosshair, the hover dot and the pointer target, in one group so the
     exporter can drop all three with a single removeChild. */
  function drawInteractive(svg, history, x, y) {
    var group = el('g', { id: 'gf-interactive' });
    var crosshair = el('line', {
      x1: 0, y1: M.top, x2: 0, y2: M.top + PH,
      stroke: GREEN_DK, 'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0
    });
    var dot = el('circle', { cx: 0, cy: 0, r: 5, fill: GOLD, stroke: '#ffffff', 'stroke-width': 2, opacity: 0 });
    var hitbox = el('rect', { x: M.left, y: M.top, width: PW, height: PH, fill: 'transparent' });
    group.appendChild(crosshair);
    group.appendChild(dot);
    group.appendChild(hitbox);
    svg.appendChild(group);

    var tip = byId('gf-tip');

    function show(event) {
      var bounds = svg.getBoundingClientRect();
      if (!bounds.width) { return; }
      var svgX = ((event.clientX - bounds.left) / bounds.width) * W;
      var best = history[0], distance = Infinity;
      history.forEach(function (row) {
        var current = Math.abs(x(row[0]) - svgX);
        if (current < distance) { distance = current; best = row; }
      });
      var pointX = x(best[0]), pointY = y(best[1]);
      crosshair.setAttribute('x1', pointX);
      crosshair.setAttribute('x2', pointX);
      crosshair.setAttribute('opacity', 1);
      dot.setAttribute('cx', pointX);
      dot.setAttribute('cy', pointY);
      dot.setAttribute('opacity', 1);
      if (!tip) { return; }
      var index = history.indexOf(best);
      var change = index ? best[1] - history[index - 1][1] : null;
      tip.textContent = best[0] + ' · ' + best[1] + ' assemblies' +
        (change === null ? '' : ' · Δ ' + (change >= 0 ? '+' : '') + change);
      tip.style.left = ((pointX / W) * bounds.width) + 'px';
      tip.style.top = ((pointY / H) * bounds.height) + 'px';
      tip.classList.add('is-visible');
    }

    hitbox.addEventListener('pointermove', show);
    hitbox.addEventListener('pointerdown', show);
    hitbox.addEventListener('pointerleave', function () {
      crosshair.setAttribute('opacity', 0);
      dot.setAttribute('opacity', 0);
      if (tip) { tip.classList.remove('is-visible'); }
    });
  }

  /* ------------------------------------------------------------------------
     The data table
     ------------------------------------------------------------------------ */

  function buildTable(history, milestones, projection) {
    var tbody = document.querySelector('#gf-table tbody');
    if (!tbody) { return; }
    while (tbody.firstChild) { tbody.removeChild(tbody.firstChild); }

    function cell(tag, value, className) {
      var node = document.createElement(tag);
      node.textContent = value;
      if (className) { node.className = className; }
      return node;
    }

    history.forEach(function (point, index) {
      var row = document.createElement('tr');
      var year = cell('th', String(point[0]));
      year.setAttribute('scope', 'row');
      row.appendChild(year);
      row.appendChild(cell('td', point[1].toLocaleString(), 'mgdb-numeric'));
      var change = index ? point[1] - history[index - 1][1] : null;
      row.appendChild(cell('td', change === null ? '—' : (change >= 0 ? '+' : '') + change, 'mgdb-numeric'));
      row.appendChild(cell('td', milestones[point[0]] || '—'));
      tbody.appendChild(row);
    });

    if (projection) {
      /* The projected row is marked in the table too. A number in a table is
         read as a measurement unless it says otherwise, and the year it skips
         is the visible sign that it is not a series. */
      var row = document.createElement('tr');
      row.className = 'gf-row-projected';
      var year = cell('th', String(projection.year));
      year.setAttribute('scope', 'row');
      row.appendChild(year);
      row.appendChild(cell('td', projection.value.toLocaleString(), 'mgdb-numeric'));
      row.appendChild(cell('td', '+' + (projection.value - history[history.length - 1][1]), 'mgdb-numeric'));
      row.appendChild(cell('td', (projection.label || 'Projected') + ' (projected)'));
      tbody.appendChild(row);
    }
  }

  /* ------------------------------------------------------------------------
     Export
     ------------------------------------------------------------------------ */

  function serialize() {
    var svg = byId('gf-chart');
    if (!svg) { return null; }
    var clone = svg.cloneNode(true);

    var interactive = clone.querySelector('#gf-interactive');
    if (interactive) { interactive.parentNode.removeChild(interactive); }

    clone.removeAttribute('id');
    clone.removeAttribute('role');
    clone.removeAttribute('aria-labelledby');
    clone.setAttribute('xmlns', NS);
    clone.setAttribute('width', W);
    clone.setAttribute('height', H);

    var title = document.createElementNS(NS, 'title');
    title.appendChild(document.createTextNode('Genome datasets hosted at MaizeGDB'));
    clone.insertBefore(title, clone.firstChild);

    return '<?xml version="1.0" encoding="UTF-8"?>\n' +
           new XMLSerializer().serializeToString(clone);
  }

  function filename(extension) {
    return 'maizegdb-genome-timeline-' + state.view + '.' + extension;
  }

  function save(blob, name) {
    var url = URL.createObjectURL(blob);
    var anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = name;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    /* Revoked on a later turn of the event loop: Safari has not finished with
       the URL when click() returns. */
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  function status(message) {
    var node = byId('gf-status');
    if (node) { node.textContent = message; }
  }

  function downloadSvg() {
    var xml = serialize();
    if (!xml) { return; }
    save(new Blob([xml], { type: 'image/svg+xml;charset=utf-8' }), filename('svg'));
    status('Downloaded ' + filename('svg') + ' — vector, ' + W + '×' + H +
           ' points. Insert it in PowerPoint with Insert › Pictures.');
  }

  function downloadPng() {
    var xml = serialize();
    if (!xml) { return; }
    var scale = 3;
    var image = new Image();
    image.onload = function () {
      var canvas = document.createElement('canvas');
      canvas.width = W * scale;
      canvas.height = H * scale;
      var context = canvas.getContext('2d');
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(function (blob) {
        if (!blob) { status('The browser could not produce a PNG. The SVG download works.'); return; }
        save(blob, filename('png'));
        status('Downloaded ' + filename('png') + ' — ' + canvas.width + '×' +
               canvas.height + ' pixels, ' + scale + '× for print.');
      }, 'image/png');
    };
    image.onerror = function () {
      status('The browser could not rasterise the figure. Use the SVG download instead.');
    };
    image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
  }

  /* ------------------------------------------------------------------------
     Wiring
     ------------------------------------------------------------------------ */

  function setView(view) {
    state.view = view;
    ['current', 'projected'].forEach(function (name) {
      var button = byId('gf-view-' + name);
      if (!button) { return; }
      var on = name === view;
      button.classList.toggle('is-active', on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    status('');
    draw();
  }

  function start() {
    var node = byId('gf-figure-data');
    if (!node) { return; }
    try {
      state.data = JSON.parse(node.textContent || 'null');
    } catch (error) {
      state.data = null;
    }
    if (!state.data || !state.data.points || !state.data.points.length) { return; }

    ['current', 'projected'].forEach(function (name) {
      var button = byId('gf-view-' + name);
      if (button) { button.addEventListener('click', function () { setView(name); }); }
    });

    var titleToggle = byId('gf-title-toggle');
    if (titleToggle) {
      titleToggle.addEventListener('change', function () {
        state.withTitle = titleToggle.checked;
        draw();
      });
    }

    var svgButton = byId('gf-download-svg');
    if (svgButton) { svgButton.addEventListener('click', downloadSvg); }
    var pngButton = byId('gf-download-png');
    if (pngButton) { pngButton.addEventListener('click', downloadPng); }

    var toggle = byId('gf-table-toggle');
    var wrap = byId('gf-table-wrap');
    if (toggle && wrap) {
      toggle.addEventListener('click', function () {
        wrap.hidden = !wrap.hidden;
        toggle.setAttribute('aria-expanded', wrap.hidden ? 'false' : 'true');
        toggle.textContent = wrap.hidden ? 'Show data table' : 'Hide data table';
      });
    }

    setView('current');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
