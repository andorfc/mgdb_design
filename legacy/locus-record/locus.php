<?PHP
  $bauplan->includeCss('/css/background_dynamic.css');
  $mgdb->get('body')->get('record_name')->replace('Locus');
  $mgdb->get('body')->get('record_name_url')->replace('locus');
?>