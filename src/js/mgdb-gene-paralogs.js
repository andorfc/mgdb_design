/* file: js/mgdb-gene-paralogs.js
 *
 * purpose: the gene record's "Homeologs and tandem arrays" section.
 *
 *            MGDB.geneParalogs(container, section, ctx)
 *
 *              section  sections.paralogs of the gene record API: the
 *                       retained maize1/maize2 homeolog (each copy's Ka, Ks
 *                       and omega against the sorghum syntelog), the tandem
 *                       array, the chromosome lengths, the table-wide
 *                       distributions and the expression route.
 *              ctx      { gene, symbol, profile } -- this gene's name and its
 *                       expression profile, as the record already carries it.
 *
 *          Four blocks, each drawn only when it has something: the homeolog
 *          (a synteny sketch and the divergence tracks), the tandem array (a
 *          track of the array among its neighbours and a table), the
 *          expression of the pair (this gene against a chosen partner --
 *          the homeolog by default, or any member of the array -- across
 *          every sample, by tissue, and by stress type), and where the
 *          data come from.
 *
 *          SVG throughout, like the Expression figure, and in its colours:
 *          this gene is always the green circle and the partner the wine
 *          diamond, whichever subgenome each is.
 *
 * history:
 *  09/17/26  claude  created
 */
