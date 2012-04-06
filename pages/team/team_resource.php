<?php
/**
 * Resource management team center page (part of Team Center)
 * 
 * @package ResourceSpace
 * @subpackage Pages_Team
 */
include "../../include/db.php";
include "../../include/authenticate.php";if (!checkperm("t")) {exit ("Permission denied.");}
include "../../include/general.php";

include "../../include/header.php";

?>

<div class="BasicsBox"> 
  <h2>&nbsp;</h2>
  <h1><?php echo $lang["manageresources"]?></h1>
  <p><?php echo text("introtext")?></p>

	<div class="VerticalNav">
	<ul>
	
	<?php if (checkperm("c")): // Check if user can create resources ?>

        <?php if($upload_methods['single_upload']): // Test if in browser is allowed. ?>
			<li><a href="../edit.php?ref=-<?php echo $userref?>&amp;single=true"><?php echo $lang["addresource"]?></a></li>
		<?php endif // Test if in browser upload is allowed. ?>

		<?php if($upload_methods['in_browser_upload_java']): // Test if in browser is allowed. ?>
			<li><a href="../edit.php?ref=-<?php echo $userref?>&amp;java=true"><?php echo $lang["addresourcebatchbrowserjava"]?></a></li>
		<?php endif // Test if in browser upload is allowed. ?>

		<?php if($upload_methods['in_browser_upload']): // Test if in browser is allowed. ?>
			<li><a href="../edit.php?ref=-<?php echo $userref?>&amp;swf=true"><?php echo $lang["addresourcebatchbrowser"]?></a></li>
		<?php endif // Test if in browser upload is allowed. ?>

		<?php if($upload_methods['fetch_from_ftp']): // Test if fetching resources from FTP is allowed. ?>
			<li><a href="../edit.php?ref=-<?php echo $userref?>"><?php echo $lang["addresourcebatchftp"]?></a></li>
		<?php endif // Test if fetching resources from FTP is allowed. ?>

		<?php if($upload_methods['fetch_from_local_folder']): // Test if fetching resources from local upload folder is allowed. ?>
			<li><a href="../edit.php?ref=-<?php echo $userref?>&amp;local=true"><?php echo $lang["addresourcebatchlocalfolder"]?></a></li>
		<?php endif // Test if fetching resources from local upload folder is allowed. ?>

		<li><a href="../upload_swf.php?replace=true"><?php echo $lang["replaceresourcebatch"]?></a></li>    

		<li><a href="team_copy.php"><?php echo $lang["copyresource"]?></a></li>
		
		<?php if (checkperm("e-2")) { ?>
		<li><a href="../search.php?search=&archive=-2"><?php echo $lang["viewuserpendingsubmission"]?></a></li>
		<?php } ?>

		<?php if (checkperm("e-1")) { ?>
		<li><a href="../search.php?search=&archive=-1"><?php echo $lang["viewuserpending"]?></a></li>
		<?php } ?>
		
		<?php if (checkperm("e-2")) { ?>
		<li><a href="../search.php?search=!contributions<?php echo $userref?>&archive=-2"><?php echo $lang["viewcontributedps"]?></a></li>
		<?php } ?>
		
		<?php
		# If deleting resources is configured AND the deletion state is '3' (deleted) AND the user has permission to edit resources in this state, then show a link to list deleted resources.
		if (isset($resource_deletion_state) && $resource_deletion_state==3 && checkperm("e3")) { ?><li><a href="../search.php?search=&archive=3"><?php echo $lang["viewdeletedresources"]?></a></li>
		<?php } ?>

		<?php
			if ($use_temp_tables && $file_checksums){
				// MySQL temporary tables and file checksums must both be enabled for duplicate searching to work
				// also, rememember that it only works for resources that have a checksum
				// so if you're using offline generation of checksum hashes, make sure they have been updated
				// before running this search.
			?>
			<li><a href="../search.php?search=<?php echo urlencode("!duplicates")?>"><?php echo $lang["viewduplicates"]?></a></li>
		<?php } // end if checksums and temp tables turned on ?>

		<li><a href="../search.php?search=<?php echo urlencode("!unused")?>"><?php echo $lang["viewuncollectedresources"]?></a></li>
		<?php if (checkperm("k")): // Check if user can manage keywords and fields ?>
			<li><a href="team_related_keywords.php"><?php echo $lang["managerelatedkeywords"]?></a></li>
			<!--<li><a href="team_fields.php"><?php echo $lang["managefieldoptions"]?></a></li>-->
		<?php endif // Check if user can manage keywords and fields ?>

	<?php endif // Check if user can create resources ?>

	</ul>
	</div>

	<p><a href="team_home.php">&gt;&nbsp;<?php echo $lang["backtoteamhome"]?></a></p>
  </div>

<?php
include "../../include/footer.php";
?>
