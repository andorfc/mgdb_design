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
 *          Nothing here reads the DOM at module scope.
 *
 * history:
 *  09/17/26  claude  created, with the presence/absence strip
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

    function clear() { detail.textContent = idle; }

    block.addEventListener('mouseover', function (event) {
      var btn = event.target.closest('[data-cell]');
      if (btn) { describe(cells[+btn.getAttribute('data-cell')]); }
    });
    block.addEventListener('mouseout', function (event) {
      var btn = event.target.closest('[data-cell]');
      if (btn && !block.contains(document.activeElement && document.activeElement.closest('[data-cell]'))) { clear(); }
    });
    block.addEventListener('focusin', function (event) {
      var btn = event.target.closest('[data-cell]');
      if (btn) { describe(cells[+btn.getAttribute('data-cell')]); }
    });
    block.addEventListener('focusout', function (event) {
      if (event.target.closest('[data-cell]')) { clear(); }
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

  MGDB.panGenePresence = panGenePresence;
})(window, document);
