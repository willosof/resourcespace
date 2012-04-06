<?php
include "include/db.php";
include "include/general.php";
include "include/collections_functions.php";

# External access support (authenticate only if no key provided, or if invalid access key provided)
$k=getvalescaped("k","");if (($k=="") || (!check_access_key_collection(getvalescaped("c",""),$k) && !check_access_key(getvalescaped("r",""),$k))) {include "include/authenticate.php";}

$topurl="pages/" . $default_home_page;
if ($use_theme_as_home) {$topurl="pages/themes.php";}
if ($use_recent_as_home) {$topurl="pages/search.php?search=" . urlencode("!last".$recent_search_quantity);}

$bottomurl="pages/collections.php?k=" . $k;

if (getval("c","")!="")
	{
	# quick redirect to a collection (from e-mails, keep the URL nice and short)
	$c=getvalescaped("c","");
	$topurl="pages/search.php?search=" . urlencode("!collection" . $c) . "&k=" . $k;;
	$bottomurl="pages/collections.php?collection=" . $c . "&k=" . $k;
	
	if ($k!="")
		{
		# External access user... set top URL to first resource
		$r=get_collection_resources($c);
		if (count($r)>0)
			{
			# Fetch collection data
			$cinfo=get_collection($c);if ($cinfo===false) {exit("Collection not found.");}
		
			if ($feedback_resource_select && $cinfo["request_feedback"])
				{
				$topurl="pages/collection_feedback.php?collection=" . $c . "&k=" . $k;		
				}
			else
				{
				$topurl="pages/search.php?search=" . urlencode("!collection" . $c) . "&k=" . $k;		
				}
			}
		else
			{
			$topurl="pages/" . $default_home_page;
			}
		}
	}

if (getval("r","")!="")
	{
	# quick redirect to a resource (from e-mails)
	$r=getvalescaped("r","");
	$topurl="pages/view.php?ref=" . $r . "&k=" . $k;
	if ($k!="") {$bottomurl="";} # No bottom frame if anon. access for single resource
	}

if (getval("u","")!="")
	{
	# quick redirect to a user (from e-mails)
	$u=getvalescaped("u","");
	$topurl="pages/team/team_user_edit.php?ref=" . $u;
	}
	
if (getval("q","")!="")
	{
	# quick redirect to a request (from e-mails)
	$q=getvalescaped("q","");
	$topurl="pages/team/team_request_edit.php?ref=" . $q;
	}

if (getval("ur","")!="")
	{
	# quick redirect to periodic report unsubscriptions.
	$ur=getvalescaped("ur","");
	$topurl="pages/team/team_report.php?unsubscribe=" . $ur;
	}

	
if (getval("url","")!="")
	{
	# New URL for top section (when the frameset is lost)
	$topurl=getval("url",$topurl);
	}

# If not using framesets, redirect instead.
if (checkperm("b") || $frameless_collections) {redirect($topurl);}

?><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">
<html>
<head>
<!--
ResourceSpace version <?php echo $productversion?>

Copyright Oxfam GB, Montala, WWF International, Tom Gleason, Colorhythm LLC, Worldcolor 2006-2010
http://www.resourcespace.org/
-->
<title><?php echo htmlspecialchars($applicationname)?></title>
<link rel="icon" type="image/png" href="<?php echo $baseurl."/".$header_favicon?>" />

<frameset rows="*<?php if ($bottomurl!="") { ?><?php if ($collection_resize!=true){?>,<?php echo $collection_frame_divider_height ?><?php } ?>,<?php echo $collection_frame_height ?><?php } ?>" id="topframe" framespacing="3" <?php if ($collection_resize!=true){?>frameborder="no"<?php } ?>>
<frame name="main" id="main" src="<?php echo $topurl?>" <?php if ($collection_resize!=true){?>frameborder="no"<?php } ?>>

<?php if ($bottomurl!="") { ?>
<?php if ($collection_resize!=true){?><frame src="pages/frame-divider.php" name="DivideFrame" frameborder="no" scrolling="no" noresize="noresize" marginwidth="0" marginheight="0" id="DivideFrame" /><?php } ?>
<frame name="collections" id="collections" src="<?php echo $bottomurl?>" frameborder=no>
<?php } ?>

</frameset>


</head>
</html>
