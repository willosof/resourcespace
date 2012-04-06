<?php
/**
 * Team center home page (part of Team Center)
 * 
 * @package ResourceSpace
 * @subpackage Pages_Team
 */
include "../../include/db.php";
include "../../include/authenticate.php";if (!checkperm("t")) {exit ("Permission denied.");}
include "../../include/general.php";
include "../../include/resource_functions.php";

if ($send_statistics) {send_statistics();}

$overquota=overquota();


# Work out free space / usage for display
if (!file_exists($storagedir)) {mkdir($storagedir,0777);}

if (isset($disksize)) # Use disk quota rather than real disk size
	{
	$avail=$disksize*(1024*1024*1024);
	$used=get_total_disk_usage();
	$free=$avail-$used;
	}
else
	{		
	$avail=disk_total_space($storagedir);
	$free=disk_free_space($storagedir);
	$used=$avail-$free;
	}
if ($free<0) {$free=0;}
		
include "../../include/header.php";
?>


<div class="BasicsBox"> 
  <h2>&nbsp;</h2>
  <h1><?php echo $lang["teamcentre"]?></h1>
  <p><?php echo text("introtext")?></p>
  
	<div class="VerticalNav">
	<ul>
	
	<?php if (checkperm("c")) { 
		if ($overquota)
			{
			?><li><?php echo $lang["manageresources"]?> : <strong><?php echo $lang["manageresources-overquota"]?></strong></li><?php
			}
		else
			{
			?><li><a href="team_resource.php"><?php echo $lang["manageresources"]?></a></li><?php
			}
 		}
 	?>
			

	<?php if (checkperm("i")) { ?><li><a href="team_archive.php"><?php echo $lang["managearchiveresources"]?></a></li><?php } ?>
	
	<?php if (checkperm("R")) { ?><li><a href="team_request.php"><?php echo $lang["managerequestsorders"]?></a>
        &nbsp;&nbsp;<?php
        $condition = "";
        if (checkperm("Rb")) {$condition = "and assigned_to='" . $userref . "'";} # Only show pending for this user?
        $pending = sql_value("select count(*) value from request where status = 0 $condition",0);
        switch ($pending)
            {
            case 0:
                echo $lang["resources-with-requeststatus0-0"];
                break;
            case 1:
                echo $lang["resources-with-requeststatus0-1"];
                break;
            default:
                echo str_replace("%number",$pending,$lang["resources-with-requeststatus0-2"]);
                break;
            } ?>
    </li><?php } ?>

    <?php if (checkperm("r")) { ?><li><a href="team_research.php"><?php echo $lang["manageresearchrequests"]?></a>
        &nbsp;&nbsp;<?php
        $unassigned = sql_value("select count(*) value from research_request where status = 0",0);
        switch ($unassigned)
            {
            case 0:
                echo $lang["researches-with-requeststatus0-0"];
                break;
            case 1:
                echo $lang["researches-with-requeststatus0-1"];
                break;
            default:
                echo str_replace("%number", $unassigned,$lang["researches-with-requeststatus0-2"]);
                break;
            } ?> 
        </li><?php } ?>

    <?php if (checkperm("u")) { ?><li><a href="team_user.php"><?php echo $lang["manageusers"]?></a></li><?php } ?>

    <?php if (checkperm("o")) { ?><li><a href="team_content.php"><?php echo $lang["managecontent"]?></a></li><?php } ?>
    
    <li><a href="team_stats.php"><?php echo $lang["viewstatistics"]?></a></li>
    
    <li><a href="team_report.php"><?php echo $lang["viewreports"]?></a></li>

    <?php if (checkperm("m")) { ?><li><a href="team_mail.php"><?php echo $lang["sendbulkmail"]?></a></li><?php } ?>

	<?php if (checkperm("a")) { ?>
    <li><a href="team_export.php"><?php echo $lang["exportdata"]?></a></li>
    <li><a href="../check.php"><?php echo $lang["installationcheck"]?></a></li>
	<?php } ?>

	<?php hook("customteamfunction")?>
	
	<?php if (checkperm("a")) { ?>
	<?php if ($use_plugins_manager == true){ ?>
	<li><a href="team_plugins.php"><?php echo $lang["pluginssetup"]?></a></li>
	<?php } ?>
	<li><a href="../admin/index.php"><?php echo $lang["systemsetup"]?></a></li>
    <li><a href="team_reportbug.php"><?php echo $lang["reportbug"]?></a></li>
	<?php hook("customteamfunctionadmin")?>
	<?php } ?>
	
	</ul>
	</div>
	
<?php if (checkperm("u") && !checkperm("U")) { # Full admin access only to user/disk quota status
?>
<p><?php echo $lang["usersonline"]?>:
<?php
$active=get_active_users();
for ($n=0;$n<count($active);$n++) {if($n>0) {echo", ";}echo "<b>" . $active[$n]["username"] . "</b> (" . $active[$n]["t"] . ")";}
?>
</p>	

<p><?php echo $lang["diskusage"]?>: <b><?php echo round(($avail?$used/$avail:0)*100,0)?>%</b> (<?php echo $lang["available"]?>: <?php echo formatfilesize($avail)?>; <?php echo $lang["used"]?>: <?php echo formatfilesize($used)?>; <?php echo $lang["free"]?>:  <?php echo formatfilesize($free)?>)
</p>
<?php } ?>

</div>

<?php
include "../../include/footer.php";
?>
