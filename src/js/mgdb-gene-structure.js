/* file: mgdb-gene-structure.js
 *
 * purpose: the gene model and protein figure on the gene record page.
 *
 *          MGDB.geneStructure(container, spec) draws, from the gene-models
 *          and domains datasets of the API, one SVG with three bands:
 *
 *            genome    every transcript of the gene against the genome, with
 *                      UTRs thin, CDS thick and introns as lines carrying the
 *                      strand; the selected transcript highlighted
 *            connectors  one shape per CDS block of the selected transcript,
 *                      from its place on the genome down to the residues it
 *                      encodes, so an exon can be read straight onto the
 *                      protein (and, on the minus strand, the crossing shows
 *                      the reversal)
 *            protein   the protein to scale, its InterPro entries as coloured
 *                      blocks, residue-level sites as markers, and each
 *                      domain also painted back onto the CDS blocks that
 *                      encode it
 *
 *          plus an optional 3D panel: the AlphaFold model of the protein,
 *          cartoon coloured by the same domains or by confidence, loaded from
 *          the site's own AlphaFill payload or from AlphaFold DB only when
 *          the reader asks, because the viewer library is half a megabyte.
 *
 *          spec = {
 *            gene:      { name, symbol, chromosome, strand, start, end }
 *            geneModel: structure.gene_model  (genome, release, transcripts[], links)
 *            domains:   structure.domains     (the canonical protein's payload) or null
 *            proteinDomains: structure.protein_domains (the database's Pfam rows,
 *                       drawn when the genome has no domains release)
 *            model:     structure.model       ({source, pdb, plddt, ...}) or null
 *            base:      the API base URL for fetching another isoform's domains
 *          }
 *
 *          Choosing another transcript fetches that protein's domains from
 *          /api/v1/data/domains/{genome}/{protein} — one request, and the
 *          first one the page makes after load. Everything else is drawn from
 *          what the record already carried.
 *
 *          Nothing here reads the DOM at module scope: includeScript() emits
 *          into <head>, so the function is only called by the record script
 *          once the section exists.
 *
 * history:
 *  09/12/26  claude  created
 */
