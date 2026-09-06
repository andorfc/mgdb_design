// file: OBO.js
//
// purpose: Javascript functions for onology annotation pages
//
// history:
//   Author: Scott Birkett
//   07/17/12  eksc  cleaned up and completed


// Shared variables because javascript is a monster out to get small children
var checks_in_progress = 0;
var return_values = [];


// Fill in all possible ontology fields given an id or name
function auto_fill() {
  var obo_term = document.getElementById('obo_term').value;
  var obo_name = document.getElementById('obo_name').value;
  
  if ((obo_term == '' && obo_name == '')
        || (obo_term == '' && obo_name.length < 3)) {
    return;
  }
  
//  var acc = obo_term.match(/\d+/);
  var acc = obo_term;
  
  if (obo_term == '' && obo_name != '') {
    get_matching_terms();
  }
  else {
    fetch_ontology_info(acc);
  }
  
  var sel = document.getElementById('ontology_type');
  if (obo_term.match(/^GO:/)) {
    for (i=0; i<sel.options.length; i++) {
      if (sel.options[i].text == 'GO') {
        sel.options[i].selected = true;
      }
      else {
        sel.options[i].selected = false;
      }
    }
  }
  else if (obo_term.match(/^PO:/)) {
    var sel = document.getElementById('ontology_type');
    for (op in sel.options) {
      if (op.text == 'PO') {
        op.selected = true;
      }
      else {
        op.selected = false;
      }
    }
  }
}//auto_fill


function cancelAndAdd() {
  document.getElementById('auto_num').value = '';
  document.getElementById('obo_term').value = '';
  document.getElementById('ontology_type').selectedIndex = 0;
  document.getElementById('evidence_code').selectedIndex = 0;
  document.getElementById('pmid').value = '';
  document.getElementById('authority').selectedIndex = 0;
  document.getElementById('comments').value = '';
  
  var theform = document.getElementById('annotation_form');
  theform.action = '/curation/OBO/edit';
  theform.submit();
}//cancelAndAdd


function check_submit_form(target) {
  var force = (document.getElementById('force_term').value == 'yes');
  
  var obo_term = document.getElementById('obo_term').value;
  if (obo_term == "" || obo_term == "GO:" || obo_term == "PO:") {
    alert("You must provide a Annotation ID");
    return;
  }
  if (evidence_code.selectedIndex == 0) {
    alert("You must set the evidence code");
    return;
  }
  
  return_values.length = 0;

  // Check GO ID validity
  if (!force) { 
    is_valid_ID(obo_term, "OBO"); 
  }
  
  // Check Pubmed ID validity
  pmid = document.getElementById("pmid").value;
  is_valid_ID(pmid, "PMID");

  // Check for MaizeGDB reference validity
  mgid = document.getElementById("reference").value;
  is_valid_ID(mgid, "MGDB_ID");

  // Check for authority
  sel_el = document.getElementById('authority').selectedIndex
  authid = document.getElementById('authority').options[sel_el].value;
  
  if (mgid == '' && pmid == '' && authid == '') {
    alert("You must provide a Pubmed ID, a MaizeGDB reference or an authority");
    return;
  }

  // Wait until checks are finished
  setTimeout('check_checks()', 100);
}//check_submit_form


// Wait until checks are finished
function check_checks() {
  if (checks_in_progress > 0) {
    setTimeout('check_checks()', 100);
  }
  else {
    if (return_values.length > 0) {
      for (var i = 0; i < return_values.length; i++ ) {
        alert("Your " + return_values[i] + " id is not valid");
      }
    }
    else {
      // Allow PHP to provide a new Target
      if (typeof target != 'undefined' && target != '') {
        annotation_form.target = target;
      }
  
      annotation_form.submit();
    }  
  }
}//check_checks()


function clearOntFields(called_from) {
  document.getElementById('obo_description').innerHTML = '';
  if (called_from == 'obo_term') {
    document.getElementById('obo_name').value = '';
  }
  else if (called_from == 'obo_name') {
    document.getElementById('obo_term').value = '';
  }
  else {
    document.getElementById('obo_term').value = '';
    document.getElementById('obo_name').value = '';
  }
  document.getElementById('obo_description').innerHTML = '';
  document.getElementById('ontology_domain_name').innerHTML = '';
}//clearOntFields


function closeDiv(divname) {
  var thisdiv = document.getElementById(divname);
  thisdiv.style.display = 'none';
}//closeDiv


