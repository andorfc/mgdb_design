// file: bac.js
//
// purpose: java script functions for BAC record page
//
// history:
//  12/16/14  eksc  cleaned up (some)

var wait_html = "<div align='center'><img src='/images/cornloading_trans.gif'></div>";

/*unused?
function changeVis(num1) {
  var ele1;
  var ele2;
  var ele3;
  
  if(num1 == 0)   {
    ele1 = document.getElementById("information");
    ele2 = document.getElementById("icon0");
    ele3 = document.getElementById("icon0s");
    
  } 
  else if(num1 == 1)   {
    ele1 = document.getElementById("gbrowse");
    ele2 = document.getElementById("icon1");
    ele3 = document.getElementById("icon1s");
    
  }
  else if(num1 == 2)   {
    ele1 = document.getElementById("related");
    ele2 = document.getElementById("icon2");
    ele3 = document.getElementById("icon2s");
    
  }
  else if(num1 == 3)   {
    ele1 = document.getElementById("sequence");
    ele2 = document.getElementById("icon3");
    ele3 = document.getElementById("icon3s");
    
  }
  else if(num1 == 4)   {
    ele1 = document.getElementById("links");
    ele2 = document.getElementById("icon4");
    ele3 = document.getElementById("icon4s");
    
  }
  else if(num1 == 5)   {
    ele1 = document.getElementById("evi");
    ele2 = document.getElementById("icon5");
    ele3 = document.getElementById("icon5s");
    
  }
  
  if (ele1.style.display == "none") {
    ele1.style.display = "inline";
    ele2.innerHTML = "<img  border=\"no\" src='../images/row-contract.gif'></img>";
    ele3.innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-contract.gif'></img>";
  }
  else {
    ele1.style.display = "none";
    ele2.innerHTML = "<img  border=\"no\" src='../images/row-expand.gif'></img>";
    ele3.innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-expand.gif'></img>";
  }
}//changeVis
*/

/*unused?
function closeVis() {
  document.getElementById("information").style.display = "none";
  document.getElementById("icon0").innerHTML = "<img  border=\"no\" src='../images/row-expand.gif'></img>";
  document.getElementById("icon0s").innerHTML = "<img width=\"10\"border=\"no\" src='../images/row-expand.gif'></img>";
  
  document.getElementById("gbrowse").style.display = "none";
  document.getElementById("icon1").innerHTML = "<img border=\"no\" src='../images/row-expand.gif'></img>";
  document.getElementById("icon1s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-expand.gif'></img>";
  
  document.getElementById("related").style.display = "none";
  document.getElementById("icon2").innerHTML = "<img  border=\"no\" src='../images/row-expand.gif'></img>";
  document.getElementById("icon2s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-expand.gif'></img>";
  
  document.getElementById("sequence").style.display = "none";
  document.getElementById("icon3").innerHTML = "<img  border=\"no\" src='../images/row-expand.gif'></img>";
  document.getElementById("icon3s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-expand.gif'></img>";
  
  document.getElementById("links").style.display = "none";
  document.getElementById("icon4").innerHTML = "<img  border=\"no\" src='../images/row-expand.gif'></img>";
  document.getElementById("icon4s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-expand.gif'></img>";
  
  document.getElementById("evi").style.display = "none";
  document.getElementById("icon5").innerHTML = "<img  border=\"no\" src='../images/row-expand.gif'></img>";
  document.getElementById("icon5s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-expand.gif'></img>";
}
*/

var xV1 = 0;
function init() {
  xV1 = document.getElementById('isFloat1').offsetTop;
  stayHome();
}//init
//window.onload=init;