(function (window, document) {
  'use strict';

  var MGDB = window.MGDB = window.MGDB || {};
  var SVG_NS = 'http://www.w3.org/2000/svg';
  var LEFT = 132;        // label gutter
  var RIGHT = 28;
  var ROW_H = 30;
  var ROW_SEL_H = 40;
  var BAND_GAP = 18;
  var CONNECTOR_H = 76;
  var PROTEIN_H = 24;
  var SITE_LANE = 26;
  var DOMAIN_CLASSES = 8;

  function esc(value) {
    return MGDB.escapeHtml ? MGDB.escapeHtml(value == null ? '' : String(value))
         : String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
             return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
           });
  }
  function fmt(n) { return Number(n || 0).toLocaleString(); }
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

  var ORIENTATION_KEY = 'mgdb-gene-structure-orientation';
  function readOrientation() {
    try {
      var v = window.localStorage.getItem(ORIENTATION_KEY);
      return v === 'transcript' ? 'transcript' : 'genome';
    } catch (e) { return 'genome'; }
  }
  function writeOrientation(v) {
    try { window.localStorage.setItem(ORIENTATION_KEY, v); } catch (e) { /* private mode */ }
  }

  /* Nice tick spacing for a span in base pairs or residues. */
  function tickStep(span, targetTicks) {
    var raw = span / targetTicks;
    var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
    var candidates = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < candidates.length; i++) {
      if (candidates[i] * mag >= raw) { return candidates[i] * mag; }
    }
    return 10 * mag;
  }
  /* Tick labels carry as many decimals as the tick spacing needs: a 4 kb
     gene on chromosome 2 ticks every 1 kb, so "4.5 Mb" three times over is
     no label at all, while a 200 kb window can say 4.40 Mb, 4.45 Mb. Below
     100 kb spacing the full coordinate with separators reads best. */
  function bpLabel(v, step) {
    if (step >= 1e5) {
      var decimals = Math.max(0, Math.ceil(-Math.log(step / 1e6) / Math.LN10));
      return (v / 1e6).toFixed(Math.min(3, decimals)) + ' Mb';
    }
    return fmt(v);
  }

  /* ------------------------------------------------------------------------
     Geometry: residues <-> CDS nucleotides <-> genome, through the CDS
     blocks in transcript order. Residue r covers CDS nucleotides 3(r-1)+1
     to 3r. The same arithmetic the domains builder uses for its projection,
     kept here so the connectors and the domain overlays agree with the API.
     ------------------------------------------------------------------------ */

  function cdsSegments(transcript) {
    var minus = transcript.strand === '-';
    var offset = 0;
    return (transcript.cds || []).map(function (block) {
      var length = block.end - block.start + 1;
      var seg = { start: block.start, end: block.end, rank: block.rank, phase: block.phase,
                  ntStart: offset + 1, ntEnd: offset + length,
                  resStart: Math.floor(offset / 3) + 1, resEnd: Math.ceil((offset + length) / 3),
                  minus: minus };
      offset += length;
      return seg;
    });
  }

  function projectResidues(segments, resStart, resEnd) {
    var ntStart = 3 * (resStart - 1) + 1;
    var ntEnd = 3 * resEnd;
    var blocks = [];
    segments.forEach(function (seg) {
      var lo = Math.max(ntStart, seg.ntStart);
      var hi = Math.min(ntEnd, seg.ntEnd);
      if (lo > hi) { return; }
      if (seg.minus) {
        blocks.push({ start: seg.end - (hi - seg.ntStart), end: seg.end - (lo - seg.ntStart) });
      } else {
        blocks.push({ start: seg.start + (lo - seg.ntStart), end: seg.start + (hi - seg.ntStart) });
      }
    });
    return blocks;
  }

  /* ------------------------------------------------------------------------
     The figure
     ------------------------------------------------------------------------ */

  function geneStructure(container, spec) {
    if (!container || !spec || !spec.geneModel || !spec.geneModel.transcripts || !spec.geneModel.transcripts.length) {
      return false;
    }
    var state = {
      spec: spec,
      transcripts: spec.geneModel.transcripts.slice(),
      selected: null,
      domainsByProtein: {},
      showAll: true,
      hot: null,
      viewer: null,
      viewerModel: null,
      /* The 3D panel opens with the section. It was behind a click because the
         viewer library is half a megabyte; the library is still only fetched
         when a model exists to show, and for a gene with no model the panel --
         and this flag -- never come into play. */
      modelOpen: true,
      colourBy: 'domains',
      /* 'genome': left to right along the chromosome, so a minus-strand
         transcript reads right to left and the connectors cross on their way
         to the protein. 'transcript': the genome band is mirrored for a
         minus-strand gene so the transcript and the protein both read left
         to right and the connectors run straight. A per-browser preference;
         it never reaches the server. */
      orientation: readOrientation()
    };
    state.transcripts.sort(function (a, b) {
      if (!!a.canonical !== !!b.canonical) { return a.canonical ? -1 : 1; }
      return a.id < b.id ? -1 : (a.id > b.id ? 1 : 0);
    });
    state.selected = state.transcripts[0];
    if (spec.domains && spec.domains.id) { state.domainsByProtein[spec.domains.id] = spec.domains; }
    /* A genome with no domains release (links.domains null: every one but
       B73 NAM-5.0) still has the database's Pfam matches for each transcript
       (structure.protein_domains). They are drawn as they are and called Pfam
       domains from the database, not InterPro entries. A gene with no rows at
       all says so; rows that name none of a transcript's IDs say nothing, since
       that may be a naming difference rather than an absence. */
    var releaseLinks = spec.geneModel && spec.geneModel.links;
    if (releaseLinks && releaseLinks.domains === null && Array.isArray(spec.proteinDomains)) {
      state.transcripts.forEach(function (t) {
        var pid = t.protein ? t.protein.id : null;
        if (!pid || state.domainsByProtein[pid]) { return; }
        var rows = spec.proteinDomains.filter(function (r) { return r.transcript === t.id && r.start && r.end; });
        if (!rows.length && spec.proteinDomains.length) { return; }
        state.domainsByProtein[pid] = {
          id: pid, source: 'database', matches: [], sites: [], genomic: null, no_matches: !rows.length,
          entries: rows.map(function (r) {
            return { accession: r.accession, name: r.name || r.accession, start: r.start, end: r.end, url: r.url || null, members: [] };
          }).sort(function (a, b) { return a.start - b.start || a.end - b.end; })
        };
      });
    }

    container.innerHTML = '';
    container.classList.add('gs');

    var toolbar = html('div', 'gs-toolbar');
    var stage = html('div', 'gs-stage');
    var tip = html('div', 'gs-tip');
    tip.hidden = true;
    var summary = html('ul', 'gs-summary');
    var legend = html('ul', 'gs-legend');
    var modelPanel = html('div', 'gs-model');
    container.appendChild(toolbar);
    container.appendChild(stage);
    container.appendChild(tip);
    container.appendChild(summary);
    container.appendChild(legend);
    container.appendChild(modelPanel);

    function currentDomains() {
      var t = state.selected;
      var pid = t && t.protein ? t.protein.id : null;
      return pid ? state.domainsByProtein[pid] || null : null;
    }

    function fetchDomains(protein) {
      if (!protein || state.domainsByProtein[protein]) { return Promise.resolve(); }
      /* links.domains is null when the genome has no domains release (every
         one but B73 NAM-5.0): there is nothing to ask for, and nothing failed. */
      var gl = spec.geneModel && spec.geneModel.links;
      if (gl && gl.domains === null) { return Promise.resolve(); }
      var url = (spec.base || '') + '/api/v1/data/domains/' + encodeURIComponent(spec.geneModel.genome) + '/' + encodeURIComponent(protein);
      stage.classList.add('is-busy');
      var req = MGDB.request ? MGDB.request(url, { key: 'gs-domains' })
              : window.fetch(url).then(function (r) { return r.json(); });
      return req.then(function (response) {
        var d = response && response.data ? response.data : null;
        state.domainsByProtein[protein] = d ? {
          id: d.id, length_aa: d.attributes.length_aa, architecture: d.attributes.architecture,
          entries: d.sections.entries || [], matches: d.sections.matches || [],
          sites: d.sections.sites || [], genomic: d.sections.genomic || null,
          no_matches: !!d.attributes.no_matches
        } : { id: protein, entries: [], matches: [], sites: [], genomic: null, no_matches: true };
      }).catch(function () {
        state.domainsByProtein[protein] = { id: protein, entries: [], matches: [], sites: [], genomic: null, unavailable: true };
      }).then(function () { stage.classList.remove('is-busy'); });
    }

    /* ---- toolbar ---- */
    function renderToolbar() {
      toolbar.innerHTML = '';
      var label = html('span', 'gs-toolbar-label', 'Transcript');
      toolbar.appendChild(label);
      var chips = html('div', 'gs-chips');
      state.transcripts.forEach(function (t) {
        var chip = html('button', 'gs-chip' + (t.canonical ? ' gs-chip-canonical' : ''), esc(t.id));
        chip.type = 'button';
        chip.setAttribute('aria-pressed', t === state.selected ? 'true' : 'false');
        chip.title = t.canonical ? 'Canonical transcript' : 'Isoform';
        chip.addEventListener('click', function () { select(t); });
        chips.appendChild(chip);
      });
      toolbar.appendChild(chips);
      var strand = html('span', 'gs-strand',
        (spec.gene.strand === '-' ? '&larr; minus strand' : spec.gene.strand === '+' ? 'plus strand &rarr;' : 'strand unknown')
        + ' &middot; ' + esc(spec.gene.chromosome) + ':' + fmt(spec.gene.start) + '&ndash;' + fmt(spec.gene.end));
      toolbar.appendChild(strand);
      if (spec.gene.strand === '-') {
        var orient = html('div', 'gs-chips gs-orientation');
        orient.setAttribute('role', 'group');
        orient.setAttribute('aria-label', 'Reading direction');
        var olabel = html('span', 'gs-toolbar-label', 'Read');
        toolbar.appendChild(olabel);
        [['genome', 'Genome, left to right', 'The chromosome as published: this transcript reads right to left, and the connectors cross on their way to the protein.'],
         ['transcript', 'Transcript, 5\u2032 to 3\u2032', 'The genome band mirrored, so the transcript and the protein both read left to right and the connectors run straight.']
        ].forEach(function (opt) {
          var chip = html('button', 'gs-chip', esc(opt[1]));
          chip.type = 'button';
          chip.title = opt[2];
          chip.setAttribute('aria-pressed', state.orientation === opt[0] ? 'true' : 'false');
          chip.addEventListener('click', function () {
            if (state.orientation === opt[0]) { return; }
            state.orientation = opt[0];
            writeOrientation(opt[0]);
            renderToolbar();
            draw();
          });
          orient.appendChild(chip);
        });
        toolbar.appendChild(orient);
      }
      var links = html('div', 'gs-toolbar-links');
      var L = spec.geneModel.links || {};
      var parts = [];
      if (L.gff3) { parts.push('<a href="' + esc(L.gff3) + '">GFF3</a>'); }
      if (L.bed) { parts.push('<a href="' + esc(L.bed) + '">BED</a>'); }
      if (L.api) { parts.push('<a href="' + esc(L.api) + '">JSON</a>'); }
      if (L.browser) { parts.push('<a href="' + esc(L.browser) + '" target="_blank" rel="noopener">JBrowse</a>'); }
      links.innerHTML = parts.join(' <span class="gs-muted">&middot;</span> ');
      toolbar.appendChild(links);
    }

    function select(t) {
      if (t === state.selected) { return; }
      state.selected = t;
      state.hot = null;
      renderToolbar();
      var pid = t.protein ? t.protein.id : null;
      fetchDomains(pid).then(function () { draw(); renderModelPanel(); });
    }

    /* ---- drawing ---- */
    function draw() {
      var width = Math.max(640, stage.clientWidth || 800);
      var innerW = width - LEFT - RIGHT;
      var gene = spec.gene;
      var gStart = Math.min.apply(null, state.transcripts.map(function (t) { return t.start; }).concat([gene.start]));
      var gEnd = Math.max.apply(null, state.transcripts.map(function (t) { return t.end; }).concat([gene.end]));
      var pad = Math.max(50, Math.round((gEnd - gStart) * 0.02));
      var x0 = gStart - pad, x1 = gEnd + pad;
      var flipped = state.orientation === 'transcript' && gene.strand === '-';
      var gx = flipped
        ? function (bp) { return LEFT + ((x1 - bp) / (x1 - x0)) * innerW; }
        : function (bp) { return LEFT + ((bp - x0) / (x1 - x0)) * innerW; };
      /* Block rectangles take their left edge from whichever end is now on the
         left, so they keep a positive width when the scale is mirrored. */
      var rx = function (a, b) { return Math.min(gx(a), gx(b)); };
      var rw = function (a, b) { return Math.max(1.5, Math.abs(gx(b) - gx(a))); };

      var visible = state.showAll ? state.transcripts : [state.selected];
      var rowsH = visible.reduce(function (h, t) { return h + (t === state.selected ? ROW_SEL_H : ROW_H); }, 0);
      var yRuler = 26;
      var yRows = yRuler + 22;
      var ySelTop = null;
      var yConnTop = yRows + rowsH + 6;
      var yProteinTitle = yConnTop + CONNECTOR_H + 4;
      var yProtein = yProteinTitle + SITE_LANE + 4;
      var yPRuler = yProtein + PROTEIN_H + 8;
      var height = yPRuler + 28;

      var domains = currentDomains();
      var protein = state.selected.protein;
      var coding = (state.selected.cds || []).length > 0;
      /* A release that publishes no protein lengths (B73 RefGen_v1 to v3 have
         no protein file) leaves length_aa null. The protein is then drawn at
         the CDS's complete codons -- within one residue, as the CDS may carry
         its stop codon -- and labelled as read from the CDS. */
      var derivedLength = (coding && protein && !protein.length_aa && state.selected.cds_length_nt)
                        ? Math.floor(state.selected.cds_length_nt / 3) : null;
      var length = protein ? (protein.length_aa || derivedLength) : null;
      var segments = cdsSegments(state.selected);
      var px = function (res) { return LEFT + ((res - 0.5) / length) * innerW; };
      var entries = (domains && domains.entries) ? domains.entries.slice(0, 64) : [];
      var domainClass = function (i) { return 'gs-dom-' + (i % DOMAIN_CLASSES); };

      var svg = el('svg', { viewBox: '0 0 ' + width + ' ' + height, width: width, height: height, role: 'img' });
      svg.setAttribute('aria-label', 'Gene model of ' + gene.name + ' with ' + state.transcripts.length + ' transcript' +
        (state.transcripts.length === 1 ? '' : 's') + ' on the ' + (gene.strand === '-' ? 'minus' : 'plus') + ' strand, and its protein ' +
        (protein ? protein.id + ' of ' + (derivedLength ? 'about ' : '') + length + ' residues with ' + entries.length +
          (domains && domains.source === 'database' ? ' Pfam domain' + (entries.length === 1 ? '' : 's')
                                                    : ' InterPro entr' + (entries.length === 1 ? 'y' : 'ies')) : 'without a protein'));

      /* genome ruler */
      var axis = el('g', { 'class': 'gs-axis' });
      axis.appendChild(el('text', { x: LEFT, y: 14, 'class': 'gs-band-title' },
        'Genome ' + gene.chromosome + (flipped ? ' \u00b7 mirrored, so the transcript reads 5\u2032 to 3\u2032 left to right' : '')));
      axis.appendChild(el('line', { x1: LEFT, x2: LEFT + innerW, y1: yRuler + 8, y2: yRuler + 8, 'class': 'gs-axis-base' }));
      var step = tickStep(x1 - x0, Math.max(4, Math.floor(innerW / 110)));
      for (var v = Math.ceil(x0 / step) * step; v <= x1; v += step) {
        axis.appendChild(el('line', { x1: gx(v), x2: gx(v), y1: yRuler + 4, y2: yRuler + 12 }));
        axis.appendChild(el('text', { x: gx(v), y: yRuler - 2, 'text-anchor': 'middle' }, bpLabel(v, step)));
      }
      svg.appendChild(axis);

      /* transcript rows */
      var y = yRows;
      var domainBlocksOnGenome = [];   // filled when drawing the selected row
      visible.forEach(function (t) {
        var isSel = t === state.selected;
        var h = isSel ? ROW_SEL_H : ROW_H;
        var mid = y + h / 2;
        var row = el('g', { 'class': 'gs-row' + (isSel ? ' is-selected' : '') });
        row.appendChild(el('rect', { x: 0, y: y, width: width, height: h, 'class': 'gs-row-bg' }));
        var label = el('text', { x: 10, y: mid + 4, 'class': 'gs-row-label' + (isSel ? ' is-selected' : ''), role: 'button', tabindex: 0 },
          t.id.replace(gene.name + '_', ''));
        label.appendChild(el('title', {}, t.id + (t.canonical ? ' (canonical)' : '') + ' — click to select'));
        label.addEventListener('click', function () { select(t); });
        label.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(t); } });
        row.appendChild(label);
        if (t.canonical) { row.appendChild(el('text', { x: 10, y: mid + 15, 'class': 'gs-row-note' }, 'canonical')); }

        // intron line with strand chevrons
        row.appendChild(el('line', { x1: rx(t.start, t.end), x2: rx(t.start, t.end) + rw(t.start, t.end), y1: mid, y2: mid, 'class': 'gs-intron' }));
        var exonsSorted = (t.exons || []).slice().sort(function (a, b) { return a.start - b.start; });
        var pointsRight = (t.strand !== '-') !== flipped;
        for (var i = 0; i + 1 < exonsSorted.length; i++) {
          var ia = Math.min(gx(exonsSorted[i].end), gx(exonsSorted[i + 1].start));
          var ib = Math.max(gx(exonsSorted[i].end), gx(exonsSorted[i + 1].start));
          if (ib - ia < 14) { continue; }
          var n = Math.max(1, Math.floor((ib - ia) / 70));
          for (var k = 1; k <= n; k++) {
            var cx = ia + (ib - ia) * k / (n + 1);
            var d = pointsRight ? ('M' + (cx - 3) + ',' + (mid - 3) + ' L' + (cx + 3) + ',' + mid + ' L' + (cx - 3) + ',' + (mid + 3))
                                : ('M' + (cx + 3) + ',' + (mid - 3) + ' L' + (cx - 3) + ',' + mid + ' L' + (cx + 3) + ',' + (mid + 3));
            row.appendChild(el('path', { d: d, 'class': 'gs-chevron' }));
          }
        }
        // UTR blocks (thin) then CDS blocks (thick); exons that are UTR-only draw as thin
        var utrH = isSel ? 10 : 8, cdsH = isSel ? 22 : 16;
        (t.five_prime_utr || []).concat(t.three_prime_utr || []).forEach(function (u) {
          row.appendChild(el('rect', { x: rx(u.start, u.end), y: mid - utrH / 2, width: rw(u.start, u.end), height: utrH, 'class': 'gs-exon-utr' }));
        });
        if (!(t.cds || []).length) {
          exonsSorted.forEach(function (e) {
            row.appendChild(el('rect', { x: rx(e.start, e.end), y: mid - utrH / 2, width: rw(e.start, e.end), height: utrH, 'class': 'gs-exon-utr' }));
          });
        }
        (t.exons || []).forEach(function (e) {
          var g = el('g', { 'class': 'gs-exon', tabindex: 0, role: 'img' });
          var ex = rx(e.start, e.end), ew = rw(e.start, e.end);
          // the exon's coding part
          (t.cds || []).forEach(function (c) {
            if (c.end < e.start || c.start > e.end) { return; }
            var cs = Math.max(c.start, e.start), ce = Math.min(c.end, e.end);
            g.appendChild(el('rect', { x: rx(cs, ce), y: mid - cdsH / 2, width: rw(cs, ce), height: cdsH, 'class': 'gs-exon-cds', rx: 1.5 }));
          });
          // an invisible hit area over the whole exon so thin UTR exons are hoverable
          g.appendChild(el('rect', { x: ex, y: mid - cdsH / 2, width: ew, height: cdsH, fill: 'transparent' }));
          if (isSel && ew > 18) {
            g.appendChild(el('text', { x: ex + ew / 2, y: mid + 3.5, 'text-anchor': 'middle', 'class': 'gs-exon-rank' }, String(e.rank)));
          }
          var cdsIn = (t.cds || []).filter(function (c) { return !(c.end < e.start || c.start > e.end); });
          var seg = segments.filter(function (s) { return cdsIn.some(function (c) { return c.start === s.start && c.end === s.end; }); });
          var title = 'Exon ' + e.rank + ' of ' + t.id + ': ' + gene.chromosome + ':' + fmt(e.start) + '-' + fmt(e.end) + ' (' + fmt(e.end - e.start + 1) + ' bp)' +
            (seg.length ? '; encodes residues ' + seg[0].resStart + '-' + seg[seg.length - 1].resEnd : '; untranslated');
          g.appendChild(el('title', {}, title));
          g.setAttribute('aria-label', title);
          attachTip(g, function () {
            return '<strong>Exon ' + e.rank + '</strong><span class="gs-tip-mono">' + esc(gene.chromosome) + ':' + fmt(e.start) + '&ndash;' + fmt(e.end) + '</span> &middot; ' + fmt(e.end - e.start + 1) + ' bp' +
              (seg.length ? '<br>CDS block' + (seg.length > 1 ? 's' : '') + ' ' + seg.map(function (s) { return s.rank; }).join(', ') + ' &rarr; residues ' + seg[0].resStart + '&ndash;' + seg[seg.length - 1].resEnd +
                (seg[0].phase != null ? ' <span class="gs-tip-muted">(phase ' + seg[0].phase + ')</span>' : '') : '<br><span class="gs-tip-muted">untranslated</span>');
          });
          g.addEventListener('mouseenter', function () { setHot('cds', seg.map(function (s) { return s.rank; })); });
          g.addEventListener('mouseleave', function () { setHot(null); });
          row.appendChild(g);
        });
        svg.appendChild(row);
        if (isSel) { ySelTop = y; }
        y += h;
      });

      var selMid = ySelTop + ROW_SEL_H / 2;
      var selCdsH = 22;

      /* connectors and the protein band, only when there is a protein */
      if (protein && length) {
        var yTop = selMid + selCdsH / 2;
        var yBottom = yProtein;
        var conn = el('g', { 'class': 'gs-connectors' });
        segments.forEach(function (s) {
          var gxa = Math.min(gx(s.start), gx(s.end)), gxb = Math.max(gx(s.start), gx(s.end));
          /* The residue span attaches to the genome block's 5' end first, so
             when the block faces the other way the two edges swap. */
          if ((s.minus !== flipped)) { var tmp = gxa; gxa = gxb; gxb = tmp; }
          var pa = px(s.resStart) - (innerW / length) * 0.5, pb = px(s.resEnd) + (innerW / length) * 0.5;
          var c1 = yTop + (yBottom - yTop) * 0.45, c2 = yTop + (yBottom - yTop) * 0.55;
          var d = 'M' + gxa + ',' + yTop + ' C' + gxa + ',' + c1 + ' ' + pa + ',' + c2 + ' ' + pa + ',' + yBottom +
                  ' L' + pb + ',' + yBottom + ' C' + pb + ',' + c2 + ' ' + gxb + ',' + c1 + ' ' + gxb + ',' + yTop + ' Z';
          var path = el('path', { d: d, 'class': 'gs-connector', 'data-rank': s.rank });
          path.appendChild(el('title', {}, 'CDS block ' + s.rank + ': ' + fmt(s.start) + '-' + fmt(s.end) + ' encodes residues ' + s.resStart + '-' + s.resEnd));
          conn.appendChild(path);
        });
        svg.appendChild(conn);

        // protein band
        var pg = el('g', { 'class': 'gs-protein' });
        pg.appendChild(el('text', { x: LEFT, y: yProteinTitle + 10, 'class': 'gs-band-title' }, 'Protein ' + protein.id + ' · ' +
          (derivedLength ? 'about ' + fmt(length) + ' aa, from the CDS' : fmt(length) + ' aa')));
        pg.appendChild(el('rect', { x: LEFT, y: yProtein, width: innerW, height: PROTEIN_H, rx: 4, 'class': 'gs-protein-bar' }));
        var paxis = el('g', { 'class': 'gs-axis' });
        paxis.appendChild(el('line', { x1: LEFT, x2: LEFT + innerW, y1: yPRuler, y2: yPRuler, 'class': 'gs-axis-base' }));
        var pstep = tickStep(length, Math.max(4, Math.floor(innerW / 90)));
        for (var r = 0; r <= length; r += pstep) {
          var rr = r === 0 ? 1 : r;
          paxis.appendChild(el('line', { x1: px(rr), x2: px(rr), y1: yPRuler, y2: yPRuler + 6 }));
          paxis.appendChild(el('text', { x: px(rr), y: yPRuler + 19, 'text-anchor': 'middle' }, String(rr)));
        }
        paxis.appendChild(el('line', { x1: px(length), x2: px(length), y1: yPRuler, y2: yPRuler + 6 }));
        pg.appendChild(paxis);

        // entries on the bar, and their projection back onto the CDS
        entries.forEach(function (entry, i) {
          var xa = px(entry.start), xb = px(entry.end);
          var g = el('g', { 'class': 'gs-domain', tabindex: 0, role: 'img' });
          g.appendChild(el('rect', { x: xa, y: yProtein + 2, width: Math.max(3, xb - xa), height: PROTEIN_H - 4, rx: 4, 'class': domainClass(i) }));
          var labelText = entry.name || entry.accession;
          var wide = (xb - xa) > (labelText.length * 6.4 + 10);
          if (wide) {
            g.appendChild(el('text', { x: (xa + xb) / 2, y: yProtein + PROTEIN_H / 2 + 4, 'text-anchor': 'middle' }, labelText));
          }
          var members = (entry.members || []).map(function (m) { return m.analysis + ' ' + m.accession; }).join(', ');
          var title = (entry.name || entry.accession) + ' (' + entry.accession + '), residues ' + entry.start + '-' + entry.end + (members ? '; ' + members : '');
          g.appendChild(el('title', {}, title));
          g.setAttribute('aria-label', title);
          var blocks = projectResidues(segments, entry.start, entry.end);
          attachTip(g, function () {
            return '<strong>' + esc(entry.name || entry.accession) + '</strong>' +
              '<span class="gs-tip-mono">' + esc(entry.accession) + '</span> &middot; residues ' + entry.start + '&ndash;' + entry.end + ' (' + (entry.end - entry.start + 1) + ' aa)' +
              (members ? '<br><span class="gs-tip-muted">' + esc(members) + '</span>' : '') +
              (blocks.length ? '<br>on the genome: ' + blocks.map(function (b) { return fmt(b.start) + '&ndash;' + fmt(b.end); }).join(', ') : '');
          });
          g.addEventListener('mouseenter', function () { setHot('domain', i); });
          g.addEventListener('mouseleave', function () { setHot(null); });
          g.addEventListener('click', function () { focusModelOn(entry); });
          g.addEventListener('keydown', function (e) { if (e.key === 'Enter') { focusModelOn(entry); } });
          pg.appendChild(g);
          blocks.forEach(function (b) {
            domainBlocksOnGenome.push(el('rect', { x: rx(b.start, b.end), y: selMid - selCdsH / 2, width: rw(b.start, b.end), height: selCdsH, rx: 1.5,
                                                   'class': 'gs-dom-genomic ' + domainClass(i) }));
          });
        });

        // sites: residue-level annotations as markers above the bar
        var sites = (domains && domains.sites) ? domains.sites : [];
        if (sites.length) {
          pg.appendChild(el('text', { x: LEFT, y: yProteinTitle + SITE_LANE - 2, 'class': 'gs-site-lane-label' }, 'sites'));
          var seenSite = {};
          sites.forEach(function (s) {
            var key = s.start + ':' + s.end + ':' + (s.description || '');
            if (seenSite[key]) { return; }
            seenSite[key] = true;
            var sx = (px(s.start) + px(s.end)) / 2;
            var yb = yProtein - 3, yt = yb - 9;
            var m = el('path', { d: 'M' + (sx - 5) + ',' + yt + ' L' + (sx + 5) + ',' + yt + ' L' + sx + ',' + yb + ' Z', 'class': 'gs-site', tabindex: 0, role: 'img' });
            var title = (s.description || s.accession) + ' at ' + (s.residue ? s.residue : '') + s.start + (s.end !== s.start ? '-' + s.end : '') + ' (' + s.analysis + ' ' + s.accession + ')';
            m.appendChild(el('title', {}, title));
            m.setAttribute('aria-label', title);
            attachTip(m, function () {
              return '<strong>' + esc(s.description || s.accession) + '</strong>residue ' + (s.residue ? '<span class="gs-tip-mono">' + esc(s.residue) + '</span>' : '') + s.start +
                (s.end !== s.start ? '&ndash;' + s.end : '') + '<br><span class="gs-tip-muted">' + esc(s.analysis + ' ' + s.accession) + '</span>';
            });
            pg.appendChild(m);
          });
        }
        svg.appendChild(pg);
        domainBlocksOnGenome.forEach(function (rect) { svg.appendChild(rect); });
      } else {
        var note = el('text', { x: LEFT, y: yProteinTitle + 10, 'class': 'gs-band-title' },
          coding ? 'Coding transcript; the release names no protein for it' : 'Non-coding transcript: no protein');
        svg.appendChild(note);
      }

      stage.innerHTML = '';
      stage.appendChild(svg);
      renderSummary(segments, entries, domains, length, !!derivedLength);
      renderLegend(entries);
      applyHot();
    }

    /* Hover coupling: an exon lights its connector, a domain lights the
       connectors of the blocks it spans. */
    function setHot(kind, value) {
      state.hot = kind ? { kind: kind, value: value } : null;
      applyHot();
    }
    function applyHot() {
      var paths = stage.querySelectorAll('.gs-connector');
      var ranks = [];
      if (state.hot && state.hot.kind === 'cds') { ranks = state.hot.value; }
      if (state.hot && state.hot.kind === 'domain') {
        var entry = (currentDomains() || { entries: [] }).entries[state.hot.value];
        if (entry) {
          cdsSegments(state.selected).forEach(function (s) {
            if (s.resEnd >= entry.start && s.resStart <= entry.end) { ranks.push(s.rank); }
          });
        }
      }
      Array.prototype.forEach.call(paths, function (p) {
        p.classList.toggle('is-hot', ranks.indexOf(Number(p.getAttribute('data-rank'))) !== -1);
      });
    }

    /* ---- tooltip ---- */
    function attachTip(node, build) {
      function show(e) {
        tip.innerHTML = build();
        tip.hidden = false;
        place(e);
      }
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

    /* ---- summary and legend ---- */
    function renderSummary(segments, entries, domains, length, derived) {
      var t = state.selected;
      var items = [];
      items.push('<li><strong>' + (t.exons || []).length + '</strong> exon' + ((t.exons || []).length === 1 ? '' : 's') + '</li>');
      if (t.cds_length_nt) { items.push('<li>CDS <strong>' + fmt(t.cds_length_nt) + '</strong> nt in <strong>' + segments.length + '</strong> block' + (segments.length === 1 ? '' : 's') + '</li>'); }
      items.push('<li>transcript span <strong>' + fmt(t.end - t.start + 1) + '</strong> bp</li>');
      if (length) { items.push('<li>protein ' + (derived ? 'about ' : '') + '<strong>' + fmt(length) + '</strong> aa' + (derived ? ' <span class="gs-muted">from the CDS</span>' : '') + '</li>'); }
      if (domains) {
        if (domains.unavailable) { items.push('<li class="gs-muted">domains could not be loaded</li>'); }
        else if (domains.no_matches) {
          items.push('<li class="gs-muted">' + (domains.source === 'database' ? 'no Pfam domain on this gene in the database'
                                                                              : 'no InterProScan match on this protein') + '</li>');
        } else if (domains.source === 'database') {
          items.push('<li><strong>' + entries.length + '</strong> Pfam domain' + (entries.length === 1 ? '' : 's') +
                     ' <span class="gs-muted">from the database</span></li>');
        } else {
          items.push('<li><strong>' + entries.length + '</strong> InterPro entr' + (entries.length === 1 ? 'y' : 'ies') + '</li>');
          if ((domains.sites || []).length) { items.push('<li><strong>' + domains.sites.length + '</strong> residue-level site' + (domains.sites.length === 1 ? '' : 's') + '</li>'); }
          if (domains.architecture) { items.push('<li class="gs-muted">' + esc(domains.architecture) + '</li>'); }
        }
      }
      summary.innerHTML = items.join('');
    }

    function renderLegend(entries) {
      var items = [
        '<li><span class="gs-legend-star" aria-hidden="true">\u2605</span>canonical transcript</li>',
        '<li><span class="gs-swatch gs-swatch-cds"></span>coding sequence</li>',
        '<li><span class="gs-swatch gs-swatch-utr"></span>untranslated region</li>',
        '<li><span class="gs-swatch gs-swatch-intron"></span>intron, chevrons point 5&prime; to 3&prime;</li>'
      ];
      entries.forEach(function (entry, i) {
        var label = esc(entry.name || entry.accession) + ' <span class="gs-muted">' + entry.start + '&ndash;' + entry.end + '</span>';
        items.push('<li><span class="gs-swatch gs-swatch-dom-' + (i % DOMAIN_CLASSES) + '"></span>' +
          (entry.url ? '<a href="' + esc(entry.url) + '" rel="noopener">' + label + '</a>' : label) + '</li>');
      });
      var d = currentDomains();
      if (d && (d.sites || []).length) { items.push('<li><span class="gs-swatch gs-swatch-site"></span>binding or active-site residue</li>'); }
      legend.innerHTML = items.join('');
    }

    /* ------------------------------------------------------------------
       3D model. The library and the model load only on request.
       ------------------------------------------------------------------ */

    function renderModelPanel() {
      modelPanel.innerHTML = '';
      var model = spec.model;
      var protein = state.selected.protein;
      if (!model || !model.pdb || !protein) { return; }

      /* Its own sub-header, in the same .mgdb-rec-block-head style as "Gene
         model and protein" above it. The two things in this section are drawn
         from different sources and answer different questions -- one is the
         annotated gene model, the other is a prediction of what the protein
         folds into -- and without a heading between them the 3D panel read as
         a continuation of the figure. Emitted after the guard above, so a gene
         with no model gets no heading over an empty space. */
      modelPanel.insertAdjacentHTML('beforeend',
        '<div class="mgdb-rec-block-head"><h3>Predicted protein structure</h3></div>');
      /* The model may be of another isoform than the one selected: AlphaFill
         models the isoform its transplants landed on, and AlphaFold DB the
         UniProt entry. Say so rather than map domains onto the wrong chain. */
      var canonicalOnly = !!(model.protein && protein && model.protein !== protein.id);
      var head = html('div', 'gs-model-head');
      var btn = html('button', 'mgdb-button mgdb-button-primary mgdb-button-sm', 'Show 3D model');
      btn.type = 'button';
      var text = html('p', null,
        esc(model.label || model.source) + (model.plddt != null ? ' &middot; mean pLDDT <strong>' + Number(model.plddt).toFixed(1) + '</strong>' : '') +
        (model.entry ? ' &middot; <a href="' + esc(model.entry) + '" target="_blank" rel="noopener">AlphaFold DB entry</a>' : '') +
        (model.html ? ' &middot; <a href="' + esc(model.html) + '">' + esc(model.source === 'AlphaFill' ? 'AlphaFill' : 'Protein Structure Hub') + '</a>' : '') +
        (canonicalOnly ? '<br><span class="gs-muted">The model is of ' + esc(model.protein) + ', not of the selected protein ' + esc(protein.id) + '; domains are placed by residue number and may not correspond.</span>' : ''));
      head.appendChild(btn);
      head.appendChild(text);
      modelPanel.appendChild(head);
      var controls = html('div', 'gs-model-controls');
      controls.hidden = true;
      var stageEl = html('div', 'gs-model-stage');
      stageEl.hidden = true;
      var viewport = html('div', 'gs-model-viewport');
      var status = html('div', 'gs-model-status');
      stageEl.appendChild(viewport);
      stageEl.appendChild(status);
      var plddtLegend = html('ul', 'gs-plddt-legend',
        '<li><span class="gs-swatch" style="background:#0053d6"></span>very high (&gt; 90)</li>' +
        '<li><span class="gs-swatch" style="background:#65cbf3"></span>confident (70&ndash;90)</li>' +
        '<li><span class="gs-swatch" style="background:#ffdb13"></span>low (50&ndash;70)</li>' +
        '<li><span class="gs-swatch" style="background:#ff7d45"></span>very low (&lt; 50)</li>');
      plddtLegend.hidden = true;
      modelPanel.appendChild(controls);
      modelPanel.appendChild(stageEl);
      modelPanel.appendChild(plddtLegend);

      function openViewer() {
        btn.disabled = true;
        btn.textContent = 'Loading the model…';
        stageEl.hidden = false;
        controls.hidden = false;
        return loadViewerLibrary().then(function () {
          return openModel(viewport, status, model, canonicalOnly);
        }).then(function () {
          btn.hidden = true;
          renderModelControls(controls, plddtLegend, status);
        }).catch(function (err) {
          /* The button comes back so the reader can retry; a failure here is
             usually the half-megabyte library not arriving, not a missing
             model. */
          state.modelOpen = false;
          btn.disabled = false;
          btn.hidden = false;
          btn.textContent = 'Show 3D model';
          status.textContent = 'The model could not be loaded' + (err && err.message ? ': ' + err.message : '') + '.';
        });
      }

      btn.addEventListener('click', function () {
        state.modelOpen = true;
        openViewer();
      });

      /* Open by default. The viewer library and the coordinates are still
         fetched on demand -- they are just demanded straight away now, rather
         than waiting for a click most readers never made. Selecting another
         transcript re-renders this panel and reopens it, because state.modelOpen
         is still true. */
      if (state.modelOpen) { openViewer(); }
    }

    function loadViewerLibrary() {
      if (window.$3Dmol) { return Promise.resolve(); }
      return new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = '/js/lib/3dmol/3Dmol-min.js';
        s.async = true;
        s.onload = function () { window.$3Dmol ? resolve() : reject(new Error('viewer library did not initialise')); };
        s.onerror = function () { reject(new Error('viewer library failed to load')); };
        document.head.appendChild(s);
      });
    }

    function plddtColor(b) {
      if (b > 90) { return '#0053d6'; }
      if (b > 70) { return '#65cbf3'; }
      if (b > 50) { return '#ffdb13'; }
      return '#ff7d45';
    }

    function domainColorMap() {
      var d = currentDomains();
      var map = {};
      var hex = ['#1a5b7a', '#8a5a0f', '#7a2c43', '#4c7038', '#4a45ad', '#a52a63', '#0e6a5e', '#9a4b12'];
      ((d && d.entries) || []).forEach(function (entry, i) {
        for (var r = entry.start; r <= entry.end; r++) { if (map[r] === undefined) { map[r] = hex[i % hex.length]; } }
      });
      return map;
    }

    function applyModelStyle() {
      if (!state.viewer || !state.viewerModel) { return; }
      var map = domainColorMap();
      var mapped = state.colourBy === 'domains';
      state.viewer.setStyle({ model: 0 }, { cartoon: { colorfunc: function (atom) {
        if (!mapped) { return plddtColor(atom.b); }
        /* The residues outside any domain. #c9d1cc was chosen to read against
           the near-black stage this viewer used to have; on white it is very
           nearly invisible, which made the model look like disconnected
           fragments rather than one chain with domains marked on it. Dark
           enough to follow, still clearly subordinate to the domain colours.

           The pLDDT scale above is left alone: those four are AlphaFold DB's
           own colours, shown there on white, and a reader who knows them
           should not have to learn ours. */
        return map[atom.resi] || '#8d9992';
      } } });
      state.viewer.render();
    }

    function openModel(viewport, status, model, canonicalOnly) {
      status.textContent = 'Fetching the model…';
      /* White, not the near-black this used to be. 3Dmol paints the canvas
         itself, so the stage's CSS background is never seen and changing only
         that left the viewer black. */
      state.viewer = window.$3Dmol.createViewer(viewport, { backgroundColor: '#ffffff', antialias: true });
      return window.fetch(model.pdb, { credentials: 'same-origin', mode: /^https?:/.test(model.pdb) && model.pdb.indexOf(window.location.origin) !== 0 ? 'cors' : 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
        .then(function (text) {
          state.viewerModel = state.viewer.addModel(text, 'pdb');
          var atoms = state.viewerModel.selectedAtoms({ atom: 'CA' });
          var n = atoms.length;
          var length = state.selected.protein ? state.selected.protein.length_aa : null;
          applyModelStyle();
          state.viewer.zoomTo();
          state.viewer.render();
          status.textContent = (n ? n + ' residues' : '') +
            (length && n && n !== length ? ' — the model has ' + n + ' residues and the annotation protein ' + length + '; domains are placed by residue number' : '') +
            (canonicalOnly ? ' — model of ' + model.protein : '');
          if (window.ResizeObserver) {
            new window.ResizeObserver(function () { if (state.viewer) { state.viewer.resize(); state.viewer.render(); } }).observe(viewport);
          }
        });
    }

    /* The hub's viewer, fetched once and mounted under this panel. */
    function loadFullViewLibrary() {
      if (window.MGDB && window.MGDB.proteinStructureViewer) { return Promise.resolve(); }
      return new Promise(function (resolve, reject) {
        var css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = '/css/mgdb-protein-structure.css';
        document.head.appendChild(css);
        var sc = document.createElement('script');
        sc.src = '/js/mgdb-protein-structure.js';
        sc.async = true;
        sc.onload = function () {
          (window.MGDB && window.MGDB.proteinStructureViewer) ? resolve() : reject(new Error('viewer did not initialise'));
        };
        sc.onerror = function () { reject(new Error('the full viewer failed to load')); };
        document.head.appendChild(sc);
      });
    }

    function toggleFullView(btn, controls, status) {
      var panel = controls.parentNode;
      var open = btn.getAttribute('aria-pressed') !== 'true';
      var host = panel.querySelector('.gs-fullview');

      if (!open) {
        btn.setAttribute('aria-pressed', 'false');
        btn.textContent = 'Full view';
        if (host) { host.hidden = true; }
        Array.prototype.forEach.call(panel.querySelectorAll('.gs-model-stage, .gs-plddt-legend'), function (n) {
          if (n.getAttribute('data-gs-was-hidden') !== 'yes') { n.hidden = false; }
        });
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Loading the full viewer…';
      loadFullViewLibrary().then(function () {
        var model = state.spec.model;
        var protein = state.selected && state.selected.protein;
        var record = {
          id: model.protein || (protein && protein.id) || state.spec.gene.name,
          pdb: model.pdb,
          entry: model.entry || null,
          tool: model.source || null,
          partners: null
        };
        if (!host) {
          host = html('div', 'gs-fullview');
          panel.appendChild(host);
        }
        host.hidden = false;
        /* White, to match the page it is sitting in rather than the hub's own
           dark workspace. */
        var ok = window.MGDB.proteinStructureViewer(host, record, 'monomer', { background: '#ffffff' });
        if (!ok) { throw new Error('the full viewer could not start'); }

        /* One model on screen at a time: the compact stage and its legend step
           aside while the full one is open, and their own hidden state is
           remembered so closing puts them back as they were. */
        Array.prototype.forEach.call(panel.querySelectorAll('.gs-model-stage, .gs-plddt-legend'), function (n) {
          n.setAttribute('data-gs-was-hidden', n.hidden ? 'yes' : 'no');
          n.hidden = true;
        });
        btn.disabled = false;
        btn.setAttribute('aria-pressed', 'true');
        btn.textContent = 'Close full view';
      }).catch(function (err) {
        btn.disabled = false;
        btn.textContent = 'Full view';
        if (status) { status.textContent = (err && err.message ? err.message : 'The full viewer could not be opened') + '.'; }
      });
    }

    function renderModelControls(controls, plddtLegend, status) {
      controls.innerHTML = '';
      var label = html('span', 'gs-toolbar-label', 'Colour by');
      controls.appendChild(label);
      var byDomains = html('button', 'gs-chip', 'InterPro domains');
      var byPlddt = html('button', 'gs-chip', 'Model confidence (pLDDT)');
      byDomains.type = byPlddt.type = 'button';
      function setMode(mode) {
        state.colourBy = mode;
        byDomains.setAttribute('aria-pressed', mode === 'domains' ? 'true' : 'false');
        byPlddt.setAttribute('aria-pressed', mode === 'plddt' ? 'true' : 'false');
        plddtLegend.hidden = mode !== 'plddt';
        applyModelStyle();
      }
      byDomains.addEventListener('click', function () { setMode('domains'); });
      byPlddt.addEventListener('click', function () { setMode('plddt'); });
      controls.appendChild(byDomains);
      controls.appendChild(byPlddt);
      var reset = html('button', 'gs-chip', 'Reset view');
      reset.type = 'button';
      reset.addEventListener('click', function () { if (state.viewer) { state.viewer.zoomTo(); state.viewer.render(); } });
      controls.appendChild(reset);
      var spin = html('button', 'gs-chip', 'Spin');
      spin.type = 'button';
      spin.setAttribute('aria-pressed', 'false');
      spin.addEventListener('click', function () {
        var on = spin.getAttribute('aria-pressed') !== 'true';
        spin.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (state.viewer) { state.viewer.spin(on ? 'y' : false); }
      });
      controls.appendChild(spin);

      /* Full view opens the Protein Structure Data Hub's own viewer here, in
         the page, rather than sending the reader to the hub to look at the
         protein they were already looking at.

         It is the same component, exported as MGDB.proteinStructureViewer --
         representation and thickness, six colour schemes, molecular surface,
         cartoon outline, PNG and coordinate downloads, and the per-residue
         pLDDT strip you can click to zoom to a residue. Its script is fetched
         on demand for the same reason the 3D library is: most readers never
         open it. */
      var modelSpec = state.spec.model;
      if (modelSpec && modelSpec.pdb) {
        var full = html('button', 'gs-chip', 'Full view');
        full.type = 'button';
        full.setAttribute('aria-pressed', 'false');
        full.addEventListener('click', function () { toggleFullView(full, controls, status); });
        controls.appendChild(full);
      }

      var hint = html('span', 'gs-muted', 'Click a domain in the figure to zoom the model to it.');
      hint.style.fontSize = 'var(--mgdb-text-xs)';
      controls.appendChild(hint);
      setMode('domains');
      status.textContent = status.textContent;
    }

    function focusModelOn(entry) {
      if (!state.viewer || !state.viewerModel) { return; }
      state.viewer.zoomTo({ model: 0, resi: entry.start + '-' + entry.end }, 500);
    }

    renderToolbar();
    draw();
    renderModelPanel();

    if (window.ResizeObserver) {
      var lastW = stage.clientWidth;
      new window.ResizeObserver(function () {
        if (stage.clientWidth && stage.clientWidth !== lastW) { lastW = stage.clientWidth; draw(); }
      }).observe(stage);
    }
    return true;
  }

  MGDB.geneStructure = geneStructure;
})(window, document);
