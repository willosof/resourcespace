<?php
/**
 * View resource page
 * 
 * @package ResourceSpace
 * @subpackage Pages
 */
include "../include/db.php";
include "../include/general.php";
# External access support (authenticate only if no key provided, or if invalid access key provided)
$k=getvalescaped("k","");if (($k=="") || (!check_access_key(getvalescaped("ref",""),$k))) {include "../include/authenticate.php";}
include "../include/search_functions.php";
include "../include/resource_functions.php";
include "../include/collections_functions.php";
include "../include/image_processing.php";

$ref=getvalescaped("ref","",true);

	
# fetch the current search (for finding simlar matches)
$search=getvalescaped("search","");
$order_by=getvalescaped("order_by","relevance");
$offset=getvalescaped("offset",0,true);
$restypes=getvalescaped("restypes","");
$starsearch=getvalescaped("starsearch","");
if (strpos($search,"!")!==false) {$restypes="";}
$archive=getvalescaped("archive",0,true);

$default_sort="DESC";
if (substr($order_by,0,5)=="field"){$default_sort="ASC";}
$sort=getval("sort",$default_sort);

# next / previous resource browsing
$go=getval("go","");
if ($go!="")
	{
	$origref=$ref; # Store the reference of the resource before we move, in case we need to revert this.
	
	# Re-run the search and locate the next and previous records.
	$result=do_search($search,$restypes,$order_by,$archive,-1,$sort,false,$starsearch);
	if (is_array($result))
		{
		# Locate this resource
		$pos=-1;
		for ($n=0;$n<count($result);$n++)
			{
			if ($result[$n]["ref"]==$ref) {$pos=$n;}
			}
		if ($pos!=-1)
			{
			if (($go=="previous") && ($pos>0)) {$ref=$result[$pos-1]["ref"];}
			if (($go=="next") && ($pos<($n-1))) {$ref=$result[$pos+1]["ref"];if (($pos+1)>=($offset+72)) {$offset=$pos+1;}} # move to next page if we've advanced far enough
			}
		else
			{
			?>
			<script type="text/javascript">
			alert('<?php echo $lang["resourcenotinresults"] ?>');
			</script>
			<?php
			}
		}
	# Check access permissions for this new resource, if an external user.
	$newkey=hook("nextpreviewregeneratekey");
	if (is_string($newkey)) {$k=$newkey;}
	if ($k!="" && !check_access_key($ref,$k)) {$ref=$origref;} # cancel the move.
	}


# Load resource data
$resource=get_resource_data($ref);
if ($resource===false) {exit("Resource not found.");}

// get mp3 paths if necessary and set $use_mp3_player switch
if (!(isset($resource['is_transcoding']) && $resource['is_transcoding']==1) && (in_array($resource["file_extension"],$ffmpeg_audio_extensions) || $resource["file_extension"]=="mp3") && $mp3_player){
		$use_mp3_player=true;
	} 
	else {
		$use_mp3_player=false;
	}
if ($use_mp3_player){
	$mp3realpath=get_resource_path($ref,true,"",false,"mp3");
	if (file_exists($mp3realpath)){
		$mp3path=get_resource_path($ref,false,"",false,"mp3");
	}
}	

# Dev feature - regenerate exif data.
if (getval("regenexif","")!="")
	{
	extract_exif_comment($ref,$resource["file_extension"]);
	$resource=get_resource_data($ref,false);
	}

# Load access level
$access=get_resource_access($ref);

# check permissions (error message is not pretty but they shouldn't ever arrive at this page unless entering a URL manually)
if ($access==2) 
		{
		exit("This is a confidential resource.");
		}
		
hook("afterpermissionscheck");
		
# Establish if this is a metadata template resource, so we can switch off certain unnecessary features
$is_template=(isset($metadata_template_resource_type) && $resource["resource_type"]==$metadata_template_resource_type);

$title_field=$view_title_field; 
# If this is a metadata template and we're using field data, change title_field to the metadata template title field
if (isset($metadata_template_resource_type) && ($resource["resource_type"]==$metadata_template_resource_type))
	{
	if (isset($metadata_template_title_field)){
		$title_field=$metadata_template_title_field;
		}
	else {$default_to_standard_title=true;}	
	}

if ($pending_review_visible_to_all && isset($userref) && $resource["created_by"]!=$userref && $resource["archive"]==-1 && !checkperm("e0"))
	{
	# When users can view resources in the 'User Contributed - Pending Review' state in the main search
	# via the $pending_review_visible_to_all option, set access to restricted.
	$access=1;
	}

# If requested, refresh the collection frame (for redirects from saves)
if (getval("refreshcollectionframe","")!="")
	{
	refresh_collection_frame();
	}

# Update the hitcounts for the search keywords (if search specified)
# (important we fetch directly from $_GET and not from a cookie
$usearch=@$_GET["search"];
if ((strpos($usearch,"!")===false) && ($usearch!="")) {update_resource_keyword_hitcount($ref,$usearch);}

# Log this activity
daily_stat("Resource view",$ref);
if ($log_resource_views) {resource_log($ref,'v',0);}

if ($metadata_report && isset($exiftool_path)){
	# Include the metadata report function
	$headerinsert.="
	<script src=\"../lib/js/metadata_report.js\" type=\"text/javascript\"></script>
	";
	}

if ($direct_download && !$save_as){	
// check browser to see if forcing save_as 
if (!$direct_download_allow_opera  && strpos(strtolower($_SERVER["HTTP_USER_AGENT"]),"opera")!==false) {$save_as=true;}
if (!$direct_download_allow_ie7 && strpos(strtolower($_SERVER["HTTP_USER_AGENT"]),"msie 7.")!==false) {$save_as=true;}	
if (!$direct_download_allow_ie8 && strpos(strtolower($_SERVER["HTTP_USER_AGENT"]),"msie 8.")!==false) {$save_as=true;}	
}
	
if ($direct_download && !$save_as){
	$headerinsert.="
	<script type=\"text/javascript\">
	function directDownload(url)
		{
		dlIFrma = document.getElementById('dlIFrm');
		dlIFrma.src = url;  
		}
	</script>";
	}
	
# Show the header/sidebar
include "../include/header.php";

if ($direct_download && !$save_as){
?>
<iframe id="dlIFrm" frameborder=0 scrolling="auto" <?php if ($debug_direct_download){?>width="600" height="200"<?php } else { ?>width="0" height="0"<?php } ?>> This browser can not use IFRAME. </iframe>

<?php }
# Load resource field data
$fields=get_resource_field_data($ref,false,true,-1,$k!="",$use_order_by_tab_view);

