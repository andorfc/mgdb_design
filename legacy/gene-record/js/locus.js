// file: locus.js
//
// purpose: support for locus data center

/* locus sequence no long supported
function downloadLociFasta(rowdata) {
  var rowdata_el = $('#'+rowdata);
  var rowdata = rowdata_el.val();

  var status = $('#downloadstatus');
  status.html("download started");
  
  var frm = document.createElement("form");
  frm.setAttribute('method', 'post');
  frm.setAttribute('action', '/search/locus/locus_fasta_results.php');
  
  var rows = document.createElement('input');
  rows.setAttribute('type', 'hidden');
  rows.setAttribute('name', 'rows');
  rows.setAttribute('value', rowdata);
  frm.appendChild(rows);

  document.getElementsByTagName('body')[0].appendChild(frm);
  frm.submit();
}//downloadLociFasta
*/


/**
* Performs an advanced search
*/
function locus_adv_search(div_name, pagenum)
{  
  try {
      var arrSearchOpt = {};
      if(document.getElementById("locus_name").value == "") 
        document.getElementById("box_name").checked = false;

      //Add all checkbox params to array
      arrSearchOpt["box_name"] = document.getElementById("box_name").checked;
      arrSearchOpt["box_type"] = document.getElementById("box_type").checked;
      arrSearchOpt["box_lg"] = document.getElementById("box_lg").checked;
      arrSearchOpt["box_detect"] = document.getElementById("box_detect").checked;
      arrSearchOpt["box_probe"] = document.getElementById("box_probe").checked;
      arrSearchOpt["box_map"] = document.getElementById("box_map").checked;
      arrSearchOpt["box_map2"] = document.getElementById("box_map2").checked;
      arrSearchOpt["box_map3"] = document.getElementById("box_map3").checked;
      arrSearchOpt["box_exp"] = document.getElementById("box_exp").checked;
      arrSearchOpt["box_gp"] = document.getElementById("box_gp").checked;
      arrSearchOpt["box_prop"] = document.getElementById("box_prop").checked;
      arrSearchOpt["box_pheno"] = document.getElementById("box_pheno").checked;
/* no longer supported
      arrSearchOpt["box_sequences"] = document.getElementById("box_sequences").checked;
*/
      arrSearchOpt["box_gel"] = document.getElementById("box_gel").checked;
      
      //Add all select values to array
      arrSearchOpt["name"] = document.getElementById("locus_name").value; 
      arrSearchOpt["type"] = document.getElementById("type").value; 
      arrSearchOpt["linkage_group"] = document.getElementById("linkage_group").value; 
      arrSearchOpt["detect"] = document.getElementById("detect").value; 
      arrSearchOpt["probe"] = document.getElementById("probe").value; 
      
      arrSearchOpt["mapname"] = document.getElementById("mapname").value; 
      arrSearchOpt["mapsource"] = document.getElementById("mapsource").value;
      arrSearchOpt["mapname2"] = document.getElementById("mapname2").value; 
      arrSearchOpt["mapsource2"] = document.getElementById("mapsource2").value;
      arrSearchOpt["mapname3"] = document.getElementById("mapname3").value; 
      arrSearchOpt["mapsource3"] = document.getElementById("mapsource3").value;  
      
      arrSearchOpt["exp"] = document.getElementById("exp").value; 
      arrSearchOpt["gene_product"] = document.getElementById("gene_product").value; 
      arrSearchOpt["prop"] = document.getElementById("prop").value; 
      arrSearchOpt["pheno"] = document.getElementById("pheno").value; 

      if(document.getElementById("adv_limit").checked) {
          arrSearchOpt["adv_limit_val"] = document.getElementById("adv_limit_val").value;
      }
      else {
          arrSearchOpt["adv_limit_val"] = 0;
      }
      getAdvSearch("locus", div_name, arrSearchOpt, pagenum);
  }
  catch (e) { //This search is being run from shadowbox, so re-use old parameters.
      getAdvSearch("locus", div_name, false, pagenum); 
  }
}

