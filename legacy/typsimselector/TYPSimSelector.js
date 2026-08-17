function runTYP_results(order,taxa,taxa2,dataset) {
	
	document.getElementById('TYP_results').innerHTML = "<center><img src='/images/cornloading_trans.gif'></img><br><br> Loading TYPSimSelector results...</center>";

	var url = "tools/ajax/typsimselector/TYPSimSelector_action.php?sort_order=" + order + "&taxa=" + taxa + "&taxa2=" + taxa2 + "&dataset=" + dataset;
	var xmlhttp;
	
	if (window.XMLHttpRequest)
  		{// code for IE7+, Firefox, Chrome, Opera, Safari
  			xmlhttp=new XMLHttpRequest();
  		}
	else
  		{// code for IE6, IE5
  			xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
  		}
	xmlhttp.onreadystatechange=function()
  		{
  			if (xmlhttp.readyState==4 && xmlhttp.status==200)
    				{

						document.getElementById('TYP_results').innerHTML = xmlhttp.responseText ;

					}	
		}						
	xmlhttp.open("GET",url,true);
	xmlhttp.send();
	
}

// Used by the checkbox to open/close either the breeder's taxa2_div or curator's taxa2_div
function showhide(dataset) {
	if (dataset == "breeding") {
	
	   if (document.getElementById('taxa2_div').style.display === "none") {
		  //show taxa2_div:
		  document.getElementById('taxa2_div').style.display = "block";
	   }
	   else {
		  //hide the div:
		  document.getElementById('taxa2_div').style.display = "none";
	   }
	   
	} else {
	
	   if (document.getElementById('curator_taxa2_div').style.display === "none") {
		  //show taxa2_div:
		  document.getElementById('curator_taxa2_div').style.display = "block";
	   }
	   else {
		  //hide the div:
		  document.getElementById('curator_taxa2_div').style.display = "none";  
	   }
	   
	}
	//resets the taxa2 drop down box back to index 0 which is equal to 'ALL':
	document.getElementById('taxa2').selectedIndex = 0;
	document.getElementById('curator_taxa2').selectedIndex = 0;
}


//When page is refreshed all checkboxes and drop down boxes reset
function reset_page() {
	document.getElementById('taxa2_check').checked = false;
	document.getElementById('taxa2_check2').checked = false;
	document.getElementById('dataset').selectedIndex = 0;
	document.getElementById('taxa').selectedIndex = 0;
	document.getElementById('taxa2').selectedIndex = 0;
	document.getElementById('curator_taxa').selectedIndex = 0;
	document.getElementById('curator_taxa2').selectedIndex = 0;
	document.getElementById('taxa_form_breeders').style.display = "none";
	document.getElementById('taxa_form_curators').style.display = "none";
	document.getElementById('taxa2_div').style.display = "none";
	document.getElementById('curator_taxa2_div').style.display = "none";
	document.getElementById('TYP_results').innerHTML = "Results will appear here:<br><br>";
	document.getElementById('TYP_results').style.display = "none";
}

// Do not combine this with reset_page() because you will end up in an infinite loop
// of things being reset
function reset_form() {
	document.getElementById('taxa2_check').checked = false;
	document.getElementById('taxa2_check2').checked = false;
	document.getElementById('taxa2_div').style.display = "none";
	document.getElementById('curator_taxa2_div').style.display = "none";
	document.getElementById('taxa2').selectedIndex = 0;
	document.getElementById('curator_taxa2').selectedIndex = 0;
	document.getElementById('taxa_form_breeders').style.display = "none";
	document.getElementById('taxa_form_curators').style.display = "none";
	document.getElementById('TYP_results').innerHTML = "Results will appear here:<br><br>";

}

// Determines which dataset form to show.
function datasetForms() {
	reset_form();
	if (document.getElementById('dataset').value === 'breeding') {
		document.getElementById('taxa_form_breeders').style.display = "block";
		document.getElementById('taxa_form_curators').style.display = "none";
		document.getElementById('TYP_results').style.display = "block";

	} else if (document.getElementById('dataset').value === 'curation') {
		document.getElementById('taxa_form_breeders').style.display = "none";
		document.getElementById('taxa_form_curators').style.display = "block";
		document.getElementById('TYP_results').style.display = "block";
		
	} else {
		document.getElementById('taxa_form_breeders').style.display = "none";
		document.getElementById('taxa_form_curators').style.display = "none";
		document.getElementById('TYP_results').style.display = "none";
		
	} 
}