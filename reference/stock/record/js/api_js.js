/* file: api_js.js
 *
 * purpose: handle Ajax requests to display various pages
 *
 *
 * history:
 *  06/29/12  jportwood - added the getDataParameters function
 *  01/21/13  jportwood - added toggle_references function (used commonly in the data centers)
 *  05/27/14  candorf - added check to see if data_center_type was defined - this was causing a bug in the gene/gene model page
 *  01/12/16  bbraun - modified toggleItem to not require toggle image
 */

/** jp
 * Add data to cache for a given key
 */
function addCacheData(data, key) {
  var cache_url = "/record_data/cache_data.php";
  var post_data = "key="+encodeURIComponent(key)+"&data="+encodeURIComponent(data);
  $.ajax({
    url: cache_url,
    cache: true,
    type: "POST",
    global: false,
    data: post_data
  });
}


function alert_test() {
  alert("Test0");
}


var set_cache = true; //on page load set the cache by default
function checkAction(checkID, formname, centername, label) {
  if (!formname) {
    formname = 'nav_form';
  }
  if (!centername) {
    centername = data_center_type;
  }
  if (!label) {
    label = centername;
  }

  // NOTE: data_center_type and data_center_id are set in the corresponding .bau files
  //       for each data center.
  var checkBoxId = 'checkbox' + checkID;
  if(document.forms[formname][checkBoxId].checked)
  {
    set_cache = false; //dont allow cache contents to be overwritten
    getData(centername, checkID, data_center_id);
    document.cookie = centername + "_" + checkID + "_checked=true";
  } else {
	if(typeof data_center_type != 'undefined')
	{
		document.getElementById(checkID).innerHTML = "<br><center>This section has been turned off. To turn this section on click the <b>" + label + "</b> checkbox on the navigation menu. <br><br>Click <a style='color:blue;' onClick='reCheckAction(data_center_type,\"" + checkID + "\", data_center_id);'>here</a> to load this section.</center><br>";
    } else {
		document.getElementById(checkID).innerHTML = "<br><center>This section has been turned off. To turn this section on click the <b>" + label + "</b> checkbox on the navigation menu.</center><br>";
	}
	document.cookie = centername + "_" + checkID + "_checked=false";
  }
}//checkAction()


function reCheckAction(source_name, div_name, id_val) {
  var checkBoxId = 'checkbox' + div_name;
  getData(source_name,div_name, id_val);
  document.forms["nav_form"][checkBoxId].checked = true
  document.cookie = data_center_type + "_" + div_name + "_checked=true";
}//checkAction()


//Get cookie routine
function get_cookie(Name) {
  var search = Name + "=";
  var returnvalue = "";
  if (document.cookie.length > 0) {
    offset = document.cookie.indexOf(search)
    // if cookie exists
    if (offset != -1) {
      offset += search.length
      // set index of beginning of value
      end = document.cookie.indexOf(";", offset);
      // set index of end of cookie value
      if (end == -1) end = document.cookie.length;
      returnvalue=unescape(document.cookie.substring(offset, end))
      }
   }
  return returnvalue;
}//get_cookie()


