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
    'author'=>'Jim Hu &lt;jimhu@tamu.edu&gt; with additional modifications by Amelia Ireland',
    'description'=>'Generates reference text for papers in National Library of Medicine PubMed database and for objects with Digital Object Identifiers (DOIs).',
    'version'=>'0.3'
);

# Register hooks ('CiteBeforeStackEntry' hook is provided by a patch to the Cite extension).
# If hook not present in Cite, add it at the start of function stack
$wgHooks['CiteBeforeStackEntry'][] = 'wfProcessCite';

# Add the js to the page
$wgHooks['BeforePageDisplay'][] = 'pcBeforePageDisplay';

global $wgExtensionPath;
$wgAutoloadClasses['PMIDeFetch'] =  "$wgExtensionPath/PMID/class.PMIDeFetch.php";

require_once(  dirname( __FILE__ ) . "/CrossRef.php");
//load internationalization file
$wgExtensionMessagesFiles['CiteByDOI'] = dirname( __FILE__ ) ."/CiteByDOI.i18n.php";

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

function wfProcessCite( $key, $str, $argv ){
	global $wgHooks, $wgSitename;

//	MWDebug::log("Starting wfProcessCite; args: key $key, str $str, argv: $argv ");

	# Configuration section

	$libtag = 'LIB'; # the prefix for commonly used references to be pulled from a page in the wiki
	$lib_pageName = "$wgSitename Reference Library"; # the PAGENAME of the page where the commonly used references are stored
	require "GO.xrf_abbs.php"; # load the dbxref url file

	# get the key;xrefs list
//	if (isset($key) && !is_int($key)){
//		$tmp_key = $key;
	// $key gets mangled by Cite, so use the 'name' attribute directly
	if (isset($argv['name'])) {
		$tmp_key = (string)$argv['name'];
	}else{
		$tmp_key = $str; #to handle situation where user puts the info inside <ref> instead of in <ref name =key>
	}

	$string = ''; // this will replace <ref .../>
//	foreach ($tmp_key as $my_key){

	$ref_fields = explode(':',$tmp_key);

	#these lines trim the extra _ when the user puts a space after colon in REFTYPE:data, then restore internal _
	$data = trim(str_replace('_',' ', array_pop($ref_fields)));	# assume the last element is the identifier data
	$data = str_replace(' ','_',$data);
	$ref_type = implode(':',$ref_fields); 	# reassemble the front part
	MWDebug::log("ref fields: $ref_fields; ref type: $ref_type; data: $data");

	switch ($ref_type){
		case 'DOI':
			// Fetch the paper data from the CrossRef server
			$paper_data = CrossRef::doiToMeta($data);

//			$v = var_export($paper_data, true);
//			MWDebug::log("results: $v");

			//check for errors
			if(isset($paper_data['error']))
			{
				$string = $ref_type . ":" . $data . ': <span class="error">' . wfMsg('doi_not_resolved') . "</span>";
				MWDebug::log("error in resolving DOI $data!");
			} else {

				// reformat date to match the PMID date

				// find the publication date.
				// Use the year of print publication
				// If no print date, use online publication date
				if (isset($paper_data['Pub_date']['print'])) {
					$paper_data['Year'] = $paper_data['Pub_date']['print']['year'];
				} elseif (isset($paper_data['Pub_date']['online'])) {
					$paper_data['Year'] = $paper_data['Pub_date']['online']['year'];
				} else {
					$paper_data['Year'] = '';
				}

				$string = formatRefs($paper_data, 'DOI');
			}
			break;

		case 'PMID':
			MWDebug::log("found a PMID ref: $data");

			$paper = new PMIDeFetch($data);
			$paper_data = $paper->citation();
			// coerce data into the same format as the DOI data
			if(isset($paper_data['xrefs']['doi']))
			{	$paper_data['DOI'] = $paper_data['xrefs']['doi'];
			}
			if (isset($paper_data['xrefs']['pmc']))
			{	$paper_data['PMCID'] = $paper_data['xrefs']['pmc'];
			}
			if (isset($paper_data['xrefs']['pubmed']))
			{	$paper_data['PMID'] = $paper_data['xrefs']['pubmed'];
			}
			$string = formatRefs($paper_data, 'PMID');
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
				$link .= " [".$dbxref_url[$ref_type]."$data $tmp_key] ";
			}
		} # end switch ref_type
//		}

	#Replace the reference text
	$str = $string;

	return true;
}

/*

function formatRefs

Mangles the article data into a format for displaying on a wiki.

@param $paper_data  // contains the data about the article
@param $type // 'PMID' or 'DOI'
*/

