function citation_collapse($a)
{
  var id1='expanded_'+$a;
  var id2='collapsed_'+$a;
  document.getElementById(id1).style.display = 'none';
  document.getElementById(id2).style.display = 'inline';
}

function citation_expand($a)
{
  var id1='expanded_'+$a;
  var id2='collapsed_'+$a;
  document.getElementById(id1).style.display = 'inline';
  document.getElementById(id2).style.display = 'none';
}

function hotnewpapers(i)
{
  var url = "/hot_new_papers?row="+i;
  alert(url);
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
    alert('inside the outer loop');
    if (xmlhttp.readyState==4 && xmlhttp.status==200)
    {
        //var json = eval('(' + xmlhttp.responseText + ')');
        alert('inside the inner loop');
    
    }
  }

  xmlhttp.open("GET",url,true);
  xmlhttp.send();
}

function ChangeColor(tableRow, highLight)
{
  if (highLight) {
    tableRow.className = 'lite_green_background_2'; 
  }
  else {
    tableRow.className = 'lite_green_background_1';
  }
}

function DoNav(i,j)
{
  document.location.href = "/hot_new_papers?row="+i+"&sort="+j;
}
