

/**
* Performs an advanced search
*/
function traits_ibm_nam_adv_search(div_name, pagenum)
{  
  var arrSearchOpt = getSearchOpts();
  getAdvSearch("traits_ibm_nam", div_name, arrSearchOpt, pagenum);
}

function traits_ibm_nam_dl() {
  document.getElementById("download").value = "true";
  document.getElementById("traits_ibm_nam_form").action = "/search/traits_ibm_nam/traits_ibm_nam_adv_results.php";
  document.getElementById("traits_ibm_nam_form").submit();
  document.getElementById("traits_ibm_nam_form").action = "javascript:traits_ibm_nam_adv_search('adv_results'\);";
}

function getSearchOpts() {
  var arrSearchOpt = new Array();
  
  if(document.getElementById("stock").value == "") 
    document.getElementById("box_stock").checked = false;

  //Add all checkbox params to array
  arrSearchOpt["box_name"] = document.getElementById("box_name").checked;
  arrSearchOpt["box_po"] = document.getElementById("box_po").checked;
  arrSearchOpt["box_stock"] = document.getElementById("box_stock").checked;
  arrSearchOpt["box_env"] = document.getElementById("box_env").checked;
  arrSearchOpt["box_ref"] = document.getElementById("box_ref").checked;

  
  //Add all select values to array
  arrSearchOpt["trait_name"] = document.getElementById("trait_name").value;  
  arrSearchOpt["po_name"] = document.getElementById("po_name").value; 
  arrSearchOpt["reference"] = document.getElementById("reference").value;
 
  arrSearchOpt["stock"] = document.getElementById("stock").value; 
  
  arrSearchOpt["env"] = document.getElementById("env").value; 

  if (document.getElementById('adv_limit').checked)
    arrSearchOpt["adv_limit_val"] = document.getElementById("adv_limit_val").value; 
  else
    arrSearchOpt["adv_limit_val"] = 5000; //Any more than 5,000 results returned can cause the user's monitor to collapse into a black hole...
    
  return arrSearchOpt;
}

  