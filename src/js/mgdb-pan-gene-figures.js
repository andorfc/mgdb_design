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
    if (window.ResizeObserver) {
      new window.ResizeObserver(debounced).observe(scroller);
    } else {
      window.addEventListener('resize', debounced);
    }

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
  MGDB.parseNewick = parseNewick;


  MGDB.panGenePresence = panGenePresence;
})(window, document);
