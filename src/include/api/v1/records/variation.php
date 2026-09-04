<?php
/* A complete variation record in one cacheable, parameterized JSON response. */
if (!defined('MGDB_API')) { http_response_code(404); exit; }

$available_sections = array('overview', 'phenotypes', 'stocks', 'related', 'annotations', 'images', 'references');
$wanted = MgdbApi::sections($available_sections);
$want = array_flip($wanted);
$max_items = MgdbApi::maxItems();

$found_id = variationResolveId($DBConn, $api_identifier);
MgdbApi::countQuery(2);
if ($found_id === false) {
  MgdbApi::problem(404, 'record-not-found', 'Variation not found',
    'No variation record matches that id, name, or synonym.', array('identifier' => $api_identifier));
}

/* All single-valued attributes and their labels are fetched together. */
$record = retrieve_row(make_query($DBConn, "
  SELECT v.id, v.name, i.curation_lvl, v.alleledescriptor, v.function,
         v.snp, v.strand, v.reference_version, v.posttext_var,
         ty.id AS type_id, ty.name AS type_name,
         dom.id AS dominance_id, dom.name AS dominance_name,
         viable.id AS viability_id, viable.name AS viability_name,
         sp.id AS species_id, sp.species AS species_name,
         loc.id AS locus_id, loc.name AS locus_name, loc.full_name AS locus_full_name,
         ib.id AS inbred_id, ib.name AS inbred_name,
         prog.id AS progenitor_id, prog.name AS progenitor_name
  FROM mgdb.variation v
    INNER JOIN mgdb.id_num i ON i.id = v.id
    LEFT JOIN mgdb.term ty ON ty.id = v.type
    LEFT JOIN mgdb.term dom ON dom.id = v.dominance
    LEFT JOIN mgdb.term viable ON viable.id = v.viability
    LEFT JOIN mgdb.species sp ON sp.id = v.species
    LEFT JOIN mgdb.locus loc ON loc.id = v.variationof
    LEFT JOIN mgdb.stock ib ON ib.id = v.inbred
    LEFT JOIN mgdb.stock prog ON prog.id = v.progenitorstock
  WHERE v.id = :id", 1, array('id' => $found_id)));
MgdbApi::countQuery();
if (!$record) {
  MgdbApi::problem(404, 'record-not-found', 'Variation not found',
    'The variation could not be retrieved.', array('identifier' => $api_identifier));
}

$id = (int) $record['id'];
$name = MgdbApi::text($record['name']);
$curation = (int) $record['curation_lvl'];
$status = $curation === 101 ? 'unavailable' : ($curation === 102 ? 'discontinued' : 'current');

$synonyms = array();
$sth = make_query($DBConn, "
  SELECT DISTINCT ON (LOWER(s.synonyms)) s.synonyms, s.authority,
         COALESCE(p.name, r.name) AS authority_name,
         CASE WHEN p.id IS NOT NULL THEN 'person' WHEN r.id IS NOT NULL THEN 'reference' END AS authority_type
  FROM mgdb.synonyms s
    LEFT JOIN mgdb.person p ON p.id = s.authority
    LEFT JOIN mgdb.reference r ON r.id = s.authority
  WHERE s.id = :id AND s.synonyms IS NOT NULL AND btrim(s.synonyms) <> ''
    AND COALESCE(s.del, '') <> 'Y'
  ORDER BY LOWER(s.synonyms), s.auto_num", 1, array('id' => $id));
MgdbApi::countQuery();
while ($row = retrieve_row($sth)) {
  if ($name !== null && strcasecmp(trim($row['synonyms']), $name) === 0) { continue; }
  $authority = null;
  if ($row['authority'] !== null) {
    $path = $row['authority_type'] === 'reference' ? '/data_center/reference?id=' : '/person?id=';
    $authority = MgdbApi::ref($row['authority_type'] ?: 'authority', $row['authority'], $row['authority_name'], $path);
  }
  $synonyms[] = array('name' => MgdbApi::text($row['synonyms']), 'authority' => $authority);
}

/* One indexed counts query labels tabs and drives the cached metric cards. */
$counts_row = retrieve_row(make_query($DBConn, "
  SELECT
    (SELECT COUNT(*) FROM mgdb.var_pheno_effects x WHERE x.id = :a) AS phenotypes,
    (SELECT COUNT(DISTINCT s.id) FROM (
       SELECT id FROM mgdb.stock_genotypic_var WHERE variation = :b
       UNION ALL SELECT id FROM mgdb.stock_karyotypic_var WHERE karyotypic_var = :c
       UNION ALL SELECT id FROM mgdb.stock_molecular_var WHERE molecular_var = :d
     ) s) AS stocks,
    (SELECT COUNT(*) FROM mgdb.relation x WHERE x.id = :e) +
    (SELECT COUNT(*) FROM mgdb.var_related_alleles x WHERE x.id = :f) AS related_variations,
    (SELECT COUNT(*) FROM mgdb.recomb_alleles x WHERE x.allele = :g) AS recombinations,
    (SELECT COUNT(*) FROM mgdb.gel_pattern_haploalleles x WHERE x.haploallele = :h) AS gel_patterns,
    (SELECT COUNT(*) FROM mgdb.var_point x WHERE x.id = :i) AS breakpoints,
    (SELECT COUNT(*) FROM mgdb.genome_pos x WHERE x.id = :j) AS positions,
    (SELECT COUNT(*) FROM mgdb.ext_db_key x WHERE x.id = :k AND COALESCE(x.obsolete, '') <> 'Y') AS offsite,
    (SELECT COUNT(DISTINCT (x.url, x.caption)) FROM mgdb.web_image x WHERE x.id = :l AND COALESCE(x.curation_lvl, 0) = 0) AS images,
    (SELECT COUNT(*) FROM mgdb.id_reference x INNER JOIN mgdb.id_num i ON i.id=x.reference AND i.curation_lvl=0 WHERE x.id = :m) AS references_count,
    (SELECT COUNT(*) FROM mgdb.memo x WHERE x.id = :n) +
    (SELECT COUNT(*) FROM mgdb.id_memo x WHERE x.id = :o) +
    (SELECT COUNT(*) FROM mgdb.annotation x WHERE x.id = :p AND x.curation_lvl = 0) AS annotations
  ", 1, array('a'=>$id,'b'=>$id,'c'=>$id,'d'=>$id,'e'=>$id,'f'=>$id,'g'=>$id,'h'=>$id,
    'i'=>$id,'j'=>$id,'k'=>$id,'l'=>$id,'m'=>$id,'n'=>$id,'o'=>$id,'p'=>$id)));
MgdbApi::countQuery();
$counts = array('synonyms'=>count($synonyms));
foreach (array('phenotypes','stocks','related_variations','recombinations','gel_patterns','breakpoints','positions','offsite','images','references_count','annotations') as $key) {
  $counts[$key === 'references_count' ? 'references' : $key] = MgdbApi::int($counts_row[$key]);
}

$sections = array();
$truncated = array();

if (isset($want['overview'])) {
  $terms = array('mutagens'=>array(), 'mutation_types'=>array(), 'properties'=>array());
  $sth = make_query($DBConn, "
    SELECT kind, term_id, term_name FROM (
      SELECT 'mutagens'::text kind, t.id term_id, t.name term_name
      FROM mgdb.var_mutagen x JOIN mgdb.term t ON t.id=x.mutagen WHERE x.id=:m
      UNION ALL
      SELECT 'mutation_types', t.id, t.name
      FROM mgdb.var_mutation_type x JOIN mgdb.term t ON t.id=x.mutation_type WHERE x.id=:u
      UNION ALL
      SELECT 'properties', t.id, t.name
      FROM mgdb.properties x JOIN mgdb.term t ON t.id=x.property WHERE x.id=:p
    ) term_values ORDER BY kind, LOWER(term_name)", 1, array('m'=>$id,'u'=>$id,'p'=>$id));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $terms[$row['kind']][] = MgdbApi::ref('term', $row['term_id'], $row['term_name']);
  }

  $positions = array();
  $sth = make_query($DBConn, "SELECT reference_seq, l_pos, r_pos, chr, linkage_group, source
    FROM mgdb.genome_pos WHERE id=:id ORDER BY reference_seq, l_pos LIMIT :lim", 1,
    array('id'=>$id, 'lim'=>$max_items));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $positions[] = array('reference_sequence'=>MgdbApi::text($row['reference_seq']),
      'chromosome'=>MgdbApi::text($row['chr']), 'start'=>MgdbApi::int($row['l_pos']),
      'end'=>MgdbApi::int($row['r_pos']), 'linkage_group'=>MgdbApi::int($row['linkage_group']),
      'source'=>MgdbApi::text($row['source']));
  }

  $sections['overview'] = array(
    'type'=>MgdbApi::ref('term',$record['type_id'],$record['type_name']),
    'species'=>MgdbApi::ref('species',$record['species_id'],$record['species_name']),
    'locus'=>MgdbApi::ref('locus',$record['locus_id'],$record['locus_name'],'/data_center/locus?id='),
    'locus_full_name'=>MgdbApi::text($record['locus_full_name']),
    'dominance'=>MgdbApi::ref('term',$record['dominance_id'],$record['dominance_name']),
    'viability'=>MgdbApi::ref('term',$record['viability_id'],$record['viability_name']),
    'allele_descriptor'=>MgdbApi::text($record['alleledescriptor']),
    'function'=>MgdbApi::text($record['function']), 'polymorphism'=>MgdbApi::text($record['snp']),
    'strand'=>MgdbApi::text($record['strand']),
    'inbred'=>MgdbApi::ref('stock',$record['inbred_id'],$record['inbred_name'],'/data_center/stock?id='),
    'progenitor_stock'=>MgdbApi::ref('stock',$record['progenitor_id'],$record['progenitor_name'],'/data_center/stock?id='),
    'mutagens'=>$terms['mutagens'], 'mutation_types'=>$terms['mutation_types'],
    'properties'=>$terms['properties'], 'genome_positions'=>$positions
  );
}

if (isset($want['phenotypes'])) {
  $items = array();
  $sth = make_query($DBConn, "SELECT DISTINCT p.id, p.name, LOWER(p.name) AS sort_name
    FROM mgdb.var_pheno_effects x JOIN mgdb.phenotype p ON p.id=x.pheno_effect
      JOIN mgdb.id_num i ON i.id=p.id AND i.curation_lvl=0
    WHERE x.id=:id ORDER BY sort_name LIMIT :lim", 1, array('id'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while ($row=retrieve_row($sth)) { $items[] = array('id'=>(int)$row['id'],'name'=>MgdbApi::text($row['name']),'html'=>'/data_center/phenotype?id='.(int)$row['id']); }
  $sections['phenotypes']=$items; $truncated['phenotypes']=$counts['phenotypes']>count($items);
}

if (isset($want['stocks'])) {
  $items=array();
  $sth=make_query($DBConn, "SELECT DISTINCT ON (s.id) s.id, s.name, s.available_from, p.name provider, x.association
    FROM (
      SELECT id, 'Genotypic'::text association FROM mgdb.stock_genotypic_var WHERE variation=:a
      UNION ALL SELECT id, 'Karyotypic' FROM mgdb.stock_karyotypic_var WHERE karyotypic_var=:b
      UNION ALL SELECT id, 'Molecular' FROM mgdb.stock_molecular_var WHERE molecular_var=:c
    ) x JOIN mgdb.stock s ON s.id=x.id JOIN mgdb.id_num i ON i.id=s.id AND i.curation_lvl=0
      LEFT JOIN mgdb.person p ON p.id=s.available_from
    ORDER BY s.id, x.association LIMIT :lim",1,array('a'=>$id,'b'=>$id,'c'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while($row=retrieve_row($sth)){$items[]=array('id'=>(int)$row['id'],'name'=>MgdbApi::text($row['name']),
    'association'=>$row['association'],'provider'=>MgdbApi::text($row['provider']),
    'available_from_stock_center'=>(int)$row['available_from']===25725,'html'=>'/data_center/stock?id='.(int)$row['id']);}
  $sections['stocks']=$items; $truncated['stocks']=$counts['stocks']>count($items);
}

if(isset($want['related'])){
  $variations=array();
  $sth=make_query($DBConn,"SELECT DISTINCT ON (v.id) v.id,v.name,x.relation
    FROM (SELECT related_id id,t.name relation FROM mgdb.relation r LEFT JOIN mgdb.term t ON t.id=r.relation WHERE r.id=:a
      UNION ALL SELECT allele,t.name FROM mgdb.var_related_alleles r LEFT JOIN mgdb.term t ON t.id=r.relation WHERE r.id=:b) x
    JOIN mgdb.variation v ON v.id=x.id JOIN mgdb.id_num i ON i.id=v.id AND i.curation_lvl=0
    ORDER BY v.id, x.relation LIMIT :lim",1,array('a'=>$id,'b'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while($row=retrieve_row($sth)){$variations[]=array('id'=>(int)$row['id'],'name'=>MgdbApi::text($row['name']),'relationship'=>MgdbApi::text($row['relation']),'html'=>'/data_center/variation?id='.(int)$row['id']);}

  $other=array();
  $sth=make_query($DBConn,"SELECT kind,record_id,name,detail,url FROM (
    SELECT 'Recombination'::text kind,r.id record_id,r.name,NULL::text detail,('/data_center/recombination?id='||r.id)::text url
      FROM mgdb.recomb_alleles x JOIN mgdb.recomb r ON r.id=x.id JOIN mgdb.id_num i ON i.id=r.id AND i.curation_lvl=0 WHERE x.allele=:a
    UNION ALL SELECT 'Gel pattern',g.id,g.name,NULL,('/data_center/gel_pattern?id='||g.id)::text
      FROM mgdb.gel_pattern_haploalleles x JOIN mgdb.gel_pattern g ON g.id=x.id JOIN mgdb.id_num i ON i.id=g.id AND i.curation_lvl=0 WHERE x.haploallele=:b
    UNION ALL SELECT 'External database',x.db_person,p.name,x.key,(u.url_prefix||x.key)::text
      FROM mgdb.ext_db_key x JOIN mgdb.person p ON p.id=x.db_person JOIN mgdb.person_url_prefix u ON u.id=x.db_person
      WHERE x.id=:c AND x.db_person<>59760 AND COALESCE(x.obsolete,'')<>'Y'
  ) related ORDER BY kind,name LIMIT :lim",1,array('a'=>$id,'b'=>$id,'c'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while($row=retrieve_row($sth)){$other[]=array('kind'=>$row['kind'],'id'=>MgdbApi::int($row['record_id']),'name'=>MgdbApi::text($row['name']),'detail'=>MgdbApi::text($row['detail']),'url'=>MgdbApi::text($row['url']));}

  $breakpoints=array();
  $sth=make_query($DBConn,"SELECT l.id,l.name,lg.name linkage_group,t.name arm,lc.value cytological_position,lc.map map_id
    FROM mgdb.var_point x JOIN mgdb.locus l ON l.id=x.point JOIN mgdb.id_num i ON i.id=l.id AND i.curation_lvl=0
      LEFT JOIN mgdb.linkage_group lg ON lg.id=l.linkage_group LEFT JOIN mgdb.term t ON t.id=l.arm
      LEFT JOIN LATERAL (SELECT value,map FROM mgdb.locus_coordinates WHERE id=l.id AND map>40027 AND map<40038 LIMIT 1) lc ON true
    WHERE x.id=:id ORDER BY l.name LIMIT :lim",1,array('id'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while($row=retrieve_row($sth)){$breakpoints[]=array('id'=>(int)$row['id'],'name'=>MgdbApi::text($row['name']),'linkage_group'=>MgdbApi::text($row['linkage_group']),'arm'=>MgdbApi::text($row['arm']),'cytological_position'=>MgdbApi::text($row['cytological_position']),'map_id'=>MgdbApi::int($row['map_id']),'html'=>'/data_center/locus?id='.(int)$row['id']);}
  $sections['related']=array('variations'=>$variations,'other_records'=>$other,'breakpoints'=>$breakpoints);
}

if(isset($want['annotations'])){
  $items=array();
  $sth=make_query($DBConn,"SELECT kind,label,text,authority_id,authority_name,modified FROM (
    SELECT 'Curator note'::text kind,COALESCE(NULLIF(t.name,'Not specified'),'Comment') label,m.memo text,m.source authority_id,COALESCE(p.name,r.name) authority_name,NULL::timestamp modified
      FROM mgdb.memo m LEFT JOIN mgdb.term t ON t.id=m.type_term LEFT JOIN mgdb.person p ON p.id=m.source LEFT JOIN mgdb.reference r ON r.id=m.source WHERE m.id=:a
    UNION ALL
    SELECT 'Curator note',COALESCE(NULLIF(t.name,'Not specified'),'Comment'),m.memo,m.source,COALESCE(p.name,r.name),NULL::timestamp
      FROM mgdb.id_memo im JOIN mgdb.memo m ON m.auto_num=im.memo_id LEFT JOIN mgdb.term t ON t.id=m.type_term LEFT JOIN mgdb.person p ON p.id=m.source LEFT JOIN mgdb.reference r ON r.id=m.source WHERE im.id=:b
    UNION ALL
    SELECT 'User annotation','Annotation',a.memo,aa.person_id,btrim(COALESCE(aa.first_name,'')||' '||COALESCE(aa.last_name,'')),a.mod_date
      FROM mgdb.annotation a JOIN mgdb.annotation_author aa ON aa.id=a.ann_author_id WHERE a.id=:c AND a.curation_lvl=0
  ) notes WHERE text IS NOT NULL AND btrim(text)<>'' ORDER BY kind,label LIMIT :lim",1,array('a'=>$id,'b'=>$id,'c'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while($row=retrieve_row($sth)){$items[]=array('kind'=>$row['kind'],'label'=>MgdbApi::text($row['label']),'text'=>MgdbApi::text($row['text']),'authority'=>MgdbApi::ref('person',$row['authority_id'],$row['authority_name'],'/person?id='),'modified'=>MgdbApi::text($row['modified']));}
  $sections['annotations']=$items; $truncated['annotations']=$counts['annotations']>count($items);
}

if(isset($want['images'])){
  $items=array(); $image_root=rtrim(isset($system['image_server_url'])?$system['image_server_url']:'', '/');
  $sth=make_query($DBConn,"SELECT DISTINCT ON (url,caption) url,caption,part,type FROM mgdb.web_image
    WHERE id=:id AND COALESCE(curation_lvl,0)=0 ORDER BY url,caption LIMIT :lim",1,array('id'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while($row=retrieve_row($sth)){$url=MgdbApi::text($row['url']); if($url!==null && !preg_match('/^https?:\\/\\//i',$url)){$url=$image_root.'/db_images/Variation/'.ltrim($url,'/');}
    $items[]=array('url'=>$url,'caption'=>MgdbApi::text($row['caption']),'part'=>MgdbApi::text($row['part']),'type'=>MgdbApi::text($row['type']));}
  $sections['images']=$items; $truncated['images']=$counts['images']>count($items);
}

if(isset($want['references'])){
  $items=array();
  /* Same shape the stock and gene product records return, so one reference
     card renders all three: the publication type for the badge, and the
     abstract the card previews. idx_reference_ab_id keeps the abstract
     subquery at about 0.1 ms per reference. */
  $sth=make_query($DBConn,"SELECT DISTINCT r.id,r.name,r.title,r.author_desc,r.year,r.doi,
      COALESCE(t.name,'General') relevance, t_type.name AS pub_type,
      (
        SELECT substring(regexp_replace(string_agg(
          concat_ws(' ', rab.abstract_1, rab.abstract_2), ' '
        ), '\s+', ' ', 'g') from 1 for 700)
        FROM mgdb.reference_abstract rab WHERE rab.id = r.id
      ) AS abstract
    FROM mgdb.id_reference x JOIN mgdb.reference r ON r.id=x.reference JOIN mgdb.id_num i ON i.id=r.id AND i.curation_lvl=0
      LEFT JOIN mgdb.term t ON t.id=x.contents
      LEFT JOIN mgdb.term t_type ON t_type.id=r.type
    WHERE x.id=:id ORDER BY r.year DESC NULLS LAST,r.id DESC LIMIT :lim",1,array('id'=>$id,'lim'=>$max_items));
  MgdbApi::countQuery();
  while($row=retrieve_row($sth)){
    /* The doi column is empty on most older records; some carry the DOI
       inside the citation text instead. Same extraction the stock and gene
       product resources use. */
    $doi = MgdbApi::text($row['doi']);
    if ($doi && preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', $doi, $m)) {
      $doi = $m[1];
    } elseif (preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', (string)$row['name'], $m)) {
      $doi = $m[1];
    } else {
      $doi = null;
    }
    $items[]=array('id'=>(int)$row['id'],'citation'=>MgdbApi::text($row['name']),'title'=>MgdbApi::text($row['title']),
      'authors'=>MgdbApi::text($row['author_desc']),'year'=>MgdbApi::int($row['year']),'doi'=>$doi,
      'pub_type'=>MgdbApi::text($row['pub_type']) ?: 'Journal article',
      'relevance'=>MgdbApi::text($row['relevance']),'abstract'=>MgdbApi::text($row['abstract']),
      'html'=>'/data_center/reference?id='.(int)$row['id']);
  }
  $sections['references']=$items; $truncated['references']=$counts['references']>count($items);
}

$attributes=array('name'=>$name,'status'=>$status,'type'=>MgdbApi::text($record['type_name']),'synonyms'=>$synonyms);
$links=array('html'=>MgdbApi::baseUrl().'/data_center/variation?id='.rawurlencode($api_identifier));
MgdbApi::send('variation',$id,$attributes,$sections,$links,array(
  'resolved_from'=>$api_identifier,'counts'=>$counts,'included_sections'=>$wanted,'truncated'=>$truncated
),300);
?>
