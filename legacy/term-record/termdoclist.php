

>>>>>>>>>>>>> OBSOLETE <<<<<<<<<<<<<<<


<?PHP

$term_type = getCGIParam("term_type", "G", false);
$id = getCGIParam("id", "G", false);
$tmpl->get('term_type')->replace($term_type);
$tmpl->get('id')->replace($id);
$mgdb->get('body')->get('record_name')->replace('Terms For Reference');
$mgdb->get('body')->get('record_name_url')->replace('termdoclist');
?>