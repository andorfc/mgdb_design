<?php
/* file: gp_lib.php
 *
 * purpose: general purpose functions that are used by most pages throughout
 *          the website.
 *
 * history:
 *   05/08/12  eksc  created
 *   08/22/13 jp - mod getCGIParam to support session variables
 *                 and added getSessionVar function
 *   10/27/15  bbraun added function getParamDump
 */


////////////////////////////////////////////////////////////////////////////////
//                       System configuration support                         //
////////////////////////////////////////////////////////////////////////////////

// Global array to hold system information.
$system = array();


function getSystemInfo($filename='mgdb.conf') {
   $system_info_file = getSystemInfoFile($filename);
   if ($system_info_file == '') {
      // eeek! We're stuck!
      echo "
        <span class=\"pc-error\">
          Unable to find system configuration file!
        </span>";
      exit;
   }

   $system = readConfFile($system_info_file);

   // Get some system information from the $_SERVER variable
   $system['root_url'] = 'http://' . $_SERVER['HTTP_HOST'];

   // Put the path to the system info file into the system object
   $system['SYSTEM_INFO'] = $system_info_file;

   if (isset($system['error_reporting'])) {
     // Turn on error reporting (according to setting in conf file)
     turnOnErrorReporting($system);
   }

   return $system;
}//getSystemInfo()


function getSystemInfoFile($filename) {
   // The configuration file $filename will be in a directory named 'conf'.
   // Continue backward through directory tree until it is found.
   $system_info_file = '';

   // We are here:
   $dir = getcwd();

   do {
      if (file_exists("$dir/conf/$filename")) {
         $system_info_file = "$dir/conf/$filename";
      }
      else {
         if (strchr($dir, '/')) {
            $dir = substr($dir, 0, strrpos($dir, '/'));
         }
         else {
            $dir = '';
         }
      }//trim dir and try again
   } while($dir != '' && $system_info_file == '');

   return $system_info_file;
}//getSystemInfoFile


function readConfFile($filename) {
   $cfg = array();

   $lines = file($filename);
   foreach ($lines as $line) {
      $line = trim($line);
      if ($line != ''
            && !preg_match("/^#/", $line)
            && !preg_match("/^\<\?/", $line)
            && !preg_match("/^\?\>/", $line)
            && !preg_match("/^\/\*/", $line)
            && !preg_match("/\*\//", $line)) {
         list ($key, $value) = explode('=', $line, 2);
         $cfg[trim($key)] = trim($value);
      }
   }

   return $cfg;
}//readConfFile()


function readURL($url, $redirect=true) {
  $heads = get_headers($url);
  $status = preg_replace("/\S* (\d\d\d) .*/", "$1", $heads[0]);
  if ($status != 200) {
    if ($status < 400) {
      reportError("URL $url has moved, redirect link unavailable or not followed: $status");
    }
    else {
      reportError("URL $url couldn't be loaded. HTTP status: $status");
    }
    $body = "Unable to load content. Please notify the MaizeGDB team using ";
    $body .= "the feedback button above.";
  }
  
  else {
    // Carry on...
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_HEADER,         true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    if ($redirect) {
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }
    $r = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($r, $header_size);
  }

  return $body;
}//readURL


function testURL($url) {
  $h = curl_init($url);
  curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($h, CURLOPT_NOBODY, true);
  curl_exec($h);
 
  $http_code = curl_getinfo($h, CURLINFO_HTTP_CODE);
  curl_close($h);
 
  return ($http_code == 200);
}//testURL


function turnOnErrorReporting($system) {
  global $system;
  if (isset($system['error_reporting']) && $system['error_reporting'] != '') {
//error_log("Error reporting is set");
    eval("error_reporting(".$system['error_reporting'].");");
  }
}//turnOnErrorReporting()





////////////////////////////////////////////////////////////////////////////////
//                          Convenience functions                             //
////////////////////////////////////////////////////////////////////////////////

/*
 * Return a hash for the given request types
 */
function getParamDump($type='GPS') {
  $params = array();
  if (strchr($type, 'G') > -1) {
    $params = array_merge($params, $_GET);
  }
  if (strchr($type, 'P') > -1) {
    $params = array_merge($params, $_POST);
  }
  if (strchr($type, 'S') > -1) {
    $params = array_merge($params, $_SESSION);
  }

  return $params;
}//getParamDump


// doesn't throw warnings if param doesn't exist.
/* jp - added condition for session variables */
function getCGIParam($name, $type='GPS', $default='') {
   if (strchr($type, 'G') > -1 && isset($_GET[$name]))
      if (is_array($_GET[$name]))
         return $_GET[$name];
      else
         return trim($_GET[$name]);
   else if (strchr($type, 'P') > -1 && isset($_POST[$name]))
         if (is_array($_POST[$name]))
            return $_POST[$name];
         else
            return trim($_POST[$name]);
   else if (strchr($type, 'S') > -1 && isset($_SESSION[$name]))
         if (is_array($_SESSION[$name]))
            return $_SESSION[$name];
         else
            return trim($_SESSION[$name]);
   else
      return $default;
}//getCGIParam()


