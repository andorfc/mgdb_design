/* file: uniformmu.js
 *
 * purpose: javascript for UniformMu page
 *
 * history:
 *  11/23/12  eksc  created
 */
 
function samples() {
  var w = window.open('', '', 'status=0,toolbar=0,location=0,menubar=0,directories=0,resizable=1,scrollbars=1,height=450,width=650');
  w.document.write('<h3>Samples</h3>');

  w.document.write('<b>Fasta</b>');
  w.document.write('<pre>');
  w.document.write(">gi|226918649|gb|FJ911423.1| Zea mays subsp. mays Mu tranposon insertion Mu1011038 flanking sequence\n");
  w.document.write("CCCACATACACACACACAAACAGTCAGTTCAGGAGAATGTAGCATCTGAATTTGACTAAATGGAGGCTTT\n");
  w.document.write("AAATGGTGAACATGAAAAAAGAAGTGCATT\n");
  w.document.write('</pre>');

  w.document.write('<b>Genbank IDs</b>');
  w.document.write('<pre>');
  w.document.write("FJ908760,FJ911175, FJ911162\n");
  w.document.write('</pre>');

  w.document.write('<b>Gene model cDNAs</b>');
  w.document.write('<pre>');
  w.document.write("GRMZM2G307823_T01, GRMZM2G304471_T01, GRMZM2G154664_T01\n");
  w.document.write('</pre>');

  w.document.write('<b>Consensus gene models</b>');
  w.document.write('<pre>');
  w.document.write("GRMZM2G307823, GRMZM2G358877, GRMZM2G154664\n");
  w.document.write('</pre>');
  
  w.document.write('<br>');
  w.document.write("<a href='javascript:window.close()'>close</a>");
}