# Load edit access level (checking edit permissions - e0,e-1 etc. and also the group 'edit filter')
$edit_access=get_edit_access($ref,$resource["archive"],$fields);
if ($k!="") {$edit_access=0;}
?>

<!--Panel for record and details-->
<div class="RecordBox">
<div class="RecordPanel"> 

<div class="RecordHeader">
<?php if (!hook("renderinnerresourceheader")) { ?>


<?php if ($usearch!="" || $archive==-1 || $archive==-2) { ?>
<div class="backtoresults">
<a href="view.php?ref=<?php echo $ref?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>&k=<?php echo $k ?>&go=previous&<?php echo hook("nextpreviousextraurl") ?>">&lt;&nbsp;<?php echo $lang["previousresult"]?></a>
<?php 
hook("viewallresults");
if ($k=="") { ?>
|
<a href="search.php?search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>&k=<?php echo $k?>"><?php echo $lang["viewallresults"]?></a>
<?php } ?>
|
<a href="view.php?ref=<?php echo $ref?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>&k=<?php echo $k?>&go=next&<?php echo hook("nextpreviousextraurl") ?>"><?php echo $lang["nextresult"]?>&nbsp;&gt;</a>
</div>
<?php } ?>


<h1><?php switch ($resource["archive"])
	{
	case -2:
	?><span class="ResourcePendingSubmissionTitle"><?php echo $lang["status-2"]?>:</span>&nbsp;<?php
	break;
	case -1:
	?><span class="ResourcePendingReviewTitle"><?php echo $lang["status-1"]?>:</span>&nbsp;<?php
	break;
	case 1:
	?><span class="ArchiveResourceTitle"><?php echo $lang["status1"]?>:</span>&nbsp;<?php
	break;
	case 2:
	?><span class="ArchiveResourceTitle"><?php echo $lang["status2"]?>:</span>&nbsp;<?php
	break;
	case 3:
	?><span class="DeletedResourceTitle"><?php echo $lang["status3"]?>:</span>&nbsp;<?php
	break;
	}
if (!hook("replaceviewtitle")){ echo highlightkeywords(htmlspecialchars(i18n_get_translated(get_data_by_field($resource['ref'],$title_field))),$search); } /* end hook replaceviewtitle */  
?>&nbsp;</h1>
<?php } /* End of renderinnerresourceheader hook */ ?>
</div>

<?php if (isset($resource['is_transcoding']) && $resource['is_transcoding']==1) { ?><div class="PageInformal"><?php echo $lang['resourceistranscoding']?></div><?php } ?>

<?php hook("renderbeforeresourceview"); ?>

