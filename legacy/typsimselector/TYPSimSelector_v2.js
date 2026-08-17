
function runLLGM(order,taxa) {

	document.getElementById('TYP_results').innerHTML = "<center><img src='/images/cornloading_trans.gif'></img><br><br> Loading TYPSimSelector results...</center>";

	var url = "tools/ajax/typsimselector/TYPSimSelector_action.php?sort_order=" + order + "&taxa=" + taxa ;
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
