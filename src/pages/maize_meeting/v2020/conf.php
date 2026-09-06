<?PHP


/* The registrant list this reads is outside the web root and does not exist on
   every host. fopen() on a missing path returned false with a warning, and
   fgets(false) is a TypeError under PHP 8, so validate_password() died before
   it could answer -- taking posters.php and videos.php down with it. Both
   answered HTTP 200 with the fatal, a stack trace and the missing path printed
   into the body.

   No readable list now means no valid passwords, which is what a gate should do
   when it cannot read its own list. Fixed 2026-09-05. */
function get_valid_pws($file="/home/vMGM2020/posters/pw.txt") {
  $pws = array();
  if (!is_readable($file)) {
    return $pws;
  }

  $fp = fopen($file, 'r');
  if ($fp === false) {
    return $pws;
  }

  $i=0;
  //File headers:
  //PRESENTER_FIRST_NAME 	 PRESENTER_LAST_NAME 	 PRESENTER_EMAIL 	 CATEGORY 	POSTER 	 PRESENTER_ROLE 	TITLE 	ABSTRACT 
  while ($line = fgets($fp)) {
    $line_parts = explode("\t", $line);
    $pws[$i] = trim($line_parts[0]);
    $i++;
  }
  fclose($fp);

  return $pws;
}

/* Answers true or false on every path. It used to fall off the end -- returning
   null -- when no password was supplied, and callers only worked because null
   is falsy. The comparison is strict: both sides are strings, and a gate should
   not be deciding what equals what. */
function validate_password($password) {

//$valid_passwords = array("john", "portwood", "cornghost");
$valid_passwords = get_valid_pws();

    if (!is_string($password) || strlen($password) == 0) {
        return false;
    }

    return in_array($password, $valid_passwords, true);
}


$year = "v2020";
$reg_year = "2020";
$date = "June 25-26";
$date_start = "June 25";
$date_end = "June 26";
$annual = "62nd";
$abstract_open = "";
$abstract_open_md = "";
$abstract_deadline = "";
$reg_deadline = "January 17";
$reg_cancel = "February 11";
$aid_deadline = "January 17";
$author_notify = "February 15";
$chair = "Clinton Whipple";
$chair_email = "whipple@byu.edu";
$hotel = "Zoom";
$hotel_deadline = "January 17";
$hotel_cancel = "February 11";

//New financial aid
$magnet_open = "November 5";
$magnet_open_md = "November 5";
$magnet_deadline = "December 2";

$pui_open = "November 5";
$pui_open_md = "November 5";
$pui_deadline = "December 2";

$dib_open = "November 5";
$dib_open_md = "November 5";
$dib_deadline = "December 2";

$location = "Online";
$location_link = "";
$city = "";
$state = "Planet Earth";
$event_link = "";

$reg_cost = "\$900";
$student_cost = "\$150";
$postdoc_cost = "\$700";
$ret_cost = "\$500";
$reg_cost_late = "\$1000";
$student_cost_late = "\$700";
$postdoc_cost_late = "\$800";
$ret_cost_late = "\$600";
$late_cost = "\$1000";
$room_rate = "\$229";
$room_total = "\$687"; //$room_rate * 3
$room_rate2 = "\$259"; //ocean view rooms in HI
$room_total2 = "\$777"; //$room_rate * 3
$one_day_cost = "\$TBD"; //New one day only (Fri or Sat) registrations for 2019 
$one_day_cost_late = "\$TBD";


$speaker1_img = "images/hake.jpg";
$speaker2_img = "images/han.jpg";
$speaker3_img = "images/hochholdinger.jpg";
$speaker4_img = "images/bomblies.jpg";


$speaker1_name = "Sarah Hake";
$speaker1_aff = "UC Berkeley";
$speaker1_title = "Organogenesis in maize - lessons from mutants.";
$speaker1_homepage = "https://plantandmicrobiology.berkeley.edu/profile/hake";

$speaker2_name = "Bin Han";
$speaker2_aff = "Chinese Academy of Sciences";
$speaker2_title = "";
$speaker2_homepage = "http://www.ncgr.ac.cn/about_director.asp";

$speaker3_name = "Frank Hochholdinger";
$speaker3_aff = "University of Bonn";
$speaker3_title = "Genetic dissection of maize root development.";
$speaker3_homepage = "https://www.hochholdinger-lab.uni-bonn.de/cfg/crop-functional-genomics/people/hochholdinger/prof.-dr.-frank-hochholdinger";

$speaker4_name = "Kirsten Bomblies";
$speaker4_aff = "ETH Zurich";
$speaker4_title = "How to tango with four  - the evolution of meiotic stability in autotetraploid Arabidopsis arenosa.";
$speaker4_homepage = "https://biol.ethz.ch/en/the-department/people/person-detail.MjU1Mzkz.TGlzdC80NjAsOTIzMDMxMjIy.html";

?>