<div class="RecordResource">
<?php if (!hook("renderinnerresourceview")) { ?>
<?php if (!hook("replacerenderinnerresourcepreview")) { ?>
<?php if (!hook("renderinnerresourcepreview")) { ?>
<?php
$download_multisize=true;

$flvfile=get_resource_path($ref,true,"pre",false,$ffmpeg_preview_extension);
if (!file_exists($flvfile)) {$flvfile=get_resource_path($ref,true,"",false,$ffmpeg_preview_extension);}
if (file_exists("../players/type" . $resource["resource_type"] . ".php"))
	{
	include "../players/type" . $resource["resource_type"] . ".php";
	}
elseif (!(isset($resource['is_transcoding']) && $resource['is_transcoding']==1) && file_exists($flvfile) && (strpos(strtolower($flvfile),".".$ffmpeg_preview_extension)!==false))
	{
	# Include the Flash player if an FLV file exists for this resource.
	$download_multisize=false;
      if(!hook("customflvplay"))
	      {
          include "flv_play.php";
	      }
	
	# If configured, and if the resource itself is not an FLV file (in which case the FLV can already be downloaded), then allow the FLV file to be downloaded.
	if ($flv_preview_downloadable && $resource["file_extension"]!="flv") {$flv_download=true;}
	}
elseif ($use_mp3_player && file_exists($mp3realpath) && hook("custommp3player")){}	
elseif ($resource['file_extension']=="swf" && $display_swf){
	$swffile=get_resource_path($ref,true,"",false,"swf");
	if (file_exists($swffile)) { include "swf_play.php";}	
	}
elseif ($resource["has_image"]==1)
	{
	$use_watermark=check_use_watermark();
	$imagepath=get_resource_path($ref,true,"pre",false,$resource["preview_extension"],-1,1,$use_watermark);
	if (!file_exists($imagepath))
		{
		$imageurl=get_resource_path($ref,false,"thm",false,$resource["preview_extension"],-1,1,$use_watermark);
		}
	else
		{
		$imageurl=get_resource_path($ref,false,"pre",false,$resource["preview_extension"],-1,1,$use_watermark);
		}
	
	?>
	<a id="previewimagelink" href="preview.php?ref=<?php echo $ref?>&ext=<?php echo $resource["preview_extension"]?>&k=<?php echo $k?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>" title="<?php echo $lang["fullscreenpreview"]?>">
	<?php
	if (file_exists($imagepath))
		{ 
		?><img src="<?php echo $imageurl?>" alt="<?php echo $lang["fullscreenpreview"]?>" class="Picture" GALLERYIMG="no" id="previewimage" /><?php 
		} 
	?></a><?php
	}
else
	{
	?>
	<img src="../gfx/<?php echo get_nopreview_icon($resource["resource_type"],$resource["file_extension"],false)?>" alt="" class="Picture" style="border:none;" id="previewimage" />
	<?php
	}

?>
<?php } /* End of renderinnerresourcepreview hook */ ?>
<?php } /* End of replacerenderinnerresourcepreview hook */ ?>
<?php hook("renderbeforerecorddownload");

if ($download_summary) {include "../include/download_summary.php";}
?>
<?php if (!hook("renderresourcedownloadspace")) { ?>
<div class="RecordDownload" id="RecordDownload">
<div class="RecordDownloadSpace">
<?php if (!hook("renderinnerresourcedownloadspace")) { ?>
<h2><?php echo $lang["resourcetools"]?></h2>

<?php 

# Look for a viewer to handle the right hand panel. If not, display the standard photo download / file download boxes.
if (file_exists("../viewers/type" . $resource["resource_type"] . ".php"))
	{
	include "../viewers/type" . $resource["resource_type"] . ".php";
	}
elseif (hook("replacedownloadoptions"))
	{
	}
elseif ($is_template)
	{
	
	}
else
	{ 
	?>
<table cellpadding="0" cellspacing="0">
<tr>
<td><?php echo $lang["fileinformation"]?></td>
<td><?php echo $lang["filesize"]?></td>
<?php if ($userrequestmode==2 || $userrequestmode==3) { ?><td><?php echo $lang["price"] ?></td><?php } ?>
<td><?php echo $lang["options"]?></td>
</tr>
<?php
$nodownloads=false;$counter=0;$fulldownload=false;
if ($resource["has_image"]==1 && $download_multisize)
	{
	# Restricted access? Show the request link.
		
	
	# List all sizes and allow the user to download them
	$sizes=get_image_sizes($ref,false,$resource["file_extension"]);
	for ($n=0;$n<count($sizes);$n++)
		{
		# DPI calculations 
		if (isset($sizes[$n]['resolution'])&& $sizes[$n]['resolution']!=0){$dpi = $sizes[$n]['resolution'];}
		elseif (isset($dpi)&& $dpi!=0){}
		else { $dpi=300; }
		if (isset($sizes[$n]['unit'])){
			if (trim(strtolower($sizes[$n]['unit']))=="inches"){$imperial_measurements=true;}
			if (trim($sizes[$n]['unit'])=="cm"){$imperial_measurements=false;}
		}
		
		if ($imperial_measurements)
			{	
			$dpi_unit=$lang["inch-short"];
			$dpi_w=round(($sizes[$n]["width"]/$dpi),1);
			$dpi_h=round(($sizes[$n]["height"]/$dpi),1);
			}
		else
			{
			$dpi_unit=$lang["centimetre-short"];
			$dpi_w=round(($sizes[$n]["width"]/$dpi)*2.54,1);
			$dpi_h=round(($sizes[$n]["height"]/$dpi)*2.54,1);
			}
			
		# MP calculation
		$mp=round(($sizes[$n]["width"]*$sizes[$n]["height"])/1000000,1);
		
		# Is this the original file? Set that the user can download the original file
		# so the request box does not appear.
		$fulldownload=false;
		if ($sizes[$n]["id"]=="") {$fulldownload=true;}
		
		$counter++;
		$headline = ($sizes[$n]['id'] == '') ? str_replace_formatted_placeholder("%extension", $resource["file_extension"], $lang["originalfileoftype"]) : $sizes[$n]["name"];

		# Should we allow this download?
		# If the download is allowed, show a download button, otherwise show a request button.
		$downloadthissize=resource_download_allowed($ref,$sizes[$n]["id"],$resource["resource_type"]);


		if ($direct_link_previews && $downloadthissize) {
			if ($direct_link_previews_filestore){
				$direct_link = "" . get_resource_path($ref,false,$sizes[$n]['id'],false,$sizes[$n]['extension']);
				$headline = "<a href='$direct_link' target='dl_window_$ref'>$headline</a>";
			} else {
				$direct_link = "download.php?direct=1&ref=$ref&size=" . $sizes[$n]['id'] . "&ext=" . $sizes[$n]['extension'];
				$headline = "<a href='$direct_link' target='dl_window_$ref'>$headline</a>";
			}
		}
		if ($hide_restricted_download_sizes && !$downloadthissize && !checkperm("q"))
			continue;

		?>
		<tr class="DownloadDBlend" id="DownloadBox<?php echo $n?>">
		<td><h2><?php echo $headline?></h2>
		<?php  if (is_numeric($sizes[$n]["width"])) { ?>
		<p><?php echo $sizes[$n]["width"] . " x " . $sizes[$n]["height"] . " " . $lang["pixels"] . " "; if ($mp>=1) {echo "(" . $mp . " " . $lang["megapixel-short"] . ")"; } ?></p>
		<p><?php echo $dpi_w . " " . $dpi_unit . " x " . $dpi_h . " " . $dpi_unit . " " . $lang["at-resolution"] . " " . $dpi ." " . $lang["ppi"]; ?></p></td>
		<?php } ?>
		
		
		<td><?php echo $sizes[$n]["filesize"]?></td>

		<?php if ($userrequestmode==2 || $userrequestmode==3) {
		# Display price for basket request modes
		$price_id=$sizes[$n]["id"];if ($price_id=="") {$price_id="hpr";}
		$price=999; # If price cannot be found
		if (array_key_exists($price_id,$pricing)) {$price=$pricing[$price_id];}
		
		# Pricing adjustment hook (for discounts or other price adjustments plugin).
		$priceadjust=hook("adjust_item_price","",array($price,$ref,$sizes[$n]["id"]));
		if ($priceadjust!==false)
			{
			$price=$priceadjust;
			}
		
		 ?>
		<td><?php echo $currency_symbol . " " . number_format($price,2) ?></td>
		<?php } ?>

		<?php

		if ($downloadthissize)
			{
			?>
			<td class="DownloadButton">
			<?php if (!$direct_download || $save_as){
				?><a id="downloadlink" <?php if (!hook("downloadlink","",array("ref=" . $ref . "&k=" . $k . "&size=" . $sizes[$n]["id"] . "&ext=" . $sizes[$n]["extension"]))) { ?>href="terms.php?ref=<?php echo $ref?>&k=<?php echo $k?>&url=<?php echo urlencode("pages/download_progress.php?ref=" . $ref . "&size=" . $sizes[$n]["id"] . "&ext=" . $sizes[$n]["extension"] . "&k=" . $k . "&search=" . urlencode($search) . "&offset=" . $offset . "&archive=" . $archive . "&sort=".$sort."&order_by=" . urlencode($order_by))?>"<?php } ?>><?php echo $lang["action-download"]?></a>
			<?php 
			} 
			else { ?>
				<a id="downloadlink" href="#" onclick="directDownload('download_progress.php?ref=<?php echo $ref ?>&size=<?php echo $sizes[$n]['id']?>&ext=<?php echo $sizes[$n]['extension']?>&k=<?php echo $k?>')"><?php echo $lang["action-download"]?></a>
			<?php } // end if direct_download ?>
			</td>
			<?php
			}
		elseif (checkperm("q"))
			{
			?>
			<?php if(!hook("resourcerequest")){?>
			<td class="DownloadButton">
			<?php if ($request_adds_to_collection) { ?>
				<?php echo add_to_collection_link($ref,$search,"alert('" . $lang["requestaddedtocollection"] . "');",$sizes[$n]["id"]) ?>
			<?php } else { ?>
				<a href="resource_request.php?ref=<?php echo $ref?>&k=<?php echo getval("k","")?>">
			<?php } ?>
			<?php echo $lang["action-request"]?></a></td>
			<?php } ?>
			<?php
			}
		else
			{
			# No access to this size, and the request functionality has been disabled. Show just 'restricted'.
			?>
			<td class="DownloadButton DownloadDisabled"><?php echo $lang["access1"]?></td>
			<?php
			}
		?>
		</tr>
		<?php
		if (!hook("previewlinkbar")){
			if ($downloadthissize && $sizes[$n]["allow_preview"]==1)
				{ 
				# Add an extra line for previewing
				?> 
				<tr class="DownloadDBlend"><td><h2><?php echo $lang["preview"]?></h2><p><?php echo $lang["fullscreenpreview"]?></p></td><td><?php echo $sizes[$n]["filesize"]?></td>
				<?php if ($userrequestmode==2 || $userrequestmode==3) { ?><td></td><?php } # Blank spacer column if displaying a price above (basket mode).
				?>
				<td class="DownloadButton">
				<a id="previewlink" href="preview.php?ref=<?php echo $ref?>&ext=<?php echo $resource["file_extension"]?>&k=<?php echo $k?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>"><?php echo $lang["action-view"]?></a>
				</td>
				</tr>
				<?php
				} 
			}
		} /* end hook previewlinkbar */
	}
elseif (strlen($resource["file_extension"])>0 && !($access==1 && $restricted_full_download==false))
	{
	# Files without multiple download sizes (i.e. no alternative previews generated).
	$counter++;
	$path=get_resource_path($ref,true,"",false,$resource["file_extension"]);
	if (file_exists($path))
		{
		?>
		<tr class="DownloadDBlend">
		<td><h2><?php echo (isset($original_download_name)) ? str_replace_formatted_placeholder("%extension", $resource["file_extension"], $original_download_name, true) : str_replace_formatted_placeholder("%extension", $resource["file_extension"], $lang["originalfileoftype"]); ?></h2></td>
		<td><?php echo formatfilesize(filesize_unlimited($path))?></td>
		<td class="DownloadButton">
		<?php if (!$direct_download || $save_as){ ?>
			<a <?php if (!hook("downloadlink","",array("ref=" . $ref . "&k=" . $k . "&ext=" . $resource["file_extension"] ))) { ?>href="terms.php?ref=<?php echo $ref?>&k=<?php echo $k?>&url=<?php echo urlencode("pages/download_progress.php?ref=" . $ref . "&ext=" . $resource["file_extension"] . "&k=" . $k . "&search=" . urlencode($search) . "&offset=" . $offset . "&archive=" . $archive . "&sort=".$sort."&order_by=" . urlencode($order_by))?>"<?php } ?>><?php echo $lang["action-download"] ?></a>
		<?php } else { ?>
			<a href="#" onclick="directDownload('download_progress.php?ref=<?php echo $ref?>&ext=<?php echo $resource['file_extension']?>&k=<?php echo $k?>')"><?php echo $lang["action-download"]?></a>
		<?php } // end if direct_download ?>
		</td>
		</tr>
		<?php
		}
	} 
else
	{
	$nodownloads=true;
	}
	
if (($nodownloads || $counter==0) && !checkperm("T" . $resource["resource_type"] . "_"))
	{
	# No file. Link to request form.
	?>
	<tr class="DownloadDBlend">
	<td><h2><?php echo ($counter==0)?$lang["offlineresource"]:$lang["access1"]?></h2></td>
	<td><?php echo $lang["notavailableshort"]?></td>

	<?php if (checkperm("q"))
		{
		?>
		<?php if(!hook("resourcerequest")){?>
		<td class="DownloadButton"><a href="resource_request.php?ref=<?php echo $ref?>&k=<?php echo $k ?>"><?php echo $lang["action-request"]?></a></td>
		<?php } ?>
		<?php
		}
	else
		{
		?>
		<td class="DownloadButton DownloadDisabled"><?php echo $lang["access1"]?></td>
		<?php
		}
	?>
	</tr>
	<?php
	}
	
if (isset($flv_download) && $flv_download)
	{
	# Allow the FLV preview to be downloaded. $flv_download is set when showing the FLV preview video above.
	?>
	<tr class="DownloadDBlend">
	<td><h2><?php echo (isset($ffmpeg_preview_download_name)) ? $ffmpeg_preview_download_name : str_replace_formatted_placeholder("%extension", $ffmpeg_preview_extension, $lang["cell-fileoftype"]); ?></h2></td>
	<td><?php echo formatfilesize(filesize($flvfile))?></td>
	<td class="DownloadButton">
	<?php if (!$direct_download || $save_as){?>
		<a href="terms.php?ref=<?php echo $ref?>&k=<?php echo $k?>&url=<?php echo urlencode("pages/download_progress.php?ref=" . $ref . "&ext=" . $ffmpeg_preview_extension . "&size=pre&k=" . $k . "&search=" . urlencode($search) . "&offset=" . $offset . "&archive=" . $archive . "&sort=".$sort."&order_by=" . urlencode($order_by))?>"><?php echo $lang["action-download"] ?></a>
	<?php } else { ?>
		<a href="#" onclick="directDownload('download_progress.php?ref=<?php echo $ref?>&ext=<?php echo $ffmpeg_preview_extension?>&size=pre&k=<?php echo $k?>')"><?php echo $lang["action-download"]?></a>
	<?php } // end if direct_download ?></td>
	</tr>
	<?php
	}
	
# Alternative files listing
if ($access==0) # open access only (not restricted)
	{
	$altfiles=get_alternative_files($ref);
	for ($n=0;$n<count($altfiles);$n++)
		{
		if ($n==0)
			{
			?>
			<tr>
			<td colspan="3"><?php echo $lang["alternativefiles"]?></td>
			</tr>
			<?php
			}	
		$alt_thm="";$alt_pre="";
		if ($alternative_file_previews)
			{
			$alt_thm_file=get_resource_path($ref,true,"col",false,"jpg",-1,1,false,"",$altfiles[$n]["ref"]);
			if (file_exists($alt_thm_file))
				{
				# Get web path for thumb (pass creation date to help cache refresh)
				$alt_thm=get_resource_path($ref,false,"col",false,"jpg",-1,1,false,$altfiles[$n]["creation_date"],$altfiles[$n]["ref"]);
				}
			$alt_pre_file=get_resource_path($ref,true,"pre",false,"jpg",-1,1,false,"",$altfiles[$n]["ref"]);
			if (file_exists($alt_pre_file))
				{
				# Get web path for preview (pass creation date to help cache refresh)
				$alt_pre=get_resource_path($ref,false,"pre",false,"jpg",-1,1,false,$altfiles[$n]["creation_date"],$altfiles[$n]["ref"]);
				}
			}
		?>
		<tr class="DownloadDBlend" <?php if ($alt_pre!="" && $alternative_file_previews_mouseover) { ?>onMouseOver="orig_preview=$('previewimage').src;$('previewimage').src='<?php echo $alt_pre ?>';" onMouseOut="$('previewimage').src=orig_preview;"<?php } ?>>
		<td>
		<?php if ($alt_thm!="") { ?><a href="preview.php?ref=<?php echo $ref?>&alternative=<?php echo $altfiles[$n]["ref"]?>&k=<?php echo $k?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>"><img src="<?php echo $alt_thm?>" class="AltThumb"></a><?php } ?>
		<h2><?php echo htmlspecialchars($altfiles[$n]["name"])?></h2>
		<p><?php echo htmlspecialchars($altfiles[$n]["description"])?></p>
		</td>
		<td><?php echo formatfilesize($altfiles[$n]["file_size"])?></td>
		
		<?php if ($userrequestmode==2 || $userrequestmode==3) { ?><td></td><?php } # Blank spacer column if displaying a price above (basket mode).
		?>
		
		<?php if ($access==0){?>
		<td class="DownloadButton">
		<?php if (!$direct_download || $save_as){?>
		<a <?php if (!hook("downloadlink","",array("ref=" . $ref . "&alternative=" . $altfiles[$n]["ref"] . "&k=" . $k . "&ext=" . $altfiles[$n]["file_extension"]))) { ?>href="terms.php?ref=<?php echo $ref?>&k=<?php echo $k?>&url=<?php echo urlencode("pages/download_progress.php?ref=" . $ref . "&ext=" . $altfiles[$n]["file_extension"] . "&k=" . $k . "&alternative=" . $altfiles[$n]["ref"] . "&search=" . urlencode($search) . "&offset=" . $offset . "&archive=" . $archive . "&sort=".$sort."&order_by=" . urlencode($order_by))?>"<?php } ?>><?php echo $lang["action-download"] ?></a>
		<?php } else { ?>
			<a href="#" onclick="directDownload('download_progress.php?ref=<?php echo $ref?>&ext=<?php echo $altfiles[$n]["file_extension"]?>&k=<?php echo $k?>&alternative=<?php echo $altfiles[$n]["ref"]?>')"><?php echo $lang["action-download"]?></a>
		<?php } // end if direct_download ?></td></td>
		<?php } else { ?>
		<td class="DownloadButton DownloadDisabled"><?php echo $lang["access1"]?></td>
		<?php } ?>
		</tr>
		<?php	
		}
        hook("morealtdownload");
	}
# --- end of alternative files listing

if ($use_mp3_player && file_exists($mp3realpath)){
		include "mp3_play.php";
}

?>



</table>
<?php } ?>
<br />
<ul>
<?php 



# ----------------------------- Resource Actions -------------------------------------
hook ("resourceactions") ?>
<?php if ($k=="") { ?>
<?php if (!hook("replaceresourceactions")) {?>
	
	
	<?php if ((!checkperm("b"))
	&&
	
	(!(($userrequestmode==2 || $userrequestmode==3) && $basket_stores_size))
	
	) { ?><li><?php echo add_to_collection_link($ref,$search)?>&gt; <?php echo $lang["action-addtocollection"]?></a></li><?php } ?>
	
	
	<?php if ($allow_share && ($access==0 || ($access==1 && $restricted_share))) { ?>
		<li><a href="resource_email.php?ref=<?php echo $ref?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>" target="main">&gt; <?php echo $lang["emailresource"]?></a></li>
		<?php if (!$disable_link_in_view) { ?><li><a target="_top" href="<?php echo $baseurl?>/?r=<?php echo $ref?>">&gt; <?php echo $lang["link"]?></a></li><?php }} ?>
	<?php if ($edit_access) { ?>
		<li><a href="edit.php?ref=<?php echo $ref?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>">&gt; 
			<?php echo $lang["action-edit"]?></a>
	<?php if (!checkperm("D") and !(isset($allow_resource_deletion) && !$allow_resource_deletion)){?>&nbsp;&nbsp;<a href="delete.php?ref=<?php echo $ref?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>">&gt; <?php if ($resource["archive"]==3){echo $lang["action-delete_permanently"];} else {echo $lang["action-delete"];}?></a><?php } ?></li>
	<?php if (! $disable_alternative_files) { ?><br />
	<li><a href="alternative_files.php?ref=<?php echo $ref?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>">&gt;&nbsp;<?php echo $lang["managealternativefiles"]?></a></li><?php } ?>

	<?php } ?>
	<?php if (checkperm("e" . $resource["archive"])) { ?><li><a href="log.php?ref=<?php echo $ref?>&search=<?php echo urlencode($search)?>&offset=<?php echo $offset?>&order_by=<?php echo $order_by?>&sort=<?php echo $sort?>&archive=<?php echo $archive?>">&gt; <?php echo $lang["log"]?></a></li><?php } ?><?php
    } /* End replaceresourceactions */ 
hook("afterresourceactions");
hook("afterresourceactions2");
?>
<?php } /* End if ($k!="")*/ 
hook("resourceactions_anonymous");
?>
<?php } /* End of renderinnerresourcedownloadspace hook */ ?>
</ul>
<div class="clearerleft"> </div>

<?php
if (!hook("replaceuserratingsbox")){
# Include user rating box, if enabled and the user is not external.
if ($user_rating && $k=="") { include "../include/user_rating.php"; }
} /* end hook replaceuserratingsbox */


?>


</div>
<?php } /* End of renderresourcedownloadspace hook */ ?>
<?php } /* End of renderinnerresourceview hook */ ?>
</div>