function display_evidence_desc(ec_select, descs){
  var thisdiv = document.getElementById('EC_div');
  if (thisdiv.style.display == 'none') {
    thisdiv.style.display = 'block';
  }
  else {
    thisdiv.style.display = 'none';
  }
}//display_evidence_desc


function get_matching_terms() {
  var obo_name = document.getElementById('obo_name').value;
  var ontology_type_select = document.getElementById('ontology_type');
  var ontology_type_index = ontology_type_select.selectedIndex;
  var ontology_type = ontology_type_select.options[ontology_type_index].text;

  var url_base = "/record_data/curation/OBO_ajax.php";
  var url = url_base + "?inst=get_obo_terms&obo_name=" + obo_name;
  url += "&ontology_type=" + ontology_type;
  
  document.getElementById('search_status').innerHTML = "searching...";
  
  var xmlHttp;
  try {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e) {
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
    if (xmlHttp.readyState==4 && xmlHttp.status==200) {
      var response = xmlHttp.responseText;

      if (response == 0) {
        document.getElementById('search_status').innerHTML = "";
        return 0;//Can't actually return, but bleh
      }
      else {
        var data = eval("(" + response + ")");
        if ((typeof data.error) != 'undefined') {
          if (data.error == 'Bioportal not responding') {
            // timeout
            err = "ontology service timed out &#151; unable to search for terms";
            document.getElementById('search_status').innerHTML = err;
            document.getElementById('errordiv').innerHTML = "";
          }
          else if (data.error == 'No such term') {
            // incorrect term
            document.getElementById('search_status').innerHTML = "";
            document.getElementById('errordiv').innerHTML = data.error;
          }
          else {
            // unknown error
            document.getElementById('search_status').innerHTML = "";
            document.getElementById('errordiv').innerHTML = data.error;
          }
          document.getElementById('obo_name').value = '';
          document.getElementById('obo_description').innerHTML = '';
        }
        else {
          document.getElementById('search_status').innerHTML = "";
          document.getElementById('errordiv').innerHTML = "";
          
          var select_term_div = document.getElementById('select_term_div');
          document.getElementById('num_terms').innerHTML = data.num_results;
          var terms = document.getElementById('terms');
          terms.length = 0;
          for (i=0; i<data.terms.length; i++) {
            op = document.createElement('option');
            op.value = data.terms[i].term;
            op.innerHTML = data.terms[i].term + ' - ' 
                           + truncate(data.terms[i].name, 48);
            terms.appendChild(op);
          }
          select_term_div.style.display = 'block';
        }
        return 1;//Can't actually return, but again, bleh
      }
    }
    else {
      document.getElementById('errordiv').innerHTML = '';
      document.getElementById('search_status').innerHTML = "searching...";
    }
  }

   xmlHttp.open("GET", url, false);
   xmlHttp.send(null)
}//get_matching_terms


function fetch_ontology_info(acc) {
  var ontology_type_select = document.getElementById('ontology_type');
  var ontology_type_index = ontology_type_select.selectedIndex;
  var ontology_type = ontology_type_select.options[ontology_type_index].text;

  var url_base = "/record_data/curation/OBO_ajax.php";
  var url = url_base + "?inst=get_obo_info&obo_term=" + acc;
  url += "&ontology_type=" + ontology_type;
  
  document.getElementById('search_status').innerHTML = "searching...";
  
  var xmlHttp;
  try {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e) {
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
    if (xmlHttp.readyState==4 && xmlHttp.status==200) {
      var response = xmlHttp.responseText;

      if (response == 0) {
        document.getElementById('search_status').innerHTML = "";
        document.getElementById('errordiv').innerHTML = 'Not valid ontology term';
        document.getElementById('obo_name').value = '';
        document.getElementById('obo_description').innerHTML = '';
        document.getElementById('ontology_domain').innerHTML = '';
        return 0;//Can't actually return, but bleh
      }
      else {
        var data = eval("(" + response + ")");
        if ((typeof data.error) != 'undefined') {
          if (data.error == 'Bioportal not responding') {
            // timeout
            err = "ontology service timed out &#151; no term validation available";
            document.getElementById('search_status').innerHTML = err;
            document.getElementById('errordiv').innerHTML = "";
          }
          else if (data.error == 'No such term' 
                    || data.error == 'Term not found'
                    || data.errno == 404) {
            // incorrect term
            document.getElementById('search_status').innerHTML = "";
            document.getElementById('errordiv').innerHTML = data.error;
            var btn = document.getElementById('force_term_btn');
            btn.disabled = false;
            btn.style.display = 'inline';
          }
          else {
            // unknown error
            document.getElementById('search_status').innerHTML = "";
            document.getElementById('errordiv').innerHTML = data.error;
          }
          document.getElementById('obo_name').value = '';
          document.getElementById('obo_description').innerHTML = '';
          document.getElementById('ontology_domain').innerHTML = '';
        }
        else {
          document.getElementById('search_status').innerHTML = "";
          document.getElementById('errordiv').innerHTML = "";
          document.getElementById('obo_name').value = data.name;
          // textContent, not innerHTML: data.description and data.domain come from the
          // external OBO web service, and an ontology definition is plain text.
          document.getElementById('obo_description').textContent = data.description;
          document.getElementById('ontology_domain_name').textContent = data.domain;
        }
        return 1;//Can't actually return, but again, bleh
      }
    }
    else {
      document.getElementById('errordiv').innerHTML = '';
      document.getElementById('search_status').innerHTML = "searching...";
    }
  }

   xmlHttp.open("GET", url, false);
   xmlHttp.send(null)
}//fetch_ontology_info


