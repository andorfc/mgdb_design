<?php
/* file: genetic_ratio_exercise.php
 *
 * purpose: retired 2026-09-07 (Carson). /genetic_ratio_exercise now redirects
 *          to /data_center/image.
 *
 * A teaching exercise: four photographs of selfed ears, each captioned with the
 * segregation to count -- orp2 at 3:1, orp1/orp2 duplicate factors at 15:1,
 * B1:Peru/R1 at 15:1 with r-mottling, and c2 at 2:1:1.
 *
 * What retires it is that it has come loose from the site around it.
 *
 *  - Its only inbound link was templates/community/education-content.bau, and
 *    /education itself now answers 301 to the homepage. Nothing reaches this
 *    page from anywhere.
 *  - Every "see also related images and information" link on it -- five of them
 *    -- points at /cgi-bin/imagebrowser_mutants_by_mutation.cgi, which is a 404.
 *    So is the invitation to filter that browser by su1, pro1 or sh1.
 *  - Its breadcrumb linked google.com as "Home", and its "contact a friendly
 *    maize researcher" address is a personal mailbox at another institution.
 *
 * One request in the log window.
 *
 * The four photographs are NOT deleted and are still on the server as
 * /images/corn1.jpg through corn4.jpg, and the captions are in
 * templates/static/genetic-ratio-content.bau. If the exercise is wanted again,
 * that is everything it needs; what it would also need is a working image
 * browser to send readers to, which is /data_center/image.
 *
 * Rollback: delete this file.
 */

  header('Location: /data_center/image', true, 301);
  exit;
?>
