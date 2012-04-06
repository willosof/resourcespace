<?php

include(dirname(__FILE__)."/../../include/db.php");
include(dirname(__FILE__)."/../../include/general.php");
include(dirname(__FILE__)."/../../include/image_processing.php");
include(dirname(__FILE__)."/../../include/resource_functions.php");
$api=true;

include(dirname(__FILE__)."/../../include/authenticate.php");

// required: check that this plugin is available to the user
if (!in_array("api_upload",$plugins)){die("no access");}


if (isset($_FILES['userfile'])){
 $ref=create_resource(getval("resourcetype",1),getval("archive",0),$userref);
 $path_parts=pathinfo($_FILES['userfile']['name']);
 $extension=strtolower($path_parts['extension']);  
 $filepath=get_resource_path($ref,true,"",true,$extension);

 print_r($_FILES);
 $result=move_uploaded_file($_FILES['userfile']['tmp_name'], $filepath);
 $wait=sql_query("update resource set file_extension='$extension',preview_extension='jpg',file_modified=now() ,has_image=0 where ref='$ref'");
 # Store original filename in field, if set
 global $filename_field;
 if (isset($filename_field))
    {
    $wait=update_field($ref,$filename_field,$_FILES['userfile']['name']);	
    }

 // extract metadata
 $wait=extract_exif_comment($ref,$extension);

 //create previews
 $wait=create_previews($ref,false,$extension);
 echo "resource created";
}

 else {echo "no file. Please post via curl with two posts: 'userfile' and 'key' as in <a href=".$baseurl."/plugins/api_upload/readme.txt>ReadMe</a>";}



