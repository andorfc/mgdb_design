
var isGM = false;
function runLL(id,locus,ref) {
runLLGM(id,locus,ref);
runLLPM(id,locus,ref);
runLLPB(id,locus,ref);
runLLGMP(id,locus,ref);
}
function toggleview(view_id, text_id) {
	if(document.getElementById(view_id).style.display == "none")
	{
		document.getElementById(view_id).style.display = "block";
		document.getElementById(text_id).innerHTML = "<a href=\"javascript:toggleview('" + view_id + "','" + text_id + "'\)\">Hide details</a>";
	} else {
		document.getElementById(view_id).style.display = "none";
		document.getElementById(text_id).innerHTML = "<a href=\"javascript:toggleview('" + view_id + "','" + text_id + "'\)\">See details</a>";
	}
}

function showDetail() {
		document.getElementById("id0").innerHTML = "Andorf, CM, Lawrence, CJ, Harper, LC, Schaeffer, ML, Campbell, DA, Sen, TZ. (2010) The Locus Lookup tool at MaizeGDB: identification of genomic regions in maize by integrating sequence information with physical and genetic maps. Bioinformatics. 2010 26: 434-436. (<a href='http://bioinformatics.oxfordjournals.org/content/26/3/434'>Link</a>, <a href='http://www.ncbi.nlm.nih.gov/pubmed?term=20124413'>PubMed</a>)";
}

function showDesc() {
		if(document.getElementById("id1").style.display == "none")
		{
			document.getElementById("id1").style.display = "block";
		} else {
			document.getElementById("id1").style.display = "none";
		}
}

function runLLGM(id,locus,ref) {
	document.getElementById('ll_results_gm').style.display = "block";
	document.getElementById('ll_results_gm').innerHTML = "Loading Locus Lookup results...";
	document.getElementById('ll_extra_gm').innerHTML = "<br>Loading Locus Lookup results...";
	var url = "/tools/ajax/locus_lookup/getGeneModels.php?locus=" + locus ;
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
    				    	var json = eval('(' + xmlhttp.responseText + ')');
							var startVal = json['start'];
							var endVal = json['end'];
							var chrVal = json['chr'];
							var gmVal = json['gm'];
							var len = json['length'];
							var cl1 = json['chrlink1'];
							var cl2 = json['chrlink2'];
							var gl1 = json['gblink1'];
							var gl2 = json['gblink2'];
							if(gmVal.length > 1)
							{
								document.getElementById('ll_results_gm').innerHTML = "The Locus <b>" + locus + "</b> is between <b>" + startVal + "</b> and <b>" + endVal + "</b> on Chromosome <b>" + chrVal + "</b> based on gene model <b>" + gmVal + "</b>.";
								var region_diff = parseInt(endVal) - parseInt(startVal);
								
								document.getElementById('ll_extra_gm').innerHTML = "<br>This region is " + len + " base pairs. Click on images to go to the MaizeGDB genome browser." + "<br><br><table><tr><td>Genome View: <br><a href=\"" + cl1 + "\"> <img border='no' src=\"" + cl2 + "\" width=300></a></td>" + "<td><td valign='top'>Genome Browser View:<br><a href=\"" + gl1 + "\"> <img border='no' src=\"" + gl2 + "\" width=350></a></td></tr></table>" ;
							} else {
								document.getElementById('ll_results_gm').innerHTML = "The Locus <b>" + locus + "</b> is not associated to any gene model.";
								document.getElementById('ll_extra_gm').innerHTML = "<br>Details are unavailable for this locus. " ;
							}	
					}	
		}						
	xmlhttp.open("GET",url,true);
	xmlhttp.send();
}

function runLLPM(id,locus,ref) {
	document.getElementById('ll_results_pm').style.display = "block";
	document.getElementById('ll_results_pm').innerHTML = "Loading Locus Lookup results...";
	document.getElementById('ll_extra_pm').innerHTML = "<br>Loading Locus Lookup results...";
	var url = "/tools/ajax/locus_lookup/getPhyMapped.php?locus=" + locus ;
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
    				    	var json = eval('(' + xmlhttp.responseText + ')');
							var startVal = json['start'];
							var endVal = json['end'];
							var chrVal = json['chr'];
							var len = json['length'];
							var support = json['support'];
							var cl1 = json['chrlink1'];
							var cl2 = json['chrlink2'];
							var gl1 = json['gblink1'];
							var gl2 = json['gblink2'];
								if(startVal.length > 1)
							{
							document.getElementById('ll_results_pm').innerHTML = "The Locus <b>" + locus + "</b> is between <b>" + startVal + "</b> and <b>" + endVal + "</b> on Chromosome <b>" + chrVal + "</b> based on <b>" + support + "</b>.";
							var region_diff = parseInt(endVal) - parseInt(startVal);
							
							document.getElementById('ll_extra_pm').innerHTML = "<br>This region is " + len + " base pairs. Click on images to go to the MaizeGDB genome browser." + "<br><br><table><tr><td>Genome View: <br><a href=\"" + cl1 + "\"> <img border='no' src=\"" + cl2 + "\" width=300></a></td>" + "<td><td valign='top'>Genome Browser View:<br><a href=\"" + gl1 + "\"> <img border='no' src=\"" + gl2 + "\" width=350></a></td></tr></table>" ;
							}  else {
								document.getElementById('ll_results_pm').innerHTML = "The Locus <b>" + locus + "</b> has not been physically mapped.";
								document.getElementById('ll_extra_pm').innerHTML = "<br>Details are unavailable for this locus. " ;
							}	
					}	
		}						
	xmlhttp.open("GET",url,true);
	xmlhttp.send();
}

