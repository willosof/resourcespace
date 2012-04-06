<?php
include "../../../include/db.php";
include "../../../include/authenticate.php"; if (!checkperm("u")) {exit ("Permission denied.");}
include "../../../include/general.php";


if (defined("PHP_EOL")) {$eol=PHP_EOL;} else {$eol="\r\n";}

if (getval("submit","")!="")
	{
	$magictouch_account_id=getvalescaped("magictouch_account_id","");
	$magictouch_secure=getvalescaped("magictouch_secure","");
	$magictouch_ext_exclude=explode(",",getvalescaped("magictouch_ext_exclude",""));
	$magictouch_view_page_sizes=explode(",",getvalescaped("magictouch_view_page_sizes",""));
	$magictouch_preview_page_sizes=explode(",",getvalescaped("magictouch_preview_page_sizes",""));
	if (isset($_POST['magictouch_rt_exclude'])){
		$magictouch_rt_exclude=$_POST['magictouch_rt_exclude'];
	}
	else {
		$magictouch_rt_exclude=array();
	}

	$config=array();
	$config['magictouch_account_id']=$magictouch_account_id;
	$config['magictouch_secure']=$magictouch_secure;
	$config['magictouch_rt_exclude']=$magictouch_rt_exclude;
	$config['magictouch_ext_exclude']=$magictouch_ext_exclude;
	$config['magictouch_view_page_sizes']=$magictouch_view_page_sizes;
	$config['magictouch_preview_page_sizes']=$magictouch_preview_page_sizes;
	set_plugin_config("magictouch",$config);
	
	redirect("pages/team/team_home.php");
	}


include "../../../include/header.php";
?>
<div class="BasicsBox"> 
  <h2>&nbsp;</h2>
  <h1><?php echo $lang["magictouch_configuration"];?></h1>
  
<?php 



if ($magictouch_account_id==""){
	echo $lang["get-magictouch"];
	echo "<br /><br />";
	echo $lang["configure-account-id-and-register-domain"];
	echo "<br /><br />";
}?>


<form id="form1" name="form1" method="post" action="">

<?php echo config_text_field("magictouch_account_id",$lang["magic_touch_key"],$magictouch_account_id);?>
<?php echo config_custom_select("magictouch_secure",$lang["https"],array("https","http"),$magictouch_secure);?>
<?php echo config_text_field("magictouch_ext_exclude",$lang["extensions_to_exclude"],implode(',',$magictouch_ext_exclude));?>   
<?php $rtypes=get_resource_types();
echo config_custom_select_multi("magictouch_rt_exclude",$lang["resource_types_to_exclude"],$rtypes,$magictouch_rt_exclude);?>
<?php echo config_text_field("magictouch_view_page_sizes",$lang["view_page_sizes"],implode(',',$magictouch_view_page_sizes));?>
<?php echo config_text_field("magictouch_preview_page_sizes",$lang["preview_page_sizes"],implode(',',$magictouch_preview_page_sizes));?>

   
<div class="Question">  
<label for="submit"></label> 
<input type="submit" name="submit" value="<?php echo $lang["save"]?>">   
</div><div class="clearerleft"></div>

</form>
</div>	
<?php include "../../../include/footer.php";