<?php hook("renderbeforeresourcedetails"); ?>

<div class="Title"><?php echo $lang["resourcedetails"]?></div>

<?php
$extra="";

#  -----------------------------  Draw tabs ---------------------------
$tabname="";
$tabcount=0;
if (count($fields)>0 && $fields[0]["tab_name"]!="")
	{ 
	?>
	<div class="TabBar">
	<?php
	$extra="";
	$tabname="";
	$tabcount=0;
	for ($n=0;$n<count($fields);$n++)
		{	
		$value=$fields[$n]["value"];

		# draw new tab?
		if (($tabname!=$fields[$n]["tab_name"]) && ($value!="") && ($value!=",") && ($fields[$n]["display_field"]==1))
			{
			?><div id="tabswitch<?php echo $tabcount?>" class="Tab<?php if ($tabcount==0) { ?> TabSelected<?php } ?>"><a href="#" onclick="SelectTab(<?php echo $tabcount?>);return false;"><?php echo i18n_get_translated($fields[$n]["tab_name"])?></a></div><?php
			$tabcount++;
			$tabname=$fields[$n]["tab_name"];
			}
		}
	?>
	</div>
	<script type="text/javascript">
	function SelectTab(tab)
		{
		// Deselect all tabs
		<?php for ($n=0;$n<$tabcount;$n++) { ?>
		document.getElementById("tab<?php echo $n?>").style.display="none";
		document.getElementById("tabswitch<?php echo $n?>").className="Tab";
		<?php } ?>
		document.getElementById("tab" + tab).style.display="block";
		document.getElementById("tabswitch" + tab).className="Tab TabSelected";
		}
	</script>
	<?php
	}
	
	
	
