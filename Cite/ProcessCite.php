<?php
/*
 ProcessCite.php - An extension 'module' for the PagesOnDemand extension.
 @author Jim Hu (jimhu@tamu.edu)
 @version 0.2
 @copyright Copyright (C) 2007 Jim Hu
 @license The MIT License - http://www.opensource.org/licenses/mit-license.php

* Features

Retrieves ref text from the following sources
		PubMed
		GO dbxrefs

if RefType:ID in the key or the text string enclosed by <ref name=key>text</ref>
Either of these can be catenated with a semicolon-delimited list of dbxrefs to be added as links
 		Example: PMID:15849274.3BTAIR:Publication:501715204
The PMID should be the first element of this list

* Installation.

In addition to the usual, ProcessCite requires
1. The PMID extension to add the PMIDeFetch class
2. a hook in Cite.php

if not already present, add
 			wfRunHooks( 'CiteBeforeStackEntry', array( &$key, &$val ) );
 at the start of function stack

3. Internal configs to for commonly used refs stored in the wiki that are not recognized automatically e.g.
a. set the prefix you want to use
	$libtag = 'LIB'; # the prefix for commonly used references to be pulled from a page in the wiki

b. set the name of the page in the wiki (in the main namespace) where the commonly used refs are stored
	$lib_pageName = "$wgSitename Reference Library"; # the PAGENAMEE of the page where the commonly used references are stored
c. Edit that wikipage to store references as lines in the format refname|refinfo Example:

	Darwin_Origin|Darwin, Charles (1859) ''On the origin of species'' [http://www.gutenberg.org/etext/1228 etext at Project Gutenberg]
d. Set the location of a file of dbxref urls.

* Changes in version 0.2

Changed to use PMID extension to connect to NCBI eUtilities and manage XML caching.  Note that version 0.1 used esummary records, while version 0.2 uses the more complete XML from efetch, which includes abstract more information needed by PMIDonDemand.

Pushed to trunk
 */

if ( ! defined( 'MEDIAWIKI' ) ) die();

# Credits
$wgExtensionCredits['other'][] = array(
    'name'=>'ProcessCite',
    'author'=>'Jim Hu &lt;jimhu@tamu.edu&gt;',
    'description'=>'Generates reference text for papers in National Library of Medicine PubMed database.',
    'version'=>'0.2'
);

# Register hooks ('CiteBeforeStackEntry' hook is provided by a patch to the Cite extension).
# If hook not present in Cite, add it at the start of function stack

$wgHooks['CiteBeforeStackEntry'][] = 'wfProcessCite';
//require_once('/Volumes/SAS/local/wiki-extensions/trunk/PMID/class.PMIDeFetch.php');

$wgHooks['BeforePageDisplay'][] = 'pcBeforePageDisplay';

global $wgExtensionPath;
$wgAutoloadClasses['PMIDeFetch'] =  "$wgExtensionPath/PMID/class.PMIDeFetch.php";

$wgResourceModules['ext.ProcessCite'] = array(
'scripts' => 'ext.ProcessCite.js',
'localBasePath' => dirname( __FILE__ ),
'remoteExtPath' => 'Cite'
);


  /**
    * BeforePageDisplay hook
    *
    * Adds the modules to the page
    *
    * @param $out OutputPage output page
    * @param $skin Skin current skin
    */
function pcBeforePageDisplay( &$out, &$skin ) {
// public static function pcBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
	$out->addModules( 'ext.ProcessCite' );
	return true;
}

/**
* params from cite: key is the name from <ref name =key>sometext</ref> or <ref name=key/>
* val is an associative array with keys text, count, and number.
*/