function toggle_tabs_locus(val)
{
  document.getElementById(val).style.display = "inline";
  if (val == "gb_v2")
  {
    document.getElementById("gb_v1").style.display = "none";
    document.getElementById("gb_bac").style.display = "none";
  }
  else if (val == "gb_v1")
  {
    document.getElementById("gb_v2").style.display = 'none';
    document.getElementById("gb_bac").style.display = 'none';
  }
  else
  {
    document.getElementById("gb_v2").style.display = 'none';
    document.getElementById("gb_v1").style.display = 'none';
  }
}


function refresh_imgtbl(id, img_name)
{      
  var arrImg = new Array();
  arrImg["name"] = img_name;
  
  getDataParameters("stock", "related_records", id, arrImg);
      
}

/**
* Refreshes the nearby loci section with the selected centimorgan
*/
function refresh_cm(id)
{
   var arrDisplay = new Array();
   arrDisplay["cm"] = document.getElementById("cm").value;

   getDataParameters("locus", "nearby", id, arrDisplay);
}

/**
 * Refreshes a column on the nearby loci table with data from the selected map
 */
function refresh_col(id, cm, val)
{
  var code = 0;
  var location = "col" + val;
  var sel = "s" + val;;

code = document.getElementById(sel).value;

 var xmlHttp;
  try
  {
    // Firefox, Opera 8.0+, Safari
    xmlHttp=new XMLHttpRequest();
  }
  catch (e)
  {
    // Internet Explorer
    try
    {
      xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
    }
    catch (e)
    {
      try
      {
        xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
      catch (e)
      {
        alert("Your browser does not support AJAX!");
        return false;
      }
    }
  }
  xmlHttp.onreadystatechange=function()
  {
    if(xmlHttp.readyState==4)
    {
      document.getElementById(location).innerHTML = xmlHttp.responseText;
    }
    if(xmlHttp.readyState==0)
    {
      document.getElementById(location).innerHTML = "<div align='center'><br><img src='/images/cornloading_60.gif'></div>" ;
    }
    if(xmlHttp.readyState==1)
    {
      document.getElementById(location).innerHTML = "<div align='center'><br><img src='/images/cornloading_60.gif'></div>" ;
    }
    if(xmlHttp.readyState==2)
    {
      document.getElementById(location).innerHTML = "<div align='center'><br><img src='/images/cornloading_60.gif'></div>";
    }
    if(xmlHttp.readyState==3)
    {
      document.getElementById(location).innerHTML = "<div align='center'><br><img src='/images/cornloading_60.gif'></div>" ;
    }
  }

  txtStr01 = "/record_data/locus_data.php?" + "id=" + id + "&type=refresh_nearby" + "&cm=" + cm + "&code=" + code + "&loc=" + location;

  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}
  
  
  
function locuslookup_agp_v2(mapname, locusname)
{

  if(mapname == "look")
  {
    mapname = document.getElementById("idlook_agp").value;
  }
  var code = 0;
  var location = "locus_agp_v2";
  var xmlHttp;
  try
  {
    xmlHttp=new XMLHttpRequest();
  }
  catch (e)
  {
    // Internet Explorer
    try
    {
      xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
    }
    catch (e)
    {
      try
      {
        xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
     catch (e)
     {
       alert("Your browser does not support AJAX!");
       return false;
     }
    }
  }
  xmlHttp.onreadystatechange=function()
  {
    if(xmlHttp.readyState==4)
    {
       document.getElementById(location).innerHTML = "<br> "  + xmlHttp.responseText + "<br><br>";
       document.forms["muform_agp"].submit();
    }
    if(xmlHttp.readyState==0)
    {
       document.getElementById(location).innerHTML = " <br><br><center>Loading coordinate data.... <br><br><img src='../white_ball.gif'></center><br><br>" ;
    }
    if(xmlHttp.readyState==1)
    {
        document.getElementById(location).innerHTML = " <br><br><center>Loading coordinate data.... <br><br><center><img src='../white_ball.gif'></center><br><br>" ;
    }
    if(xmlHttp.readyState==2)
    {
        document.getElementById(location).innerHTML = " <br><br><center>Loading coordinate data.... <br><br><center><img src='../white_ball.gif'></center><br><br>";
    }
    if(xmlHttp.readyState==3)
    {
        document.getElementById(location).innerHTML = " <br><br><center>Loading coordinate data.... <br><br><center><img src='../white_ball.gif'></center><br><br>" ;
    }
  }
  txtStr01 = "locus_lookup_refgenv2.cgi?id=" + mapname + "&locus=" + locusname + "&embed=1";
  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);    
  }


/**
  * Performs a QTL Experiment Browser Search
  */
  function qtl_adv_search(div_name, pagenum)
  {  
    try {
        var arrSearchOpt = {};
        //Add all checkbox params to array
        arrSearchOpt["map_box"] = document.getElementById("map_box").checked;
        arrSearchOpt["trait_box"] = document.getElementById("trait_box").checked;
        arrSearchOpt["bin_box"] = document.getElementById("bin_box").checked;
        arrSearchOpt["stock_box"] = document.getElementById("stock_box").checked;

        //Add all select values to array
        arrSearchOpt["trait"] = document.getElementById("trait").value; 
        arrSearchOpt["stock"] = document.getElementById("stock").value; 
        arrSearchOpt["bin1"] = document.getElementById("bin1").value; 
        arrSearchOpt["bin2"] = document.getElementById("bin2").value; 

        getAdvSearch("qtl", div_name, arrSearchOpt, pagenum);
   }
   catch (e) { //This search is being run from shadowbox, so re-use old parameters.
        getAdvSearch("qtl", div_name, false, pagenum); 
    }

  }
  
/**
  jp - Controls stock field in overview section of Gene record.
  TODO - delete and use generic toggle_sec function
 */
function toggle_stocks(){
 var stock_div = document.getElementById("stock_list");
 var stock_text = document.getElementById("stock_text");
 if (stock_div.style.display == "none"){
   stock_div.style.display = "inline";
   stock_text.innerHTML = "hide";
 }
 else {
   stock_div.style.display = "none";
   stock_text.innerHTML = "show";
 }
}

/**
 * jp - Controls display of Gel Pattern Images inside the Genetic Info section on Locus/Gene pages.
 */
var new_gel_search = true;
function gelImgSearch(div_name, adl_params) {
  var arrSearchOpt = new Array();
  var icon = document.getElementById("gel_img_icon");
  //add additional params to array such as locus or probe ids
  if (icon.src.indexOf("expand") > -1) {
    if (new_gel_search) {
      var arr = adl_params.split("=");
      if (arr.length % 2 == 0) { //should always be an even number of elements
        for (i=0; i<arr.length; i+=2) {
          var key = arr[i];
          var val = arr[i+1];
          arrSearchOpt[key] = val;
        }
      }
      getAdvSearch("image_gel_pattern", div_name, arrSearchOpt); //TODO: include pagenum as a 4th param?
      new_gel_search = false;
    }
    document.getElementById("gel_img_icon").src = "/images/collapse.jpg";
    document.getElementById("gel_img_results").style.display = "block";
  }
  else {
    document.getElementById("gel_img_icon").src = "/images/expand.jpg";
    document.getElementById("gel_img_results").style.display = "none";
  }
}


/** TODO - make this generic for all sections that are toggled with the collapse/expand image for all datacenters**/
function toggle_sec(section) {
  var display = document.getElementById(section).style.display;
  if (display == "none") {
    document.getElementById(section+"_icon").src = "/images/collapse.png";
    document.getElementById(section).style.display = "inline";
  }
  else {
    document.getElementById(section+"_icon").src = "/images/expand.png";
    document.getElementById(section).style.display = "none";
  }

}

  