?>

<div id="tab0" class="TabbedPanel<?php if ($tabcount>0) { ?> StyledTabbedPanel<?php } ?>">
<div class="clearerleft"> </div>
<div>
<?php 
#  ----------------------------- Draw standard fields ------------------------
?>
<?php if ($show_resourceid) { ?><div class="itemNarrow"><h3><?php echo $lang["resourceid"]?></h3><p><?php echo $ref?></p></div><?php } ?>
<?php if ($show_access_field) { ?><div class="itemNarrow"><h3><?php echo $lang["access"]?></h3><p><?php echo @$lang["access" . $resource["access"]]?></p></div><?php } ?>
<?php if ($show_resource_type) { ?><div class="itemNarrow"><h3><?php echo $lang["resourcetype"]?></h3><p><?php echo  get_resource_type_name($resource["resource_type"])?></p></div><?php } ?>
<?php if ($show_hitcount){ ?><div class="itemNarrow"><h3><?php echo $resource_hit_count_on_downloads?$lang["downloads"]:$lang["hitcount"]?></h3><p><?php echo $resource["hit_count"]+$resource["new_hit_count"]?></p></div><?php } ?>
<?php hook("extrafields");?>
<?php
# contributed by field
$udata=get_user($resource["created_by"]);
if ($udata!==false)
	{
	?>
<?php if ($show_contributed_by){?>	<div class="itemNarrow"><h3><?php echo $lang["contributedby"]?></h3><p><?php if (checkperm("u")) { ?><a href="team/team_user_edit.php?ref=<?php echo $udata["ref"]?>"><?php } ?><?php echo highlightkeywords($udata["fullname"],$search)?><?php if (checkperm("u")) { ?></a><?php } ?></p></div><?php } ?>
	<?php
	}


