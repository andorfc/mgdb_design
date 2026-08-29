<?PHP
	  $subDomainArray = explode(".",$_SERVER['HTTP_HOST']);
	  $subdomain = 'english';
	if(strtolower($subDomainArray[0]) == "chinese" || strtolower($subDomainArray[0]) == "china"){
		$subdomain = 'chinese';
	}
	switch($subdomain) {
		case 'english':
			$mgdb->get('home_name')->replace("Home");
			$mgdb->get('about_name')->replace("About");
			$mgdb->get('community_name')->replace("Community");
//			$mgdb->get('gb_name')->replace("Genome Browsers");
			$mgdb->get('genome_name')->replace("Genomes");
			$mgdb->get('tools_name')->replace("Tools");
			$mgdb->get('dc_name')->replace("Data Hubs");
			$mgdb->get('search_name')->replace("Search");
			$mgdb->get('feedback_name')->replace("Feedback");
			
			$mgdb->get('footer_contact')->replace("Contact");
			$mgdb->get('footer_cite')->replace("Please cite us!");
			
			$mgdb->get('search_value')->replace("Search");
			
			$mgdb->get('topright_download')->replace("Download");
			$mgdb->get('topright_preferences')->replace("Preferences");
			$mgdb->get('topright_logout')->replace("Log out");
			$mgdb->get('topright_login')->replace("Log in/Create account");
			$mgdb->get('logo-id')->replace("logo");
			$mgdb->get('translate_wording')->replace("Chinese Version (中文版)");
			$mgdb->get('translate_site')->replace("chinese.maizegdb.org");
			
			break;
			
		case 'chinese':
			$mgdb->get('home_name')->replace("主页");
			$mgdb->get('about_name')->replace("简介");
			$mgdb->get('community_name')->replace("社区");
//			$mgdb->get('gb_name')->replace("基因组浏览器");
//			$mgdb->get('genome_name')->replace("Genomes");
			$mgdb->get('tools_name')->replace("工具");
			$mgdb->get('dc_name')->replace("数据中心");
			$mgdb->get('search_name')->replace("搜索");
			$mgdb->get('feedback_name')->replace("反馈");
			
			$mgdb->get('footer_contact')->replace("联系人");
			$mgdb->get('footer_cite')->replace("请引用我们！");
			
			$mgdb->get('search_value')->replace("搜索");
			
			$mgdb->get('topright_download')->replace("下载");
			$mgdb->get('topright_preferences')->replace("Preferences");
			$mgdb->get('topright_logout')->replace("Log out");
			$mgdb->get('topright_login')->replace("登陆/注册");
			
			$mgdb->get('server-url')->replace("http://chinese.maizegdb.org");
			$mgdb->get('logo-id')->replace("logo_chinese");
			$mgdb->get('translate_wording')->replace("English Version (英文版)");
			$mgdb->get('translate_site')->replace("www.maizegdb.org");
		
			break;
		
	default:
			
	}
?>
