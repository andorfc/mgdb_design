/* file: mgdb-pan-gene-figures.js
 *
 * purpose: the figures on the pan-gene record page, drawn from the record's
 *          own JSON. Each is a function on MGDB that renders into a container
 *          and returns a small handle; js/mgdb-pan-gene-record.js decides
 *          where they go and wires them to the rest of the page.
 *
 *          MGDB.panGenePresence(container, spec)
 *            The presence/absence strip: one cell per annotation the analysis
 *            grouped, in panels (B73 references, NAM founders, ...), filled
 *            when this pan-gene has a member there, numbered when it has more
 *            than one, empty when it has none. The exemplar's cell is ringed,
 *            and a cell whose member sits on a different chromosome from the
 *            pan-gene carries a corner mark.
 *
 *            spec = {
 *              presence:  sections.presence  ({annotation_count, present_count,
 *                         absent_count, member_count, panels[], unplaced[]})
 *              chr:       the pan-gene's chromosome (overview.chr), or null
 *              (a cell click publishes to MGDB.panGeneSelection, which the
 *               record page wires to the members table)
 *              filename:  the TSV download name
 *            }
 *
 *          MGDB.panGeneArchitectures(container, spec)
 *            Domain architecture ribbons: the members collapsed onto their
 *            distinct `domain_string`s, commonest first, each drawn as its
 *            domains laid along a shared residue axis. Blocks are packed into
 *            lanes because HMMscan reports overlapping models over the same
 *            region, so one row per architecture would hide most of them.
 *
 *            spec = {
 *              domains:   sections.domains  ({architectures[], domain_totals[],
 *                         axis_max, definitions[]})
 *              limit:     how many ribbons to draw before the tail is summarised
 *              filename:  the TSV download name
 *            }
 *
 *          Nothing here reads the DOM at module scope.
 *
 * history:
 *  09/17/26  claude  created, with the presence/absence strip
 *  09/17/26  claude  domain architecture ribbons
 */