function wfProcessCite( $key, $str ){
	global $wgHooks, $wgSitename;

	# Configuration section

	$libtag = 'LIB'; # the prefix for commonly used references to be pulled from a page in the wiki
	$lib_pageName = "$wgSitename Reference Library"; # the PAGENAME of the page where the commonly used references are stored
	require "GO.xrf_abbs.php"; # load the dbxref url file


	#get the text enclosed in <ref> tags if present
	$my_text = $str;

	# get the key;xrefs list
	if (isset($key) && !is_int($key)){
		$tmp_key = $key;
	}else{
		$tmp_key = $my_text; #to handle situation where user puts the info inside <ref> instead of in <ref name =key>
	}
	$xrefs = explode('.3B',$tmp_key); #Cite changes the encoding of the semicolon


	$string = $my_text;
	$link ='';
	$is='';
	foreach ($xrefs as $my_key){

		$ref_fields = explode(':',$my_key);

		#these lines trim the extra _ when the user puts a space after colon in REFTYPE:data, then restore internal _
		$data = trim(str_replace('_',' ', array_pop($ref_fields)));	# assume the last element is the identifier data
		$data = str_replace(' ','_',$data);
		$ref_type = implode(':',$ref_fields); 	# reassemble the front part


		switch ($ref_type){
			case 'PMID':
				$paper = new PMIDeFetch($data);
				$ref_fields = $paper->citation();

				$string = '';
				$smw_str = "Publication\n";
				$set_smw = '{{#set:';

				$date = $ref_fields['Year'];
				$title = $ref_fields['Title'];
				$jrnl = $ref_fields['JournalAbbrev'];
				$journal = $ref_fields['JournalFullName'];
				$volume = $ref_fields['Volume'];
				$pages = $ref_fields['Pages'];

				$jrnl_str = "";

				## see if the full journal name and the abbreviated name are the same.
				if ($jrnl != $journal)
				{	$jrnl_str = "<abbr class='journal' title='" . $journal . "'>$jrnl</abbr>";
				}
				else
				{	$jrnl_str = "<span class='journal'>$jrnl</span>";
				}

				$author = array();
				foreach($paper->authors() as $auth){
					$author[] = $auth['Cite_name'];
				}

				$smw_str .= '|author=' . join(":", $author);
				$set_smw .= '|Has author='. join("|Has author=", $author);
				switch(count($author)){
					case 0:
						break;
					case 1:
						$string .= "<span class='author'>$author[0]</span>.";
						break;
					case 2:
						$string .= "<span class='author'>" . $author[0] . "</span> and <span class='author'>". $author[1] . "</span>.";
						break;
					case 3: ## this is in line with Nature's guidelines
					case 4: ## more than five authors ==> use "et al."
					case 5:
						$last = array_pop($author);
						$string .= "<span class='author'>" . join("</span>, <span class='author'>", $author) . "</span>, and <span class='author'>$last</span>.";
						break;
					default:
						$string .= "<span class='author'>$author[0]</span> <i>et al</i>.";
				}

				$string .= " <span class='title'>$title</span> <span class='container hcite'>$jrnl_str <span class='pubdate'>$date</span> <span class='volume'>$volume</span>:<span class='page'>$pages</span></span>.<br>";
				# set up a subobject with this data in it
				$smw_str .= "|title=$title|journal=$jrnl_str|pubdate=$date|vol=$volume|page=$pages";

				if($ref_fields['doi'])
				{	$string .= " [http://dx.doi.org/" . $ref_fields['doi'] . " DOI:" . $ref_fields['doi'] . "] ";
					$smw_str .= '|doi:' . $ref_fields['doi'];
					$set_smw .= "|Has DOI=" . $ref_fields['doi'];
				}


				if ($ref_fields['pmc'])
				{	$string .= " [http://www.ncbi.nlm.nih.gov/pmc/articles/" . $ref_fields['pmc'] . "/ PMCID:" . $ref_fields['pmc'] . "] ";
					$smw_str .= '|pmcid:' . $ref_fields['pmc'];
					$set_smw .= '|Has PubMed Central ID=' . $ref_fields['pmc'];
				}

				$link = " [http://www.ncbi.nlm.nih.gov/entrez/query.fcgi?cmd=Retrieve&db=pubmed&dopt=Abstract&list_uids=$data <span class='Z3988' title='ctx_ver=Z39.88-2004&amp;rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Ajournal&amp;rfr_id=info%3Asid%2Focoins.info%3Agenerator&amp;rft.genre=article&amp;rft_id=info%3Apmid%2F$data'>PMID:$data</span>]";
				$smw_str .= "|pmid:$data";
				$set_smw .= "|Has title=$title|Published in=$jrnl_str|Publication date=$date|"
			//	. "Publication volume=$volume|Publication pages=$pages|"
				."PubMed ID=$data}}";
				# prepend the ImpactStory stuff
				$is = "<div class='impactstory-embed' data-show-logo='false' data-id='". $data ."' data-id-type='pmid' data-badge-type='icon' data-api-key='gmod-th0dfd'><i class='loading'></i></div>";
				# add internal link if PagesOnDemand is present.
#				if (isset($wgHooks['PagesOnDemand']) && in_array('wfLoadPubmedPageOnDemand',$wgHooks['PagesOnDemand'])) $link .= " [[$key|$wgSitename page]]";


				break;
			case 'ISBN':
				break;

			# look in a library of reference information on a specific pagename
			case "$libtag":

				# load the data library
				$item = array();
				$data_page = Revision::newFromTitle(Title::makeTitle(NS_MAIN, $lib_pageName));
				if (! $data_page){
				#	echo "Library not found\n"; break;
				}else{
					$text = $data_page->getText();
					$text = preg_replace( '/<noinclude>.*?<\/noinclude>/s', '', $text );
					$records = explode("\n",$text);
					foreach($records as $record){
						$tmp = explode('|',$record);
						# rejoin just in case the text has another |, which will happen with redirected wiki links
						$item[$tmp[0]] = implode('|',array_slice($tmp, 1));
					}

				}

				@$string = $item[$data];

				break;
			default:
				if (isset ($dbxref_url[$ref_type])){
					$link .= " [".$dbxref_url[$ref_type]."$data $my_key] ";
				}
			} # end switch ref_type
		}

	$string .= " $link ";

	$my_text = "$is<cite class='hcite'>$string</cite>$set_smw"; //<br><br>{{" . $smw_str . '}}' ;

	#Replace the reference text
	$str = $my_text;

	return true;
}
