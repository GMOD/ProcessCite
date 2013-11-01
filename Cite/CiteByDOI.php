<?php
/**
 * CiteByDOI extension - Allows citing documents using their DOI
 *
 * @author Aurimas Vinckevicius
 * @email	aurimas.dev@gmail.com
 * @license GNU General Public Licence 3.0 or later
 */

/*
 * For PHP versions >= 5.3.1, this extension requires a modification in the MediaWiki source code.
 * This restores intended behavior broken after PHP 5.3.0
 * For explanation, please refer to comment by 'tstarling at wikimedia dot org' at http://bugs.php.net/bug.php?id=50394
 *
 * File includes/EditPage.php must be edited as follows:
 * 
 * // Allow extensions to modify form data
 * wfRunHooks( 'EditPage::importFormData', array( $this, $request ) );
 *
 * Needs to be changed to
 *
 * // Allow extensions to modify form data
 * wfRunHooks( 'EditPage::importFormData', array( &$this, $request ) );
 *
 * Note the & before $this.
 */

if( !defined( 'MEDIAWIKI' ) ) {
	die( "This is an extension to the MediaWiki package and cannot be run standalone.\n" );
}

//extension credits
$wgExtensionCredits['other'][] = array(
  'path' => __FILE__,
  'name' => 'CiteByDOI',
  'author' =>'Aurimas Vinckevicius', 
  'url' => 'http://www.mediawiki.org/wiki/Extension:CiteByDOI', 
  'description' => 'Allows citing documents using their DOI',
  'descriptionmsg' => 'doi_description',
  'version' => '1.0a'
);

//define user-configurable options
$wgCBDOI_askToValidate = true;

require_once(__DIR__ . '/CrossRef.php');
require_once(__DIR__ . '/BibFormat.php');

//load internationalization file
$wgExtensionMessagesFiles['CiteByDOI'] = __DIR__ . '/CiteByDOI.i18n.php';

//Hook right after getting data from the edit form before parsing it.
//Available since 1.16.0
$wgHooks['EditPage::importFormData'][] = 'parseDOITags';

//Looks for DOIs inside <doi></doi> tags and attempts to resolve them to metadata,
//which is then formatted using AMA citation style (by default)
function parseDOITags( &$editpage, $request )
{
	$doiOpenTag = '<doi>';
	$doiPregMatch = '#<doi>([^<]*)</doi>#';

	//Return immediately if there is nothing to process
	if(!$editpage->textbox1 || strpos($editpage->textbox1,$doiOpenTag) === FALSE)
	{
		return true;
	}

	$newText = preg_replace_callback($doiPregMatch,
		//This is the function that does all the replacements
		//If all goes well, it will replace DOIs with formatted citation
		//otherwise, the <doi> tags remain in text
		function ($matches) use (&$editpage) {
			$meta = CrossRef::doiToMeta($matches[1]);
			//check for errors
			if(isset($meta['error']))
			{
				$editpage->hookError = "<span style=\"color:red\">'''" . wfMsg('doi_not_resolved') . "'''</span>";
				return $matches[0];
			}

			//format the data
			$styled = BibFormat::style($meta);
			if(!$styled)
			{
				$editpage->hookError = "<span style=\"color:red\">'''" . wfMsg('doi_not_resolved') . "'''</span>";
				return $matches[0];
			}

			return $styled;
		},
		$editpage->textbox1);
	
	//check if preg_replace_callback encountered an error
	if(!$newText)
	{
		$editpage->hookError = "<span style=\"color:red\">'''" . wfMsg('doi_internal_error') . "'''</span>";
		user_error('CiteByDOI: parseDOITags encountered an error while executing preg_replace_callback.');
		return true;
	}

	//encourage users to validate that the doi substitution worked properly before saving
	//This can be disabled by setting $wgCBDOI_askToValidate to false
	global $wgCBDOI_askToValidate;
	if($wgCBDOI_askToValidate && !$editpage->hookError)
	{
		$editpage->hookError = "<span style=\"color:blue\">'''" . wfMsg('doi_please_check') . "'''</span>";
	}
	
	$editpage->textbox1 = $newText;
	return true;
}

?>