function runLLPB(id,locus,ref) {
	document.getElementById('ll_results_pb').style.display = "block";
	document.getElementById('ll_results_pb').innerHTML = "Loading Locus Lookup results...";
	document.getElementById('ll_extra_pb').innerHTML = "<br>Loading Locus Lookup results...";
	var url = "/tools/ajax/locus_lookup/getPlacedBAC.php?locus=" + locus ;
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
    				    	var json = eval('(' + xmlhttp.responseText + ')');
							var startVal = json['start'];
							var endVal = json['end'];
							var chrVal = json['chr'];
							var len = json['length'];
							var support = json['support'];
							var cl1 = json['chrlink1'];
							var cl2 = json['chrlink2'];
							var gl1 = json['gblink1'];
							var gl2 = json['gblink2'];
							if(startVal.length > 1)
							{
							document.getElementById('ll_results_pb').innerHTML = "The Locus <b>" + locus + "</b> is between <b>" + startVal + "</b> and <b>" + endVal + "</b> on Chromosome <b>" + chrVal + "</b> based on <b>" + support + "</b>.";
							var region_diff = parseInt(endVal) - parseInt(startVal);
							
							document.getElementById('ll_extra_pb').innerHTML = "<br>This region is " + len + " base pairs. Click on images to go to the MaizeGDB genome browser." + "<br><br><table><tr><td>Genome View: <br><a href=\"" + cl1 + "\"> <img border='no' src=\"" + cl2 + "\" width=300></a></td>" + "<td><td valign='top'>Genome Browser View:<br><a href=\"" + gl1 + "\"> <img border='no' src=\"" + gl2 + "\" width=350></a></td></tr></table>" ;
							}  else {
								document.getElementById('ll_results_pb').innerHTML = "The Locus <b>" + locus + "</b> is not associated with physically mapped probes.";
								document.getElementById('ll_extra_pb').innerHTML = "<br>Details are unavailable for this locus. " ;
							}	
					}	
		}						
	xmlhttp.open("GET",url,true);
	xmlhttp.send();
}

function runLLGMP(id,locus,ref) {
	document.getElementById('ll_results_gmp').style.display = "block";
	document.getElementById('ll_results_gmp').innerHTML = "Loading Locus Lookup results...";
	document.getElementById('ll_extra_gmp').innerHTML = "<br>Loading Locus Lookup results...";
	var url = "/tools/ajax/locus_lookup/getGenMapped.php?locus=" + locus + "&id=" + id ;
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
    				    	var json = eval('(' + xmlhttp.responseText + ')');
							var startVal = json['start'];
							var endVal = json['end'];
							var chrVal = json['chr'];
							var len = json['length'];
							var support = json['support'];
							var extra_out = json['extra'];
							var lname = json['lname'];
							var rname = json['rname'];
							var lid = json['lid'];
							var rid = json['rid'];
							if(startVal.length > 1 || startVal > 0 )
							{
							document.getElementById('ll_results_gmp').innerHTML = "The Locus <b>" + locus + "</b> is between <b>" + startVal + "</b> and <b>" + endVal + "</b> on Chromosome <b>" + chrVal + "</b> based on <b>" + support + "</b> " + "(<a href='data_center/locus/?id=" + lid + "'>" + lname + "</a> and <a href='data_center/locus/?id=" + rid + "'>" + rname + "</a>).";
							var region_diff = parseInt(endVal) - parseInt(startVal);
							document.getElementById('ll_extra_gmp').innerHTML = "<br>" + extra_out;
							}  else {
								document.getElementById('ll_results_gmp').innerHTML = "The Locus <b>" + locus + "</b> is not genetically mapped.";
								document.getElementById('ll_extra_gmp').innerHTML = "<br>Details are unavailable for this locus. " ;
							}	
					}	
		}						
	xmlhttp.open("GET",url,true);
	xmlhttp.send();	
}