function formatRefs( $paper_data, $type ){

	$string = ''; //this will get returned to the wiki.

	global $wgPCImpactStoryApiKey; ## MUST have an ImpactStory API key to include IS alt-metrics
	global $wgPCUseTemplate; ## Name of template (if using)


	if (isset($wgPCUseTemplate) && $wgPCUseTemplate){
		// set up the semantic stuff
		$smw_str = '|Author=' . join(":", $paper_data['Authors']);

		$attribs = array( 'Title', 'JournalFullName', 'JournalAbbrev', 'Volume', 'Issue', 'Pages', 'Year', 'DOI', 'PMID', 'PMCID');
		foreach ($attribs as $a) {
			( isset($paper_data[$a])
			? $smw_str .= "|$a=" . $paper_data[$a]
			: '' );
		}
		// add the COinS info in case of template calamities
		($type == 'PMID'
		? $smw_str .= "|PMID COinS=<span class='Z3988' title='ctx_ver=Z39.88-2004&amp;rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Ajournal&amp;rfr_id=info%3Asid%2Focoins.info%3Agenerator&amp;rft.genre=article&amp;rft_id=info%3Apmid%2F" . $paper_data['PMID'] . "'>PMID:" . $paper_data['PMID'] . "</span>"
		: $smw_str .= "|DOI COinS=<span class='Z3988' title='ctx_ver=Z39.88-2004&amp;rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Ajournal&amp;rfr_id=info%3Asid%2Focoins.info%3Agenerator&amp;rft.genre=article&amp;rft_id=info%3Adoi%2F" . $paper_data['DOI'] . "'>DOI:" . $paper_data['DOI'] . "</span>"
		);

		# set up an object with this data in it
		$string .= "{{$wgPCUseTemplate\n".$smw_str."}}";
	} else {
		// Just output a string that will be displayed directly on the wiki.
		switch(count($paper_data['Authors'])){
		case 0:
				break;
			case 1:
				$string = "<span class='author'>".$paper_data['Authors'][0]."</span>.";
				break;
			case 2:
				$string = "<span class='author'>" . $paper_data['Authors'][0] . "</span> and <span class='author'>". $paper_data['Authors'][1] . "</span>.";
				break;
			case 3: ## this is in line with Nature's guidelines
			case 4: ## more than five authors ==> use "et al."
			case 5:
				$last = array_pop($paper_data['Authors']);
				$string = "<span class='author'>" . join("</span>, <span class='author'>", $paper_data['Authors']) . "</span>, and <span class='author'>$last</span>.";
				$paper_data['Authors'][] = $last;
				break;
			default:
				$string = "<span class='author'>".$paper_data['Authors'][0]."</span> <i>et al</i>.";
		}


		## see if the full journal name and the abbreviated name are the same.
		if (isset($paper_data['JournalAbbrev']) && $paper_data['JournalAbbrev'] !== $paper_data['JournalFullName'])
		{	$paper_data['jrnl_str'] = "<abbr class='journal' title='" . $paper_data['JournalFullName'] . "'>" . $paper_data['JournalAbbrev'] . "</abbr>";
		}
		else
		{	$paper_data['jrnl_str'] = "<span class='journal'>" . $paper_data['JournalFullName'] . "</span>";
		}


		// put together the citation string
		$string .= " <span class='title'>" . $paper_data['Title'] . "</span> <span class='container hcite'>" . $paper_data['jrnl_str']
		. ( isset($paper_data['Year']) ? " <span class='pubdate' title='Publication date'>" . $paper_data['Year'] . "</span>" : "")
		. ( isset($paper_data['Volume']) ? " <span class='volume' title='Volume'>" . $paper_data['Volume'] . "</span>": '')

		. ( isset($paper_data['Issue']) ? ":<span class='issue' title='Issue'>" . $paper_data['Issue'] . "</span>": '')

		. ( isset($paper_data['Pages']) ? " <span class='page' title='Pages'>" . $paper_data['Pages'] . "</span>": '')

		."</span>.<br>"

		// add the DOI
		. ( isset($paper_data['DOI']) ?
		// does this come from a DOI listing?
			" [http://dx.doi.org/" . $paper_data['DOI'] .
			( $type == 'DOI' ?
			" <span class='Z3988' title='ctx_ver=Z39.88-2004&amp;rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Ajournal&amp;rfr_id=info%3Asid%2Focoins.info%3Agenerator&amp;rft.genre=article&amp;rft_id=info%3Adoi%2F"
			. rawurlencode($paper_data['DOI'])
			. "'>DOI:" . $paper_data['DOI'] . "</span>]"
			: " DOI:" . $paper_data['DOI']. "]"
			)
			: ""
		)
		// PubMedCentral
		. ( isset($paper_data['PMCID']) ?
			" [http://www.ncbi.nlm.nih.gov/pmc/articles/" . $paper_data['PMCID'] . "/ PMCID:" . $paper_data['PMCID'] . "] "
			: ""
		)
		// PubMed
		. ( isset($paper_data['PMID']) ?
			" [http://www.ncbi.nlm.nih.gov/pubmed/" . $paper_data['PMID'] .
			( $type == 'PMID' ?
			" <span class='Z3988' title='ctx_ver=Z39.88-2004&amp;rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Ajournal&amp;rfr_id=info%3Asid%2Focoins.info%3Agenerator&amp;rft.genre=article&amp;rft_id=info%3Apmid%2F" . $paper_data['PMID'] . "'>PMID:" . $paper_data['PMID'] . "</span>]"
			: " PMID:" . $paper_data['PMID'] . "]"
			)
			: ""
		);

		// add the hcite container
		$string = "<cite class='hcite'>$string</cite>";
	}

	if (isset($wgPCImpactStoryApiKey)){
		# prepend the ImpactStory stuff
		$string = "<div class='impactstory-embed' data-id='"
		. ($type == 'PMID'
			? $paper_data['PMID'] . "' data-id-type='pmid"
			: $paper_data['DOI'] ."' data-id-type='doi"
		)
		."' data-show-logo='false' data-badge-type='icon' data-api-key='"
		. $wgPCImpactStoryApiKey
		. "'><i class='loading'></i></div>"
		. $string;
	}

	return $string;
}


