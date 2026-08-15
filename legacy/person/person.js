/* file: person.js
 *
 * purpose: functions for the find person search page.
 *
 * history:
 *   06/21/12  jp - added the doWork, doSugg, doCity, doNation, and doSyn functions
 *   10/20/12 jp - added method to toggle display of references/libraries/genetic elements on record page 
 */

function SamplePersonQuery()
{
    var d = new Date();
    var rand_val = d.getTime() % 20;
    
    switch(rand_val)
        {
            
            case 0:
              document.getElementById('bacterm').value ='Ed Buckler';
              break;
            case 1:
              document.getElementById('bacterm').value ='Virginia Walbot';
              break;
              case 2:
              document.getElementById('bacterm').value ='Ed Coe';
              break;
            case 3:
              document.getElementById('bacterm').value ='Mary Polacco';
              break;
              case 4:
              document.getElementById('bacterm').value ='Leland Ellis';
              break;
            case 5:
              document.getElementById('bacterm').value ='Nathan Springer';
              break;
              case 6:
              document.getElementById('bacterm').value ='Sarah Hake';
              break;
            case 7:
              document.getElementById('bacterm').value ='Barbara McClintock';
              break;
              case 8:
              document.getElementById('bacterm').value ='Marty Sachs';
              break;
            case 9:
              document.getElementById('bacterm').value ='Carol Soderlund';
              break;
              case 10:
              document.getElementById('bacterm').value ='Doreen Ware';
              break;
            case 11:
              document.getElementById('bacterm').value ='Lincoln Stein';
              break;
              case 12:
              document.getElementById('bacterm').value ='Michael Freeling';
              break;
            case 13:
              document.getElementById('bacterm').value ='Vicki Chandler';
              break;
              case 14:
              document.getElementById('bacterm').value ='Phil Becraft';
              break;
            case 15:
              document.getElementById('bacterm').value ='Pat Schnable';
              break;
              case 16:
              document.getElementById('bacterm').value ='Thomas Peterson';
              break;
            case 17:
              document.getElementById('bacterm').value ='Alan Myers';
              break;
              case 18:
              document.getElementById('bacterm').value ='Leland Ellis';
              break;
            case 19:
              document.getElementById('bacterm').value ='Bill Beavis';
              break;
            default:
              document.getElementById('bacterm').value ='John Portwood';
        }
}

/**
 * Load matches from the person search
 * Fixed the asynchronous result loading // CMA 10/11/2012
 */
 
var len=1;
var len1=1;
var len2=1;
  
