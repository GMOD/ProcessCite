The enclosed extensions are what we use at EcoliWiki and GONUTS. Here's what is included:

PMID/*	
	This is an extension that handles the connections to PubMed E-Utilities for the other components
	the global variable $extEfetchCache is the path to a directory where we cache XML returns from 
	PubMed, so we don't constantly hit their server
Cite/*
	This is a patched version of the Cite extension with a hook added to support adding citations
	from just their pmids using <ref name=PMID:<number> /> as the citation marker.  The folder includes
	Our ProcessCite extension, which uses the hook to call the PMID extensions E-utilities methods
PagesOnDemand/*
	ProcessCite is set to include a wiki link to a page with the title PMID:<number>. 
	This one is only needed if you want clicking the links to automatically create pages 
	and populate them with title, abstract, etc.  This is aided by having your own template
	file in the wiki. Ours are at:
	
		EcoliWiki: http://ecoliwiki.net/colipedia/index.php/Template:PMID_page		
		GONUTS: http://gowiki.tamu.edu/wiki/index.php/Template:PMID_page

	Note that these both use TableEdit and yet another Template page to create a box for the 
	citation info. You probably don't need the other stuff in the page.

Our lines in LocalSettings.php:

# PMID for EUtils
	require_once( $wgExtensionPath . "PMID/PMID.php");
	$extEfetchCache = "/Library/WebServer/tmp/pubmed";
# Cite
	require_once( "$wgExtension1_19_Path/Cite/Cite.php" );
	require_once( "$wgExtensionPath/Cite/ProcessCite.php" );
# PagesOnDemand
	require_once( $wgExtensionPath . "PagesOnDemand/PagesOnDemand.php");
	require_once( $wgExtensionPath . "PagesOnDemand/PMID_OnDemand.php");