/*unused?
function openVis() {
  document.getElementById("information").style.display = "inline";
  document.getElementById("icon0").innerHTML = "<img border=\"no\" src='../images/row-contract.gif'></img> ";
  document.getElementById("icon0s").innerHTML = "<img width=\"10\"border=\"no\" src='../images/row-contract.gif'></img> ";
  
  document.getElementById("gbrowse").style.display = "inline";
  document.getElementById("icon1").innerHTML = "<img border=\"no\" src='../images/row-contract.gif'></img> ";
  document.getElementById("icon1s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-contract.gif'></img> ";
  
  document.getElementById("related").style.display = "inline";
  document.getElementById("icon2").innerHTML = "<img border=\"no\" src='../images/row-contract.gif'></img> ";
  document.getElementById("icon2s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-contract.gif'></img> ";
  
  document.getElementById("sequence").style.display = "inline";
  document.getElementById("icon3").innerHTML = "<img border=\"no\" src='../images/row-contract.gif'></img> ";
  document.getElementById("icon3s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-contract.gif'></img> ";
  
  document.getElementById("links").style.display = "inline";
  document.getElementById("icon4").innerHTML = "<img  border=\"no\" src='../images/row-contract.gif'></img> ";
  document.getElementById("icon4s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-contract.gif'></img> ";
  
  document.getElementById("evi").style.display = "inline";
  document.getElementById("icon5").innerHTML = "<img  border=\"no\" src='../images/row-contract.gif'></img> ";
  document.getElementById("icon5s").innerHTML = "<img width=\"10\" border=\"no\" src='../images/row-contract.gif'></img> ";
}//openVis
*/

function popUpAlignment(url) {
  disable_megamenu();
  Shadowbox.init({
    skipSetup: true,
    onClose: function () {enable_megamenu()}
  });

  Shadowbox.open({ 
    content: url, 
    player: 'iframe',
    height: 800,
    width: 1100        
  });
}//popUpAlignment


function stayHome() {  
  var nV = 0;
  if (!document.body.scrollTop){nV = document.documentElement.scrollTop}
  else {nV = document.body.scrollTop}
  document.getElementById('isFloat1').style.top = xV1+nV+"px";
  setTimeout("stayHome()",50);
}//stayHome


function toggle_genome_tabs(tab, div) {
  $('.toggle_div').each(function(k, el) {
     $('#'+el.id).hide();
  });
    
  $('.toggle_tab').each(function(k, el) {
    if (el.id == tab) {
      $('#'+div).show();
      $('#'+div).css("display:block");
      $('#'+tab).removeClass('genome_tab_unfocus');
      $('#'+tab).addClass('genome_tab_focus');
    }
    else {
      $('#'+el.id).removeClass('genome_tab_focus');
      $('#'+el.id).addClass('genome_tab_unfocus');
    }
  });
}


  