function doWork(update)
{ 

console.log("doWork, update: " + update);
    len = len + 1;
    var loc_len = len;
    var code = document.getElementById("bacterm").value;
    
    var person_update = "";
    if(typeof(update) !== 'undefined') {
        //Tells the person search to use a different bauplan template to render the results in
        person_update = "&update=Y";
    }
   
    if(code.length > 2)
    {
        var xmlHttp;
        try
        { 
            xmlHttp=new XMLHttpRequest();
        }
        catch (e)
        {
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
        txtStr01 = "/tools/ajax/person_search/persondisplayresults.php?term=" + code + person_update; 
        xmlHttp.onreadystatechange=function()
          { 
              document.getElementById('p1').style.display = 'inline';
            if ((xmlHttp.readyState==0) || (xmlHttp.readyState==1) || (xmlHttp.readyState==2) || (xmlHttp.readyState==3))
            {        
                //document.getElementById('p1').innerHTML = "<table id='person_green_background' style='width:723px'><tr><td>   Loading Matches for <b>" + code + "</b></td></tr><tr><td><center><img src='../../images/popcorn_loading.gif'></center></td></tr> </table>";    
				if(len == loc_len)
				{				
					document.getElementById('p1').innerHTML = "<center><img src='/images/cornloading_trans.gif'></center>";
				} else {
				xmlHttp.close();
				}
            }                
            if (xmlHttp.readyState==4 && xmlHttp.status==200)
            {        
				if(len == loc_len)
				{
					document.getElementById('p1').innerHTML = "" + xmlHttp.responseText;
					doSyn()
				} else {
				xmlHttp.close();
				}
            }
        }
        xmlHttp.open("GET",txtStr01,true); 
        xmlHttp.send();        
    } 
}


/**
 * Load matches for the city
 *
 */
function doCity() {
  var code = $("#city").val();

  if(code.length > 2) {
    $('#p2').html("<center><img src='/images/cornloading_trans.gif'></center>");
    $('#p2').show();

    url = "/tools/ajax/person_search/personuslocquery_ajax.php?city=" + code;
    $.get(url)
    .fail(function(data) {
      console.log('doCity(), GET request failed with ' + code);
    })
    .done(function(data, status, xhr) {
      if (status == 'success') {
        $('#p2').html(xhr.responseText);
      }
    });
  }
  
  return false;
}//doCity


/**
 * Load matches for the state
 *
 */
function doState() {
  var state = $("#state").val();
  var state_full = $("#state option:selected").text()

  if (state.length > 1) {
    $('#p2').html("<center><img src='/images/cornloading_trans.gif'></center>");
    $('#p2').show();

    url = "/tools/ajax/person_search/personusstatequery_ajax.php?state=" + state + "&state_full=" + state_full;
    $.get(url)
    .fail(function(data) {
      console.log('doState(), GET request failed with ' + state_full);
    })
    .done(function(data, status, xhr) {
      if (status == 'success') {
        $('#p2').html(xhr.responseText);
      }
    });
  }
  
  return false;
}//doState


function doNation() {
  var code = $("#country").val();

  if (code.length > 1) {
    $('#p3').html("<center><img src='/images/cornloading_trans.gif'></center>");
    $('#p3').show();
    
    url = "/tools/ajax/person_search/personintllocquery_ajax.php?country=" + code;
    $.get(url)
    .fail(function(data) {
      console.log('doNation(), GET request failed with ' + code);
    })
    .done(function(data, status, xhr) {
      if (status == 'success') {
        $('#p3').html(xhr.responseText);
      }
    });
  }
                
  return false;
}//doNation


function doSyn()
{
    var code = document.getElementById("bacterm").value;
    len2 = len2 + 1;
    var loc_len2 = len2;


    if(code.length > 2)
    {
        var xmlHttp;
        try
        {   
            xmlHttp=new XMLHttpRequest();
        }
        catch (e)
        {
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
        txtStr01 = "/tools/ajax/person_search/displaypersonsynlist_ajax.php?term=" + code; 
        xmlHttp.onreadystatechange=function()
        {   
            document.getElementById("p4").style.display='inline';
            if (xmlHttp.readyState==4 && xmlHttp.status==200)
            {
                if(len2 == loc_len2)
                {
                    document.getElementById("p4").innerHTML = "" + xmlHttp.responseText;
                } else 
                {
                    xmlHttp.close();
                }               
            }
            if((xmlHttp.readyState==0)||(xmlHttp.readyState==1)||(xmlHttp.readyState==2)||(xmlHttp.readyState==3))
            {
                if(len2 == loc_len2)
                {
                    //document.getElementById("p4").innerHTML = "<table id='person_green_background' style='width:723px' ><tr><td>   Loading Synonyms for <b>" + code + "</b></td></tr><td><tr><center><img src='../../images/popcorn_loading.gif'></center></td></tr></table>";
                    document.getElementById('p4').innerHTML = "<center><img src='/images/cornloading_trans.gif'></center>";
                } 
                else 
                {
                    xmlHttp.close();
                }
            }
    
        }
        xmlHttp.open("GET",txtStr01,true);
        xmlHttp.send(null);
    } 
}

function doSugg()
{
    var code = document.getElementById("bacterm").value;
    len3 = len3 + 1;
    var loc_len3 = len3;

    if(code.length > 2)
    {
        var xmlHttp;
        try
        {   
            xmlHttp=new XMLHttpRequest();
        }
        catch (e)
        {
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
        txtStr01 = "/tools/ajax/person_search/displaysuggestion.php?term=" + code;
        xmlHttp.onreadystatechange=function()
        {     
            document.getElementById("sp14").style.display='inline';
            if (xmlHttp.readyState==4 )//&& xmlHttp.status==200
            {
                if(len3 == loc_len3)
                {                
                    if(xmlHttp.responseText)
                    {
                        document.getElementById("sp4").innerHTML = "" + xmlHttp.responseText;
                        document.getElementById("sp4").innerHTML = "" + xmlHttp.responseText;
                    } 
                    else 
                    {                        
                         document.getElementById("sp4").innerHTML =  " no suggestions";
                    }
                
                }     else 
                {
                    xmlHttp.close();
                }                
            }
            if((xmlHttp.readyState==0)||(xmlHttp.readyState==1)||(xmlHttp.readyState==2)||(xmlHttp.readyState==3))
            {
                if(len3 == loc_len3)
                {
                    //document.getElementById("sp4").innerHTML ="<center><img src='../../images/popcorn_loading.gif'></center>";                
                    document.getElementById('sp4').innerHTML = "<center><img src='/images/ajax-loader-small.gif'></center>";
                } 
                else 
                {
                    xmlHttp.close();
                }
            }
        }
        xmlHttp.open("GET",txtStr01,true);
        xmlHttp.send(null);
    }
}

/**
  * Refreshes display of the reference data
  */       
function toggle_references(display)
{   
  if(display == "show")
  {
    document.getElementById("show_ref").style.display = "block";
    document.getElementById("hide_ref").style.display = "none";
  }
  else
  {
    document.getElementById("show_ref").style.display = "none";
    document.getElementById("hide_ref").style.display = "block";
  }
}

/**
  * Refreshes display of the project data
  */
function toggle_prj(display)
{   
  if(display == "show")
  {
    document.getElementById("show_prj").style.display = "block";
    document.getElementById("hide_prj").style.display = "none";
  }
  else
  {
    document.getElementById("show_prj").style.display = "none";
    document.getElementById("hide_prj").style.display = "block";
  }
}
