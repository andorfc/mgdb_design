<?PHP
/* file: mnl.php
 *
 * purpose: main controller for /mnl, the Maize Genetics Cooperation
 *          Newsletter archive.
 *
 * Replaces the modernized archive over the canonical /mnl route.
 * controller.php checks controllers/<CONTROLLER>.php first, so this takes the
 * route without touching controllers/community/mnl.php or the community
 * templates. Pre-redesign files are archived in the redesign repository under
 * legacy/mnl/. Rollback: delete this file.
 *
 * The newsletter stopped publishing after volume 94 in 2020, so the volume
 * list is final. It is held here rather than in a data file for that reason.
 *
 * ON FORMAT BADGES: the design mockup for this page carried Born-digital /
 * Complete PDF / Scanned badges and noted in its own footer that they were
 * illustrative. They are not shipped. Every one of the 94 volume URLs returns
 * an HTML index page, so there is no per-volume format distinction observable
 * from here, and inventing one on an archive page would misdescribe the
 * holdings. The one era split the page can state is the one the newsletter
 * itself documents: it became fully electronic with volume 89 in 2015.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/mnl.php');

  define('MNL_ELECTRONIC_FROM', 89);

  function mnlEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }

  /* Whole numbers under 10 MB, one decimal above, so "127 MB" and "8 MB" both
     read at a glance rather than "127.15 MB". */
  function mnlSize($bytes) {
    $mb = $bytes / 1048576;
    if ($mb < 1) { return max(1, (int) round($bytes / 1024)) . ' KB'; }
    return ($mb < 10 ? number_format($mb, 1) : number_format($mb, 0)) . ' MB';
  }

  /* Every volume of the newsletter, 1929 to 2020, carried over verbatim
     from the link list in controllers/community/mnl.php -- volume number,
     year, the month recorded for the first eight volumes, and the URL on
     mnl.maizegdb.org. Two URL shapes are in use and both are live; they
     are kept exactly as published rather than normalized, because the
     older form is what external citations point at. */
  $MNL_VOLUMES = array(
    array(94, 2020, '', 'https://mnl.maizegdb.org/94'),
    array(93, 2019, '', 'https://mnl.maizegdb.org/93'),
    array(92, 2018, '', 'https://mnl.maizegdb.org/92'),
    array(91, 2017, '', 'https://mnl.maizegdb.org/91'),
    array(90, 2016, '', 'https://mnl.maizegdb.org/90'),
    array(89, 2015, '', 'https://mnl.maizegdb.org/89'),
    array(88, 2014, '', 'https://mnl.maizegdb.org/88'),
    array(87, 2013, '', 'https://mnl.maizegdb.org/87'),
    array(86, 2012, '', 'https://mnl.maizegdb.org/86'),
    array(85, 2011, '', 'https://mnl.maizegdb.org/85'),
    array(84, 2010, '', 'https://mnl.maizegdb.org/84'),
    array(83, 2009, '', 'https://mnl.maizegdb.org/mnl/83/'),
    array(82, 2008, '', 'https://mnl.maizegdb.org/mnl/82/'),
    array(81, 2007, '', 'https://mnl.maizegdb.org/mnl/81/'),
    array(80, 2006, '', 'https://mnl.maizegdb.org/mnl/80/'),
    array(79, 2005, '', 'https://mnl.maizegdb.org/mnl/79/'),
    array(78, 2004, '', 'https://mnl.maizegdb.org/mnl/78/'),
    array(77, 2003, '', 'https://mnl.maizegdb.org/mnl/77/'),
    array(76, 2002, '', 'https://mnl.maizegdb.org/mnl/76/'),
    array(75, 2001, '', 'https://mnl.maizegdb.org/mnl/75/'),
    array(74, 2000, '', 'https://mnl.maizegdb.org/mnl/74/'),
    array(73, 1999, '', 'https://mnl.maizegdb.org/mnl/73/'),
    array(72, 1998, '', 'https://mnl.maizegdb.org/mnl/72/'),
    array(71, 1997, '', 'https://mnl.maizegdb.org/mnl/71/'),
    array(70, 1996, '', 'https://mnl.maizegdb.org/mnl/70/'),
    array(69, 1995, '', 'https://mnl.maizegdb.org/mnl/69/'),
    array(68, 1994, '', 'https://mnl.maizegdb.org/mnl/68/'),
    array(67, 1993, '', 'https://mnl.maizegdb.org/mnl/67/'),
    array(66, 1992, '', 'https://mnl.maizegdb.org/mnl/66/'),
    array(65, 1991, '', 'https://mnl.maizegdb.org/mnl/65/'),
    array(64, 1990, '', 'https://mnl.maizegdb.org/mnl/64/'),
    array(63, 1989, '', 'https://mnl.maizegdb.org/mnl/63/'),
    array(62, 1988, '', 'https://mnl.maizegdb.org/mnl/62/'),
    array(61, 1987, '', 'https://mnl.maizegdb.org/mnl/61/'),
    array(60, 1986, '', 'https://mnl.maizegdb.org/mnl/60/'),
    array(59, 1985, '', 'https://mnl.maizegdb.org/mnl/59/'),
    array(58, 1984, '', 'https://mnl.maizegdb.org/mnl/58/'),
    array(57, 1983, '', 'https://mnl.maizegdb.org/mnl/57/'),
    array(56, 1982, '', 'https://mnl.maizegdb.org/mnl/56/'),
    array(55, 1981, '', 'https://mnl.maizegdb.org/mnl/55/'),
    array(54, 1980, '', 'https://mnl.maizegdb.org/mnl/54/'),
    array(53, 1979, '', 'https://mnl.maizegdb.org/mnl/53/'),
    array(52, 1978, '', 'https://mnl.maizegdb.org/mnl/52/'),
    array(51, 1977, '', 'https://mnl.maizegdb.org/mnl/51/'),
    array(50, 1976, '', 'https://mnl.maizegdb.org/mnl/50/'),
    array(49, 1975, '', 'https://mnl.maizegdb.org/mnl/49/'),
    array(48, 1974, '', 'https://mnl.maizegdb.org/mnl/48/'),
    array(47, 1973, '', 'https://mnl.maizegdb.org/mnl/47/'),
    array(46, 1972, '', 'https://mnl.maizegdb.org/mnl/46/'),
    array(45, 1971, '', 'https://mnl.maizegdb.org/mnl/45/'),
    array(44, 1970, '', 'https://mnl.maizegdb.org/mnl/44/'),
    array(43, 1969, '', 'https://mnl.maizegdb.org/mnl/43/'),
    array(42, 1968, '', 'https://mnl.maizegdb.org/mnl/42/'),
    array(41, 1967, '', 'https://mnl.maizegdb.org/mnl/41/'),
    array(40, 1966, '', 'https://mnl.maizegdb.org/mnl/40/'),
    array(39, 1965, '', 'https://mnl.maizegdb.org/mnl/39/'),
    array(38, 1964, '', 'https://mnl.maizegdb.org/mnl/38/'),
    array(37, 1963, '', 'https://mnl.maizegdb.org/mnl/37/'),
    array(36, 1962, '', 'https://mnl.maizegdb.org/mnl/36/'),
    array(35, 1961, '', 'https://mnl.maizegdb.org/mnl/35/'),
    array(34, 1960, '', 'https://mnl.maizegdb.org/mnl/34/'),
    array(33, 1959, '', 'https://mnl.maizegdb.org/mnl/33/'),
    array(32, 1958, '', 'https://mnl.maizegdb.org/32'),
    array(31, 1957, '', 'https://mnl.maizegdb.org/31'),
    array(30, 1956, '', 'https://mnl.maizegdb.org/30'),
    array(29, 1955, '', 'https://mnl.maizegdb.org/29'),
    array(28, 1954, '', 'https://mnl.maizegdb.org/28'),
    array(27, 1953, '', 'https://mnl.maizegdb.org/27'),
    array(26, 1952, '', 'https://mnl.maizegdb.org/26'),
    array(25, 1951, '', 'https://mnl.maizegdb.org/mnl/25/'),
    array(24, 1950, '', 'https://mnl.maizegdb.org/mnl/24/'),
    array(23, 1949, '', 'https://mnl.maizegdb.org/mnl/23/'),
    array(22, 1948, '', 'https://mnl.maizegdb.org/mnl/22/'),
    array(21, 1947, '', 'https://mnl.maizegdb.org/mnl/21/'),
    array(20, 1946, '', 'https://mnl.maizegdb.org/mnl/20/'),
    array(19, 1945, '', 'https://mnl.maizegdb.org/mnl/19/'),
    array(18, 1944, '', 'https://mnl.maizegdb.org/mnl/18/'),
    array(17, 1943, '', 'https://mnl.maizegdb.org/mnl/17/'),
    array(16, 1942, '', 'https://mnl.maizegdb.org/mnl/16/'),
    array(15, 1941, '', 'https://mnl.maizegdb.org/mnl/15/'),
    array(14, 1940, '', 'https://mnl.maizegdb.org/mnl/14/'),
    array(13, 1939, '', 'https://mnl.maizegdb.org/mnl/13/'),
    array(12, 1938, '', 'https://mnl.maizegdb.org/mnl/12/'),
    array(11, 1937, '', 'https://mnl.maizegdb.org/mnl/11/'),
    array(10, 1936, '', 'https://mnl.maizegdb.org/mnl/10/'),
    array(9, 1935, '', 'https://mnl.maizegdb.org/mnl/09/'),
    array(8, 1934, 'Nov', 'https://mnl.maizegdb.org/mnl/08/'),
    array(7, 1934, 'Sep', 'https://mnl.maizegdb.org/mnl/07/'),
    array(6, 1934, 'Feb', 'https://mnl.maizegdb.org/mnl/06/'),
    array(5, 1934, 'Jan', 'https://mnl.maizegdb.org/mnl/05/'),
    array(4, 1933, 'Nov', 'https://mnl.maizegdb.org/mnl/04/'),
    array(3, 1933, 'Jan', 'https://mnl.maizegdb.org/mnl/03/'),
    array(2, 1932, 'Oct', 'https://mnl.maizegdb.org/mnl/02/'),
    array(1, 1929, 'Apr', 'https://mnl.maizegdb.org/mnl/01/'),
  );

  /* Whole-volume PDF for each volume that has one, with its size.

     Found by walking all 94 volume index pages on mnl.maizegdb.org: the
     complete-volume file is the one whose name begins "00", which several
     volumes label outright as "Volume NN, PDF Version". Every URL below
     was fetched and confirmed to return 200 with content-type
     application/pdf.

     Volumes 88 to 94 are absent on purpose. Those years were published as
     separate per-article PDFs with no combined file, which is the same
     shift the page describes as becoming fully electronic.

     The sizes are not decoration. These scans run to 127 MB, and a reader
     on a phone or a field connection should see that before tapping. */
  $MNL_PDFS = array(
    87 => array('https://mnl.maizegdb.org/87/00mnl87.pdf', 21906382),
    86 => array('https://mnl.maizegdb.org/86/00mnl86.pdf', 10737387),
    85 => array('https://mnl.maizegdb.org/85/00mnl85.pdf', 127150770),
    84 => array('https://mnl.maizegdb.org/84/00mnl84.pdf', 17966695),
    83 => array('https://mnl.maizegdb.org/mnl/83/00mnl83.pdf', 18460970),
    82 => array('https://mnl.maizegdb.org/mnl/82/mnl82.pdf', 86872445),
    81 => array('https://mnl.maizegdb.org/mnl/81/mnl81.pdf', 8853662),
    80 => array('https://mnl.maizegdb.org/mnl/80/mnl80.pdf', 14719854),
    79 => array('https://mnl.maizegdb.org/mnl/79/mnl79.pdf', 13838060),
    78 => array('https://mnl.maizegdb.org/mnl/78/mnl78.pdf', 8848001),
    77 => array('https://mnl.maizegdb.org/mnl/77/mnl77.pdf', 12351087),
    76 => array('https://mnl.maizegdb.org/mnl/76/mnl76.pdf', 13216120),
    75 => array('https://mnl.maizegdb.org/mnl/75/mnl75.pdf', 8933836),
    74 => array('https://mnl.maizegdb.org/mnl/74/00MNL%2074or.pdf', 37077508),
    73 => array('https://mnl.maizegdb.org/mnl/73/00MNL%2073or.pdf', 45313213),
    72 => array('https://mnl.maizegdb.org/mnl/72/00MNL%2072or.pdf', 41007682),
    71 => array('https://mnl.maizegdb.org/mnl/71/00MNL%2071or.pdf', 40087140),
    70 => array('https://mnl.maizegdb.org/mnl/70/00MNL%2070or.pdf', 55634492),
    69 => array('https://mnl.maizegdb.org/mnl/69/00MNL%2069or.pdf', 92748141),
    68 => array('https://mnl.maizegdb.org/mnl/68/00MNL%2068or.pdf', 76047349),
    67 => array('https://mnl.maizegdb.org/mnl/67/00MNL%2067or.pdf', 72703095),
    66 => array('https://mnl.maizegdb.org/mnl/66/00MNL%2066or.pdf', 70053001),
    65 => array('https://mnl.maizegdb.org/mnl/65/00MNL%2065or.pdf', 64327199),
    64 => array('https://mnl.maizegdb.org/mnl/64/00MNL%2064or.pdf', 67627135),
    63 => array('https://mnl.maizegdb.org/mnl/63/00MNL%2063or.pdf', 66121634),
    62 => array('https://mnl.maizegdb.org/mnl/62/00MNL%2062or.pdf', 53246838),
    61 => array('https://mnl.maizegdb.org/mnl/61/00MNL%2061or.pdf', 48346949),
    60 => array('https://mnl.maizegdb.org/mnl/60/00MNL%2060or.pdf', 56392570),
    59 => array('https://mnl.maizegdb.org/mnl/59/00MNL%2059or.pdf', 22247972),
    58 => array('https://mnl.maizegdb.org/mnl/58/00MNL%2058or.pdf', 60163173),
    57 => array('https://mnl.maizegdb.org/mnl/57/00MNL%2057or.pdf', 49167691),
    56 => array('https://mnl.maizegdb.org/mnl/56/00MNL%2056or.pdf', 23154864),
    55 => array('https://mnl.maizegdb.org/mnl/55/00MNL%2055or.pdf', 18288891),
    54 => array('https://mnl.maizegdb.org/mnl/54/00MNL%2054or.pdf', 18342321),
    53 => array('https://mnl.maizegdb.org/mnl/53/00MNL%2053or.pdf', 19927444),
    52 => array('https://mnl.maizegdb.org/mnl/52/00MNL%2052or.pdf', 9203480),
    51 => array('https://mnl.maizegdb.org/mnl/51/00MNL%2051or.pdf', 14273772),
    50 => array('https://mnl.maizegdb.org/mnl/50/00MNL%2050or.pdf', 20963319),
    49 => array('https://mnl.maizegdb.org/mnl/49/00MNL%2049or.pdf', 19166479),
    48 => array('https://mnl.maizegdb.org/mnl/48/00MNL%2048or.pdf', 20283189),
    47 => array('https://mnl.maizegdb.org/mnl/47/00MNL%2047or.pdf', 22058546),
    46 => array('https://mnl.maizegdb.org/mnl/46/00MNL%2046or.pdf', 19216510),
    45 => array('https://mnl.maizegdb.org/mnl/45/00MNL%2045or.pdf', 21937277),
    44 => array('https://mnl.maizegdb.org/mnl/44/00MNL%2044%20Appendixor.pdf', 4255607),
    43 => array('https://mnl.maizegdb.org/mnl/43/00MNL%2043or.pdf', 19192181),
    42 => array('https://mnl.maizegdb.org/mnl/42/00MNL%2042or.pdf', 16920782),
    41 => array('https://mnl.maizegdb.org/mnl/41/00MNL%2041or.pdf', 20273727),
    40 => array('https://mnl.maizegdb.org/mnl/40/00MNL%2040or.pdf', 18871783),
    39 => array('https://mnl.maizegdb.org/mnl/39/00MNL%2039or.pdf', 20029068),
    38 => array('https://mnl.maizegdb.org/mnl/38/00MNL%2038or.pdf', 13628394),
    37 => array('https://mnl.maizegdb.org/mnl/37/00MNL%2037or.pdf', 19773550),
    36 => array('https://mnl.maizegdb.org/mnl/36/00MNL%2036%20Appendixor.pdf', 2038626),
    35 => array('https://mnl.maizegdb.org/mnl/35/00MNL%2035or.pdf', 18023094),
    34 => array('https://mnl.maizegdb.org/mnl/34/00MNL%2034or.pdf', 11898614),
    33 => array('https://mnl.maizegdb.org/mnl/33/00MNL%2033or.pdf', 21452701),
    32 => array('https://mnl.maizegdb.org/32/00MNL%2032or.pdf', 17721368),
    31 => array('https://mnl.maizegdb.org/31/00MNL%2031or.pdf', 25855588),
    30 => array('https://mnl.maizegdb.org/30/00MNL%2030or.pdf', 25956161),
    29 => array('https://mnl.maizegdb.org/29/00MNL%2029roo.pdf', 6122776),
    28 => array('https://mnl.maizegdb.org/28/00MNL%2028roo.pdf', 6027939),
    27 => array('https://mnl.maizegdb.org/27/00MNL%2027roo.pdf', 6088473),
    26 => array('https://mnl.maizegdb.org/26/00MNL%2026roo.pdf', 4139833),
    25 => array('https://mnl.maizegdb.org/mnl/25/00MNL%2025roo.pdf', 4261264),
    24 => array('https://mnl.maizegdb.org/mnl/24/00MNL%2024roo.pdf', 4794680),
    23 => array('https://mnl.maizegdb.org/mnl/23/00MNL%2023roo.pdf', 4959858),
    22 => array('https://mnl.maizegdb.org/mnl/22/00MNL%2022roo.pdf', 3940579),
    21 => array('https://mnl.maizegdb.org/mnl/21/00MNL21.pdf', 11685961),
    20 => array('https://mnl.maizegdb.org/mnl/20/00MNL20.pdf', 4497709),
    19 => array('https://mnl.maizegdb.org/mnl/19/00MNL19.pdf', 6352699),
    18 => array('https://mnl.maizegdb.org/mnl/18/00MNL18_opt.pdf', 4557091),
    17 => array('https://mnl.maizegdb.org/mnl/17/00MNL17.pdf', 6568840),
    16 => array('https://mnl.maizegdb.org/mnl/16/00MNL16.pdf', 7808239),
    15 => array('https://mnl.maizegdb.org/mnl/15/00MNL15.pdf', 6737370),
    14 => array('https://mnl.maizegdb.org/mnl/14/00MNL14.pdf', 8071469),
    13 => array('https://mnl.maizegdb.org/mnl/13/00MNL13.pdf', 2901944),
    12 => array('https://mnl.maizegdb.org/mnl/12/00MNL12.pdf', 4426615),
    11 => array('https://mnl.maizegdb.org/mnl/11/00MNL11.pdf', 3511358),
    10 => array('https://mnl.maizegdb.org/mnl/10/00MNL10.pdf', 3150218),
    9 => array('https://mnl.maizegdb.org/mnl/09/00MNL09.pdf', 2819999),
    8 => array('https://mnl.maizegdb.org/mnl/08/00MNL08.pdf', 2187591),
    7 => array('https://mnl.maizegdb.org/mnl/07/00MNL07.pdf', 1609902),
    6 => array('https://mnl.maizegdb.org/mnl/06/00MNL06.pdf', 474697),
    5 => array('https://mnl.maizegdb.org/mnl/05/00MNL05.pdf', 1161833),
    4 => array('https://mnl.maizegdb.org/mnl/04/00MNL04.pdf', 1022948),
    3 => array('https://mnl.maizegdb.org/mnl/03/00MNL03.pdf', 1685795),
    2 => array('https://mnl.maizegdb.org/mnl/02/00MNL02.pdf', 446400),
    1 => array('https://mnl.maizegdb.org/mnl/01/00MNL01.pdf', 5437329),
  );

  /* ---- shape the volumes ------------------------------------------------- */

  $volumes = array();
  foreach ($MNL_VOLUMES as $row) {
    list($number, $year, $month, $url) = $row;
    $decade = ((int) floor($year / 10)) * 10;
    $pdf = isset($MNL_PDFS[$number]) ? $MNL_PDFS[$number] : null;
    $volumes[] = array(
      'number'     => $number,
      'year'       => $year,
      'month'      => $month,
      'url'        => $url,
      'decade'     => $decade,
      'electronic' => $number >= MNL_ELECTRONIC_FROM,
      'pdf'        => $pdf ? $pdf[0] : '',
      'pdf_size'   => $pdf ? mnlSize($pdf[1]) : '',
    );
  }

  $years = array();
  foreach ($volumes as $v) { $years[$v['year']] = true; }
  $first_year = min(array_keys($years));
  $last_year  = max(array_keys($years));

  $by_decade = array();
  foreach ($volumes as $v) { $by_decade[$v['decade']][] = $v; }
  krsort($by_decade);

  $decade_options = '';
  foreach ($by_decade as $decade => $items) {
    $decade_options .= '<option value="' . mnlEsc($decade) . '">' . mnlEsc($decade)
                     . 's (' . count($items) . ')</option>';
  }

  /* One row of markup drives both views. The grid and the table are the same
     list styled two ways rather than two renderings that could disagree. */
  $cards = '';
  $rows  = '';
  foreach ($volumes as $v) {
    $label   = 'Volume ' . $v['number'];
    $when    = $v['month'] !== '' ? $v['month'] . ' ' . $v['year'] : (string) $v['year'];
    $search  = $label . ' ' . $when . ' ' . $v['year'] . ' ' . $v['decade'] . 's';
    $badge   = $v['electronic']
      ? '<span class="mgdb-pill mgdb-pill-ok">Electronic</span>'
      : '';
    $has_pdf = $v['pdf'] !== '';
    $search .= $has_pdf ? ' pdf' : '';
    $attrs = ' data-decade="' . mnlEsc($v['decade']) . '"'
           . ' data-era="' . ($v['electronic'] ? 'electronic' : 'earlier') . '"'
           . ' data-pdf="' . ($has_pdf ? 'yes' : 'no') . '"'
           . ' data-search="' . mnlEsc($search) . '"';

    /* The size rides in the link text, not a title attribute: a 127 MB
       download is the kind of thing a reader needs before they tap, and a
       tooltip is invisible on a phone. */
    $pdf_link = $has_pdf
      ? '<a class="mnl-pdf-link" href="' . mnlEsc($v['pdf']) . '">'
        . 'PDF <span class="mnl-pdf-size">' . mnlEsc($v['pdf_size']) . '</span></a>'
      : '';

    $cards .= '<li class="mnl-card"' . $attrs . '>'
            . '<a class="mnl-card-link" href="' . mnlEsc($v['url']) . '">'
            . '<span class="mnl-card-number">' . mnlEsc($v['number']) . '</span>'
            . '<span class="mnl-card-year">' . mnlEsc($when) . '</span>'
            . '</a>'
            . ($badge ? '<span class="mnl-card-badge">' . $badge . '</span>' : '')
            . ($pdf_link ? '<div class="mnl-card-pdf">' . $pdf_link . '</div>' : '')
            . '</li>';

    $rows .= '<tr' . $attrs . '>'
           . '<th scope="row"><a href="' . mnlEsc($v['url']) . '">' . mnlEsc($label) . '</a></th>'
           . '<td>' . mnlEsc($when) . '</td>'
           . '<td>' . ($pdf_link ?: '<span class="mgdb-muted">Per-article only</span>') . '</td>'
           . '<td>' . ($badge ?: '<span class="mgdb-muted">&mdash;</span>') . '</td>'
           . '<td class="mnl-row-url"><a href="' . mnlEsc($v['url']) . '">'
           . mnlEsc(preg_replace('~^https?://~', '', $v['url'])) . '</a></td>'
           . '</tr>';
  }

  /* ---- page -------------------------------------------------------------- */

  $bauplan = new Bauplan('Maize Genetics Cooperation Newsletter | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-mnl.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-mnl.js');
  $bauplan->head('<meta name="description" content="Every volume of the Maize Genetics Cooperation Newsletter, 1929 to 2020: 94 volumes of working research notes shared across the maize community.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_mnl.bau');
  $body->get('volume-count')->replace(number_format(count($volumes)));
  $body->get('first-year')->replace((string) $first_year);
  $body->get('last-year')->replace((string) $last_year);
  $body->get('electronic-from')->replace((string) MNL_ELECTRONIC_FROM);
  $body->get('decade-count')->replace((string) count($by_decade));
  $body->get('pdf-count')->replace((string) count($MNL_PDFS));
  $body->get('decade-options')->replace($decade_options);
  $body->get('volume-cards')->replace($cards);
  $body->get('volume-rows')->replace($rows);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
