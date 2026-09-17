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
 *              onSelect:  function (cell) called when a cell is clicked,
 *                         with {assembly, annotation, label, members[]}
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
          '<button class="mgdb-rec-tsv" type="button" data-role="tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
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
       filtered the table by a cell can undo it from the same place. onSelect
       is called with null for that. */
    block.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-cell]');
      if (!btn || !spec.onSelect) { return; }
      var was = btn.classList.contains('is-selected');
      Array.prototype.forEach.call(block.querySelectorAll('.mgdb-pg-cell.is-selected'), function (el) {
        el.classList.remove('is-selected');
      });
      if (was) { spec.onSelect(null); return; }
      btn.classList.add('is-selected');
      spec.onSelect(cells[+btn.getAttribute('data-cell')]);
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
          '<button class="mgdb-rec-tsv" type="button" data-role="arch-tsv">Download TSV</button>' +
        '</div>' +
      '</div>' +
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

  MGDB.panGenePresence = panGenePresence;
})(window, document);
