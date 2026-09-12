<?php
/* file: controllers/expression.php
 *
 * purpose: top-level controller for MaizeGDB Expression Data Hub (/expression)
 *          Shadows legacy controllers/static/expression.php.
 */

/* The hub, and only the hub. This shim used to include it whatever came after
   /expression/, so /expression/anything rendered the Expression hub and
   answered 200 -- a bogus sub-path looked like a real page. PAGE is empty for
   /expression itself. */
/* PAGE is null for /expression itself -- controller.php defines it as null when
   there is no sub-path -- so null and '' both mean "the hub". */
if (defined('PAGE') && PAGE !== null && PAGE !== '' && PAGE !== 'expression') {
  http_response_code(404);
  include('controllers/not_found.php');
  exit;
}

include_once('./controllers/expression/expression_modern.php');