// forceTerm()
//
// Permits user to force use of a term not validated by BioPortal
function forceTerm() {
  document.getElementById('force_term').value = 'yes';
  var name = prompt('Term name', 'unknown');
  document.getElementById('obo_name').value = name;
  var desc = prompt('Term description', 'none');
  document.getElementById('obo_description').innerHTML = desc;
}//forceTerm



// is_valid_ID()
//
// Function that performs a generic test for ID validity
function is_valid_ID(id, type) {
  if (id == '') {
    // empty id; don't check
    return;
  }
  
  var xmlHttp;

  try {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e) {
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
    if (xmlHttp.readyState==4 && xmlHttp.status==200) {
      var response = xmlHttp.responseText;
      if (response == 0) {
        // Invalid type
        return_values.push(type);
      }
      else {
        var data = eval("(" + response + ")");
        if ((typeof data.error) == 'undefined') {
          return_flag = true;
        }
        else {
          if (data.error == 'Bioportal not responding') {
            // timeout
            err = "ontology service timed out &#151; no term validation available";
            document.getElementById('search_status').innerHTML = err;
            document.getElementById('errordiv').innerHTML = "";
          }
          else if (data.error == 'No such term' 
                      || data.error == 'Term not found') {
            // incorrect term
            document.getElementById('search_status').innerHTML = "";
            document.getElementById('errordiv').innerHTML = data.error;
            var btn = document.getElementById('force_term_btn');
            btn.disabled = false;
            btn.style.display = 'inline';
          }
          else {
            // unknown error
            document.getElementById('search_status').innerHTML = "";
            document.getElementById('errordiv').innerHTML = data.error;
          }
          return_flag = false;
        }//response contained error message
      }//got a response
      checks_in_progress--;
    }
  }//function

  var url_base = "/record_data/curation/OBO_ajax.php";
  var url;
  if (type == 'OBO') {
    url = url_base + "?inst=check_obo_term&obo_term=" + id 
  }
  else if (type == 'PMID') {
    url = url_base + "?inst=check_pmid&pmid=" + id;
  }
  else if (type == 'MGID') {
    url = url_base + "?inst=check_mgdb_reference&mgid=" + id;
  }
  else {
    alert("is_valid_ID(): Unknown id type: [" + type + "]");
    return;
  }
  
  checks_in_progress++;
  xmlHttp.open("GET", url, false);
  xmlHttp.send(null)
}//is_valid_ID


function select_term() {
  var terms = document.getElementById('terms');
  var term = terms.options[terms.selectedIndex].value;
  document.getElementById('obo_term').value = term;
  
  var select_term_div = document.getElementById('select_term_div');
  select_term_div.style.display = 'none';
  
  auto_fill();
}//select_term


function show_help(helpdiv) {
  var thisdiv = document.getElementById(helpdiv);
  if (thisdiv.style.display == 'none') {
    thisdiv.style.display = 'block';
  }
  else {
    thisdiv.style.display = 'none';
  }
}//show_help


function show_term() {
  var terms = document.getElementById('terms');
  var term = terms.options[terms.selectedIndex].value;
  var ontology_type = document.getElementById('ontology_type');
  var ontology = ontology_type.options[ontology_type.selectedIndex].innerHTML;
  term = term.replace(':', '_');
  var url = 'http://purl.obolibrary.org/obo/' +ontology + '_' + term;
  window.open(url, '', 'height=1000,width=1000');
}//show_term


function truncate(str, maxlen) {
   var truncated = str;
   if (truncated.length > maxlen) {
      truncated = truncated.substr(0, maxlen-3) + "...";
   }
   return truncated;
}//truncate