(function () {
  'use strict';

  var MGDB = window.MGDB = window.MGDB || {};
  var SVG_NS = 'http://www.w3.org/2000/svg';

  var TISSUE_ORDER = ['root', 'leaf', 'stem', 'shoot apex', 'floral', 'seed', 'seedling / whole plant', 'other'];
  var GROUPS = [
    { key: 'atlas', label: 'Development atlases' },
    { key: 'control', label: 'Stress studies: controls' },
    { key: 'abiotic', label: 'Abiotic stress' },
    { key: 'biotic', label: 'Biotic stress' }
  ];

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function el(name, attrs, text) {
    var node = document.createElementNS(SVG_NS, name);
    for (var k in attrs) { if (attrs[k] !== null && attrs[k] !== undefined) { node.setAttribute(k, attrs[k]); } }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }
  function html(tag, cls, inner) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (inner !== undefined && inner !== null) { node.innerHTML = inner; }
    return node;
  }
  function fixed(v, d) { return (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toFixed(d === undefined ? 3 : d); }
  function int(v) { return Number(v).toLocaleString(); }
  function mb(bp) { return (bp / 1e6).toFixed(2) + ' Mb'; }
  function span(bp) { return bp >= 1e6 ? (bp / 1e6).toFixed(2) + ' Mb' : (bp >= 1e4 ? Math.round(bp / 1e3) : (bp / 1e3).toFixed(1)) + ' kb'; }
  function log2p(v) { return Math.log(v + 1) / Math.LN2; }
  function unlog(v) { return Math.pow(2, v) - 1; }
  function median(xs) {
    if (!xs.length) { return null; }
    var s = xs.slice().sort(function (a, b) { return a - b; });
    var m = Math.floor(s.length / 2);
    return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
  }
  function mean(xs) { return xs.length ? xs.reduce(function (a, b) { return a + b; }, 0) / xs.length : null; }
  function pearson(xs, ys) {
    var n = xs.length;
    if (n < 3) { return null; }
    var mx = mean(xs), my = mean(ys), sxy = 0, sxx = 0, syy = 0;
    for (var i = 0; i < n; i++) {
      var dx = xs[i] - mx, dy = ys[i] - my;
      sxy += dx * dy; sxx += dx * dx; syy += dy * dy;
    }
    return (sxx && syy) ? sxy / Math.sqrt(sxx * syy) : null;
  }
  /* A gene as a reader knows it: its name when it has one, with the model id. */
  function nameHtml(gene, symbol, link) {
    var label = symbol ? esc(symbol) + ' <span class="gp-id">' + esc(gene) + '</span>' : '<span class="gp-id-only">' + esc(gene) + '</span>';
    return link === false ? label : '<a href="/gene_center/gene/' + encodeURIComponent(gene) + '">' + label + '</a>';
  }
  function short(gene, symbol) { return symbol || gene; }
  function niceStep(max, ticks) {
    var raw = max / Math.max(1, ticks);
    var p = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
    var m = raw / p;
    return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p;
  }

  /* This gene's and the partner's markers, the same everywhere in the section. */
  function marker(kind, x, y, size, title) {
    var g = el('g', { 'class': 'gp-mark gp-mark-' + kind });
    if (kind === 'this') {
      g.appendChild(el('circle', { cx: x, cy: y, r: size }));
    } else {
      var s = size * 1.25;
      g.appendChild(el('path', { d: 'M' + x + ' ' + (y - s) + 'L' + (x + s) + ' ' + y + 'L' + x + ' ' + (y + s) + 'L' + (x - s) + ' ' + y + 'Z' }));
    }
    if (title) { g.appendChild(el('title', {}, title)); }
    return g;
  }
  function keyHtml(thisName, partnerName) {
    return '<div class="gp-key"><span><i class="gp-key-this"></i>' + thisName + ' <small>this gene</small></span>' +
      '<span><i class="gp-key-partner"></i>' + partnerName + '</span></div>';
  }

  function block(title, note) {
    var b = html('div', 'mgdb-rec-block gp-block');
    b.appendChild(html('div', 'mgdb-rec-block-head', '<h3>' + esc(title) + '</h3>' + (note || '')));
    return b;
  }

  /* ======================================================================
     Retained homeolog
     ====================================================================== */

  function homeologBlock(sec, ctx) {
    var h = sec.homeolog, me = h.this, mate = h.partner;
    var b = block('Retained homeolog');
    var sb = h.sorghum || {};
    var sbHtml = sb.url ? '<a href="' + esc(sb.url) + '" target="_blank" rel="noopener">' + esc(sb.id) + '</a>' : esc(sb.id || 'a sorghum gene');
    if (h.complete) {
      b.appendChild(html('p', 'gp-lead',
        '<strong>' + nameHtml(me.gene, me.symbol, false) + '</strong> is the <strong>' + esc(me.subgenome) + '</strong> copy of a gene both maize subgenomes kept after the whole-genome duplication. ' +
        'Its ' + esc(mate.subgenome) + ' homeolog is ' + nameHtml(mate.gene, mate.symbol) + ' on ' + esc(mate.chr) + '. ' +
        'Both are syntenic with sorghum ' + sbHtml + '.'));
      b.appendChild(syntenySketch(sec));
    } else {
      b.appendChild(html('p', 'gp-lead',
        '<strong>' + nameHtml(me.gene, me.symbol, false) + '</strong> is the <strong>' + esc(me.subgenome) + '</strong> copy of a retained pair in the B73 RefGen_v4 table, syntenic with sorghum ' + sbHtml + '. ' +
        'Its ' + esc(mate.subgenome) + ' homeolog there, <a href="' + esc(mate.html) + '">' + esc(mate.v4) + '</a> (v4 ' + esc(mate.v4_location.chr) + ', ' + mb(mate.v4_location.start) + '), ' +
        'could not be placed on this annotation' + (mate.note ? ': ' + esc(mate.note) : '') + '. Its divergence from sorghum is still the table’s.'));
    }
    b.appendChild(divergence(sec, ctx));
    return b;
  }

  function syntenySketch(sec) {
    var h = sec.homeolog, chroms = sec.chromosomes || {};
    var m1 = h.this.subgenome === 'maize1' ? h.this : h.partner;
    var m2 = m1 === h.this ? h.partner : h.this;
    var L1 = chroms[m1.chr] || m1.end, L2 = chroms[m2.chr] || m2.end;
    var maxL = Math.max(L1, L2);
    var left = 118, width = 840, y1 = 52, y2 = 150, barH = 12;
    var fig = html('figure', 'gp-figure gp-synteny-figure');
    var svg = el('svg', { viewBox: '0 0 1000 200', 'class': 'gp-synteny', role: 'img',
      'aria-label': 'The maize1 copy on ' + m1.chr + ' at ' + mb(m1.start) + ' and the maize2 copy on ' + m2.chr + ' at ' + mb(m2.start) + ', both syntenic with ' + (h.sorghum.id || 'the same sorghum gene') + '.' });

    function row(copy, L, y, above) {
      var isThis = copy === h.this;
      svg.appendChild(el('text', { x: left - 16, y: y + 5, 'text-anchor': 'end', 'class': 'gp-syn-sub' }, copy.subgenome));
      svg.appendChild(el('text', { x: left - 16, y: y + 21, 'text-anchor': 'end', 'class': 'gp-syn-chr' }, copy.chr + ' · ' + Math.round(L / 1e6) + ' Mb'));
      svg.appendChild(el('rect', { x: left, y: y - barH / 2, width: width * L / maxL, height: barH, rx: barH / 2, 'class': 'gp-syn-bar' }));
      var x = left + width * ((copy.start + copy.end) / 2) / maxL;
      svg.appendChild(el('rect', { x: x - 2.5, y: y - barH / 2 - 5, width: 5, height: barH + 10, rx: 2, 'class': 'gp-syn-pin' + (isThis ? ' is-this' : '') }));
      var anchor = x < left + 90 ? 'start' : (x > left + width - 90 ? 'end' : 'middle');
      var ly = above ? y - barH / 2 - 14 : y + barH / 2 + 24;
      var link = el('a', { href: '/gene_center/gene/' + encodeURIComponent(copy.gene) });
      link.appendChild(el('text', { x: x, y: ly, 'text-anchor': anchor, 'class': 'gp-syn-gene' + (isThis ? ' is-this' : '') },
        short(copy.gene, copy.symbol) + ' · ' + mb(copy.start)));
      svg.appendChild(link);
      return x;
    }
    var x1 = row(m1, L1, y1, true);
    var x2 = row(m2, L2, y2, false);
    var top = y1 + barH / 2 + 4, bottom = y2 - barH / 2 - 4, mid = (top + bottom) / 2;
    svg.appendChild(el('path', { d: 'M' + x1 + ' ' + top + ' C' + x1 + ' ' + mid + ' ' + x2 + ' ' + mid + ' ' + x2 + ' ' + bottom, 'class': 'gp-syn-link' }));
    if (h.sorghum && h.sorghum.id) {
      var label = 'sorghum ' + h.sorghum.id;
      var mx = (x1 + x2) / 2, w = label.length * 6.6 + 16;
      var lx = Math.max(left, Math.min(left + width - w, mx - w / 2));
      svg.appendChild(el('rect', { x: lx, y: mid - 11, width: w, height: 22, rx: 11, 'class': 'gp-syn-anchor' }));
      svg.appendChild(el('text', { x: lx + w / 2, y: mid + 4, 'text-anchor': 'middle', 'class': 'gp-syn-anchor-text' }, label));
    }
    fig.appendChild(svg);
    fig.appendChild(html('figcaption', null, 'Both copies drawn to the scale of the longer chromosome. The pair is anchored on its shared sorghum syntelog: the two copies are the halves of one ancestral gene the maize whole-genome duplication made two of.'));
    return fig;
  }

  function divergence(sec, ctx) {
    var h = sec.homeolog, st = sec.stats || {};
    var me = h.this, mate = h.partner;
    var wrap = html('div', 'gp-div');
    wrap.appendChild(html('h4', 'gp-sub', 'Divergence from the sorghum syntelog <small>each copy against the sorghum gene, from the source table</small>'));
    wrap.appendChild(html('div', null, keyHtml(nameHtml(me.gene, me.symbol, false) + ' (' + esc(me.subgenome) + ')',
      nameHtml(mate.gene || mate.v4, mate.symbol, false) + ' (' + esc(mate.subgenome) + ')')));
    var rows = [
      ['ka', 'Ka', 'nonsynonymous substitutions per nonsynonymous site: changes to the protein'],
      ['ks', 'Ks', 'synonymous substitutions per synonymous site: the silent changes, a clock'],
      ['omega', 'ω = Ka/Ks', 'below 1, changes to the protein were purged; the nearer 1, the less constraint']
    ];
    rows.forEach(function (r) {
      var key = r[0], s = st[key] && st[key].all, a = me[key], c = mate[key];
      var hi = Math.max(s ? s.p95 : 0, a || 0, c || 0) * 1.08 || 1;
      function pct(v) { return Math.max(0, Math.min(100, 100 * v / hi)); }
      var row = html('div', 'gp-div-row');
      row.innerHTML = '<div class="gp-div-head"><span class="gp-div-label">' + r[1] + '</span>' +
        '<span class="gp-div-vals"><b class="is-this">' + fixed(a, 4) + '</b><b class="is-partner">' + fixed(c, 4) + '</b></span></div>';
      var track = el('svg', { viewBox: '0 0 1000 26', preserveAspectRatio: 'none', 'class': 'gp-div-track', role: 'img',
        'aria-label': r[1] + ': ' + fixed(a, 4) + ' for ' + short(me.gene, me.symbol) + ', ' + fixed(c, 4) + ' for ' + short(mate.gene || mate.v4, mate.symbol) +
                      (s ? '; the table’s median is ' + fixed(s.p50, 3) : '') });
      track.appendChild(el('rect', { x: 0, y: 11, width: 1000, height: 4, rx: 2, 'class': 'gp-div-rail' }));
      if (s) {
        track.appendChild(el('rect', { x: pct(s.p5) * 10, y: 8, width: (pct(s.p95) - pct(s.p5)) * 10, height: 10, rx: 5, 'class': 'gp-div-band' }));
        track.appendChild(el('rect', { x: pct(s.p50) * 10 - 1.5, y: 4, width: 3, height: 18, 'class': 'gp-div-median' }));
      }
      var trackWrap = html('div', 'gp-div-trackwrap');
      trackWrap.appendChild(track);
      row.appendChild(trackWrap);
      /* The markers sit in an HTML layer over the stretched SVG so they stay
         round at any width. */
      var dots = html('div', 'gp-div-dots');
      if (a !== null && a !== undefined) { dots.appendChild(html('span', 'gp-dot gp-dot-this', '')).style.left = pct(a) + '%'; }
      if (c !== null && c !== undefined) { dots.appendChild(html('span', 'gp-dot gp-dot-partner', '')).style.left = pct(c) + '%'; }
      trackWrap.appendChild(dots);
      row.appendChild(html('div', 'gp-div-ends', '<span>0</span>' + (s ? '<span>median ' + fixed(s.p50, 3) + ' <small>of ' + int(s.n) + ' copies</small></span><span>' + fixed(s.p95, 3) + ' <small>95th percentile</small></span>' : '<span></span>')));
      row.appendChild(html('p', 'gp-div-note', r[2]));
      wrap.appendChild(row);
    });
    wrap.appendChild(html('p', 'gp-read', omegaReading(me, mate, st)));
    return wrap;
  }

  function omegaReading(me, mate, st) {
    var a = me.omega, c = mate.omega;
    var an = short(me.gene, me.symbol), cn = short(mate.gene || mate.v4, mate.symbol);
    var out = '';
    if (a === null || c === null || a === undefined || c === undefined) {
      out = 'The table gives no ω for ' + (a == null ? an : cn) + ', so the two copies cannot be compared on it.';
    } else {
      var hiName = a >= c ? an : cn, hi = Math.max(a, c), lo = Math.min(a, c);
      if (lo > 0 && hi / lo >= 1.5) {
        out = '<strong>' + esc(hiName) + '</strong> has the higher ω (' + fixed(hi, 3) + ' against ' + fixed(lo, 3) + '): more of its protein changes were kept since the split from sorghum, the pattern of a copy under relaxed constraint.';
      } else if (lo === 0 && hi > 0) {
        out = '<strong>' + esc(hiName) + '</strong> has the higher ω; the other copy has no protein change against sorghum at all.';
      } else {
        out = 'The two copies’ ω are within 1.5-fold of each other (' + fixed(a, 3) + ' and ' + fixed(c, 3) + '): similar constraint on both.';
      }
      if (hi > 1) { out += ' An ω above 1 means more protein changes than silent ones, which is rare in this table.'; }
    }
    var m1 = st.omega && st.omega.maize1, m2 = st.omega && st.omega.maize2;
    if (m1 && m2) {
      out += ' Across the table, the median ω of maize1 copies is ' + fixed(m1.p50, 3) + ' and of maize2 copies ' + fixed(m2.p50, 3) + '.';
    }
    return out;
  }

  /* ======================================================================
     Tandem array
     ====================================================================== */

  function tandemBlock(sec, ctx) {
    var t = sec.tandem;
    var me = t.members.filter(function (m) { return m.this; })[0] || t.members[0];
    var between = t.context.filter(function (g) { return g.start >= t.start && g.end <= t.end; }).length;
    var b = block('Tandem array');
    b.appendChild(html('p', 'gp-lead',
      '<strong>' + nameHtml(me.gene, me.symbol, false) + '</strong> is one of <strong>' + t.size + '</strong> genes in a tandem array on ' + esc(t.chr) + ', ' +
      mb(t.start) + ' to ' + mb(t.end) + ' (' + span(t.end - t.start + 1) + '). ' +
      (between ? between + ' other gene' + (between === 1 ? ' lies' : 's lie') + ' between its members.' : 'Its members are adjacent.')));
    b.appendChild(tandemTrack(t));

    var R = window.MGDBRecord;
    var tableHost = html('div', 'gp-tandem-table');
    b.appendChild(tableHost);
    if (R && R.collection) {
      R.collection(tableHost, {
        title: 'Genes in the array', items: t.members, filename: 'tandem-array.tsv',
        columns: [
          { key: 'gene', label: 'Gene model', tile: true, html: function (m) { return '<a href="/gene_center/gene/' + encodeURIComponent(m.gene) + '">' + esc(m.gene) + '</a>' + (m.this ? ' <span class="mgdb-pill mgdb-pill-ok">This gene</span>' : ''); } },
          { key: 'symbol', label: 'Name', get: function (m) { return m.symbol || ''; } },
          { key: 'start', label: 'Position', sort: 'number', get: function (m) { return t.chr + ':' + int(m.start) + '-' + int(m.end); } },
          { key: 'strand', label: 'Strand' },
          { key: 'length', label: 'Length', sort: 'number', numeric: true, get: function (m) { return span(m.end - m.start + 1); } }
        ]
      });
    }
    return b;
  }

  function tandemTrack(t) {
    /* The axis is the array and a margin, not every neighbour the server
       sent: three genes either side can be a megabase away and would squeeze
       the array into a corner. Neighbours are drawn where they fall in it. */
    var width0 = t.end - t.start + 1;
    var pad = Math.max(width0 * 0.15, 10000);
    var lo = t.start - pad, hi = t.end + pad;
    var genes = t.members.concat(t.context.filter(function (g) { return g.end >= lo && g.start <= hi; }))
      .sort(function (a, b) { return a.start - b.start; });
    var left = 20, width = 960, y = 70, gh = 16;
    function x(bp) { return left + width * (Math.max(lo, Math.min(hi, bp)) - lo) / (hi - lo); }
    var fig = html('figure', 'gp-figure gp-tandem-figure');
    var svg = el('svg', { viewBox: '0 0 1000 176', 'class': 'gp-tandem', role: 'img',
      'aria-label': 'The ' + t.size + ' genes of the array on ' + t.chr + ' and the genes around them.' });
    svg.appendChild(el('line', { x1: left, x2: left + width, y1: y + gh / 2, y2: y + gh / 2, 'class': 'gp-tan-axisline' }));

    /* Labels in up to four rows, two above and two below, each put in the
       first row where it clears the label before it. */
    var lanes = [
      { y: y - 16, tick: [y - 4, y - 10], right: -Infinity },
      { y: y + gh + 26, tick: [y + gh + 4, y + gh + 12], right: -Infinity },
      { y: y - 34, tick: [y - 4, y - 28], right: -Infinity },
      { y: y + gh + 44, tick: [y + gh + 4, y + gh + 30], right: -Infinity }
    ];
    var placed = [];
    genes.forEach(function (g) {
      var x1 = x(g.start), x2 = Math.max(x(g.end), x1 + 3);
      var ah = Math.min(8, (x2 - x1) * 0.6);
      var d = g.strand === '-'
        ? 'M' + x2 + ' ' + y + 'L' + (x1 + ah) + ' ' + y + 'L' + x1 + ' ' + (y + gh / 2) + 'L' + (x1 + ah) + ' ' + (y + gh) + 'L' + x2 + ' ' + (y + gh) + 'Z'
        : 'M' + x1 + ' ' + y + 'L' + (x2 - ah) + ' ' + y + 'L' + x2 + ' ' + (y + gh / 2) + 'L' + (x2 - ah) + ' ' + (y + gh) + 'L' + x1 + ' ' + (y + gh) + 'Z';
      var a = el('a', { href: '/gene_center/gene/' + encodeURIComponent(g.gene) });
      a.appendChild(el('path', { d: d, 'class': 'gp-tan-gene' + (g.member ? ' is-member' : '') + (g.this ? ' is-this' : '') }));
      a.appendChild(el('title', {}, g.gene + (g.symbol ? ' (' + g.symbol + ')' : '') + ' · ' + (g.member ? 'in the array' : 'neighbour, not in the array') +
        ' · ' + t.chr + ':' + int(g.start) + '-' + int(g.end) + ' (' + g.strand + ')'));
      svg.appendChild(a);
      if (g.member) { placed.push({ g: g, a: a, cx: (x1 + x2) / 2 }); }
    });
    placed.forEach(function (p) {
      var text = p.g.symbol || p.g.gene.replace(/^Zm00001eb/, '…');
      var w = text.length * 6.9 + 8;
      var anchor = p.cx - w / 2 < left ? 'start' : (p.cx + w / 2 > left + width ? 'end' : 'middle');
      var l = anchor === 'start' ? p.cx : (anchor === 'end' ? p.cx - w : p.cx - w / 2);
      var lane = lanes.filter(function (ln) { return ln.right + 4 < l; })[0] || lanes[0];
      lane.right = l + w;
      p.a.appendChild(el('line', { x1: p.cx, x2: p.cx, y1: lane.tick[0], y2: lane.tick[1], 'class': 'gp-tan-tick' }));
      p.a.appendChild(el('text', { x: p.cx, y: lane.y, 'text-anchor': anchor, 'class': 'gp-tan-label' + (p.g.this ? ' is-this' : '') }, text));
    });
    var step = niceStep(hi - lo, 5);
    for (var v = Math.ceil(lo / step) * step; v <= hi; v += step) {
      svg.appendChild(el('line', { x1: x(v), x2: x(v), y1: 148, y2: 153, 'class': 'gp-tan-axisline' }));
      svg.appendChild(el('text', { x: x(v), y: 168, 'text-anchor': 'middle', 'class': 'gp-axis-text' },
        (v / 1e6).toFixed(step >= 1e5 ? 1 : (step >= 1e4 ? 2 : 3)) + ' Mb'));
    }
    fig.appendChild(svg);
    fig.appendChild(html('figcaption', null,
      '<span class="gp-swatch is-this"></span>this gene <span class="gp-swatch is-member"></span>in the array <span class="gp-swatch"></span>neighbours, not in the array. ' +
      'Arrows point along the strand; every gene opens its page.'));
    return fig;
  }

  /* ======================================================================
     Expression of the pair
     ====================================================================== */

  function partners(sec) {
    var out = [];
    var h = sec.homeolog;
    if (h && h.complete && h.partner.gene) {
      out.push({ gene: h.partner.gene, symbol: h.partner.symbol, kind: 'homeolog', note: h.partner.subgenome + ' homeolog' });
    }
    if (sec.tandem) {
      sec.tandem.members.forEach(function (m) {
        if (!m.this) { out.push({ gene: m.gene, symbol: m.symbol, kind: 'tandem', note: 'tandem copy' }); }
      });
    }
    return out;
  }

  function rnaSamples(profile) {
    var s = profile && profile.sections && profile.sections.samples;
    return (s || []).filter(function (x) { return x.assay === 'rna'; });
  }

  function groupOf(s, stressSource) {
    if (s.condition === 'abiotic stress') { return 'abiotic'; }
    if (s.condition === 'biotic stress') { return 'biotic'; }
    if (s.condition === 'control') { return 'control'; }
    return stressSource[s.source_id] ? 'control' : 'atlas';
  }

  function expressionBlock(sec, ctx, list) {
    var b = block('Expression of the pair');
    b.classList.add('gp-expr');
    var picker = html('div', 'gp-pick');
    picker.innerHTML = '<label>Compare ' + nameHtml(ctx.gene, ctx.symbol, false) + ' with <select>' +
      list.map(function (p) { return '<option value="' + esc(p.gene) + '">' + esc(short(p.gene, p.symbol)) + (p.symbol ? ' (' + esc(p.gene) + ')' : '') + ' · ' + esc(p.note) + '</option>'; }).join('') +
      '</select></label>';
    b.appendChild(picker);
    var status = html('p', 'gp-expr-status', '');
    var body = html('div', 'gp-expr-body');
    b.appendChild(status);
    b.appendChild(body);

    var mine = rnaSamples(ctx.profile);
    var sources = (ctx.profile && ctx.profile.sections && ctx.profile.sections.sources) || [];
    var stressSource = {};
    sources.forEach(function (s) { stressSource[s.id] = !!s.stress; });
    var cache = {};
    var select = picker.querySelector('select');

    function show(gene) {
      var p = list.filter(function (x) { return x.gene === gene; })[0];
      if (!p) { return; }
      select.value = gene;
      if (cache[gene]) { draw(p, cache[gene]); return; }
      status.textContent = 'Loading the expression of ' + short(p.gene, p.symbol) + '…';
      body.innerHTML = '';
      fetch(sec.expression_api + encodeURIComponent(gene), { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (json) {
          var theirs = json && json.data ? rnaSamples(json.data) : [];
          cache[gene] = theirs;
          if (select.value === gene) { draw(p, theirs); }
        })
        .catch(function () {
          status.textContent = 'The expression of ' + short(p.gene, p.symbol) + ' could not be loaded.';
        });
    }

    function draw(p, theirs) {
      body.innerHTML = '';
      var byId = {};
      theirs.forEach(function (s) { byId[s.id] = s.value; });
      var rows = [];
      mine.forEach(function (s) {
        var a = s.value, c = byId[s.id];
        if (a === null || a === undefined || c === null || c === undefined) { return; }
        rows.push({ s: s, a: a, c: c, la: log2p(a), lc: log2p(c), group: groupOf(s, stressSource) });
      });
      var pn = short(p.gene, p.symbol), mn = short(ctx.gene, ctx.symbol);
      if (!rows.length) {
        status.innerHTML = 'MaizeGDB holds no RNA values shared by ' + esc(mn) + ' and ' + esc(pn) + ', so the two cannot be compared.';
        return;
      }
      var r = pearson(rows.map(function (x) { return x.la; }), rows.map(function (x) { return x.lc; }));
      var expressed = rows.filter(function (x) { return x.a >= 1 || x.c >= 1; });
      var aHigher = expressed.filter(function (x) { return x.la > x.lc; }).length;
      status.innerHTML = 'The same <strong>' + rows.length + '</strong> RNA samples for both genes. ' +
        (r === null ? '' : 'Correlation across them <strong>r = ' + r.toFixed(2) + '</strong> on log2 values. ') +
        (expressed.length
          ? 'Of the ' + expressed.length + ' samples where either reaches 1, <strong>' + esc(mn) + '</strong> is the higher in ' + aHigher + ' (' + Math.round(100 * aHigher / expressed.length) + '%) and ' + esc(pn) + ' in ' + (expressed.length - aHigher) + '.'
          : 'Neither gene reaches 1 in any sample, so what follows compares background values.');
      body.appendChild(html('div', null, keyHtml(nameHtml(ctx.gene, ctx.symbol, false), nameHtml(p.gene, p.symbol, false) + ' <small>' + esc(p.note) + '</small>')));
      var grid = html('div', 'gp-expr-grid');
      grid.appendChild(scatter(rows, mn, pn));
      var tissueGroups = byTissue(rows), stressGroups = byStress(rows);
      var max = Math.max(1, tissueGroups.concat(stressGroups).reduce(function (m, g) { return Math.max(m, g.a, g.c); }, 0));
      max = Math.ceil(max / (max > 12 ? 4 : (max > 6 ? 2 : 1))) * (max > 12 ? 4 : (max > 6 ? 2 : 1));
      var side = html('div', 'gp-expr-side');
      side.appendChild(dumbbell('gp-tissue-figure', 'By tissue', 'median of the development-atlas samples, log2(value + 1)',
        tissueGroups, max, mn, pn, 'in each tissue', null));
      side.appendChild(dumbbell('gp-stress-type-figure', 'By stress type', 'median of the stress-study samples, log2(value + 1)',
        stressGroups, max, mn, pn, 'under each stress type',
        'Pooled across the stress studies, with the same studies\u2019 untreated controls for a baseline. Both figures share one scale.'));
      grid.appendChild(side);
      body.appendChild(grid);
    }

    select.addEventListener('change', function () { show(select.value); });
    return { node: b, show: show };
  }

  function scatter(rows, mn, pn) {
    var fig = html('figure', 'gp-figure gp-scatter-figure');
    fig.appendChild(html('h4', 'gp-sub', 'Every sample <small>log2(value + 1)</small>'));
    var max = Math.max(1, rows.reduce(function (m, x) { return Math.max(m, x.la, x.lc); }, 0));
    var step = max > 12 ? 4 : (max > 6 ? 2 : 1);
    max = Math.ceil(max / step) * step;
    var L = 46, T = 10, S = 300;
    function px(v) { return L + S * v / max; }
    function py(v) { return T + S - S * v / max; }
    var svg = el('svg', { viewBox: '0 0 360 360', 'class': 'gp-scatter', role: 'img',
      'aria-label': 'Each RNA sample as a point: ' + mn + ' across, ' + pn + ' up.' });
    for (var v = 0; v <= max; v += step) {
      svg.appendChild(el('line', { x1: px(v), x2: px(v), y1: T, y2: T + S, 'class': 'gp-grid' }));
      svg.appendChild(el('line', { x1: L, x2: L + S, y1: py(v), y2: py(v), 'class': 'gp-grid' }));
      svg.appendChild(el('text', { x: px(v), y: T + S + 16, 'text-anchor': 'middle', 'class': 'gp-axis-text' }, String(v)));
      svg.appendChild(el('text', { x: L - 8, y: py(v) + 4, 'text-anchor': 'end', 'class': 'gp-axis-text' }, String(v)));
    }
    svg.appendChild(el('line', { x1: px(0), y1: py(0), x2: px(max), y2: py(max), 'class': 'gp-diagonal' }));
    svg.appendChild(el('text', { x: L + S / 2, y: T + S + 36, 'text-anchor': 'middle', 'class': 'gp-axis-title' }, mn));
    svg.appendChild(el('text', { x: 12, y: T + S / 2, 'text-anchor': 'middle', 'class': 'gp-axis-title', transform: 'rotate(-90 12 ' + (T + S / 2) + ')' }, pn));
    /* Atlas samples underneath, stressed ones on top where they are fewer. */
    var order = { atlas: 0, control: 1, abiotic: 2, biotic: 3 };
    rows.slice().sort(function (a, b) { return order[a.group] - order[b.group]; }).forEach(function (x) {
      var c = el('circle', { cx: px(x.la), cy: py(x.lc), r: 3.4, 'class': 'gp-pt gp-pt-' + x.group });
      c.appendChild(el('title', {}, x.s.label + ' — ' + (x.s.source || '') + '\n' + mn + ': ' + fixed(x.a, 2) + ' · ' + pn + ': ' + fixed(x.c, 2)));
      svg.appendChild(c);
    });
    fig.appendChild(svg);
    var counts = {};
    rows.forEach(function (x) { counts[x.group] = (counts[x.group] || 0) + 1; });
    fig.appendChild(html('figcaption', 'gp-legend', GROUPS.filter(function (g) { return counts[g.key]; }).map(function (g) {
      return '<span><i class="gp-pt-key gp-pt-' + g.key + '"></i>' + esc(g.label) + ' <small>' + counts[g.key] + '</small></span>';
    }).join('') + '<span><i class="gp-diag-key"></i>equal expression</span>'));
    return fig;
  }

  /* Two dumbbell figures on one scale: the development atlases by tissue,
     and the stress studies by stress type with those studies' own controls
     beside them, so a stress median has its baseline in the same figure. */
  function byTissue(rows) {
    var by = {};
    rows.filter(function (x) { return x.group === 'atlas'; }).forEach(function (x) {
      (by[x.s.tissue || 'other'] = by[x.s.tissue || 'other'] || []).push(x);
    });
    return TISSUE_ORDER.filter(function (t) { return by[t]; }).map(function (t) { return summarize(t, by[t]); });
  }

  function byStress(rows) {
    var kinds = [['abiotic', 'abiotic stress'], ['biotic', 'biotic stress'], ['control', 'control']];
    return kinds.map(function (k) {
      var xs = rows.filter(function (x) { return x.group === k[0]; });
      return xs.length ? summarize(k[1], xs) : null;
    }).filter(Boolean);
  }

  function summarize(label, xs) {
    var studies = {};
    xs.forEach(function (x) { studies[x.s.source_id] = true; });
    return { label: label, n: xs.length, studies: Object.keys(studies).length,
             a: median(xs.map(function (x) { return x.la; })), c: median(xs.map(function (x) { return x.lc; })) };
  }

  function dumbbell(cls, title, sub, groups, max, mn, pn, what, note) {
    var fig = html('figure', 'gp-figure ' + cls);
    fig.appendChild(html('h4', 'gp-sub', esc(title) + ' <small>' + esc(sub) + '</small>'));
    if (!groups.length) { fig.appendChild(html('p', 'gp-empty', 'No ' + what + ' samples carry both genes.')); return fig; }
    var step = max > 12 ? 4 : (max > 6 ? 2 : 1);
    var L = 170, W = 360, rowH = 32, T = 8, H = T + groups.length * rowH + 30;
    function px(v) { return L + W * v / max; }
    var svg = el('svg', { viewBox: '0 0 560 ' + H, 'class': 'gp-dumbbell', role: 'img',
      'aria-label': 'Median expression of ' + mn + ' and ' + pn + ' ' + what + '.' });
    for (var v = 0; v <= max; v += step) {
      svg.appendChild(el('line', { x1: px(v), x2: px(v), y1: T, y2: T + groups.length * rowH, 'class': 'gp-grid' }));
      svg.appendChild(el('text', { x: px(v), y: T + groups.length * rowH + 18, 'text-anchor': 'middle', 'class': 'gp-axis-text' }, String(v)));
    }
    groups.forEach(function (g, i) {
      var y = T + i * rowH + rowH / 2;
      svg.appendChild(el('text', { x: L - 12, y: y + 4, 'text-anchor': 'end', 'class': 'gp-row-label' }, g.label));
      svg.appendChild(el('text', { x: L - 12, y: y + 16, 'text-anchor': 'end', 'class': 'gp-row-n' },
        g.n + ' sample' + (g.n === 1 ? '' : 's') + (note ? ', ' + g.studies + ' stud' + (g.studies === 1 ? 'y' : 'ies') : '')));
      svg.appendChild(el('line', { x1: px(Math.min(g.a, g.c)), x2: px(Math.max(g.a, g.c)), y1: y, y2: y, 'class': 'gp-bell' }));
      svg.appendChild(marker('partner', px(g.c), y, 6, g.label + ' · ' + pn + ': median ' + fixed(unlog(g.c), 2)));
      svg.appendChild(marker('this', px(g.a), y, 6, g.label + ' · ' + mn + ': median ' + fixed(unlog(g.a), 2)));
    });
    fig.appendChild(svg);
    if (note) { fig.appendChild(html('figcaption', null, note)); }
    return fig;
  }

  /* ======================================================================
     About the data
     ====================================================================== */

  function aboutBlock(sec) {
    var s = sec.source || {};
    var b = html('div', 'gp-about');
    b.innerHTML = '<h4 class="gp-sub">About these data</h4><ul>' +
      ('<li><strong>Homeolog pairs:</strong> ' + esc(s.title || 'the maize1/maize2 pair table') + ', computed on ' + esc(s.assembly || 'B73 RefGen_v4') + '. ' +
        int(s.pairs_placed) + ' of its ' + int(s.pairs_in_source) + ' pairs are placed on ' + esc(sec.genome_label) + ' v5 by the pan-gene crosswalk and by the table’s own coordinates lifted through the v4-to-v5 chain file; ' +
        int(s.pairs_half_placed) + ' more have one copy placed. Ka, Ks and ω are the table’s, not recomputed. ' +
        (s.download ? '<a href="' + esc(s.download) + '" download>All placed pairs (TSV)</a>' : '') + '</li>') +
      '<li><strong>Tandem arrays:</strong> the v5 annotation’s published tandem file, ' + int(s.tandem_arrays) + ' arrays of ' + int(s.tandem_genes) + ' genes.</li>' +
      '<li><strong>Expression:</strong> the RNA samples of the Expression section, as each study published them (TPM or FPKM). Units differ between studies, so the medians by tissue and by stress type are a guide to where each gene is expressed, not a measurement to compare across studies.</li>' +
      '</ul>';
    return b;
  }

  /* ======================================================================
     Entry
     ====================================================================== */

  MGDB.geneParalogs = function (container, sec, ctx) {
    if (!container || !sec || (!sec.homeolog && !sec.tandem)) { return false; }
    ctx = ctx || {};
    container.innerHTML = '';
    var list = partners(sec);
    var expr = (list.length && sec.expression_api && rnaSamples(ctx.profile).length) ? expressionBlock(sec, ctx, list) : null;
    if (sec.homeolog) { container.appendChild(homeologBlock(sec, ctx)); }
    if (sec.tandem) { container.appendChild(tandemBlock(sec, ctx)); }
    if (expr) {
      container.appendChild(expr.node);
      /* The partner's profile is a request of its own; it is made when the
         block is about to be seen, not on every page load. */
      var started = false;
      var show = expr.show;
      expr.show = function (gene) { started = true; show(gene); };
      var start = function () { if (!started) { expr.show(list[0].gene); } };
      if (typeof window.IntersectionObserver === 'function') {
        var io = new IntersectionObserver(function (entries) {
          if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); start(); }
        }, { rootMargin: '400px 0px' });
        io.observe(expr.node);
      } else {
        start();
      }
    }
    container.appendChild(aboutBlock(sec));
    return true;
  };
})();
