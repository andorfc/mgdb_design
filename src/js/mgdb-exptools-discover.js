/* file: mgdb-exptools-discover.js
 *
 * purpose: Expression Tools -- the genome-wide tools: co-expression,
 *          tissue-specific genes, two groups of samples compared, the sample
 *          map, and GO enrichment of any gene list. Each runs one pass over
 *          the genome's matrix on the server (search/expression_tools/) and
 *          draws the answer here.
 *
 * history:
 *  09/24/26  claude  created
 */
(function (window, document) {
  'use strict';

  var ET = window.MGDB.ET;
  var U = ET.util, UI = ET.ui, C = ET.chart, S = ET.stats, EX = ET.expr;
  var esc = U.esc, fmt = U.fmt, fmtInt = U.fmtInt, el = UI.el, qs = UI.qs;

  function geneCol(label) {
    return { key: 'gene', label: label || 'Gene', type: 'str', render: function (r) { return UI.geneLink(r.gene); } };
  }
  function symbolCol() {
    return { key: 'symbol', label: 'Symbol', type: 'str', value: function (r) { return r.symbol || (r.b73_symbol ? 'B73 ' + r.b73_symbol : ''); } };
  }
  function nameCol() {
    return { key: 'name', label: 'Name', type: 'str', value: function (r) { return r.name || ''; } };
  }

  /* A choice of samples for a target or a group: a study, a tissue, a
     condition, or one sample. -> element with .get() returning sample ids. */
  function groupPicker(label, value) {
    var samples = ET.samples('rna').filter(function (s) { return s.usable; });
    var opts = [];
    var studies = ET.state.catalog.studies.filter(function (st) { return st.assay === 'rna'; });
    var add = function (group, list) { if (list.length) { opts.push({ group: group, options: list }); } };
    add('Tissue', ET.TISSUES.filter(function (t) { return samples.some(function (s) { return s.tissue === t; }); }).map(function (t) {
      return { value: 'tissue:' + t, label: t + ' (' + samples.filter(function (s) { return s.tissue === t; }).length + ')' };
    }));
    add('Stress condition', ET.CONDITIONS.filter(function (c) { return samples.some(function (s) { return s.condition === c; }); }).map(function (c) {
      return { value: 'cond:' + c, label: c + ' (' + samples.filter(function (s) { return s.condition === c; }).length + ')' };
    }));
    if (studies.length > 1) {
      add('Study', studies.map(function (st) {
        var n = samples.filter(function (s) { return s.study === st.id; }).length;
        return n ? { value: 'study:' + st.id, label: U.shortStudy(st.name) + ' (' + n + ')' } : null;
      }).filter(Boolean));
    }
    add('One sample', samples.map(function (s) { return { value: 'sample:' + s.id, label: s.label + ' — ' + U.shortStudy(s.studyName) }; }));
    var sel = UI.select(opts, value);
    var f = UI.field(label, sel);
    f.get = function () { return idsOf(sel.value); };
    f.value = function () { return sel.value; };
    f.labelOf = function () { return labelOf(sel.value); };
    f.select = sel;
    return f;
  }
  function idsOf(v) {
    if (!v) { return []; }
    var i = v.indexOf(':'), k = v.slice(0, i), val = v.slice(i + 1);
    return ET.samples('rna').filter(function (s) {
      if (!s.usable) { return false; }
      if (k === 'tissue') { return s.tissue === val; }
      if (k === 'cond') { return s.condition === val; }
      if (k === 'study') { return String(s.study) === val; }
      if (k === 'sample') { return String(s.id) === val; }
      return false;
    }).map(function (s) { return s.id; });
  }
  function labelOf(v) {
    if (!v) { return ''; }
    var i = v.indexOf(':'), k = v.slice(0, i), val = v.slice(i + 1);
    if (k === 'study') { var st = ET.state.catalog.studyById[val]; return st ? U.shortStudy(st.name) : val; }
    if (k === 'sample') { var s = ET.state.catalog.assays.rna.byId[val]; return s ? s.label : val; }
    return val;
  }

  /* ------------------------------------------------------------------------
     Co-expression
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'coexp', group: 'Discover', title: 'Co-expression', nav: 'Co-expression',
    summary: 'The genes whose expression rises and falls with one gene across the selected samples, from every gene of the genome.',
    card: 'Every gene correlated with one gene across the selected samples: the closest and the most opposite, with their profiles.',
    selection: true,
    requires: function (G) { return G.samples.rna >= 5 ? null : 'It needs at least 5 samples.'; },
    render: function (root, ctx) {
      var gene = ctx.get('id'), method = ctx.get('method', 'pearson'), n = +ctx.get('n', 50) || 50, min = ctx.get('min', '1'), center = ctx.get('center') === '1';
      var form = UI.panel({});
      var row = el('<form class="et-form-row"></form>');
      var gi = UI.geneInput({ value: gene, label: 'Gene', onPick: function (g) { gene = g; run(); } });
      row.appendChild(UI.field('Gene', gi));
      var mSel = UI.select([{ value: 'pearson', label: 'Pearson' }, { value: 'spearman', label: 'Spearman (ranks, slower)' }], method);
      row.appendChild(UI.field('Correlation', mSel));
      var cSel = UI.select([{ value: '0', label: 'Values as published' }, { value: '1', label: 'Centered within each study' }], center ? '1' : '0');
      row.appendChild(UI.field('Scale', cSel, 'Centering removes each study’s own level'));
      var minSel = UI.select([{ value: '0', label: 'Any' }, { value: '1', label: '1 or more' }, { value: '5', label: '5 or more' }, { value: '10', label: '10 or more' }], min);
      row.appendChild(UI.field('Genes reaching', minSel, 'in at least one sample'));
      var nSel = UI.select(['25', '50', '100', '200', '500'].map(function (x) { return { value: x, label: x + ' each way' }; }), String(n));
      row.appendChild(UI.field('List', nSel));
      row.appendChild(el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Find co-expressed genes</button></div>'));
      row.addEventListener('submit', function (e) {
        e.preventDefault();
        gene = gi.value; method = mSel.value; center = cSel.value === '1'; min = minSel.value; n = +nSel.value;
        run();
      });
      UI.body(form).appendChild(row);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      var seq = 0;
      function run() {
        if (!gene) { out.innerHTML = ''; ET.pickGene(out, ctx, 'coexp', 'id'); return; }
        ctx.set({ id: gene, method: method === 'pearson' ? '' : method, n: n === 50 ? '' : n, min: min === '1' ? '' : min, center: center ? '1' : '' });
        if (ET.SEL.size() < 5) { out.innerHTML = ''; out.appendChild(UI.message('Co-expression needs at least 5 samples; the selection has ' + ET.SEL.size() + '.', 'info')); return; }
        var my = ++seq;
        out.innerHTML = '';
        out.appendChild(UI.loading('Correlating ' + gene + ' with every gene over ' + U.plural(ET.SEL.size(), 'sample') + (method === 'spearman' ? ' (Spearman ranks every gene; allow a few seconds)' : '') + '…'));
        ET.api('coexpression', { genome: ET.state.genome.key, id: gene, s: ET.SEL.api(), method: method, n: n, min: min, center: center ? 1 : '' }, { signal: ctx.signal }).then(function (r) {
          if (my !== seq || !ctx.alive()) { return; }
          show(r);
        }, function (e) { if (my === seq && ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function show(r) {
        out.innerHTML = '';
        var q = r.query;
        if (r.samples_used < 12) {
          out.appendChild(UI.message('<strong>' + r.samples_used + ' samples.</strong> A correlation over this few points is unstable: one sample can push r past 0.9. Read the list as leads.', 'info'));
        }
        var acts = [
          ET.basketButton(function () { return [q.gene].concat(r.positive.slice(0, 25).map(function (x) { return x.gene; })); }, 'Gene and top 25 to basket'),
          UI.button('Heatmap of the top 30', { onClick: function () { ET.go('heatmap', { genes: [q.gene].concat(r.positive.slice(0, 20).map(function (x) { return x.gene; }), r.negative.slice(0, 9).map(function (x) { return x.gene; })).join(',') }); } }),
          UI.button('GO enrichment of the top ' + Math.min(100, r.positive.length), { onClick: function () { ET.go('enrich', { genes: r.positive.slice(0, 100).map(function (x) { return x.gene; }).join(','), label: 'co-expressed with ' + (q.symbol || q.gene) }); } })
        ];
        var p = UI.panel({ title: 'Co-expressed with ' + esc(EX.geneTitle(q)),
          sub: (r.method === 'pearson' ? 'Pearson' : 'Spearman') + ' r on log₂(value + 1)' + (r.centered ? ', centered within each study' : '') + ' over the ' + fmtInt(r.samples_used) +
               ' selected samples that measured it; each pair over the samples both genes have (at least ' + r.min_overlap + ').', actions: acts });
        var grid = el('<div class="et-grid2"></div>');
        var hist = C.figure({ label: 'How the correlation is spread across the genome', filename: q.gene + '_r_distribution', getTable: function () {
          return { header: ['r from', 'r to', 'genes'], numeric: [true, true, true], rows: r.histogram.counts.map(function (c, i) { var a = -1 + i * 0.05; return [a.toFixed(2), (a + 0.05).toFixed(2), c]; }) };
        } });
        var profTable = null;
        var prof = C.figure({ label: 'Profiles of the gene and its five closest partners', filename: q.gene + '_profiles', getTable: function () { return profTable; } });
        grid.appendChild(hist);
        grid.appendChild(prof);
        UI.body(p).appendChild(grid);
        out.appendChild(p);
        var xs = r.histogram.counts.map(function (_, i) { return -1 + 0.05 * (i + 0.5); });
        C.plot(hist.plotNode, [{ type: 'bar', x: xs, y: r.histogram.counts, width: 0.045, marker: { color: xs.map(function (x) { return x >= 0 ? ET.DIV[4] : ET.DIV[0]; }) },
          hovertemplate: 'r %{x:.2f}: %{y:,} genes<extra></extra>' }],
          { xaxis: { title: { text: 'r with ' + (q.symbol || q.gene) }, range: [-1, 1] }, yaxis: { title: { text: 'genes' } }, showlegend: false, margin: { l: 64, r: 10, t: 10, b: 48 },
            shapes: r.positive.length ? [{ type: 'line', x0: r.positive[r.positive.length - 1].r, x1: r.positive[r.positive.length - 1].r, yref: 'paper', y0: 0, y1: 1, line: { color: ET.INK, width: 1 } }] : [] },
          { height: 300 });
        hist.caption.innerHTML = fmtInt(r.tested) + ' genes compared. Left out: ' + fmtInt(r.skipped.low) + ' never reaching the threshold, ' + fmtInt(r.skipped.overlap) + ' sharing too few samples, ' + fmtInt(r.skipped.flat) + ' constant. The line marks the last gene listed.';
        var top = [q.gene].concat(r.positive.slice(0, 5).map(function (x) { return x.gene; }));
        ET.values(top, { signal: ctx.signal }).then(function (v) {
          if (!ctx.alive()) { return; }
          var samples = EX.order(ET.SEL.list(), 'study');
          var genes = top.map(function (id) { return v.genes.filter(function (g) { return g.gene === id; })[0]; }).filter(Boolean);
          var rows = genes.map(function (g) { return samples.map(function (s) { var x = g.values[s.idx]; return x == null ? NaN : S.log2p1(x); }); });
          var z = S.zscoreRows(rows);
          var xs2 = samples.map(function (_, i) { return i; });
          C.plot(prof.plotNode, genes.map(function (g, i) {
            return { type: 'scatter', mode: 'lines', name: g.symbol || g.gene, x: xs2, y: z[i].map(function (x) { return isFinite(x) ? x : null; }), connectgaps: false,
                     line: { width: i === 0 ? 3 : 1.5, color: C.seriesColor(i) }, hoverinfo: 'name' };
          }), { xaxis: { showticklabels: false, title: { text: 'samples, by study' } }, yaxis: { title: { text: 'z-score' } }, margin: { l: 50, r: 10, t: 10, b: 36 } }, { height: 300, legendBand: 48 });
          prof.caption.textContent = 'z-scores of log₂(value + 1), so genes at different levels share one axis; the thick line is the query gene.';
          profTable = { header: ['sample', 'study'].concat(genes.map(function (g) { return g.gene; })), numeric: [false, false].concat(genes.map(function () { return true; })),
                        rows: samples.map(function (s) { return [s.label, s.studyName].concat(genes.map(function (g) { return g.values[s.idx]; })); }),
                        notes: ['values as published, not z-scores', 'samples: ' + ET.SEL.label()] };
          prof.renderTable();
        });
        var tp = UI.panel({ title: 'The lists' });
        out.appendChild(tp);
        var tabs = el('<div class="mgdb-view-toggle et-tabs" role="group" aria-label="List"></div>');
        var pos = UI.button('Most similar (' + r.positive.length + ')'), neg = UI.button('Most opposite (' + r.negative.length + ')');
        pos.className = neg.className = 'mgdb-view-btn';
        tabs.appendChild(pos); tabs.appendChild(neg);
        UI.body(tp).appendChild(tabs);
        var host = el('<div></div>');
        UI.body(tp).appendChild(host);
        var cols = [
          { key: 'rank', label: '#', type: 'num', render: function (x) { return x.rank; } },
          geneCol(), symbolCol(), nameCol(),
          { key: 'r', label: 'r', type: 'num', render: function (x) { return '<span class="et-r ' + (x.r >= 0 ? 'is-pos' : 'is-neg') + '">' + x.r.toFixed(3) + '</span>'; } },
          { key: 'n', label: 'Samples', type: 'num', int: true, title: 'Samples both genes were measured in' }
        ];
        var notes = ['query: ' + q.gene, 'method: ' + r.method + ' on log2(value + 1)' + (r.centered ? ', centered within study' : ''), 'samples: ' + ET.SEL.label()];
        function showList(which) {
          host.innerHTML = '';
          pos.setAttribute('aria-pressed', which === 'pos' ? 'true' : 'false');
          neg.setAttribute('aria-pressed', which === 'neg' ? 'true' : 'false');
          var list = (which === 'pos' ? r.positive : r.negative).map(function (x, i) { return Object.assign({ rank: i + 1 }, x); });
          new ET.DataTable(host, { columns: cols, rows: list, selectable: true, exportName: q.gene + '_coexpressed_' + (which === 'pos' ? 'positive' : 'negative'), notes: notes, caption: 'Genes co-expressed with ' + q.gene });
        }
        pos.addEventListener('click', function () { showList('pos'); });
        neg.addEventListener('click', function () { showList('neg'); });
        showList('pos');
        out.appendChild(UI.message('Correlation ranks genes; it does not test them. The samples are not independent (a study’s tissues share a batch), so no p-value is reported. Centering within each study removes the studies’ own offsets.', 'info'));
      }
      ctx.on('selection', function () { if (gene) { run(); } });
      if (gene) { run(); } else { ET.pickGene(out, ctx, 'coexp', 'id'); }
    }
  });

  /* ------------------------------------------------------------------------
     Tissue-specific genes
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'specific', group: 'Discover', title: 'Tissue-specific genes', nav: 'Tissue-specific genes',
    summary: 'Genes switched on in a tissue, condition, study or sample and low in the rest of the selection—or switched off there.',
    card: 'Genes on in one tissue, stress condition or sample and off in the rest of the selection, or the reverse.',
    selection: true,
    requires: function (G) { return G.samples.rna >= 3 ? null : 'It needs at least 3 samples.'; },
    render: function (root, ctx) {
      var target = ctx.get('target', 'tissue:root'), metric = ctx.get('metric', 'specificity'), dir = ctx.get('dir', 'up'), min = ctx.get('min', '5'), n = +ctx.get('n', 200) || 200;
      var form = UI.panel({});
      var row = el('<form class="et-form-row"></form>');
      var tp = groupPicker('Target samples', target);
      row.appendChild(tp);
      var mSel = UI.select([{ value: 'specificity', label: 'against the highest other sample' }, { value: 'enrichment', label: 'against the mean of the others' }], metric);
      row.appendChild(UI.field('Compare the target mean', mSel));
      var dSel = UI.select([{ value: 'up', label: 'on in the target' }, { value: 'down', label: 'off in the target' }], dir);
      row.appendChild(UI.field('Genes', dSel));
      var minIn = el('<input type="text" class="et-input" inputmode="decimal">'); minIn.value = min;
      row.appendChild(UI.field('Mean at least', minIn, 'target (on) or background (off)'));
      row.appendChild(el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Find genes</button></div>'));
      row.addEventListener('submit', function (e) {
        e.preventDefault();
        target = tp.value(); metric = mSel.value; dir = dSel.value; min = minIn.value || '0';
        run();
      });
      UI.body(form).appendChild(row);
      UI.body(form).appendChild(el('<p class="et-muted">The background is every other sample in the current selection.</p>'));
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      var seq = 0;
      function run() {
        /* The target is the chosen group as far as the selection reaches, so a
           selection narrowed to one study keeps the comparison inside it. */
        var t = idsOf(target).filter(function (i) { return ET.SEL.has(i); });
        var bg = ET.SEL.ids().filter(function (i) { return t.indexOf(i) === -1; });
        ctx.set({ target: target, metric: metric === 'specificity' ? '' : metric, dir: dir === 'up' ? '' : dir, min: min === '5' ? '' : min });
        if (!t.length) { out.innerHTML = ''; out.appendChild(UI.message('None of the ' + esc(labelOf(target)) + ' samples is in the current selection. Widen the selection or choose another target.', 'info')); return; }
        if (!bg.length) { out.innerHTML = ''; out.appendChild(UI.message('The background is empty: the selection has no samples outside the target.', 'info')); return; }
        var my = ++seq;
        out.innerHTML = '';
        out.appendChild(UI.loading('Scoring every gene…'));
        ET.api('specific', { genome: ET.state.genome.key, target: U.compressIds(t), s: U.compressIds(t.concat(bg)), metric: metric, direction: dir, min: min, n: n },
               { post: true, signal: ctx.signal }).then(function (r) {
          if (my !== seq || !ctx.alive()) { return; }
          show(r, t, bg);
        }, function (e) { if (my === seq && ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function show(r, t, bg) {
        out.innerHTML = '';
        var name = labelOf(target);
        var acts = [ET.basketButton(function () { return r.genes.slice(0, 50).map(function (g) { return g.gene; }); }, 'Top 50 to basket'),
          UI.button('Heatmap', { onClick: function () { ET.go('heatmap', { genes: r.genes.slice(0, 60).map(function (g) { return g.gene; }).join(',') }); } }),
          UI.button('GO enrichment', { onClick: function () { ET.go('enrich', { genes: r.genes.slice(0, 200).map(function (g) { return g.gene; }).join(','), label: (dir === 'up' ? 'on in ' : 'off in ') + name }); } })];
        var p = UI.panel({ title: (dir === 'up' ? 'On in ' : 'Off in ') + esc(name) + ': ' + fmtInt(r.passing) + ' genes at two-fold or more',
          sub: fmtInt(r.target) + ' target and ' + fmtInt(r.background) + ' background samples · ' + fmtInt(r.tested) + ' genes passed the ' + esc(String(r.min)) + ' threshold · score is log₂ of (target mean + 1) over (' +
               (metric === 'specificity' ? (dir === 'up' ? 'the highest' : 'the lowest') + ' background sample' : 'the background mean') + ' + 1)', actions: acts });
        out.appendChild(p);
        var fig = C.figure({ label: 'The top genes across target and background', filename: 'specific_' + name });
        UI.body(p).appendChild(fig);
        if (r.genes.length && r.genes[0].score === 0 && dir === 'up') {
          UI.body(p).appendChild(UI.message('Every top gene has the same value in some background sample: the target may duplicate another sample. See the data checks on the overview.', 'info'));
        }
        var top = r.genes.slice(0, 40);
        if (top.length) {
          ET.values(top.map(function (g) { return g.gene; }), { signal: ctx.signal }).then(function (v) {
            if (!ctx.alive()) { return; }
            var byGene = {};
            v.genes.forEach(function (g) { byGene[g.gene] = g; });
            var tset = {};
            t.forEach(function (i) { tset[i] = true; });
            var sel = ET.SEL.list();
            var samples = EX.order(sel.filter(function (s) { return tset[s.id]; }), 'tissue').concat(EX.order(sel.filter(function (s) { return !tset[s.id]; }), 'study'));
            var rows = top.map(function (g) { var x = byGene[g.gene]; return samples.map(function (s) { var y = x && x.values ? x.values[s.idx] : null; return y == null ? NaN : S.log2p1(y); }); });
            var z = S.zscoreRows(rows);
            var xs = samples.map(function (_, k) { return k; });
            C.plot(fig.plotNode, [{ type: 'heatmap', z: z.map(function (r0) { return r0.map(function (x) { return isFinite(x) ? x : null; }); }), x: xs, y: top.map(function (_, k) { return k; }),
              zmin: -3, zmax: 3, colorscale: C.divScale(), hoverongaps: false,
              customdata: top.map(function (g) { return samples.map(function (s) { var x = byGene[g.gene]; return [esc(s.label), fmt(x && x.values ? x.values[s.idx] : null), esc(g.gene)]; }); }),
              hovertemplate: '<b>%{customdata[2]}</b><br>%{customdata[0]}<br>value %{customdata[1]}<br>z %{z:.2f}<extra></extra>', colorbar: { title: { text: 'z' }, thickness: 12, len: 0.6 } }],
              { xaxis: { showticklabels: false, title: { text: 'target samples left of the line, then the background by study' } },
                yaxis: { tickmode: 'array', tickvals: top.map(function (_, k) { return k; }), ticktext: top.map(function (g) { return g.symbol || g.b73_symbol || g.gene; }), autorange: 'reversed', tickfont: { size: 10 } },
                shapes: [{ type: 'line', xref: 'x', yref: 'paper', x0: t.length - 0.5, x1: t.length - 0.5, y0: 0, y1: 1, line: { color: ET.INK, width: 1.5 } }],
                margin: { l: 110, r: 12, t: 10, b: 36 } }, { height: Math.max(360, top.length * 15 + 90) });
            fig.caption.textContent = 'Row z-scores of log₂(value + 1) for the top ' + top.length + ' genes: red above each gene’s own mean, blue below. Blank cells were not measured.';
          });
        } else {
          fig.plotNode.appendChild(UI.message('No gene passes these settings.', 'info'));
        }
        var tbl = UI.panel({ title: 'Ranked genes' });
        out.appendChild(tbl);
        new ET.DataTable(UI.body(tbl), { selectable: true, exportName: 'specific_' + (dir === 'up' ? 'on' : 'off') + '_' + name, pageSize: 50,
          notes: ['target: ' + name + ' (' + t.length + ' samples)', 'background: ' + bg.length + ' samples of ' + ET.SEL.label(), 'metric: ' + metric + ', direction: ' + dir + ', minimum mean ' + min],
          caption: 'Genes ' + (dir === 'up' ? 'on' : 'off') + ' in ' + name,
          columns: [geneCol(), symbolCol(), nameCol(),
            { key: 'score', label: 'log₂ fold', type: 'num', render: function (g) { return '<span class="et-r ' + (g.score >= 0 ? 'is-pos' : 'is-neg') + '">' + g.score.toFixed(2) + '</span>'; } },
            { key: 'target_mean', label: 'Target mean', type: 'num', heat: true },
            { key: 'background_mean', label: 'Background mean', type: 'num' },
            { key: dir === 'up' ? 'background_max' : 'background_min', label: dir === 'up' ? 'Background highest' : 'Background lowest', type: 'num' },
            { key: 'tau', label: 'τ', type: 'num', title: 'Tissue specificity over target and background', render: function (g) { return g.tau == null ? '—' : g.tau.toFixed(2); } }],
          rows: r.genes });
      }
      ctx.on('selection', run);
      if (ctx.get('target')) { run(); }
    }
  });

  /* ------------------------------------------------------------------------
     Two groups of samples
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'contrast', group: 'Discover', title: 'Compare samples', nav: 'Compare samples',
    summary: 'Every gene’s mean in one group of samples against another: an MA plot, fold changes, and the genes that move most.',
    card: 'Every gene in one sample or group against another: MA plot and the genes that move most.',
    requires: function (G) { return G.samples.rna >= 2 ? null : 'It needs at least 2 samples.'; },
    render: function (root, ctx) {
      var samples = ET.samples('rna').filter(function (s) { return s.usable; });
      /* The opening comparison is one study's root against its leaf, so the
         first thing on the page is a fold change within one set of units,
         not the first two samples of the catalog, which came from two
         studies. The largest study that has both wins. */
      var pair = (function () {
        var byStudy = {}, best = null;
        /* A plain root and a plain leaf: the tissue keywords also file brace
           roots under root and coleoptiles under leaf. */
        var pick = function (list, tissue, plain, not) {
          var of = list.filter(function (s) { return s.tissue === tissue; });
          return of.filter(function (s) { return plain.test(s.label) && !not.test(s.label); })[0] || of[0];
        };
        samples.forEach(function (s) { (byStudy[s.study] = byStudy[s.study] || []).push(s); });
        Object.keys(byStudy).forEach(function (k) {
          var list = byStudy[k];
          var ra = pick(list, 'root', /root/i, /brace|crown|nodal|node/i), lb = pick(list, 'leaf', /leaf/i, /coleoptile|sheath|husk|primordi/i);
          var plainness = (ra && /root/i.test(ra.label) && !/brace|crown|nodal|node/i.test(ra.label) ? 1 : 0) + (lb && /leaf/i.test(lb.label) && !/coleoptile|sheath|husk|primordi/i.test(lb.label) ? 1 : 0);
          if (ra && lb && (!best || plainness > best.plain || (plainness === best.plain && list.length > best.n))) { best = { n: list.length, plain: plainness, a: ra, b: lb }; }
        });
        if (best) { return best; }
        var first = samples[0], other = samples.filter(function (s) { return first && s.study === first.study && s.tissue !== first.tissue; })[0] || samples[1];
        return { a: first, b: other };
      })();
      var a = ctx.get('a', pair.a ? 'sample:' + pair.a.id : ''), b = ctx.get('b', pair.b ? 'sample:' + pair.b.id : '');
      var fcRaw = ctx.get('fc'), minRaw = ctx.get('min');
      var fc = fcRaw === '' ? 1 : (+fcRaw || 0), minE = minRaw === '' ? 5 : (+minRaw || 0), view = ctx.get('view', 'ma');
      var form = UI.panel({});
      var row = el('<form class="et-form-row"></form>');
      var pa = groupPicker('Group A', a), pb = groupPicker('Group B', b);
      row.appendChild(pa); row.appendChild(pb);
      var fcIn = el('<input type="text" class="et-input" inputmode="decimal">'); fcIn.value = fc;
      var minIn = el('<input type="text" class="et-input" inputmode="decimal">'); minIn.value = minE;
      row.appendChild(UI.field('|log₂ fold| at least', fcIn));
      row.appendChild(UI.field('Higher mean at least', minIn));
      row.appendChild(el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Compare</button></div>'));
      row.addEventListener('submit', function (e) { e.preventDefault(); a = pa.value(); b = pb.value(); fc = +fcIn.value || 0; minE = +minIn.value || 0; run(); });
      [fcIn, minIn].forEach(function (i) { i.addEventListener('change', function () { fc = +fcIn.value || 0; minE = +minIn.value || 0; ctx.set({ fc: fc === 1 ? '' : fc, min: minE === 5 ? '' : minE }); if (data) { draw(); } }); });
      UI.body(form).appendChild(row);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      var data = null;
      function run() {
        var A = idsOf(a), B = idsOf(b).filter(function (i) { return A.indexOf(i) === -1; });
        ctx.set({ a: a, b: b, fc: fc === 1 ? '' : fc, min: minE === 5 ? '' : minE });
        if (!A.length || !B.length) { out.innerHTML = ''; out.appendChild(UI.message('Both groups need at least one sample, and they cannot be the same samples.', 'info')); return; }
        out.innerHTML = '';
        out.appendChild(UI.loading('Averaging every gene in each group…'));
        ET.api('contrast', { genome: ET.state.genome.key, a: U.compressIds(A), b: U.compressIds(B) }, { post: true, signal: ctx.signal }).then(function (r) {
          if (!ctx.alive()) { return; }
          data = Object.assign(r, { A: A, B: B, la: labelOf(a), lb: labelOf(b) });
          draw();
        }, function (e) { if (ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      /* Axis titles hold a label of up to about 24 characters before they
         run off the plot; the panel title carries the full names. */
      function short(l) { return l.length > 24 ? l.slice(0, 22) + '…' : l; }
      function draw() {
        var d = data, n = d.genes.length;
        var lfc = new Float64Array(n), mean = new Float64Array(n), cls = new Int8Array(n);
        var up = 0, down = 0;
        for (var i = 0; i < n; i++) {
          lfc[i] = Math.log((d.a[i] + 1) / (d.b[i] + 1)) / Math.LN2;
          mean[i] = Math.log((d.a[i] + d.b[i]) / 2 + 1) / Math.LN2;
          if (Math.max(d.a[i], d.b[i]) >= minE && Math.abs(lfc[i]) >= fc) { cls[i] = lfc[i] > 0 ? 1 : -1; if (lfc[i] > 0) { up++; } else { down++; } }
        }
        out.innerHTML = '';
        var studies = {};
        d.A.concat(d.B).forEach(function (id) { var s = ET.state.catalog.assays.rna.byId[id]; if (s) { studies[s.study] = true; } });
        if (d.n_a === 1 && d.n_b === 1) {
          out.appendChild(UI.message('One sample in each group and no replicates: these are fold changes, not tests.' + (Object.keys(studies).length > 1 ? ' The two groups also come from different studies, whose units and pipelines differ.' : ''), 'info'));
        } else if (Object.keys(studies).length > 1) {
          out.appendChild(UI.message('The groups draw on ' + Object.keys(studies).length + ' studies, whose units and pipelines differ; a fold change across them mixes biology with method.', 'info'));
        }
        var viewCtl = UI.segmented([{ value: 'ma', label: 'MA plot' }, { value: 'xy', label: 'A against B' }], view, function (v) { view = v; ctx.set({ view: v === 'ma' ? '' : v }); draw(); }, 'Plot');
        var fig = C.figure({ label: d.la + ' against ' + d.lb, filename: 'compare_' + d.la + '_vs_' + d.lb, controls: [viewCtl] });
        var p = UI.panel({ title: esc(d.la) + ' against ' + esc(d.lb), sub: fmtInt(n) + ' genes with a value in both groups · ' + fmtInt(up) + ' higher in ' + esc(d.la) + ', ' + fmtInt(down) + ' higher in ' + esc(d.lb) +
          ' (|log₂ fold| ≥ ' + fc + ', higher mean ≥ ' + minE + ') · ' + fmtInt(d.zero_in_both) + ' zero in both left out' });
        UI.body(p).appendChild(fig);
        out.appendChild(p);
        var sets = [[0, 'no change', '#b9bdb6'], [1, 'higher in ' + d.la, ET.DIV[4]], [-1, 'higher in ' + d.lb, ET.DIV[0]]];
        var traces = sets.map(function (st) {
          var idx = [];
          for (var k = 0; k < n; k++) { if (cls[k] === st[0]) { idx.push(k); } }
          return { type: 'scattergl', mode: 'markers', name: st[1] + ' (' + fmtInt(idx.length) + ')',
                   x: idx.map(function (k) { return view === 'ma' ? mean[k] : Math.log(d.b[k] + 1) / Math.LN2; }),
                   y: idx.map(function (k) { return view === 'ma' ? lfc[k] : Math.log(d.a[k] + 1) / Math.LN2; }),
                   marker: { size: st[0] ? 5 : 3.5, color: st[2], opacity: st[0] ? 0.9 : 0.45 },
                   text: idx.map(function (k) { return d.genes[k]; }),
                   customdata: idx.map(function (k) { return [fmt(d.a[k]), fmt(d.b[k]), lfc[k].toFixed(2)]; }),
                   hovertemplate: '<b>%{text}</b><br>' + esc(d.la) + ' %{customdata[0]} · ' + esc(d.lb) + ' %{customdata[1]}<br>log₂ fold %{customdata[2]}<extra></extra>' };
        });
        var lay = view === 'ma'
          ? { xaxis: { title: { text: 'mean log₂(value + 1)' } }, yaxis: { title: { text: 'log₂ fold, ' + short(d.la) + ' over ' + short(d.lb) }, zeroline: true },
              shapes: [fc, -fc].map(function (y) { return { type: 'line', xref: 'paper', x0: 0, x1: 1, y0: y, y1: y, line: { color: '#9a9994', width: 1 } }; }) }
          : { xaxis: { title: { text: short(d.lb) + ' · log₂(value + 1)' } }, yaxis: { title: { text: short(d.la) + ' · log₂(value + 1)' } } };
        lay.margin = { l: 64, r: 12, t: 12, b: 56 };
        C.plot(fig.plotNode, traces, lay, { height: 520 }).then(function () {
          fig.plotNode.on('plotly_click', function (ev) { var g = ev.points && ev.points[0] && ev.points[0].text; if (g) { ET.go('gene', { id: g }); } });
        });
        fig.caption.textContent = 'Click a point to open its gene report. Pseudocount 1 in every fold change.';
        var rows = [];
        for (var k = 0; k < n; k++) { if (cls[k]) { rows.push({ gene: d.genes[k], a: d.a[k], b: d.b[k], lfc: lfc[k] }); } }
        var tp = UI.panel({ title: U.plural(rows.length, 'gene') + ' past the thresholds', actions: [
          ET.basketButton(function () { return rows.slice().sort(function (x, y) { return Math.abs(y.lfc) - Math.abs(x.lfc); }).slice(0, 100).map(function (r) { return r.gene; }); }, 'Top 100 to basket'),
          UI.button('GO enrichment of the higher-in-A genes', { onClick: function () { ET.go('enrich', { genes: rows.filter(function (r) { return r.lfc > 0; }).sort(function (x, y) { return y.lfc - x.lfc; }).slice(0, 500).map(function (r) { return r.gene; }).join(','), label: 'higher in ' + d.la }); } })] });
        out.appendChild(tp);
        new ET.DataTable(UI.body(tp), { rows: rows, selectable: true, sort: { key: 'lfc', dir: 'desc' }, exportName: 'compare_' + d.la + '_vs_' + d.lb,
          notes: ['A: ' + d.la + ' (' + d.A.join(',') + ')', 'B: ' + d.lb + ' (' + d.B.join(',') + ')', '|log2 fold| >= ' + fc + ', higher mean >= ' + minE],
          caption: 'Genes that differ between the groups',
          columns: [geneCol(), { key: 'a', label: esc(d.la), exportLabel: d.la, type: 'num', heat: true }, { key: 'b', label: esc(d.lb), exportLabel: d.lb, type: 'num', heat: true },
            { key: 'lfc', label: 'log₂ fold', type: 'num', render: function (r) { return '<span class="et-r ' + (r.lfc >= 0 ? 'is-pos' : 'is-neg') + '">' + r.lfc.toFixed(2) + '</span>'; } }] });
      }
      run();
    }
  });

  /* ------------------------------------------------------------------------
     The sample map
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'samplemap', group: 'Discover', title: 'Sample map', nav: 'Sample map',
    summary: 'How the selected samples relate to each other: principal components and a clustered correlation map over the most variable genes. Identical or misplaced samples stand out.',
    card: 'Principal components and a clustered correlation map of the selected samples; duplicates and outliers stand out.',
    selection: true,
    requires: function (G) { return G.samples.rna >= 3 ? null : 'It needs at least 3 samples.'; },
    render: function (root, ctx) {
      var n = ctx.get('n', '1000'), center = ctx.get('center') === '1', color = ctx.get('color', 'tissue');
      var form = UI.panel({});
      var row = el('<div class="et-form-row"></div>');
      var nSel = UI.select(['250', '500', '1000', '2000', '5000'].map(function (x) { return { value: x, label: x + ' most variable genes' }; }), n);
      nSel.addEventListener('change', function () { n = nSel.value; run(); });
      row.appendChild(UI.field('Genes used', nSel, 'variance of log₂(value + 1), mean 1 or more'));
      var cSel = UI.select([{ value: '0', label: 'Values as published' }, { value: '1', label: 'Centered within each study' }], center ? '1' : '0');
      cSel.addEventListener('change', function () { center = cSel.value === '1'; run(); });
      row.appendChild(UI.field('Scale', cSel, 'Centering removes each study’s level, so samples group by biology rather than by study'));
      var studies = ET.state.catalog.studies.filter(function (st) { return st.assay === 'rna'; });
      var colOpts = [{ value: 'tissue', label: 'Tissue' }, { value: 'condition', label: 'Stress condition' }];
      if (studies.length > 1) {
        colOpts.push({ group: 'Highlight one study', options: studies.map(function (st) { return { value: 'study:' + st.id, label: U.shortStudy(st.name) }; }) });
      }
      var colSel = UI.select(colOpts, color);
      colSel.addEventListener('change', function () { color = colSel.value; ctx.set({ color: color === 'tissue' ? '' : color }); if (data) { draw(); } });
      row.appendChild(UI.field('Color by', colSel, 'Thirty-odd studies are too many colors to tell apart, so a study is shown against the rest'));
      UI.body(form).appendChild(row);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      var data = null, seq = 0;
      function run() {
        ctx.set({ n: n === '1000' ? '' : n, center: center ? '1' : '' });
        if (ET.SEL.size() < 3) { out.innerHTML = ''; out.appendChild(UI.message('Select at least 3 samples.', 'info')); return; }
        var my = ++seq;
        out.innerHTML = '';
        out.appendChild(UI.loading('Finding the most variable genes…'));
        ET.api('variable', { genome: ET.state.genome.key, s: ET.SEL.api(), n: n, center: center ? 1 : '' }, { signal: ctx.signal }).then(function (r) {
          if (my !== seq || !ctx.alive()) { return; }
          data = r;
          draw();
        }, function (e) { if (my === seq && ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function groupOf(s) { return color === 'condition' ? (s.condition || 'not a stress study') : s.tissue; }
      function draw() {
        var r = data;
        out.innerHTML = '';
        if (r.genes.length < 3) { out.appendChild(UI.message('Too few variable genes in this selection.', 'info')); return; }
        var byId = ET.state.catalog.assays.rna.byId;
        var samples = r.samples.map(function (id) { return byId[id]; });
        var pca = S.pca(r.values, 3);
        var C2 = S.columnCorrelation(r.values);
        var pp = UI.panel({ title: 'Principal components', sub: fmtInt(r.genes.length) + ' genes × ' + samples.length + ' samples' + (r.centered ? ', centered within each study' : '') + '; each point is a sample' });
        var fig = C.figure({ label: 'Principal components of the selected samples', filename: 'sample_pca', getTable: function () {
          return { header: ['sample', 'study', 'tissue', 'condition', 'PC1', 'PC2', 'PC3'], numeric: [false, false, false, false, true, true, true],
                   rows: samples.map(function (s, i) { return [s.label, s.studyName, s.tissue, s.condition || '', pca.scores[i][0].toFixed(3), pca.scores[i][1].toFixed(3), pca.scores[i][2] != null ? pca.scores[i][2].toFixed(3) : '']; }) };
        } });
        UI.body(pp).appendChild(fig);
        var legendHost = fig.legendHost;
        out.appendChild(pp);
        var groups, colorOf, symbolOf;
        var studyHi = color.indexOf('study:') === 0 ? color.slice(6) : null;
        if (studyHi) {
          groups = ['other studies', 'this study'];
        } else {
          var order = color === 'condition' ? ET.CONDITIONS.concat(['not a stress study']) : ET.TISSUES;
          groups = order.filter(function (g) { return samples.some(function (s) { return groupOf(s) === g; }); });
        }
        colorOf = function (g) { return color === 'condition' ? (g === 'not a stress study' ? '#b9bdb6' : C.conditionColor(g)) : C.tissueColor(g); };
        symbolOf = function (g) { return color === 'condition' ? 'circle' : (ET.TISSUE_SYMBOLS[g] || 'circle'); };
        var traces = groups.map(function (g) {
          var idx = samples.map(function (s, i) { return i; }).filter(function (i) {
            if (studyHi) { return (String(samples[i].study) === studyHi) === (g === 'this study'); }
            return groupOf(samples[i]) === g;
          });
          return { type: 'scatter', mode: 'markers', name: g, x: idx.map(function (i) { return pca.scores[i][0]; }), y: idx.map(function (i) { return pca.scores[i][1]; }),
                   marker: studyHi
                     ? { size: g === 'this study' ? 11 : 8, color: g === 'this study' ? ET.SERIES[0] : '#c9ccc6', line: { color: '#ffffff', width: 1.5 } }
                     : { size: 10, color: colorOf(g), symbol: symbolOf(g), line: { color: '#ffffff', width: 1.5 } },
                   hoverinfo: 'text', hovertext: idx.map(function (i) { var s = samples[i]; return '<b>' + esc(s.label) + '</b><br>' + esc(U.shortStudy(s.studyName)) + ' · ' + esc(s.tissue) + (s.condition ? ' · ' + esc(s.condition) : ''); }) };
        });
        C.plot(fig.plotNode, traces, { showlegend: false, xaxis: { title: { text: 'PC1 (' + (pca.explained[0] * 100).toFixed(1) + '%)' }, zeroline: false },
          yaxis: { title: { text: 'PC2 (' + (pca.explained[1] * 100).toFixed(1) + '%)' }, zeroline: false }, margin: { l: 64, r: 12, t: 10, b: 52 } }, { height: 480 });
        if (color === 'tissue') {
          var present = {};
          samples.forEach(function (s) { present[s.tissue] = (present[s.tissue] || 0) + 1; });
          legendHost.appendChild(C.legend('tissue', present));
          fig.caption.textContent = 'Each tissue has its own marker shape as well as its color. Hover a point for the sample.';
        } else if (color === 'condition') {
          var pc = {};
          samples.forEach(function (s) { if (s.condition) { pc[s.condition] = (pc[s.condition] || 0) + 1; } });
          legendHost.appendChild(C.legend('condition', pc));
          fig.caption.textContent = 'Gray points are samples outside the stress studies.';
        } else {
          var st = ET.state.catalog.studyById[studyHi];
          fig.caption.innerHTML = '<span class="et-swatch" style="background:' + ET.SERIES[0] + '"></span>' + esc(st ? st.name : '') + '; every other study in gray.';
        }

        /* Correlation map, clustered */
        /* Clustered on 1 - r between the samples themselves, which is what
           the map shows. */
        var ord = S.orderFromDistance(samples.map(function (_, i) { return samples.map(function (_, j) { var v = C2[i][j]; return isFinite(v) ? 1 - v : 1; }); }));
        var hp = UI.panel({ title: 'Correlation between samples', sub: 'Pearson r over the same genes, clustered by average linkage' });
        var hfig = C.figure({ label: 'Clustered correlation between the selected samples', filename: 'sample_correlation', getTable: function () {
          return { header: ['sample'].concat(ord.map(function (i) { return samples[i].label; })), numeric: [false].concat(ord.map(function () { return true; })),
                   rows: ord.map(function (i) { return [samples[i].label].concat(ord.map(function (j) { return C2[i][j] == null ? null : Math.round(C2[i][j] * 1000) / 1000; })); }) };
        } });
        UI.body(hp).appendChild(hfig);
        var hl = hfig.legendHost;
        out.appendChild(hp);
        var labels = ord.map(function (i) { return samples[i].label; });
        var small = samples.length > 60;
        var vals = [];
        C2.forEach(function (r0) { r0.forEach(function (v) { if (isFinite(v) && v < 0.99999) { vals.push(v); } }); });
        var lo = vals.length ? Math.min.apply(null, vals) : 0;
        var shapes = ord.map(function (i, k) { return { type: 'rect', xref: 'x', yref: 'paper', x0: k - 0.5, x1: k + 0.5, y0: 1.004, y1: 1.03, fillcolor: C.tissueColor(samples[i].tissue), line: { width: 0 } }; });
        C.plot(hfig.plotNode, [{ type: 'heatmap', z: ord.map(function (i) { return ord.map(function (j) { return C2[i][j]; }); }), x: labels.map(function (_, k) { return k; }), y: labels.map(function (_, k) { return k; }),
          zmin: lo < 0 ? -1 : Math.max(0, Math.floor(lo * 10) / 10), zmax: 1, colorscale: lo < 0 ? C.divScale() : C.seqScale(),
          customdata: ord.map(function (i) { return ord.map(function (j) { return [esc(samples[i].label), esc(samples[j].label)]; }); }),
          hovertemplate: '%{customdata[0]}<br>%{customdata[1]}<br>r %{z:.3f}<extra></extra>', colorbar: { title: { text: 'r' }, thickness: 12, len: 0.6 } }],
          { xaxis: { showticklabels: !small, tickmode: 'array', tickvals: labels.map(function (_, k) { return k; }), ticktext: labels.map(function (l) { return l.length > 20 ? l.slice(0, 18) + '…' : l; }), tickangle: -60, tickfont: { size: 9 }, showgrid: false },
            yaxis: { showticklabels: !small, tickmode: 'array', tickvals: labels.map(function (_, k) { return k; }), ticktext: labels.map(function (l) { return l.length > 24 ? l.slice(0, 22) + '…' : l; }), autorange: 'reversed', tickfont: { size: 9 }, showgrid: false },
            shapes: shapes, margin: { l: small ? 20 : 150, r: 12, t: 22, b: small ? 20 : 150 } }, { height: Math.min(1200, Math.max(460, samples.length * (small ? 3 : 14) + 200)) });
        var present2 = {};
        samples.forEach(function (s) { present2[s.tissue] = (present2[s.tissue] || 0) + 1; });
        hl.appendChild(C.legend('tissue', present2));
        hfig.caption.textContent = 'The strip above marks each sample’s tissue.' + (small ? ' Names are in the hover and the table.' : '');
        /* Nearest neighbor of each sample; r of 1 flags a sample stored twice. */
        var nn = samples.map(function (s, i) {
          var best = -2, bj = -1;
          for (var j = 0; j < samples.length; j++) { if (j !== i && C2[i][j] > best) { best = C2[i][j]; bj = j; } }
          return { gene: s.label, s: s, other: samples[bj], r: best, same: samples[bj] && samples[bj].tissue === s.tissue };
        });
        var twins = nn.filter(function (x) { return x.r > 0.99999; });
        if (twins.length) {
          out.appendChild(UI.message('<strong>' + U.plural(twins.length, 'sample') + ' with an identical twin</strong> (r = 1.000): ' +
            twins.map(function (x) { return esc(x.s.label) + ' (' + esc(U.shortStudy(x.s.studyName)) + ') = ' + esc(x.other.label) + ' (' + esc(U.shortStudy(x.other.studyName)) + ')'; }).join('; ') +
            '. These are almost certainly one sample stored twice.', 'info'));
        }
        var tp = UI.panel({ title: 'Each sample’s closest sample', sub: '"Same tissue" says no where a sample sits closest to another tissue, which is worth a look' });
        out.appendChild(tp);
        new ET.DataTable(UI.body(tp), { rows: nn, rowKey: 'gene', exportName: 'sample_nearest_neighbors', sort: { key: 'r', dir: 'desc' }, caption: 'The closest sample to each sample',
          columns: [{ key: 'gene', label: 'Sample', type: 'str' }, { key: 'study', label: 'Study', type: 'str', value: function (x) { return U.shortStudy(x.s.studyName); } },
            { key: 'tissue', label: 'Tissue', type: 'str', value: function (x) { return x.s.tissue; } },
            { key: 'other', label: 'Closest sample', type: 'str', value: function (x) { return x.other ? x.other.label + ' (' + U.shortStudy(x.other.studyName) + ')' : ''; } },
            { key: 'r', label: 'r', type: 'num', render: function (x) { return x.r.toFixed(3); } },
            { key: 'same', label: 'Same tissue', type: 'str', value: function (x) { return x.same ? 'yes' : 'no'; } }] });
      }
      ctx.on('selection', run);
      run();
    }
  });

  /* ------------------------------------------------------------------------
     GO enrichment
     ------------------------------------------------------------------------ */

  ET.register({
    id: 'enrich', group: 'Discover', title: 'GO enrichment', nav: 'GO enrichment',
    summary: 'Which GO terms a gene list carries more often than the genome does: a one-sided hypergeometric test against the genome’s GO-annotated genes, with Benjamini–Hochberg correction.',
    card: 'Any gene list—co-expressed genes, tissue-specific genes, the basket—against the genome’s GO annotations.',
    requires: function (G) { return G.go ? null : 'MaizeGDB holds GO annotations for B73 v5 and the 25 NAM founders; ' + G.short + ' has too few to test against.'; },
    render: function (root, ctx) {
      var start = (ctx.get('genes') || '').split(',').filter(Boolean);
      var label = ctx.get('label');
      var bg = ctx.get('bg', 'annotated'), aspects = ctx.get('aspects', 'PFC');
      var form = UI.panel({});
      var input = UI.geneListInput({ value: start, rows: 6, label: 'Genes' });
      ctx.onDispose(input.dispose);
      UI.body(form).appendChild(input.el);
      var row = el('<form class="et-form-row"></form>');
      var bgSel = UI.select([{ value: 'annotated', label: 'Every GO-annotated gene of the genome' }, { value: 'expressed', label: 'Annotated genes detected in some sample' }], bg);
      row.appendChild(UI.field('Background', bgSel, 'For a list drawn from expressed genes, the second is the fairer comparison'));
      var aSel = UI.select([{ value: 'PFC', label: 'All three' }, { value: 'P', label: 'Biological process' }, { value: 'F', label: 'Molecular function' }, { value: 'C', label: 'Cellular component' }], aspects);
      row.appendChild(UI.field('GO aspect', aSel));
      row.appendChild(el('<div class="mgdb-hub-field mgdb-hub-field-action"><button type="submit" class="mgdb-button mgdb-button-primary">Test</button></div>'));
      row.addEventListener('submit', function (e) { e.preventDefault(); bg = bgSel.value; aspects = aSel.value; label = ''; run(input.get()); });
      UI.body(form).appendChild(row);
      root.appendChild(form);
      var out = el('<div class="et-stack"></div>');
      root.appendChild(out);
      function run(ids) {
        if (ids.length < 3) { out.innerHTML = ''; out.appendChild(UI.message('Enter at least three genes.', 'info')); return; }
        ctx.set({ genes: ids.length <= 2000 ? ids.join(',') : '', bg: bg === 'annotated' ? '' : bg, aspects: aspects === 'PFC' ? '' : aspects, label: label || '' });
        out.innerHTML = '';
        if (ids.length > 2000) { out.appendChild(UI.message('A link carries at most 2,000 genes, so Copy link will not reopen this list.', 'info')); }
        out.appendChild(UI.loading('Testing ' + U.plural(ids.length, 'gene') + '…'));
        ET.api('enrich', { genome: ET.state.genome.key, ids: ids, background: bg, aspects: aspects, limit: 2000 }, { post: true, signal: ctx.signal }).then(function (r) {
          if (!ctx.alive()) { return; }
          show(r);
        }, function (e) { if (ctx.alive()) { out.innerHTML = ''; out.appendChild(UI.errorBox(e)); } });
      }
      function show(r) {
        out.innerHTML = '';
        var sig = r.terms.filter(function (t) { return t.fdr <= 0.05; });
        var p = UI.panel({ title: (label ? esc(label) + ': ' : '') + U.plural(sig.length, 'term') + ' at FDR ≤ 0.05',
          sub: fmtInt(r.annotated) + ' of the ' + fmtInt(r.list) + ' genes carry a GO annotation; background ' + fmtInt(r.background) + ' genes (' + esc(r.background_rule) + '); ' + fmtInt(r.tested) + ' terms tested' });
        out.appendChild(p);
        if (r.missing && r.missing.length) { UI.body(p).appendChild(UI.message(U.plural(r.missing.length, 'identifier') + ' matched no gene: <span class="et-mono">' + esc(r.missing.slice(0, 30).join(', ')) + '</span>', 'info')); }
        var top = r.terms.slice(0, 25);
        if (top.length) {
          var fig = C.figure({ label: 'The most enriched GO terms', filename: 'go_enrichment' });
          UI.body(p).appendChild(fig);
          var aspectColor = { P: ET.SERIES[0], F: ET.SERIES[1], C: ET.SERIES[2] };
          var aspectName = { P: 'biological process', F: 'molecular function', C: 'cellular component' };
          var groups = ['P', 'F', 'C'].filter(function (a) { return top.some(function (t) { return t.aspect === a; }); });
          C.plot(fig.plotNode, groups.map(function (a) {
            var idx = top.map(function (t, i) { return i; }).filter(function (i) { return top[i].aspect === a; });
            return { type: 'bar', orientation: 'h', name: aspectName[a], x: idx.map(function (i) { return -Math.log(Math.max(top[i].fdr, 1e-300)) / Math.LN10; }), y: idx,
                     marker: { color: aspectColor[a] }, hoverinfo: 'text',
                     hovertext: idx.map(function (i) { var t = top[i]; return '<b>' + esc(t.name) + '</b><br>' + t.go + '<br>' + t.x + ' of the list, ' + t.K + ' of the background · ' + t.fold + '×<br>FDR ' + t.fdr.toExponential(2); }) };
          }), { barmode: 'overlay', xaxis: { title: { text: '−log₁₀ FDR' } },
                yaxis: { tickmode: 'array', tickvals: top.map(function (_, i) { return i; }), ticktext: top.map(function (t) { return t.name.length > 48 ? t.name.slice(0, 46) + '…' : t.name; }), autorange: 'reversed', tickfont: { size: 11 } },
                shapes: [{ type: 'line', x0: -Math.log(0.05) / Math.LN10, x1: -Math.log(0.05) / Math.LN10, yref: 'paper', y0: 0, y1: 1, line: { color: ET.INK, width: 1 } }],
                margin: { l: 10, r: 12, t: 10, b: 48 } }, { height: Math.max(300, top.length * 20 + 90) });
          fig.caption.textContent = 'The line marks FDR 0.05. A term counts a gene annotated to it or to any term under it (is_a and part_of).';
        } else {
          UI.body(p).appendChild(UI.message('No term reaches two genes of the list with a background of 5 to 2,000 genes.', 'info'));
        }
        var tp = UI.panel({ title: r.terms.length < r.tested ? 'The ' + fmtInt(r.terms.length) + ' most enriched of ' + fmtInt(r.tested) + ' terms tested' : 'Every term tested' });
        out.appendChild(tp);
        new ET.DataTable(UI.body(tp), { rows: r.terms, rowKey: 'go', exportName: 'go_enrichment', sort: { key: 'p', dir: 'asc' }, caption: 'GO terms tested',
          notes: ['one-sided hypergeometric, Benjamini-Hochberg', 'background: ' + r.background_rule + ' (' + r.background + ')', 'GO release: ' + (r.go_release || '')],
          columns: [
            { key: 'go', label: 'Term', type: 'str', render: function (t) { return '<a href="https://amigo.geneontology.org/amigo/term/' + encodeURIComponent(t.go) + '">' + esc(t.go) + '</a>'; } },
            { key: 'name', label: 'Name', type: 'str' },
            { key: 'aspect', label: 'Aspect', type: 'str', value: function (t) { return { P: 'process', F: 'function', C: 'component' }[t.aspect]; } },
            { key: 'x', label: 'In list', type: 'num', int: true }, { key: 'K', label: 'In background', type: 'num', int: true },
            { key: 'fold', label: 'Fold', type: 'num', render: function (t) { return t.fold == null ? '—' : t.fold.toFixed(2); } },
            { key: 'p', label: 'p', type: 'num', render: function (t) { return t.p.toExponential(2); } },
            { key: 'fdr', label: 'FDR', type: 'num', render: function (t) { return '<span class="' + (t.fdr <= 0.05 ? 'et-strong' : '') + '">' + t.fdr.toExponential(2) + '</span>'; } },
            { key: 'genes', label: 'Genes', type: 'str', value: function (t) { return t.genes.join(' '); }, render: function (t) {
              return t.genes.slice(0, 6).map(function (g) { return UI.geneLink(g); }).join(', ') + (t.x > 6 ? ' <span class="et-muted">and ' + (t.x - 6) + ' more</span>' : '');
            } }
          ] });
      }
      if (start.length) { run(start); }
    }
  });
})(window, document);
