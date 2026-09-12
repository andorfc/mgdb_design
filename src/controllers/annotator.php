<?php
/* file: annotator.php  (top-level retirement controller)
 *
 * purpose: retired 2026-09-10 (Carson). Community curation is being retired as
 *          a feature, so /annotator redirects to /contribute_data.
 *
 * WHAT IT WAS. The public profile of a community curator -- who they are and
 * what they annotated. It is the reader-facing half of the feature whose
 * authoring half was /login and /curation/FreeText, so it goes with them.
 *
 * IT WAS ALSO BROKEN, AND LOUDLY. /annotator is one of the two routes
 * controller.php hands to controllers/community.php by name (the other is
 * /person), and the record URL fataled on PHP 8:
 *
 *     /annotator/727 -> HTTP 200, 417 bytes
 *     Uncaught Error: Undefined constant "LAST_NAME"
 *       in controllers/community/annotator_functions.php:37
 *
 * A public stack trace naming server paths, served with a 200 so no monitor
 * would call it an error. The bare /annotator answered 200 with the generic
 * shell -- the usual MaizeGDB shape where a 200 proves nothing about whether a
 * page exists. Neither form has rendered an annotator profile in years.
 *
 * WHY A TOP-LEVEL FILE TAKES THE ROUTE. controller.php resolves
 * ./controllers/<CONTROLLER>.php *before* it checks its two by-name cases:
 *
 *     $controller_filename = "./controllers/" . CONTROLLER . ".php";
 *     if (file_exists($controller_filename)) { include ($controller_filename); }
 *     else if (CONTROLLER == "person" || CONTROLLER == "annotator")
 *        include ("./controllers/community.php");
 *
 * so this file shadows the community.php branch for /annotator and every
 * /annotator/<id> beneath it, and leaves /person untouched. The redirect drops
 * the id deliberately: there is no per-annotator destination to preserve.
 *
 * controllers/community/annotator.php, annotator_functions.php,
 * templates/community/annotator.bau and record_data/annotator_data.php all stay
 * on disk, unreachable.
 *
 * Rollback: delete this file and /annotator goes back to community.php -- and
 * back to serving the stack trace above.
 */

  header('Location: /contribute_data', true, 301);
  exit;
?>
