<?php
/* file: include/genome_growth_lib.php
 *
 * purpose: the "Genome datasets hosted at MaizeGDB" timeline -- the one series,
 *          in one place.
 *
 *          The chart under Metrics on /genome and the standalone figure page
 *          at /genome_figure draw the same curve, the same landmark labels and
 *          the same label heights. They read them from here rather than each
 *          keeping a copy, so the figure a talk is built from cannot drift
 *          away from the figure on the site.
 *
 *          Everything about the data is decided in this file. The two callers
 *          supply the rows they have already read and render what comes back;
 *          the drawing code decides nothing.
 *
 * history:
 *  09/20/26  claude  created, extracted verbatim from
 *                    controllers/genome/genome_center_modern.php
 */

/*
 * Release-year coverage below which the curated series is drawn instead.
 *
 * The chart is only honest if most assemblies carry a date, so coverage is
 * measured and the page falls back to the curated series when the column is
 * too sparse. When the release_date cleanup lands this switches over on its
 * own, with no code change.
 */
if (!defined('GC_GROWTH_COVERAGE_FLOOR')) { define('GC_GROWTH_COVERAGE_FLOOR', 0.9); }

/*
 * One release year out of chado.genome_metadata.release_date, which is free
 * text. The documented formats are YYYY-MM-DD and YYYY; the column also still
 * holds DD-Mon-YY, M/D/YYYY, "Nov, 2017", "fall 2017" and "1st of February
 * 2017 (pre-release)". Only a year is needed, so all of those are reduced to
 * one rather than parsed as dates.
 */
if (!function_exists('gcReleaseYear')) {
function gcReleaseYear($value) {
    $v = trim((string)$value);
    if ($v === '' || stripos($v, 'n/a') === 0) { return null; }

    // Documented formats first, then anything carrying a four-digit year.
    if (preg_match('/^(19|20)\d{2}(-\d{2}-\d{2})?$/', $v, $m)) {
        return (int)substr($v, 0, 4);
    }
    if (preg_match('/(19|20)\d{2}/', $v, $m)) {
        return (int)$m[0];
    }
    // Legacy DD-Mon-YY, the one remaining shape with no four-digit year.
    if (preg_match('/^\d{1,2}-[A-Za-z]{3}-(\d{2})$/', $v, $m)) {
        return 2000 + (int)$m[1];
    }
    return null;
}
}

/*
 * Landmark releases are editorial annotations on the curve, not database
 * values, so they are kept whichever series is drawn.
 */
function mgdbGenomeMilestones() {
    return array(
        2008 => 'B73',
        2015 => 'W22, PH207',
        2018 => 'Mo17, A188, European flints',
        2020 => 'NAM founders',
        2022 => 'PanAnd v1',
        2024 => 'PanAnd v2',
        2026 => 'Highland and lowland landraces',
    );
}

/*
 * Where each landmark label sits on the chart, in assemblies above the axis,
 * so the stems step up the curve rather than overprinting one another.
 */
function mgdbGenomeMilestoneLevels() {
    return array(
        2008 => 32, 2015 => 32, 2018 => 64, 2020 => 108,
        2022 => 132, 2024 => 156, 2026 => 174,
    );
}

/* The record kept by hand for the redesign, used while the column is sparse. */
function mgdbGenomeCuratedGrowth() {
    return array(
        array(2008, 1), array(2009, 1), array(2010, 2), array(2011, 1), array(2012, 1),
        array(2013, 3), array(2014, 1), array(2015, 1), array(2016, 5), array(2017, 10),
        array(2018, 15), array(2019, 25), array(2020, 51), array(2021, 52), array(2022, 75),
        array(2023, 76), array(2024, 101), array(2025, 123), array(2026, 158),
    );
}

/**
 * The growth series, and the data note that has to accompany it.
 *
 * @param array $assembly_names  assembly names of the visible completed
 *                               assemblies -- the collection the chart counts.
 * @param array $date_rows       rows of {assembly_name, release_date} from
 *                               chado.genome_metadata.
 *
 * @return array {data: <the JSON the chart is drawn from>, note: <HTML>}
 */
function mgdbGenomeGrowth($assembly_names, $date_rows) {

    $total_assemblies = count($assembly_names);

    $years_by_assembly = array();
    foreach ($date_rows as $row) {
        $year = gcReleaseYear($row['release_date']);
        if ($year !== null) {
            $years_by_assembly[trim((string)$row['assembly_name'])] = $year;
        }
    }

    $year_counts = array();
    $dated_assemblies = 0;
    foreach ($assembly_names as $name) {
        $name = trim((string)$name);
        if (!isset($years_by_assembly[$name])) { continue; }
        $year = $years_by_assembly[$name];
        $year_counts[$year] = isset($year_counts[$year]) ? $year_counts[$year] + 1 : 1;
        $dated_assemblies++;
    }

    $coverage = ($total_assemblies > 0) ? ($dated_assemblies / $total_assemblies) : 0;

    if ($coverage >= GC_GROWTH_COVERAGE_FLOOR && !empty($year_counts)) {
        ksort($year_counts);
        $points  = array();
        $running = 0;
        $first   = min(array_keys($year_counts));
        $last    = max(array_keys($year_counts));
        for ($y = $first; $y <= $last; $y++) {
            $running += isset($year_counts[$y]) ? $year_counts[$y] : 0;
            $points[] = array($y, $running);
        }
        $growth_source = 'database';
        $growth_note = 'Cumulative count of assemblies by release year, read live from '
                     . 'genome_metadata.release_date. All ' . number_format($total_assemblies)
                     . ' visible completed assemblies carry a release year.';
    } else {
        /* The final point is today's live published total rather than the
           hand-kept figure, so the curve ends where the metrics say it does. */
        $points = mgdbGenomeCuratedGrowth();
        $last_index = count($points) - 1;
        if ($points[$last_index][0] >= (int) date('Y') - 1 && $total_assemblies > 0) {
            $points[$last_index][1] = $total_assemblies;
        }
        $growth_source = 'curated';
        $growth_note = '<strong>Data note:</strong> this timeline is a curated historical record, '
                     . 'not a figure derived from the assembly table; its final point is today\'s live '
                     . 'published total. Only '
                     . number_format($dated_assemblies) . ' of ' . number_format($total_assemblies)
                     . ' visible completed assemblies carry a release year in '
                     . 'genome_metadata.release_date, so a year-by-year count cannot be computed '
                     . 'from it yet. This chart switches to live data automatically once that '
                     . 'column is populated. Every other figure on this page is read directly '
                     . 'from the database.';
    }

    return array(
        'data' => array(
            'source'     => $growth_source,
            'dated'      => $dated_assemblies,
            'total'      => $total_assemblies,
            'points'     => $points,
            'milestones' => mgdbGenomeMilestones(),
            'levels'     => mgdbGenomeMilestoneLevels(),
        ),
        'note' => $growth_note,
    );
}