# Show field data
$tabname="";
$tabcount=0;
$fieldcount=0;
$extra="";
for ($n=0;$n<count($fields);$n++)
	{
	$value=$fields[$n]["value"];
	
	# Handle expiry fields
	if ($fields[$n]["type"]==6 && $value!="" && $value<=date("Y-m-d H:i") && $show_expiry_warning) 
		{
		$extra.="<div class=\"RecordStory\"> <h1>" . $lang["warningexpired"] . "</h1><p>" . $lang["warningexpiredtext"] . "</p><p id=\"WarningOK\"><a href=\"#\" onClick=\"document.getElementById('RecordDownload').style.display='block';document.getElementById('WarningOK').style.display='none';\">" . $lang["warningexpiredok"] . "</a></p></div><style>#RecordDownload {display:none;}</style>";
		}
	
	if (($value!="") && ($value!=",") && ($fields[$n]["display_field"]==1) && ($access==0 || ($access==1 && !$fields[$n]["hide_when_restricted"])))
		{
		$title=htmlspecialchars(str_replace("Keywords - ","",$fields[$n]["title"]));
		//if ($fields[$n]["type"]==4 || $fields[$n]["type"]==6) {$value=NiceDate($value,false,true);}

		# Value formatting
		$value=i18n_get_translated($value);
		if (($fields[$n]["type"]==2) || ($fields[$n]["type"]==3) || ($fields[$n]["type"]==7) || ($fields[$n]["type"]==9)) {$value=TidyList($value);}
		$value_unformatted=$value; # store unformatted value for replacement also

		if ($fields[$n]["type"]!=8) # Do not convert HTML formatted fields (that are already HTML) to HTML.
			{
			$value=nl2br(htmlspecialchars($value));
			}
		
		# draw new tab panel?
		if (($tabname!=$fields[$n]["tab_name"]) && ($fieldcount>0))
			{
			$tabcount++;
			# Also display the custom formatted data $extra at the bottom of this tab panel.
			?><div class="clearerleft"> </div><?php echo $extra?></div></div><div class="TabbedPanel StyledTabbedPanel" style="display:none;" id="tab<?php echo $tabcount?>"><div><?php	
			$extra="";
			}
		$tabname=$fields[$n]["tab_name"];
		$fieldcount++;		

		if (trim($fields[$n]["display_template"])!="")
			{
			# Process the value using a plugin
			$plugin="../plugins/value_filter_" . $fields[$n]["name"] . ".php";
			if ($fields[$n]['value_filter']!=""){
				eval($fields[$n]['value_filter']);
			}
			else if (file_exists($plugin)) {include $plugin;}
			else if ($fields[$n]["type"]==4 || $fields[$n]["type"]==6) { 
				$value=NiceDate($value,false,true);
			}
			
			# Highlight keywords
			$value=highlightkeywords($value,$search,$fields[$n]["partial_index"],$fields[$n]["name"],$fields[$n]["keywords_index"]);

			# Use a display template to render this field
			$template=$fields[$n]["display_template"];
			$template=str_replace("[title]",$title,$template);
			$template=str_replace("[value]",$value,$template);
			$template=str_replace("[value_unformatted]",$value_unformatted,$template);
			$template=str_replace("[ref]",$ref,$template);
			$extra.=$template;
			}
		else
			{
			#There is a value in this field, but we also need to check again for a current-language value after the i18n_get_translated() function was called, to avoid drawing empty fields
			if ($value!=""){
				# Draw this field normally.
				
				
					# value filter plugin should be used regardless of whether a display template is used.
					$plugin="../plugins/value_filter_" . $fields[$n]["name"] . ".php";
					if ($fields[$n]['value_filter']!=""){
						eval($fields[$n]['value_filter']);
					}
					else if (file_exists($plugin)) {include $plugin;}
				    else if ($fields[$n]["type"]==4 || $fields[$n]["type"]==6) { 
						$value=NiceDate($value,false,true);
					}
				
				# Extra word wrapping to break really large words (e.g. URLs)
				$value=wordwrap($value,20,"<br />",true);
				
				# Highlight keywords
				$value=highlightkeywords($value,$search,$fields[$n]["partial_index"],$fields[$n]["name"],$fields[$n]["keywords_index"]);
				?><div class="itemNarrow"><h3><?php echo $title?></h3><p><?php echo $value?></p></div><?php
				}
			}
		}
	}
