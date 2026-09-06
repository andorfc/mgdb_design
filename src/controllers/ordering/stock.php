<?PHP
  if (ID) {
    $desc = ID;
  } 
  else {
    $desc = getCGIParam('desc', 'G', '');
  }
 
   
  $stock = $mgdb->get('body')->load('templates/ordering/stock.bau');

  if (ID == "empty") {
    $flush = setcookie("stock_order"," ",(time() + 86400));
    $stock_order = "Your stock order is currently empty";
    $stock->get('remove-sections')->unmute();
    $stock->get('add-sections')->mute();
    $stock->get('link-sections')->mute();
  } 
  
  else if(ID == "submit") {
    $stock_order = $_COOKIE["stock_order"];
    $stock_order_comma_list = "";
    $tok1 = strtok($stock_order, "\n ");
    while($tok1)
    {
      $tok2 = substr($tok1,0,strpos($tok1,"+"));
      if(strlen($stock_order_comma_list) < 1)
        $stock_order_comma_list = $tok2;
      else
        $stock_order_comma_list = $stock_order_comma_list . "," . $tok2;
      $tok1 = strtok("\n");
    }

    /* Redirect AND stop. Without the exit the branch fell through to the
       template replacements below and controllers/ordering.php published the
       whole ordering page into the body of the 302 -- the visitor's own cart,
       built and thrown away on every order submission.

       The URL itself is deliberately untouched: it is a live order form at the
       Maize Genetics Cooperation Stock Center, and the id list is the format
       that endpoint expects. Encoding it is not a change that can be verified
       from here. */
    header("Location: https://maizecoop.cropsci.uiuc.edu/request/?id=" . $stock_order_comma_list);
    exit;
  }//submit
  
  else if(ID == "") {
    $stock->get('remove-sections')->mute();
    $stock->get('add-sections')->mute();
    $stock->get('empty-sections')->unmute();
    $stock->get('link-sections')->unmute();
    $stock_order = $_COOKIE["stock_order"];
  } 
  else {
    $stock->get('remove-sections')->mute();
    $stock->get('add-sections')->unmute();
    $stock->get('link-sections')->unmute();
    $stock->get('empty-sections')->mute();
    $stock_order = (isset($_COOKIE['stock_order'])) ? $_COOKIE['stock_order'] : '';
    $stock_order = $stock_order . "\n" . $desc;
    $flush = setcookie("stock_order", $stock_order, (time() + 86400));
  }

  $stock->get('stock_added')->replace($desc);
  $stock->get('stock_order')->replace(str_replace("\n", "<br>", $stock_order));
?>
