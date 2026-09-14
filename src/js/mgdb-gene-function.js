/**
 * mgdb-gene-function.js
 *
 * purpose: The "Function at a glance" figure of the gene record: the gene's
 *          Gene Ontology terms placed in the ontology (a plant-GO-slim
 *          fingerprint per aspect, the terms with their evidence, and the
 *          ancestry graph up to the roots), the protein's atlas class in its
 *          pan-genome context, and the metabolic pathways the explorer
 *          assigns the gene to, drawn step by step.
 *
 *          MGDB.geneFunction(container, spec)
 *            spec = {
 *              gene: { name, symbol }
 *              fn:   sections.function of the record API (go, classes,
 *                    pathways, ontology ...)
 *              base: '' or the API origin
 *            }
 *          Returns true when something was drawn.
 *
 *          Everything is inline SVG and HTML with the site's tokens. The
 *          three views of the ontology (squares, term rows, graph nodes)
 *          highlight each other on hover and pin on click. Every colour is
 *          also a word: squares and nodes carry titles, tooltips carry the
 *          definitions, and the tables below the figure keep every row.
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

  var ASPECTS = [
    { key: 'biological_process', label: 'Biological process', short: 'BP', cls: 'bp' },
    { key: 'molecular_function', label: 'Molecular function', short: 'MF', cls: 'mf' },
    { key: 'cellular_component', label: 'Cellular component', short: 'CC', cls: 'cc' }
  ];
  var ASPECT_BY_KEY = {};
  ASPECTS.forEach(function (a) { ASPECT_BY_KEY[a.key] = a; });

  var EVIDENCE_KIND = {};
  [['experimental', ['EXP', 'IDA', 'IPI', 'IMP', 'IGI', 'IEP', 'HTP', 'HDA', 'HMP', 'HGI', 'HEP']],
   ['similarity', ['ISS', 'ISO', 'ISA', 'ISM', 'IGC', 'IBA', 'IBD', 'IKR', 'IRD', 'RCA']],
   ['author', ['TAS', 'NAS', 'IC']],
   ['computational', ['IEA', 'COMP']],
   ['none', ['ND']]].forEach(function (pair) {
    pair[1].forEach(function (code) { EVIDENCE_KIND[code] = pair[0]; });
  });
  var EVIDENCE_LABEL = { experimental: 'experimental', similarity: 'by similarity', author: 'author statement',
                         computational: 'computational', none: 'no data', unknown: 'unstated' };

  var PAN_ORDER = ['core', 'near-core', 'shell', 'genome-specific', 'absent'];

  function esc(value) {
    return MGDB.escapeHtml ? MGDB.escapeHtml(value == null ? '' : String(value))
         : String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
             return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
           });
  }
  function num(v) { return v == null ? '—' : Number(v).toLocaleString(); }
  function html(tag, cls, inner) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (inner != null) { n.innerHTML = inner; }
    return n;
  }
  function el(name, attrs, text) {
    var n = document.createElementNS(SVG_NS, name);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text != null) { n.textContent = text; }
    return n;
  }
  /* The explorer's equations carry HTML entities (&rarr;, &beta;); decode
     them as text, never as markup. */
  function decodeEntities(text) {
    if (!text) { return ''; }
    var t = document.createElement('textarea');
    t.innerHTML = String(text).replace(/<[^>]*>/g, '');
    return t.value;
  }
  function shortEc(ec) { return ec ? String(ec).replace(/^EC-/, '') : ''; }
  function shortReaction(r) { return r ? String(r).replace(/-RXN$/, '').replace(/^RXN-?/, 'RXN ') : ''; }
  function evidenceKind(code) { return code ? (EVIDENCE_KIND[String(code).toUpperCase()] || 'unknown') : 'unknown'; }
  function truncate(text, max) {
    text = String(text || '');
    return text.length > max ? text.slice(0, max - 1).replace(/\s+\S*$/, '') + '…' : text;
  }
  function pct(a, b) { return b ? Math.round(100 * a / b) : 0; }

  /* ======================================================================
     MGDB.geneFunction
     ====================================================================== */
  MGDB.geneFunction = function (container, spec) {
    spec = spec || {};
    var fn = spec.fn || {};
    var gene = spec.gene || {};
    var go = fn.go && fn.go.available ? fn.go : null;
    var classes = fn.classes && fn.classes.available ? fn.classes : null;
    var pathways = fn.pathways && fn.pathways.available ? fn.pathways : null;

    var hasGo = !!(go && ((go.terms && go.terms.length) || (go.implied && go.implied.length)));
    var hasClasses = !!(classes && ((classes.classes && classes.classes.length) || (classes.entries && classes.entries.length) || classes.immunity));
    var hasPathways = !!(pathways && pathways.pathways && pathways.pathways.length);
    if (!hasGo && !hasClasses && !hasPathways) { return false; }

    container.innerHTML = '';
    container.classList.add('gf');
    var tip = html('div', 'gf-tip');
    tip.hidden = true;
    container.appendChild(tip);

    /* ---- tooltip ---- */
    function attachTip(node, build) {
      function show(e) { tip.innerHTML = build(); tip.hidden = false; place(e); }
      function place(e) {
        var rect = container.getBoundingClientRect();
        var x = (e && e.clientX != null ? e.clientX : rect.left + 40) - rect.left + 14;
        var y = (e && e.clientY != null ? e.clientY : rect.top + 40) - rect.top + 14;
        if (x + 340 > rect.width) { x = Math.max(0, x - 360); }
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
      }
      node.addEventListener('mouseenter', show);
      node.addEventListener('mousemove', place);
      node.addEventListener('mouseleave', function () { tip.hidden = true; });
      node.addEventListener('focus', function () { show(null); });
      node.addEventListener('blur', function () { tip.hidden = true; });
    }

    /* ---- cross-highlighting between squares, term rows and graph nodes ---- */
    var pinned = null;
    function clearHot() {
      Array.prototype.forEach.call(container.querySelectorAll('.is-hot'), function (n) { n.classList.remove('is-hot'); });
    }
    function setHot(termIds, slimIds) {
      clearHot();
      (termIds || []).forEach(function (id) {
        Array.prototype.forEach.call(container.querySelectorAll('[data-term="' + id + '"]'), function (n) { n.classList.add('is-hot'); });
      });
      (slimIds || []).forEach(function (id) {
        Array.prototype.forEach.call(container.querySelectorAll('[data-slim="' + id + '"]'), function (n) { n.classList.add('is-hot'); });
      });
    }
    function hotOn(node, key, getTerms, getSlims) {
      node.addEventListener('mouseenter', function () { if (!pinned) { setHot(getTerms(), getSlims()); } });
      node.addEventListener('mouseleave', function () { if (!pinned) { clearHot(); } });
      node.addEventListener('click', function () {
        if (pinned === key) { pinned = null; clearHot(); }
        else { pinned = key; setHot(getTerms(), getSlims()); }
        Array.prototype.forEach.call(container.querySelectorAll('[aria-pressed]'), function (b) {
          if (b.hasAttribute('data-hot-key')) { b.setAttribute('aria-pressed', b.getAttribute('data-hot-key') === pinned ? 'true' : 'false'); }
        });
      });
    }

    /* ---- the numbers first ---- */
    var tiles = html('div', 'gf-tiles');
    var aspectCounts = go ? (go.aspects || {}) : {};
    var litSlim = go ? (go.slim || []).filter(function (s) { return s.terms.length; }).length : 0;
    var totalSlim = go ? (go.slim || []).length : 0;
    var evidenceKinds = {};
    if (go) {
      go.terms.forEach(function (t) {
        var kinds = {};
        (t.evidence || []).forEach(function (c) { kinds[evidenceKind(c)] = true; });
        if (!(t.evidence || []).length) { kinds.unknown = true; }
        Object.keys(kinds).forEach(function (k) { evidenceKinds[k] = (evidenceKinds[k] || 0) + 1; });
      });
    }
    var evNote = Object.keys(evidenceKinds).sort().map(function (k) { return evidenceKinds[k] + ' ' + EVIDENCE_LABEL[k]; }).join(' · ');

    tiles.appendChild(html('div', 'gf-tile',
      '<span class="gf-tile-label">GO terms</span>' +
      '<span class="gf-tile-value">' + (go ? go.terms.length : '—') + '</span>' +
      '<span class="gf-tile-note">' + (go
        ? ASPECTS.map(function (a) { return '<strong>' + (aspectCounts[a.key] || 0) + '</strong> ' + a.short; }).join(' · ') +
          (go.implied.length ? ' · <strong>' + go.implied.length + '</strong> suggested by domains' : '')
        : 'GO reference index not on file') + '</span>'));
    tiles.appendChild(html('div', 'gf-tile',
      '<span class="gf-tile-label">Ontology footprint</span>' +
      '<span class="gf-tile-value">' + (go ? litSlim + ' <small>of ' + totalSlim + '</small>' : '—') + '</span>' +
      '<span class="gf-tile-note">plant GO-slim categories the terms fall under' + (evNote ? ' · ' + esc(evNote) : '') + '</span>'));
    var classValue = '—', classNote = '';
    if (classes) {
      if (classes.classes.length) {
        classValue = esc(classes.classes[0].name);
        classNote = (classes.classes[0].group ? '<strong>' + esc(classes.classes[0].group) + '</strong> · ' : '') +
                    (classes.classes[0].genes_here != null ? num(classes.classes[0].genes_here) + ' genes in ' + esc(classes.genome_label) : '') +
                    (classes.classes.length > 1 ? ' · +' + (classes.classes.length - 1) + ' more class' + (classes.classes.length > 2 ? 'es' : '') : '');
      } else if (classes.immunity) {
        classValue = esc(classes.immunity.label);
        classNote = 'immunity call' + (classes.immunity.subclass ? ' · <strong>' + esc(classes.immunity.subclass) + '</strong>' : '');
      } else if (classes.entries.length) {
        classValue = 'No curated class';
        classNote = esc(truncate(classes.architecture || '', 70));
      } else {
        classValue = 'No domain match';
        classNote = 'InterProScan found no entry on the canonical protein';
      }
    }
    tiles.appendChild(html('div', 'gf-tile',
      '<span class="gf-tile-label">Protein class</span>' +
      '<span class="gf-tile-value gf-tile-text">' + classValue + '</span>' +
      '<span class="gf-tile-note">' + classNote + '</span>'));
    tiles.appendChild(html('div', 'gf-tile',
      '<span class="gf-tile-label">Pathways</span>' +
      '<span class="gf-tile-value">' + (pathways ? pathways.pathways.length : '—') + '</span>' +
      '<span class="gf-tile-note">' + (pathways
        ? (pathways.pathways.length
            ? '<strong>' + pathways.counts.core + '</strong> core across the NAM founders · ' + pathways.counts.reactions + ' reaction' + (pathways.counts.reactions === 1 ? '' : 's')
            : 'no pathway assignment in the explorer')
        : 'pathway explorer not on file') + '</span>'));
    container.appendChild(tiles);

    /* ==================================================================
       Gene Ontology: fingerprint, terms, ancestry
       ================================================================== */
    var slimById = {};
    var termById = {};
    if (hasGo) {
      go.slim.forEach(function (s) { slimById[s.id] = s; });
      go.terms.forEach(function (t) { termById[t.term] = t; });
      go.implied.forEach(function (t) { if (!termById[t.term]) { termById[t.term] = t; } });

      var block = html('div', 'gf-block gf-go');
      block.appendChild(html('div', 'gf-block-head',
        '<h4>Gene Ontology <small>' + go.terms.length + ' term' + (go.terms.length === 1 ? '' : 's') + ' · release ' + esc((go.release || '').replace(/^releases\//, '')) + '</small></h4>' +
        '<div class="gf-legend">' +
          '<span><i class="gf-sq is-lit gf-sq-bp"></i> category with a term</span>' +
          '<span><i class="gf-sq is-implied gf-sq-bp"></i> suggested by domains only</span>' +
          '<span><i class="gf-sq gf-sq-bp"></i> not touched</span>' +
        '</div>'));

      var cols = html('div', 'gf-aspects');
      ASPECTS.forEach(function (a) {
        var terms = go.terms.filter(function (t) { return t.aspect === a.key; });
        var implied = go.implied.filter(function (t) { return t.aspect === a.key; });
        var slims = go.slim.filter(function (s) { return s.aspect === a.key; });
        var col = html('div', 'gf-aspect gf-aspect-' + a.cls);
        col.appendChild(html('h5', null, esc(a.label) + ' <small>' + terms.length + '</small>'));

        /* the fingerprint: one square per slim category, in a fixed order */
        var fp = html('div', 'gf-fp');
        fp.setAttribute('role', 'group');
        fp.setAttribute('aria-label', a.label + ' plant GO-slim categories');
        slims.forEach(function (s, i) {
          var n = s.terms.length;
          var b = html('button', 'gf-sq gf-sq-' + a.cls + (n ? ' is-lit is-lit-' + Math.min(n, 3) : (s.implied.length ? ' is-implied' : '')));
          b.type = 'button';
          b.setAttribute('data-slim', s.id);
          b.setAttribute('data-hot-key', 'slim:' + s.id);
          b.setAttribute('aria-pressed', 'false');
          b.style.transitionDelay = (i * 18) + 'ms';
          b.title = s.name + (n ? ': ' + n + ' term' + (n === 1 ? '' : 's') : (s.implied.length ? ': suggested by domains' : ''));
          b.setAttribute('aria-label', b.title);
          hotOn(b, 'slim:' + s.id, function () { return s.terms.concat(s.implied); }, function () { return [s.id]; });
          attachTip(b, function () {
            return '<strong>' + esc(s.name) + '</strong><span class="gf-tip-muted">' + esc(s.id) + ' · plant slim, ' + esc(a.label.toLowerCase()) + '</span>' +
              (n ? '<ul>' + s.terms.map(function (id) { return '<li>' + esc(termById[id] ? termById[id].name : id) + '</li>'; }).join('') + '</ul>' : '') +
              (s.implied.length ? '<div class="gf-tip-muted">suggested by domains: ' + s.implied.map(function (id) { return esc(termById[id] ? termById[id].name : id); }).join(', ') + '</div>' : '') +
              (!n && !s.implied.length ? '<div class="gf-tip-muted">no term of this gene falls here</div>' : '');
          });
          fp.appendChild(b);
        });
        col.appendChild(fp);

        var lit = slims.filter(function (s) { return s.terms.length; });
        var litList = html('ul', 'gf-fp-lit');
        if (lit.length) {
          lit.forEach(function (s) {
            var li = html('li', null, '<span class="gf-fp-dot gf-sq-' + a.cls + '"></span>' + esc(s.name) + (s.terms.length > 1 ? ' <small>×' + s.terms.length + '</small>' : ''));
            li.setAttribute('data-slim', s.id);
            hotOn(li, 'lit:' + s.id, function () { return s.terms; }, function () { return [s.id]; });
            litList.appendChild(li);
          });
        } else {
          litList.appendChild(html('li', 'gf-muted', terms.length ? 'terms sit outside the plant slim' : 'no term in this aspect'));
        }
        col.appendChild(litList);

        /* the terms themselves */
        var list = html('ul', 'gf-terms');
        terms.forEach(function (t) { list.appendChild(termRow(t, a, false)); });
        implied.forEach(function (t) { list.appendChild(termRow(t, a, true)); });
        col.appendChild(list);
        cols.appendChild(col);
      });
      block.appendChild(cols);

      var unplaced = go.terms.filter(function (t) { return !t.aspect; });
      if (unplaced.length) {
        block.appendChild(html('p', 'gf-note', unplaced.length + ' term' + (unplaced.length === 1 ? '' : 's') +
          ' the GO release no longer carries (' + unplaced.map(function (t) { return esc(t.term); }).join(', ') + '): listed in the table below, not placed here.'));
      }

      /* the ancestry graph */
      if (go.graph && go.graph.nodes && go.graph.nodes.length) {
        var graphWrap = html('div', 'gf-graph-wrap');
        var head = html('div', 'gf-graph-head',
          '<h5>Ancestry <small>each term traced to its root through the plant-slim categories it belongs to</small></h5>');
        var toggle = html('button', 'gf-chip', 'Hide ancestry');
        toggle.type = 'button';
        toggle.setAttribute('aria-pressed', 'true');
        head.appendChild(toggle);
        graphWrap.appendChild(head);
        var stage = html('div', 'gf-graph');
        graphWrap.appendChild(stage);
        block.appendChild(graphWrap);
        toggle.addEventListener('click', function () {
          var open = stage.hidden;
          stage.hidden = !open;
          toggle.textContent = open ? 'Hide ancestry' : 'Show ancestry';
          toggle.setAttribute('aria-pressed', open ? 'true' : 'false');
          if (open) { drawGraph(stage, go); }
        });
        container.appendChild(block);
        drawGraph(stage, go);
        if (window.MGDB && MGDB.debounce) {
          window.addEventListener('resize', MGDB.debounce(function () { if (!stage.hidden) { drawGraph(stage, go); } }, 250));
        }
      } else {
        container.appendChild(block);
      }
    }

    function termRow(t, a, implied) {
      var li = html('li', 'gf-term' + (implied ? ' gf-term-implied' : '') + (t.obsolete ? ' gf-term-obsolete' : ''));
      li.setAttribute('data-term', t.term);
      var badges = '';
      if (implied) {
        badges += '<span class="gf-badge gf-badge-implied" title="Not an annotation of this gene: InterPro2GO maps a domain on the protein to this term">domain-implied</span>';
      } else {
        var kinds = {};
        (t.evidence || []).forEach(function (c) { kinds[evidenceKind(c)] = c; });
        var keys = Object.keys(kinds);
        if (!keys.length) { keys = ['unknown']; }
        keys.forEach(function (k) {
          badges += '<span class="gf-badge gf-badge-' + k + '" title="evidence ' + esc(kinds[k] || 'not stated') + '">' + esc(EVIDENCE_LABEL[k]) + '</span>';
        });
        if (t.implied_by && t.implied_by.length) {
          badges += '<span class="gf-badge gf-badge-implied" title="also implied by ' + esc(t.implied_by.map(function (x) { return x.accession; }).join(', ')) + '">◆ domain</span>';
        }
      }
      if (t.obsolete) { badges += '<span class="gf-badge gf-badge-obsolete">retired' + (t.replaced_by ? ' → ' + esc(t.replaced_by) : '') + '</span>'; }
      if (t.merged_into) { badges += '<span class="gf-badge gf-badge-merged" title="The annotation carries the old id; the GO release merged it into this term">merged into ' + esc(t.merged_into) + '</span>'; }
      li.innerHTML = '<span class="gf-term-name">' + esc(t.name || t.db_name || t.term) + '</span>' +
        '<span class="gf-term-meta"><a href="' + esc(t.url) + '" target="_blank" rel="noopener">' + esc(t.term) + '</a>' + badges + '</span>';
      hotOn(li, 'term:' + t.term, function () { return [t.term]; }, function () { return (t.slim_ancestors || []).map(function (s) { return s.id; }); });
      attachTip(li, function () {
        var path = (t.slim_ancestors || []).map(function (s) { return esc(s.name); });
        return '<strong>' + esc(t.name || t.db_name || t.term) + '</strong>' +
          '<span class="gf-tip-muted">' + esc(t.term) + (a ? ' · ' + esc(a.label.toLowerCase()) : '') + (t.depth != null ? ' · depth ' + t.depth : '') + '</span>' +
          (t.definition ? '<p>' + esc(t.definition) + '</p>' : '') +
          (path.length ? '<div class="gf-tip-path">' + path.join(' › ') + '</div>' : '') +
          (implied
            ? '<div class="gf-tip-muted">suggested by ' + (t.implied_by || []).map(function (x) { return esc(x.accession) + ' ' + esc(x.name); }).join('; ') + ' (InterPro2GO)</div>'
            : '<div class="gf-tip-muted">' +
                ((t.evidence || []).length ? 'evidence ' + esc(t.evidence.join(', ')) + ' · ' : '') +
                ((t.sources || []).length ? 'source ' + esc(t.sources.join(', ')) : 'source not stated') +
                ((t.proteins || []).length ? ' · on ' + esc(t.proteins.join(', ')) : '') +
                ((t.comments || []).length ? ' · ' + esc(t.comments.join('; ')) : '') +
              '</div>');
      });
      return li;
    }

    /* ---- the ancestry graph: three columns (one per aspect), layered by
       depth, ordered by barycentre, edges child -> parent ---- */
    function drawGraph(stage, go) {
      stage.innerHTML = '';
      var nodes = go.graph.nodes;
      var edges = go.graph.edges;
      var byNs = {};
      nodes.forEach(function (n) { if (n.namespace) { (byNs[n.namespace] = byNs[n.namespace] || []).push(n); } });
      var colsPresent = ASPECTS.filter(function (a) { return byNs[a.key] && byNs[a.key].length; });
      if (!colsPresent.length) { return; }
      var width = Math.max(320, stage.clientWidth || 900);
      var narrow = width < 700;
      var colW = narrow ? width : Math.floor(width / colsPresent.length);
      var rowH = 44, nodeH = 22, padX = 10, padY = 14;

      var parentsOf = {}, childrenOf = {};
      edges.forEach(function (e) {
        (parentsOf[e[0]] = parentsOf[e[0]] || []).push(e[1]);
        (childrenOf[e[1]] = childrenOf[e[1]] || []).push(e[0]);
      });
      var nodeById = {};
      nodes.forEach(function (n) { nodeById[n.id] = n; });

      var layouts = [];
      var maxRows = 0;
      colsPresent.forEach(function (a) {
        var list = byNs[a.key];
        var maxDepth = 0;
        list.forEach(function (n) { if (n.depth != null && n.depth > maxDepth) { maxDepth = n.depth; } });
        var layers = [];
        for (var d = 0; d <= maxDepth + 1; d++) { layers.push([]); }
        list.forEach(function (n) { layers[n.depth == null ? maxDepth + 1 : n.depth].push(n); });
        if (!layers[maxDepth + 1].length) { layers.pop(); }
        layers.forEach(function (layer) { layer.sort(function (x, y) { return x.name < y.name ? -1 : 1; }); });
        var pos = {};
        function assign() { layers.forEach(function (layer) { layer.forEach(function (n, i) { pos[n.id] = i; }); }); }
        assign();
        function bary(n, rel) {
          var ids = (rel[n.id] || []).filter(function (id) { return pos[id] != null; });
          if (!ids.length) { return pos[n.id]; }
          return ids.reduce(function (s, id) { return s + pos[id]; }, 0) / ids.length;
        }
        for (var pass = 0; pass < 4; pass++) {
          for (var d2 = 1; d2 < layers.length; d2++) {
            layers[d2].sort(function (x, y) { return bary(x, parentsOf) - bary(y, parentsOf) || (x.name < y.name ? -1 : 1); });
            assign();
          }
          for (var d3 = layers.length - 2; d3 >= 0; d3--) {
            layers[d3].sort(function (x, y) { return bary(x, childrenOf) - bary(y, childrenOf) || (x.name < y.name ? -1 : 1); });
            assign();
          }
        }
        layouts.push({ aspect: a, layers: layers });
        if (layers.length > maxRows) { maxRows = layers.length; }
      });

      var height = narrow ? layouts.reduce(function (h, L) { return h + L.layers.length * rowH + padY * 2 + 18; }, 0)
                          : maxRows * rowH + padY * 2 + 18;
      var svg = el('svg', { viewBox: '0 0 ' + width + ' ' + height, width: '100%', height: height, 'class': 'gf-graph-svg', role: 'img',
                            'aria-label': 'Ancestry of the GO terms of this gene' });
      var coords = {};
      var yOffset = 0;
      layouts.forEach(function (L, ci) {
        var x0 = narrow ? 0 : ci * colW;
        var y0 = narrow ? yOffset : 0;
        svg.appendChild(el('text', { x: x0 + padX, y: y0 + 12, 'class': 'gf-graph-title gf-graph-title-' + L.aspect.cls }, L.aspect.label));
        if (!narrow && ci > 0) {
          svg.appendChild(el('line', { x1: x0, x2: x0, y1: 0, y2: height, 'class': 'gf-graph-divider' }));
        }
        L.layers.forEach(function (layer, d) {
          var slot = (colW - padX * 2) / layer.length;
          var w = Math.min(210, Math.max(56, slot - 8));
          layer.forEach(function (n, i) {
            var cx = x0 + padX + slot * (i + 0.5);
            var cy = y0 + 18 + padY + d * rowH + rowH / 2;
            coords[n.id] = { x: cx, y: cy, w: w, h: nodeH };
          });
        });
        yOffset += L.layers.length * rowH + padY * 2 + 18;
      });

      var gEdges = el('g', { 'class': 'gf-graph-edges' });
      edges.forEach(function (e) {
        var c = coords[e[0]], p = coords[e[1]];
        if (!c || !p) { return; }
        var y1 = c.y - c.h / 2, y2 = p.y + p.h / 2;
        var my = (y1 + y2) / 2;
        gEdges.appendChild(el('path', { d: 'M' + c.x + ',' + y1 + ' C' + c.x + ',' + my + ' ' + p.x + ',' + my + ' ' + p.x + ',' + y2,
                                        'class': 'gf-graph-edge' }));
      });
      svg.appendChild(gEdges);

      nodes.forEach(function (n) {
        var c = coords[n.id];
        if (!c) { return; }
        var a = ASPECT_BY_KEY[n.namespace] || ASPECTS[0];
        var g = el('g', { 'class': 'gf-node gf-node-' + n.kind + ' gf-node-' + a.cls, tabindex: 0, role: 'img' });
        if (n.annotated) { g.setAttribute('data-term', n.id); }
        if (n.slim || n.kind === 'root') { g.setAttribute('data-slim', n.id); }
        g.appendChild(el('rect', { x: c.x - c.w / 2, y: c.y - c.h / 2, width: c.w, height: c.h, rx: 11 }));
        var maxChars = Math.max(6, Math.floor((c.w - 12) / 5.9));
        g.appendChild(el('text', { x: c.x, y: c.y + 4, 'text-anchor': 'middle' }, truncate(n.name, maxChars)));
        var title = n.name + ' (' + n.id + ')' + (n.kind === 'root' ? ', root' : n.kind === 'slim' ? ', plant-slim category' : ', annotated term');
        g.appendChild(el('title', {}, title));
        g.setAttribute('aria-label', title);
        var t = termById[n.id];
        hotOn(g, 'node:' + n.id,
          function () { return n.annotated ? [n.id] : (slimById[n.id] ? slimById[n.id].terms.concat(slimById[n.id].implied) : []); },
          function () { return n.annotated && t ? [n.id].concat((t.slim_ancestors || []).map(function (s) { return s.id; })) : [n.id]; });
        attachTip(g, function () {
          return '<strong>' + esc(n.name) + '</strong><span class="gf-tip-muted">' + esc(n.id) + ' · ' +
            (n.kind === 'root' ? 'root of ' + esc(a.label.toLowerCase()) : n.kind === 'slim' ? 'plant-slim category' : 'annotated term') +
            (n.depth != null ? ' · depth ' + n.depth : '') + '</span>' +
            (t && t.definition ? '<p>' + esc(t.definition) + '</p>' : '');
        });
        svg.appendChild(g);
      });
      stage.appendChild(svg);
    }

    /* ==================================================================
       Protein family and class
       ================================================================== */
    if (hasClasses) {
      var cb = html('div', 'gf-block gf-classes');
      cb.appendChild(html('div', 'gf-block-head',
        '<h4>Protein family and class <small>InterPro entries on ' + esc(classes.protein || 'the canonical protein') + ', classes from the domain atlas</small></h4>' +
        '<a class="gf-out" href="' + esc(classes.atlas.page) + '">Domain atlas</a>'));

      /* the architecture as pills, in order along the protein */
      if (classes.architecture) {
        var byName = {};
        classes.entries.forEach(function (e) { byName[e.name] = e; });
        var arch = html('div', 'gf-arch');
        arch.appendChild(html('span', 'gf-arch-label', 'Architecture'));
        var seen = {};
        var palette = 0;
        var colourOf = {};
        classes.architecture.split(' - ').forEach(function (name) {
          var e = byName[name];
          var key = e ? e.accession : name;
          if (colourOf[key] == null) { colourOf[key] = palette++ % 8; }
          var pill = html(e ? 'a' : 'span', 'gf-arch-pill gf-dom-' + colourOf[key], esc(truncate(name, 34)));
          if (e) { pill.href = e.url; pill.target = '_blank'; pill.rel = 'noopener'; }
          pill.title = name + (e ? ' (' + e.accession + (e.gene_count != null ? ', ' + num(e.gene_count) + ' genes in ' + classes.genome_label : '') + ')' : '');
          arch.appendChild(pill);
          seen[key] = true;
        });
        cb.appendChild(arch);
      }

      var cards = html('div', 'gf-cards');
      classes.classes.forEach(function (c) {
        var card = html('div', 'gf-card');
        card.innerHTML = '<div class="gf-card-head"><strong>' + esc(c.name) + '</strong>' +
          (c.group ? '<span class="gf-group">' + esc(c.group) + '</span>' : '') + '</div>' +
          '<div class="gf-card-num">' + (c.genes_here != null ? num(c.genes_here) : '—') + ' <small>genes in ' + esc(classes.genome_label) + '</small></div>';
        card.appendChild(founderStrip(c.founders, 'genes in this class'));
        var m = c.maize;
        card.appendChild(html('div', 'gf-card-note',
          (m ? 'maize mean <strong>' + num(Math.round(m.maize_mean)) + '</strong>, range ' + num(m.maize_min) + '–' + num(m.maize_max) +
               (m.maize_cv != null ? ', CV ' + Number(m.maize_cv).toFixed(2) : '') + ' across the atlas maize genomes. ' : '') +
          (c.entries_here.length
            ? 'This protein carries ' + c.entries_here.map(function (e) { return '<a href="' + esc(e.url) + '" target="_blank" rel="noopener">' + esc(e.accession) + '</a> ' + esc(e.name); }).join(', ') +
              ' of the class’s ' + c.entries_in_class + ' entries.'
            : '')));
        cards.appendChild(card);
      });
      if (classes.immunity) {
        var im = classes.immunity;
        var icard = html('div', 'gf-card gf-card-immunity');
        icard.innerHTML = '<div class="gf-card-head"><strong>' + esc(im.label) + '</strong><span class="gf-group">Immunity call</span></div>' +
          '<div class="gf-card-num">' + (im.genes_here != null ? num(im.genes_here) : '—') + ' <small>' + esc(im.label) + ' genes in ' + esc(classes.genome_label) + '</small></div>';
        icard.appendChild(founderStrip(im.founders, im.label + ' genes'));
        icard.appendChild(html('div', 'gf-card-note',
          (im.subclass ? 'Subclass <strong>' + esc(im.subclass) + '</strong>' + (im.subclass_genes_here != null ? ' (' + num(im.subclass_genes_here) + ' in ' + esc(classes.genome_label) + ')' : '') + '. ' : '') +
          'The immunity call is exclusive: one class per gene, by domain-architecture precedence.'));
        cards.appendChild(icard);
      }
      if (cards.childNodes.length) { cb.appendChild(cards); }
      else if (classes.entries.length) {
        cb.appendChild(html('p', 'gf-note', 'None of the atlas’s 36 curated functional classes claims these entries; the entries themselves are below.'));
      }

      if (classes.entries.length) {
        var ul = html('ul', 'gf-entries');
        classes.entries.forEach(function (e) {
          var impliedGo = (go ? go.terms.concat(go.implied) : []).filter(function (t) {
            return (t.implied_by || []).some(function (x) { return x.accession === e.accession; });
          });
          var st = e.atlas || {};
          var li = html('li', null,
            '<a class="gf-entry-acc" href="' + esc(e.url) + '" target="_blank" rel="noopener">' + esc(e.accession) + '</a> ' +
            '<span class="gf-entry-name">' + esc(e.name) + '</span>' +
            '<span class="gf-entry-meta">' +
              (e.gene_count != null ? '<strong>' + num(e.gene_count) + '</strong> genes in ' + esc(classes.genome_label) : '') +
              (st.status ? ' · <span class="gf-status gf-status-' + esc(st.status) + '">' + esc(st.status) + '</span>' : '') +
              (st.genomes_with_entry != null ? ' in ' + st.genomes_with_entry + ' atlas genomes' : '') +
              (st.mean_per_genome != null ? ' · mean ' + num(Math.round(st.mean_per_genome)) + ' (' + num(st.min) + '–' + num(st.max) + ')' : '') +
              (e.members && e.members.length ? ' · ' + esc(e.members.join(', ')) : '') +
            '</span>' +
            (impliedGo.length ? '<span class="gf-entry-go">implies ' + impliedGo.map(function (t) { return '<span data-term="' + esc(t.term) + '">' + esc(t.name) + '</span>'; }).join(', ') + '</span>' : ''));
          ul.appendChild(li);
        });
        cb.appendChild(ul);
      }
      container.appendChild(cb);
    }

    function founderStrip(founders, what) {
      var wrap = html('div', 'gf-strip');
      if (!founders || !founders.length) { return wrap; }
      var max = founders.reduce(function (m, f) { return f.genes != null && f.genes > m ? f.genes : m; }, 0) || 1;
      var w = 8, gap = 2, h = 26;
      var svg = el('svg', { viewBox: '0 0 ' + (founders.length * (w + gap)) + ' ' + h, 'class': 'gf-strip-svg', role: 'img',
                            'aria-label': what + ' in each NAM founder genome', preserveAspectRatio: 'none' });
      founders.forEach(function (f, i) {
        var bh = f.genes == null ? 0 : Math.max(1, h * f.genes / max);
        var r = el('rect', { x: i * (w + gap), y: h - bh, width: w, height: bh, rx: 1, 'class': 'gf-strip-bar' + (f.here ? ' is-here' : '') });
        r.appendChild(el('title', {}, f.label + ': ' + (f.genes == null ? 'no count' : num(f.genes) + ' ' + what)));
        svg.appendChild(r);
      });
      wrap.appendChild(svg);
      var here = founders.filter(function (f) { return f.here; })[0];
      wrap.appendChild(html('span', 'gf-strip-label', founders.length + ' NAM founder genomes' + (here ? ', <strong>' + esc(here.label) + '</strong> in gold' : '')));
      return wrap;
    }

    /* ==================================================================
       Metabolic pathways
       ================================================================== */
    if (hasPathways) {
      var pb = html('div', 'gf-block gf-pathways');
      var founders = pathways.pathways[0].founders || 26;
      pb.appendChild(html('div', 'gf-block-head',
        '<h4>Metabolic pathways <small>' + pathways.pathways.length + ' pathway' + (pathways.pathways.length === 1 ? '' : 's') +
        ' · E2P2 assignment on ' + esc(pathways.genome_label || pathways.genome) + ', compared across ' + founders + ' NAM founders</small></h4>' +
        '<a class="gf-out" href="' + esc(pathways.explorer) + '">Pathway explorer</a>' +
        '<div class="gf-legend">' +
          '<span><i class="gf-step-key is-this"></i> this gene’s step</span>' +
          '<span><i class="gf-step-key is-filled"></i> another ' + esc(pathways.genome_label || 'B73') + ' gene fills it</span>' +
          '<span><i class="gf-step-key is-empty"></i> no gene in ' + esc(pathways.genome_label || 'B73') + '</span>' +
          '<span><i class="gf-step-key is-bar"></i> founders with a gene</span>' +
        '</div>'));
      var list = html('div', 'gf-pw-list');
      pathways.pathways.forEach(function (p) { list.appendChild(pathwayCard(p, pathways)); });
      pb.appendChild(list);
      if (pathways.counts.truncated) {
        pb.appendChild(html('p', 'gf-note', 'Steps are drawn for the first ' + pathways.counts.files_read + ' pathways; the rest are listed by name. The explorer shows every one.'));
      }
      container.appendChild(pb);
    } else if (pathways && !pathways.pathways.length && pathways.genome) {
      container.appendChild(html('p', 'gf-note', 'The pathway explorer assigns no reaction step to this gene.'));
    }

    function pathwayCard(p, ctx) {
      var card = html('div', 'gf-pw');
      var mine = p.this_gene || [];
      var head = html('div', 'gf-pw-head',
        '<a class="gf-pw-name" href="' + esc(p.url) + '">' + esc(p.name) + '</a>' +
        '<span class="gf-pan gf-pan-' + esc(p.pan) + '" title="pan-genome status across the NAM founders">' + esc(p.pan) + '</span>' +
        (p.variability ? '<span class="gf-var" title="completeness variability across founders">' + esc(p.variability) + ' variability</span>' : '') +
        (p.class_tail ? '<span class="gf-pw-class">' + esc(p.class_tail) + '</span>' : ''));
      card.appendChild(head);

      if (p.steps && p.steps.length) {
        var strip = html('div', 'gf-steps');
        strip.setAttribute('role', 'list');
        p.steps.forEach(function (s, i) {
          if (i > 0) { strip.appendChild(html('span', 'gf-step-arrow', '<span aria-hidden="true">&rarr;</span>')); }
          var state = s.this_gene ? 'is-this' : (s.filled ? 'is-filled' : 'is-empty');
          var b = html('button', 'gf-step ' + state);
          b.type = 'button';
          b.setAttribute('role', 'listitem');
          var label = shortEc(s.ec) || shortReaction(s.reaction);
          var share = ctx.pathways[0].founders ? s.founders_filled / ctx.pathways[0].founders : 0;
          b.innerHTML = '<span class="gf-step-ec">' + esc(label) + '</span>' +
            '<span class="gf-step-enz">' + esc(truncate(s.enzyme || s.common_name || s.reaction, 28)) + '</span>' +
            '<span class="gf-step-bar"><span style="width:' + Math.round(share * 100) + '%"></span></span>';
          var geneNames = (s.genes || []).map(function (g) { return g.symbol || g.gene; });
          var title = (s.enzyme || s.reaction) + (s.ec ? ' (' + s.ec + ')' : '') + ': ' +
            (s.this_gene ? 'this gene’s step; ' : '') + (s.filled ? s.genes_total_here + ' gene' + (s.genes_total_here === 1 ? '' : 's') + ' in ' + ctx.genome_label : 'no gene in ' + ctx.genome_label) +
            '; a gene in ' + s.founders_filled + ' of ' + ctx.pathways[0].founders + ' founders';
          b.title = title;
          b.setAttribute('aria-label', title);
          attachTip(b, function () {
            return '<strong>' + esc(s.enzyme || s.common_name || s.reaction) + '</strong>' +
              '<span class="gf-tip-muted">' + esc(s.reaction) + (s.ec ? ' · ' + esc(s.ec) : '') + (s.occurrence ? ' · ' + esc(s.occurrence) + ' step' : '') + '</span>' +
              (s.equation ? '<p class="gf-tip-eq">' + esc(decodeEntities(s.equation)) + '</p>' : '') +
              '<div>' + (s.this_gene ? '<strong>This gene fills this step.</strong> ' : '') +
                (s.filled ? esc(ctx.genome_label) + ' genes: ' + esc(geneNames.join(', ')) + (s.genes_total_here > geneNames.length ? ' +' + (s.genes_total_here - geneNames.length) + ' more' : '')
                          : 'No ' + esc(ctx.genome_label) + ' gene is assigned here' + (s.gap_class ? ' (' + esc(s.gap_class) + ')' : '') + '.') + '</div>' +
              '<div class="gf-tip-muted">a gene in ' + s.founders_filled + ' of ' + ctx.pathways[0].founders + ' NAM founders</div>';
          });
          strip.appendChild(b);
        });
        card.appendChild(strip);
      } else {
        card.appendChild(html('p', 'gf-note', 'Steps not drawn: ' + mine.map(function (m) { return esc(shortReaction(m.reaction)); }).join(', ') + '.'));
      }

      var foot = html('div', 'gf-pw-foot');
      if (p.presence) {
        var dots = html('span', 'gf-presence');
        dots.title = 'pathway present in ' + p.present_in + ' of ' + p.founders + ' NAM founders';
        p.presence.forEach(function (g) {
          dots.appendChild(html('i', 'gf-presence-dot' + (g.present ? ' is-on' : ''), ''));
        });
        foot.appendChild(dots);
        foot.appendChild(html('span', null, '<strong>' + p.present_in + '</strong> of ' + p.founders + ' founders have the pathway'));
      }
      if (p.genome && p.genome.steps_filled != null) {
        foot.appendChild(html('span', null, esc(ctx.genome_label) + ': <strong>' + p.genome.steps_filled + '</strong> of ' + (p.reactions != null ? p.reactions : (p.steps ? p.steps.length : '?')) +
          ' steps have a gene' + (p.genome.genes != null ? ' (' + p.genome.genes + ' genes)' : '')));
      }
      if (mine.length) {
        foot.appendChild(html('span', 'gf-muted', 'evidence ' + esc(mine.map(function (m) { return m.evidence || 'unstated'; }).filter(function (v, i, arr) { return arr.indexOf(v) === i; }).join(', '))));
      }
      var links = [];
      if (p.links && p.links.metacyc) { links.push('<a href="' + esc(p.links.metacyc) + '" target="_blank" rel="noopener">MetaCyc</a>'); }
      if (p.links && p.links.plantcyc) { links.push('<a href="' + esc(p.links.plantcyc) + '" target="_blank" rel="noopener">PlantCyc</a>'); }
      if (links.length) { foot.appendChild(html('span', 'gf-pw-links', links.join(' · '))); }
      card.appendChild(foot);
      return card;
    }

    /* ---- footer ---- */
    var footer = html('div', 'gf-footer');
    var notes = [];
    if (go) { notes = notes.concat(go.notes || []); }
    if (classes && classes.atlas && classes.atlas.counting_unit) {
      notes.push('Class and entry counts are the domain atlas’s reference arm (' + classes.atlas.counting_unit + ').');
    }
    if (pathways) { notes.push(pathways.source + ' A step is "filled" when the explorer assigns any gene of the genome to its reaction.'); }
    footer.appendChild(html('p', null, esc(notes.join(' '))));
    var links = html('div', 'gf-footer-links');
    links.innerHTML = '<a class="mgdb-rec-tsv" href="' + esc((spec.base || '') + '/api/v1/records/gene/' + encodeURIComponent(gene.name || '') + '?fields=function') + '" target="_blank" rel="noopener">JSON</a>';
    footer.appendChild(links);
    container.appendChild(footer);

    /* light the fingerprint up once it is on screen (a timer as well as a
       frame: a background tab paints no frames until it is shown) */
    function ready() { container.classList.add('is-ready'); }
    window.requestAnimationFrame(ready);
    window.setTimeout(ready, 60);
    return true;
  };
}(window, document));