?><?php hook("extrafields2");?><div class="clearerleft"></div>
<?php echo $extra?>
</div>
</div>
<!-- end of tabbed panel-->
</div></div>
<div class="PanelShadow"></div>
</div>

<?php 
if (!$disable_geocoding) { 
  // only show this section if the resource is geocoded OR they have permission to do it themselves
  if ($edit_access||($resource["geo_lat"]!="" && $resource["geo_long"]!="")){ 
?>
    <!-- Begin Geolocation Section -->
    <div class="RecordBox">
    <div class="RecordPanel">
    <div class="Title"><?php echo $lang['location-title']; ?></div>
    <?php 
    if ($resource["geo_lat"]!="" && $resource["geo_long"]!="")
    	{
		?>
        <?php if ($edit_access) { ?>
        <p>&gt;&nbsp;<a href="geo_edit.php?ref=<?php echo $ref; ?>"><?php echo $lang['location-edit']; ?></a></p><?php } ?>
        
		<?php $mapheight=$view_mapheight; include "../include/geo_map.php";
		$zoom = $resource["mapzoom"];
		if (!($zoom>=2 && $zoom<=21)) {
			// set $zoom based on precision of specified position
			$zoom = 18;
			$siglon = round(100000*abs($resource["geo_long"]))%100000;
			$siglat = round(100000*abs($resource["geo_lat"]))%100000;
			if ($siglon%100000==0 && $siglat%100000==0) {
				$zoom = 3;
			} elseif ($siglon%10000==0 && $siglat%10000==0) {
				$zoom = 6;
			} elseif ($siglon%1000==0 && $siglat%1000==0) {
				$zoom = 10;
			} elseif ($siglon%100==0 && $siglat%100==0) {
				$zoom = 15;
			}
		}
		?>
		<script>
		var lonLat = new OpenLayers.LonLat( <?php echo $resource["geo_long"] ?>, <?php echo $resource["geo_lat"] ?> )
	          .transform(
	            new OpenLayers.Projection("EPSG:4326"), // transform from WGS 1984
	            map.getProjectionObject() // to Spherical Mercator Projection
	          );
	    var markers = new OpenLayers.Layer.Markers("<?php echo $lang["markers"]?>");
	    map.addLayer(markers);

	    <?php if (!hook("addmapicon")) { ?>
			markers.addMarker(new OpenLayers.Marker(lonLat));
		<?php } ?>

	    map.setCenter (lonLat, Math.min(<?php echo $zoom ?>, map.getNumZoomLevels() - 1));
	  </script>
    <?php     
		} else {?>
    <a href="geo_edit.php?ref=<?php echo $ref; ?>">&gt; <?php echo $lang['location-add'];?></a>
    <?php }?>
    </div>
    <div class="PanelShadow"></div>
    </div>
    <!-- End Geolocation Section -->
<?php    } 
      }?>

<?php hook("w2pspawn");?>

<?php 
// include collections listing
if ($view_resource_collections){ 
	include("resource_collection_list.php"); 
	}

// include optional ajax metadata report
if ($metadata_report && isset($exiftool_path) && $k==""){?>
        <div class="RecordBox">
        <div class="RecordPanel">  
        <div class="Title"><?php echo $lang['metadata-report']?></div>
        <div id="metadata_report"><a onclick="metadataReport(<?php echo $ref?>);document.getElementById('metadata_report').innerHTML='<?php echo $lang['pleasewait']?>';return false;" class="itemNarrow" href="#">&gt; <?php echo $lang['viewreport'];?></a><br></div>
        </div>
        <div class="PanelShadow"></div>
        </div>

<?php } ?>

<?php hook("customrelations"); //For future template/spawned relations in Web to Print plugin ?>

