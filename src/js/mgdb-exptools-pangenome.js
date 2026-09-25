/* file: mgdb-exptools-pangenome.js
 *
 * purpose: Expression Tools -- the pan-genome tools and the reference views:
 *          one pan-gene across the 26 NAM genomes, the landscape of
 *          expression presence and absence over every pan-gene, two genomes
 *          compared gene by gene, the retained maize1/maize2 homeologs, RNA
 *          against protein, the sample catalog, and how the tools work.
 *
 *          The 26 NAM genomes (B73 v5 and the 25 founders) were measured in
 *          23 samples in common -- the NAM Consortium's ten tissues, Lin
 *          2017's five and Diepenbrock 2017's eight -- matched here by study
 *          and label, never by sample id, which is numbered per release.
 *
 * history:
 *  09/24/26  claude  created
 */
(function (window, document) {
  'use strict';

  var ET = window.MGDB.ET;
  var U = ET.util, UI = ET.ui, C = ET.chart, S = ET.stats, EX = ET.expr;
  var esc = U.esc, fmt = U.fmt, fmtInt = U.fmtInt, el = UI.el, qs = UI.qs;

  var STATE_LABEL = {
    expressed: 'expressed', low: 'low', silent: 'silent', absent: 'no member', 'not measured': 'not measured'
  };

  function namGenomes() { return (ET.state.boot.pangenome && ET.state.boot.pangenome.nam_genomes) || []; }
  function sharedSamples() { return ET.state.boot.shared_samples || []; }
  function studyShort(name) { return U.shortStudy(name); }
  function shortOf(key) { var g = ET.state.genomes.filter(function (x) { return x.key === key; })[0]; return g ? g.short : key; }

  /* The 23 shared samples arrive in the NAM release's column order, which
     interleaves the three studies, so the heatmap drew ten study bands for
     three studies and their names overprinted. Group them by study (in the
     order each first appears), keeping each study's own order, and permute
     every per-sample array with them. Display only: the API's order is the
     one pangenes.sqlite's top_sample indexes, so it stays as it is. */
  function groupByStudy(nam) {
    if (!nam || !nam.samples) { return nam; }
    var first = {};
    nam.samples.forEach(function (s, j) { if (first[s.source] == null) { first[s.source] = j; } });
    var ord = nam.samples.map(function (_, j) { return j; }).sort(function (a, b) {
      return first[nam.samples[a].source] - first[nam.samples[b].source] || a - b;
    });
    var perm = function (arr) { return arr ? ord.map(function (j) { return arr[j]; }) : arr; };
    return Object.assign({}, nam, {
      samples: perm(nam.samples),
      consensus: perm(nam.consensus),
      genomes: nam.genomes.map(function (g) {
        return Object.assign({}, g, { total: perm(g.total), members: g.members.map(function (m) { return Object.assign({}, m, { values: perm(m.values) }); }) });
      })
    });
  }

  /* ------------------------------------------------------------------------
     One pan-gene across the NAM genomes
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'pangene', group: 'Pan-genome', title: 'Pan-gene expression', nav: 'Pan-gene expression',
    summary: 'One pan-gene in every NAM genome that carries it: each copy’s expression in the 23 samples the genomes share, which lines express it, which carry it silent, and how alike their tissue profiles are.',
    card: 'A gene’s every copy across B73 and the 25 NAM founders in the 23 shared samples: who expresses it, who carries it silent, whose tissue profile departs.',
    render: function (root, ctx) {
      var id = ctx.get('id');
      var form = UI.panel({});
      var row = el('<form class="et-form-row"></form>');
      var gi = UI.geneInput({ value: id, label: 'Gene or pan-gene', placeholder: 'A gene of any NAM genome, a B73 symbol, or pan02070', onPick: function (g) { id = g; run(); } });
      row.appendChild(UI.field('Gene or pan-gene', gi, 'Any member of the pan-gene, in any of the 66 annotations'));
      row.appendChild(el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Show</button></div>'));
      row.addEventListener('submit', function (e) { e.preventDefault(); id = gi.value; run(); });
      UI.body(form).appendChild(row);
      var ex = el('<p class="et-examples"><span>Examples</span></p>');
      [['tb1', 'conserved everywhere'], ['lg1', 'silent in five lines'], ['ralf5', 'on in 11, off in 12'], ['rp1', 'a resistance cluster']].forEach(function (x) {
        var a = el('<a class="mgdb-chip"></a>');
        a.href = ET.href('pangene', { id: x[0] });
        a.textContent = x[0];
        a.title = x[1];
        ex.appendChild(a);
      });
      UI.body(form).appendChild(ex);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      function run() {
        if (!id) { return; }
        ctx.set({ id: id });
        out.innerHTML = '';
        out.appendChild(UI.loading('Reading every copy of ' + id + '…'));
        ET.api('pangene', { id: id }, { signal: ctx.signal }).then(function (d) {
          if (!ctx.alive()) { return; }
          out.innerHTML = '';
          drawPanGene(out, ctx, d);
        }, function (e) { if (ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      run();
    }
  });

  function drawPanGene(out, ctx, d) {
    var pg = d.pangene, nam = groupByStudy(d.nam);
    var mode = ctx.get('view', 'level'), copies = ctx.get('copies') === '1';
    /* ---- identity ---- */
    var head = UI.panel({});
    var hb = UI.body(head);
    var cls = { core: 'core: in all 26 NAM genomes', 'near-core': 'near-core: in 24 or 25', dispensable: 'dispensable: in 2 to 23', 'private': 'private: in one' }[pg.class] || '';
    hb.innerHTML = '<div class="et-gene-id"><h3 class="et-gene-title"><span class="et-mono">' + esc(pg.name.replace('pan-zea.v4.', '')) + '</span>' +
      (pg.b73_symbol ? ' <span class="et-gene-symbol">' + esc(pg.b73_symbol) + '</span>' : '') + '</h3>' +
      '<p class="et-muted">Pan-Zea v4 pan-gene ' + esc(pg.name) + (pg.b73 ? ' · B73 v5 <a class="et-gene-link" href="' + esc(ET.href('gene', { id: pg.b73, g: 'B73v5' }, { keepSelection: false })) + '">' + esc(pg.b73) + '</a>' : '') + ' · exemplar ' + esc(pg.exemplar_gene || pg.exemplar || '') + (pg.chr ? ' · ' + esc(pg.chr) : '') + '</p></div>' +
      '<dl class="et-facts"><div><dt>Class</dt><dd>' + esc(cls) + '</dd></div><div><dt>Members</dt><dd>' + fmtInt(pg.members) + ' gene models in ' + fmtInt(pg.annotations) + ' of 66 annotations</dd></div>' +
      '<div><dt>NAM genomes</dt><dd>' + pg.present + ' carry it · ' + pg.expressed + ' express it · ' + pg.low + ' low · ' + pg.silent + ' silent</dd></div>' +
      (pg.conservation != null ? '<div><dt>Profiles agree</dt><dd>mean r ' + pg.conservation.toFixed(2) + (pg.divergent ? ', least alike ' + esc(pg.divergent) + ' (' + pg.min_r.toFixed(2) + ')' : '') + '</dd></div>' : '') +
      '</dl><div class="et-gene-links"></div>';
    var links = qs('.et-gene-links', hb);
    var rec = el('<a class="mgdb-button mgdb-button-secondary mgdb-button-sm">Pan-gene record</a>');
    rec.href = '/pan_gene_center/pan_gene/' + encodeURIComponent(pg.exemplar_gene || pg.name);
    links.appendChild(rec);
    if (pg.b73) {
      var gr = el('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm">B73 v5 gene report</a>');
      gr.href = ET.href('gene', { id: pg.b73, g: 'B73v5' }, { keepSelection: false });
      links.appendChild(gr);
    }
    var members = [];
    nam.genomes.forEach(function (g) { g.members.forEach(function (m) { members.push(m.gene); }); });
    links.appendChild(UI.button('Copy the NAM member ids', { onClick: function () { U.copyText(members.join('\n')).then(function () { UI.toast('Copied ' + U.plural(members.length, 'gene model')); }); } }));
    out.appendChild(head);

    /* ---- presence across the 66 annotations ---- */
    var pres = UI.panel({ title: 'Presence in the 66 annotations', sub: 'A filled cell carries the pan-gene; a number counts its copies. NAM genomes are outlined.' });
    var panels = {};
    d.annotations.forEach(function (a) { (panels[a.panel_label] = panels[a.panel_label] || []).push(a); });
    var strip = el('<div class="et-presence"></div>');
    Object.keys(panels).forEach(function (label) {
      var blk = el('<div class="et-presence-panel"><h3 class="et-subhead"></h3><ul></ul></div>');
      qs('h3', blk).textContent = label + ' · ' + panels[label].filter(function (a) { return a.members.length; }).length + ' of ' + panels[label].length;
      var ul = qs('ul', blk);
      panels[label].forEach(function (a) {
        var li = el('<li' + (a.members.length ? ' class="is-present"' : '') + (a.nam_index != null ? ' data-nam="1"' : '') + '><span class="et-pcell"></span><span class="et-pname"></span></li>');
        qs('.et-pcell', li).textContent = a.members.length > 1 ? String(a.members.length) : '';
        qs('.et-pname', li).textContent = a.short;
        li.title = a.assembly + ': ' + (a.members.length ? a.members.join(', ') : 'no member');
        ul.appendChild(li);
      });
      strip.appendChild(blk);
    });
    UI.body(pres).appendChild(strip);
    if (d.unplaced.length) { UI.body(pres).appendChild(el('<p class="et-caption">' + U.plural(d.unplaced.length, 'member') + ' match no annotation of the analysis: ' + esc(d.unplaced.join(', ')) + '.</p>')); }
    out.appendChild(pres);

    /* ---- the matrix ---- */
    var viewCtl = UI.segmented([{ value: 'level', label: 'Level' }, { value: 'relative', label: 'Against the median genome' }], mode, function (v) { mode = v; ctx.set({ view: v === 'level' ? '' : v }); drawHeat(); }, 'Values');
    var copyCtl = UI.segmented([{ value: '0', label: 'One row per genome' }, { value: '1', label: 'Every copy' }], copies ? '1' : '0', function (v) { copies = v === '1'; ctx.set({ copies: copies ? '1' : '' }); drawHeat(); }, 'Rows');
    var hp = UI.panel({ title: 'Expression in the 23 shared samples' });
    var fig = C.figure({ label: 'Expression of every copy across the NAM genomes', filename: function () { return pg.name + '_nam_expression'; }, controls: [viewCtl, copyCtl], getTable: function () {
      var header = ['genome', 'gene models', 'state', 'r with median'].concat(nam.samples.map(function (s) { return studyShort(s.source) + ': ' + s.label; }));
      var rows = [];
      nam.genomes.forEach(function (g) {
        rows.push([g.short, g.members.map(function (m) { return m.gene; }).join(' '), g.state, g.r].concat(g.total || nam.samples.map(function () { return null; })));
        if (g.members.length > 1) { g.members.forEach(function (m) { rows.push(['  ' + g.short, m.gene, '', ''].concat(m.values || nam.samples.map(function () { return null; }))); }); }
      });
      return { header: header, numeric: [false, false, false, true].concat(nam.samples.map(function () { return true; })), rows: rows, notes: ['FPKM summed over a genome’s copies', pg.name] };
    } });
    UI.body(hp).appendChild(fig);
    var legendHost = fig.legendHost;
    out.appendChild(hp);

    function drawHeat() {
      var rows = [];
      nam.genomes.forEach(function (g) {
        rows.push({ label: g.short, genome: g, values: g.total, member: null });
        if (copies && g.members.length > 1) {
          g.members.forEach(function (m) { rows.push({ label: ' ' + m.gene, genome: g, values: m.values, member: m }); });
        }
      });
      var k = nam.samples.length;
      var cons = nam.consensus;
      var Z = rows.map(function (r) {
        return nam.samples.map(function (s, j) {
          var v = r.values ? r.values[j] : null;
          if (v == null) { return null; }
          var lv = S.log2p1(v);
          if (mode === 'relative') { return cons && cons[j] != null ? lv - cons[j] : null; }
          return lv;
        });
      });
      var zmax = 0;
      Z.forEach(function (r) { r.forEach(function (v) { if (v != null && Math.abs(v) > zmax) { zmax = Math.abs(v); } }); });
      var studyRuns = [];
      nam.samples.forEach(function (s, j) {
        var last = studyRuns[studyRuns.length - 1];
        if (last && last.source === s.source) { last.end = j; } else { studyRuns.push({ source: s.source, start: j, end: j }); }
      });
      var shapes = nam.samples.map(function (s, j) { return { type: 'rect', xref: 'x', yref: 'paper', x0: j - 0.5, x1: j + 0.5, y0: 1.004, y1: 1.03, fillcolor: C.tissueColor(s.tissue || 'other'), line: { width: 0 } }; });
      studyRuns.forEach(function (r, i) {
        if (i > 0) { shapes.push({ type: 'line', xref: 'x', yref: 'paper', x0: r.start - 0.5, x1: r.start - 0.5, y0: 0, y1: 1, line: { color: '#ffffff', width: 3 } }); }
      });
      var annotations = studyRuns.map(function (r) {
        return { xref: 'x', yref: 'paper', x: (r.start + r.end) / 2, y: 1.04, yanchor: 'bottom', showarrow: false, text: esc(studyShort(r.source)), font: { size: 11, color: ET.MUTED } };
      });
      var stateText = function (r) {
        if (r.member) { return r.member.values ? 'copy of ' + r.genome.short : 'copy not in the expression release'; }
        return STATE_LABEL[r.genome.state] + (r.genome.members.length > 1 ? ', ' + r.genome.members.length + ' copies' : '') + (r.genome.r != null ? ', r ' + r.genome.r.toFixed(2) : '');
      };
      C.plot(fig.plotNode, [{
        type: 'heatmap', z: Z, x: nam.samples.map(function (_, j) { return j; }), y: rows.map(function (_, i) { return i; }),
        zmin: mode === 'relative' ? -Math.min(4, Math.max(1, zmax)) : 0, zmax: mode === 'relative' ? Math.min(4, Math.max(1, zmax)) : Math.max(1, zmax),
        colorscale: mode === 'relative' ? C.divScale() : C.seqScale(), hoverongaps: false, xgap: 1, ygap: 1,
        customdata: rows.map(function (r) { return nam.samples.map(function (s, j) { return [esc(r.member ? r.member.gene : r.genome.short), esc(s.label), fmt(r.values ? r.values[j] : null), esc(stateText(r))]; }); }),
        hovertemplate: '<b>%{customdata[0]}</b> · %{customdata[3]}<br>%{customdata[1]}<br>%{customdata[2]} FPKM<extra></extra>',
        colorbar: { title: { text: mode === 'relative' ? 'log₂ vs median' : 'log₂(FPKM + 1)', side: 'right' }, thickness: 12, len: 0.7 }
      }], {
        xaxis: { tickmode: 'array', tickvals: nam.samples.map(function (_, j) { return j; }), ticktext: nam.samples.map(function (s) { return s.label.length > 24 ? s.label.slice(0, 22) + '…' : s.label; }), tickangle: -50, tickfont: { size: 10 }, showgrid: false },
        yaxis: { tickmode: 'array', tickvals: rows.map(function (_, i) { return i; }), ticktext: rows.map(function (r) {
          var mark = r.member ? '' : ({ expressed: '', low: ' ○', silent: ' ∅', absent: ' —', 'not measured': ' ?' }[r.genome.state] || '');
          return r.label + mark;
        }), autorange: 'reversed', tickfont: { size: 11 }, showgrid: false },
        shapes: shapes, annotations: annotations, margin: { l: copies ? 150 : 80, r: 12, t: 40, b: 150 }
      }, { height: Math.max(420, rows.length * 18 + 200) });
      var present = {};
      nam.samples.forEach(function (s) { present[s.tissue || 'other'] = (present[s.tissue || 'other'] || 0) + 1; });
      legendHost.innerHTML = '';
      legendHost.appendChild(C.legend('tissue', present));
      fig.caption.innerHTML = (mode === 'relative'
        ? 'Each cell is log₂ of the genome’s value over the median of the genomes that express the pan-gene: red above, blue below.'
        : 'Each cell is log₂(FPKM + 1), summed over a genome’s copies.') +
        ' Marks after a genome: ○ low (0.1 to 1 FPKM at most), ∅ silent (under 0.1 everywhere), — no member, ? a member that was not measured. Blank cells were not measured. The strip above marks each sample’s tissue.';
      fig.renderTable();
    }
    drawHeat();

    /* ---- profiles against the median ---- */
    if (nam.consensus) {
      var pp = UI.panel({ title: 'Tissue profiles', sub: 'Each expressing genome’s profile against the median of them all, on log₂(FPKM + 1)' });
      var pfig = C.figure({ label: 'Tissue profiles of the expressing genomes', filename: pg.name + '_profiles' });
      UI.body(pp).appendChild(pfig);
      out.appendChild(pp);
      var xs = nam.samples.map(function (_, j) { return j; });
      var expr = nam.genomes.filter(function (g) { return g.expressed; });
      var worst = expr.filter(function (g) { return g.r != null; }).sort(function (a, b) { return a.r - b.r; })[0];
      var traces = expr.filter(function (g) { return g !== worst; }).map(function (g) {
        return { type: 'scatter', mode: 'lines', name: g.short, x: xs, y: g.total.map(function (v) { return v == null ? null : S.log2p1(v); }), line: { color: '#c9ccc6', width: 1 }, showlegend: false, hoverinfo: 'name+y' };
      });
      traces.push({ type: 'scatter', mode: 'lines', name: 'median of the expressing genomes', x: xs, y: nam.consensus, line: { color: ET.SERIES[0], width: 3 } });
      if (worst) { traces.push({ type: 'scatter', mode: 'lines+markers', name: worst.short + ', least alike (r ' + worst.r.toFixed(2) + ')', x: xs, y: worst.total.map(function (v) { return v == null ? null : S.log2p1(v); }), line: { color: ET.SERIES[1], width: 2 }, marker: { size: 6 } }); }
      C.plot(pfig.plotNode, traces, { xaxis: { tickmode: 'array', tickvals: xs, ticktext: nam.samples.map(function (s) { return s.label.length > 24 ? s.label.slice(0, 22) + '…' : s.label; }), tickangle: -50, tickfont: { size: 10 } },
        yaxis: { title: { text: 'log₂(FPKM + 1)' }, rangemode: 'tozero' }, margin: { l: 60, r: 12, t: 12, b: 150 } }, { height: 420 });
      pfig.caption.textContent = expr.length + ' genomes express it; every other one is a gray line.';
    }

    /* ---- the genomes, as a table ---- */
    var mine = nam.genomes.filter(function (g) { return g.key === ET.state.genome.key; }).reduce(function (a, g) { return a.concat(g.members.map(function (m) { return m.gene; })); }, []);
    var tp = UI.panel({ title: 'The NAM genomes', actions: mine.length
      ? [ET.basketButton(mine, (ET.state.genome.short || 'This genome') + '’s ' + (mine.length === 1 ? 'copy' : mine.length + ' copies') + ' to basket')]
      : [] });
    out.appendChild(tp);
    var rows = nam.genomes.map(function (g) {
      var peak = null, top = null;
      (g.total || []).forEach(function (v, j) { if (v != null && (peak == null || v > peak)) { peak = v; top = nam.samples[j]; } });
      return { gene: g.short, g: g, peak: peak, top: top ? top.label : '', copies: g.members.length };
    });
    new ET.DataTable(UI.body(tp), { rows: rows, rowKey: 'gene', exportName: pg.name + '_nam_genomes', pageSize: 'all', filter: false, caption: 'The pan-gene in each NAM genome',
      columns: [
        { key: 'gene', label: 'Genome', type: 'str' },
        { key: 'state', label: 'State', type: 'str', value: function (r) { return STATE_LABEL[r.g.state]; }, render: function (r) { return '<span class="et-state is-' + esc(r.g.state.replace(' ', '-')) + '">' + esc(STATE_LABEL[r.g.state]) + '</span>'; } },
        { key: 'copies', label: 'Copies', type: 'num', int: true },
        { key: 'members', label: 'Gene models', type: 'str', value: function (r) { return r.g.members.map(function (m) { return m.gene; }).join(' '); }, render: function (r) {
          return r.g.members.map(function (m) { return r.g.key ? '<a class="et-mono" href="' + esc(ET.href('gene', { id: m.gene, g: r.g.key }, { keepSelection: false })) + '">' + esc(m.gene) + '</a>' : esc(m.gene); }).join('<br>') || '—';
        } },
        { key: 'peak', label: 'Highest FPKM', type: 'num' },
        { key: 'top', label: 'Highest in', type: 'str' },
        { key: 'r', label: 'r with median', type: 'num', value: function (r) { return r.g.r; }, render: function (r) { return r.g.r == null ? '—' : r.g.r.toFixed(2); } }
      ] });
  }

  /* ------------------------------------------------------------------------
     The landscape: expression presence and absence over every pan-gene
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'landscape', group: 'Pan-genome', title: 'Presence and expression landscape', nav: 'Presence and expression',
    summary: 'Every pan-gene by how many NAM genomes carry it and how many express it, from the 23 samples they share. Pan-genes carried by a genome but silent there are expression presence/absence variation.',
    card: 'Every pan-gene by how many of the 26 NAM genomes carry it and how many express it: find genes silent in some lines, and lines that silence many.',
    render: function (root, ctx) {
      /* "any" is written to the hash as 0 (an absent value means the default
         of 1); the selects know it as the empty option. */
      var anyOr = function (v) { return v === '0' ? '' : v; };
      var f = {
        class: ctx.get('class'), chr: ctx.get('chr'), silent_in: ctx.get('silent_in'), expressed_in: ctx.get('expressed_in'), absent_in: ctx.get('absent_in'),
        min_silent: anyOr(ctx.get('min_silent', '1')), min_expressed: anyOr(ctx.get('min_expressed', '1')), max_conservation: ctx.get('max_conservation'), q: ctx.get('q'),
        present: ctx.get('present'), expressed: ctx.get('expressed')
      };
      var sort = ctx.get('sort', 'variation'), offset = +ctx.get('offset', 0) || 0, limit = 50;
      var form = UI.panel({});
      var row = el('<form class="et-form-row et-form-wrap"></form>');
      var classSel = UI.select([{ value: '', label: 'Every class' }, { value: 'core', label: 'Core: all 26 genomes' }, { value: 'near-core', label: 'Near-core: 24 or 25' },
        { value: 'dispensable', label: 'Dispensable: 2 to 23' }, { value: 'private', label: 'Private: one genome' }], f.class);
      row.appendChild(UI.field('Pan-gene class', classSel));
      var genomeOpts = [{ value: '', label: 'Any genome' }].concat(namGenomes().map(function (g) { return { value: g.short.replace(/\s/g, ''), label: g.short }; }));
      var silentSel = UI.select(genomeOpts, f.silent_in);
      row.appendChild(UI.field('Silent in', silentSel));
      var exprSel = UI.select(genomeOpts, f.expressed_in);
      row.appendChild(UI.field('Expressed in', exprSel));
      var absentSel = UI.select(genomeOpts, f.absent_in);
      row.appendChild(UI.field('Missing from', absentSel));
      var minSil = UI.select([{ value: '', label: 'any' }, { value: '1', label: '1 or more' }, { value: '3', label: '3 or more' }, { value: '5', label: '5 or more' }, { value: '10', label: '10 or more' }], f.min_silent);
      row.appendChild(UI.field('Genomes silent', minSil));
      var minExp = UI.select([{ value: '', label: 'any' }, { value: '1', label: '1 or more' }, { value: '5', label: '5 or more' }, { value: '13', label: '13 or more' }, { value: '20', label: '20 or more' }], f.min_expressed);
      row.appendChild(UI.field('Genomes expressing', minExp));
      var consSel = UI.select([{ value: '', label: 'any' }, { value: '0.5', label: 'below 0.5' }, { value: '0.7', label: 'below 0.7' }, { value: '0.9', label: 'below 0.9' }], f.max_conservation);
      row.appendChild(UI.field('Profiles agree at', consSel, 'mean r between genomes'));
      var chrSel = UI.select([{ value: '', label: 'Any' }].concat([1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(function (c) { return { value: 'chr' + c, label: 'chr' + c }; })), f.chr);
      row.appendChild(UI.field('Chromosome', chrSel));
      var qIn = el('<input type="search" class="et-input" placeholder="B73 symbol or id">'); qIn.value = f.q;
      row.appendChild(UI.field('Gene', qIn));
      row.appendChild(el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Apply</button></div>'));
      row.addEventListener('submit', function (e) {
        e.preventDefault();
        f.class = classSel.value; f.silent_in = silentSel.value; f.expressed_in = exprSel.value; f.absent_in = absentSel.value;
        f.min_silent = minSil.value; f.min_expressed = minExp.value; f.max_conservation = consSel.value; f.chr = chrSel.value; f.q = qIn.value.trim();
        f.present = ''; f.expressed = ''; offset = 0;
        run();
      });
      UI.body(form).appendChild(row);
      var rules = ET.state.boot.pangenome || {};
      UI.body(form).appendChild(el('<p class="et-muted">' + esc(rules.expressed_rule || '') + ' ' + esc(rules.class_rule || '') + '</p>'));
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      var seq = 0;
      function params(extra) {
        var p = {};
        Object.keys(f).forEach(function (k) { if (f[k]) { p[k] = f[k]; } });
        return Object.assign(p, extra || {});
      }
      function run() {
        ctx.set(Object.assign(params(), { sort: sort === 'variation' ? '' : sort, min_silent: f.min_silent === '1' ? '' : (f.min_silent || '0'), min_expressed: f.min_expressed === '1' ? '' : (f.min_expressed || '0'), offset: offset || '' }));
        var my = ++seq;
        out.innerHTML = '';
        out.appendChild(UI.loading('Counting pan-genes…'));
        ET.api('landscape', params({ sort: sort, limit: limit, offset: offset }), { signal: ctx.signal }).then(function (r) {
          if (my !== seq || !ctx.alive()) { return; }
          show(r);
        }, function (e) { if (my === seq && ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function show(r) {
        out.innerHTML = '';
        var cellTxt = f.present ? ' in the cell of ' + f.present + ' genomes carrying, ' + f.expressed + ' expressing' : '';
        var p = UI.panel({ title: fmtInt(r.total) + ' pan-genes' + esc(cellTxt), sub: fmtInt(r.with_silent) + ' are silent in at least one genome that carries them' + (r.mean_conservation != null ? ' · profiles agree at mean r ' + r.mean_conservation.toFixed(2) : '') });
        out.appendChild(p);
        var grid = el('<div class="et-grid2"></div>');
        var gfig = C.figure({ label: 'Pan-genes by genomes carrying and genomes expressing', filename: 'pangene_landscape', getTable: function () {
          return { header: ['genomes carrying', 'genomes expressing', 'pan-genes'], numeric: [true, true, true], rows: r.grid.map(function (c) { return [c[0], c[1], c[2]]; }) };
        } });
        var sfig = C.figure({ label: 'Pan-genes each genome carries silent', filename: 'silent_by_genome', getTable: function () {
          return { header: ['genome', 'pan-genes silent'], numeric: [false, true], rows: r.silent_by_genome.map(function (x) { return [x.short, x.silent]; }) };
        } });
        grid.appendChild(gfig);
        grid.appendChild(sfig);
        UI.body(p).appendChild(grid);
        /* Grid: x genomes carrying 1..26, y genomes expressing 0..26. */
        var Z = [];
        for (var y = 0; y <= 26; y++) { Z.push(new Array(26).fill(null)); }
        var maxN = 0;
        r.grid.forEach(function (c) { Z[c[1]][c[0] - 1] = c[2]; if (c[2] > maxN) { maxN = c[2]; } });
        var Zl = Z.map(function (rr) { return rr.map(function (v) { return v == null ? null : Math.log(v) / Math.LN10; }); });
        var sel = f.present ? [{ type: 'rect', xref: 'x', yref: 'y', x0: +f.present - 0.5, x1: +f.present + 0.5, y0: +f.expressed - 0.5, y1: +f.expressed + 0.5, line: { color: ET.SERIES[1], width: 3 } }] : [];
        sel.push({ type: 'line', xref: 'x', yref: 'y', x0: 0.5, y0: 0.5, x1: 26.5, y1: 26.5, line: { color: '#9a9994', width: 1 } });
        C.plot(gfig.plotNode, [{ type: 'heatmap', z: Zl, x: Array.apply(null, Array(26)).map(function (_, i) { return i + 1; }), y: Array.apply(null, Array(27)).map(function (_, i) { return i; }),
          colorscale: C.seqScale(), zmin: 0, zmax: Math.max(1, Math.log(maxN) / Math.LN10), hoverongaps: false, xgap: 1, ygap: 1,
          customdata: Z.map(function (rr, yy) { return rr.map(function (v, xx) { return [v == null ? 0 : v, xx + 1, yy]; }); }),
          hovertemplate: '<b>%{customdata[0]:,}</b> pan-genes<br>carried by %{customdata[1]}, expressed in %{customdata[2]}<extra></extra>',
          colorbar: { title: { text: 'pan-genes', side: 'right' }, thickness: 12, len: 0.7, tickvals: [0, 1, 2, 3, 4], ticktext: ['1', '10', '100', '1,000', '10,000'] } }],
          { xaxis: { title: { text: 'NAM genomes carrying it' }, dtick: 5, range: [0.5, 26.5] }, yaxis: { title: { text: 'genomes expressing it' }, dtick: 5, range: [-0.5, 26.5] },
            shapes: sel, margin: { l: 60, r: 12, t: 10, b: 50 } }, { height: 420 }).then(function () {
          gfig.plotNode.on('plotly_click', function (ev) {
            var pt = ev.points && ev.points[0];
            if (!pt || pt.z == null) { return; }
            f.present = String(pt.x); f.expressed = String(pt.y); offset = 0;
            run();
          });
        });
        gfig.caption.innerHTML = 'Click a cell to list its pan-genes. On the diagonal every genome that carries a pan-gene expresses it; below it, some carry it silent or low.' +
          (f.present ? ' <a href="#" data-clear>Show every cell again</a>' : '');
        var clr = qs('[data-clear]', gfig.caption);
        if (clr) { clr.addEventListener('click', function (e) { e.preventDefault(); f.present = ''; f.expressed = ''; offset = 0; run(); }); }
        var sb = r.silent_by_genome.slice();
        C.plot(sfig.plotNode, [{ type: 'bar', orientation: 'h', x: sb.map(function (x) { return x.silent; }), y: sb.map(function (_, i) { return i; }), marker: { color: ET.SERIES[0] },
          hovertemplate: '%{x:,} pan-genes silent<extra></extra>' }],
          { xaxis: { title: { text: 'pan-genes carried but silent' } }, yaxis: { tickmode: 'array', tickvals: sb.map(function (_, i) { return i; }), ticktext: sb.map(function (x) { return x.short; }), autorange: 'reversed', tickfont: { size: 10 } },
            showlegend: false, margin: { l: 10, r: 12, t: 10, b: 50 } }, { height: 420 });
        sfig.caption.textContent = 'Within the current filters. A genome with many silent pan-genes is where to look for lost or silenced function.';

        /* the list */
        var sortSel = UI.select([{ value: 'variation', label: 'Most variable: expressed in some, silent in others' }, { value: 'silent', label: 'Most genomes silent' },
          { value: 'divergent', label: 'Least alike tissue profiles' }, { value: 'conserved', label: 'Most alike tissue profiles' }, { value: 'level', label: 'Widest spread of level' },
          { value: 'max', label: 'Highest expression' }, { value: 'name', label: 'Pan-gene number' }], sort, 'et-select-sm');
        sortSel.setAttribute('aria-label', 'Sort');
        sortSel.addEventListener('change', function () { sort = sortSel.value; offset = 0; run(); });
        var tsv = el('<a class="mgdb-button mgdb-button-quiet mgdb-button-sm">Download all ' + fmtInt(r.total) + ' as TSV</a>');
        var qsParams = params({ action: 'landscape', format: 'tsv', sort: sort });
        tsv.href = document.getElementById('et-app').getAttribute('data-api') + '?' + Object.keys(qsParams).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(qsParams[k]); }).join('&');
        var lp = UI.panel({ title: 'Pan-genes', actions: [sortSel, tsv] });
        out.appendChild(lp);
        var tbl = el('<div class="mgdb-table-scroll" tabindex="0"><table class="mgdb-table et-table"><caption class="mgdb-visually-hidden">Pan-genes</caption><thead><tr>' +
          '<th scope="col">Pan-gene</th><th scope="col">B73 v5</th><th scope="col">Class</th><th scope="col" class="mgdb-numeric">Carry</th><th scope="col" class="mgdb-numeric">Express</th>' +
          '<th scope="col" class="mgdb-numeric">Low</th><th scope="col" class="mgdb-numeric">Silent</th><th scope="col">Silent in</th><th scope="col" class="mgdb-numeric">Profiles r</th><th scope="col" class="mgdb-numeric">Highest FPKM</th></tr></thead><tbody></tbody></table></div>');
        var tb = qs('tbody', tbl);
        tb.innerHTML = r.rows.map(function (x) {
          return '<tr><td><a class="et-mono" href="' + esc(ET.href('pangene', { id: x.name })) + '">' + esc(x.name.replace('pan-zea.v4.', '')) + '</a></td>' +
            '<td>' + (x.b73 ? '<a class="et-mono" href="' + esc(ET.href('gene', { id: x.b73, g: 'B73v5' }, { keepSelection: false })) + '">' + esc(x.b73) + '</a>' + (x.b73_symbol ? ' <span class="et-sym">' + esc(x.b73_symbol) + '</span>' : '') : '<span class="et-muted">no B73 copy</span>') + '</td>' +
            '<td>' + esc(x.class || '') + '</td><td class="mgdb-numeric">' + x.present + '</td><td class="mgdb-numeric">' + x.expressed + '</td><td class="mgdb-numeric">' + x.low + '</td><td class="mgdb-numeric">' + x.silent + '</td>' +
            '<td class="et-silent-list">' + esc(x.silent_in.join(', ')) + '</td><td class="mgdb-numeric">' + (x.conservation == null ? '—' : x.conservation.toFixed(2)) + '</td><td class="mgdb-numeric">' + fmt(x.max) + '</td></tr>';
        }).join('') || '<tr><td colspan="10" class="et-dt-empty">No pan-gene matches these filters.</td></tr>';
        UI.body(lp).appendChild(tbl);
        var pager = el('<nav class="et-dt-pager" aria-label="Pan-gene pages"></nav>');
        var prev = UI.button('Previous', { onClick: function () { offset = Math.max(0, offset - limit); run(); } });
        var next = UI.button('Next', { onClick: function () { offset += limit; run(); } });
        prev.disabled = offset === 0;
        next.disabled = offset + limit >= r.total;
        pager.appendChild(prev);
        pager.appendChild(el('<span class="et-dt-page">' + fmtInt(Math.min(r.total, offset + 1)) + '–' + fmtInt(Math.min(r.total, offset + limit)) + ' of ' + fmtInt(r.total) + '</span>'));
        pager.appendChild(next);
        UI.body(lp).appendChild(pager);
        var b73 = r.rows.filter(function (x) { return x.b73; }).map(function (x) { return x.b73; });
        if (b73.length) {
          var bl = el('<p class="et-caption"></p>');
          var a = el('<a></a>');
          a.href = ET.href('heatmap', { genes: b73.join(','), g: 'B73v5' }, { keepSelection: false });
          a.textContent = 'This page’s B73 v5 genes in the heatmap';
          bl.appendChild(a);
          bl.appendChild(document.createTextNode(' · '));
          var a2 = el('<a></a>');
          a2.href = ET.href('enrich', { genes: b73.join(','), g: 'B73v5' }, { keepSelection: false });
          a2.textContent = 'their GO enrichment';
          bl.appendChild(a2);
          UI.body(lp).appendChild(bl);
        }
      }
      run();
    }
  });

  /* ------------------------------------------------------------------------
     Two NAM genomes, gene by gene
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'pairs', group: 'Pan-genome', title: 'Genome pairs', nav: 'Genome pairs',
    summary: 'Every gene of one NAM genome against its counterpart in another, paired through the pan-genes, in the samples they share: which genes change level and which change pattern.',
    card: 'Two NAM genomes paired gene by gene through the pan-genes: level and tissue pattern, and the genes one carries and the other does not.',
    render: function (root, ctx) {
      var nam = namGenomes();
      var keyOf = function (g) { return g.short.replace(/\s/g, ''); };
      var a = ctx.get('a', 'B73v5'), b = ctx.get('b', 'Mo18W'), stat = ctx.get('stat', 'mean'), set = ctx.get('set', 'all');
      if (!nam.some(function (g) { return keyOf(g) === b; })) { b = nam[1] ? keyOf(nam[1]) : 'Oh7B'; }
      var form = UI.panel({});
      var row = el('<form class="et-form-row"></form>');
      var opts = nam.map(function (g) { return { value: keyOf(g), label: g.short }; });
      var aSel = UI.select(opts, a), bSel = UI.select(opts, b);
      row.appendChild(UI.field('Genome A (x axis)', aSel));
      row.appendChild(UI.field('Genome B (y axis)', bSel));
      var shared = sharedSamples();
      var setOpts = [{ value: 'all', label: 'All 23 shared samples' }];
      var sources = [];
      shared.forEach(function (s) { if (sources.indexOf(s.source) === -1) { sources.push(s.source); } });
      sources.forEach(function (src) { setOpts.push({ value: 'study:' + src, label: studyShort(src) + ' (' + shared.filter(function (s) { return s.source === src; }).length + ')' }); });
      setOpts.push({ group: 'One sample', options: shared.map(function (s, j) { return { value: 'sample:' + j, label: s.label + ' — ' + studyShort(s.source) }; }) });
      var setSel = UI.select(setOpts, set);
      row.appendChild(UI.field('Samples', setSel));
      var statSel = UI.select([{ value: 'mean', label: 'Mean' }, { value: 'max', label: 'Highest' }], stat);
      row.appendChild(UI.field('Value', statSel));
      row.appendChild(el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Pair the genomes</button></div>'));
      row.addEventListener('submit', function (e) { e.preventDefault(); a = aSel.value; b = bSel.value; stat = statSel.value; set = setSel.value; run(); });
      UI.body(form).appendChild(row);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      function sampleIdx() {
        if (set === 'all') { return []; }
        if (set.indexOf('study:') === 0) { var src = set.slice(6); return shared.map(function (s, j) { return s.source === src ? j : -1; }).filter(function (j) { return j >= 0; }); }
        if (set.indexOf('sample:') === 0) { return [+set.slice(7)]; }
        return [];
      }
      function run() {
        ctx.set({ a: a, b: b, stat: stat === 'mean' ? '' : stat, set: set === 'all' ? '' : set });
        if (a === b) { out.innerHTML = ''; out.appendChild(UI.message('Choose two different genomes.', 'info')); return; }
        out.innerHTML = '';
        out.appendChild(UI.loading('Pairing every ' + a + ' gene with its ' + b + ' counterpart…'));
        ET.api('pairs', { a: a, b: b, samples: sampleIdx().join(','), stat: stat }, { signal: ctx.signal }).then(function (d) {
          if (!ctx.alive()) { return; }
          show(d);
        }, function (e) { if (ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function show(d) {
        out.innerHTML = '';
        var n = d.a.length;
        var rows = [];
        for (var i = 0; i < n; i++) {
          rows.push({ a: d.a[i], b: d.b[i], kind: d.kind[i], pan: d.pan[i], va: d.va[i], vb: d.vb[i], r: d.r[i],
                      lfc: Math.log((d.vb[i] + 1) / (d.va[i] + 1)) / Math.LN2 });
        }
        var one = rows.filter(function (r) { return r.kind === '1:1'; });
        var rs = S.spearman(one.map(function (r) { return r.va; }), one.map(function (r) { return r.vb; }));
        var what = (d.stat === 'max' ? 'highest' : 'mean') + ' over ' + (d.samples.length === 23 ? 'the 23 shared samples' : U.plural(d.samples.length, 'shared sample'));
        /* Keys (B73v5) name files and links; people read the short names. */
        var la = shortOf(d.genome_a), lb = shortOf(d.genome_b);
        var p = UI.panel({ title: esc(la) + ' against ' + esc(lb) + ': ' + fmtInt(rows.length) + ' gene pairs',
          sub: fmtInt(one.length) + ' one-to-one · ' + fmtInt(d.pangenes_only_a) + ' pan-genes only ' + esc(la) + ' carries, ' + fmtInt(d.pangenes_only_b) + ' only ' + esc(lb) +
               ' · ' + fmtInt(d.pairs_not_measured) + ' pairs with a copy not measured · Spearman ρ of the one-to-one pairs ' + U.fmtR(rs) + ' · value: ' + what });
        out.appendChild(p);
        var grid = el('<div class="et-grid2"></div>');
        var sfig = C.figure({ label: la + ' against ' + lb, filename: 'pairs_' + d.genome_a + '_' + d.genome_b });
        var rfig = C.figure({ label: 'How alike the paired tissue profiles are', filename: 'pairs_r_' + d.genome_a + '_' + d.genome_b });
        grid.appendChild(sfig);
        grid.appendChild(rfig);
        UI.body(p).appendChild(grid);
        var groups = [['1:1', 'one to one', ET.SERIES[0]], ['multi', 'more than one copy', ET.SERIES[1]]];
        var mx = 1;
        rows.forEach(function (r) { mx = Math.max(mx, S.log2p1(r.va), S.log2p1(r.vb)); });
        C.plot(sfig.plotNode, groups.map(function (gr) {
          var sub = rows.filter(function (r) { return (r.kind === '1:1') === (gr[0] === '1:1'); });
          return { type: 'scattergl', mode: 'markers', name: gr[1] + ' (' + fmtInt(sub.length) + ')', x: sub.map(function (r) { return S.log2p1(r.va); }), y: sub.map(function (r) { return S.log2p1(r.vb); }),
                   marker: { size: 4, color: gr[2], opacity: 0.5 }, text: sub.map(function (r) { return r.a; }),
                   customdata: sub.map(function (r) { return [r.b, fmt(r.va), fmt(r.vb), r.kind, r.r == null ? 'n/a' : r.r.toFixed(2)]; }),
                   hovertemplate: '<b>%{text}</b> / %{customdata[0]}<br>' + esc(la) + ' %{customdata[1]} · ' + esc(lb) + ' %{customdata[2]}<br>copies %{customdata[3]} · profile r %{customdata[4]}<extra></extra>' };
        }).concat([{ type: 'scatter', mode: 'lines', x: [0, mx], y: [0, mx], line: { color: '#9a9994', width: 1 }, hoverinfo: 'skip', showlegend: false }]),
          { xaxis: { title: { text: la + ' · log₂(FPKM + 1)' } }, yaxis: { title: { text: lb + ' · log₂(FPKM + 1)' } }, margin: { l: 64, r: 12, t: 12, b: 52 } }, { height: 440 }).then(function () {
          sfig.plotNode.on('plotly_click', function (ev) { var pt = ev.points && ev.points[0]; if (pt) { ET.go('pangene', { id: pt.text }); } });
        });
        sfig.caption.textContent = 'One point per pair of copies; click one to open its pan-gene. The gray line is equal expression.';
        var hist = new Array(20).fill(0), withR = rows.filter(function (r) { return r.r != null; });
        withR.forEach(function (r) { hist[Math.min(19, Math.max(0, Math.floor((r.r + 1) * 10)))]++; });
        var hx = hist.map(function (_, i) { return -1 + 0.1 * (i + 0.5); });
        if (!withR.length) {
          C.clear(rfig.plotNode);
          rfig.plotNode.appendChild(UI.message('No pair has a profile r: it needs the two copies measured in at least 8 of the 23 shared samples.', 'info'));
          rfig.caption.textContent = '';
        } else {
          C.plot(rfig.plotNode, [{ type: 'bar', x: hx, y: hist, width: 0.09, marker: { color: ET.SERIES[0] }, hovertemplate: 'r %{x:.2f}: %{y:,} pairs<extra></extra>' }],
            { xaxis: { title: { text: 'r between the two copies’ tissue profiles' }, range: [-1, 1] }, yaxis: { title: { text: 'pairs' } }, showlegend: false, margin: { l: 64, r: 12, t: 12, b: 52 } }, { height: 440 });
          rfig.caption.textContent = 'Profile r is over all 23 shared samples whatever the sample set above chooses for the level; ' + fmtInt(withR.length) + ' pairs are measured in at least 8 of them. A pair near 1 keeps its tissue pattern; one near 0 or below has changed it.';
        }
        var tp = UI.panel({ title: 'The pairs', sub: 'Sorted by the size of the change; the profile r column finds pairs whose level agrees but pattern does not' });
        out.appendChild(tp);
        rows.forEach(function (r) { r.key = r.a + '|' + r.b; r.abs = Math.abs(r.lfc); });
        new ET.DataTable(UI.body(tp), { rows: rows, rowKey: 'key', exportName: 'pairs_' + d.genome_a + '_' + d.genome_b, sort: { key: 'abs', dir: 'desc' }, caption: 'Gene pairs between the two genomes',
          notes: ['A: ' + la + ', B: ' + lb, 'value: ' + what, 'pairs from the Pan-Zea v4 pan-genes'],
          columns: [
            { key: 'a', label: esc(la), exportLabel: la, type: 'str', render: function (r) { return '<a class="et-mono" href="' + esc(ET.href('gene', { id: r.a, g: d.genome_a }, { keepSelection: false })) + '">' + esc(r.a) + '</a>'; } },
            { key: 'b', label: esc(lb), exportLabel: lb, type: 'str', render: function (r) { return '<a class="et-mono" href="' + esc(ET.href('gene', { id: r.b, g: d.genome_b }, { keepSelection: false })) + '">' + esc(r.b) + '</a>'; } },
            { key: 'kind', label: 'Copies', type: 'str' },
            { key: 'pan', label: 'Pan-gene', type: 'num', render: function (r) { return '<a class="et-mono" href="' + esc(ET.href('pangene', { id: r.a })) + '">pan' + ('0000' + r.pan).slice(-5) + '</a>'; } },
            { key: 'va', label: esc(la), exportLabel: la + ' FPKM', type: 'num', heat: true },
            { key: 'vb', label: esc(lb), exportLabel: lb + ' FPKM', type: 'num', heat: true },
            { key: 'lfc', label: 'log₂ B/A', type: 'num', render: function (r) { return '<span class="et-r ' + (r.lfc >= 0 ? 'is-pos' : 'is-neg') + '">' + r.lfc.toFixed(2) + '</span>'; } },
            { key: 'abs', label: '|change|', type: 'num', render: function (r) { return r.abs.toFixed(2); } },
            { key: 'r', label: 'Profile r', type: 'num', render: function (r) { return r.r == null ? '—' : r.r.toFixed(2); } }
          ] });
      }
      run();
    }
  });

  /* ------------------------------------------------------------------------
     Homeologs
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'homeologs', group: 'Pan-genome', title: 'Homeolog expression', nav: 'Homeologs',
    summary: 'The 4,395 retained maize1/maize2 gene pairs placed on B73 v5, each pair’s expression over the selected samples: which subgenome’s copy dominates, and whether the two copies still share a pattern.',
    card: 'The retained maize1/maize2 duplicate pairs of B73 v5: which subgenome’s copy dominates, and which pairs have split their tissue patterns.',
    selection: true,
    requires: function (G) { return G.key === 'B73v5' ? null : 'The homeolog pairs are placed on B73 v5.'; },
    render: function (root, ctx) {
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      var seq = 0;
      function run() {
        var my = ++seq;
        out.innerHTML = '';
        out.appendChild(UI.loading('Comparing the copies of every pair…'));
        ET.api('homeologs', { s: ET.SEL.api() }, { signal: ctx.signal }).then(function (d) {
          if (my !== seq || !ctx.alive()) { return; }
          show(d);
        }, function (e) { if (my === seq && ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function show(d) {
        out.innerHTML = '';
        var order = ['maize1 higher', 'balanced', 'maize2 higher', 'maize1 only', 'maize2 only', 'neither detected'];
        var c = d.classes;
        var dom1 = (c['maize1 higher'] || 0) + (c['maize1 only'] || 0), dom2 = (c['maize2 higher'] || 0) + (c['maize2 only'] || 0);
        var p = UI.panel({ title: fmtInt(d.pairs.length) + ' pairs over ' + U.plural(d.samples, 'sample'),
          sub: 'maize1’s copy is higher in ' + fmtInt(dom1) + ', maize2’s in ' + fmtInt(dom2) + ' (two-fold on the mean, or detected in one copy only)' });
        out.appendChild(p);
        var grid = el('<div class="et-grid2"></div>');
        var cfig = C.figure({ label: 'Pairs by which copy is higher', filename: 'homeolog_classes', getTable: function () {
          return { header: ['class', 'pairs'], numeric: [false, true], rows: order.map(function (k) { return [k, c[k] || 0]; }) };
        } });
        var sfig = C.figure({ label: 'Mean expression of the two copies', filename: 'homeolog_scatter' });
        grid.appendChild(cfig);
        grid.appendChild(sfig);
        UI.body(p).appendChild(grid);
        C.plot(cfig.plotNode, [{ type: 'bar', orientation: 'h', x: order.map(function (k) { return c[k] || 0; }), y: order.map(function (_, i) { return i; }), marker: { color: ET.SERIES[0] },
          text: order.map(function (k) { return ' ' + fmtInt(c[k] || 0); }), textposition: 'outside', cliponaxis: false, hovertemplate: '%{x:,} pairs<extra></extra>' }],
          { xaxis: { title: { text: 'pairs' } }, yaxis: { tickmode: 'array', tickvals: order.map(function (_, i) { return i; }), ticktext: order, autorange: 'reversed' }, showlegend: false, margin: { l: 10, r: 40, t: 10, b: 50 } }, { height: 320 });
        cfig.caption.textContent = 'Higher: the copies’ means differ at least two-fold. Only: one copy never reaches 1 in the selection.';
        var cls3 = function (k) { return k.indexOf('maize1') === 0 ? 'maize1' : (k.indexOf('maize2') === 0 ? 'maize2' : 'neither'); };
        var groups = [['maize1', 'maize1 copy higher', ET.SERIES[0]], ['maize2', 'maize2 copy higher', ET.SERIES[1]], ['neither', 'balanced or undetected', '#b9bdb6']];
        C.plot(sfig.plotNode, groups.map(function (gr) {
          var sub = d.pairs.filter(function (x) { return cls3(x.class) === gr[0]; });
          return { type: 'scattergl', mode: 'markers', name: gr[1] + ' (' + fmtInt(sub.length) + ')', x: sub.map(function (x) { return S.log2p1(x.mean1); }), y: sub.map(function (x) { return S.log2p1(x.mean2); }),
                   marker: { size: 5, color: gr[2], opacity: 0.6 }, text: sub.map(function (x) { return x.m1; }),
                   customdata: sub.map(function (x) { return [x.m2, fmt(x.mean1), fmt(x.mean2), x.r == null ? 'n/a' : x.r.toFixed(2), (x.m1_symbol || '') + (x.m2_symbol ? ' / ' + x.m2_symbol : '')]; }),
                   hovertemplate: '<b>%{text}</b> / %{customdata[0]} %{customdata[4]}<br>maize1 %{customdata[1]} · maize2 %{customdata[2]}<br>profile r %{customdata[3]}<extra></extra>' };
        }), { xaxis: { title: { text: 'maize1 copy · mean log₂(value + 1)' } }, yaxis: { title: { text: 'maize2 copy · mean log₂(value + 1)' } }, margin: { l: 64, r: 12, t: 12, b: 52 } }, { height: 380 }).then(function () {
          sfig.plotNode.on('plotly_click', function (ev) { var pt = ev.points && ev.points[0]; if (pt) { ET.go('compare', { g1: pt.text, g2: pt.customdata[0] }); } });
        });
        sfig.caption.textContent = 'Click a pair to compare its two copies sample by sample.';
        var rfig = C.figure({ label: 'How alike the two copies’ patterns are', filename: 'homeolog_r' });
        var rp = UI.panel({ title: 'Do the copies share a pattern?' });
        UI.body(rp).appendChild(rfig);
        out.appendChild(rp);
        var hist = new Array(20).fill(0), withR = d.pairs.filter(function (x) { return x.r != null; });
        withR.forEach(function (x) { hist[Math.min(19, Math.max(0, Math.floor((x.r + 1) * 10)))]++; });
        C.plot(rfig.plotNode, [{ type: 'bar', x: hist.map(function (_, i) { return -1 + 0.1 * (i + 0.5); }), y: hist, width: 0.09, marker: { color: ET.SERIES[0] }, hovertemplate: 'r %{x:.2f}: %{y:,} pairs<extra></extra>' }],
          { xaxis: { title: { text: 'r between the maize1 and maize2 copies (on log₂ values)' }, range: [-1, 1] }, yaxis: { title: { text: 'pairs' } }, showlegend: false, margin: { l: 64, r: 12, t: 12, b: 52 } }, { height: 300 });
        rfig.caption.textContent = 'Pairs near 1 kept a shared pattern after the duplication; pairs near 0 have partitioned or lost it.';
        var tp = UI.panel({ title: 'The pairs' });
        out.appendChild(tp);
        new ET.DataTable(UI.body(tp), { rows: d.pairs, rowKey: 'm1', exportName: 'homeolog_expression', sort: { key: 'log2_ratio', dir: 'desc' }, caption: 'Homeolog pairs', selectable: false,
          notes: ['samples: ' + ET.SEL.label(), 'Ka/Ks against the sorghum syntelog, as the source table gives them'],
          columns: [
            { key: 'm1', label: 'maize1', type: 'str', render: function (x) { return UI.geneLink(x.m1) + (x.m1_symbol ? ' <span class="et-sym">' + esc(x.m1_symbol) + '</span>' : ''); } },
            { key: 'm2', label: 'maize2', type: 'str', render: function (x) { return UI.geneLink(x.m2) + (x.m2_symbol ? ' <span class="et-sym">' + esc(x.m2_symbol) + '</span>' : ''); } },
            { key: 'mean1', label: 'maize1 mean', type: 'num', heat: true }, { key: 'mean2', label: 'maize2 mean', type: 'num', heat: true },
            { key: 'log2_ratio', label: 'log₂ maize1/maize2', type: 'num', render: function (x) { return '<span class="et-r ' + (x.log2_ratio >= 0 ? 'is-pos' : 'is-neg') + '">' + x.log2_ratio.toFixed(2) + '</span>'; } },
            { key: 'r', label: 'Profile r', type: 'num', render: function (x) { return x.r == null ? '—' : x.r.toFixed(2); } },
            { key: 'class', label: 'Class', type: 'str' },
            { key: 'ks1', label: 'Ks maize1', type: 'num' }, { key: 'ks2', label: 'Ks maize2', type: 'num' }
          ] });
      }
      ctx.on('selection', run);
      run();
    }
  });

  /* ------------------------------------------------------------------------
     RNA against protein
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'protein', group: 'Protein', title: 'RNA and protein', nav: 'RNA and protein',
    summary: 'Walley 2019 measured mRNA and protein in the same 23 tissues. Every gene with protein detected in one tissue, RNA against protein, and the genes whose protein is out of line with their message.',
    card: 'mRNA against protein in the 23 Walley 2019 tissues: the whole proteome of one tissue, and the discordant genes.',
    requires: function (G, cat) { return (G.assays || []).indexOf('protein') !== -1 ? null : 'Protein abundance is measured for B73 (v5 and v4) only.'; },
    render: function (root, ctx) {
      var P = ET.samples('protein');
      var sample = +ctx.get('sample', P[0] ? P[0].id : 0);
      var form = UI.panel({});
      var sel = UI.select(P.map(function (s) { return { value: String(s.id), label: s.label }; }), String(sample));
      UI.body(form).appendChild(UI.field('Tissue', sel));
      sel.addEventListener('change', function () { sample = +sel.value; run(); });
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      function run() {
        ctx.set({ sample: sample });
        out.innerHTML = '';
        out.appendChild(UI.loading('Reading every protein of the tissue…'));
        ET.api('protein_global', { genome: ET.state.genome.key, sample: sample }, { signal: ctx.signal }).then(function (d) {
          if (!ctx.alive()) { return; }
          out.innerHTML = '';
          var idx = [];
          d.genes.forEach(function (_, i) { if (d.rna[i] != null) { idx.push(i); } });
          var X = idx.map(function (i) { return S.log2p1(d.rna[i]); }), Y = idx.map(function (i) { return S.log2p1(d.protein[i]); });
          var fit = S.linfit(X, Y);
          var resid = idx.map(function (i, k) { return Y[k] - (fit.a + fit.b * X[k]); });
          var p = UI.panel({ title: esc(d.label) + ': ' + fmtInt(d.genes.length) + ' proteins detected',
            sub: 'Pearson r ' + U.fmtR(S.pearson(X, Y)) + ', Spearman ρ ' + U.fmtR(S.spearman(X, Y)) + ' on the log scale over ' + fmtInt(idx.length) + ' genes with both' });
          var fig = C.figure({ label: 'RNA against protein in ' + d.label, filename: 'rna_protein_' + d.label });
          UI.body(p).appendChild(fig);
          out.appendChild(p);
          C.plot(fig.plotNode, [{ type: 'scattergl', mode: 'markers', x: X, y: Y, text: idx.map(function (i) { return d.genes[i]; }), marker: { size: 4, color: ET.SERIES[0], opacity: 0.45 },
            customdata: idx.map(function (i) { return [fmt(d.rna[i]), fmt(d.protein[i])]; }), hovertemplate: '<b>%{text}</b><br>RNA %{customdata[0]} · protein %{customdata[1]}<extra></extra>' },
            { type: 'scatter', mode: 'lines', x: [Math.min.apply(null, X), Math.max.apply(null, X)], y: [fit.a + fit.b * Math.min.apply(null, X), fit.a + fit.b * Math.max.apply(null, X)], line: { color: ET.INK, width: 2 }, hoverinfo: 'skip' }],
            { showlegend: false, xaxis: { title: { text: 'RNA · log₂(value + 1)' } }, yaxis: { title: { text: 'protein · log₂(value + 1)' } }, margin: { l: 64, r: 12, t: 12, b: 52 } }, { height: 460 }).then(function () {
            fig.plotNode.on('plotly_click', function (ev) { var pt = ev.points && ev.points[0]; if (pt && pt.text) { ET.go('gene', { id: pt.text }); } });
          });
          fig.caption.textContent = 'Each point a gene; the line is the least-squares fit. Click a point to open the gene.';
          var tp = UI.panel({ title: 'Most discordant', sub: 'Distance from the fit, in log₂ units: protein above what the message predicts, or below' });
          out.appendChild(tp);
          new ET.DataTable(UI.body(tp), { selectable: true, exportName: 'rna_protein_discordant_' + d.label, sort: { key: 'abs', dir: 'desc' }, caption: 'Genes whose protein departs from their RNA',
            rows: idx.map(function (i, k) { return { gene: d.genes[i], rna: d.rna[i], protein: d.protein[i], res: resid[k], abs: Math.abs(resid[k]) }; }),
            columns: [{ key: 'gene', label: 'Gene', type: 'str', render: function (r) { return UI.geneLink(r.gene); } },
              { key: 'rna', label: 'RNA', type: 'num' }, { key: 'protein', label: 'Protein', type: 'num' },
              { key: 'res', label: 'Residual', type: 'num', render: function (r) { return '<span class="et-r ' + (r.res >= 0 ? 'is-pos' : 'is-neg') + '">' + r.res.toFixed(2) + '</span>'; } },
              { key: 'abs', label: '|Residual|', type: 'num', render: function (r) { return r.abs.toFixed(2); } },
              { key: 'dir', label: 'Protein is', type: 'str', value: function (r) { return r.res > 0 ? 'higher than the message predicts' : 'lower than the message predicts'; } }] });
        }, function (e) { if (ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      run();
    }
  });

  /* ------------------------------------------------------------------------
     Samples and studies
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'samples', group: 'Data', title: 'Samples and studies', nav: 'Samples and studies',
    summary: 'Every sample of the genome’s release: its study, tissue reading, stress condition, and how many genes it detects.',
    card: 'The catalog: studies, samples, tissue and condition readings, and how many genes each sample detects.',
    render: function (root, ctx) {
      var cat = ET.state.catalog;
      var rna = cat.assays.rna.samples;
      var p = UI.panel({ title: 'Genes detected in each sample', sub: 'Genes at 1 or more, by study; a sample far below its study’s others is worth a look' });
      var fig = C.figure({ label: 'Genes detected in each sample', filename: 'genes_detected_per_sample', getTable: function () {
        return { header: ['sample', 'study', 'tissue', 'condition', 'genes measured', 'genes at 1 or more', 'median'], numeric: [false, false, false, false, true, true, true],
                 rows: rna.map(function (s) { return [s.label, s.studyName, s.tissue, s.condition || '', s.n, s.ge1, s.median]; }) };
      } });
      UI.body(p).appendChild(fig);
      var lh = fig.legendHost;
      root.appendChild(p);
      var ordered = EX.order(rna.filter(function (s) { return s.usable; }), 'study');
      var bands = EX.studyBands(ordered, false, Math.max(200, (fig.plotNode.clientWidth || 800) - 76));
      var byT = {};
      ordered.forEach(function (s, i) { (byT[s.tissue] = byT[s.tissue] || []).push(i); });
      C.plot(fig.plotNode, ET.TISSUES.filter(function (t) { return byT[t]; }).map(function (t) {
        return { type: 'bar', name: t, x: byT[t], y: byT[t].map(function (i) { return ordered[i].ge1; }), marker: { color: C.tissueColor(t) },
                 hovertext: byT[t].map(function (i) { var s = ordered[i]; return '<b>' + fmtInt(s.ge1) + '</b> genes ≥ 1<br>' + esc(s.label) + '<br>' + esc(U.shortStudy(s.studyName)); }), hoverinfo: 'text' };
      }), { barmode: 'overlay', bargap: 0.1, showlegend: false, shapes: bands.shapes, annotations: bands.annotations,
            xaxis: { showticklabels: false, range: [-0.6, ordered.length - 0.4], title: { text: fmtInt(ordered.length) + ' samples, grouped by study' } }, yaxis: { title: { text: 'genes at ≥ 1' } },
            margin: { l: 64, r: 12, t: 24, b: 40 } }, { height: 340 });
      var present = {};
      ordered.forEach(function (s) { present[s.tissue] = (present[s.tissue] || 0) + 1; });
      lh.appendChild(C.legend('tissue', present));
      fig.caption.textContent = cat.units_note || '';

      var sp = UI.panel({ title: 'Studies' });
      root.appendChild(sp);
      var stRows = cat.studies.map(function (st) {
        var ss = cat.assays[st.assay] ? cat.assays[st.assay].samples.filter(function (s) { return s.study === st.id; }) : [];
        var tissues = {};
        ss.forEach(function (s) { tissues[s.tissue] = (tissues[s.tissue] || 0) + 1; });
        return { gene: st.name, st: st, n: ss.length, tissues: Object.keys(tissues).map(function (t) { return t + ' ' + tissues[t]; }).join(', ') };
      });
      new ET.DataTable(UI.body(sp), { rows: stRows, rowKey: 'gene', exportName: 'studies_' + ET.state.genome.key, pageSize: 50, caption: 'Studies of the release',
        columns: [{ key: 'gene', label: 'Study', type: 'str', render: function (r) { return r.st.link ? '<a href="' + esc(r.st.link) + '">' + esc(r.st.name) + '</a>' : esc(r.st.name); } },
          { key: 'assay', label: 'Assay', type: 'str', value: function (r) { return r.st.assay === 'rna' ? 'RNA' : 'protein'; } },
          { key: 'n', label: 'Samples', type: 'num', int: true },
          { key: 'stress', label: 'Stress study', type: 'str', value: function (r) { return r.st.stress ? 'yes' : ''; } },
          { key: 'tissues', label: 'Tissue readings', type: 'str' }] });

      var cp = UI.panel({ title: 'Samples' });
      root.appendChild(cp);
      var all = [];
      Object.keys(cat.assays).forEach(function (a) { cat.assays[a].samples.forEach(function (s) { all.push(s); }); });
      new ET.DataTable(UI.body(cp), { rows: all, rowKey: 'label', exportName: 'samples_' + ET.state.genome.key, pageSize: 25, caption: 'Samples of the release',
        columns: [{ key: 'id', label: 'Id', type: 'num', int: true }, { key: 'label', label: 'Sample', type: 'str' },
          { key: 'studyName', label: 'Study', type: 'str', value: function (s) { return U.shortStudy(s.studyName); }, export: function (s) { return s.studyName; } },
          { key: 'assay', label: 'Assay', type: 'str' },
          { key: 'tissue', label: 'Tissue', type: 'str', render: function (s) { return '<span class="et-swatch" style="background:' + C.tissueColor(s.tissue) + '"></span>' + esc(s.tissue); } },
          { key: 'condition', label: 'Condition', type: 'str' },
          { key: 'n', label: 'Genes measured', type: 'num', int: true }, { key: 'ge1', label: 'Genes ≥ 1', type: 'num', int: true },
          { key: 'median', label: 'Median', type: 'num' },
          { key: 'usable', label: 'Used', type: 'str', value: function (s) { return s.usable ? 'yes' : 'no: not measured'; } }] });
      root.appendChild(UI.message(esc(cat.tissue_note || '') + ' ' + esc(cat.condition_note || ''), 'info'));
      void ctx;
    }
  });

  /* ------------------------------------------------------------------------
     About
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'about', group: 'Data', title: 'How these tools work', nav: 'How they work',
    summary: 'What each number means, how missing values are handled, and how to get the same answers from the API.',
    card: 'Methods, the rules behind every number, the data checks, and the API.',
    render: function (root, ctx) {
      var boot = ET.state.boot;
      var pg = boot.pangenome || {};
      var api = document.getElementById('et-app').getAttribute('data-api');
      var sections = [
        ['The values', '<p>Every value is the one qTeller holds: FPKM or TPM for RNA as each study published it, normalized abundance for protein, biological replicates averaged, rounded to four significant figures. The same files feed <a href="/expression">the Expression Data Hub</a>, the gene record’s Expression section and <a href="/api/v1/data/expression">/api/v1/data/expression</a>.</p>' +
          '<p><strong>Not measured is never zero.</strong> A gene a study did not quantify has no value in that study’s samples, and every statistic here is taken over the samples a gene has. Where a source table leaves its zeros out (Walley 2019 and the NAM Consortium tables store no zero at all), a gene the table quantified carries 0 in the samples it omits; a gene the table does not list stays not measured. A sample that reads zero in every gene is a failed load and is left out of every analysis.</p>' +
          '<p>Compare samples within a study. Across studies the units and pipelines differ, which is why co-expression and the sample map can center each study first.</p>'],
        ['Co-expression', '<p>Pearson (or Spearman) correlation on log₂(value + 1) of the chosen gene with every other gene of the genome, over the selected samples both were measured in. A pair needs half the gene’s selected samples in common and at least five. Genes that never reach the chosen level are skipped. No p-value is reported: the samples are not independent.</p>'],
        ['Tissue-specific genes', '<p>Specificity is log₂ of (target mean + 1) over (the highest background sample + 1): on in the target and off everywhere else. Enrichment uses the background mean instead. Off in the target mirrors both against the lowest background sample. τ (Yanai et al. 2005) runs from 0, even everywhere, to 1, one sample only, and is left blank unless the gene reaches 1 somewhere.</p>'],
        ['GO enrichment', '<p>A one-sided hypergeometric test of each GO term against the genome’s GO-annotated genes, with a gene counted under a term when it is annotated to the term or anything below it (is_a and part_of). Terms with 5 to 2,000 background genes and 2 or more list genes are tested; the false discovery rate is Benjamini–Hochberg.</p>'],
        ['The pan-genome', '<p>' + esc(pg.class_rule || '') + ' ' + esc(pg.expressed_rule || '') + '</p><p>' + esc(pg.conservation_rule || '') + '</p>' +
          '<p>The 26 NAM genomes were measured in 23 samples in common: the NAM Consortium’s ten tissues, Lin 2017’s five and Diepenbrock 2017’s eight. They are matched by study and label, never by sample id, which is numbered per release. Members come from the Pan-Zea v4 analysis (' + fmtInt(pg.counts ? pg.counts.pangenes : 0) + ' pan-genes, ' + fmtInt(pg.counts ? pg.counts.members : 0) + ' gene models in 66 annotations).</p>'],
        ['Homeologs', '<p>The 4,578 retained maize1/maize2 pairs of the published table, 4,395 placed on B73 v5 through the pan-gene crosswalk and the v4-to-v5 chain file (the gene record’s Homeologs section reads the same release). Ka, Ks and ω are each copy against its sorghum syntelog as the source gives them.</p>'],
        ['Programmatic use', '<p>Everything these pages draw comes from one JSON endpoint, <code>' + esc(api) + '</code>: <code>?action=coexpression&amp;genome=B73v5&amp;id=adh1</code>, <code>?action=pangene&amp;id=lg1</code>, <code>?action=landscape&amp;silent_in=Oh7B&amp;format=tsv</code>, and the rest listed at the top of the file. Per-gene profiles, gene models, protein domains and GO terms are the documented <a href="/api">MaizeGDB API</a>.</p>'],
        ['Where this came from', '<p>These tools were built as ExpressionTools, a browser for qTeller databases, and rebuilt to run on MaizeGDB’s own expression releases. qTeller itself remains at <a href="https://qteller.maizegdb.org">qteller.maizegdb.org</a>. The papers behind the data and the methods are under <a href="' + esc(ET.href('references', {}, { keepSelection: false })) + '">References</a>.</p>']
      ];
      sections.forEach(function (s) {
        var p = UI.panel({ title: s[0] });
        UI.body(p).innerHTML = '<div class="mgdb-prose et-prose">' + s[1] + '</div>';
        root.appendChild(p);
      });
      void ctx;
    }
  });

  /* ------------------------------------------------------------------------
     References

     The cards are the page's own, rendered server-side by
     include/references_lib.php (abstracts, full text, PubMed, the copy
     buttons) into an inert <template>; this view clones them. The shared
     copy binding in js/mgdb-modern.js ran at load, before these buttons
     existed, so it is run again -- it skips buttons already bound.
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'references', group: 'Data', title: 'References', nav: 'References',
    summary: 'The papers behind the expression releases, the NAM genomes and their pan-genes, and the analyses these tools run.',
    card: 'The papers behind the data and the methods, with full text, PubMed and a citation to copy.',
    render: function (root, ctx) {
      var tpl = document.getElementById('et-references-cards');
      if (!tpl || !tpl.content || !tpl.content.childElementCount) {
        root.appendChild(UI.empty('No references', 'The reference list did not come with the page. Reload it to try again.'));
        return;
      }
      var list = el('<div class="mgdb-ref-list et-ref-list"></div>');
      list.appendChild(document.importNode(tpl.content, true));
      root.appendChild(list);
      if (window.MGDB && window.MGDB.initCopyButtons) { window.MGDB.initCopyButtons(); }
      void ctx;
    }
  });
})(window, document);
