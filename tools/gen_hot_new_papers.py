#!/usr/bin/env python3
"""Generate templates/static/mgdb_hot_new_papers.bau.

Bauplan tokenizes on parentheses and does not read HTML, so every literal ( and
) has to be escaped. Write ordinary HTML below; @@name@@ becomes $(name),
applied after the escaping so no generated syntax passes through it.
"""

import pathlib
import re

OUT = pathlib.Path(__file__).resolve().parent.parent / "src/templates/static/mgdb_hot_new_papers.bau"

BODY = r"""
<meta name="twitter:card" content="summary">
<meta name="twitter:site" content="@MaizeGDB">
<meta property="og:url" content="https://maizegdb.org/hot_new_papers">
<meta property="og:title" content="MaizeGDB Hot New Papers | Editorial Board Recommended Readings">
<meta property="og:description" content="Noteworthy maize primary literature recommended each month by the MaizeGDB Editorial Board, with the board's own comments on why each paper matters.">

<main class="mgdb-page mgdb-hnp-page" id="hnp-top">

  <nav class="mgdb-breadcrumb" aria-label="Breadcrumb">
    <a href="/">Home</a><span aria-hidden="true">&rsaquo;</span><a href="/sitemap">Community</a><span aria-hidden="true">&rsaquo;</span><span aria-current="page">Hot New Papers</span>
  </nav>

  <section class="mgdb-hero" aria-labelledby="hnp-title">
    <div class="mgdb-hero-emblem">
      <img src="/images/quicklinks/editorial_board.png" alt="" width="128" height="128" decoding="async" />
      <strong>Editorial<br />Board</strong>
    </div>
    <div class="mgdb-hero-body">
      <p class="mgdb-hero-stamp"><span aria-hidden="true">&#9679;</span> Updated&#58; @@built_date@@</p>
      <h1 id="hnp-title">Hot New Papers</h1>
      <p class="mgdb-hero-description">@@total_papers@@ papers recommended by the MaizeGDB Editorial Board between @@first_year@@ and @@last_year@@, each with the board's comments on why it is worth reading.</p>
    </div>
  </section>

  <nav class="mgdb-section-tabs" aria-label="Sections on this page">
    <a href="#hnp-papers" class="is-current">Recommended readings</a>
    <a href="#hnp-board">Editorial Board</a>
    <a href="#hnp-trend">Recommendations by year</a>
    <a href="#hnp-metrics">Metrics</a>
    <a href="#hnp-resources">Other resources</a>
  </nav>

  <!-- ==================================================================
       Recommended readings
       ================================================================== -->
  <section id="hnp-papers" aria-labelledby="hnp-papers-title">
    <div class="mgdb-section-heading">
      <div><h2 id="hnp-papers-title">Recommended readings</h2></div>
      <p>Search every recommendation, or narrow to one year, quarter, month or board member.</p>
    </div>

    <div class="mgdb-message mgdb-message-info" role="note">
      <div>From 2026 the Editorial Board publishes its recommendations quarterly rather than monthly.</div>
    </div>

    <form id="hnp-filters" class="mgdb-search" role="search">
      <div class="mgdb-search-row">
        <div class="mgdb-field">
          <label class="mgdb-label" for="hnp-term">Search titles, citations, abstracts and board comments</label>
          <input class="mgdb-input" id="hnp-term" name="term" type="search" placeholder="drought, CRISPR, pangenome, nitrogen" autocomplete="off" />
        </div>
        <div class="mgdb-field hnp-field-narrow">
          <label class="mgdb-label" for="hnp-year">Year</label>
          <select class="mgdb-select" id="hnp-year" name="year">
            <option value="0">All years</option>
            @@year_options@@
          </select>
        </div>
        <div class="mgdb-field hnp-field-narrow">
          <label class="mgdb-label" for="hnp-period" id="hnp-period-label">@@period_label@@</label>
          <select class="mgdb-select" id="hnp-period" name="period">
            @@period_options@@
          </select>
        </div>
      </div>

      <div class="mgdb-search-row">
        <div class="mgdb-field">
          <label class="mgdb-label" for="hnp-recommender">Recommending member</label>
          <select class="mgdb-select" id="hnp-recommender" name="recommender">
            <option value="0">Any member</option>
            @@recommender_options@@
          </select>
        </div>
        <div class="mgdb-field hnp-field-narrow">
          <label class="mgdb-label" for="hnp-sort">Sort</label>
          <select class="mgdb-select" id="hnp-sort" name="sort">
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="title">Title A to Z</option>
            <option value="recommender">Recommending member</option>
          </select>
        </div>
      </div>

      <div class="mgdb-form-actions">
        <button class="mgdb-button mgdb-button-primary" type="submit">Search</button>
        <button class="mgdb-button mgdb-button-quiet" type="reset" id="hnp-reset">Reset</button>
      </div>

      <p class="mgdb-hint hnp-examples">
        <span>Try&#58;</span>
        <button type="button" class="hnp-example" data-term="drought">drought</button>
        <button type="button" class="hnp-example" data-term="CRISPR">CRISPR</button>
        <button type="button" class="hnp-example" data-term="pangenome">pangenome</button>
        <button type="button" class="hnp-example" data-term="nitrogen">nitrogen</button>
        <button type="button" class="hnp-example" data-term="single-cell">single-cell</button>
        <button type="button" class="hnp-example" data-term="teosinte">teosinte</button>
      </p>
    </form>

    <div class="hnp-results-head">
      <p id="hnp-status" aria-live="polite">@@initial_status@@</p>
      <a class="mgdb-button mgdb-button-quiet" id="hnp-export" href="@@export_url@@" download>Export TSV</a>
    </div>

    <div id="hnp-notes"></div>
    <div id="hnp-results" class="hnp-list">@@paper_list@@</div>

    <div class="mgdb-empty" id="hnp-empty" hidden>
      <h3>No recommendations matched</h3>
      <p>Try a broader term, or clear the year and member filters. The board's comments are searched as well as the papers, so a word from a comment will find its paper. To search all maize literature rather than the board's picks, use the <a href="/data_center/reference">literature search</a>.</p>
    </div>
  </section>

  <!-- ==================================================================
       Editorial Board
       ================================================================== -->
  <section id="hnp-board" aria-labelledby="hnp-board-title">
    <div class="mgdb-section-heading">
      <div><h2 id="hnp-board-title">Editorial Board</h2></div>
    </div>

    <div class="mgdb-prose hnp-prose">
      <p>The MaizeGDB Editorial Board is charged with the task of recommending noteworthy maize primary literature on a monthly basis. This list highlights research of interest to maize researchers and is appropriate for use in by journal clubs. The inaugural board was convened in January 2005 by <a href="/person?id=16906">Virginia Walbot</a>.</p>
    </div>

    <div class="hnp-board-panel">
      <div class="mgdb-field hnp-field-narrow">
        <label class="mgdb-label" for="hnp-board-year">Membership year</label>
        <select class="mgdb-select" id="hnp-board-year">
          @@board_year_options@@
        </select>
      </div>
      <div id="hnp-board-members" class="hnp-board-members">@@board_members@@</div>
    </div>
  </section>

  <!-- ==================================================================
       Recommendations by year
       ================================================================== -->
  <section id="hnp-trend" aria-labelledby="hnp-trend-title">
    <div class="mgdb-section-heading">
      <div><h2 id="hnp-trend-title">Recommendations by year</h2></div>
      <p>How many papers the board has recommended in each year, and in which months.</p>
    </div>

    <figure class="mgdb-figure">
      <div class="mgdb-chart" id="hnp-year-chart" role="img" aria-label="Bar chart of the number of papers recommended by the MaizeGDB Editorial Board in each year">
        <span class="mgdb-chart-fallback">Loading recommendations by year&hellip;</span>
      </div>
      <figcaption>@@trend_note@@</figcaption>
      <details class="mgdb-chart-data">
        <summary>View the data behind this chart</summary>
        <div class="mgdb-table-scroll">
          <table class="mgdb-table" id="hnp-year-table" data-sortable>
            <caption>Papers recommended in each year</caption>
            <thead><tr><th scope="col" data-sort="number"><button type="button">Year</button></th><th scope="col" data-sort="number" class="mgdb-numeric"><button type="button">Papers</button></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </details>
    </figure>

    <figure class="mgdb-figure mgdb-spaced">
      <div class="mgdb-chart mgdb-chart-tall" id="hnp-month-chart" role="img" aria-label="Heat map of the number of papers recommended in each month of each year">
        <span class="mgdb-chart-fallback">Loading the monthly pattern&hellip;</span>
      </div>
      <figcaption>@@cadence_note@@</figcaption>
    </figure>
  </section>

  <!-- ==================================================================
       Metrics
       ================================================================== -->
  <section id="hnp-metrics" aria-labelledby="hnp-metrics-title">
    <div class="mgdb-section-heading">
      <div><h2 id="hnp-metrics-title">Metrics</h2></div>
      <p>Read live from the MaizeGDB database.</p>
    </div>

    <div class="mgdb-metric-grid">
      <article class="mgdb-metric">
        <div class="mgdb-metric-top">
          <h3>Papers recommended</h3>
          <span class="mgdb-metric-badge">All years</span>
        </div>
        <div class="mgdb-metric-stat">
          <strong class="mgdb-metric-value">@@total_papers@@</strong>
        </div>
        <p class="mgdb-metric-description">Primary literature selected by the board since @@first_year@@.</p>
      </article>

      <article class="mgdb-metric">
        <div class="mgdb-metric-top">
          <h3>Years</h3>
          <span class="mgdb-metric-badge">With picks</span>
        </div>
        <div class="mgdb-metric-stat">
          <strong class="mgdb-metric-value">@@total_years@@</strong>
        </div>
        <p class="mgdb-metric-description">Years in which the board published a reading list.</p>
      </article>

      <article class="mgdb-metric">
        <div class="mgdb-metric-top">
          <h3>Recommending members</h3>
          <span class="mgdb-metric-badge">Named</span>
        </div>
        <div class="mgdb-metric-stat">
          <strong class="mgdb-metric-value">@@total_members@@</strong>
        </div>
        <p class="mgdb-metric-description">Board members credited with at least one recommendation.</p>
      </article>

      <article class="mgdb-metric">
        <div class="mgdb-metric-top">
          <h3>With a comment</h3>
          <span class="mgdb-metric-badge">Curated</span>
        </div>
        <div class="mgdb-metric-stat">
          <strong class="mgdb-metric-value">@@with_comment_pct@@</strong>
        </div>
        <p class="mgdb-metric-description">@@with_comment_note@@</p>
      </article>
    </div>
  </section>

  <!-- ==================================================================
       Other resources
       ================================================================== -->
  <section id="hnp-resources" aria-labelledby="hnp-resources-title">
    <div class="mgdb-section-heading">
      <div><h2 id="hnp-resources-title">Other resources</h2></div>
    </div>

    <div class="mgdb-resource-panel">
      <div class="mgdb-resource-list hnp-resource-list">
        <a href="/data_center/reference">
          <strong>Literature Search</strong>
          <span>Every reference in MaizeGDB, not only the board's picks</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/editorial_board">
          <strong>About the Board</strong>
          <span>Who serves on the Editorial Board and how it works</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/person">
          <strong>People and Organizations</strong>
          <span>Find a maize researcher or a laboratory</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/gene_center/gene">
          <strong>Gene Data Hub</strong>
          <span>Genes and gene models by name or position</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/data_center/locus">
          <strong>Locus Data Hub</strong>
          <span>Classic genetic loci, alleles, and phenotypes</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/genome">
          <strong>Genome Data Hub</strong>
          <span>Assemblies hosted at MaizeGDB</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/data_center/qtl">
          <strong>QTL Data Hub</strong>
          <span>Quantitative trait loci and their intervals</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/maize_meeting">
          <strong>Maize Meeting</strong>
          <span>The annual Maize Genetics Conference</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/mnl">
          <strong>Maize Newsletter</strong>
          <span>The Maize Genetics Cooperation Newsletter archive</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
        <a href="/contact">
          <strong>Contact MaizeGDB</strong>
          <span>Suggest a paper or tell us about a missing record</span>
          <span class="mgdb-resource-badge">Internal</span>
        </a>
      </div>
    </div>
  </section>

  <script id="hnp-year-data" type="application/json">@@year_data@@</script>
  <script id="hnp-month-data" type="application/json">@@month_data@@</script>

</main>
"""


def build():
    body = BODY
    body = body.replace("\\", "\\\\")
    body = body.replace("(", r"\(").replace(")", r"\)")
    body = re.sub(r"@@([a-z_]+)@@", r"$(\1)", body)

    text = "$$SYNTAX-LEVEL 2\n*(mgdb-hot-new-papers\n" + body.strip("\n") + "\n)\n"

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(text, encoding="utf-8")

    slots = len(re.findall(r"@@[a-z_]+@@", BODY))
    variables = sorted(set(re.findall(r"@@([a-z_]+)@@", BODY)))
    expected = slots + 1
    opens = len(re.findall(r"(?<!\\)\(", text))
    closes = len(re.findall(r"(?<!\\)\)", text))

    print(f"wrote {OUT} ({len(text.splitlines())} lines)")
    print(f"{len(variables)} distinct variables in {slots} slots")
    print(f"unescaped '(' {opens}, ')' {closes}  (expect {expected} each)")
    if opens != expected or closes != expected:
        raise SystemExit("unbalanced Bauplan block -- check the escaping")
    print("variables:", ", ".join(variables))


if __name__ == "__main__":
    build()