<?php
# -------- Related Resources (must be able to search for this to work)
if ($enable_related_resources && checkperm("s") && ($k=="")) {
$result=do_search("!related" . $ref);
if (count($result)>0) 
	{
	# -------- Related Resources by File Extension
	if($sort_relations_by_filetype){	
		#build array of related resources' file extensions
		for ($n=0;$n<count($result);$n++){
			$related_file_extension=$result[$n]["file_extension"];
			$related_file_extensions[]=$related_file_extension;
			}
		#reduce extensions array to unique values
		$related_file_extensions=array_unique($related_file_extensions);
		$count_extensions=0;
		foreach($related_file_extensions as $rext){
		?><!--Panel for related resources-->
		<div class="RecordBox">
		<div class="RecordPanel">  

		<div class="RecordResouce">
		<div class="Title"><?php echo str_replace_formatted_placeholder("%extension", $rext, $lang["relatedresources-filename_extension"]); ?></div>
		<?php
		# loop and display the results by file extension
		for ($n=0;$n<count($result);$n++)			
			{	
			if ($result[$n]["file_extension"]==$rext){
				$rref=$result[$n]["ref"];
				$title=$result[$n]["field".$view_title_field];

				# swap title fields if necessary

				if (isset($metadata_template_title_field) && isset($metadata_template_resource_type))
					{
					if ($result[$n]['resource_type']==$metadata_template_resource_type)
						{
						$title=$result[$n]["field".$metadata_template_title_field];
						}	
					}	
						
				?>
				
				<!--Resource Panel-->
				<div class="CollectionPanelShell">
				<table border="0" class="CollectionResourceAlign"><tr><td>
				<a target="main" href="view.php?ref=<?php echo $rref?>&search=<?php echo urlencode("!related" . $ref)?>"><?php if ($result[$n]["has_image"]==1) { ?><img border=0 src="<?php echo get_resource_path($rref,false,"col",false,$result[$n]["preview_extension"],-1,1,checkperm("w"),$result[$n]["file_modified"])?>" class="CollectImageBorder"/><?php } else { ?><img border=0 src="../gfx/<?php echo get_nopreview_icon($result[$n]["resource_type"],$result[$n]["file_extension"],true)?>"/><?php } ?></a></td>
				</tr></table>
				<div class="CollectionPanelInfo"><a target="main" href="view.php?ref=<?php echo $rref?>"><?php echo tidy_trim(i18n_get_translated($title),15)?></a>&nbsp;</div>
				</div>
				<?php		
				}
			}
		?>
		<div class="clearerleft"> </div>
		<?php $count_extensions++; if ($count_extensions==count($related_file_extensions)){?><a href="search.php?search=<?php echo urlencode("!related" . $ref) ?>"><?php echo $lang["clicktoviewasresultset"]?></a><?php }?>
		</div>
		</div>
		<div class="PanelShadow"></div>
		</div><?php
		} #end of display loop by resource extension
	} #end of IF sorted relations
	
	
	# -------- Related Resources (Default)
	else { 
		 ?><!--Panel for related resources-->
		<div class="RecordBox">
		<div class="RecordPanel">  

		<div class="RecordResouce">
		<div class="Title"><?php echo $lang["relatedresources"]?></div>
		<?php
    	# loop and display the results
    	for ($n=0;$n<count($result);$n++)            
        	{
        	$rref=$result[$n]["ref"];
			$title=$result[$n]["field".$view_title_field];

			# swap title fields if necessary

			if (isset($metadata_template_title_field) && isset($metadata_template_resource_type))
				{
				if ($result[$n]["resource_type"]==$metadata_template_resource_type)
					{
					$title=$result[$n]["field".$metadata_template_title_field];
					}	
				}	
	

			?>
        	<!--Resource Panel-->
        	<div class="CollectionPanelShell">
            <table border="0" class="CollectionResourceAlign"><tr><td>
            <a target="main" href="view.php?ref=<?php echo $rref?>&search=<?php echo urlencode("!related" . $ref)?>"><?php if ($result[$n]["has_image"]==1) { ?><img border=0 src="<?php echo get_resource_path($rref,false,"col",false,$result[$n]["preview_extension"],-1,1,checkperm("w"),$result[$n]["file_modified"])?>" class="CollectImageBorder"/><?php } else { ?><img border=0 src="../gfx/<?php echo get_nopreview_icon($result[$n]["resource_type"],$result[$n]["file_extension"],true)?>"/><?php } ?></a></td>
            </tr></table>
            <div class="CollectionPanelInfo"><a target="main" href="view.php?ref=<?php echo $rref?>"><?php echo tidy_trim(i18n_get_translated($title),15)?></a>&nbsp;</div>
        </div>
        <?php        
        }
    ?>
    <div class="clearerleft"> </div>
        <a href="search.php?search=<?php echo urlencode("!related" . $ref) ?>"><?php echo $lang["clicktoviewasresultset"]?></a>

    </div>
    </div>
    <div class="PanelShadow"></div>
    </div><?php
		}# end related resources display
	} 
	# -------- End Related Resources
	
	

if ($show_related_themes==true ){
# -------- Public Collections / Themes
$result=get_themes_by_resource($ref);
if (count($result)>0) 
	{
	?><!--Panel for related themes / collections -->
	<div class="RecordBox">
	<div class="RecordPanel">  
	
	<div class="RecordResouce BasicsBox">
	<div class="Title"><?php echo $lang["collectionsthemes"]?></div>
	<div class="VerticalNav">
	<ul>
	<?php
		# loop and display the results
		for ($n=0;$n<count($result);$n++)			
			{
			?>
			<li><a href="search.php?search=!collection<?php echo $result[$n]["ref"]?>"><?php echo (strlen($result[$n]["theme"])>0)?htmlspecialchars(str_replace("*","",i18n_get_translated($result[$n]["theme"])) . " / "):$lang["public"] . " : "; ?><?php if (!$collection_public_hide_owner) {echo htmlspecialchars($result[$n]["fullname"] . " / ");} ?><?php echo htmlspecialchars(i18n_get_translated($result[$n]["name"])); ?></a></li>
			<?php		
			}
		?>
	</ul>
	</div>
	</div>
	</div>
	<div class="PanelShadow"></div>
	</div><?php
	}} 
?>



<?php if ($enable_find_similar) { ?>
<!--Panel for search for similar resources-->
<div class="RecordBox">
<div class="RecordPanel"> 


<div class="RecordResouce">
<div class="Title"><?php echo $lang["searchforsimilarresources"]?></div>
<?php if ($resource["has_image"]==1) { ?>

<!--
<p>Find resources with a <a href="search.php?search=<?php echo urlencode("!rgb:" . $resource["image_red"] . "," . $resource["image_green"] . "," . $resource["image_blue"])?>">similar colour theme</a>.</p>
<p>Find resources with a <a href="search.php?search=<?php echo urlencode("!colourkey" . $resource["colour_key"]) ?>">similar colour theme (2)</a>.</p>
-->

<?php } ?>
<script type="text/javascript">
function UpdateResultCount()
	{
	// set the target of the form to be the result count iframe and submit
	document.getElementById("findsimilar").target="resultcount";
	document.getElementById("countonly").value="yes";
	document.getElementById("findsimilar").submit();
	document.getElementById("findsimilar").target="";
	document.getElementById("countonly").value="";
	}
</script>

<form method="post" action="find_similar.php" id="findsimilar">
<input type="hidden" name="resource_type" value="<?php echo $resource["resource_type"]?>">
<input type="hidden" name="countonly" id="countonly" value="">
<?php
$keywords=get_resource_top_keywords($ref,30);
$searchwords=split_keywords($search);
for ($n=0;$n<count($keywords);$n++)
	{
	?>
	<div class="SearchSimilar"><input type=checkbox name="keyword_<?php echo urlencode($keywords[$n])?>" value="yes"
	<?php if (in_array($keywords[$n],$searchwords)) {?>checked<?php } ?> onClick="UpdateResultCount();">&nbsp;<?php echo $keywords[$n]?></div>
	<?php
	}
?>
<div class="clearerleft"> </div>
<br />
<input name="search" type="submit" value="&nbsp;&nbsp;<?php echo $lang["searchbutton"]?>&nbsp;&nbsp;" id="dosearch"/>
<iframe src="blank.html" frameborder=0 scrolling=no width=1 height=1 style="visibility:hidden;" name="resultcount" id="resultcount"></iframe>
</form>
<div class="clearerleft"> </div>
</div>
</div>
<div class="PanelShadow"></div>
</div></div>
<?php } ?>



<?php } # end of block that requires search permissions


include "../include/footer.php";
?>