/* unused
var prevsort = "";
var mdir = "0";

var prevsort2 = "";
var mdir2 = "0";

var prevsort3 = "";
var mdir3 = "0";

var prevsort4 = "";
var mdir4 = "0";

// Evidence EST for B73 RefGen_v2
function doESTTable_v2(mid, macc, msort) {
  if(prevsort == msort) {
    if(mdir == "0") {
      mdir = "1";
    }
    else {
      mdir = "0";
    }
  }
  else {
    mdir = "0";
  }
  
  prevsort = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById("est-alignment-v2").innerHTML = xmlHttp.responseText;
      if (!xmlHttp.responseText.match(/-- no EST alignments found --/)) {
        // Turn on detail area
        document.getElementById('est-alignment-detail-v2').style.display = "inline";
      }
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("est-alignment-v2").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("est-alignment-v2").innerHTML = wait_html;
      }
    if (xmlHttp.readyState==2) {
      document.getElementById("est-alignment-v2").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("est-alignment-v2").innerHTML = wait_html;
    }
  }

  if (document.getElementById("est-alignment-v2") != null) {
    var txtStr01 = "/record_data/bac_data.php?id=" + mid + "&acc=" + macc
               + "&type=est_alignments_v2&sort=" + msort + "&direction=" + mdir;
  
    xmlHttp.open("GET", txtStr01, true);
    xmlHttp.send(null);
  }
}//doESTTable_v2


// Evidence EST table for B73 RefGen_V1
function doESTTable_v1(mid, macc, msort) {
  if (prevsort == msort) {
    if (mdir == "0") {
      mdir = "1";
    }
    else {
      mdir = "0";
    }
  } 
  else {
    mdir = "0";
  }
  
  prevsort = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById("est-alignment-v1").innerHTML = xmlHttp.responseText;
      if (!xmlHttp.responseText.match(/-- no EST alignments found --/)) {
        // Turn on detail area
        document.getElementById('est-alignment-detail-v1').style.display = "inline";
      }
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("est-alignment-v1").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("est-alignment-v1").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==2) {
      document.getElementById("est-alignment-v1").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==3) {
      document.getElementById("est-alignment-v1").innerHTML = wait_html;
    }
  }

  if (document.getElementById("est-alignment-v1") != null) {
    var txtStr01 = "/record_data/bac_data.php?id=" + mid + "&acc=" + macc
               + "&type=est_alignments_v1&sort=" + msort + "&direction=" + mdir;
               
    xmlHttp.open("GET", txtStr01, true);
    xmlHttp.send(null);
  }
}//doESTTable_v1


// Evidence EST table for BAC-based assembly
function doESTTable_bac(mid, macc, msort) {
  if(prevsort == msort) {
    if(mdir == "0") {
      mdir = "1";
    } 
    else {
      mdir = "0";
    }
  } 
  else {
    mdir = "0";
  }
  
  prevsort = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById("est-alignment-bac").innerHTML = xmlHttp.responseText;
      if (!xmlHttp.responseText.match(/-- no EST alignments found --/)) {
        // Turn on detail area
        document.getElementById('est-alignment-detail-bac').style.display = "inline";
      }
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("est-alignment-bac").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("est-alignment-bac").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==2) {
      document.getElementById("est-alignment-bac").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==3) {
      document.getElementById("est-alignment-bac").innerHTML = wait_html;
    }
  }

  if (document.getElementById("est-alignment-bac") != null) {
    var txtStr01 = "/record_data/bac_data.php?id=" + mid + "&acc=" + macc
               + "&type=est_alignments_bac&sort=" + msort + "&direction=" + mdir;
  
    xmlHttp.open("GET", txtStr01,  true);
    xmlHttp.send(null);
  }
}//doESTTable_bac


// Load details for individual evidence EST record for BAC
function doEST_bac(gb_name, mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if(mor == "+") {
    mor = "Forward";
  }
  else {
    mor = "Reverse";
  }
  
  if(mor2 == "+") {
    mor2 = "Forward";
  }
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("evi_bac_est_view").innerHTML = "" + xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("evi_bac_est_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("evi_bac_est_view").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==2) {
      document.getElementById("evi_bac_est_view").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==3) {
      document.getElementById("evi_bac_est_view").innerHTML = wait_html;
    }
  }

  txtStr01 = "/record_data/bac_data.php?id=" + gb_name
             + "&type=create_est_links_bac&mseq=" + mseq +  "&mstart=" + mstart 
             + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 
             + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen 
             + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET", txtStr01, true);
  xmlHttp.send(null);
}//doEST_bac


// Load details for individual evidence EST record for V1
function doEST_v1(gb_name , mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if(mor == "+") {
    mor = "Forward";
  } 
  else {
    mor = "Reverse";
  }
  
  if(mor2 == "+") {
    mor2 = "Forward";
  }
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("evi_v1_est_view").innerHTML = xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("evi_v1_est_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("evi_v1_est_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("evi_v1_est_view").innerHTML = wait_html
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("evi_v1_est_view").innerHTML = wait_html;
    }
  }

  txtStr01 = "/record_data/bac_data.php?id=" + gb_name
             + "&type=create_est_links_v1&mseq=" + mseq +  "&mstart=" + mstart 
             + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 
             + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen 
             + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET", txtStr01, true);
  xmlHttp.send(null);
}//doEST_v1


// Load details for individual evidence EST record for V2
function doEST_v2(gb_name, mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if(mor == "+") {
    mor = "Forward";
  }
  else {
    mor = "Reverse";
  }
  
  if(mor2 == "+") {
    mor2 = "Forward";
  }
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("evi_v2_est_view").innerHTML = xmlHttp.responseText;
      // NOTE: this string is coded in bac_sections.bau
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("evi_v2_est_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("evi_v2_est_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("evi_v2_est_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("evi_v2_est_view").innerHTML = wait_html;
    }
  }

  txtStr01 = "/record_data/bac_data.php?id=" + gb_name
             + "&type=create_est_links_v2&mseq=" + mseq +  "&mstart=" + mstart 
             + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 
             + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen 
             + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET", txtStr01, true);
  xmlHttp.send(null);
}//doEST_v2


// Load cDNA table for evidence cDNA (V2)
function do_cDNATable_v2(mid, macc, msort) {
  if(prevsort2 == msort) {
    if(mdir2 == "0") {
      mdir2 = "1";
    }
    else {
      mdir2 = "0";
    }
  }
  else {
    mdir2 = "0";
  }
  
  prevsort2 = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById('cdna-alignment-v2').innerHTML = xmlHttp.responseText;
      if (!xmlHttp.responseText.match(/-- no cDNA alignments found --/)) {
        // Turn on detail area
        document.getElementById('cdna-alignment-detail-v2').style.display = "inline";
      }
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("cdna-alignment-v2").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("cdna-alignment-v2").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("cdna-alignment-v2").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("cdna-alignment-v2").innerHTML = wait_html;
    }
  }

  if (document.getElementById('cdna-alignment-v2') != null) {
    txtStr01 = "/record_data/bac_data.php?id=" + mid + "&acc=" + macc
               + "&type=create_cDNA_aligns_v2&sort=" + msort 
               + "&direction=" + mdir2; 
  
    xmlHttp.open("GET", txtStr01, true);
    xmlHttp.send(null);
  }
}//do_cDNATable_v2


// Load cDNA table for evidence cDNA (V1)
function do_cDNATable_v1(mid, macc, msort) {
  if (prevsort2 == msort)  {
    if (mdir2 == "0") {
      mdir2 = "1";
    } 
    else {
      mdir2 = "0";
    }
  } 
  else {
    mdir2 = "0";
  }
  
  prevsort2 = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById("cdna-alignment-v1").innerHTML = xmlHttp.responseText;
      if (!xmlHttp.responseText.match(/-- no cDNA alignments found --/)) {
        // Turn on detail area
        document.getElementById('cdna-alignment-detail-v1').style.display = "inline";
      }
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("cdna-alignment-v1").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("cdna-alignment-v1").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("cdna-alignment-v1").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("cdna-alignment-v1").innerHTML = wait_html;
    }
  }
 
  if (document.getElementById("cdna-alignment-v1") != null) {
    txtStr01 = "/record_data/bac_data.php?id=" + mid + "&acc=" + macc
               + "&type=create_cDNA_aligns_v1&sort=" + msort 
               + "&direction=" + mdir2; 
  
    xmlHttp.open("GET", txtStr01, true);
    xmlHttp.send(null);
  }
}//do_cDNATable_v1


// Load cDNA table for evidence cDNA (BAC)
function do_cDNATable_bac(mid, macc, msort) {
  if (prevsort2 == msort) {
    if(mdir2 == "0") {
      mdir2 = "1";
    } 
    else {
      mdir2 = "0";
    }
  } 
  else {
    mdir2 = "0";
  }
  
  prevsort2 = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById("cdna-alignment-bac").innerHTML = xmlHttp.responseText;
      if (!xmlHttp.responseText.match(/-- no cDNA alignments found --/)) {
        // Turn on detail area
        document.getElementById('cdna-alignment-detail-bac').style.display = "inline";
      }
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("cdna-alignment-bac").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("cdna-alignment-bac").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("cdna-alignment-bac").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("cdna-alignment-bac").innerHTML = wait_html;
    }
  }

  if (document.getElementById("cdna-alignment-bac") != null) {
    txtStr01 = "/record_data/bac_data.php?id=" + mid + "&acc=" + macc
               + "&type=create_cDNA_aligns_bac&sort=" + msort 
               + "&direction=" + mdir2; 
  
    xmlHttp.open("GET", txtStr01, true);
    xmlHttp.send(null);
  }
}//do_cDNATable_bac


// Load individual cDNA record (BAC)
function do_cDNA_bac(gb_name, mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if (mor == "+") {
    mor = "Forward";
  } 
  else {
    mor = "Reverse";
  }
  
  if(mor2 == "+") {
    mor2 = "Forward";
  } 
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("evi_bac_cdna_view").innerHTML = xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("evi_bac_cdna_view").innerHTML = wait-html
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("evi_bac_cdna_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("evi_bac_cdna_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("evi_bac_cdna_view").innerHTML = wait_html;
    }
  }

  txtStr01 = "/record_data/bac_data.php?id=" + gb_name
             + "&type=create_cdna_links_bac&mseq=" + mseq +  "&mstart=" + mstart
             + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 
             + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen 
             + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET", txtStr01, true);
  xmlHttp.send(null);
}//do_cDNA_bac


// Load individual cDNA record (V1)
function do_cDNA_v1(gb_name, mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if (mor == "+") {
    mor = "Forward";
  } 
  else {
    mor = "Reverse";
  }
  
  if(mor2 == "+") {
    mor2 = "Forward";
  } 
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("evi_v1_cdna_view").innerHTML = xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("evi_v1_cdna_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("evi_v1_cdna_view").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==2) {
      document.getElementById("evi_v1_cdna_view").innerHTML = wait_html;
    }
    if(xmlHttp.readyState==3) {
      document.getElementById("evi_v1_cdna_view").innerHTML = wait_html;
    }
  }

  txtStr01 = "/record_data/bac_data.php?id=" + gb_name
             + "&type=create_cdna_links_v1&mseq=" + mseq +  "&mstart=" + mstart
             + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 
             + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen 
             + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET", txtStr01, true);
  xmlHttp.send(null);
}//doGB2_v1


// Load individual cDNA record (V2)
function do_cDNA_v2(gb_name , mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if (mor == "+") {
    mor = "Forward";
  } 
  else {
    mor = "Reverse";
  }
  
  if (mor2 == "+") {
    mor2 = "Forward";
  } 
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("evi_v2_cdna_view").innerHTML = xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("evi_v2_cdna_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("evi_v2_cdna_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("evi_v2_cdna_view").innerHTML = wait_html;
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("evi_v2_cdna_view").innerHTML = wait_html;
    }
  }

  txtStr01 = "/record_data/bac_data.php?id=" + gb_name 
             + "&type=create_cdna_links_v2&mseq=" + mseq +  "&mstart=" + mstart
             + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 
             + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen 
             + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET", txtStr01, true);
  xmlHttp.send(null);
}//do_cDNA_v2
*/

