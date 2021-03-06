<?php
/*
NCBI Eutils class

Getting content from PubMed EUtilities
See: http://www.ncbi.nlm.nih.gov/books/NBK25500/

Used by other extensions:
	PMIDonDemand
	ProcessCite
*/

class PMIDeFetch{

	var $pmidObj;
	var $error_msg;

	function __construct($pmid){
		#accept different formats but generate just the numeric id
		$pmid = trim(str_replace("PMID:","", $pmid));
		# try to get from cache
		try{
			$pmidXML = $this->get_from_cache($pmid); #die(var_dump($pmidXML));
			if ($pmidXML != false) $this->pmidObj = new SimpleXMLElement($pmidXML);
		} catch (Exception $e){
			#$this->error_msg = "failed to valid xml from cache $e";
			$pmidXML = false;
		}
		# if it's not in the cache, or if it's just a docsum, fetch it from NCBI
		if(!$pmidXML || !is_object($this->pmidObj->DocSum)){
			$pmidXML = $this->get_from_eutils($pmid);
		}
		if ($pmidXML == '') return false;
		try{
			$this->pmidObj = new SimpleXMLElement($pmidXML);
		}catch(Exception $e){
			throw new MWException( "can't load paper. Please check the PMID and/or try again later" );
			exit;
		}
	}

	function get_from_eutils($pmid){
		$email = 'ecoliwiki@gmail.com';
		$tool = 'MediaWiki_ProcessCite_extension';
		global $wgEmergencyContact;
		global $wgPasswordSender;
		global $wgSitename;

		if($wgEmergencyContact != '')
		{	$email = $wgEmergencyContact;
		}
		elseif($wgPasswordSender != '')
		{	$email = $wgPasswordSender;
		}

		if($wgSitename != '')
		{	$tool = $wgSitename . '_ProcessCite_extension';
		}

		$url = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi".
		"?db=pubmed&id=$pmid&retmode=xml&email=".$email."&tool=".$tool;
		# need to handle failed connection
		#echo "trying EUtils...\n";
		$pmidXML = file_get_contents( $url );
		if($pmidXML != ''){
			$this->save_to_cache($pmid, $pmidXML);
			return $pmidXML;
		}else{
			$this->error_msg = "could not retrieve XML for PMID:$pmid from NCBI\n";
			return false;
		}
	}

	function article(){
		if(!is_object($this->pmidObj->PubmedArticle->MedlineCitation)) return false;
		return $this->pmidObj->PubmedArticle->MedlineCitation->Article;
	}

	/*
	return authors as an array.
	*/
	function authors(){
		$authors = array();
		$tags = array('LastName', 'Initials', 'Suffix', 'CollectiveName');
		if(is_object($this->article())){
			foreach($this->article()->AuthorList->Author as $auth){
				foreach ($tags as $tag){
					$$tag = '';
					if(isset($auth->$tag)) $$tag = (string)$auth->$tag;
				}
				$cite_name = 'Author unknown';
				if($LastName != "") $cite_name = "$LastName $Initials";
				if($Suffix != "") $cite_name .= " $Suffix";
				if($CollectiveName != "") $cite_name = "$CollectiveName";

/*				$authors[] = array(
					'Last' => $LastName,
					'Initials' => $Initials,
					'CollectiveName' => $CollectiveName,
					'Cite_name' => $cite_name
				);
*/
				$authors[] = $cite_name;
			}
		}
		return $authors;
	}

	function title(){
		if (!$this->article()){
			return ($this->error_msg);
		}
		return (string)$this->article()->ArticleTitle;
	}

	function journal(){
		return (string)$this->article()->Journal->ISOAbbreviation;
	}

	function journal_fullname(){
#	<Item Name="FullJournalName" Type="String">Genome research</Item>
		return (string)$this->article()->Journal->Title;
	}

	function volume(){
		return (string)$this->article()->Journal->JournalIssue->Volume;
	}

	function issue(){
		return (string)$this->article()->Journal->JournalIssue->Issue;
	}

