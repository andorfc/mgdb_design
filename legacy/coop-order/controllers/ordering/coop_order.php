<?php
/* file: coop_order.php
 *
 * purpose: a form for quickly accumulating a maize coop stock center order 
 *          ("request", per Marty)
 *
 * history:
 *  08/01/22  eksc  created
 */

include_once('./include/mail.php');

  $subdomain = '.maizegdb.org'; 
  
  $DBConn = connect_to_database();
  
  // Handle action, if any, and exit
  $action = getCGIParam('action', 'GP', false);
logMessage("Coop order action: $action");
  switch ($action) {
    case 'add-stock':
      if (!addStock(getCGIParam('stock_name', 'GP', false),
                    getCGIParam('stock_comment', 'GP', false))) {
        echo "ERROR";
      }
      else {
        echo "success";
      }
      exit;
    case 'check-country':
      checkCountry($DBConn);
      exit;
    case 'check-stock':
      checkStock($DBConn);
      exit;
    case 'clear-order':
      clearOrder();
      exit;
    case 'force-add-stock':
      if (!addStock(getCGIParam('stock_name', 'GP', false),
                   getCGIParam('stock_comment', 'GP', false), true)) {
        echo "ERROR";
      }
      else {
        echo "success";
      }
      exit;
    case 'get-comment':
      getStockComment(getCGIParam('stock_descriptive_name', 'GP', false));
      exit;
    case 'get-list':
      $orderstr = readOrder();
      echo $orderstr;
      exit;
    case 'remove-stock':
      removeStock(getCGIParam('stock_name', 'GP', false));
      exit;
    case 'submit':
      submitOrder();
      exit;
  }
  
  if (ID && ID == 'completed') {
    $tmpl = $mgdb->get('body')->load('templates/ordering/coop_order_completed.bau');
  }
   else {
    //// No specific action, proceed normally ////
  
    // If ID passed in, it's a stock name; add it to the stock_order 
    if (ID) {
      $desc = ID;
    } 
    else {
      $desc = getCGIParam('desc', 'G', false);
    }

    if ($desc and $desc != '') {
      $stock_comment = getCGIParam('stock_comment', 'GP', false);
      addStock($desc, $stock_comment);
    }

    $tmpl = $mgdb->get('body')->load('templates/ordering/coop_order.bau');
  
// Disable country checking until migration from Oracle is completed. (eksc 08/25/22)
//    $countries = getCountryList($DBConn);
//    $tmpl->get('coop_stock_order-countries')->loop($countries);
  }
  
  
  ///////////////////////////////////////////////////////////////////////////////////////
  
  function addStock($stock_name, $stock_comment, $force=false) {
    global $subdomain;
    $stock_name = decodeStockName($stock_name);
    $stock = $stock_name;
    if ($stock_comment && $stock_comment != '') {
      $stock .= "|||$stock_comment";
    }
    
    $stock_order = readOrder();
logMessage("Stock order: $stock_order");
    if ($stock_order == 'ERROR') {
      return false;
    }
    
    $update = false;
    if ($stock_order == '' || $stock_order == ' ' || $stock_order == '+') {
      $stock_order = $stock;
    }
    else {
      $stocks = explode(':::', $stock_order);

      // Check if stock already listed
      for ($i=0; $i<count($stocks); $i++) {
        $parts = explode('|||', $stocks[$i]);
        if ($parts[0] == $stock_name) {
          $update = true;
          $stock = $parts[0];
          if ($stock_comment && $stock_comment != '') {
            $stock .= "|||$stock_comment";
          }
          $stocks[$i] = $stock;
        }
        else {
        }
      }//each stock
      
      if (!$update) {
        $stock_order .= ':::' . $stock_name;
      }
      else {
        $stock_order = implode(':::', $stocks);
      }
    }

    $success = writeOrder($stock_order);
    return $success;
  }//addStock
  
  
  function checkCountry($DBConn) {
    $country = getCGIParam('country', 'GP', false);
    if (!$country or $country == '') {
      echo '';
    }
    else if ($countries = findCountry($country, $DBConn)) {
      if (count($countries) > 1) {
        $countries = array_column($countries, 'description');
        echo 'Multiple: ' . implode(',', $countries);
      }
      else {
        echo $countries[0]['country'];
      }
    }
    else {
      echo '';
    }
  }//checkCountry
  
  
  function checkStock($DBConn) {
    $stock_name = getCGIParam('stock_name', 'GP', false);
    $stock_name = pg_escape_string($stock_name);
logMessage("Check stock '$stock_name'");
    if (!$stock_name or $stock_name == '') {
      echo "No stock name.";
    }
    else if ($stocks = findStock($stock_name, $DBConn)) {
      if (count($stocks) > 1) {
        $stock_names = array_column($stocks, 'description');
        echo 'Multiple: ' . implode('||', $stock_names);
      }
      else if ($stocks[0]['curation_lvl'] > 0) {
        echo "Stock is not available.";
      }
      else {
        echo $stocks[0]['description'];
      }
    }
    else {
      echo "Stock not found.";
    }
  }//checkStock
  
  
  function clearOrder() {
    global $subdomain;
    writeOrder('');
  }//clearOrder
  
  
  function decodeStockName($stock_name) {
    $stock_name = urldecode($stock_name);
    return $stock_name;
  }//decodeStockName
  
  
  function findCountry($country, $DBConn) {
    $lc_country = strtolower($country);
    $uc_country = strtoupper($country);
    $sql = "
      SELECT country FROM mgdb.country 
      WHERE LOWER(country)='$lc_country' 
            OR '$country'=ANY (variations) OR '$uc_country'=ANY (variations)";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    
    return $rows;
  }//findCountry
  
  
  function findStock($stock_name, $DBConn) {
    $stock_name = strtolower($stock_name);
    $sql = "
      SELECT description, idn.curation_lvl FROM mgdb.stock s
        INNER JOIN mgdb.id_num idn ON idn.id=s.id
        INNER JOIN mgdb.description d ON d.id=s.id
      WHERE (LOWER(name) = '$stock_name' OR LOWER(description) = '$stock_name')
            AND available_from=(SELECT id FROM mgdb.person 
                                WHERE name='Maize Genetics Cooperation - Stock Center')";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    
    if (count($rows) == 1) {
      return $rows;
    }
    else {
      $sql = "
        SELECT description, idn.curation_lvl FROM mgdb.stock s
          INNER JOIN mgdb.id_num idn ON idn.id=s.id
          INNER JOIN mgdb.description d ON d.id=s.id
        WHERE idn.curation_lvl=0
              AND (LOWER(name) LIKE '$stock_name%' OR LOWER(description) LIKE '$stock_name%')
              AND available_from=(SELECT id FROM mgdb.person 
                                  WHERE name='Maize Genetics Cooperation - Stock Center')
        ORDER BY name";
      $sth = make_query($DBConn, $sql);
      $rows = get_all_rows($sth);
      if (count($rows) > 0) {
        return $rows;
      }
      else {
        $sql = "
          SELECT description, idn.curation_lvl FROM mgdb.stock s
            INNER JOIN mgdb.id_num idn ON idn.id=s.id
            INNER JOIN mgdb.description d ON d.id=s.id
          WHERE idn.curation_lvl=0
                AND (LOWER(name) LIKE '%$stock_name%' OR LOWER(description) LIKE '%$stock_name%')
                AND available_from=(SELECT id FROM mgdb.person 
                                    WHERE name='Maize Genetics Cooperation - Stock Center')
          ORDER BY name";
        $sth = make_query($DBConn, $sql);
        return get_all_rows($sth);
      }
      
      return array();
    }
  }//findStock
  
  
  function getCountryList($DBConn) {
    $sql = "SELECT country FROM mgdb.country";
    $sth = make_query($DBConn, $sql);
    return get_all_rows($sth);
  }//getCountryList
  
  
  function getStockComment($descriptive_name) {
    global $subdomain;
    $stock_order = readOrder();
    $stocks = explode(':::', $stock_order);
    for ($i=0; $i<count($stocks); $i++) {
      $parts = explode('|||', $stocks[$i]);
      if (count($parts) > 1 && $parts[0] == $descriptive_name) {
        echo $parts[1];
      }
    }
    
    echo '';
  }//getStockComment
  

  function readOrder() {  
    global $system;
    
    $stock_order_id = (isset($_COOKIE['stock_order_id'])) ? $_COOKIE['stock_order_id'] : '';

    if (!$stock_order_id || $stock_order_id == '') {
      return '';
    }
    $orderfile = $system['temp_dir'] . "/$stock_order_id.order";
logMessage("Order file: $orderfile");
    $orderstr = file_get_contents($orderfile);
    if (strstr($orderstr, "Warning")) {
      logMessage("Unable to open file: $orderstr");
      return 'ERROR';
    }
    
    return $orderstr;
  }//readOrder
  
  
  function removeStock($stock_name) {
    global $subdomain;
    
    $stock_order = readOrder($stock_order_id);
    if ($stock_order == 'ERROR') {
      // handle this?
    }
    
    $stocks = explode(':::', $stock_order);
    for ($i=0; $i<count($stocks); $i++) {
      $parts = explode('|||', $stocks[$i]);
      if ($parts[0] == $stock_name) {
        array_splice($stocks, $i, 1);
        break;
      }
    }
    $stock_order = implode(':::', $stocks);
logMessage("New stock order after removing one:\n$stock_order");
    writeOrder($stock_order);
  }//removeStock
  
  
  function submitOrder() {
logMessage("Submit stock request.");
    // Construct e-mail
    $stock_order = getCGIParam('stock_order', 'GP', false);
    $name = getCGIParam('name', 'GP', false);
    $phone = getCGIParam('phone', 'GP', false);
    $address = getCGIParam('address', 'GP', false);
    $country = getCGIParam('country', 'GP', false);
    $email = getCGIParam('email', 'GP', false);
    $genome = getCGIParam('genome', 'GP', false);
    $instructions = getCGIParam('instructions', 'GP', false);
    
    if (!$stock_order || !$name || !$address || !$country || !$email) {
      echo "Missing information";
    }
    else {
      $stock_list = str_replace(':::', "\n", $stock_order);
      $message = "Stock order\n";
      $message .= "$stock_list\n\n";
      $message .= "Shipping information\n";
      $message .= "Name: $name\nAddress: $address\nCountry: $country\nPhone: $phone\nE-mail: $email";
      if ($genome == 'Y') {
        $message .= "Stock will be used in a genome sequencing project.";
        $genome = True;
      }
      else {
        $genome = False;
      }
      $message .= "\n\n$instructions";

/* Don't do this (prone to pranking)
      // Send confirmation e-mail
      $subject = "Stock center order confirmation";
      $message = "Thank you for your order. A copy of your order is below.\n\n" . $text;
      $html = str_replace("\n", "<br>", $message);
      send_email($email, 'admin@maizegdb.org', $subject, $html);
*/
      
      // E-mail order to stock center
      $subject = "STOCK REQUEST";
      $message = "A request has been made via MaizeGDB.\n\n" . $message;

      // Destination e-mail  <-----------
      //$email_dest = 'ekcannon@iastate.edu';
      $email_dest = 'maize@uiuc.edu';
      
      $data = array(
        'sender' => $email, 
        'subject' => $subject, 
        'email_dest' => $email_dest, 
        'password' => 'supersecret', 
        'message' => nl2br(htmlentities($message)),
      );
      $options = array(
        'http' => array(
          'header'  => "Content-type: text/html\r\n",
          'method'  => 'POST',
          'content' => http_build_query($data)
        ),
      );
      $context  = stream_context_create($options);
      $url = "https://mailhub.maizegdb.org/cgi-bin/mail_sender.cgi";
      $result = file_get_contents($url, false, $context);
      if ($result === FALSE) { 
        reportError("Attempt to send e-mail may have failed!");
      }
      _writeToMailLog("Send email:\nret: $result\nDetails:\n" . print_r($data, true) . "\n\n");

      // Also send order to database - full host name required (not sure why)
      //$url = 'https://curation2.maizegdb.org/wsgi/mgcsc/request/submit';
      //$url = 'http://curation-tools-dev.usda.iastate.edu/wsgi/mgcsc/request/submit';
      $url = 'http://mgdb-curation-tools2.usda.iastate.edu/wsgi/mgcsc/request/submit';
/* Some day, figure out how to make this work
      $header = array(
        'Authorization: OAuth SomeHugeOAuthaccess_tokenThatIReceivedAsAString'
      );
*/
      $data = array(
        'user'           => 'maizegdb',
        'stock_order'    => $stock_order,
        'requester_name' => $name,
        'phone'          => $phone,
        'address'        => $address,
        'country'        => $country,
        'email'          => $email,
        'genome_project_request' => $genome,
        'instructions'   => $instructions);
logVarDump($data, "POST this data to $url:\n");
      $curl = curl_init();
//      curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
      curl_setopt($curl, CURLOPT_URL, $url);
      curl_setopt($curl, CURLOPT_POST, true);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
      $resp = curl_exec($curl);
logVarDump($resp, "Request submission response:\n");
      curl_close($curl);

      // All done
//      clearOrder();
logMessage("Curl error: " . curl_error($curl));
      curl_close($curl);

      // All done
      clearOrder();
    }
  }//submitOrder
  
  
  function writeOrder($stock_order) {
    global $system;
    
    $stock_order_id = (isset($_COOKIE['stock_order_id'])) ? $_COOKIE['stock_order_id'] : '';
logMessage("writeOrder(): stock_order_id = $stock_order_id");
    if ($stock_order_id == '' || $stock_order_id == ' ') {
      $stock_order_id = uniqid(strval(rand(0, 1000)).'-', true);
      $flush = setrawcookie('stock_order_id', rawurlencode($stock_order_id), 0, '/', $subdomain);
    }

    $orderfile = $system['temp_dir'] . "/$stock_order_id.order";
logMessage("writeOrder(): order file: $orderfile");
    
    $fh = fopen($orderfile, 'w');
    if (!$fh) {
      return false;
    }
    
    fwrite($fh, "$stock_order");
    fclose($fh);
    
    return true;
  }//writeOrder
?>