/* unused?
function doTableAGP(mid, msort) {
  if (prevsort3 == msort) {
    if(mdir3 == "0") {
      mdir3 = "1";
    } 
    else {
      mdir3 = "0";
    }
  } 
  else {
    mdir3 = "0";
  }
  
  prevsort3 = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById("agp0").innerHTML = "" + xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("agp0").innerHTML = ""; //"<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\">   Loading data for " + mid + " <br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("agp0").innerHTML = ""; //"<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\">   Loading data for " + mid + " <br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("agp0").innerHTML = ""; //"<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\">   Loading data for " + mid + " <br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("agp0").innerHTML = "<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\"> <font size='4'>  Loading data for " + mid + " </font><br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
  }

  txtStr01 = "createBACESTaligns2_as.cgi?id=" + mid + "&sort=" + msort + "&direction=" + mdir3;

  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}
*/

/* unused
function doAS(gb_name , mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if (mor == "+") {
    mor = "Forward";
  } 
  else {
    mor = "Reverse";
  }
  
  if (mor2 == "+") {
    mor2 = "Forward";
  } 
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("gb1_as").innerHTML = "" + xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
       document.getElementById("gb1_as").innerHTML = ""; //"<table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td width=\"100%\">   0Loading data for " + gb_name + " <img src='../white_ball.gif'></td><tr> </table>";
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("gb1_as").innerHTML = ""; //"<table  cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td width=\"100%\">   1Loading data for " + gb_name + " <img src='../white_ball.gif'></td><tr> </table>";
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("gb1_as").innerHTML = ""; //"<table  cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td width=\"100%\">   2Loading data for " + gb_name + " <img src='../white_ball.gif'></td><tr> </table>";
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("gb1_as").innerHTML = "<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\"> <font size='4'>  Loading data for " + gb_name + " and " + mseq + " </font><br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
  }

  txtStr01 = "createESTlinks_as.cgi?id=" + gb_name + "&mseq=" + mseq +  "&mstart=" + mstart + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}
*/