/**
 * Sets a session variable.
 */
function setSessionVar($key, $value) {
  $_SESSION[$key] = $value;
}


// doesn't throw warnings if param doesn't exist.
function getCookie($name, $default='') {
   if (isset($_COOKIE[$name])) {
      if (is_array($_COOKIE[$name]))
         return $_COOKIE[$name];
      else
         return trim($_COOKIE[$name]);
   }
   else
      return $default;
}//getCookie


/**
 * Pretty-prints an array's content. Used for debugging only
 */
function arrdump($arr) {
   echo "<pre>";
   var_dump($arr);
   echo "</pre>";   
}


function truncate_str($string, $length) {
  if (strlen($string) > $length) {
      $string = substr($string, 0, $length) . '...';
  }
  
  return $string;
}//truncate_str


////////////////////////////////////////////////////////////////////////////////
//                             Logging support                                //
////////////////////////////////////////////////////////////////////////////////
function reportError($err) {
   _writeToLog("ERROR>> $err\n", true);
}//reportError()

function reportWarning($err) {
   _writeToLog("WARNING>> $err\n", true);
}//reportError()

function logMessage($msg, $backtrace=false) {
  _writeToLog($msg, $backtrace);
}//logMessage

function logVarDump($obj, $desc='') {
  _writeToLog($desc . print_r($obj, true));
}//logVarDump

function _writeToLog($string, $backtrace=false) {
  global $system;
  if (isset($system['enable_log']) && !$system['enable_log']) {
    return;
  }

  if (isset($system['log_file']) && isset($system['max_logsize'])) {
    ob_start(); // <-- don't send file system errors to client browser

    if (filesize($system['log_file']) > $system['max_logsize']) {
      // just stop and hope the cronjob rolls the log soon
      return;
    }
    $fh = fopen($system['log_file'], 'a+');

    if (isset($fh) && $fh != 0) {
      list ($micro, $d) = explode(' ', microtime());
      fwrite($fh, date("d M Y H:i:s.$micro") . "-- ");
      $trace_array = debug_backtrace();
      fwrite($fh, $trace_array[1]['file'] . ':' . $trace_array[1]['line']);
      if (isset($trace_array[2])) {
        fwrite($fh, ':' . $trace_array[2]['function'] . "()\n");
        if ($backtrace) {
          fwrite($fh, "Called by ");
          fwrite($fh, $trace_array[2]['file'] . ':' . $trace_array[2]['line']);
          if (isset($trace_array[3])) {
            fwrite($fh, ':' . $trace_array[3]['function'] . "()\n");
            fwrite($fh, "from ");
            fwrite($fh, $trace_array[3]['file'] . ':' . $trace_array[3]['line']);
            if (isset($trace_array[4])) {
              fwrite($fh, ':' . $trace_array[3]['function'] . "()\n");
              fwrite($fh, "from ");
              fwrite($fh, $trace_array[4]['file'] . ':' . $trace_array[4]['line']);
            }
          }
        }
      }
    }
    fwrite($fh, "\n$string\n\n");
    fclose($fh);

    ob_end_clean();
  }
}//_writeToLog

/**
 * Escape a value for output inside HTML text or a double-quoted attribute.
 *
 * The advanced-search pages build a "Search Criteria" summary that mixes
 * authored markup with the user's own search terms, and Bauplan's replace()
 * inserts the finished string raw -- which it has to, or the <b> and <i> in
 * that summary would be printed rather than rendered. So the terms have to be
 * escaped one at a time, here at the sink.
 *
 * ENT_QUOTES matters: several of those terms land inside href="..." and a bare
 * quote would break out of the attribute. Do NOT apply this to a value on its
 * way into SQL -- that needs (int) or $DBConn->quote(), and escaping a value
 * for the wrong sink is how you get &amp; stored in the database.
 */
function mgdb_html($value) {
  if ($value === null || is_bool($value)) {
    return '';
  }

  // Decode first, then escape. The database stores pre-encoded entities as a
  // display convention -- person.name holds "A&#223;mann" for Assmann,
  // variation.name holds "ms26-&#916;E5", and 557 rows across person,
  // variation, locus and synonyms are like this. Escaping those directly would
  // print the literal "&#223;" on the page. Decoding first renders them
  // correctly, and because htmlspecialchars() still runs last, nothing
  // executable survives: "&lt;script&gt;" decodes to "<script>" and is escaped
  // straight back. One decode pass only, so it cannot be double-decoded.
  $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

  return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}//mgdb_html

