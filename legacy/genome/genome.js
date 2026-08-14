function setGenomeTab(which) {
  if (which == 'browser_tab') {
    if ($('#detail_tab').length > 0) {
      document.getElementById('detail_tab').style.display='none';
    }
    document.getElementById('metadata_tab').style.display='none';
    document.getElementById('browser_tab').style.display='inline';
  }
  else if (which == 'detail_tab') {
    if ($('#detail_tab').length > 0) {
      document.getElementById('browser_tab').style.display='none';
    }
    document.getElementById('metadata_tab').style.display='none';
    document.getElementById('detail_tab').style.display='inline';
  }
  else if (which == 'metadata_tab') {
    if ($('#detail_tab').length > 0) {
      document.getElementById('browser_tab').style.display='none';
    }
    document.getElementById('metadata_tab').style.display='inline';
    document.getElementById('detail_tab').style.display='none';
  }
}//setRecordTab