/*unused
function doTableAGP2(mid, msort) {
  if (prevsort4 == msort) {
    if(mdir4 == "0") {
      mdir4 = "1";
    } 
    else {
      mdir4 = "0";
    }
  } 
  else {
    mdir4 = "0";
  }
  
  prevsort4 = msort;

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
    if (xmlHttp.readyState==4) {
      document.getElementById("agp2").innerHTML = "" + xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("agp2").innerHTML = ""; //"<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\">   Loading data for " + mid + " <br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("agp2").innerHTML = ""; //"<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\">   Loading data for " + mid + " <br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("agp2").innerHTML = ""; //"<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\">   Loading data for " + mid + " <br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("agp2").innerHTML = "<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\"> <font size='4'>  Loading data for " + mid + " </font><br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
  }

  txtStr01 = "createBACCDNAaligns2_as.cgi?id=" + mid + "&sort=" + msort + "&direction=" + mdir4; 

  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}
*/

/*unused
function doAS2(gb_name , mseq, mstart, mend, mor, mstart2, mend2, mor2, mlen, mcov, msim) {
  if (mor == "+") {
    mor = "Forward";
  } 
  else {
    mor = "Reverse";
  }
  
  if (mor2 == "+") {
    mor2 = "Forward";
  }
  else {
    mor2 = "Reverse";
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
    if (xmlHttp.readyState==4) {
      document.getElementById("gb3_as").innerHTML = "" + xmlHttp.responseText;
    }
    if (xmlHttp.readyState==0) {
      document.getElementById("gb3_as").innerHTML = ""; //"<table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td width=\"100%\">   0Loading data for " + gb_name + " <img src='../white_ball.gif'></td><tr> </table>";
    }
    if (xmlHttp.readyState==1) {
      document.getElementById("gb3_as").innerHTML = ""; //"<table  cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td width=\"100%\">   1Loading data for " + gb_name + " <img src='../white_ball.gif'></td><tr> </table>";
    }
    if (xmlHttp.readyState==2) {
      document.getElementById("gb3_as").innerHTML = ""; //"<table  cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td width=\"100%\">   2Loading data for " + gb_name + " <img src='../white_ball.gif'></td><tr> </table>";
    }
    if (xmlHttp.readyState==3) {
      document.getElementById("gb3").innerHTML = "<br><br><br><br><center><table cellpadding=8 cellspacing=3 width=\"100%\" ><tr><td align=center width=\"100%\"> <font size='4'>  Loading data for " + gb_name + " and " + mseq + " </font><br><img src='../white_ball.gif'></td><tr> </table></center>";
    }
  }

  txtStr01 = "createCDNAlinks_as.cgi?id=" + gb_name + "&mseq=" + mseq +  "&mstart=" + mstart + "&mend=" + mend + "&mor=" + mor + "&mstart2=" + mstart2 + "&mend2=" + mend2 + "&mor2=" + mor2 + "&mlen=" + mlen + "&mcov=" + mcov + "&msim=" + msim;

  xmlHttp.open("GET",txtStr01,true);
  xmlHttp.send(null);
}
*/