/**
 * Allowlist-sanitise curator-authored HTML for display.
 *
 * The results tables and record pages are an HTML-by-design pipeline: memo
 * holds 58,656 curator-written <a href> links, reference.title italicises
 * species names, and synonyms carry allele nomenclature like P1-pr<sup>TP</sup>.
 * Escaping that (mgdb_html) would print the tags. So this keeps the markup the
 * curators meant and removes everything that could execute.
 *
 * The allowlist was read off the corpus rather than guessed: the tags actually
 * present are a, b, br, div, i, p and sup; every href is http, https or mailto;
 * and every style attribute is "margin-left: 40px" indentation.
 *
 * Unknown tags are UNWRAPPED, not escaped -- a browser already swallows
 * <mpolacco> and <Aug 2003> (curator initials and dates that happen to look
 * like markup), so unwrapping keeps every page rendering exactly as it does
 * today. Escaping them instead would surface that text, which is arguably the
 * better behaviour but is a content decision, not a security one.
 *
 * Use this for a column that is meant to carry markup. For a search term or any
 * value that is plain text, use mgdb_html() -- an allowlist is not a substitute
 * for escaping.
 */
function mgdb_safe_html($html) {
  if ($html === null || $html === '' || is_bool($html)) {
    return '';
  }

  $html = (string) $html;
  if (strpos($html, '<') === false) {
    return $html;   // nothing to parse; leave entity conventions untouched
  }

  $allowed_tags = array('a', 'b', 'br', 'div', 'em', 'i', 'p', 'span',
                        'strong', 'sub', 'sup', 'u', 'li', 'ol', 'ul');
  // Elements whose CONTENT must go too, rather than being unwrapped.
  $strip_whole  = array('script', 'style', 'iframe', 'object', 'embed',
                        'form', 'input', 'button', 'link', 'meta', 'base');
  $allowed_attr = array('href', 'title', 'target', 'rel', 'style');
  $ok_scheme    = array('http', 'https', 'mailto', 'ftp');

  // libxml assumes ISO-8859-1 without a charset declaration, and declaring one
  // via <meta> makes NOIMPLIED take the meta as the whole document. So encode
  // anything non-ASCII as a numeric entity first -- which is the convention the
  // database already uses -- and parse pure ASCII.
  $html = preg_replace_callback('/[\x{0080}-\x{10FFFF}]/u',
    function ($m) { return '&#' . mb_ord($m[0], 'UTF-8') . ';'; }, $html);
  if ($html === null) {
    return '';   // invalid UTF-8
  }

  $prev = libxml_use_internal_errors(true);
  $doc  = new DOMDocument();
  // Wrap in a container so the fragment keeps its shape: without one, libxml
  // adds an implied <p> around bare text.
  $loaded = $doc->loadHTML('<div id="mgdb-sanitise-root">' . $html . '</div>',
                           LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);

  $root = $doc->getElementById('mgdb-sanitise-root');
  if (!$loaded || !$root) {
    return mgdb_html($html);   // unparseable: fall back to escaping it
  }

  $xpath = new DOMXPath($doc);

  // 1. Dangerous elements, content and all. Deepest last, so walk backwards.
  $nodes = $xpath->query('//' . implode(' | //', $strip_whole));
  for ($i = $nodes->length - 1; $i >= 0; $i--) {
    $n = $nodes->item($i);
    if ($n->parentNode) { $n->parentNode->removeChild($n); }
  }

  // 2. Every remaining element: unwrap if not allowed, else clean its attributes.
  $all = array();
  foreach ($xpath->query('//*') as $el) { $all[] = $el; }
  for ($i = count($all) - 1; $i >= 0; $i--) {
    $el = $all[$i];
    if (!$el->parentNode || $el === $root) { continue; }
    $name = strtolower($el->nodeName);

    if (!in_array($name, $allowed_tags, true)) {
      while ($el->firstChild) {
        $el->parentNode->insertBefore($el->firstChild, $el);
      }
      $el->parentNode->removeChild($el);
      continue;
    }

    $attrs = array();
    foreach ($el->attributes as $a) { $attrs[] = $a->nodeName; }
    foreach ($attrs as $a) {
      $la = strtolower($a);
      if (!in_array($la, $allowed_attr, true)) {
        $el->removeAttribute($a);   // covers every on* handler
        continue;
      }
      $v = $el->getAttribute($a);
      if ($la === 'href') {
        // Strip control characters and whitespace before reading the scheme, so
        // "java&#9;script:" cannot slip past.
        $t = strtolower(preg_replace('/[\x00-\x20]/', '', $v));
        if (preg_match('/^([a-z][a-z0-9+.-]*):/', $t, $m)
            && !in_array($m[1], $ok_scheme, true)) {
          $el->removeAttribute($a);
        }
      }
      else if ($la === 'style') {
        if (preg_match('/expression|javascript|@import|behavior|url\s*\(/i', $v)) {
          $el->removeAttribute($a);
        }
      }
    }
  }

  // Serialise the container's children, never the container itself.
  $out = '';
  foreach ($root->childNodes as $child) { $out .= $doc->saveHTML($child); }

  return $out;
}//mgdb_safe_html

?>