(function (window, document) {
  'use strict';

  var MGDB = window.MGDB = window.MGDB || {};

  function esc(value) {
    return MGDB.escapeHtml ? MGDB.escapeHtml(value == null ? '' : String(value))
         : String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
             return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
           });
  }

  function number(n) {
    return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString();
  }


  /* ------------------------------------------------------------------------
     PNG export

     A figure hands over a finished, self-contained <svg> string and this turns
     it into a PNG the reader can drop into a talk.

     Self-contained matters: the rasteriser loads the SVG through an <img>,
     and an <img> does not see the page's stylesheets or its web fonts. So a
     figure that leaves its colours to CSS exports as black shapes on white.
     Every export SVG here therefore carries presentation attributes rather
     than classes, and names only fonts a machine already has.

     Nothing is fetched and no canvas is tainted, so toBlob() works everywhere.
     ------------------------------------------------------------------------ */

  /* Single quotes inside the stack, deliberately. These go into a
     double-quoted XML attribute built by hand, and the usual CSS spelling --
     `"Segoe UI"` -- closes the attribute at the first inner quote. The SVG then
     fails to parse, the <img> never loads, and the export silently produces
     nothing at all: no error, no file. */
  var EXPORT_FONT = "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif";
  var EXPORT_MONO = "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";

  function exportSvgToPng(svgMarkup, width, height, filename, scale) {
    var factor = scale || 2;   /* 2x, so it holds up in a slide */
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height +
              '" viewBox="0 0 ' + width + ' ' + height + '">' +
              '<rect width="' + width + '" height="' + height + '" fill="#ffffff"></rect>' +
              svgMarkup + '</svg>';
    var url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    var image = new Image();
    image.onload = function () {
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(width * factor);
      canvas.height = Math.round(height * factor);
      var ctx = canvas.getContext('2d');
      ctx.setTransform(factor, 0, 0, factor, 0, 0);
      ctx.drawImage(image, 0, 0);
      canvas.toBlob(function (blob) {
        if (!blob) { return; }
        var href = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = href;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
      }, 'image/png');
    };
    image.onerror = function () {
      if (window.console && console.warn) { console.warn('PNG export failed for ' + filename); }
      MGDB.announce('The figure could not be exported.');
    };
    image.src = url;
  }
  MGDB.exportSvgToPng = exportSvgToPng;


  /* Clone an on-page <svg> with its styling written onto the elements.

     The rasteriser loads the markup through an <img>, which sees no
     stylesheet, so a figure whose colours live in CSS would export black. This
     copies the computed value of the handful of properties that carry a
     drawing's appearance, which keeps the export in step with the stylesheet
     instead of duplicating it. */
  var PAINT_PROPS = ['fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-opacity',
                     'stroke-dasharray', 'opacity', 'font-family', 'font-size', 'font-weight',
                     'font-style', 'text-anchor', 'letter-spacing'];

  function inlineSvgStyles(source) {
    var clone = source.cloneNode(true);
    var from = source.querySelectorAll('*');
    var to = clone.querySelectorAll('*');
    for (var i = 0; i < from.length; i++) {
      var cs = window.getComputedStyle(from[i]);
      if (cs.display === 'none') { to[i].setAttribute('display', 'none'); continue; }
      var css = '';
      for (var j = 0; j < PAINT_PROPS.length; j++) {
        var v = cs.getPropertyValue(PAINT_PROPS[j]);
        /* A computed font-family is spelled with double quotes
           (`"Liberation Mono"`). XMLSerializer escapes them on the way out,
           but the value is normalised here so the same string is safe whether
           it is serialised or concatenated. */
        if (v) { css += PAINT_PROPS[j] + ':' + v.replace(/"/g, "'") + ';'; }
      }
      to[i].setAttribute('style', css);
      /* A class is meaningless once the stylesheet is gone, and dropping it
         keeps the exported file small. */
      to[i].removeAttribute('class');
    }
    clone.removeAttribute('class');
    clone.removeAttribute('style');
    return clone;
  }
  MGDB.inlineSvgStyles = inlineSvgStyles;

  /* A <text> for an export SVG. Everything is a presentation attribute
     because no stylesheet reaches the rasteriser. */
  function attr(value) { return String(value).replace(/"/g, '&quot;'); }

  function xText(x, y, text, opts) {
    var o = opts || {};
    return '<text x="' + x + '" y="' + y + '"' +
      ' font-family="' + attr(o.mono ? EXPORT_MONO : EXPORT_FONT) + '"' +
      ' font-size="' + (o.size || 11) + '"' +
      (o.weight ? ' font-weight="' + o.weight + '"' : '') +
      (o.style ? ' font-style="' + o.style + '"' : '') +
      (o.anchor ? ' text-anchor="' + o.anchor + '"' : '') +
      (o.transform ? ' transform="' + o.transform + '"' : '') +
      ' fill="' + (o.fill || '#3d4a42') + '">' + esc(text) + '</text>';
  }

  /* chr2, Chr2, chr02 and 2 are the same chromosome; the annotations do not
     agree on a spelling. */
  function chrKey(chr) {
    if (chr == null || chr === '') { return null; }
    var s = String(chr).toLowerCase().replace(/^chr0*/, '').replace(/^0+(\d)/, '$1');
    return s === '' ? null : s;
  }

  /* ------------------------------------------------------------------------
     Presence / absence strip
     ------------------------------------------------------------------------ */

  function panGenePresence(container, spec) {
    if (!container || !spec || !spec.presence || !spec.presence.panels) { return null; }
    var presence = spec.presence;
    var panels = presence.panels || [];
    var panChr = chrKey(spec.chr);
    var cells = [];   /* flat, in drawn order, for the TSV and the handlers */

    function memberSummary(cell) {
      if (!cell.members.length) { return 'no member'; }
      return cell.members.map(function (m) {
        var where = m.chr ? ' (' + m.chr + ')' : '';
        return m.name + where + (m.is_exemplar ? ', the exemplar' : '');
      }).join('; ');
    }

    function elsewhere(cell) {
      if (!panChr) { return false; }
      return cell.members.some(function (m) {
        var k = chrKey(m.chr);
        return k !== null && k !== panChr;
      });
    }

    var html = '<div class="mgdb-rec-block mgdb-pg-presence">' +
      '<div class="mgdb-rec-block-head">' +
        '<h3>Presence across the analysis</h3>' +
        '<div class="mgdb-pg-presence-tools">' +
          '<ul class="mgdb-pg-presence-legend" aria-label="Key">' +
            '<li><span class="mgdb-pg-cell is-present" aria-hidden="true"></span>Present</li>' +
            '<li><span class="mgdb-pg-cell is-present is-multi" aria-hidden="true">2</span>More than one member</li>' +
            '<li><span class="mgdb-pg-cell" aria-hidden="true"></span>Absent</li>' +
            '<li><span class="mgdb-pg-cell is-present is-exemplar" aria-hidden="true"></span>Exemplar</li>' +
            (panChr ? '<li><span class="mgdb-pg-cell is-present is-elsewhere" aria-hidden="true"></span>Other chromosome</li>' : '') +
          '</ul>' +
          '<button class="mgdb-rec-tsv" type="button" data-role="png">Export PNG</button>' +
          '<button class="mgdb-rec-tsv" type="button" data-role="tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
      '<p class="mgdb-fig-desc">Presence and absence across every annotation in the analysis, ' +
        'grouped by panel; selecting a cell links to the other figures.</p>' +
      '<p class="mgdb-rec-block-status">Present in <strong>' + number(presence.present_count) + ' of ' +
        number(presence.annotation_count) + '</strong> annotations' +
        (presence.absent_count > 0 ? ', absent from ' + number(presence.absent_count) : '') +
        ' &middot; ' + number(presence.member_count) + ' members' +
        (panChr ? ' &middot; pan-gene on ' + esc(spec.chr) : '') + '.</p>' +
      '<div class="mgdb-pg-presence-panels">';

    panels.forEach(function (panel, pi) {
      html += '<div class="mgdb-pg-presence-panel" data-panel="' + esc(panel.key) + '">' +
        '<div class="mgdb-pg-presence-panel-label">' + esc(panel.label) +
          ' <span>' + number(panel.present_count) + '/' + number(panel.annotation_count) + '</span></div>' +
        '<div class="mgdb-pg-presence-cells" role="group" aria-label="' + esc(panel.label) + '">';
      (panel.annotations || []).forEach(function (a) {
        var cell = {
          panel: panel.label, assembly: a.assembly, annotation: a.annotation, label: a.label,
          species: a.species, count: a.count || 0, members: a.members || []
        };
        var index = cells.length;
        cells.push(cell);
        var classes = ['mgdb-pg-cell'];
        if (cell.count > 0) { classes.push('is-present'); }
        if (cell.count > 1) { classes.push('is-multi'); }
        if (cell.members.some(function (m) { return m.is_exemplar; })) { classes.push('is-exemplar'); }
        if (elsewhere(cell)) { classes.push('is-elsewhere'); }
        var aria = cell.label + ', ' + cell.assembly + ': ' +
          (cell.count === 0 ? 'absent' : (cell.count === 1 ? '1 member' : cell.count + ' members') + ', ' + memberSummary(cell));
        html += '<div class="mgdb-pg-presence-col">' +
          '<button class="' + classes.join(' ') + '" type="button" data-cell="' + index + '" aria-label="' + esc(aria) + '">' +
            (cell.count > 1 ? '<span>' + (cell.count > 99 ? '99+' : cell.count) + '</span>' : '') +
          '</button>' +
          '<span class="mgdb-pg-presence-name" aria-hidden="true">' + esc(cell.label) + '</span>' +
        '</div>';
      });
      html += '</div></div>';
    });

    html += '</div>' +
      '<p class="mgdb-pg-presence-detail" data-role="detail" aria-live="polite">' +
        'Hover or focus a cell for its members; click one to filter the table below to it.</p>';

    var unplaced = presence.unplaced || [];
    if (unplaced.length) {
      html += '<p class="mgdb-rec-block-status">' + number(unplaced.length) +
        (unplaced.length === 1 ? ' member is' : ' members are') +
        ' not assigned to an annotation in this analysis: ' +
        unplaced.map(function (m) { return '<span class="mgdb-sequence">' + esc(m.name) + '</span>'; }).join(', ') + '.</p>';
    }
    html += '</div>';

    container.insertAdjacentHTML('beforeend', html);
    var block = container.lastElementChild;
    var detail = block.querySelector('[data-role="detail"]');
    var idle = detail.textContent;

    function describe(cell) {
      var parts = ['<strong>' + esc(cell.label) + '</strong> <span class="mgdb-muted">' + esc(cell.assembly) +
        (cell.species ? ' &middot; <em>' + esc(cell.species) + '</em>' : '') + '</span>'];
      if (!cell.count) {
        parts.push('no member of this pan-gene');
      } else {
        parts.push(cell.members.map(function (m) {
          var text = '<span class="mgdb-sequence">' + esc(m.name) + '</span>';
          if (m.chr) {
            var off = panChr && chrKey(m.chr) !== null && chrKey(m.chr) !== panChr;
            text += ' <span class="' + (off ? 'mgdb-pill mgdb-pill-warn' : 'mgdb-muted') + '">' + esc(m.chr) + '</span>';
          }
          if (m.is_exemplar) { text += ' <span class="mgdb-pill mgdb-pill-ok">Exemplar</span>'; }
          return text;
        }).join(', '));
      }
      detail.innerHTML = parts.join(' &middot; ');
    }

    /* The line under the panels shows, in order: the cell the pointer is over,
       then the cell holding keyboard focus, then the idle sentence. Tracking
       hover and focus separately is what makes moving the pointer away always
       return to the default. The previous version kept the description up
       whenever any cell held focus -- and a click focuses the cell it hit, so
       after one click the line stayed on that cell wherever the pointer went.

       Keyboard focus only, deliberately: a mouse click leaves the button
       focused but not :focus-visible, so it does not hold the line. */
    var hovered = null;
    var focused = null;

    function cellOf(event) {
      var btn = event.target.closest ? event.target.closest('[data-cell]') : null;
      return btn ? cells[+btn.getAttribute('data-cell')] : null;
    }

    function keyboardFocused(btn) {
      /* No :focus-visible support -- keep the old behaviour and hold the line. */
      try { return btn.matches(':focus-visible'); } catch (e) { return true; }
    }

    function refresh() {
      var cell = hovered || focused;
      if (cell) { describe(cell); } else { detail.textContent = idle; }
    }

    block.addEventListener('mouseover', function (event) {
      var cell = cellOf(event);
      if (cell) { hovered = cell; refresh(); }
    });
    block.addEventListener('mouseout', function (event) {
      if (cellOf(event)) { hovered = null; refresh(); }
    });
    block.addEventListener('focusin', function (event) {
      var btn = event.target.closest('[data-cell]');
      if (btn && keyboardFocused(btn)) { focused = cells[+btn.getAttribute('data-cell')]; refresh(); }
    });
    block.addEventListener('focusout', function (event) {
      if (event.target.closest('[data-cell]')) { focused = null; refresh(); }
    });
    /* Clicking the selected cell again clears the selection, so a reader who
       filtered the table by a cell can undo it from the same place. */
    block.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-cell]');
      if (!btn) { return; }
      var was = btn.classList.contains('is-selected');
      if (was) { MGDB.panGeneSelection.clear(); return; }
      var cell = cells[+btn.getAttribute('data-cell')];
      if (!cell.members.length) { MGDB.panGeneSelection.clear(); return; }
      MGDB.panGeneSelection.set({
        genes: cell.members.map(function (m) { return m.name; }),
        label: cell.label,
        source: 'presence',
        /* One member filters the table by its own name; several by the
           annotation they share. */
        filter: cell.members.length === 1 ? cell.members[0].name : (cell.annotation || cell.assembly || '')
      });
    });

    /* Re-paint whenever anybody publishes a selection, including this strip. */
    MGDB.panGeneSelection.subscribe(function (sel) {
      var set = {};
      if (sel) { sel.genes.forEach(function (g) { set[g] = true; }); }
      var any = !!sel;
      Array.prototype.forEach.call(block.querySelectorAll('[data-cell]'), function (btn) {
        var cell = cells[+btn.getAttribute('data-cell')];
        var hit = any && cell.members.some(function (m) { return set[m.name]; });
        btn.classList.toggle('is-dim', any && !hit);
        btn.classList.toggle('is-selected', !!hit);
      });
    });

    /* Drawn from the data rather than cloned from the page: the strip is HTML
       -- boxes, rotated labels, a wrapping flex row -- and there is no <svg>
       to take a copy of. Laying every panel out on its own row also reads
       better as a still image than reproducing where the flex happened to
       wrap at the reader's window width. */
    block.querySelector('[data-role="png"]').addEventListener('click', function () {
      var M = 24, CELL = 20, GAP = 5, NAME_H = 70, W = 1100;
      var perRow = Math.floor((W - 2 * M) / (CELL + GAP));
      var out = '';
      var y = M;
      out += xText(M, y + 6, 'Presence across the analysis', { size: 17, weight: 700, fill: '#1f2723' });
      y += 26;
      out += xText(M, y + 6, presence.present_count + ' of ' + presence.annotation_count +
        ' annotations \u00b7 ' + presence.member_count + ' members' +
        (spec.chr ? ' \u00b7 pan-gene on ' + spec.chr : ''), { size: 12, fill: '#5d6b62' });
      y += 22;

      /* A key, because an exported figure has to stand on its own in a talk:
         nothing beside it explains a pale cell or a gold ring. */
      var key = [
        { fill: '#1d6b42', edge: '#1d6b42', text: 'Present' },
        { fill: '#123524', edge: '#123524', text: 'More than one member', num: '2' },
        { fill: '#f3f1ea', edge: '#d7d2c6', text: 'Absent' },
        { fill: '#1d6b42', edge: '#1d6b42', text: 'Exemplar', ring: true },
        { fill: '#1d6b42', edge: '#1d6b42', text: 'Other chromosome', off: true }
      ];
      var kx = M;
      key.forEach(function (k) {
        out += '<rect x="' + kx + '" y="' + (y - 9) + '" width="12" height="12" rx="3" fill="' +
          k.fill + '" stroke="' + k.edge + '"></rect>';
        if (k.num) {
          out += xText(kx + 6, y + 0.5, k.num, { size: 8, weight: 700, fill: '#ffffff', anchor: 'middle' });
        }
        if (k.ring) {
          out += '<rect x="' + (kx - 2) + '" y="' + (y - 11) + '" width="16" height="16" rx="5" fill="none" ' +
            'stroke="#c8a227" stroke-width="1.6"></rect>';
        }
        if (k.off) {
          out += '<circle cx="' + (kx + 12) + '" cy="' + (y - 9) + '" r="3" fill="#e95e22" ' +
            'stroke="#ffffff" stroke-width="1"></circle>';
        }
        out += xText(kx + 18, y + 1, k.text, { size: 10, fill: '#3d4a42' });
        kx += 18 + k.text.length * 5.3 + 18;
      });
      y += 26;

      panels.forEach(function (panel) {
        out += xText(M, y + 8, panel.label.toUpperCase() + '   ' + panel.present_count + '/' +
          panel.annotation_count, { size: 10, weight: 700, fill: '#3d4a42' });
        y += 16;
        var list = panel.annotations || [];
        for (var start = 0; start < list.length; start += perRow) {
          var chunk = list.slice(start, start + perRow);
          chunk.forEach(function (a, i) {
            var cx = M + i * (CELL + GAP);
            var present = (a.count || 0) > 0;
            var multi = (a.count || 0) > 1;
            var fill = multi ? '#123524' : (present ? '#1d6b42' : '#f3f1ea');
            out += '<rect x="' + cx + '" y="' + y + '" width="' + CELL + '" height="' + CELL +
              '" rx="4" fill="' + fill + '" stroke="' + (present ? fill : '#d7d2c6') + '"></rect>';
            if (multi) {
              out += xText(cx + CELL / 2, y + CELL / 2 + 3.5, String(a.count),
                { size: 9, weight: 700, fill: '#ffffff', anchor: 'middle' });
            }
            if ((a.members || []).some(function (m) { return m.is_exemplar; })) {
              out += '<rect x="' + (cx - 2.5) + '" y="' + (y - 2.5) + '" width="' + (CELL + 5) +
                '" height="' + (CELL + 5) + '" rx="6" fill="none" stroke="#c8a227" stroke-width="2"></rect>';
            }
            if (panChr && (a.members || []).some(function (m) {
              var k = chrKey(m.chr);
              return k !== null && k !== panChr;
            })) {
              out += '<circle cx="' + (cx + CELL) + '" cy="' + y + '" r="3.4" fill="#e95e22" ' +
                'stroke="#ffffff" stroke-width="1.2"></circle>';
            }
            out += xText(cx + CELL / 2 + 3.5, y + CELL + 6, a.label,
              { size: 9, fill: '#5d6b62', anchor: 'end',
                transform: 'rotate(-90 ' + (cx + CELL / 2 + 3.5) + ' ' + (y + CELL + 6) + ')' });
          });
          y += CELL + NAME_H;
        }
        y += 6;
      });

      y += 4;
      out += xText(M, y, 'MaizeGDB \u00b7 ' + (spec.recordName || 'pan-gene') +
        ' \u00b7 presence across the analysis', { size: 10, fill: '#7c837e' });
      exportSvgToPng(out, W, y + 16,
        (spec.filename || 'pan-gene-presence.tsv').replace(/\.tsv$/, '') + '.png');
    });

    block.querySelector('[data-role="tsv"]').addEventListener('click', function () {
      var columns = [
        { label: 'Panel', get: function (c) { return c.panel; } },
        { label: 'Assembly', get: function (c) { return c.assembly; } },
        { label: 'Annotation', get: function (c) { return c.annotation; } },
        { label: 'Present', get: function (c) { return c.count > 0 ? 'yes' : 'no'; } },
        { label: 'Members', get: function (c) { return c.count; } },
        { label: 'Gene models', get: function (c) { return c.members.map(function (m) { return m.name; }).join(', '); } },
        { label: 'Chromosomes', get: function (c) { return c.members.map(function (m) { return m.chr || ''; }).join(', '); } }
      ];
      if (window.MGDBRecord && window.MGDBRecord.downloadTsv) {
        window.MGDBRecord.downloadTsv(spec.filename || 'pan-gene-presence.tsv', columns, cells);
      }
    });

    return {
      element: block,
      /* Mark the cells that carry any of these gene models, for the linked
         selection the tree and the other figures will drive. */
      highlight: function (names) {
        var set = {};
        (names || []).forEach(function (n) { set[n] = true; });
        var any = Object.keys(set).length > 0;
        Array.prototype.forEach.call(block.querySelectorAll('[data-cell]'), function (btn) {
          var cell = cells[+btn.getAttribute('data-cell')];
          var hit = cell.members.some(function (m) { return set[m.name]; });
          btn.classList.toggle('is-dim', any && !hit);
          btn.classList.toggle('is-selected', any && hit);
        });
      }
    };
  }


  /* ------------------------------------------------------------------------
     Domain architecture ribbons

     Colour carries domain identity, and only six of them. The site's
     Okabe-Ito palette (MGDB.CHART_COLORS) was validated for this figure with
     the data-viz palette checker: any two domains can end up side by side
     here, since the order differs between architectures, so it is the
     all-pairs test that applies rather than the adjacent-pairs one. Seven
     slots FAIL it -- slot 7 against slot 2 is dE 4.9 under deuteranopia and
     13.7 under normal vision, below the hard floor of 15. Six slots pass.
     So the six commonest domains in the record take slots 1-6 and every other
     domain is drawn neutral, which is the documented answer for a seventh
     category rather than a colour invented for it.

     Colour is never the only encoding: every block is labelled where it fits,
     carries an SVG <title>, names itself in the line under the figure, and
     appears in the table below. That relief is also what the two low-contrast
     slots (#E69F00 at 2.25:1 and #56B4E9 at 2.31:1 on white) require.
     ------------------------------------------------------------------------ */

  var NEUTRAL_DOMAIN = '#7c837e';   /* not a palette slot: "some other domain" */
  var COLOURED_DOMAINS = 6;

  /* HMMscan reports several models over one region -- on rp1's commonest
     architecture, 24 blocks over 1,237 residues, many of them nested. Drawn on
     one line they cover each other, so each block goes in the first lane whose
     last block ended before it starts. */
  function packLanes(blocks, gapResidues) {
    var lanes = [];
    var sorted = blocks.slice().sort(function (a, b) {
      return a.start === b.start ? (b.end - b.start) - (a.end - a.start) : a.start - b.start;
    });
    sorted.forEach(function (block) {
      for (var i = 0; i < lanes.length; i++) {
        var last = lanes[i][lanes[i].length - 1];
        if (block.start > last.end + gapResidues) { lanes[i].push(block); return; }
      }
      lanes.push([block]);
    });
    return lanes;
  }

  function panGeneArchitectures(container, spec) {
    if (!container || !spec || !spec.domains) { return null; }
    var data = spec.domains;
    var architectures = data.architectures || [];
    var totals = data.domain_totals || [];
    var axisMax = data.axis_max || 0;
    if (!architectures.length || !axisMax) { return null; }

    var palette = (MGDB.CHART_COLORS || []).slice(0, COLOURED_DOMAINS);
    var colours = {};
    totals.slice(0, palette.length).forEach(function (t, i) { colours[t.name] = palette[i]; });
    function colourOf(name) { return colours[name] || NEUTRAL_DOMAIN; }

    var definitions = {};
    (data.definitions || []).forEach(function (d) { definitions[d.name] = d.definition; });

    var memberTotal = architectures.reduce(function (n, a) { return n + a.member_count; }, 0);
    var limit = Math.min(spec.limit || 8, architectures.length);
    var expanded = false;

    /* One residue axis for every ribbon, so the architectures can be read
       against each other -- but scaled to the ribbons actually drawn, not to
       the whole pan-gene. On rp1 a single member carries a domain out to
       2,277 aa while every one of the eight commonest architectures ends near
       1,250, and scaling to the record squeezed all of them into the left half
       of the figure. `axis_max` is still reported in the note, so the outlier
       is stated rather than hidden. Expanding the list rescales. */
    var recordMax = axisMax;
    var drawnMax = axisMax;

    function maxExtent(list) {
      var m = 0;
      list.forEach(function (a) {
        (a.blocks || []).forEach(function (b) { if (b.end > m) { m = b.end; } });
      });
      return m > 0 ? m : recordMax;
    }

    var W = 1000, PAD = 2, LANE = 15, LANE_GAP = 5;
    function x(residue) { return PAD + (residue / drawnMax) * (W - 2 * PAD); }

    function tickStep() {
      var raw = drawnMax / 6;
      var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
      var steps = [1, 2, 2.5, 5, 10];
      for (var i = 0; i < steps.length; i++) {
        if (mag * steps[i] >= raw) { return mag * steps[i]; }
      }
      return mag * 10;
    }

    function axisHtml() {
      var step = tickStep(), out = '', r;
      for (r = 0; r <= drawnMax; r += step) {
        out += '<span class="mgdb-pg-arch-tick" style="left:' +
               ((x(r) / W) * 100).toFixed(3) + '%">' + number(Math.round(r)) + '</span>';
      }
      return '<div class="mgdb-pg-arch-axis" aria-hidden="true">' + out + '</div>';
    }

    function ribbonSvg(arch) {
      var lanes = packLanes(arch.blocks || [], Math.max(2, Math.round(drawnMax * 0.004)));
      var height = lanes.length * LANE + (lanes.length - 1) * LANE_GAP;
      var svg = '<svg class="mgdb-pg-arch-svg" viewBox="0 0 ' + W + ' ' + height +
                '" preserveAspectRatio="none" role="img" aria-label="' +
                esc(architectureLabel(arch)) + '">';
      lanes.forEach(function (lane, li) {
        var y = li * (LANE + LANE_GAP);
        /* The backbone: what the domains are placed along. */
        svg += '<rect class="mgdb-pg-arch-rail" x="' + PAD + '" y="' + (y + LANE / 2 - 1.5) +
               '" width="' + (W - 2 * PAD) + '" height="3" rx="1.5"></rect>';
        lane.forEach(function (block) {
          var x0 = x(block.start), x1 = x(block.end);
          var w = Math.max(x1 - x0, 3);
          svg += '<g class="mgdb-pg-arch-block">' +
            '<title>' + esc(block.name + '  ' + number(block.start) + '–' + number(block.end) + ' aa' +
              (definitions[block.name] ? '\n' + definitions[block.name] : '')) + '</title>' +
            '<rect x="' + x0.toFixed(2) + '" y="' + y + '" width="' + w.toFixed(2) +
              '" height="' + LANE + '" rx="4" fill="' + colourOf(block.name) + '"></rect>' +
            '</g>';
        });
      });
      svg += '</svg>';
      return { html: svg, lanes: lanes.length };
    }

    /* Names sit in HTML over the SVG rather than inside it, so they keep their
       own size while the SVG stretches to the container. */
    function labelsHtml(arch, lanes) {
      var out = '';
      lanes.forEach(function (lane, li) {
        lane.forEach(function (block) {
          var left = (x(block.start) / W) * 100;
          var width = ((x(block.end) - x(block.start)) / W) * 100;
          if (width < 5.5) { return; }   /* no room; the <title> and the table carry it */
          out += '<span class="mgdb-pg-arch-name" style="left:' + left.toFixed(3) +
                 '%;width:' + width.toFixed(3) + '%;top:' +
                 (li * (LANE + LANE_GAP) + 1) + 'px;height:' + (LANE - 2) + 'px">' +
                 esc(block.name) + '</span>';
        });
      });
      return out;
    }

    function architectureLabel(arch) {
      return arch.member_count + (arch.member_count === 1 ? ' member: ' : ' members: ') +
             (arch.domain_string || 'no domains');
    }

    function rowHtml(arch, index) {
      var ribbon = ribbonSvg(arch);
      var rep = arch.representative || {};
      var height = ribbon.lanes * LANE + (ribbon.lanes - 1) * LANE_GAP;
      return '<li class="mgdb-pg-arch-row" data-arch="' + index + '">' +
        '<button class="mgdb-pg-arch-head" type="button" data-role="arch-toggle" aria-expanded="false">' +
          '<span class="mgdb-pg-arch-count"><strong>' + number(arch.member_count) + '</strong>' +
            (arch.member_count === 1 ? 'member' : 'members') + '</span>' +
          '<span class="mgdb-pg-arch-string">' + esc(arch.domain_string || 'No domains') + '</span>' +
          '<span class="mgdb-pg-arch-chevron" aria-hidden="true"></span>' +
        '</button>' +
        '<div class="mgdb-pg-arch-ribbon" style="height:' + height + 'px">' +
          ribbon.html + labelsHtml(arch, packLanes(arch.blocks || [], Math.max(2, Math.round(drawnMax * 0.004)))) +
        '</div>' +
        '<p class="mgdb-pg-arch-caption">Drawn from <span class="mgdb-sequence">' + esc(rep.transcript || '') +
          '</span>' + (rep.is_exemplar ? ' <span class="mgdb-pill mgdb-pill-ok">Exemplar</span>' : '') +
          ' &middot; ' + number((arch.blocks || []).length) + ' domain' + ((arch.blocks || []).length === 1 ? '' : 's') +
          (arch.extent ? ' &middot; last ends at ' + number(arch.extent) + ' aa' : '') + '</p>' +
        '<div class="mgdb-pg-arch-members" hidden></div>' +
      '</li>';
    }

    function legendHtml() {
      if (totals.length < 2) { return ''; }
      var shown = totals.slice(0, palette.length);
      var out = '<ul class="mgdb-pg-arch-legend" aria-label="Domains">';
      shown.forEach(function (t) {
        out += '<li><span class="mgdb-pg-arch-swatch" style="background:' + colourOf(t.name) +
               '" aria-hidden="true"></span>' + esc(t.name) +
               ' <span class="mgdb-muted">' + number(t.member_count) + '</span></li>';
      });
      if (totals.length > shown.length) {
        out += '<li><span class="mgdb-pg-arch-swatch" style="background:' + NEUTRAL_DOMAIN +
               '" aria-hidden="true"></span>' + number(totals.length - shown.length) +
               ' other domain' + (totals.length - shown.length === 1 ? '' : 's') + '</li>';
      }
      return out + '</ul>';
    }

    function statusText() {
      var n = architectures.length;
      var one = n === 1;
      var txt = number(memberTotal) + ' member' + (memberTotal === 1 ? '' : 's') +
        (one ? ' share <strong>one</strong> architecture.'
             : ' carry <strong>' + number(n) + '</strong> distinct architectures.');
      if (!one && limit < n) {
        var covered = architectures.slice(0, limit).reduce(function (s2, a) { return s2 + a.member_count; }, 0);
        txt += ' The ' + number(limit) + ' commonest are shown, covering ' + number(covered) +
               ' of them.';
      }
      return txt;
    }

    function noteText(lastDrawn) {
      return 'Identical means the same domains in the same order with the same repeat counts. ' +
        'The axis is residue position. It runs past the last annotated domain of the architectures ' +
        'drawn (' + number(lastDrawn) + ' aa)' +
        (lastDrawn < recordMax
          ? '; the furthest domain anywhere in this pan-gene ends at ' + number(recordMax) + ' aa'
          : '') +
        ' &mdash; it is not the end of the protein, which the database does not record for every ' +
        'annotation. Domains are called by HMMscan and may overlap, so a ribbon is stacked into ' +
        'lanes where they do.';
    }

    var html = '<div class="mgdb-rec-block mgdb-pg-arch">' +
      '<div class="mgdb-rec-block-head">' +
        '<h3>Domain architectures</h3>' +
        '<div class="mgdb-pg-arch-tools">' + legendHtml() +
          '<button class="mgdb-rec-tsv" type="button" data-role="arch-png">Export PNG</button>' +
          '<button class="mgdb-rec-tsv" type="button" data-role="arch-tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
      '<p class="mgdb-fig-desc">Domain architecture ribbons, identical architectures collapsed.</p>' +
      '<p class="mgdb-rec-block-status" data-role="arch-status"></p>' +
      '<div data-role="arch-axis"></div>' +
      '<ol class="mgdb-pg-arch-list"></ol>' +
      (limit < architectures.length
        ? '<p class="mgdb-pg-arch-more"><button class="mgdb-button mgdb-button-secondary" type="button" data-role="arch-more">Show all ' +
          number(architectures.length) + ' architectures</button></p>' : '') +
      '<p class="mgdb-rec-block-status mgdb-pg-arch-note" data-role="arch-note"></p>' +
    '</div>';

    container.insertAdjacentHTML('beforeend', html);
    var block = container.lastElementChild;
    var list = block.querySelector('.mgdb-pg-arch-list');

    /* Draw (or redraw) the axis and every ribbon at the current scale. */
    function draw(count) {
      var shown = architectures.slice(0, count);
      /* Round the axis up to the next labelled tick. Without it the last
         domain of the longest architecture ends exactly at the axis end --
         on a pan-gene with one architecture that is the whole figure, and a
         block flush against the edge reads as a protein that stops there. */
      drawnMax = maxExtent(shown);
      var step = tickStep();
      drawnMax = Math.ceil(drawnMax / step) * step;
      block.querySelector('[data-role="arch-axis"]').innerHTML = axisHtml();
      list.innerHTML = shown.map(rowHtml).join('');
      block.querySelector('[data-role="arch-status"]').innerHTML =
        count >= architectures.length && architectures.length > 1
          ? number(memberTotal) + ' members carry <strong>' + number(architectures.length) +
            '</strong> distinct architectures, all shown.'
          : statusText();
      block.querySelector('[data-role="arch-note"]').innerHTML = noteText(maxExtent(shown));
    }

    draw(limit);

    function memberListHtml(arch) {
      return '<ul class="mgdb-pg-arch-member-list">' + arch.members.map(function (m) {
        return '<li><span class="mgdb-sequence">' + esc(m.transcript) + '</span> <span class="mgdb-muted">' +
               esc(m.assembly || 'Assembly not recorded') + '</span></li>';
      }).join('') + '</ul>';
    }

    list.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-role="arch-toggle"]');
      if (!btn) { return; }
      var row = btn.closest('[data-arch]');
      var arch = architectures[+row.getAttribute('data-arch')];
      var panel = row.querySelector('.mgdb-pg-arch-members');
      var open = btn.getAttribute('aria-expanded') === 'true';
      if (!open && !panel.innerHTML) { panel.innerHTML = memberListHtml(arch); }
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      panel.hidden = open;
    });

    var more = block.querySelector('[data-role="arch-more"]');
    if (more) {
      more.addEventListener('click', function () {
        if (expanded) { return; }
        expanded = true;
        /* A full redraw, not an append: the tail can reach further along the
           protein than the eight commonest do, and every ribbon has to stay on
           one scale. */
        draw(architectures.length);
        more.parentNode.removeChild(more);
        MGDB.announce('All ' + architectures.length + ' architectures shown.');
      });
    }

    /* Rows that hold none of the selected members step back, so picking a
       clade in the tree says which architectures it spans. */
    MGDB.panGeneSelection.subscribe(function (sel) {
      var set = {};
      if (sel) { sel.genes.forEach(function (g) { set[g] = true; }); }
      var any = !!sel;
      Array.prototype.forEach.call(list.querySelectorAll('[data-arch]'), function (row) {
        var arch = architectures[+row.getAttribute('data-arch')];
        var hit = any && arch.members.some(function (m) { return set[m.gene_model]; });
        row.classList.toggle('is-dim', any && !hit);
        row.classList.toggle('is-picked', !!hit);
      });
    });

    /* Redrawn at export size from the same blocks and the same lane packing,
       so what lands in the PNG is what is on screen rather than a screenshot
       of it. */
    block.querySelector('[data-role="arch-png"]').addEventListener('click', function () {
      var M = 24, W = 1180, LANE = 16, LANE_GAP = 5, PLOT = W - 2 * M;
      var shown = Array.prototype.map.call(list.querySelectorAll('[data-arch]'), function (row) {
        return architectures[+row.getAttribute('data-arch')];
      });
      if (!shown.length) { return; }
      function EX(residue) { return M + (residue / drawnMax) * PLOT; }
      var out = '';
      var y = M;
      out += xText(M, y + 6, 'Domain architectures', { size: 17, weight: 700, fill: '#1f2723' });
      y += 24;
      out += xText(M, y + 6, number(memberTotal) + ' members, ' + number(architectures.length) +
        ' distinct architectures; ' + number(shown.length) + ' shown',
        { size: 12, fill: '#5d6b62' });
      y += 24;

      totals.slice(0, palette.length).forEach(function (t, i) {
        var lx = M + i * 150;
        out += '<rect x="' + lx + '" y="' + (y - 8) + '" width="11" height="11" rx="3" fill="' +
          colourOf(t.name) + '"></rect>';
        out += xText(lx + 16, y + 1, t.name, { size: 10, fill: '#3d4a42' });
      });
      if (totals.length > palette.length) {
        var ox = M + palette.length * 150;
        out += '<rect x="' + ox + '" y="' + (y - 8) + '" width="11" height="11" rx="3" fill="' +
          NEUTRAL_DOMAIN + '"></rect>';
        out += xText(ox + 16, y + 1, (totals.length - palette.length) + ' other', { size: 10, fill: '#3d4a42' });
      }
      y += 22;

      /* Residue axis. */
      var step = tickStep();
      out += '<path d="M' + M + ' ' + (y + 4) + 'h' + PLOT + '" stroke="#d7d2c6" stroke-width="1" fill="none"></path>';
      for (var r = 0; r <= drawnMax + 0.5; r += step) {
        out += xText(EX(r), y, number(Math.round(r)), { size: 9, fill: '#7c837e', anchor: 'middle' });
      }
      y += 18;

      shown.forEach(function (arch) {
        var rep = arch.representative || {};
        out += xText(M, y + 8, number(arch.member_count) + (arch.member_count === 1 ? ' member' : ' members'),
          { size: 11, weight: 700, fill: '#1f2723' });
        var head = arch.domain_string || 'No domains';
        if (head.length > 120) { head = head.slice(0, 117) + '\u2026'; }
        out += xText(M + 80, y + 8, head, { size: 9, mono: true, fill: '#3d4a42' });
        y += 16;
        var lanes = packLanes(arch.blocks || [], Math.max(2, Math.round(drawnMax * 0.004)));
        lanes.forEach(function (lane, li) {
          var ly = y + li * (LANE + LANE_GAP);
          out += '<rect x="' + M + '" y="' + (ly + LANE / 2 - 1.5) + '" width="' + PLOT +
            '" height="3" rx="1.5" fill="#f3f1ea"></rect>';
          lane.forEach(function (b) {
            var x0 = EX(b.start), w = Math.max(EX(b.end) - x0, 2);
            out += '<rect x="' + x0.toFixed(2) + '" y="' + ly + '" width="' + w.toFixed(2) +
              '" height="' + LANE + '" rx="4" fill="' + colourOf(b.name) +
              '" stroke="#ffffff" stroke-width="1.5"></rect>';
            if (w > 44) {
              out += xText(x0 + w / 2, ly + LANE / 2 + 3.5, b.name,
                { size: 9, weight: 700, fill: '#ffffff', anchor: 'middle' });
            }
          });
        });
        y += lanes.length * LANE + (lanes.length - 1) * LANE_GAP + 6;
        out += xText(M, y + 6, 'Drawn from ' + (rep.transcript || '') +
          (rep.is_exemplar ? ' (exemplar)' : '') + ' \u00b7 ' + (arch.blocks || []).length + ' domains',
          { size: 9, fill: '#7c837e' });
        y += 18;
      });

      out += xText(M, y + 6, 'MaizeGDB \u00b7 axis is residue position to ' + number(drawnMax) + ' aa',
        { size: 10, fill: '#7c837e' });
      exportSvgToPng(out, W, y + 22,
        (spec.filename || 'pan-gene-architectures.tsv').replace(/\.tsv$/, '') + '.png');
    });

    block.querySelector('[data-role="arch-tsv"]').addEventListener('click', function () {
      var columns = [
        { label: 'Members', get: function (a) { return a.member_count; } },
        { label: 'Architecture', get: function (a) { return a.domain_string; } },
        { label: 'Domains', get: function (a) { return (a.blocks || []).length; } },
        { label: 'Drawn from', get: function (a) { return a.representative ? a.representative.transcript : ''; } },
        { label: 'Last domain ends (aa)', get: function (a) { return a.extent == null ? '' : a.extent; } },
        { label: 'Blocks', get: function (a) {
            return (a.blocks || []).map(function (b) { return b.name + ':' + b.start + '-' + b.end; }).join(' '); } },
        { label: 'Transcripts', get: function (a) {
            return a.members.map(function (m) { return m.transcript; }).join(', '); } }
      ];
      if (window.MGDBRecord && window.MGDBRecord.downloadTsv) {
        window.MGDBRecord.downloadTsv(spec.filename || 'pan-gene-architectures.tsv', columns, architectures);
      }
    });

    return { element: block };
  }

  MGDB.panGeneArchitectures = panGeneArchitectures;

  /* ------------------------------------------------------------------------
     Linked selection

     One set of gene models, shared by every figure on the record. A figure
     publishes a selection when the reader picks something in it and re-paints
     when anybody else publishes one, so picking a clade in the tree dims the
     presence cells and the architecture rows that are not in it.

     Gene models, not transcripts, are the currency: the presence strip knows
     members by gene model and the tree knows them by transcript, so whoever
     publishes resolves to the gene model first.
     ------------------------------------------------------------------------ */

  function makeSelection() {
    var subscribers = [];
    var current = null;
    return {
      subscribe: function (fn) { subscribers.push(fn); if (current) { fn(current); } },
      get: function () { return current; },
      /* payload = {genes[], label, source, filter}. `filter` is what the
         members table should be filtered to, which is not always the gene
         list: picking a presence cell with nine members filters by its
         annotation, not by nine names. */
      set: function (payload) {
        var list = payload && payload.genes ? payload.genes.filter(Boolean) : [];
        current = list.length ? {
          genes: list,
          label: payload.label || '',
          source: payload.source || '',
          filter: payload.filter == null ? '' : payload.filter
        } : null;
        subscribers.forEach(function (fn) { fn(current); });
      },
      clear: function () { this.set(null); }
    };
  }
  MGDB.panGeneSelection = MGDB.panGeneSelection || makeSelection();

  /* ------------------------------------------------------------------------
     Newick

     The pan-gene trees carry support values as internal node labels, branch
     lengths on every edge including zero ones, and polytomies -- the root has
     three children and identical proteins sit in multifurcations of up to
     nine. So the parser cannot assume a binary tree, and it has to keep a
     label that is a number as a support value rather than as a taxon name.
     ------------------------------------------------------------------------ */

  function parseNewick(text) {
    var s = String(text).trim();
    var end = s.lastIndexOf(';');
    if (end !== -1) { s = s.slice(0, end); }
    var pos = 0;

    function readToken() {
      var start = pos;
      while (pos < s.length && ':,()'.indexOf(s[pos]) === -1) { pos++; }
      return s.slice(start, pos).trim().replace(/^'|'$/g, '');
    }

    function readLength() {
      if (s[pos] !== ':') { return null; }
      pos++;
      var start = pos;
      while (pos < s.length && ':,()'.indexOf(s[pos]) === -1) { pos++; }
      var v = parseFloat(s.slice(start, pos));
      return isNaN(v) ? null : v;
    }

    function readNode() {
      var node = { name: null, support: null, length: null, children: [] };
      if (s[pos] === '(') {
        pos++;
        for (;;) {
          node.children.push(readNode());
          if (s[pos] === ',') { pos++; continue; }
          break;
        }
        if (s[pos] === ')') { pos++; }
        var label = readToken();
        /* After a ')' a bare number is a support value, not a name. */
        if (label !== '') {
          if (/^[0-9]*\.?[0-9]+$/.test(label)) { node.support = parseFloat(label); }
          else { node.name = label; }
        }
      } else {
        var name = readToken();
        node.name = name === '' ? null : name;
      }
      node.length = readLength();
      return node;
    }

    var root = readNode();
    return root;
  }

  /* ------------------------------------------------------------------------
     Interactive tree

     spec = {
       url:      sections.tree.url
       exemplar: the exemplar transcript
       resolve:  function (transcript) -> {gene, assembly, species, chr} or null
       panChr:   the pan-gene's chromosome
       filename: the TSV download name
     }
     ------------------------------------------------------------------------ */

  /* Six species, so six slots -- the whole point of colouring a pan-gene tree
     by species rather than by the assembly panel, which has seven groups and
     cannot be coloured safely. The order is fixed so a species keeps its
     colour between records. */
  var SPECIES_ORDER = [
    'Zea mays subsp. mays',
    'Zea mays subsp. mexicana',
    'Zea mays subsp. parviglumis',
    'Zea mays subsp. huehuetenangensis',
    'Zea diploperennis',
    'Zea nicaraguensis'
  ];
  var SPECIES_SHORT = {
    'Zea mays subsp. mays': 'Z. mays mays',
    'Zea mays subsp. mexicana': 'Z. mays mexicana',
    'Zea mays subsp. parviglumis': 'Z. mays parviglumis',
    'Zea mays subsp. huehuetenangensis': 'Z. mays huehuetenangensis',
    'Zea diploperennis': 'Z. diploperennis',
    'Zea nicaraguensis': 'Z. nicaraguensis'
  };

  function panGeneTree(container, spec) {
    if (!container || !spec || !spec.url) { return null; }

    /* PAD_LEFT keeps the root and the leftmost tips off the edge of the box;
       without it the top of the tree sat flush against the container. */
    var ROW = 14, PAD_TOP = 8, PAD_LEFT = 16, LABEL_W = 210, AXIS_H = 26;
    var resolve = spec.resolve || function () { return null; };
    var root = null, tree = null, mode = 'branch';
    var svgEl = null, scroller = null, detail = null;
    var tipsTotal = 0;
    var speciesPresent = {};

    container.insertAdjacentHTML('beforeend',
      /* No heading of its own: the section this renders into is already
         called Phylogenetic tree, and a second one under it read as two
         different things. */
      '<div class="mgdb-rec-block mgdb-pg-tree-block">' +
        '<div class="mgdb-rec-block-head is-headless">' +
          '<div class="mgdb-pg-tree-tools" data-role="tools" hidden>' +
            '<ul class="mgdb-pg-tree-legend" data-role="legend" aria-label="Species"></ul>' +
            '<div class="mgdb-pg-tree-buttons">' +
              '<button class="mgdb-rec-tsv" type="button" data-role="mode" aria-pressed="true">Branch lengths</button>' +
              '<button class="mgdb-rec-tsv" type="button" data-role="expand">Expand all</button>' +
              '<button class="mgdb-rec-tsv" type="button" data-role="tree-png">Export PNG</button>' +
              '<button class="mgdb-rec-tsv" type="button" data-role="tree-tsv">Download TSV</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<p class="mgdb-fig-desc">Interactive SVG tree \u0028d3-hierarchy\u0029, linked selection across figures.</p>' +
        '<p class="mgdb-rec-block-status" data-role="status">Loading the tree&hellip;</p>' +
        '<div class="mgdb-pg-tree-scroll" data-role="scroll" hidden></div>' +
        '<p class="mgdb-pg-tree-detail" data-role="tree-detail" aria-live="polite"></p>' +
      '</div>');

    var block = container.lastElementChild;
    var status = block.querySelector('[data-role="status"]');
    var tools = block.querySelector('[data-role="tools"]');
    scroller = block.querySelector('[data-role="scroll"]');
    detail = block.querySelector('[data-role="tree-detail"]');
    var idleDetail = 'Hover a tip for its member; click a node to collapse a clade, or a tip to select it.';
    detail.textContent = idleDetail;

    /* ---- geometry ------------------------------------------------------- */

    function visibleLeaves(node) {
      if (node.collapsed || !node.children.length) { return [node]; }
      var out = [];
      node.children.forEach(function (c) { out = out.concat(visibleLeaves(c)); });
      return out;
    }

    function eachNode(node, fn) {
      fn(node);
      node.children.forEach(function (c) { eachNode(c, fn); });
    }

    function tipCount(node) {
      if (!node.children.length) { return 1; }
      return node.children.reduce(function (n, c) { return n + tipCount(c); }, 0);
    }

    /* Depth in substitutions from the root, and the spread of a subtree --
       what the automatic collapse is decided on. */
    function measure(node, depth) {
      node.depth_len = depth + (node.length || 0);
      if (!node.children.length) { node.maxTip = node.depth_len; node.tips = 1; return; }
      node.maxTip = node.depth_len;
      node.tips = 0;
      node.children.forEach(function (c) {
        measure(c, node.depth_len);
        if (c.maxTip > node.maxTip) { node.maxTip = c.maxTip; }
        node.tips += c.tips;
      });
    }

    function layout() {
      var leaves = visibleLeaves(root);
      var height = Math.max(leaves.length * ROW, ROW);
      var h = window.d3.hierarchy(root, function (n) { return n.collapsed ? null : n.children; });
      window.d3.cluster().size([height - ROW, 100]).separation(function () { return 1; })(h);
      /* d3 gives the leaf ordering and the y; x is ours, from branch length
         or from depth when the reader asks for equal branches. */
      var maxDepth = 0, maxRank = 0;
      h.each(function (d) {
        if (d.data.depth_len > maxDepth) { maxDepth = d.data.depth_len; }
        if (d.depth > maxRank) { maxRank = d.depth; }
      });
      if (maxDepth <= 0) { maxDepth = 1; }
      if (maxRank <= 0) { maxRank = 1; }
      h.each(function (d) {
        d.data.py = d.x + ROW / 2 + PAD_TOP;
        d.data.px = mode === 'branch' ? (d.data.depth_len / maxDepth) : (d.depth / maxRank);
      });
      return { nodes: h, height: height + PAD_TOP * 2, leaves: leaves, maxDepth: maxDepth };
    }

    /* ---- drawing -------------------------------------------------------- */

    function speciesColour(species) {
      var i = SPECIES_ORDER.indexOf(species);
      var palette = (MGDB.CHART_COLORS || []).slice(0, SPECIES_ORDER.length);
      return i === -1 ? NEUTRAL_DOMAIN : palette[i];
    }

    function draw() {
      var L = layout();
      /* Drawn at real pixel size, measured from the box, rather than at a
         fixed viewBox scaled to fit. A viewBox scales the tip labels with it:
         at 390 px the 1,210-unit design shrank them to about 3 px and the tree
         became unreadable exactly where it needed to be readable most. At
         real size the text is always 9.5 px and the box scrolls instead. */
      var avail = (scroller.clientWidth || 900) - 2;
      var width = Math.max(avail, 560);
      var plotW = width - LABEL_W - PAD_LEFT;
      var height = L.height + AXIS_H;
      var parts = [];

      parts.push('<svg class="mgdb-pg-tree-svg" width="' + width + '" height="' + height +
        '" viewBox="0 0 ' + width + ' ' + height +
        '" role="img" aria-label="Phylogenetic tree of ' + tipsTotal + ' members">');

      function X(v) { return PAD_LEFT + v * plotW; }

      /* Elbow links, parent to child. */
      L.nodes.each(function (d) {
        if (!d.parent) { return; }
        var x0 = X(d.parent.data.px), x1 = X(d.data.px);
        var y0 = d.parent.data.py, y1 = d.data.py;
        parts.push('<path class="mgdb-pg-tree-link" d="M' + x0.toFixed(2) + ' ' + y0.toFixed(2) +
          'V' + y1.toFixed(2) + 'H' + x1.toFixed(2) + '"></path>');
      });

      L.nodes.each(function (d) {
        var n = d.data;
        var x = X(n.px), y = n.py;
        var isTip = !n.children.length;
        var collapsed = n.collapsed && n.children.length;

        if (collapsed) {
          var span = Math.min(n.tips * ROW, 26);
          var tipX = X(Math.min(1, n.px + (n.maxTip - n.depth_len) / L.maxDepth));
          parts.push('<g class="mgdb-pg-tree-node is-collapsed" data-id="' + n.id + '" tabindex="0" role="button">' +
            '<title>' + esc(n.tips + ' members, collapsed. Support ' +
              (n.support == null ? 'not given' : n.support.toFixed(3))) + '</title>' +
            '<path class="mgdb-pg-tree-wedge" d="M' + x.toFixed(2) + ' ' + y.toFixed(2) +
              'L' + tipX.toFixed(2) + ' ' + (y - span / 2).toFixed(2) +
              'L' + tipX.toFixed(2) + ' ' + (y + span / 2).toFixed(2) + 'Z"></path>' +
            '</g>');
          parts.push('<text class="mgdb-pg-tree-label is-clade" x="' + (tipX + 6).toFixed(2) +
            '" y="' + (y + 3.5).toFixed(2) + '">' + n.tips + ' members</text>');
          return;
        }

        if (isTip) {
          var info = resolve(n.name) || {};
          var off = spec.panChr && info.chr && chrKey(info.chr) !== null &&
                    chrKey(info.chr) !== chrKey(spec.panChr);
          var isExemplar = n.name === spec.exemplar;
          /* The tree file names its tips by transcript. A reader works in gene
             models -- it is what the members table, the presence strip and
             every link on the record use -- so Zm00001eb056510_T002 is shown
             as Zm00001eb056510. The transcript stays on the element, since it
             is still the key back into the tree's own data. */
          var shown = info.gene || n.name;
          parts.push('<g class="mgdb-pg-tree-tip' + (isExemplar ? ' is-exemplar' : '') +
              '" data-id="' + n.id + '" data-gene="' + esc(info.gene || '') +
              '" data-transcript="' + esc(n.name) + '" tabindex="0" role="button">' +
            '<title>' + esc(shown + (info.assembly ? '\n' + info.assembly : '') +
              (info.species ? '\n' + info.species : '') + (info.chr ? '\nChromosome ' + info.chr : '') +
              '\nTranscript ' + n.name) + '</title>' +
            '<circle class="mgdb-pg-tree-dot" cx="' + x.toFixed(2) + '" cy="' + y.toFixed(2) +
              '" r="' + (isExemplar ? 4.5 : 3.2) + '" fill="' + speciesColour(info.species) + '"></circle>' +
            (off ? '<circle class="mgdb-pg-tree-off" cx="' + (x + 5).toFixed(2) + '" cy="' + (y - 4).toFixed(2) +
              '" r="2.4"></circle>' : '') +
            '<text class="mgdb-pg-tree-label" x="' + (x + 9).toFixed(2) + '" y="' + (y + 3.5).toFixed(2) + '">' +
              esc(shown) + '</text>' +
            '</g>');
          return;
        }

        /* An internal node: the handle that collapses its clade. */
        parts.push('<g class="mgdb-pg-tree-node" data-id="' + n.id + '" tabindex="0" role="button">' +
          '<title>' + esc('Collapse ' + n.tips + ' members. Support ' +
            (n.support == null ? 'not given' : n.support.toFixed(3))) + '</title>' +
          '<circle class="mgdb-pg-tree-handle" cx="' + x.toFixed(2) + '" cy="' + y.toFixed(2) + '" r="3.6"></circle>' +
          '</g>');
        if (n.support != null && n.support >= 0.9 && n.tips >= 4) {
          parts.push('<text class="mgdb-pg-tree-support" x="' + (x - 5).toFixed(2) +
            '" y="' + (y - 4).toFixed(2) + '">' + n.support.toFixed(2) + '</text>');
        }
      });

      /* Scale bar, in substitutions per site -- the only thing that says how
         much of the width is real distance. */
      if (mode === 'branch') {
        var barVal = niceStep(L.maxDepth / 4);
        var barPx = (barVal / L.maxDepth) * plotW;
        var by = L.height + 14;
        parts.push('<g class="mgdb-pg-tree-scale">' +
          '<path d="M' + PAD_LEFT + ' ' + by + 'h' + barPx.toFixed(2) + '"></path>' +
          '<path d="M' + PAD_LEFT + ' ' + (by - 3) + 'v6"></path>' +
          '<path d="M' + (PAD_LEFT + barPx).toFixed(2) + ' ' + (by - 3) + 'v6"></path>' +
          '<text x="' + (PAD_LEFT + barPx + 8).toFixed(2) + '" y="' + (by + 4) + '">' + barVal +
            ' substitutions per site</text>' +
          '</g>');
      }

      parts.push('</svg>');
      scroller.innerHTML = parts.join('');
      svgEl = scroller.firstChild;
      applySelection(MGDB.panGeneSelection.get());
    }

    function niceStep(raw) {
      if (!(raw > 0)) { return 0.01; }
      var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
      var steps = [1, 2, 2.5, 5, 10];
      for (var i = 0; i < steps.length; i++) {
        if (mag * steps[i] >= raw) { return +(mag * steps[i]).toPrecision(2); }
      }
      return +(mag * 10).toPrecision(2);
    }

    /* ---- state ---------------------------------------------------------- */

    function statusText() {
      /* Count the folds a reader can actually see. A collapsed clade inside
         another collapsed clade is not drawn, and counting those said "60
         clades collapsed" over a tree showing 24 of them. */
      var visible = visibleLeaves(root);
      var folds = visible.filter(function (n) { return n.collapsed && n.children.length; }).length;
      var tipsShown = visible.length - folds;
      return number(tipsTotal) + ' members. ' + number(tipsShown) + ' shown' +
        (folds ? ', with ' + number(folds) + ' clade' + (folds === 1 ? '' : 's') +
          ' of near-identical sequences folded &mdash; click one to open it' : '') + '.';
    }

    function refreshStatus() { status.innerHTML = statusText(); }

    function nodeById(id) {
      var found = null;
      eachNode(root, function (n) { if (n.id === id) { found = n; } });
      return found;
    }

    function genesUnder(node) {
      var out = [];
      eachNode(node, function (n) {
        if (!n.children.length && n.name) {
          var info = resolve(n.name);
          if (info && info.gene) { out.push(info.gene); }
        }
      });
      return out;
    }

    function applySelection(sel) {
      if (!svgEl) { return; }
      var set = {};
      if (sel) { sel.genes.forEach(function (g) { set[g] = true; }); }
      var any = !!sel;
      Array.prototype.forEach.call(svgEl.querySelectorAll('.mgdb-pg-tree-tip'), function (g) {
        var gene = g.getAttribute('data-gene');
        var hit = any && gene && set[gene];
        g.classList.toggle('is-dim', any && !hit);
        g.classList.toggle('is-picked', !!hit);
      });
      /* A member picked elsewhere may sit inside a folded clade, where there
         is no tip to light up. Mark the fold instead, so the tree still
         answers where the member is rather than looking as if it has none. */
      Array.prototype.forEach.call(svgEl.querySelectorAll('.mgdb-pg-tree-node.is-collapsed'), function (g) {
        var node = nodeById(+g.getAttribute('data-id'));
        var hit = any && node && genesUnder(node).some(function (gene) { return set[gene]; });
        g.classList.toggle('is-dim', any && !hit);
        g.classList.toggle('is-picked', !!hit);
      });
    }

    MGDB.panGeneSelection.subscribe(applySelection);

    /* The drawing is sized in pixels, so a width change needs a redraw.
       A ResizeObserver on the box rather than a window resize listener: the
       box can change width on its own -- a section opening, the record
       reflowing -- and a window resize is not always delivered. */
    var lastWidth = 0;
    function onBoxResize() {
      if (!root || !scroller.clientWidth) { return; }
      if (Math.abs(scroller.clientWidth - lastWidth) < 8) { return; }
      lastWidth = scroller.clientWidth;
      draw();
    }
    var debounced = MGDB.debounce ? MGDB.debounce(onBoxResize, 150) : onBoxResize;
    /* Both, as on the alignment: an observer alone missed a viewport change
       and left the drawing at its old width. */
    if (window.ResizeObserver) { new window.ResizeObserver(debounced).observe(scroller); }
    window.addEventListener('resize', debounced);

    /* ---- events --------------------------------------------------------- */

    scroller.addEventListener('click', function (event) {
      var tip = event.target.closest('.mgdb-pg-tree-tip');
      if (tip) {
        var gene = tip.getAttribute('data-gene');
        var picked = MGDB.panGeneSelection.get();
        if (picked && picked.genes.length === 1 && picked.genes[0] === gene) {
          MGDB.panGeneSelection.clear();
        } else if (gene) {
          MGDB.panGeneSelection.set({ genes: [gene], label: gene, source: 'tree', filter: gene });
        }
        return;
      }
      var handle = event.target.closest('[data-id]');
      if (!handle) { return; }
      var node = nodeById(+handle.getAttribute('data-id'));
      if (!node || !node.children.length) { return; }
      if (event.altKey || event.shiftKey) {
        /* Alt- or shift-click picks the clade instead of folding it. */
        var genes = genesUnder(node);
        MGDB.panGeneSelection.set({ genes: genes, label: genes.length + ' members in a clade',
                                    source: 'tree', filter: '' });
        return;
      }
      node.collapsed = !node.collapsed;
      draw();
      refreshStatus();
    });

    scroller.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' && event.key !== ' ') { return; }
      var target = event.target.closest('[data-id]');
      if (!target) { return; }
      event.preventDefault();
      target.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    function describeTip(g) {
      var transcript = g.getAttribute('data-transcript');
      var info = resolve(transcript) || {};
      var shown = info.gene || transcript;
      /* The gene model, linked to its own record where it has one. */
      var head = info.html
        ? '<a href="' + esc(info.html) + '"><span class="mgdb-sequence">' + esc(shown) + '</span></a>'
        : '<span class="mgdb-sequence">' + esc(shown) + '</span>';
      var bits = [head];
      if (info.assembly) { bits.push('<span class="mgdb-muted">' + esc(info.assembly) + '</span>'); }
      if (info.species) { bits.push('<em>' + esc(info.species) + '</em>'); }
      if (info.chr) {
        var off = spec.panChr && chrKey(info.chr) !== chrKey(spec.panChr);
        bits.push('<span class="' + (off ? 'mgdb-pill mgdb-pill-warn' : 'mgdb-muted') + '">' +
          esc(info.chr) + '</span>');
      }
      if (transcript === spec.exemplar) { bits.push('<span class="mgdb-pill mgdb-pill-ok">Exemplar</span>'); }
      detail.innerHTML = bits.join(' &middot; ');
    }

    scroller.addEventListener('mouseover', function (event) {
      var tip = event.target.closest('.mgdb-pg-tree-tip');
      if (tip) { describeTip(tip); }
    });
    scroller.addEventListener('mouseout', function (event) {
      if (event.target.closest('.mgdb-pg-tree-tip')) { detail.textContent = idleDetail; }
    });

    block.querySelector('[data-role="mode"]').addEventListener('click', function () {
      mode = mode === 'branch' ? 'equal' : 'branch';
      this.textContent = mode === 'branch' ? 'Branch lengths' : 'Equal branches';
      this.setAttribute('aria-pressed', mode === 'branch' ? 'true' : 'false');
      draw();
    });

    block.querySelector('[data-role="expand"]').addEventListener('click', function () {
      var anyCollapsed = false;
      eachNode(root, function (n) { if (n.collapsed) { anyCollapsed = true; } });
      eachNode(root, function (n) { n.collapsed = anyCollapsed ? false : autoCollapseMark(n); });
      this.textContent = anyCollapsed
        ? (collapseSpread > 0 ? 'Collapse tight clades' : 'Collapse identical')
        : 'Expand all';
      draw();
      refreshStatus();
    });

    /* The tree is already an <svg>, so the export takes a copy of the live one
       with its styling written onto the elements, and adds a title and the
       species key above it. Nothing here re-implements the drawing, so the
       PNG cannot drift from the page. */
    block.querySelector('[data-role="tree-png"]').addEventListener('click', function () {
      if (!svgEl) { return; }
      var M = 24, HEAD = 74;
      var w = +svgEl.getAttribute('width');
      var h = +svgEl.getAttribute('height');
      var clone = inlineSvgStyles(svgEl);
      clone.setAttribute('x', M);
      clone.setAttribute('y', HEAD);
      var out = xText(M, M + 6, 'Phylogenetic tree', { size: 17, weight: 700, fill: '#1f2723' });
      out += xText(M, M + 26, tipsTotal + ' members' +
        (spec.exemplar ? ' \u00b7 exemplar ' + spec.exemplar : ''), { size: 12, fill: '#5d6b62' });
      var lx = M;
      SPECIES_ORDER.forEach(function (sp) {
        if (!speciesPresent[sp]) { return; }
        out += '<circle cx="' + (lx + 5) + '" cy="' + (M + 44) + '" r="5" fill="' +
          speciesColour(sp) + '"></circle>';
        var label = (SPECIES_SHORT[sp] || sp) + ' ' + speciesPresent[sp];
        out += xText(lx + 15, M + 48, label, { size: 10, fill: '#3d4a42' });
        lx += 15 + label.length * 5.4 + 16;
      });
      out += new XMLSerializer().serializeToString(clone);
      out += xText(M, HEAD + h + 18, 'MaizeGDB \u00b7 ' +
        (mode === 'branch' ? 'branch lengths in substitutions per site' : 'equal branches'),
        { size: 10, fill: '#7c837e' });
      exportSvgToPng(out, w + 2 * M, HEAD + h + 30,
        (spec.filename || 'pan-gene-tree.tsv').replace(/\.tsv$/, '') + '.png');
    });

    block.querySelector('[data-role="tree-tsv"]').addEventListener('click', function () {
      var rows = [];
      eachNode(root, function (n) {
        if (n.children.length || !n.name) { return; }
        var info = resolve(n.name) || {};
        rows.push({ transcript: n.name, gene: info.gene || '', assembly: info.assembly || '',
                    species: info.species || '', chr: info.chr || '',
                    depth: n.depth_len == null ? '' : n.depth_len.toPrecision(6) });
      });
      var columns = [
        { label: 'Transcript', get: function (r) { return r.transcript; } },
        { label: 'Gene model', get: function (r) { return r.gene; } },
        { label: 'Assembly', get: function (r) { return r.assembly; } },
        { label: 'Species', get: function (r) { return r.species; } },
        { label: 'Chromosome', get: function (r) { return r.chr; } },
        { label: 'Distance from root', get: function (r) { return r.depth; } }
      ];
      if (window.MGDBRecord && window.MGDBRecord.downloadTsv) {
        window.MGDBRecord.downloadTsv(spec.filename || 'pan-gene-tree.tsv', columns, rows);
      }
    });

    /* A clade whose members are all but identical carries no shape worth the
       vertical space: on rp1, 146 of 525 branches are zero length. Those are
       collapsed on the first draw of a large tree and can be opened one at a
       time, or all at once. */
    var collapseSpread = 0;
    function autoCollapseMark(n) {
      return !!(n.children.length && n.tips >= 3 &&
                (n.maxTip - n.depth_len) <= collapseSpread);
    }

    function legendHtml(present) {
      var out = '';
      SPECIES_ORDER.forEach(function (sp) {
        if (!present[sp]) { return; }
        out += '<li><span class="mgdb-pg-tree-swatch" style="background:' + speciesColour(sp) +
               '" aria-hidden="true"></span>' + esc(SPECIES_SHORT[sp] || sp) +
               ' <span class="mgdb-muted">' + number(present[sp]) + '</span></li>';
      });
      return out;
    }

    /* ---- load ----------------------------------------------------------- */

    fetch(spec.url, { credentials: 'omit' })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.text();
      })
      .then(function (text) {
        if (!window.d3 || !window.d3.cluster) { throw new Error('d3-hierarchy not loaded'); }
        root = parseNewick(text);
        var id = 0;
        eachNode(root, function (n) { n.id = id++; n.collapsed = false; });
        measure(root, 0);
        tipsTotal = root.tips;

        speciesPresent = {};
        eachNode(root, function (n) {
          if (n.children.length || !n.name) { return; }
          var info = resolve(n.name);
          var sp = (info && info.species) || 'Unknown';
          speciesPresent[sp] = (speciesPresent[sp] || 0) + 1;
        });

        /* Only a big tree starts collapsed; a 65-tip one fits as it is. */
        if (tipsTotal > 120) {
          collapseSpread = root.maxTip * 0.02;
          eachNode(root, function (n) { n.collapsed = autoCollapseMark(n); });
          block.querySelector('[data-role="expand"]').textContent = 'Expand all';
        } else {
          /* Nothing is folded on a small tree, so the button's job is the
             other one. Saying "Expand all" over a fully expanded tree and then
             collapsing on click is what it used to do. */
          block.querySelector('[data-role="expand"]').textContent = 'Collapse identical';
        }

        block.querySelector('[data-role="legend"]').innerHTML = legendHtml(speciesPresent);
        tools.hidden = false;
        scroller.hidden = false;
        lastWidth = scroller.clientWidth;
        draw();
        refreshStatus();
      })
      .catch(function (error) {
        status.innerHTML = 'The tree could not be loaded. ' +
          '<a href="' + esc(spec.url) + '" target="_blank" rel="noopener">Open the Newick file</a>.';
        if (window.console && console.warn) { console.warn('pan-gene tree:', error); }
      });

    return { element: block };
  }

  MGDB.panGeneTree = panGeneTree;

  /* ------------------------------------------------------------------------
     Conservation profile and multiple sequence alignment

     spec = {
       proteinUrl, cdsUrl: the aligned FASTA files (CORS-readable from here)
       exemplar:  the exemplar transcript
       resolve:   function (transcript) -> {gene, assembly, species, chr, html}
       treeUrl:   the Newick, for tree order
       domains:   sections.domains, for the exemplar's domain track and the
                  same domain colours the ribbons use
       panGene:   the internal pan-gene name, for file names
     }

     A windowed canvas: the drawing is one canvas the size of the visible box,
     pinned with position: sticky inside a sizer as large as the whole
     alignment, so the browser supplies real scrollbars, touch scrolling and
     keyboard scrolling, and each frame paints only the cells in view. rp1's
     protein alignment is 301 x 3,564 = 1.07 million residues and its CDS
     alignment 3.2 million; a frame costs the same few thousand cells either
     way.
     ------------------------------------------------------------------------ */

  /* Six residue classes, in the six palette slots validated for this page,
     assigned so the families land near their Clustal colours: hydrophobic
     blue, positive red, negative magenta, polar green, H/Y cyan, G/P orange.
     Every cell also carries its letter once the zoom allows. */
  var RESIDUE_CLASS = {};
  'AILMFWVC'.split('').forEach(function (c) { RESIDUE_CLASS[c] = '#0072B2'; });
  'KR'.split('').forEach(function (c) { RESIDUE_CLASS[c] = '#D55E00'; });
  'DE'.split('').forEach(function (c) { RESIDUE_CLASS[c] = '#CC79A7'; });
  'NQST'.split('').forEach(function (c) { RESIDUE_CLASS[c] = '#009E73'; });
  'HY'.split('').forEach(function (c) { RESIDUE_CLASS[c] = '#56B4E9'; });
  'GP'.split('').forEach(function (c) { RESIDUE_CLASS[c] = '#E69F00'; });
  var NUCLEOTIDE_CLASS = { A: '#009E73', C: '#0072B2', G: '#E69F00', T: '#D55E00', U: '#D55E00' };

  var MSA_ZOOM = [
    { cw: 1, rh: 4 }, { cw: 3, rh: 7 }, { cw: 6, rh: 11 }, { cw: 10, rh: 15 }, { cw: 14, rh: 18 }
  ];

  function parseFasta(text) {
    var names = [], seqs = [], cur = null;
    String(text).split(/\r?\n/).forEach(function (line) {
      if (line.charAt(0) === '>') {
        if (cur !== null) { seqs.push(cur.join('').toUpperCase()); }
        names.push(line.slice(1).trim().split(/\s+/)[0]);
        cur = [];
      } else if (cur !== null) {
        cur.push(line.replace(/\s+/g, ''));
      }
    });
    if (cur !== null) { seqs.push(cur.join('').toUpperCase()); }
    var width = 0;
    seqs.forEach(function (s) { if (s.length > width) { width = s.length; } });
    return { names: names, seqs: seqs, width: width };
  }

  /* Per column: occupancy (share of sequences with a residue there), the
     consensus residue, and conservation (share of ALL sequences carrying that
     consensus). Conservation can never exceed occupancy, which is why the
     profile draws it inside the occupancy band: the gap between the two is
     the variation among the sequences that have the column at all. */
  function profile(aln) {
    var W = aln.width, N = aln.seqs.length;
    var counts = new Uint16Array(W * 27);   /* A..Z, and 26 for anything else */
    aln.seqs.forEach(function (s) {
      for (var c = 0; c < s.length; c++) {
        var code = s.charCodeAt(c);
        if (code === 45 || code === 46) { continue; }          /* '-' '.' */
        var k = code >= 65 && code <= 90 ? code - 65 : 26;
        counts[c * 27 + k]++;
      }
    });
    var occ = new Float32Array(W), cons = new Float32Array(W), consensus = new Uint8Array(W);
    for (var c = 0; c < W; c++) {
      var best = 0, bestK = 26, total = 0;
      for (var k = 0; k < 27; k++) {
        var n = counts[c * 27 + k];
        total += n;
        if (n > best && k < 26) { best = n; bestK = k; }
      }
      occ[c] = N ? total / N : 0;
      cons[c] = N ? best / N : 0;
      consensus[c] = bestK < 26 ? 65 + bestK : 0;
    }
    return { occ: occ, cons: cons, consensus: consensus };
  }

  function panGeneMsa(container, spec) {
    if (!container || !spec || !(spec.proteinUrl || spec.cdsUrl)) { return null; }

    var resolve = spec.resolve || function () { return null; };
    var cache = {};                 /* kind -> {aln, prof, text} */
    var kind = spec.proteinUrl ? 'protein' : 'cds';
    var zoom = 3;
    var colourMode = 'conservation';
    var sortMode = 'tree';
    var treeOrder = null;           /* transcript -> leaf index */
    var order = [];
    var current = null;             /* cache[kind] */
    var hover = null;               /* {row, col} */
    var selected = {};
    var raf = 0;

    var HEADER_RULER = 18, LANE_H = 7, LANE_GAP = 2;
    var GREEN = '#2f6b3f', INK = '#1f2723', MUTED = '#7c837e', LINE = '#d7d2c6';

    container.insertAdjacentHTML('beforeend',
      '<div class="mgdb-rec-block mgdb-pg-msa-block">' +
        '<div class="mgdb-rec-block-head is-headless">' +
          '<div class="mgdb-pg-msa-tools" data-role="tools" hidden>' +
            '<div class="mgdb-view-toggle" role="group" aria-label="Alignment">' +
              (spec.proteinUrl ? '<button class="mgdb-view-btn" type="button" data-kind="protein" aria-pressed="true">Protein</button>' : '') +
              (spec.cdsUrl ? '<button class="mgdb-view-btn" type="button" data-kind="cds" aria-pressed="' +
                (spec.proteinUrl ? 'false' : 'true') + '">CDS</button>' : '') +
            '</div>' +
            '<label>Colour <select data-role="colour" aria-label="Colour the alignment by">' +
              '<option value="conservation">Conservation</option>' +
              '<option value="residue">Residue class</option>' +
              '<option value="plain">None</option>' +
            '</select></label>' +
            '<label>Order <select data-role="sort" aria-label="Order the sequences by">' +
              '<option value="tree">Tree</option>' +
              '<option value="file">Alignment file</option>' +
              '<option value="name">Gene model</option>' +
              '<option value="conservation">Identity to consensus</option>' +
            '</select></label>' +
            '<div class="mgdb-pg-msa-zoom" role="group" aria-label="Zoom">' +
              '<button class="mgdb-rec-tsv" type="button" data-role="zoom-out" aria-label="Zoom out">&minus;</button>' +
              '<button class="mgdb-rec-tsv" type="button" data-role="zoom-in" aria-label="Zoom in">+</button>' +
            '</div>' +
            '<button class="mgdb-rec-tsv" type="button" data-role="msa-png">Export PNG</button>' +
            '<button class="mgdb-rec-tsv" type="button" data-role="msa-fasta">Download FASTA</button>' +
          '</div>' +
        '</div>' +
        '<p class="mgdb-fig-desc">Conservation profile and multiple sequence alignment on a windowed canvas, ' +
          'linked selection across figures.</p>' +
        '<p class="mgdb-rec-block-status" data-role="msa-status">Loading the alignment&hellip;</p>' +
        '<div class="mgdb-pg-msa-overview" data-role="overview" hidden>' +
          '<canvas data-role="overview-canvas" role="img" aria-label="Conservation profile"></canvas>' +
        '</div>' +
        '<div class="mgdb-pg-msa-scroll" data-role="scroll" tabindex="0" hidden ' +
          'aria-label="Multiple sequence alignment. Scroll to move through it.">' +
          '<div class="mgdb-pg-msa-sizer" data-role="sizer">' +
            '<canvas class="mgdb-pg-msa-canvas" data-role="canvas" role="img"></canvas>' +
          '</div>' +
        '</div>' +
        '<p class="mgdb-pg-msa-detail" data-role="msa-detail" aria-live="polite"></p>' +
      '</div>');

    var block = container.lastElementChild;
    var statusEl = block.querySelector('[data-role="msa-status"]');
    var tools = block.querySelector('[data-role="tools"]');
    var overviewWrap = block.querySelector('[data-role="overview"]');
    var overview = block.querySelector('[data-role="overview-canvas"]');
    var scroller = block.querySelector('[data-role="scroll"]');
    var sizer = block.querySelector('[data-role="sizer"]');
    var canvas = block.querySelector('[data-role="canvas"]');
    var detail = block.querySelector('[data-role="msa-detail"]');
    var idleDetail = 'Hover the alignment for a column’s consensus and conservation; click a row to select ' +
      'that gene model in every figure. Click or drag the profile to move along the alignment.';
    detail.textContent = idleDetail;

    (function readTheme() {
      var cs = window.getComputedStyle(block);
      GREEN = (cs.getPropertyValue('--mgdb-green') || '').trim() || GREEN;
      INK = (cs.getPropertyValue('--mgdb-ink') || '').trim() || INK;
      MUTED = (cs.getPropertyValue('--mgdb-muted') || '').trim() || MUTED;
      LINE = (cs.getPropertyValue('--mgdb-line') || '').trim() || LINE;
    })();

    /* ---- the exemplar's domains, in alignment columns ------------------ */

    var domainColours = {};
    (function () {
      var totals = (spec.domains && spec.domains.domain_totals) || [];
      var palette = (MGDB.CHART_COLORS || []).slice(0, COLOURED_DOMAINS);
      totals.slice(0, palette.length).forEach(function (t, i) { domainColours[t.name] = palette[i]; });
    })();

    function exemplarBlocks() {
      var archs = (spec.domains && spec.domains.architectures) || [];
      for (var i = 0; i < archs.length; i++) {
        var rep = archs[i].representative;
        if (rep && rep.is_exemplar) { return archs[i].blocks || []; }
      }
      return [];
    }

    /* Domain coordinates are residue positions in the exemplar's own protein;
       the alignment has gaps in it, so each one is walked across to the column
       it sits in. Protein only -- on the CDS alignment a residue is three
       columns and the track is not drawn. */
    function domainLanes() {
      if (kind !== 'protein' || !current) { return []; }
      var ix = current.aln.names.indexOf(spec.exemplar);
      if (ix === -1) { return []; }
      var s = current.aln.seqs[ix];
      var colOf = [], r = 0;
      for (var c = 0; c < s.length; c++) {
        var code = s.charCodeAt(c);
        if (code !== 45 && code !== 46) { colOf[r++] = c; }
      }
      var mapped = exemplarBlocks().map(function (b) {
        var a = colOf[b.start - 1], z = colOf[Math.min(b.end, r) - 1];
        return a == null || z == null ? null : { name: b.name, start: a, end: z, rs: b.start, re: b.end };
      }).filter(Boolean);
      return packLanes(mapped, 2);
    }

    /* ---- load ------------------------------------------------------------ */

    function load(which) {
      if (cache[which]) { return Promise.resolve(cache[which]); }
      var url = which === 'protein' ? spec.proteinUrl : spec.cdsUrl;
      return fetch(url, { credentials: 'omit' })
        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
        .then(function (text) {
          var aln = parseFasta(text);
          if (!aln.seqs.length) { throw new Error('empty alignment'); }
          cache[which] = { aln: aln, prof: profile(aln), text: text };
          return cache[which];
        });
    }

    function loadTreeOrder() {
      if (!spec.treeUrl || !MGDB.parseNewick) { return Promise.resolve(null); }
      return fetch(spec.treeUrl, { credentials: 'omit' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (text) {
          if (!text) { return null; }
          var idx = {}, n = 0;
          (function walk(node) {
            if (!node.children.length) { if (node.name) { idx[node.name] = n++; } return; }
            node.children.forEach(walk);
          })(MGDB.parseNewick(text));
          return idx;
        })
        .catch(function () { return null; });
    }

    /* ---- ordering -------------------------------------------------------- */

    function labelOf(i) {
      var name = current.aln.names[i];
      var info = resolve(name);
      return info && info.gene ? info.gene : name;
    }

    function identityToConsensus(i) {
      var s = current.aln.seqs[i], cons = current.prof.consensus, hit = 0, n = 0;
      for (var c = 0; c < s.length; c++) {
        var code = s.charCodeAt(c);
        if (code === 45 || code === 46) { continue; }
        n++;
        if (code === cons[c]) { hit++; }
      }
      return n ? hit / n : 0;
    }

    function reorder() {
      var n = current.aln.seqs.length;
      order = [];
      for (var i = 0; i < n; i++) { order.push(i); }
      if (sortMode === 'tree' && treeOrder) {
        order.sort(function (a, b) {
          var x = treeOrder[current.aln.names[a]], y = treeOrder[current.aln.names[b]];
          if (x == null && y == null) { return a - b; }
          if (x == null) { return 1; }
          if (y == null) { return -1; }
          return x - y;
        });
      } else if (sortMode === 'name') {
        order.sort(function (a, b) { return labelOf(a).localeCompare(labelOf(b)); });
      } else if (sortMode === 'conservation') {
        var score = {};
        order.forEach(function (i) { score[i] = identityToConsensus(i); });
        order.sort(function (a, b) { return score[b] - score[a]; });
      }
    }

    /* ---- geometry -------------------------------------------------------- */

    function gutter() { return scroller.clientWidth < 520 ? 104 : 136; }
    function Z() { return MSA_ZOOM[zoom]; }

    function headerHeight() {
      var lanes = domainLanes().length;
      return HEADER_RULER + (lanes ? lanes * (LANE_H + LANE_GAP) + 4 : 0);
    }

    function layout() {
      var z = Z(), rows = current.aln.seqs.length, H = headerHeight();
      var content = H + rows * z.rh;
      var maxBox = Math.min(Math.round(window.innerHeight * 0.62), 560);
      scroller.style.height = Math.min(content + 2, Math.max(maxBox, 160)) + 'px';
      sizer.style.width = (gutter() + current.aln.width * z.cw) + 'px';
      sizer.style.height = content + 'px';
      sizeCanvas(canvas, scroller.clientWidth, scroller.clientHeight);
      sizeCanvas(overview, overviewWrap.clientWidth, overviewHeight());
    }

    function overviewHeight() {
      var lanes = domainLanes().length;
      return 58 + (lanes ? lanes * (LANE_H + LANE_GAP) + 6 : 0);
    }

    function sizeCanvas(cv, w, h) {
      var dpr = window.devicePixelRatio || 1;
      cv.style.width = w + 'px';
      cv.style.height = h + 'px';
      cv.width = Math.max(1, Math.round(w * dpr));
      cv.height = Math.max(1, Math.round(h * dpr));
      cv.getContext('2d').setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function colourOfDomain(name) { return domainColours[name] || NEUTRAL_DOMAIN; }

    /* ---- the conservation profile, across the whole alignment ------------ */

    function drawOverview() {
      var ctx = overview.getContext('2d');
      var W = overviewWrap.clientWidth, H = overviewHeight();
      var width = current.aln.width, prof = current.prof;
      var plotH = 46, top = 4;
      ctx.clearRect(0, 0, W, H);
      /* One pixel column per slice of the alignment: the mean of each track
         across the columns that pixel covers. */
      for (var x = 0; x < W; x++) {
        var c0 = Math.floor(x * width / W), c1 = Math.max(c0 + 1, Math.floor((x + 1) * width / W));
        var o = 0, v = 0;
        for (var c = c0; c < c1; c++) { o += prof.occ[c]; v += prof.cons[c]; }
        o /= (c1 - c0); v /= (c1 - c0);
        ctx.fillStyle = '#e8e4da';
        ctx.fillRect(x, top + plotH * (1 - o), 1, plotH * o);
        ctx.fillStyle = GREEN;
        ctx.fillRect(x, top + plotH * (1 - v), 1, plotH * v);
      }
      ctx.fillStyle = LINE;
      ctx.fillRect(0, top + plotH, W, 1);

      var lanes = domainLanes();
      lanes.forEach(function (lane, li) {
        var y = top + plotH + 6 + li * (LANE_H + LANE_GAP);
        lane.forEach(function (b) {
          var x0 = b.start / width * W, x1 = (b.end + 1) / width * W;
          ctx.fillStyle = colourOfDomain(b.name);
          roundRect(ctx, x0, y, Math.max(x1 - x0, 2), LANE_H, 2);
          ctx.fill();
        });
      });

      /* Where the alignment box is looking. */
      var z = Z(), dataW = scroller.clientWidth - gutter();
      var vx0 = scroller.scrollLeft / z.cw / width * W;
      var vw = Math.min(W, dataW / z.cw / width * W);
      ctx.fillStyle = 'rgba(11, 87, 164, 0.10)';
      ctx.fillRect(vx0, 0, Math.max(vw, 2), H);
      ctx.strokeStyle = '#0b57a4';
      ctx.lineWidth = 1.5;
      ctx.strokeRect(vx0 + 0.75, 0.75, Math.max(vw, 2) - 1.5, H - 1.5);
    }

    function roundRect(ctx, x, y, w, h, r) {
      r = Math.min(r, w / 2, h / 2);
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
    }

    /* ---- the alignment window -------------------------------------------- */

    function cellFill(code, c) {
      if (colourMode === 'plain') { return null; }
      var ch = String.fromCharCode(code);
      if (colourMode === 'residue') {
        return (kind === 'protein' ? RESIDUE_CLASS : NUCLEOTIDE_CLASS)[ch] || null;
      }
      /* Conservation: a residue that matches its column's consensus, shaded
         by how conserved the column is. A lone agreeing residue in a column
         nobody else shares is not conservation, so the floor is 0.3. */
      if (code === current.prof.consensus[c] && current.prof.cons[c] >= 0.3) { return GREEN; }
      return null;
    }

    function draw() {
      raf = 0;
      if (!current) { return; }
      var ctx = canvas.getContext('2d');
      var z = Z(), G = gutter(), H = headerHeight();
      var W = scroller.clientWidth, VH = scroller.clientHeight;
      var sl = scroller.scrollLeft, st = scroller.scrollTop;
      var aln = current.aln, prof = current.prof;
      var rows = aln.seqs.length, width = aln.width;
      var c0 = Math.max(0, Math.floor(sl / z.cw));
      var c1 = Math.min(width, Math.ceil((sl + W - G) / z.cw));
      var r0 = Math.max(0, Math.floor(st / z.rh));
      var r1 = Math.min(rows, Math.ceil((st + VH - H) / z.rh));
      var letters = z.cw >= 8;
      var anySelected = Object.keys(selected).length > 0;

      ctx.clearRect(0, 0, W, VH);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, W, VH);

      ctx.save();
      ctx.beginPath();
      ctx.rect(G, H, W - G, VH - H);
      ctx.clip();
      ctx.font = (z.cw >= 12 ? 12 : 10) + 'px ' + EXPORT_MONO;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      for (var r = r0; r < r1; r++) {
        var i = order[r];
        var s = aln.seqs[i];
        var y = H + r * z.rh - st;
        var gene = labelOf(i);
        var isSel = anySelected && selected[gene];
        if (isSel) {
          ctx.fillStyle = 'rgba(11, 87, 164, 0.10)';
          ctx.fillRect(G, y, W - G, z.rh);
        }
        for (var c = c0; c < c1; c++) {
          var code = s.charCodeAt(c);
          var x = G + c * z.cw - sl;
          if (code === 45 || code === 46 || isNaN(code)) {
            if (z.cw >= 3) {
              ctx.fillStyle = '#ece8df';
              ctx.fillRect(x, y + z.rh / 2 - 0.5, z.cw, 1);
            }
            continue;
          }
          var fill = cellFill(code, c);
          if (fill) {
            /* With letters drawn the fill has to stay light enough for dark
               ink to read on it. At 0.18 + 0.55 x conservation a column 97%
               conserved -- nearly every column of lg1 -- came out dark green
               under dark letters; capped near 0.45 the letters hold about 7:1.
               Without letters the fill is the whole signal, so it runs full. */
            ctx.globalAlpha = colourMode === 'conservation'
              ? (letters ? 0.10 + 0.35 * prof.cons[c] : 0.35 + 0.65 * prof.cons[c])
              : (letters ? 0.30 : 0.9);
            ctx.fillStyle = fill;
            ctx.fillRect(x, y + (z.rh > 6 ? 1 : 0), z.cw - (z.cw > 3 ? 1 : 0), z.rh - (z.rh > 6 ? 2 : 0));
            ctx.globalAlpha = 1;
          } else if (!letters) {
            ctx.fillStyle = '#c9c4b8';
            ctx.fillRect(x, y + (z.rh > 6 ? 1 : 0), z.cw, z.rh - (z.rh > 6 ? 2 : 0));
          }
          if (letters) {
            ctx.fillStyle = fill || colourMode === 'plain' ? INK : MUTED;
            ctx.fillText(String.fromCharCode(code), x + z.cw / 2, y + z.rh / 2 + 0.5);
          }
        }
        if (anySelected && !isSel) {
          ctx.fillStyle = 'rgba(255, 255, 255, 0.55)';
          ctx.fillRect(G, y, W - G, z.rh);
        }
      }
      /* The column under the pointer. */
      if (hover && hover.col >= c0 && hover.col < c1) {
        ctx.strokeStyle = 'rgba(11, 87, 164, 0.7)';
        ctx.lineWidth = 1;
        ctx.strokeRect(G + hover.col * z.cw - sl + 0.5, H, Math.max(z.cw - 1, 1), VH - H);
      }
      ctx.restore();

      /* Header: ruler and the exemplar's domains, fixed to the top. */
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, W, H);
      ctx.save();
      ctx.beginPath();
      ctx.rect(G, 0, W - G, H);
      ctx.clip();
      var step = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000].filter(function (t) {
        return t * z.cw >= 48;
      })[0] || 5000;
      ctx.font = '10px ' + EXPORT_FONT;
      ctx.fillStyle = MUTED;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'alphabetic';
      for (var t = Math.ceil((c0 + 1) / step) * step; t <= c1; t += step) {
        var tx = G + (t - 0.5) * z.cw - sl;
        ctx.fillText(number(t), tx, 11);
        ctx.fillRect(tx, 13, 1, 4);
      }
      var lanes = domainLanes();
      lanes.forEach(function (lane, li) {
        var ly = HEADER_RULER + 2 + li * (LANE_H + LANE_GAP);
        lane.forEach(function (b) {
          var x0 = G + b.start * z.cw - sl, x1 = G + (b.end + 1) * z.cw - sl;
          if (x1 < G || x0 > W) { return; }
          ctx.fillStyle = colourOfDomain(b.name);
          roundRect(ctx, x0, ly, Math.max(x1 - x0, 2), LANE_H, 2);
          ctx.fill();
          if (x1 - x0 > 46) {
            ctx.fillStyle = '#ffffff';
            ctx.font = '600 8.5px ' + EXPORT_FONT;
            ctx.textAlign = 'left';
            ctx.fillText(b.name, Math.max(x0, G) + 4, ly + LANE_H - 1);
          }
        });
      });
      ctx.restore();
      ctx.fillStyle = LINE;
      ctx.fillRect(0, H - 1, W, 1);

      /* Gutter: the gene model names, fixed to the left. */
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, G, VH);
      ctx.fillStyle = MUTED;
      ctx.font = '600 10px ' + EXPORT_FONT;
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      ctx.fillText(kind === 'protein' ? 'Protein' : 'CDS', 8, 9);
      if (lanes.length) { ctx.fillText('Domains', 8, HEADER_RULER + 2 + LANE_H / 2); }
      ctx.save();
      ctx.beginPath();
      ctx.rect(0, H, G, VH - H);
      ctx.clip();
      /* A name is about 11 px tall. Rows shorter than that cannot each carry
         one -- at 7 px rows they overprinted into a smear -- so the gutter
         names every Nth row instead, enough to stay oriented; the line under
         the figure always names the exact row under the pointer. */
      var every = Math.max(1, Math.ceil(12 / z.rh));
      if (z.rh >= 4) {
        for (var rr = r0; rr < r1; rr++) {
          if (every > 1 && rr % every !== 0) { continue; }
          var ii = order[rr];
          var label = labelOf(ii);
          var yy = H + rr * z.rh - st + (every > 1 ? 6 : z.rh / 2 + 0.5);
          var ex = aln.names[ii] === spec.exemplar;
          var sel = anySelected && selected[label];
          ctx.fillStyle = sel ? '#0b57a4' : (anySelected ? '#b8b3a8' : (ex ? INK : '#3d4a42'));
          ctx.font = (ex || sel ? '700 ' : '') + (z.rh >= 14 ? 11 : 9) + 'px ' + EXPORT_MONO;
          ctx.fillText(fitText(ctx, label, G - 14), 8, yy);
        }
      }
      ctx.restore();
      ctx.fillStyle = LINE;
      ctx.fillRect(G - 1, 0, 1, VH);

      drawOverview();
    }

    function fitText(ctx, text, max) {
      if (ctx.measureText(text).width <= max) { return text; }
      var t = text;
      while (t.length > 3 && ctx.measureText(t + '…').width > max) { t = t.slice(0, -1); }
      return t + '…';
    }

    function schedule() { if (!raf) { raf = window.requestAnimationFrame(draw); } }

    /* ---- pointer --------------------------------------------------------- */

    function cellAt(event) {
      var rect = canvas.getBoundingClientRect();
      var x = event.clientX - rect.left, y = event.clientY - rect.top;
      var z = Z(), G = gutter(), H = headerHeight();
      if (y < H) { return null; }
      var row = Math.floor((y - H + scroller.scrollTop) / z.rh);
      if (row < 0 || row >= order.length) { return null; }
      var col = x < G ? -1 : Math.floor((x - G + scroller.scrollLeft) / z.cw);
      if (col >= current.aln.width) { return null; }
      return { row: row, col: col };
    }

    function describe(hit) {
      if (!hit) { detail.textContent = idleDetail; return; }
      var i = order[hit.row];
      var name = current.aln.names[i];
      var info = resolve(name) || {};
      var label = info.gene || name;
      var bits = [info.html
        ? '<a href="' + esc(info.html) + '"><span class="mgdb-sequence">' + esc(label) + '</span></a>'
        : '<span class="mgdb-sequence">' + esc(label) + '</span>'];
      if (info.assembly) { bits.push('<span class="mgdb-muted">' + esc(info.assembly) + '</span>'); }
      if (hit.col >= 0) {
        var s = current.aln.seqs[i], code = s.charCodeAt(hit.col);
        var gap = code === 45 || code === 46;
        var pos = 0;
        for (var c = 0; c <= hit.col; c++) {
          var k = s.charCodeAt(c);
          if (k !== 45 && k !== 46) { pos++; }
        }
        var p = current.prof;
        var cons = p.consensus[hit.col] ? String.fromCharCode(p.consensus[hit.col]) : '–';
        bits.push('column <strong>' + number(hit.col + 1) + '</strong>');
        bits.push(gap ? 'gap' : (kind === 'protein' ? 'residue ' : 'base ') + '<strong>' + number(pos) +
          '</strong> <span class="mgdb-sequence">' + String.fromCharCode(code) + '</span>');
        bits.push('consensus <span class="mgdb-sequence">' + cons + '</span>, ' +
          Math.round(p.cons[hit.col] * 100) + '% conserved, ' + Math.round(p.occ[hit.col] * 100) + '% occupied');
      }
      detail.innerHTML = bits.join(' &middot; ');
    }

    canvas.addEventListener('mousemove', function (event) {
      if (!current) { return; }
      var hit = cellAt(event);
      var prev = hover;
      hover = hit && hit.col >= 0 ? hit : null;
      describe(hit);
      if ((prev && (!hover || prev.col !== hover.col)) || (!prev && hover)) { schedule(); }
    });
    canvas.addEventListener('mouseleave', function () {
      hover = null;
      detail.textContent = idleDetail;
      schedule();
    });
    canvas.addEventListener('click', function (event) {
      if (!current) { return; }
      var hit = cellAt(event);
      if (!hit) { return; }
      var gene = labelOf(order[hit.row]);
      var info = resolve(current.aln.names[order[hit.row]]);
      if (!info || !info.gene) { return; }   /* a row no member claims cannot be selected */
      var sel = MGDB.panGeneSelection.get();
      if (sel && sel.genes.length === 1 && sel.genes[0] === gene) { MGDB.panGeneSelection.clear(); return; }
      MGDB.panGeneSelection.set({ genes: [gene], label: gene, source: 'msa', filter: gene });
    });

    scroller.addEventListener('scroll', schedule, { passive: true });

    function seekOverview(event) {
      var rect = overview.getBoundingClientRect();
      var frac = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
      var z = Z(), dataW = scroller.clientWidth - gutter();
      scroller.scrollLeft = frac * current.aln.width * z.cw - dataW / 2;
      schedule();
    }
    var dragging = false;
    overview.addEventListener('pointerdown', function (event) {
      if (!current) { return; }
      dragging = true;
      if (overview.setPointerCapture) { overview.setPointerCapture(event.pointerId); }
      seekOverview(event);
    });
    overview.addEventListener('pointermove', function (event) { if (dragging) { seekOverview(event); } });
    overview.addEventListener('pointerup', function () { dragging = false; });
    overview.addEventListener('pointercancel', function () { dragging = false; });

    /* ---- selection from the other figures -------------------------------- */

    MGDB.panGeneSelection.subscribe(function (sel) {
      selected = {};
      if (sel) { sel.genes.forEach(function (g) { selected[g] = true; }); }
      if (!current) { return; }
      /* Bring the first selected row into the box -- the box's own scroll,
         never the page's. */
      if (sel && sel.source !== 'msa') {
        for (var r = 0; r < order.length; r++) {
          if (selected[labelOf(order[r])]) {
            var z = Z(), top = r * z.rh, H = headerHeight();
            var inView = top >= scroller.scrollTop && top + z.rh <= scroller.scrollTop + scroller.clientHeight - H;
            if (!inView) { scroller.scrollTop = Math.max(0, top - (scroller.clientHeight - H) / 3); }
            break;
          }
        }
      }
      schedule();
    });

    /* ---- controls -------------------------------------------------------- */

    function setZoom(next) {
      if (!current || next < 0 || next >= MSA_ZOOM.length || next === zoom) { return; }
      var old = Z(), G = gutter(), dataW = scroller.clientWidth - G;
      var centreCol = (scroller.scrollLeft + dataW / 2) / old.cw;
      var centreRow = scroller.scrollTop / old.rh;
      zoom = next;
      layout();
      var z = Z();
      scroller.scrollLeft = centreCol * z.cw - (scroller.clientWidth - G) / 2;
      scroller.scrollTop = centreRow * z.rh;
      block.querySelector('[data-role="zoom-out"]').disabled = zoom === 0;
      block.querySelector('[data-role="zoom-in"]').disabled = zoom === MSA_ZOOM.length - 1;
      schedule();
    }
    block.querySelector('[data-role="zoom-in"]').addEventListener('click', function () { setZoom(zoom + 1); });
    block.querySelector('[data-role="zoom-out"]').addEventListener('click', function () { setZoom(zoom - 1); });

    block.querySelector('[data-role="colour"]').addEventListener('change', function () {
      colourMode = this.value;
      schedule();
    });
    block.querySelector('[data-role="sort"]').addEventListener('change', function () {
      sortMode = this.value;
      if (current) { reorder(); schedule(); }
    });

    Array.prototype.forEach.call(block.querySelectorAll('[data-kind]'), function (btn) {
      btn.addEventListener('click', function () {
        var which = btn.getAttribute('data-kind');
        if (which === kind) { return; }
        Array.prototype.forEach.call(block.querySelectorAll('[data-kind]'), function (b) {
          b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
        });
        show(which);
      });
    });

    block.querySelector('[data-role="msa-fasta"]').addEventListener('click', function () {
      if (!current) { return; }
      /* From the text already fetched, as a Blob. A download attribute on a
         link to the FTP host does nothing: it is cross-origin. */
      var blob = new Blob([current.text], { type: 'text/plain' });
      var href = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = href;
      a.download = (spec.panGene || 'pan-gene') + '.' + kind + '.aln.fasta';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
    });

    /* The PNG is the profile and the window as they stand, stacked under a
       title. Both are canvases this page drew itself from text it fetched
       under CORS, so neither is tainted and toBlob() works. */
    block.querySelector('[data-role="msa-png"]').addEventListener('click', function () {
      if (!current) { return; }
      var dpr = window.devicePixelRatio || 1, scale = 2;
      var ow = overview.width / dpr, oh = overview.height / dpr;
      var mw = canvas.width / dpr, mh = canvas.height / dpr;
      var M = 20, TITLE = 44, W = Math.max(ow, mw) + 2 * M, H = TITLE + oh + 12 + mh + M + 16;
      var out = document.createElement('canvas');
      out.width = W * scale; out.height = H * scale;
      var ctx = out.getContext('2d');
      ctx.scale(scale, scale);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, W, H);
      ctx.fillStyle = INK;
      ctx.font = '700 16px ' + EXPORT_FONT;
      ctx.fillText((kind === 'protein' ? 'Protein' : 'CDS') + ' alignment and conservation', M, M + 12);
      ctx.fillStyle = MUTED;
      ctx.font = '11px ' + EXPORT_FONT;
      var z = Z();
      var c0 = Math.floor(scroller.scrollLeft / z.cw) + 1;
      var c1 = Math.min(current.aln.width, Math.floor((scroller.scrollLeft + scroller.clientWidth - gutter()) / z.cw));
      ctx.fillText(number(current.aln.seqs.length) + ' sequences · ' + number(current.aln.width) +
        ' columns · window shows columns ' + number(c0) + '–' + number(c1), M, M + 28);
      ctx.drawImage(overview, M, TITLE, ow, oh);
      ctx.drawImage(canvas, M, TITLE + oh + 12, mw, mh);
      ctx.fillStyle = MUTED;
      ctx.font = '10px ' + EXPORT_FONT;
      ctx.fillText('MaizeGDB · grey band: occupancy · green: conservation to consensus', M, H - 10);
      out.toBlob(function (b) {
        if (!b) { return; }
        var href = URL.createObjectURL(b);
        var a = document.createElement('a');
        a.href = href;
        a.download = (spec.panGene || 'pan-gene') + '.' + kind + '.alignment.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
      }, 'image/png');
    });

    /* ---- show ------------------------------------------------------------ */

    /* The first column most sequences share, less a few for context. rp1's
       members run from 400 to 2,322 residues, so its first ~1,000 columns are
       nearly all gap and opening at column 1 showed an empty box with the
       domains out of view to the right. */
    function coreStart() {
      var occ = current.prof.occ;
      for (var c = 0; c < occ.length; c++) {
        if (occ[c] >= 0.5) { return Math.max(0, c - 4); }
      }
      return 0;
    }

    function statusText() {
      var aln = current.aln, prof = current.prof, n = 0, hi = 0;
      for (var c = 0; c < aln.width; c++) {
        if (prof.occ[c] >= 0.5) { n++; if (prof.cons[c] >= 0.9) { hi++; } }
      }
      var unmatched = aln.names.filter(function (nm) { return !resolve(nm); }).length;
      return number(aln.seqs.length) + ' sequences over ' + number(aln.width) + ' columns. ' +
        number(hi) + ' of the ' + number(n) + ' columns most sequences share are at least 90% conserved.' +
        (unmatched ? ' ' + number(unmatched) + ' sequence' + (unmatched === 1 ? ' is' : 's are') +
          ' named differently in the alignment file than in the member list and are shown by the file’s name.' : '');
    }

    function show(which) {
      kind = which;
      statusEl.innerHTML = 'Loading the ' + (which === 'protein' ? 'protein' : 'CDS') + ' alignment&hellip;';
      return load(which).then(function (entry) {
        current = entry;
        reorder();
        hover = null;
        tools.hidden = false;
        overviewWrap.hidden = false;
        scroller.hidden = false;
        layout();
        lastW = scroller.clientWidth;
        scroller.scrollLeft = coreStart() * Z().cw;
        scroller.scrollTop = 0;
        block.querySelector('[data-role="zoom-out"]').disabled = zoom === 0;
        block.querySelector('[data-role="zoom-in"]').disabled = zoom === MSA_ZOOM.length - 1;
        canvas.setAttribute('aria-label', (which === 'protein' ? 'Protein' : 'CDS') + ' alignment of ' +
          entry.aln.seqs.length + ' sequences over ' + entry.aln.width + ' columns');
        statusEl.innerHTML = statusText();
        schedule();
      }).catch(function (error) {
        statusEl.innerHTML = 'The alignment could not be loaded. ' +
          '<a href="' + esc(which === 'protein' ? spec.proteinUrl : spec.cdsUrl) +
          '" target="_blank" rel="noopener">Open the file</a>.';
        if (window.console && console.warn) { console.warn('pan-gene MSA:', error); }
      });
    }

    var lastW = 0;
    function onResize() {
      if (!current || !scroller.clientWidth || Math.abs(scroller.clientWidth - lastW) < 4) { return; }
      lastW = scroller.clientWidth;
      layout();
      schedule();
    }
    var debounced = MGDB.debounce ? MGDB.debounce(onResize, 120) : onResize;
    /* Both, not either. The canvases are sized in pixels, so a missed resize
       leaves them drawn at the old width inside a wider box -- which is what a
       ResizeObserver alone did when the viewport changed under it. */
    if (window.ResizeObserver) { new window.ResizeObserver(debounced).observe(block); }
    window.addEventListener('resize', debounced);

    loadTreeOrder().then(function (idx) {
      treeOrder = idx;
      if (!treeOrder) {
        sortMode = 'file';
        block.querySelector('[data-role="sort"]').value = 'file';
        block.querySelector('[data-role="sort"] option[value="tree"]').disabled = true;
      }
      return show(kind);
    });

    return { element: block };
  }

  MGDB.panGeneMsa = panGeneMsa;

  /* ------------------------------------------------------------------------
     NAM expression heatmap

     spec = {
       matrix:   sections.expression_matrix
       treeUrl:  the Newick, for tree order
       filename: the TSV download name
     }

     One row per member gene model in B73v5 or a NAM founder, one column per
     NAM Consortium tissue, and a tau column. Several rows for one line are
     that line's paralogs -- on rp1, B97 alone carries eleven -- which is the
     within-genome comparison the figure is for.

     Colour is log2(value + 1) on the site's own heatmap ramp (the one Hot New
     Papers uses): one hue, light to dark, the lightest step fading into the
     page. A cell with no value in the release is hatched, never drawn as the
     lightest colour -- 0 says "not expressed", a missing value says "not
     measured", and a quarter of lg1's cells are the second.
     ------------------------------------------------------------------------ */

  var HEAT_RAMP = [[242, 239, 231], [215, 232, 220], [169, 208, 184], [79, 143, 104], [29, 92, 61]];

  function heatColour(t) {
    if (t == null || isNaN(t)) { return null; }
    t = Math.max(0, Math.min(1, t));
    var seg = t * (HEAT_RAMP.length - 1), i = Math.min(Math.floor(seg), HEAT_RAMP.length - 2), f = seg - i;
    var a = HEAT_RAMP[i], b = HEAT_RAMP[i + 1];
    return 'rgb(' + Math.round(a[0] + (b[0] - a[0]) * f) + ',' + Math.round(a[1] + (b[1] - a[1]) * f) + ',' +
           Math.round(a[2] + (b[2] - a[2]) * f) + ')';
  }

  function log2p(v) { return v == null ? null : Math.log(v + 1) / Math.LN2; }

  /* Average-linkage clustering of the rows' tissue patterns. Distance is
     Euclidean on each row scaled to its own maximum, so two genes cluster for
     sharing a pattern, not a level -- the level is what "Absolute" shows. Only
     tissues both rows were measured in count, rescaled to all ten. */
  function clusterRows(rows) {
    var n = rows.length;
    var prof = rows.map(function (r) {
      var l = r.values.map(log2p);
      var m = Math.max.apply(null, l.map(function (v) { return v == null ? 0 : v; }));
      return l.map(function (v) { return v == null ? null : (m > 0 ? v / m : 0); });
    });
    function dist(a, b) {
      var s = 0, k = 0;
      for (var i = 0; i < a.length; i++) {
        if (a[i] == null || b[i] == null) { continue; }
        s += (a[i] - b[i]) * (a[i] - b[i]); k++;
      }
      return k ? Math.sqrt(s * a.length / k) : 1e9;
    }
    var clusters = [];
    for (var i = 0; i < n; i++) { clusters.push({ id: i, members: [i], left: null, right: null, height: 0 }); }
    var D = [];
    for (var a = 0; a < n; a++) { D[a] = []; for (var b = 0; b < n; b++) { D[a][b] = a === b ? 0 : dist(prof[a], prof[b]); } }
    var active = clusters.slice();
    function linkage(x, y) {
      var s = 0;
      x.members.forEach(function (i) { y.members.forEach(function (j) { s += D[i][j]; }); });
      return s / (x.members.length * y.members.length);
    }
    while (active.length > 1) {
      var best = Infinity, bi = 0, bj = 1;
      for (var p = 0; p < active.length; p++) {
        for (var q = p + 1; q < active.length; q++) {
          var d = linkage(active[p], active[q]);
          if (d < best) { best = d; bi = p; bj = q; }
        }
      }
      var merged = { members: active[bi].members.concat(active[bj].members),
                     left: active[bi], right: active[bj], height: best };
      active.splice(bj, 1);
      active.splice(bi, 1, merged);
    }
    return active[0] || null;
  }

  function panGeneHeatmap(container, spec) {
    var matrix = spec && spec.matrix;
    if (!container || !matrix || !matrix.rows || !matrix.rows.length) { return null; }
    var tissues = matrix.tissues || [];
    var rows = matrix.rows.slice();
    var mode = 'tree', scale = 'absolute';
    var treeOrder = null, dendro = null;
    var selected = {};

    var globalMax = 0;
    rows.forEach(function (r) { r.values.forEach(function (v) { var l = log2p(v); if (l != null && l > globalMax) { globalMax = l; } }); });
    if (globalMax <= 0) { globalMax = 1; }

    var lines = {};
    rows.forEach(function (r) { lines[r.line] = true; });

    container.insertAdjacentHTML('afterbegin',
      '<div class="mgdb-rec-block mgdb-pg-heat-block">' +
        '<div class="mgdb-rec-block-head">' +
          '<h3>Expression across the NAM founders</h3>' +
          '<div class="mgdb-pg-heat-tools">' +
            '<label>Order <select data-role="heat-order" aria-label="Order the rows by">' +
              '<option value="tree">Phylogenetic tree</option>' +
              '<option value="cluster">Cluster by pattern</option>' +
              '<option value="genome">Genome</option>' +
              '<option value="tau">Tissue specificity</option>' +
            '</select></label>' +
            '<label>Scale <select data-role="heat-scale" aria-label="Colour scale">' +
              '<option value="absolute">Absolute</option>' +
              '<option value="row">Each row to its maximum</option>' +
            '</select></label>' +
            '<button class="mgdb-rec-tsv" type="button" data-role="heat-png">Export PNG</button>' +
            '<button class="mgdb-rec-tsv" type="button" data-role="heat-tsv">Download TSV</button>' +
          '</div>' +
        '</div>' +
        '<p class="mgdb-fig-desc">NAM expression heatmap: ' + number(matrix.genome_count) + ' genomes × ' +
          number(tissues.length) + ' NAM tissues, tree or cluster ordering, with a tissue-specificity τ column.</p>' +
        '<p class="mgdb-rec-block-status" data-role="heat-status"></p>' +
        '<div class="mgdb-pg-heat-scroll" data-role="heat-scroll"></div>' +
        '<div class="mgdb-pg-heat-legend" data-role="heat-legend"></div>' +
        '<p class="mgdb-pg-heat-detail" data-role="heat-detail" aria-live="polite"></p>' +
      '</div>');
    var block = container.firstElementChild;
    var scroller = block.querySelector('[data-role="heat-scroll"]');
    var detail = block.querySelector('[data-role="heat-detail"]');
    var idle = 'Hover a cell for its value; click a row to select that gene model in every figure.';
    detail.textContent = idle;

    function statusText() {
      var missing = (matrix.members_without_profile || []).length;
      var noTau = rows.filter(function (r) { return r.tau == null; }).length;
      var nulls = 0;
      rows.forEach(function (r) { r.values.forEach(function (v) { if (v == null) { nulls++; } }); });
      return number(rows.length) + ' gene model' + (rows.length === 1 ? '' : 's') + ' across ' +
        number(Object.keys(lines).length) + ' of the ' + number(matrix.genome_count) + ' genomes · ' +
        esc(matrix.source) + ' RNA-seq, colour ' + esc(matrix.scale) +
        (nulls ? ' · ' + number(nulls) + ' cell' + (nulls === 1 ? '' : 's') + ' not measured, hatched' : '') +
        (missing ? ' · ' + number(missing) + ' member' + (missing === 1 ? ' has' : 's have') + ' no profile' : '') +
        (noTau ? ' · τ is left blank for ' + number(noTau) + ' where no tissue reaches 1, since it ' +
          'is meaningless at noise level' : '') + '.';
    }
    block.querySelector('[data-role="heat-status"]').innerHTML = statusText();

    /* ---- ordering ------------------------------------------------------- */

    function lineSort(a, b) {
      var ab = a.line.indexOf('B73') === 0, bb = b.line.indexOf('B73') === 0;
      if (ab !== bb) { return ab ? -1 : 1; }
      var c = a.line.localeCompare(b.line, undefined, { numeric: true, sensitivity: 'base' });
      return c || a.gene.localeCompare(b.gene);
    }

    function applyOrder() {
      dendro = null;
      if (mode === 'tree' && treeOrder) {
        rows.sort(function (a, b) {
          var x = treeOrder[a.transcript], y = treeOrder[b.transcript];
          if (x == null && y == null) { return lineSort(a, b); }
          if (x == null) { return 1; }
          if (y == null) { return -1; }
          return x - y;
        });
      } else if (mode === 'cluster') {
        var root = clusterRows(rows);
        var order = [];
        (function walk(node) {
          if (!node.left) { order.push(node.members[0]); return; }
          walk(node.left); walk(node.right);
        })(root);
        var before = rows.slice();
        rows = order.map(function (i) { return before[i]; });
        /* Re-index the tree onto the new row order for drawing. */
        var pos = {};
        order.forEach(function (orig, k) { pos[orig] = k; });
        (function reindex(node) {
          if (!node.left) { node.row = pos[node.members[0]]; return; }
          reindex(node.left); reindex(node.right);
          node.row = (node.left.row + node.right.row) / 2;
        })(root);
        dendro = root;
      } else if (mode === 'tau') {
        rows.sort(function (a, b) {
          var x = a.tau == null ? -1 : a.tau, y = b.tau == null ? -1 : b.tau;
          return y - x || lineSort(a, b);
        });
      } else {
        rows.sort(lineSort);
      }
    }

    /* ---- drawing -------------------------------------------------------- */

    var ROW = 16, HEAD = 46, GROUP_GAP = 8, TAU_W = 86, LINE_W = 64, GENE_W = 132;

    function draw() {
      var dendroW = dendro ? 56 : 0;
      var left = dendroW + LINE_W + GENE_W + 10;
      var avail = Math.max((scroller.clientWidth || 900) - 2, 560);
      var groups = [];
      tissues.forEach(function (t, i) {
        if (!groups.length || groups[groups.length - 1].name !== t.group) { groups.push({ name: t.group, from: i, to: i }); }
        else { groups[groups.length - 1].to = i; }
      });
      var gaps = (groups.length - 1) * GROUP_GAP;
      var cellW = Math.max(30, Math.min(78, Math.floor((avail - left - TAU_W - gaps - 8) / tissues.length)));
      var width = left + tissues.length * cellW + gaps + 12 + TAU_W;
      var height = HEAD + rows.length * ROW + 6;
      var x = [];
      var cx = left;
      tissues.forEach(function (t, i) {
        if (i > 0 && tissues[i - 1].group !== t.group) { cx += GROUP_GAP; }
        x.push(cx); cx += cellW;
      });
      var tauX = cx + 12;
      var any = Object.keys(selected).length > 0;
      var out = [];
      out.push('<svg class="mgdb-pg-heat-svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' +
        width + ' ' + height + '" role="img" aria-label="Expression of ' + rows.length +
        ' gene models across ' + tissues.length + ' tissues">');
      out.push('<defs><pattern id="mgdb-heat-hatch" width="6" height="6" patternUnits="userSpaceOnUse" ' +
        'patternTransform="rotate(45)"><rect width="6" height="6" fill="#ffffff"></rect>' +
        '<line x1="0" y1="0" x2="0" y2="6" stroke="#cfcac0" stroke-width="2"></line></pattern></defs>');

      /* Column headers: the group over its tissues, then the tissue. */
      groups.forEach(function (g) {
        var gx0 = x[g.from], gx1 = x[g.to] + cellW;
        out.push('<text class="mgdb-pg-heat-group" x="' + ((gx0 + gx1) / 2) + '" y="14" text-anchor="middle">' +
          esc(g.name) + '</text>');
        out.push('<path class="mgdb-pg-heat-rule" d="M' + (gx0 + 2) + ' 20H' + (gx1 - 2) + '"></path>');
      });
      tissues.forEach(function (t, i) {
        out.push('<text class="mgdb-pg-heat-tissue" x="' + (x[i] + cellW / 2) + '" y="36" text-anchor="middle">' +
          '<title>' + esc(t.label) + '</title>' + esc(t.short) + '</text>');
      });
      out.push('<text class="mgdb-pg-heat-group" x="' + (tauX + TAU_W / 2) + '" y="36" text-anchor="middle">' +
        '<title>Tissue specificity over these ten tissues: 0 is even, 1 is one tissue alone.</title>τ</text>');

      rows.forEach(function (r, ri) {
        var y = HEAD + ri * ROW;
        var isSel = any && selected[r.gene];
        var dim = any && !isSel;
        out.push('<g class="mgdb-pg-heat-row' + (isSel ? ' is-picked' : '') + (dim ? ' is-dim' : '') +
          '" data-row="' + ri + '">');
        out.push('<rect class="mgdb-pg-heat-hit" x="' + dendroW + '" y="' + y + '" width="' + (width - dendroW) +
          '" height="' + ROW + '"></rect>');
        var firstOfLine = ri === 0 || rows[ri - 1].line !== r.line || mode === 'tree' || mode === 'cluster' || mode === 'tau';
        if (firstOfLine) {
          out.push('<text class="mgdb-pg-heat-line" x="' + (dendroW + 4) + '" y="' + (y + ROW - 4.5) + '">' +
            esc(r.line) + '</text>');
        }
        out.push('<text class="mgdb-pg-heat-gene' + (r.is_exemplar ? ' is-exemplar' : '') + '" x="' +
          (dendroW + LINE_W) + '" y="' + (y + ROW - 4.5) + '">' + esc(r.gene) + '</text>');
        var rowMax = 0;
        r.values.forEach(function (v) { var l = log2p(v); if (l != null && l > rowMax) { rowMax = l; } });
        r.values.forEach(function (v, i) {
          var l = log2p(v);
          var fill = l == null ? 'url(#mgdb-heat-hatch)'
            : heatColour(scale === 'row' ? (rowMax > 0 ? l / rowMax : 0) : l / globalMax);
          out.push('<rect class="mgdb-pg-heat-cell" data-col="' + i + '" x="' + x[i] + '" y="' + (y + 1) +
            '" width="' + (cellW - 2) + '" height="' + (ROW - 2) + '" rx="2" fill="' + fill + '"></rect>');
        });
        if (r.tau != null) {
          out.push('<rect class="mgdb-pg-heat-taubar" x="' + tauX + '" y="' + (y + 4) + '" width="' +
            (r.tau * 44).toFixed(1) + '" height="' + (ROW - 8) + '" rx="2"></rect>');
          out.push('<text class="mgdb-pg-heat-tau" x="' + (tauX + 50) + '" y="' + (y + ROW - 4.5) + '">' +
            r.tau.toFixed(2) + '</text>');
        } else {
          out.push('<text class="mgdb-pg-heat-tau is-none" x="' + (tauX + 50) + '" y="' + (y + ROW - 4.5) +
            '">–</text>');
        }
        out.push('</g>');
      });

      if (dendro) {
        var maxH = dendro.height || 1;
        var DX = function (h) { return 4 + (1 - h / maxH) * (dendroW - 10); };
        var DY = function (row) { return HEAD + row * ROW + ROW / 2; };
        (function link(node) {
          if (!node.left) { return; }
          var xh = DX(node.height);
          [node.left, node.right].forEach(function (c) {
            var xc = c.left ? DX(c.height) : dendroW - 4;
            out.push('<path class="mgdb-pg-heat-dendro" d="M' + xh.toFixed(1) + ' ' + DY(c.row).toFixed(1) +
              'H' + xc.toFixed(1) + '"></path>');
            link(c);
          });
          out.push('<path class="mgdb-pg-heat-dendro" d="M' + xh.toFixed(1) + ' ' + DY(node.left.row).toFixed(1) +
            'V' + DY(node.right.row).toFixed(1) + '"></path>');
        })(dendro);
      }

      out.push('</svg>');
      scroller.innerHTML = out.join('');
      block.querySelector('[data-role="heat-legend"]').innerHTML = legendHtml();
    }

    function legendHtml() {
      var stops = [];
      for (var i = 0; i <= 10; i++) { stops.push(heatColour(i / 10) + ' ' + (i * 10) + '%'); }
      return '<span class="mgdb-pg-heat-ramp" style="background:linear-gradient(to right,' + stops.join(',') +
        ')" aria-hidden="true"></span>' +
        '<span class="mgdb-pg-heat-rampcap">' + (scale === 'row'
          ? '0 → each row’s highest tissue'
          : '0 → ' + globalMax.toFixed(1) + ' ' + esc(matrix.scale)) + '</span>' +
        '<span class="mgdb-pg-heat-hatchkey" aria-hidden="true"></span><span>not measured</span>';
    }

    /* ---- interaction ----------------------------------------------------- */

    scroller.addEventListener('mousemove', function (event) {
      var g = event.target.closest ? event.target.closest('[data-row]') : null;
      if (!g) { detail.textContent = idle; return; }
      var r = rows[+g.getAttribute('data-row')];
      var cell = event.target.getAttribute('data-col');
      var bits = [(r.html ? '<a href="' + esc(r.html) + '">' : '') + '<span class="mgdb-sequence">' +
        esc(r.gene) + '</span>' + (r.html ? '</a>' : ''), '<strong>' + esc(r.line) + '</strong>'];
      if (cell != null) {
        var t = tissues[+cell], v = r.values[+cell];
        bits.push(esc(t.label) + ': ' + (v == null ? 'not measured'
          : '<strong>' + v.toLocaleString(undefined, { maximumSignificantDigits: 4 }) + '</strong> (log2 ' +
            log2p(v).toFixed(2) + ')'));
      }
      bits.push('τ ' + (r.tau == null ? 'not computed, no tissue reaches 1' : r.tau.toFixed(2)));
      if (r.is_exemplar) { bits.push('<span class="mgdb-pill mgdb-pill-ok">Exemplar</span>'); }
      detail.innerHTML = bits.join(' &middot; ');
    });
    scroller.addEventListener('mouseleave', function () { detail.textContent = idle; });

    scroller.addEventListener('click', function (event) {
      var g = event.target.closest ? event.target.closest('[data-row]') : null;
      if (!g) { return; }
      var gene = rows[+g.getAttribute('data-row')].gene;
      var sel = MGDB.panGeneSelection.get();
      if (sel && sel.genes.length === 1 && sel.genes[0] === gene) { MGDB.panGeneSelection.clear(); return; }
      MGDB.panGeneSelection.set({ genes: [gene], label: gene, source: 'heatmap', filter: gene });
    });

    MGDB.panGeneSelection.subscribe(function (sel) {
      selected = {};
      if (sel) { sel.genes.forEach(function (g) { selected[g] = true; }); }
      var any = !!sel;
      Array.prototype.forEach.call(scroller.querySelectorAll('[data-row]'), function (g) {
        var hit = any && selected[rows[+g.getAttribute('data-row')].gene];
        g.classList.toggle('is-dim', any && !hit);
        g.classList.toggle('is-picked', !!hit);
      });
    });

    block.querySelector('[data-role="heat-order"]').addEventListener('change', function () {
      mode = this.value; applyOrder(); draw();
    });
    block.querySelector('[data-role="heat-scale"]').addEventListener('change', function () {
      scale = this.value; draw();
    });

    block.querySelector('[data-role="heat-tsv"]').addEventListener('click', function () {
      var columns = [
        { label: 'Gene model', get: function (r) { return r.gene; } },
        { label: 'Line', get: function (r) { return r.line; } },
        { label: 'Genome', get: function (r) { return r.genome; } }
      ];
      tissues.forEach(function (t, i) {
        columns.push({ label: t.label, get: function (r) { return r.values[i] == null ? '' : r.values[i]; } });
      });
      columns.push({ label: 'Tau (these ten tissues)', get: function (r) { return r.tau == null ? '' : r.tau; } });
      if (window.MGDBRecord && window.MGDBRecord.downloadTsv) {
        window.MGDBRecord.downloadTsv(spec.filename || 'pan-gene-nam-expression.tsv', columns, rows);
      }
    });

    block.querySelector('[data-role="heat-png"]').addEventListener('click', function () {
      var svg = scroller.querySelector('svg');
      if (!svg) { return; }
      var M = 24, HEADER = 54;
      var w = +svg.getAttribute('width'), h = +svg.getAttribute('height');
      var clone = inlineSvgStyles(svg);
      clone.setAttribute('x', M);
      clone.setAttribute('y', HEADER);
      var body = xText(M, M + 6, 'Expression across the NAM founders', { size: 17, weight: 700, fill: '#1f2723' });
      body += xText(M, M + 26, rows.length + ' gene models · ' + matrix.source + ' · ' +
        (scale === 'row' ? 'each row scaled to its maximum' : matrix.scale + ', 0 to ' + globalMax.toFixed(1)) +
        ' · ordered by ' + block.querySelector('[data-role="heat-order"] option:checked').textContent.toLowerCase(),
        { size: 11, fill: '#5d6b62' });
      body += new XMLSerializer().serializeToString(clone);
      var lx = M, ly = HEADER + h + 16;
      for (var i = 0; i < 40; i++) {
        body += '<rect x="' + (lx + i * 4) + '" y="' + ly + '" width="4" height="10" fill="' + heatColour(i / 39) + '"></rect>';
      }
      body += xText(lx + 168, ly + 9, scale === 'row' ? '0 → row maximum' : '0 → ' + globalMax.toFixed(1),
        { size: 10, fill: '#5d6b62' });
      body += xText(M, ly + 30, 'MaizeGDB · hatched: not measured · τ over these ten tissues (Yanai 2005), blank where no tissue reaches 1',
        { size: 10, fill: '#7c837e' });
      exportSvgToPng(body, w + 2 * M, ly + 40,
        (spec.filename || 'pan-gene-nam-expression.tsv').replace(/\.tsv$/, '') + '.png');
    });

    var lastW = 0;
    function onResize() {
      if (!scroller.clientWidth || Math.abs(scroller.clientWidth - lastW) < 8) { return; }
      lastW = scroller.clientWidth; draw();
    }
    var debounced = MGDB.debounce ? MGDB.debounce(onResize, 150) : onResize;
    if (window.ResizeObserver) { new window.ResizeObserver(debounced).observe(scroller); }
    window.addEventListener('resize', debounced);

    /* Tree order when the tree loads, genome order until then and if it
       does not. */
    mode = 'genome';
    applyOrder();
    draw();
    lastW = scroller.clientWidth;
    if (spec.treeUrl && MGDB.parseNewick) {
      fetch(spec.treeUrl, { credentials: 'omit' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (text) {
          if (!text) { throw new Error('no tree'); }
          var idx = {}, n = 0;
          (function walk(node) {
            if (!node.children.length) { if (node.name) { idx[node.name] = n++; } return; }
            node.children.forEach(walk);
          })(MGDB.parseNewick(text));
          treeOrder = idx;
          mode = 'tree';
          block.querySelector('[data-role="heat-order"]').value = 'tree';
          applyOrder();
          draw();
        })
        .catch(function () {
          var sel = block.querySelector('[data-role="heat-order"]');
          sel.value = 'genome';
          sel.querySelector('option[value="tree"]').disabled = true;
        });
    } else {
      block.querySelector('[data-role="heat-order"]').value = 'genome';
      block.querySelector('[data-role="heat-order"] option[value="tree"]').disabled = true;
    }

    return { element: block };
  }

  MGDB.panGeneHeatmap = panGeneHeatmap;


  MGDB.parseNewick = parseNewick;


  MGDB.panGenePresence = panGenePresence;
})(window, document);
