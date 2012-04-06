<?php

function HookPixlrViewAfterresourceactions() {

	global $ref;
	global $access;
	global $lang;
	global $resource;
	global $pixlr_default_target_format;

	include_once "../plugins/transform/include/config.default.php";

	if (file_exists("../plugins/transform/include/config.php")){
                include_once("../plugins/transform/include/config.php");
	}

	$url = get_resource_path(
		$ref,				// Ref 
		false, 				// Getfilepath
		"",				// Size
		true, 				// Generate
		$pixlr_default_target_format, 	// Extension
		-1, 				// Scramble
		1, 				// Page
		false, 				// Watermarked
		"",				// File Modified
		-1,				// Alternative
		false,				// Includemodified
	);

	print "<li>";
		print "<a target='_new' href='"
			."http://pixlr.com/editor?"
			."referrer=Biter&"
			."exit=".urlencode("http://".$_SERVER['HTTP_HOST']."/pages/view.php?ref=$ref")."&"
			."redirect=false&"
			."image=".urlencode($url)."'"
			.">";
	
			print "&gt; ";
			print $lang['pixlr'];
	
		print "</a>";
		
	print "</li>";

}

?>
