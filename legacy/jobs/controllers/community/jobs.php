<?PHP
/* file: jobs.php
 *
 * purpose: display current job listings
 *
 * history:
 *   summer, 2012  eksc  ported to bauplan
 *   12/17/12      eksc  improved(?) handling of job data and added job 
 *                       submission
 */
   include_once('./include/mail.php');
   
   $jobs = array(
/*template
     array('Job Title'                => '',
           'Date Posted'              => '',
           'Apply By'                 => '',
           'Location'                 => '',
           'Department'               => '',
           'Degree Required'          => '',
           'Area of Study'            => '',
           'Description'              => '',
           'Required Qualifications'  => '',
           'Preferred Qualifications' => '',
           'Application Instructions' => '',
           'Job Announcement Number'  => '',
           'Full Announcement'        => '',
           'Contact'                    => '',
          ),
          
*/
array('Job Title'                => 'Postdoctoral fellow',
           'Date Posted'              => 'July 26, 2026',
           'Apply By'                 => '',
           'Location'                 => 'Athens, Georgia, USA',
           'Department'               => '',
           'Degree Required'          => 'Doctorate',
           'Area of Study'            => '',
           'Description'              => 'The <a href="https://nelmslab.org/TFs" target="_blank">Nelms Lab</a> at the University of Georgia is seeking postdoctoral researchers interested in cell fate reprogramming and gene regulation during reproduction in maize.<br><br>

We combine high-throughput functional screening, genetics, genomics, and microscopy to study cell fate decisions and haploid gene regulation in maize. Current projects include: (1) systematic discovery of transcription factors that reprogram cell identity, using a robotics-assisted platform for TF functional screening at genome scale (<a href="https://nelmslab.org/TFs" target="_blank">https://nelmslab.org/TFs</a>); and (2) investigating the mechanisms and evolutionary consequences of haploid genome activation during pollen development.<br><br>

This work is supported by NSF and NIH awards. We welcome candidates whose interests align with either project area, as well as those seeking to develop independent directions within the lab\'s broader research program.

',
           'Qualifications'  => 'Ph.D. in plant biology, maize genetics, genomics, cell biology, or a related field. Experience in any combination of plant tissue culture, transformation, microscopy, or developmental genetics is valued.
',
           'Preferred Qualifications' => '',
           'Application Instructions' => 'Start dates are flexible. To apply, send a CV and brief cover letter describing your research background and why you are interested in joining the lab to <a href="mailto:nelms@uga.edu">Brad Nelms</a>.
',
           'Job Announcement Number'  => '',
           'Full Announcement'        => '',
           'Contact'                    => 'Contact Brad Nelms at <a href="mailto:nelms@uga.edu">nelms@uga.edu</a> for more information.',
          ),
array('Job Title'                => 'Postdoctoral research fellow',
           'Date Posted'              => 'April 23rd, 2026',
           'Apply By'                 => '',
           'Location'                 => 'College Station, Texas, USA',
           'Department'               => '',
           'Degree Required'          => 'Doctorate',
           'Area of Study'            => 'Plant Biology, Genetics, Molecular Biology, Plant Breeding, Horticulture, Chemistry, Plant Physiology, or closely related field.',
           'Description'              => '<ul style="margin-left: 20px">
           <li>Participate in a collaborative team research project aiming at discovering the impact of surfactant-like chemical in plant-soil interaction</li>
<li>Prepare, collect and organize plant samples for transcriptome and chemical analysis.</li>
<li>Prepare libraries for transcriptome, and conduct various downstream analyses, such as differential gene expression and expression Quantitative Trait Loci (eQTL) analyses. </li>
<li>Conduct various molecular biology experiments to validate the results bioinformatics.</li>
<li>Perform HPLC and other analytical methods to identify root secreted chemicals. </li>
<li>Evaluate data and summarize results for preparation in peer reviewed publications including use of simple to complex statistical analysis.</li>
<li>Compose and write scientific publications, progress and annual/final reports for grants.</li>
<li>Supervise undergraduate and/or graduate students and other lab personnel in research.</li>
<li>Other related duties as required.</li>
</ul>
',
           'Required Qualifications'  => '<ul style="margin-left: 20px">
           <li>Excellent written and verbal communication skills to communicate with a variety of stakeholders, for example, but not limited to, faculty, staff, students, funders, and peer scientists</li>
<li>Ability to multi-task and work cooperatively with others</li>
<li>Ability to perform independent research</li>
<li>Good organizational skills and ability to coordinate research and work in research teams</li>
<li>Ability to travel independently or in groups</li>
</ul>
',
           'Preferred Qualifications' => '<ul style="margin-left: 20px;">
           <li>Two (2) years of related professional experience.</li>
<li>Experience in Bioinformatics</li>
<li>Experience with cultivating plants in greenhouse and growth chambers</li>
<li>Experience in basic molecular biology techniques such as cloning and PCR</li>
<li>Experience in conducting wet lab procedures to prepare libraries</li>
<li>Formulating hypothesis, design experiments, handling scientific data, perform statistical analysis, and writing scientific reports</li>
<li>Experience in developing and writing publications for peer-reviewed journals</li>
<li>Experience in QTL analysis using genetic markers in plants</li>
<li>Experience in analytical chemistry</li>
<li>Ability to prepare, collect and organize plant samples for genetic and bioinformatics analyses</li>
<li>Ability to conduct molecular biology work</li>
<li>Ability to work with various bioinformatics pipelines</li>
<li>Ability to operate liquid chromatography</li>
<li>Ability to evaluate data and summarize results for preparation in peer reviewed publications including use of simple to complex statistical analysis</li>
<li>Ability to compose and write scientific publications, progress and annual/final reports for grants</li>
<li>Ability to supervise undergraduate and/or graduate students and other lab personnel in research</li>

</ul>
',
           'Application Instructions' => 'Submit the application directly to <br>
<a href="https://tamus.wd1.myworkdayjobs.com/en-US/AgriLife_Research_External/details/Postdoctoral-Research-Associate_R-089834-3?q=R-089834" target="_blank">https://tamus.wd1.myworkdayjobs.com/en-US/AgriLife_Research_External/details/Postdoctoral-Research-Associate_R-089834-3?q=R-089834</a>
',
           'Job Announcement Number'  => '',
           'Full Announcement'        => '',
           'Contact'                    => 'Contact Sakiko Okumoto at <a href="mailto:sokumoto@tamu.edu">sokumoto@tamu.edu</a> for more information.',
          ),
array('Job Title'                => 'Postdoctoral Research Associate',
           'Date Posted'              => 'February 24th, 2026',
           'Apply By'                 => '',
           'Location'                 => 'Clemson, South Carolina',
           'Department'               => '',
           'Degree Required'          => 'Doctorate',
           'Area of Study'            => 'Ph.D. Genetics or Plant Biology or similar',
           'Description'              => 'This position is supported by an NSF-funded project on the systems genetics of leaf senescence in maize, with an emphasis on source-sink regulation and the genetic mechanisms that shape nitrogen partitioning and remobilization. The postdoc will lead and coordinate maize field genetics and physiology experiments, including experimental design, phenotyping, tissue sampling, and data quality control. The postdoc will also conduct wet-lab molecular and functional genomics work, including transcriptomics, metabolomics, and gene validation (experience with single-cell omics is a plus). In collaboration with Clemson and Cornell/USDA-ARS teams, the postdoc will contribute to AI/ML- and LLM-enabled data integration, gene discovery, and regulatory network inference. The role includes substantial upfront maize field work, followed by a transition into wet-lab and computational analyses.
',
           'Required Qualifications'  => 'Strong molecular biology skills and the ability to execute wet-lab workflows independently. Experience with plant field research and/or phenotyping (maize preferred). Proficiency in R and/or Python and comfort working with biological datasets. Ability to work effectively in a collaborative, interdisciplinary environment. This position is available immediately, with an ideal start date before the summer field season.',
           'Application Instructions' => 'Apply online at <a href="http://apply.interfolio.com/181030" target="_blank">http://apply.interfolio.com/181030</a>',
           'Job Announcement Number'  => '',
           'Full Announcement'        => '',
           'Contact'                    => 'Contact Rajan Sekhon at <a href="mailto:sekhon@clemson.edu">sekhon@clemson.edu</a> for more information.',
          )
  );
  
  $tmpl = $mgdb->get('body')->load('templates/community/jobs.bau');

  if (getCGIParam('SubmitJob', 'P', false)) {
    submitJob($tmpl);
  }
  
  // Strip out summary information for table
  $job_summaries = array();
  $job_sections  = array();
  $job_details   = array();
  $job_num = 1;
  foreach ($jobs as $job) {
    array_push($job_summaries, 
               array('DatePosted' => $job['Date Posted'],
                     'ApplyBy'    => $job['Apply By'],
                     'Title'      => $job['Job Title'],
                     'Location'   => $job['Location'],
                     'JobNum'     => $job_num,
                    ));
                    
    array_push($job_sections,
               array('JobNo' => $job_num,
                     'JobTitle'=>$job['Job Title']
                    ));
                    
    $job_detail = array();
    foreach ($job as $detail => $value) {
      if ($value != '') {
        if ($detail == 'Full Announcement') {
          $link = "<a href=\"$value\">$value</a>";
          array_push($job_detail,
                     array('SubTitle' => 'Full Announcement',
                           'SubTitle_detail' => $link));
        }
        else {
          array_push($job_detail, 
                     array('SubTitle' => $detail, 'SubTitle_detail' => $value));
        }
      }
    }//each job detail
    array_push($job_details, $job_detail);
    
    $job_num++;
  }//each job

  $year = date("Y");
  $tmpl->get('cur_year')->replace($year);
  $tmpl->get('next_year')->replace($year+1);
  $tmpl->get('jobs-create-table-top')->loop($job_summaries);

  $inner_sections = $tmpl->get('jobs-text-bottom')->loop_array($job_sections);
  for ($i=0; $i<count($inner_sections); $i++) {
    $inner_sections[$i]->get('jobs-text-bottom2')->loop($job_details[$i]);
  }//each job section
  
  
  function submitJob($tmpl) {
    // Verify inputs
    $errors = array();
    if (!($contactName=getCGIParam('ContactName', 'P', false))) {
      array_push($errors, "You haven't given a contact name");
    }
    if (!($contactEmail=getCGIParam('ContactEmail', 'P', false))) {
      array_push($errors, "You haven't given a contact e-mail address");
    }
    if (!($jobTitle=getCGIParam('JobTitle', 'P', false))) {
      array_push($errors, "You haven't given the job title");
    }
    if (!($location=getCGIParam('Location', 'P', false))) {
      array_push($errors, "You haven't given the job location");
    }
    if (!($responsibilities=getCGIParam('Responsibilities', 'P', false))) {
      array_push($errors, "You haven't listed the job responsibilities");
    }
    
    //OtherNotes is a dummy field meant to be invisible to humans but still detectable by bots
    if ($OtherNotes=getCGIParam('OtherNotes', 'P', false)) {
      array_push($errors, "You have been identified as a bot.");
    }
    
    if (count($errors) > 0) {
      $tmpl->get('addjobdisplay')->replace('block');
      $tmpl->get('error')->replace(join("<br>", $errors));
      $tmpl->get('job-submit-error')->unmute();
      
      $tmpl->get('ContactName')->replace($contactName);
      $tmpl->get('ContactEmail')->replace($contactEmail);
      $tmpl->get('JobTitle')->replace($jobTitle);
      $tmpl->get('Location')->replace($location);
      $tmpl->get('Responsibilities')->replace($responsibilities);

      $tmpl->get('AreaOfStudy')->replace(getCGIParam('AreaOfStudy', 'P', ''));
      $tmpl->get('OtherRequirements')->replace(getCGIParam('OtherRequirements', 'P', ''));
      
      return;
    }

    else {
      // construct and send e-mail
      $message = "Job title: $jobTitle\n\n";
      $date = array(getCGIParam('ApplyBy_month', 'P', ''), 
                    getCGIParam('ApplyBy_day', 'P', ''),
                    getCGIParam('ApplyBy_year', 'P', ''));
      $message .= "Apply by: " . implode(' ', $date) . "\n\n";
      $message .= "Location: " . getCGIParam('Location', 'P', '') . "\n\n";
      $message .= "Degree Required: " . getCGIParam('DegreeRequired', 'P', '') . "\n\n";
      $message .= "Area of Study: " . getCGIParam('AreaOfStudy', 'P', '') . "\n\n";
      $message .= "Description: " . getCGIParam('Responsibilities', 'P', '') . "\n\n";
      $message .= "Other Requirements: " . getCGIParam('OtherRequirements', 'P', '') . "\n\n";
      
      $message .= "Contact $contactName at $contactEmail for more information.\n\n";

      $sendtos = array("portwood@iastate.edu",
                       "john.portwood@usda.gov",
                       "carson.andorf@usda.gov",
                       "portwoodii@gmail.com",
                       "mgdbtech1@gmail.com"
      );
      $sendfrom = "mgdb@iastate.edu"; //jp - having the contact email as sender sometimes routes the mail to my junk folder
      $subject = "[MAIZEGDB-JOB] New Job Posting";
      
      $fp = fopen("./data/jobs/newjob_" . rand(), "w");
      fwrite($fp, $message);
      if (strlen($message) > 300) { //large postings cause the email notifications to fail, so write them to a file on the server for now
        $message = "New job posting, check /data/jobs on production server";
      }
      
      foreach ($sendtos as $sendto) {
        send_email($sendto, $sendfrom, $subject, $message);
      }
      
      
      // turn on confirmation
      $tmpl->get('job-submitted')->unmute();
    }
  }//submitJob()
?>