function getCounts(source_name, div_name, type, id_val) {
  var url = "/tools/counts/getCount.php?id=" + id_val + "&source=" + source_name + "&type=" + type;
  $.ajax({
    url: url,
    cache: true,
    beforeSend: function() {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  })
    .done(function(data) {
      $('#'+div_name).html(data);
    });
}


/**
 * Load a page with the 'id' and 'type' parameters
 * jp 08/10/15 - modified to check if the section has been cached first
 * jp 07/22/16 - domain sharding hack
 *
 *   Browsers limit the amount of parallel TCP connections to a single domain, but we can get around
 *   this by spreading the requests across multiple subdomains. This significantly improves page loads
 *   of the GM pages on the production server. However, domain sharding may not be necessary once
 *   all of our requests are served through HTTP/2, which suposedly allows unlimited parallel
 *   connections. 
 *
 *   ajax_num -- specifies the current subdomain to send the request
 *   max_ajax -- maximum # of ajax domains (7 is sufficient for 23 parallel ajax connections on GM pages).
 *               If more than 7 are needed, then additional subdomains will need to be added to the DNS
 *               on CloudFlare.
 */
var c = 0;
var ajax_num = 0;
var max_ajax = 7; 
function getData(source_name, div_name, id_val, func) {
  
  var url = (useCrossdomain())  
          ? "https://ajax"+ajax_num+".maizegdb.org/record_data/" + source_name + "_data.php" + "?id=" + id_val + "&type=" + div_name
          : "/record_data/" + source_name + "_data.php" + "?id=" + id_val + "&type=" + div_name;

  var key = source_name + "_" + div_name + "_" + id_val;
  ajax_num = ++ajax_num % max_ajax;
  func = typeof func !== 'undefined' ? func : null; // optional parameter
  if (!source_name) { //a null source_name means the page was cached, so don't call the ajax
    return;
  }
  $.ajax({
    url: url,
    error : function(xhr, status, error) {
        getDataRetry(source_name, div_name, id_val, func);
    },
    cache: true,
    beforeSend: function() {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  })

  .fail(function() {
    getDataRetry(source_name, div_name, id_val, func);
  })

  .done(function(data) {
    $('#'+div_name).html(data);
    if (func != null) {
      eval(func);
    }
  });

  $(document).ready(function() {
    $(document).ajaxStop(function() {
      if (c == 0) {
        var data = document.documentElement.outerHTML;
        var docHref = document.location.href.split('#');
        c++;
        if (set_cache == true) {
          addCacheData(data.trim(), docHref[0]);
        }
      }
    });

  });

}


function resetForm(formname) {
  frm = document.getElementById(formname);
  frm.reset();
}//resetForm


/** 
 * Check browser and subdomain environments to see if queries should use the ajax cross domain
 */
function useCrossdomain() {
  var userAgent = navigator.userAgent;
  if (userAgent.indexOf("Mac OS X 10_9_5") !== -1 && userAgent.indexOf("Version/9.1.3 Safari") !== -1) {
     return false;
  }
  var subdomain = (window.location.host.split("."))[0];
  if (subdomain == "www" || subdomain == "chinese" || subdomain == "maizegdb") {
    return true;
  }
  return false;
}

/** jp
 * When all ajax queries are finished, cache page html
 */


function getDataRetry(source_name, div_name, id_val, func) {
  func = typeof func !== 'undefined' ? func : null; // optional parameter
  var url = "/record_data/" + source_name + "_data.php" + "?id=" + id_val + "&type=" + div_name;
  $.ajax({
    url: url,
    cache: true,
    beforeSend: function() {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  })
    .done(function(data) {
      $('#'+div_name).html(data);
      if (func != null) {
        eval(func);
      }
    });
}


/** andorfc
 * Returns message if menu item is not turned on.
 */
function getBlank(section_name, div_name) {
  var checkBoxId = 'checkbox' + div_name;
	if(typeof data_center_type != 'undefined')
		{
			document.getElementById(div_name).innerHTML = "<div align='center'><br><center>This section has been turned off.  To turn this section on click the <b>" + section_name + "</b> checkbox on the navigation menu. <br><br>Click <a style='color:blue;' onClick='javascript:reCheckAction(data_center_type,\"" + div_name + "\", data_center_id);'>here</a> to load this section.</center></div>";
            set_cache = false;
		} else {
			document.getElementById(div_name).innerHTML = "<div align='center'><br><center>This section has been turned off.  To turn this section on click the <b>" + section_name + "</b> checkbox on the navigation menu. <br><br></center></div>";
            set_cache = false;
		}
}


/** jportwood
 * Load a page with additional parameters defined in arrParams.
 * @arrParams - an associative array with each key as the param name
 *
 * Use getData() if the only params are 'id' and 'type'
 */
function getDataParameters(source_name, div_name, id_val, arrParams) {
  var xmlHttp;
  try {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e) {
    // Internet Explorer
    try {
      xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
    }
    catch (e) {
      try {
        xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
      catch (e) {
        alert("Your browser does not support AJAX!");
        return false;
      }
    }
  }

  xmlHttp.onreadystatechange=function() {
    if (xmlHttp.readyState==4)
      document.getElementById(div_name).innerHTML =  xmlHttp.responseText;

    if (xmlHttp.readyState==0 || xmlHttp.readyState==1 || xmlHttp.readyState==2 || xmlHttp.readyState==3)
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
  }

  var txtStr01 = "/record_data/" + source_name + "_data.php" + "?id=" + id_val + "&type=" + div_name;

  //Add the additional parameters
  for(key in arrParams) {
    txtStr01 = txtStr01 + "&" + key + "=" + arrParams[key];
  }
  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}//getDataParameters


function getData_cluetip(source_name, div_name, id_val) {
  var xmlHttp;
  try {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e) {
    // Internet Explorer
    try {
      xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
    }
    catch (e) {
      try {
        xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
      catch (e) {
        alert("Your browser does not support AJAX!");
        return false;
      }
    }
  }

  xmlHttp.onreadystatechange=function() {
    if(xmlHttp.readyState==4) {
      document.getElementById(div_name).innerHTML = xmlHttp.responseText;
      var count = 0;
      var name_prefix1 = source_name + "_" + div_name + "_";
      var name_prefix2 = "#" + source_name + "_" + div_name + "_";
      var name1 = name_prefix1 + count;
      var name2 = name_prefix2 + count;

      while(document.getElementById(name1)) {
        $(name2).cluetip({activation: 'click', sticky: true, width: 450, closePosition: 'title'});
        count++;
        name1 = name_prefix1 + count;
        name2 = name_prefix2 + count;
      }
    }
    if (xmlHttp.readyState==0) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
    if(xmlHttp.readyState==1) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
    if (xmlHttp.readyState==2) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
    if(xmlHttp.readyState==3) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  }

  var txtStr01 = "/record_data/" + source_name + "_data.php" + "?id=" + id_val + "&type=" + div_name;

  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}//getData_cluetip


function getClassical(div_name, id_val) {
  var xmlHttp;
  try {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e) {
    // Internet Explorer
    try {
      xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
    }
    catch (e) {
      try {
        xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
      catch (e) {
        alert("Your browser does not support AJAX!");
        return false;
      }
    }
  }

  xmlHttp.onreadystatechange=function() {
    if (xmlHttp.readyState==4) {
     document.getElementById(div_name).innerHTML =  xmlHttp.responseText;
    }
    if(xmlHttp.readyState==0) {
      document.getElementById(div_name).innerHTML = "<div align='center'><br>Searching classical genes...</div>";
    }
    if(xmlHttp.readyState==1) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
    if(xmlHttp.readyState==2) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
    if(xmlHttp.readyState==3) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  }

  var txtStr01 = "/tools/ajax/getClassicalGene.php?name=" + id_val;

  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}


function getGeneReview(div_name, id_val) {
  var xmlHttp;
  try {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e) {
    // Internet Explorer
    try {
      xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
    }
    catch (e) {
      try {
        xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
      catch (e) {
        alert("Your browser does not support AJAX!");
        return false;
      }
    }
  }

  xmlHttp.onreadystatechange=function() {
    if (xmlHttp.readyState==4) {
     document.getElementById(div_name).innerHTML =  xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById(div_name).innerHTML = "<div align='center'><br>Searching classical genes...</div>";
    }
    if (xmlHttp.readyState==1) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
    if (xmlHttp.readyState==2) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
    if (xmlHttp.readyState==3) {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  }

  var txtStr01 = "/tools/ajax/getGeneReview.php?name=" + id_val;
  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}//getClassical


/* This appears to be unused.
function getCalendar(div_name, id_val, type) {
  var url = "/tools/ajax/getCalendar.php?id=" + id_val + "&type=" + type;
  $.ajax({
    url: url,
    cache: true,
    beforeSend: function() {
      document.getElementById(div_name).innerHTML = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";
    }
  })
    .done(function(data) {
      $('#'+div_name).html(data);
    });

}//getCalendar
*/

function recordAccess(item, detail, redirect) {
  var data = {item: item, detail: detail};
  $.post('/tools/recordAccess.php', data)
    .fail(function(data) {
       console.log('recordAccess.php failed');
    })
    .done(function(data) {
        if (redirect) {
          document.location = redirect;
        }
    });
}//recordAccess


function toggleItem(dom_id) {
  var toggleElement = document.getElementById(dom_id);
  var cur_status = toggleElement.style.display;
  var img_id = dom_id + "_img";
  var img = document.getElementById(img_id);

  if(cur_status == "none") {
    toggleElement.style.display = "block";
    if (img) img.src = "/images/collapse.png";
  }
  else {
    toggleElement.style.display = "none";
    if (img) img.src = "/images/expand.png";
  }
}//toggleItem


function popupWindow(url, name ,x, y) {
  newwindow = window.open(url, name, 'toolbar=no,location=no,resizeable=yes,scrollbars=yes,height='+y+',width='+x);
  newwindow.focus();
}//popup_window

/**
 *  9/8/14 jp - modified to display annotation form in shadowbox instead of pop-up window
 */
function popUpAnnotation(form_id, url) {
  var get_data = "";
  var inputs = document.getElementById(form_id).getElementsByTagName("input");
  for (var i=0; i<inputs.length; i++) {
    get_data += (i == 0) ? "?" : "&";
    get_data += inputs[i].id + "=" + inputs[i].value;
  }
  url += get_data;
  disable_megamenu();
  Shadowbox.init({
    skipSetup: true,
    onClose: function () {enable_megamenu()}
  });

  Shadowbox.open({
    content: url,
    player: 'iframe',
    height: 680,
    width: 800
  });
}//popUpAnnotation


function popUpFeedback(sendto, subject, instructions) {
  if (!window.focus) return true;
  url = "/feedback?sendto="+sendto+"&subject="+subject+"&instructions="+instructions;
  var popup = window.open(url, 'feedback', 'height=310,width=720,scrollbars=yes');
  popup.focus();
}//popUpFeedback()


function popUpHelp(section, anchor) {
  if (!window.focus) return true;
  url = "/help/" + section + '#' + anchor;
  parms = 'height=200,width=440,scrollbars=yes,'
         + 'top=' + mouse_y + ',left=' + mouse_x;
  var popup = window.open(url, 'help', parms);
  popup.focus();
}//popUpHelp()


//document.onmousedown = getMousePosition;
var mouse_x, mouse_y;
function getMousePosition(e) {
  var _x;
  var _y;
  var isIE = document.all ? true : false;
  if (!isIE) {
    _x = e.pageX;
    _y = e.pageY;
  }
  else {
    _x = event.clientX + document.body.scrollLeft;
    _y = event.clientY + document.body.scrollTop;
  }
  mouse_x = _x;
  mouse_y = _y;
  return true;
}//getMousePosition()


function verifyFeedback() {
  if (document.getElementById('email').value == '') {
    alert("You did not provide an e-mail address. We will need this to respond to your message.");
    return false;
  }
  if (document.getElementById('message').value == '') {
    alert("You did not include a message.");
    return false;
  }
  if (document.getElementById('turingtest').value != '18') {
    alert("You did not answer the question correctly. Please enter the answer to the question in digits.");
    return false;
  }
  document.getElementById('feedbackform').submit();
}//verifyFeedback()

/**
  * Refreshes display of the reference data
  * (DEPRECATED) -- Use toggle_section when you want to toggle the display of only two sections
  */
function toggle_references(display) {
  if (display == "show") {
    document.getElementById("show_ref").style.display = "block";
    document.getElementById("hide_ref").style.display = "none";
  }
  else {
    document.getElementById("show_ref").style.display = "none";
    document.getElementById("hide_ref").style.display = "block";
  }
}

/**
* Use when you want to toggle the display between two sections
* The names of each section should be "(name)_1" and "(name)_2"
*/
function toggle_section(sec) {
  if (document.getElementById(sec+"_1").style.display == "none") {
    document.getElementById(sec+"_1").style.display = "block";
    document.getElementById(sec+"_2").style.display = "none";
  }
  else {
    document.getElementById(sec+"_1").style.display = "none";
    document.getElementById(sec+"_2").style.display = "block";
  }
}

/**
*  Opens a shadowbox window.
*  When opening from inside an iframe (as is the case with images in a jCarousel)
*  pass in any value as use_parent, otherwise pass in only 2 parameters.
*  Two parameter functionality is preserved for backwards compatibility.
*/
function open_sb(url, title, use_parent) {
  var img = new Image();
  var sbx;
  if (typeof(use_parent) === 'undefined') {
    sbx = Shadowbox;
    disable_megamenu();
  }
  else {
   sbx = window.parent.Shadowbox;
   disable_megamenu(true);
  }

  img.src = url;
  sbx.init({
    skipSetup: true,
    onClose: function () {enable_megamenu()}
  });

  img.onload = function () {
    sbx.open({
      content: url,
      player: 'img',
      height: img.height,
      width: img.width,
     title: title
    });
  }
}

/**
*  Opens a shadowbox window with content. 
*
* Content can be HTML or a URL
*/
function open_sb_basic(content, title, width, height, player) {    
  var sbx = Shadowbox;
  disable_megamenu();
  sbx.init({
    skipSetup: true,
    onClose: function () {enable_megamenu()}
  });
  sbx.open({
    content: content,
    title: title,
    player: player,
    height: height,
    width: width
  });
}

/**
 * Disable the megamenu (useful for when a shadowbox is open)
 */
var doc = document;
var menu_class = window.location.pathname.indexOf("gbrowse") !== -1 ? "menu_gbrowse" : "menu";
function disable_megamenu(use_parent) {

 if(typeof(use_parent) === 'undefined')
   use_parent = false;

 if (use_parent)
   doc = parent.document;
 
  $("#menu_bar", doc).removeClass(menu_class);
  $("#menu_bar", doc).addClass("menu_nohover");
  doc.getElementById("tool_menu").style.display = "none";
  doc.getElementById("community_menu").style.display = "none";
  doc.getElementById("data_center_menu").style.display = "none";
  doc.getElementById("genomes_menu").style.display = "none";
  doc.getElementById("about_menu").style.display = "none";

}


function enable_megamenu() {
  $("#menu_bar", doc).removeClass("menu_nohover");
  $("#menu_bar", doc).addClass(menu_class);
  doc.getElementById("tool_menu").style.display = "block";
  doc.getElementById("community_menu").style.display = "block";
  doc.getElementById("data_center_menu").style.display = "block";
  doc.getElementById("genomes_menu").style.display = "block";
  doc.getElementById("about_menu").style.display = "block";
}


// Toggles tabs in cases where the tabs are included with the div being toggled.
//  (That is, each div contains its own copy of the tabs)
function toggle_tab(val) {
  $('.gbdiv').each(function(k, el) {
    if (el.id == val) {
      $('#'+el.id).show();
    }
    else {
      $('#'+el.id).hide();
    }
  });
}//toggle_tab


// Toggles tabs in cases where the tabs are included with the div being toggled.
//  (That is, each div contains its own copy of the tabs)
function toggle_tab(val, cssClass) {
  $('.'+cssClass).each(function(k, el) {
    if (el.id == val) {
      //$('#'+el.id).show();
      $('#'+el.id).css("display", "inline");
    }
    else {
      //$('#'+el.id).hide();
      $('#'+el.id).css("display", "none");
    }
  });
}//toggle_tab



