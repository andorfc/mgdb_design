<?php
/* file: controllers/fusarium/notfound.php
 *
 * purpose: Any /fusarium/<page> that is not a toolkit page: HTTP 404, in the
 *          toolkit's own shell. A mistyped address that answered 200 would be
 *          indexed as a real page, and a link checker would pass it.
 */

header('HTTP/1.1 404 Not Found');

list($bauplan, $content) = fptBeginPage(array(
    'title'    => 'Page not found | Fusarium Protein Toolkit',
    'nav'      => '',
    'template' => 'templates/fusarium/fpt_notfound.bau',
    'noindex'  => true,
));
$content->get('requested')->replace(fptEsc('/fusarium/' . $fptPage));
$bauplan->publish();
?>