/*unused?
function switchSeq(val) {
  if (val == 1) {
    $('#seq_v1_tab').removeClass('bac_sequence_tab_on');
    $('#seq_v1_tab').addClass('bac_sequence_tab_off');
    document.getElementById("seq_v1").style.display = 'none';
    $('#seq_v2_tab').removeClass('bac_sequence_tab_on');
    $('#seq_v2_tab').addClass('bac_sequence_tab_off');
    document.getElementById("seq_v2").style.display = 'none';
    $('#seq_tab').removeClass('bac_sequence_tab_off');
    $('#seq_tab').addClass('bac_sequence_tab_on');
    document.getElementById("seq").style.display = 'inline';
  } 
  else if (val == 2) {
    $('#seq_v1_tab').removeClass('bac_sequence_tab_on');
    $('#seq_v1_tab').addClass('bac_sequence_tab_off');
    document.getElementById("seq_v1").style.display = 'none';
    $('#seq_tab').removeClass('bac_sequence_tab_on');
    $('#seq_tab').addClass('bac_sequence_tab_off');
    document.getElementById("seq").style.display = 'none';
    $('#seq_v2_tab').removeClass('bac_sequence_tab_off');
    $('#seq_v2_tab').addClass('bac_sequence_tab_on');
    document.getElementById("seq_v2").style.display = 'inline';
  } 
  else {
    $('#seq_tab').removeClass('bac_sequence_tab_on');
    $('#seq_tab').addClass('bac_sequence_tab_off');
    document.getElementById("seq").style.display = 'none';
    $('#seq_v2_tab').removeClass('bac_sequence_tab_on');
    $('#seq_v2_tab').addClass('bac_sequence_tab_off');
    document.getElementById("seq_v2").style.display = 'none';
    $('#seq_v1_tab').removeClass('bac_sequence_tab_off');
    $('#seq_v1_tab').addClass('bac_sequence_tab_on');
    document.getElementById("seq_v1").style.display = 'inline';
  }
}
*/