	function pages(){
		return (string)$this->article()->Pagination->MedlinePgn;
	}

	function year(){
		return (string)$this->article()->Journal->JournalIssue->PubDate->Year;
	}

	/*
	return abstract text as string. Can't use abstract for the method name because it is a reserved word.
	*/
	function abstract_text(){
		return (string)$this->article()->Abstract->AbstractText;
	}

	function pubmed_data(){
		return $this->pmidObj->PubmedArticle->PubmedData;
	}

	function xrefs(){
		if (!is_object($this->pmidObj)) return false;
		$arr = array();
		if(is_object ($this->pubmed_data())){
			$xrefs = $this->pubmed_data()->ArticleIdList;
			foreach ($xrefs->ArticleId as $xref){
				$arr[(string)$xref->attributes()] = (string)$xref;
			}
		}
		return $arr;
	}

	function mesh(){
		$arr = array();
		if (is_object($this->pmidObj->PubmedArticle->MedlineCitation->MeshHeadingList)){
			$mesh_list = $this->pmidObj->PubmedArticle->MedlineCitation->MeshHeadingList; #print_r($mesh_list);
			foreach($mesh_list->MeshHeading as $mesh_item){
				$base_heading = (string)$mesh_item->DescriptorName;
				switch ($mesh_item->QualifierName->count()){
					case 0:
						$arr[] = "$base_heading";
						break;
					case 1:
						$arr[] = "$base_heading/".(string)$mesh_item->QualifierName;
						break;
					default:
						foreach ($mesh_item->QualifierName as $qualifier){
							$arr[] = "$base_heading/$qualifier";
					}
				}
			}
		}
		return $arr;
	}

	function citation(){
		$authorlist = array();
		foreach($this->authors() as $auth){
			$authorlist[] = $auth['Cite_name'];
		}
		return array(
//			'Authors' 	=> implode(',', $authorlist),
			'Authors'	=> $this->authors(),
			'Year'   	=> $this->year(),
			'Title'    	=> $this->title(),
			'JournalAbbrev'   => $this->journal(),
			'JournalFullName'   => $this->journal_fullname(),
			'Volume'   	=> $this->volume(),
			'Pages'   	=> $this->pages(),
			'Abstract' 	=> $this->abstract_text(),
			'xrefs' 	=> $this->xrefs(),
//			'doi'       => $this->doi(),
//			'pmc'       => $this->pmc(),
		);
	}

	function dump(){
		print_r($this->pmidObj);
	}

	function get_from_cache($pmid){
		$file = $this->set_cache_path($pmid);
		if(is_file($file)){
			$xml = file_get_contents($file);
			if(trim($xml) == '') return false;
			return $xml;
		}
		return false;
	}

	function save_to_cache($pmid, $xml){
		$file = self::set_cache_path($pmid);
		#echo "$file\n\n";
		file_put_contents($file, $xml);
		return true;
	}

	/*
	Creates subdirectories as needed to split the saved PMID XML files into groups based on the first 4 digits of the ID.
	*/
	function set_cache_path($pmid){
		global $extEfetchCache;
		if(!is_dir( $extEfetchCache)){
			    trigger_error("Cache directory $extEfetchCache not set");
			    $this->error_msg = 'Cant find $extEfetchCache. if you are seeing this message, please contact a site admin';
			    return false;
			}
		$pmid = str_replace('PMID', '', $pmid);
		if(strlen($pmid) < 4) str_pad($pmid, 4, '0');
		$subdir = substr($pmid, 0, 2)."/".substr($pmid, 2, 2);
		if (!is_dir("$extEfetchCache/".substr($pmid, 0, 2))){
			mkdir("$extEfetchCache/".substr($pmid, 0, 2));
		}
		if (!is_dir("$extEfetchCache/$subdir")){
			mkdir("$extEfetchCache/$subdir");
		}
		return "$extEfetchCache/$subdir/PMID$pmid.xml";
	}



}
