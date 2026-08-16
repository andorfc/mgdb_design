/* ==========================================================================
   Gene record page — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-gene-record.css and
   templates/static/mgdb_gene_record.bau.

   One request to /api/v1/records/gene/{id} builds the whole page. The record
   page this replaces made nineteen, sharded across ajax0..6.maizegdb.org
   subdomains to get around the browser's per-host connection limit — a
   workaround that one request makes unnecessary — and cost over 1,700 database
   queries between them.

   A second, parallel request fetches the canonical protein's length. That value
   is not in the database and has to be read from the sequence service, which
   takes about 470 ms; keeping it out of the main request means the page is
   interactive in around 130 ms and the domain track fills in when the length
   arrives. If it never arrives, the domains are listed as a table instead.

   The identity is already on the page, server-rendered, so a failure here
   degrades to a record that still says what gene it is and links to its data.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var CHIP_LIMIT = 40;    // chips shown before the rest collapse behind a toggle
  var ROW_LIMIT = 25;     // table rows shown before the rest collapse

  function byId(id) { return document.getElementById(id); }
  function escape(value) { return MGDB.escapeHtml(value); }
  function show(el, visible) { if (el) { el.hidden = !visible; } }
  function num(value) { return (value === null || value === undefined) ? '' : Number(value).toLocaleString(); }

  var els = {};
  var payload = null;

  /* ------------------------------------------------------------------------
     Small builders
     ------------------------------------------------------------------------ */

  /* A reference from the API is {type, id, name, html}. Anything without a page
     is still named, just not linked. */
  function refLink(ref) {
    if (!ref || !ref.name) { return ''; }
    if (!ref.html) { return escape(ref.name); }
    return '<a href="' + escape(ref.html) + '">' + escape(ref.name) + '</a>';
  }

  function extLink(url, label) {
    if (!url) { return escape(label); }
    return '<a href="' + escape(url) + '" rel="noopener" class="gene-record-ext">' +
           escape(label) + '</a>';
  }

  function fact(label, value, note) {
    if (!value && value !== 0) { return ''; }
    return '<div><dt>' + escape(label) + '</dt><dd>' + value +
           (note ? '<small>' + escape(note) + '</small>' : '') + '</dd></div>';
  }

  function block(title, description, body) {
    if (!body) { return ''; }
    return '<div class="gene-record-block"><h3>' + escape(title) + '</h3>' +
           (description ? '<p class="gene-record-block-note">' + escape(description) + '</p>' : '') +
           body + '</div>';
  }

  function empty(message) {
    return '<p class="mgdb-empty">' + escape(message) + '</p>';
  }

  /* A table whose long tail collapses. The hidden rows stay in the DOM so
     find-in-page and assistive technology can still reach them. */
  function table(headers, rows, options) {
    if (!rows || !rows.length) { return ''; }
    options = options || {};
    var head = '<thead><tr>' + headers.map(function (h) {
      return '<th scope="col">' + escape(h) + '</th>';
    }).join('') + '</tr></thead>';

    var visible = rows.slice(0, options.limit || ROW_LIMIT);
    var rest = rows.slice(options.limit || ROW_LIMIT);

    var html = '<div class="mgdb-table-scroll"><table class="mgdb-table">' + head +
               '<tbody>' + visible.join('') + '</tbody></table></div>';

    if (rest.length) {
      html += '<details class="gene-record-more"><summary>Show the remaining ' +
              rest.length.toLocaleString() + '</summary>' +
              '<div class="mgdb-table-scroll"><table class="mgdb-table">' + head +
              '<tbody>' + rest.join('') + '</tbody></table></div></details>';
    }
    return html;
  }

  function chipList(items) {
    if (!items || !items.length) { return ''; }
    function chip(item) {
      return '<li>' + (item.html
        ? '<a href="' + escape(item.html) + '">' + escape(item.name) + '</a>'
        : '<span>' + escape(item.name) + '</span>') + '</li>';
    }
    var visible = items.slice(0, CHIP_LIMIT).map(chip).join('');
    var html = '<ul class="gene-record-chips-list">' + visible + '</ul>';
    if (items.length > CHIP_LIMIT) {
      html += '<details class="gene-record-more"><summary>Show the remaining ' +
              (items.length - CHIP_LIMIT).toLocaleString() + '</summary>' +
              '<ul class="gene-record-chips-list">' +
              items.slice(CHIP_LIMIT).map(chip).join('') + '</ul></details>';
    }
    return html;
  }

  /* ------------------------------------------------------------------------
     Header
     ------------------------------------------------------------------------ */

  function renderHeader(data, sections, counts) {
    var overview = sections.overview || {};
    var fn = sections['function'] || {};

    // The one-line answer to "what does this gene do", which on the page this
    // replaces was three clicks deep.
    if (fn.summary) {
      els.functionLine.innerHTML = escape(fn.summary);
      show(els.functionLine, true);
    }

    var locus = sections.locus;
    if (locus && locus.synonyms && locus.synonyms.length) {
      els.synonyms.innerHTML = 'Also known as ' +
        locus.synonyms.map(function (s) { return '<em>' + escape(s.name) + '</em>'; }).join(', ');
      show(els.synonyms, true);
    }

    /* The server already rendered location, model type and transcript count.
       These are the facts that need the API. */
    var extra = '';
    if (overview.species) { extra += fact('Species', '<em>' + escape(overview.species) + '</em>'); }
    if (overview.assembly && overview.assembly.name) {
      extra += fact('Assembly',
        '<a href="' + escape(overview.assembly.html) + '">' + escape(overview.assembly.name) + '</a>',
        [overview.assembly.provider, overview.assembly.date].filter(Boolean).join(', '));
    }
    if (overview.annotation && overview.annotation.name) {
      extra += fact('Annotation', escape(overview.annotation.name));
    }
    if (els.facts) { els.facts.insertAdjacentHTML('beforeend', extra); }

    // Actions: the things a reader came here to do.
    var actions = [];
    var seq = sections.sequences;
    if (seq && seq.genomic) {
      actions.push('<a class="mgdb-button mgdb-button-primary" href="' + escape(seq.genomic) +
        '" rel="noopener">Genomic FASTA</a>');
    }
    if (overview.chromosome && overview.start) {
      actions.push('<a class="mgdb-button mgdb-button-secondary" rel="noopener" href="' +
        escape('https://jbrowse.maizegdb.org/?data=' + encodeURIComponent(overview.assembly && overview.assembly.name || '') +
               '&loc=' + encodeURIComponent(overview.chromosome + ':' + overview.start + '..' + overview.end)) +
        '">Genome browser</a>');
    }
    var pan = sections.pan_gene;
    if (pan && pan.pan_gene && pan.pan_gene.name) {
      actions.push('<a class="mgdb-button mgdb-button-quiet" href="/pan_gene_center/pan_gene/' +
        encodeURIComponent(pan.pan_gene.name) + '">Pan-gene</a>');
    }
    els.actions.innerHTML = actions.join('');

    // Availability chips. Each links to its section; a count of zero renders
    // greyed and unclickable, so an empty section is visible without a click.
    var chips = [
      { key: 'ontology', label: 'GO terms', target: 'function' },
      { key: 'protein_domains', label: 'domains', target: 'structure' },
      { key: 'pan_gene_members', label: 'pan-gene members', target: 'pan_gene' },
      { key: 'insertions', label: 'insertions', target: 'variation' },
      { key: 'snp_traits', label: 'GWAS associations', target: 'variation' },
      { key: 'alleles', label: 'alleles', target: 'locus' },
      { key: 'references', label: 'references', target: 'references' }
    ];
    var chipHtml = chips.map(function (chip) {
      var count = counts[chip.key] || 0;
      if (!count) {
        return '<span class="gene-record-chip is-zero">no ' + escape(chip.label) + '</span>';
      }
      return '<a class="gene-record-chip" href="#gene-record-' + chip.target + '">' +
             '<strong>' + count.toLocaleString() + '</strong> ' + escape(chip.label) + '</a>';
    }).join('');
    els.chips.innerHTML = chipHtml;
    show(els.chips, true);
  }

  /* ------------------------------------------------------------------------
     Overview
     ------------------------------------------------------------------------ */

  function renderOverview(overview) {
    if (!overview) { return false; }
    var html = '';

    var facts = '';
    facts += fact('Gene model', overview.name ? '<code>' + escape(overview.name) + '</code>' : '');
    if (overview.symbol) { facts += fact('Gene symbol', '<em>' + escape(overview.symbol) + '</em>'); }
    if (overview.full_name) { facts += fact('Full name', escape(overview.full_name)); }
    if (overview.chromosome && overview.start) {
      facts += fact('Position',
        escape(overview.chromosome) + ':' + num(overview.start) + '&ndash;' + num(overview.end),
        num(overview.span_bp) + ' bp on the genome');
    }
    /* Strand is deliberately shown as unrecorded rather than omitted. It is NULL
       for every row in the database, and a reader who knows a gene is on the
       minus strand should be told that MaizeGDB does not hold it, not left to
       assume the page forgot. */
    if (overview.strand_note) {
      facts += fact('Strand', '<span class="gene-record-unknown">not recorded</span>',
        overview.strand_note);
    }
    if (overview.model_type) {
      facts += fact('Model type', escape(overview.model_type.replace(/_/g, ' ')));
    }
    if (overview.is_reference_gene_model) {
      facts += fact('Reference model', 'Yes', 'the representative model for this locus');
    }
    if (overview.is_current === false) {
      facts += fact('Annotation status', 'Superseded',
        'a newer annotation of this assembly exists');
    }
    if (overview.updated) { facts += fact('Annotation note', escape(overview.updated)); }

    html += '<dl class="gene-record-facts-grid">' + facts + '</dl>';

    if (overview.loci && overview.loci.length) {
      var rows = overview.loci.map(function (locus) {
        return '<tr><td><a href="' + escape(locus.html) + '"><em>' + escape(locus.name) + '</em></a></td>' +
               '<td>' + escape(locus.full_name || '') + '</td>' +
               '<td>' + escape(locus.type || '') + '</td></tr>';
      });
      if (overview.loci.length > 1) {
        html += block('Classical genes at this model',
          'This gene model has been matched to more than one curated locus.',
          table(['Symbol', 'Full name', 'Type'], rows));
      }
    }

    els.overviewBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Structure
     ------------------------------------------------------------------------ */

  /* The domain track. Drawn only when the protein's length is known, because
     without it the domains cannot be placed: scaling to the last domain's end
     would imply the protein stops there. For lg1 that would show a domain
     ending at residue 258 as if it ran to the C-terminus, when the protein is
     399 residues long. */
  function domainTrack(domains, protein) {
    if (!protein || !protein.length_aa) { return ''; }
    var length = protein.length_aa;
    var canonical = domains.filter(function (d) {
      return d.is_canonical && d.start && d.end;
    });
    if (!canonical.length) { return ''; }

    var bars = canonical.map(function (domain, index) {
      var left = ((domain.start - 1) / length) * 100;
      var width = Math.max(((domain.end - domain.start + 1) / length) * 100, 0.6);
      return '<span class="gene-record-domain gene-record-domain-' + (index % 5) + '" ' +
             'style="left:' + left.toFixed(2) + '%;width:' + width.toFixed(2) + '%" ' +
             'title="' + escape(domain.name + ' ' + domain.start + '–' + domain.end) + '">' +
             '<span class="mgdb-visually-hidden">' +
             escape(domain.name + ', residues ' + domain.start + ' to ' + domain.end) +
             '</span></span>';
    }).join('');

    var legend = canonical.map(function (domain, index) {
      return '<li><span class="gene-record-swatch gene-record-domain-' + (index % 5) + '"></span>' +
             (domain.url ? '<a href="' + escape(domain.url) + '" rel="noopener">' + escape(domain.name) + '</a>'
                         : escape(domain.name)) +
             ' <span class="gene-record-muted">' + domain.start + '–' + domain.end + '</span></li>';
    }).join('');

    return '<figure class="gene-record-track">' +
           '<div class="gene-record-track-bar" role="img" aria-label="Protein domain positions">' +
           bars + '</div>' +
           '<div class="gene-record-track-scale"><span>1</span><span>' + num(length) + ' aa</span></div>' +
           '<ul class="gene-record-track-legend">' + legend + '</ul>' +
           '</figure>';
  }

  function renderStructure(structure) {
    if (!structure) { return false; }
    var html = '';

    var transcripts = structure.transcripts || [];
    if (transcripts.length) {
      var rows = transcripts.map(function (t) {
        return '<tr>' +
          '<td><code>' + escape(t.name) + '</code>' +
            (t.canonical ? ' <span class="mgdb-pill mgdb-pill-ok">canonical</span>' : '') + '</td>' +
          '<td>' + (t.protein ? '<code>' + escape(t.protein) + '</code>' : '&mdash;') + '</td>' +
          '<td>' + escape((t.model_type || '').replace(/_/g, ' ')) + '</td>' +
          '<td class="mgdb-numeric">' + num(t.span_bp) + '</td>' +
          '</tr>';
      });
      /* The column is "genomic span", not "length". The page this replaces
         labelled this number "Canonical Length", which reads as the protein's
         length and is not: for lg1 it showed 4,010 for a 399-residue protein. */
      html += block('Transcripts', null,
        table(['Transcript', 'Protein', 'Type', 'Genomic span (bp)'], rows));
    } else {
      html += block('Transcripts', null, empty('No transcript records for this gene model.'));
    }

    var protein = structure.protein;
    if (protein && protein.length_aa) {
      html += '<p class="gene-record-protein-length">Canonical protein <code>' +
              escape(protein.name) + '</code> is <strong>' + num(protein.length_aa) +
              ' residues</strong>.</p>';
    }

    var domains = structure.protein_domains || [];
    if (domains.length) {
      var track = domainTrack(domains, protein);
      var domainRows = domains.map(function (d) {
        return '<tr>' +
          '<td>' + (d.url ? '<a href="' + escape(d.url) + '" rel="noopener">' + escape(d.accession) + '</a>'
                          : escape(d.accession)) + '</td>' +
          '<td>' + escape(d.name || '') + '</td>' +
          '<td>' + escape(d.description || '') + '</td>' +
          '<td class="mgdb-numeric">' + num(d.start) + '&ndash;' + num(d.end) + '</td>' +
          '<td><code>' + escape(d.transcript || '') + '</code></td>' +
          '</tr>';
      });
      html += block('Protein domains',
        track ? null : 'Protein length is not recorded in this database, so the domains are listed by position rather than drawn to scale.',
        '<div id="gene-record-domain-track">' + track + '</div>' +
        table(['Accession', 'Name', 'Description', 'Residues', 'Transcript'], domainRows));
    } else {
      html += block('Protein domains', null,
        empty('No protein domains have been assigned to this gene model.'));
    }

    /* Exon and UTR structure is stated as absent rather than left out. There are
       no exon, CDS or UTR features anywhere in the database, for any organism,
       so no transcript diagram can be drawn from it. */
    if (structure.exon_structure_note) {
      html += '<p class="gene-record-gap">' + escape(structure.exon_structure_note) +
              ' To see exon and intron structure, open this region in the ' +
              '<a href="https://jbrowse.maizegdb.org/" rel="noopener">genome browser</a>.</p>';
    }

    els.structureBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Function
     ------------------------------------------------------------------------ */

  function renderFunction(fn) {
    if (!fn) { return false; }
    var html = '';
    var terms = fn.ontology || [];

    if (terms.length) {
      // Grouped by aspect, which is how a reader reads GO — what it does, where
      // it is, what process it takes part in.
      var groups = {};
      terms.forEach(function (term) {
        var key = term.domain || (term.ontology || 'Other');
        if (!groups[key]) { groups[key] = []; }
        groups[key].push(term);
      });

      Object.keys(groups).sort().forEach(function (key) {
        var rows = groups[key].map(function (term) {
          var provenance = term.evidence_label || term.evidence_code || 'source not recorded';
          return '<tr>' +
            '<td>' + (term.url ? '<a href="' + escape(term.url) + '" rel="noopener">' + escape(term.term) + '</a>'
                               : escape(term.term)) + '</td>' +
            '<td>' + escape(term.name || '') + '</td>' +
            '<td><span class="gene-record-evidence">' + escape(provenance) + '</span></td>' +
            '<td>' + escape(term.source || '') +
              (term.reference ? ' ' + refLink(term.reference) : '') + '</td>' +
            '<td>' + (term.scope === 'locus'
                      ? '<span class="mgdb-pill mgdb-pill-info">classical gene</span>'
                      : '<span class="mgdb-pill">gene model</span>') + '</td>' +
            '</tr>';
        });
        html += block(key, null,
          table(['Term', 'Name', 'Evidence', 'Asserted by', 'Applies to'], rows));
      });
    } else {
      html += block('Ontology terms', null,
        empty('No ontology terms have been assigned to this gene.'));
    }

    var products = fn.gene_products || [];
    if (products.length) {
      html += block('Gene products', 'What curators record this gene as making.',
        table(['Product', 'Type', 'Evidence'], products.map(function (p) {
          return '<tr><td>' + escape(p.name) + '</td><td>' + escape(p.type || '') +
                 '</td><td>' + escape(p.evidence || '') + '</td></tr>';
        })));
    }

    var accessions = fn.protein_accessions || [];
    if (accessions.length) {
      html += block('Protein family assignments', null,
        table(['Accession', 'Database', 'Analysis'], accessions.map(function (a) {
          return '<tr><td>' + extLink(a.url, a.accession) + '</td><td>' +
                 escape(a.database || '') + '</td><td>' + escape(a.analysis || '') + '</td></tr>';
        })));
    }

    els.functionBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Expression
     ------------------------------------------------------------------------ */

  function renderExpression(expression) {
    if (!expression) { return false; }
    var html = '';
    var any = false;

    if (expression.qteller && expression.qteller.available) {
      any = true;
      html += block('qTeller', 'Expression across tissues and experiments, at qTeller.',
        '<p><a class="mgdb-button mgdb-button-secondary" rel="noopener" href="' +
        escape(expression.qteller.url) + '">Open in qTeller</a></p>');
    }

    if (expression.efp && expression.efp.available && expression.efp.atlases.length) {
      any = true;
      /* Loaded lazily and one at a time by the browser. The page this replaces
         emitted every atlas image unconditionally, having had its availability
         check commented out with the note that checking eleven images per page
         load put too much load on the upstream server. */
      var cells = expression.efp.atlases.map(function (atlas) {
        return '<figure class="gene-record-efp">' +
          '<img loading="lazy" alt="' + escape(atlas.label + ' expression pattern') +
          '" src="' + escape(atlas.image) + '" ' +
          'onerror="this.closest(\'figure\').classList.add(\'is-missing\')">' +
          '<figcaption>' + escape(atlas.label) + '</figcaption></figure>';
      }).join('');
      html += block('eFP browser', expression.efp.source,
        '<div class="gene-record-efp-grid">' + cells + '</div>');
    }

    var relatives = [];
    if (expression.rnaseq_histogram && !expression.rnaseq_histogram.available) {
      html += '<p class="gene-record-gap">' + escape(expression.rnaseq_histogram.reason) + '</p>';
    }
    if (expression.proteomics && !expression.proteomics.available) {
      html += '<p class="gene-record-gap">' + escape(expression.proteomics.reason) + '</p>';
    }
    void relatives;

    if (expression.note) {
      html += '<p class="gene-record-block-note">' + escape(expression.note) + '</p>';
    }

    if (!any) {
      html = empty('No expression resources cover this assembly.') + html;
    }

    els.expressionBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Variation
     ------------------------------------------------------------------------ */

  function renderVariation(variation, counts) {
    if (!variation) { return false; }
    var html = '';
    var insertions = variation.insertions || [];
    var snps = variation.snp_traits || [];
    var alleles = variation.alleles || [];

    if (!insertions.length && !snps.length && !alleles.length) {
      els.variationBody.innerHTML =
        empty('No insertions, trait associations, or alleles have been reported for this gene.');
      return true;
    }

    if (insertions.length) {
      var rows = insertions.map(function (ins) {
        var stocks = (ins.stocks || []).map(function (s) {
          return '<a href="' + escape(s.html) + '">' + escape(s.name) + '</a>';
        }).join(', ');
        return '<tr>' +
          '<td>' + escape(ins.name) + '</td>' +
          '<td>' + escape(ins.gene_structures || '') + '</td>' +
          '<td>' + escape(ins.source || '') + '</td>' +
          '<td class="mgdb-numeric">' + (ins.start ? num(ins.start) : '') + '</td>' +
          '<td>' + (stocks || '<span class="gene-record-muted">no stock</span>') + '</td>' +
          '</tr>';
      });
      html += block('Insertion alleles',
        'Mutant lines carrying an insertion in this gene. Where a stock is listed, it can be ordered.',
        table(['Insertion', 'Disrupts', 'Collection', 'Position', 'Stock'], rows));
    }

    if (snps.length) {
      // Grouped by study, because a trait association only means something
      // alongside the experiment that produced it.
      var studies = {};
      snps.forEach(function (snp) {
        var key = (snp.study && snp.study.name) || 'Unattributed';
        if (!studies[key]) { studies[key] = { study: snp.study, rows: [] }; }
        studies[key].rows.push(snp);
      });
      var studyHtml = Object.keys(studies).map(function (key) {
        var group = studies[key];
        var rows = group.rows.map(function (snp) {
          return '<tr>' +
            '<td>' + escape(snp.snp || '') + '</td>' +
            '<td class="mgdb-numeric">' + num(snp.position) + '</td>' +
            '<td>' + escape(snp.gene_structure || '') + '</td>' +
            '<td>' + escape(snp.trait || '') + '</td>' +
            '<td class="gene-record-property">' + escape(snp.property || '') + '</td>' +
            '</tr>';
        });
        return '<h4>' + refLink(group.study) + '</h4>' +
               table(['Variant', 'Position', 'Region', 'Trait', 'Reported value'], rows);
      }).join('');
      html += block('Trait associations',
        'Variants in or near this gene that were associated with a trait in a genome-wide association study.',
        studyHtml);
    }

    if (alleles.length) {
      html += block('Classical alleles',
        'Curated alleles of this gene, named in the literature.',
        chipList(alleles.map(function (a) { return { name: a.name, html: null }; })));
    }

    void counts;
    els.variationBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Pan-gene
     ------------------------------------------------------------------------ */

  function renderPanGene(pan, orthologs) {
    var hasPan = pan && pan.pan_gene && pan.pan_gene.name;
    var orthoList = (orthologs && orthologs.orthologs) || [];
    if (!hasPan && !orthoList.length) {
      els.panGeneBody.innerHTML =
        empty('This gene model is not placed in a pan-gene, and no orthologs are recorded.');
      return true;
    }

    var html = '';

    if (hasPan) {
      var pg = pan.pan_gene;
      html += '<dl class="gene-record-facts-grid">' +
        fact('Pan-gene', '<a href="/pan_gene_center/pan_gene/' + encodeURIComponent(pg.name) + '">' +
             escape(pg.name) + '</a>') +
        fact('Members', num(pg.member_count), 'gene models across all assemblies') +
        fact('Assemblies', num(pan.assembly_count), 'genomes where this gene was found') +
        fact('Analysis', escape(pg.analysis || '')) +
        '</dl>';

      /* The presence strip. This is the thing MaizeGDB has that nobody else
         does: whether a gene is present across cultivated maize only, or across
         the wild Zea species too. */
      if (pan.species && pan.species.length) {
        var strip = pan.species.map(function (group) {
          var cells = group.assemblies.map(function (assembly) {
            return '<li title="' + escape(assembly) + '"><span class="mgdb-visually-hidden">' +
                   escape(assembly) + '</span></li>';
          }).join('');
          return '<div class="gene-record-species">' +
            '<h4><em>' + escape(group.species) + '</em> <span class="gene-record-muted">' +
            group.count + '</span></h4>' +
            '<ul class="gene-record-presence">' + cells + '</ul></div>';
        }).join('');
        html += block('Present in', 'One square per assembly in which this gene was found.', strip);
      }

      var members = pan.members || [];
      if (members.length) {
        var rows = members.map(function (m) {
          return '<tr' + (m.is_current_record ? ' class="is-current"' : '') + '>' +
            '<td>' + (m.html ? '<a href="' + escape(m.html) + '"><code>' + escape(m.name) + '</code></a>'
                             : '<code>' + escape(m.name) + '</code>') +
            (m.is_current_record ? ' <span class="mgdb-pill mgdb-pill-ok">this record</span>' : '') + '</td>' +
            '<td>' + escape(m.assembly || '') + '</td>' +
            '<td>' + escape(m.annotation || '') + '</td>' +
            '<td>' + escape(m.chromosome || '') + '</td>' +
            '</tr>';
        });
        html += block('Members', null,
          table(['Gene model', 'Assembly', 'Annotation', 'Chromosome'], rows));
      }
    }

    if (orthoList.length) {
      var direct = orthoList.filter(function (o) { return o.is_direct; });
      var indirect = orthoList.filter(function (o) { return !o.is_direct; });
      function orthoRows(list) {
        return list.map(function (o) {
          return '<tr><td><em>' + escape(o.species || o.kind.replace(/_ortholog.*/, '')) + '</em></td>' +
                 '<td><code>' + escape(o.identifier) + '</code></td>' +
                 '<td>' + escape(o.analysis || '') + '</td>' +
                 (list === indirect ? '<td><code>' + escape(o.via || '') + '</code></td>' : '') +
                 '</tr>';
        });
      }
      if (direct.length) {
        html += block('Orthologs in other grasses', null,
          table(['Species', 'Ortholog', 'Analysis'], orthoRows(direct)));
      }
      if (indirect.length) {
        html += block('Orthologs via other pan-gene members',
          'Recorded against a different gene model in the same pan-gene.',
          table(['Species', 'Ortholog', 'Analysis', 'Via'], orthoRows(indirect)));
      }
    }

    els.panGeneBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Classical locus
     ------------------------------------------------------------------------ */

  function renderLocus(locus) {
    if (!locus) { return false; }
    var html = '';

    html += '<dl class="gene-record-facts-grid">' +
      fact('Symbol', '<em>' + escape(locus.name || '') + '</em>') +
      fact('Full name', escape(locus.full_name || '')) +
      fact('Type', escape(locus.type || '')) +
      fact('Chromosome bin', escape(locus.bin || '')) +
      '</dl>';

    if (locus.phenotypes && locus.phenotypes.length) {
      html += block('Mutant phenotypes',
        'What is seen when this gene is disrupted. This is usually why the gene was named.',
        chipList(locus.phenotypes.map(function (p) { return { name: p.name, html: null }; })));
    }

    if (locus.comments && locus.comments.length) {
      var notes = locus.comments.map(function (c) {
        return '<div class="gene-record-note">' +
          '<h4>' + escape(c.label) + '</h4><p>' + escape(c.text) + '</p>' +
          (c.reference ? '<p class="gene-record-muted">' + refLink(c.reference) + '</p>' : '') +
          '</div>';
      }).join('');
      html += block('Curator notes', null, notes);
    }

    if (locus.synonyms && locus.synonyms.length) {
      html += block('Synonyms', 'Other names this gene has been published under.',
        table(['Name', 'Authority'], locus.synonyms.map(function (s) {
          return '<tr><td><em>' + escape(s.name) + '</em></td><td>' +
                 (s.reference ? refLink(s.reference) : escape(s.authority || '')) + '</td></tr>';
        })));
    }

    if (locus.associated_gene_models && locus.associated_gene_models.length) {
      html += block('Gene models for this gene',
        'The same classical gene as annotated in each assembly.',
        table(['Gene model', 'Assembly', 'Annotation', 'Position'],
          locus.associated_gene_models.map(function (g) {
            return '<tr' + (g.is_current_record ? ' class="is-current"' : '') + '>' +
              '<td><a href="' + escape(g.html) + '"><code>' + escape(g.name) + '</code></a>' +
              (g.is_current_record ? ' <span class="mgdb-pill mgdb-pill-ok">this record</span>' : '') + '</td>' +
              '<td>' + escape(g.assembly || '') + '</td>' +
              '<td>' + escape(g.annotation || '') + '</td>' +
              '<td>' + (g.chromosome ? escape(g.chromosome) + ':' + num(g.start) : '') + '</td>' +
              '</tr>';
          })));
    }

    if (locus.map_positions && locus.map_positions.length) {
      html += block('Genetic map positions',
        'Where this gene sits on the classical genetic maps.',
        table(['Map', 'Position (cM)', 'Bin'], locus.map_positions.map(function (m) {
          return '<tr><td>' + escape(m.map) + '</td>' +
                 '<td class="mgdb-numeric">' + (m.position === null ? '' : m.position) + '</td>' +
                 '<td>' + escape(m.bin || m.bin2 || '') + '</td></tr>';
        })));
    }

    if (locus.related_loci && locus.related_loci.length) {
      html += block('Related genes', null,
        table(['Gene', 'Relationship'], locus.related_loci.map(function (r) {
          return '<tr><td><em>' + escape(r.name) + '</em></td><td>' +
                 escape(r.qualifier || '') + '</td></tr>';
        })));
    }

    els.locusBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     References, sequences, cross-references, model quality
     ------------------------------------------------------------------------ */

  function renderReferences(section) {
    var refs = (section && section.references) || [];
    if (!refs.length) { return false; }
    els.referencesBody.innerHTML = table(['Year', 'Reference', 'Relevance'],
      refs.map(function (r) {
        return '<tr><td class="mgdb-numeric">' + (r.year || '') + '</td>' +
               '<td><a href="' + escape(r.html) + '">' + escape(r.name) + '</a></td>' +
               '<td>' + escape(r.relevance || '') + '</td></tr>';
      }), { limit: 15 });
    return true;
  }

  function renderSequences(seq) {
    if (!seq || !seq.set) { return false; }
    var html = '';

    var links = [];
    if (seq.genomic) {
      links.push('<a class="mgdb-button mgdb-button-secondary" rel="noopener" href="' +
        escape(seq.genomic) + '">Genomic FASTA</a>');
    }
    (seq.transcripts || []).forEach(function (t) {
      if (t.cdna) {
        links.push('<a class="mgdb-button mgdb-button-quiet" rel="noopener" href="' + escape(t.cdna) +
          '">cDNA ' + escape(t.name) + '</a>');
      }
      if (t.protein_url) {
        links.push('<a class="mgdb-button mgdb-button-quiet" rel="noopener" href="' + escape(t.protein_url) +
          '">Protein ' + escape(t.protein) + '</a>');
      }
    });

    html += block('Download sequence',
      'Served by the MaizeGDB sequence service for gene model set ' + seq.set + '.',
      '<div class="gene-record-actions-row">' + links.join('') + '</div>');

    if (seq.downloads && seq.downloads.length) {
      html += block('Bulk downloads',
        'The whole assembly and annotation, including GFF3 and every FASTA.',
        '<ul class="gene-record-links">' + seq.downloads.map(function (url) {
          return '<li><a href="' + escape(url) + '" rel="noopener">' + escape(url) + '</a></li>';
        }).join('') + '</ul>');
    }

    els.sequencesBody.innerHTML = html;
    return true;
  }

  function renderXrefs(section) {
    var xrefs = ((section && section.xrefs) || []).filter(function (x) { return x.display; });
    if (!xrefs.length) { return false; }
    els.xrefsBody.innerHTML = table(['Database', 'Identifier', 'Note'],
      xrefs.map(function (x) {
        return '<tr><td>' + escape(x.database) + '</td>' +
               '<td>' + extLink(x.url, x.key) + '</td>' +
               '<td>' + escape(x.comment || '') + '</td></tr>';
      }));
    return true;
  }

  /* Model quality. Every score is shown with what it means, because the number
     alone is misleading — a reader who sees a pLDDT of 57.72 and no context
     reads it as "57%, fine" rather than "below the threshold where the fold
     should be trusted". */
  function renderProvenance(structure, overview) {
    var scores = (structure && structure.scores) || [];
    if (!scores.length) { return false; }

    // Frame-by-frame pSAURON scores are specialist detail and bury the rest.
    var primary = scores.filter(function (s) { return s.interpretation; });
    var detail = scores.filter(function (s) { return !s.interpretation; });

    var html = '';
    if (primary.length) {
      html += table(['Measure', 'Value', 'What it means', 'From'],
        primary.map(function (s) {
          return '<tr><td>' + escape(s.label) + '</td>' +
            '<td class="mgdb-numeric">' + (Math.round(s.value * 10000) / 10000) + '</td>' +
            '<td>' + escape(s.interpretation) + '</td>' +
            '<td>' + escape(s.analysis || '') +
              (s.version ? ' <span class="gene-record-muted">' + escape(s.version) + '</span>' : '') +
            '</td></tr>';
        }), { limit: 20 });
    }

    if (detail.length) {
      html += '<details class="gene-record-more"><summary>Show ' + detail.length +
        ' additional raw scores</summary>' +
        table(['Measure', 'Value', 'From'], detail.map(function (s) {
          return '<tr><td>' + escape(s.label) + '</td>' +
            '<td class="mgdb-numeric">' + (Math.round(s.value * 10000) / 10000) + '</td>' +
            '<td>' + escape(s.analysis || '') + '</td></tr>';
        }), { limit: 100 }) + '</details>';
    }

    // Reproducibility: a gene model name means nothing without the annotation
    // version it came from, because models change between annotations.
    if (overview && overview.assembly && overview.assembly.name) {
      html += block('Cite this record',
        'Gene model identifiers are specific to an annotation version. Record both.',
        '<p class="gene-record-cite"><code>' + escape(overview.name || '') + '</code>, ' +
        escape(overview.assembly.name) +
        (overview.annotation && overview.annotation.name
          ? ', annotation ' + escape(overview.annotation.name) : '') +
        (overview.assembly.accession ? ' (' + escape(overview.assembly.accession) + ')' : '') +
        '. MaizeGDB, accessed ' + new Date().toISOString().slice(0, 10) + '.</p>');
    }

    els.provenanceBody.innerHTML = html;
    return true;
  }

  /* ------------------------------------------------------------------------
     Tabs and scrollspy
     ------------------------------------------------------------------------ */

  var TAB_LABELS = {
    'gene-record-overview': ['Overview', null],
    'gene-record-structure': ['Structure', 'protein_domains'],
    'gene-record-function': ['Function', 'ontology'],
    'gene-record-expression': ['Expression', null],
    'gene-record-variation': ['Variation', 'insertions'],
    'gene-record-pan_gene': ['Pan-gene', 'pan_gene_members'],
    'gene-record-locus': ['Classical gene', null],
    'gene-record-references': ['References', 'references'],
    'gene-record-sequences': ['Sequences', null],
    'gene-record-xrefs': ['Cross-references', 'xrefs'],
    'gene-record-provenance': ['Model quality', null]
  };

  function buildTabs(rendered, counts) {
    if (rendered.length < 2) { return; }
    els.tabs.innerHTML = rendered.map(function (id) {
      var entry = TAB_LABELS[id] || [id, null];
      var count = entry[1] ? (counts[entry[1]] || 0) : null;
      return '<a href="#' + id + '">' + escape(entry[0]) +
        (count ? '<span class="gene-record-tab-count">' + count.toLocaleString() + '</span>' : '') +
        '</a>';
    }).join('');
    show(els.tabs, true);

    var pairs = rendered.map(function (id) {
      return { tab: els.tabs.querySelector('a[href="#' + id + '"]'), section: byId(id) };
    }).filter(function (pair) { return pair.tab && pair.section; });

    function markCurrent(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    if (pairs.length) { markCurrent(pairs[0].section); }
    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () { markCurrent(pair.section); });
    });

    if (!window.IntersectionObserver) { return; }
    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { markCurrent(entry.target); }
      });
    }, { rootMargin: '-25% 0px -65% 0px' });
    pairs.forEach(function (pair) { observer.observe(pair.section); });
  }

  /* ------------------------------------------------------------------------
     Copy to clipboard
     ------------------------------------------------------------------------ */

  function wireCopy() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest('[data-copy-target]');
      if (!button) { return; }
      var source = byId(button.getAttribute('data-copy-target'));
      if (!source) { return; }
      var text = source.textContent.trim();
      var original = button.textContent;

      function done(message) {
        button.textContent = message;
        window.setTimeout(function () { button.textContent = original; }, 2000);
      }

      // navigator.clipboard is absent outside a secure context; selecting the
      // text is the honest fallback rather than a button that does nothing.
      if (window.navigator.clipboard && window.isSecureContext) {
        window.navigator.clipboard.writeText(text).then(function () { done('Copied'); },
          function () { done('Press Ctrl+C'); });
      } else {
        var range = document.createRange();
        range.selectNodeContents(source);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        done('Press Ctrl+C');
      }
    });
  }

  /* ------------------------------------------------------------------------
     Assembly
     ------------------------------------------------------------------------ */

  function render(response) {
    payload = response;
    var data = response.data || {};
    var sections = data.sections || {};
    var meta = response.meta || {};
    var counts = meta.counts || {};

    show(els.loading, false);
    show(els.error, false);

    renderHeader(data, sections, counts);

    var rendered = [];
    if (renderOverview(sections.overview)) { rendered.push('gene-record-overview'); }
    if (renderStructure(sections.structure)) { rendered.push('gene-record-structure'); }
    if (renderFunction(sections['function'])) { rendered.push('gene-record-function'); }
    if (renderExpression(sections.expression)) { rendered.push('gene-record-expression'); }
    if (renderVariation(sections.variation, counts)) { rendered.push('gene-record-variation'); }
    if (renderPanGene(sections.pan_gene, sections.orthologs)) { rendered.push('gene-record-pan_gene'); }
    if (renderLocus(sections.locus)) { rendered.push('gene-record-locus'); }
    if (renderReferences(sections.references)) { rendered.push('gene-record-references'); }
    if (renderSequences(sections.sequences)) { rendered.push('gene-record-sequences'); }
    if (renderXrefs(sections.xrefs)) { rendered.push('gene-record-xrefs'); }
    if (renderProvenance(sections.structure, sections.overview)) { rendered.push('gene-record-provenance'); }

    rendered.forEach(function (id) { show(byId(id), true); });
    buildTabs(rendered, counts);

    // Anything the API held back is said out loud rather than left to look like
    // the record simply contains less than it does.
    var notices = [];
    (meta.truncated || []).forEach(function (list) {
      var key = list.split('.').pop();
      notices.push('Only the first ' + meta.max_items.toLocaleString() + ' of ' +
        (counts[key] || 0).toLocaleString() + ' ' + key.replace(/_/g, ' ') + ' are shown.');
    });
    (meta.warnings || []).forEach(function (warning) { notices.push(warning.detail); });

    if (meta.other_matches && meta.other_matches.length) {
      notices.push('This identifier also matches ' +
        meta.other_matches.slice(0, 5).map(function (m) {
          return m.name + (m.assembly ? ' (' + m.assembly + ')' : '');
        }).join(', ') + '.');
    }

    if (notices.length) {
      els.notice.innerHTML = '<div><strong>Note</strong><span>' +
        notices.map(escape).join(' ') + '</span></div>';
      show(els.notice, true);
    }

    if (els.apiLink) {
      els.apiLink.href = '/api/v1/records/gene/' + encodeURIComponent(data.id);
    }

    MGDB.announce('Record loaded, ' + rendered.length + ' sections.');

    // The domain track needs the protein's length, which is not in the database
    // and costs about 470 ms to read from the sequence service. Fetched
    // separately so it never delays the page.
    upgradeDomainTrack(data.id, sections.structure);
  }

  function upgradeDomainTrack(id, structure) {
    if (!structure || !(structure.protein_domains || []).length) { return; }
    if (structure.protein && structure.protein.length_aa) { return; }

    MGDB.request('/api/v1/records/gene/' + encodeURIComponent(id) +
                 '?fields=structure&protein_length=1', { key: 'gene-record-protein' })
      .then(function (response) {
        var upgraded = response && response.data && response.data.sections &&
                       response.data.sections.structure;
        if (!upgraded || !upgraded.protein || !upgraded.protein.length_aa) { return; }

        var target = byId('gene-record-domain-track');
        if (target) {
          target.innerHTML = domainTrack(structure.protein_domains, upgraded.protein);
        }
        var note = byId('gene-record-structure-body');
        if (note) {
          var stale = note.querySelector('.gene-record-block-note');
          if (stale && /not recorded in this database/.test(stale.textContent)) {
            stale.remove();
          }
        }
      })
      .catch(function () { /* the table already says what the track would */ });
  }

  function load() {
    var main = byId('gene-record-top');
    if (!main) { return; }
    var id = main.getAttribute('data-gene-id');
    if (!id) { return; }

    /* A withdrawn gene model has no record to fetch — the API answers 410 — and
       the server has already rendered the banner saying so. Asking anyway would
       replace that with "the rest of this record could not be loaded", which
       reads as a transient failure rather than a permanent, correct answer. */
    if (main.getAttribute('data-gene-state') === 'withdrawn') {
      show(els.loading, false);
      return;
    }

    show(els.error, false);
    show(els.loading, true);

    MGDB.request('/api/v1/records/gene/' + encodeURIComponent(id), { key: 'gene-record' })
      .then(function (response) {
        if (!response || !response.data) { throw new Error('unexpected payload'); }
        render(response);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        show(els.loading, false);
        show(els.error, true);
      });
  }

  function init() {
    els = {
      functionLine: byId('gene-record-function'),
      synonyms: byId('gene-record-synonyms'),
      facts: byId('gene-record-facts'),
      actions: byId('gene-record-actions'),
      chips: byId('gene-record-chips'),
      tabs: byId('gene-record-tabs'),
      loading: byId('gene-record-loading'),
      error: byId('gene-record-error'),
      retry: byId('gene-record-retry'),
      notice: byId('gene-record-notice'),
      overviewBody: byId('gene-record-overview-body'),
      structureBody: byId('gene-record-structure-body'),
      functionBody: byId('gene-record-function-body'),
      expressionBody: byId('gene-record-expression-body'),
      variationBody: byId('gene-record-variation-body'),
      panGeneBody: byId('gene-record-pan_gene-body'),
      locusBody: byId('gene-record-locus-body'),
      referencesBody: byId('gene-record-references-body'),
      sequencesBody: byId('gene-record-sequences-body'),
      xrefsBody: byId('gene-record-xrefs-body'),
      provenanceBody: byId('gene-record-provenance-body'),
      apiLink: byId('gene-record-api-link')
    };

    if (els.retry) { els.retry.addEventListener('click', load); }
    wireCopy();
    load();
    void payload;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