/*unused?
function switchGB(val) {
  if (val == 1) {
    document.getElementById("gb_pseudo").style.display = 'none';
    document.getElementById("gb_pseudo_v2").style.display = 'none';
    document.getElementById("gb_bac").style.display = 'inline';
  } 
  else if (val == 2) {
    document.getElementById("gb_pseudo").style.display = 'none';
    document.getElementById("gb_bac").style.display = 'none';
    document.getElementById("gb_pseudo_v2").style.display = 'inline';
  } 
  else {
    document.getElementById("gb_bac").style.display = 'none';
    document.getElementById("gb_pseudo_v2").style.display = 'none';
    document.getElementById("gb_pseudo").style.display = 'inline';
  }
}
*/

/*unused
function switchEvi(val) {
  if (val == 1) {
    document.getElementById("ev_v1").style.display = 'none';
    document.getElementById("ev_v2").style.display = 'none';
    document.getElementById("ev").style.display = 'inline';
  } 
  else if (val == 2) {
    document.getElementById("ev_v1").style.display = 'none';
    document.getElementById("ev").style.display = 'none';
    document.getElementById("ev_v2").style.display = 'inline';
  } 
  else {
    document.getElementById("ev").style.display = 'none';
    document.getElementById("ev_v2").style.display = 'none';
    document.getElementById("ev_v1").style.display = 'inline';
  }
}
*/

/*unused
function switchAS(gb_val) {
  if(gb_val == 1) {
    document.getElementById("gb_pseudo").style.display = 'none';
    document.getElementById("gb_bac").style.display = 'inline';
  } 
  else {
    document.getElementById("gb_bac").style.display = 'none';
    document.getElementById("gb_pseudo").style.display = 'inline';
  }
}
*/

/*unused
function initEvidence(id, acc, name) {
  // Load EST tables for all assemblies
  doESTTable_v2(id, acc, 'ZACC');
  doESTTable_v1(id, acc, 'ZACC');
  doESTTable_bac(id, acc, 'ZACC');
  
  // Load cDNA tables for all assemblies
  do_cDNATable_v2(id, acc, 'ZACC');
  do_cDNATable_v1(id, acc, 'ZACC');
  do_cDNATable_bac(id, acc, 'ZACC');
}
*/

//unused
function toggle_tabs(val) {
  var section = val.replace(/(.*)\_.*/, "$1");
  (val == section+'_v2') ?
      document.getElementById(section+'_v2').style.display = 'inline'
    :
      document.getElementById(section+'_v2').style.display = 'none'; 
  (val == section+'_v1') ?
      document.getElementById(section+'_v1').style.display = 'inline'
    :
      document.getElementById(section+'_v1').style.display = 'none'; 
  (val == section+'_bac') ?
      document.getElementById(section+'_bac').style.display = 'inline'
    :
      document.getElementById(section+'_bac').style.display = 'none'; 
}

