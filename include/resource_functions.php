<?php
# Resource functions
# Functions to create, edit and index resources

function create_resource($resource_type,$archive=999,$user=-1)
	{
	# Create a new resource.
	global $always_record_resource_creator;

	if ($archive==999)
		{
		# Work out an appropriate default state
		$archive=0;
		if (!checkperm("e0")) {$archive=2;} # Can't create a resource in normal state? create in archive.
		}
	if ($archive==-2 || $archive==-1 || (isset($always_record_resource_creator) and $always_record_resource_creator))
		{
		# Work out user ref - note: only for content in status -2 and -1 (user submitted / pending review).
		global $userref;
		$user=$userref;
		} else {$user=-1;}
	
	sql_query("insert into resource(resource_type,creation_date,archive,created_by) values ('$resource_type',now(),'$archive','$user')");
	
	$insert=sql_insert_id();
	
	# Copying a resource of the 'pending review' state? Notify, if configured.
	if ($archive==-1)
		{
		notify_user_contributed_submitted(array($insert));
		}

	# set defaults for resource here (in case there are edit filters that depend on them)
	set_resource_defaults($insert);	

	# Always index the resource ID as a keyword
	remove_keyword_mappings($insert, $insert, -1);
	add_keyword_mappings($insert, $insert, -1);

	# Log this			
	daily_stat("Create resource",$insert);
	resource_log($insert,'c',0);

	return $insert;
	}
	
function save_resource_data($ref,$multi)
	{
	# Save all submitted data for resource $ref.
	# Also re-index all keywords from indexable fields.
		
	global $auto_order_checkbox,$userresourcedefaults,$multilingual_text_fields,$languages,$language;

	# Loop through the field data and save (if necessary)
	$errors=array();
	$fields=get_resource_field_data($ref,$multi);
	$expiry_field_edited=false;
	$resource_data=get_resource_data($ref);
	
	# save resource defaults
	# (do this here so that user can override them if the fields are visible.)
	set_resource_defaults($ref);	 
	
	for ($n=0;$n<count($fields);$n++)
		{
		if (!(
		
		# Not if field has write access denied
		checkperm("F" . $fields[$n]["ref"])
		||
		(checkperm("F*") && !checkperm("F-" . $fields[$n]["ref"]))
			
		))
			{
			if ($fields[$n]["type"]==2)
				{
				# construct the value from the ticked boxes
				$val=","; # Note: it seems wrong to start with a comma, but this ensures it is treated as a comma separated list by split_keywords(), so if just one item is selected it still does individual word adding, so 'South Asia' is split to 'South Asia','South','Asia'.
				$options=trim_array(explode(",",$fields[$n]["options"]));

				for ($m=0;$m<count($options);$m++)
					{
					$name=$fields[$n]["ref"] . "_" . md5($options[$m]);
					if (getval($name,"")=="yes")
						{
						if ($val!=",") {$val.=",";}
						$val.=$options[$m];
						}
					}
				}
			elseif ($fields[$n]["type"]==4 || $fields[$n]["type"]==6 || $fields[$n]["type"]==10)
				{
				# date type, construct the value from the date/time dropdowns
				$val=sprintf("%04d", getvalescaped("field_" . $fields[$n]["ref"] . "-y",""));
				if ((int)$val<=0) 
					{
					$val="";
					}
				elseif (($field=getvalescaped("field_" . $fields[$n]["ref"] . "-m",""))!="") 
					{
					$val.="-" . $field;
					if (($field=getvalescaped("field_" . $fields[$n]["ref"] . "-d",""))!="") 
						{
						$val.="-" . $field;
						if (($field=getval("field_" . $fields[$n]["ref"] . "-h",""))!="")
							{
							$val.=" " . $field . ":";
							if (($field=getvalescaped("field_" . $fields[$n]["ref"] . "-i",""))!="") 
								{
									$val.=$field;
								} 
							else 
								{
									$val.="00";
								}
							}
						}
					}
				}
			elseif ($multilingual_text_fields && ($fields[$n]["type"]==0 || $fields[$n]["type"]==1 || $fields[$n]["type"]==5))
				{
				# Construct a multilingual string from the submitted translations
				$val=getvalescaped("field_" . $fields[$n]["ref"],"");
				$val="~" . $language . ":" . $val;
				reset ($languages);
				foreach ($languages as $langkey => $langname)
					{
					if ($language!=$langkey)
						{
						$val.="~" . $langkey . ":" . getvalescaped("multilingual_" . $n . "_" . $langkey,"");
						}
					}
				}
			elseif ($fields[$n]["type"] == 3)
				{
				$val=getvalescaped("field_" . $fields[$n]["ref"],"");				
				// if it doesn't already start with a comma, add one
				if (substr($val,0,1) != ',')
					{
					$val = ','.$val;
					}
				}
			else
				{
				# Set the value exactly as sent.
				$val=getvalescaped("field_" . $fields[$n]["ref"],"");
				} 
			
			if (str_replace("\r\n","\n",$fields[$n]["value"])!== str_replace("\r\n","\n",unescape($val)))
				{
				//$testvalue=$fields[$n]["value"];var_dump($testvalue);$val=unescape($val);var_dump($val);
				//echo "FIELD:".$fields[$n]["value"]."!==ORIG:".unescape($val); 
				
				# This value is different from the value we have on record.

				# Write this edit to the log (including the diff) (unescaped is safe because the diff is processed later)
				resource_log($ref,'e',$fields[$n]["ref"],"",$fields[$n]["value"],unescape($val));

				# Expiry field? Set that expiry date(s) have changed so the expiry notification flag will be reset later in this function.
				if ($fields[$n]["type"]==6) {$expiry_field_edited=true;}

				# If 'resource_column' is set, then we need to add this to a query to back-update
				# the related columns on the resource table
				$resource_column=$fields[$n]["resource_column"];	

				# Purge existing data and keyword mappings, decrease keyword hitcounts.
				sql_query("delete from resource_data where resource='$ref' and resource_type_field='" . $fields[$n]["ref"] . "'");
				
				# Insert new data and keyword mappings, increase keyword hitcounts.
				sql_query("insert into resource_data(resource,resource_type_field,value) values('$ref','" . $fields[$n]["ref"] . "','" . escape_check($val) ."')");
	
				$oldval=$fields[$n]["value"];
				
				if ($fields[$n]["type"]==3 && substr($oldval,0,1) != ',')
					{
					# Prepend a comma when indexing dropdowns
					$oldval="," . $oldval;
					}
				
				if ($fields[$n]["keywords_index"]==1)
					{
					# Date field? These need indexing differently.
					$is_date=($fields[$n]["type"]==4 || $fields[$n]["type"]==6);
					
					remove_keyword_mappings($ref, i18n_get_indexable($oldval), $fields[$n]["ref"], $fields[$n]["partial_index"],$is_date);
					add_keyword_mappings($ref, i18n_get_indexable($val), $fields[$n]["ref"], $fields[$n]["partial_index"],$is_date);
					}
				
					# If this is a 'joined' field we need to add it to the resource column
					$joins=get_resource_table_joins();
					if (in_array($fields[$n]["ref"],$joins)){
						$val=strip_leading_comma($val);	
						sql_query("update resource set field".$fields[$n]["ref"]."='".escape_check($val)."' where ref='$ref'");
					}	
				
				}
			
			# Check required fields have been entered.
			if ($fields[$n]["required"]==1 && ($val=="" || $val==","))
				{
				global $lang;
				$errors[$fields[$n]["ref"]]=$lang["requiredfield"];
				}
			}
		}
    //die();
	# Always index the resource ID as a keyword
	remove_keyword_mappings($ref, $ref, -1);
	add_keyword_mappings($ref, $ref, -1);
	
	# Autocomplete any blank fields.
	autocomplete_blank_fields($ref);
	
	# Also save related resources field
	sql_query("delete from resource_related where resource='$ref' or related='$ref'"); # remove existing related items
	$related=explode(",",getvalescaped("related",""));
	# Make sure all submitted values are numeric
	$ok=array();for ($n=0;$n<count($related);$n++) {if (is_numeric(trim($related[$n]))) {$ok[]=trim($related[$n]);}}
	if (count($ok)>0) {sql_query("insert into resource_related(resource,related) values ($ref," . join("),(" . $ref . ",",$ok) . ")");}
					
	// Notify the resources team ($email_notify) if moving from pending review->submission.
	$archive=getvalescaped("archive",0,true);
	$oldarchive=sql_value("select archive value from resource where ref='$ref'",0);
	if ($oldarchive==-2 && $archive==-1 && $ref>0)
		{
		notify_user_contributed_submitted(array($ref));
		}
	if ($oldarchive==-1 && $archive==-2 && $ref>0)
		{
		notify_user_contributed_unsubmitted(array($ref));
		}	

	# Expiry field(s) edited? Reset the notification flag so that warnings are sent again when the date is reached.
	$expirysql="";
	if ($expiry_field_edited) {$expirysql=",expiry_notification_sent=0";}

	# Also update archive status and access level
	$oldaccess=sql_value("select access value from resource where ref='$ref'",0);
	$access=getvalescaped("access",0,true);
	if (getvalescaped("archive","")!="") # Only if archive has been sent
		{
		sql_query("update resource set archive='" . $archive . "',access='" . $access . "' $expirysql where ref='$ref'");
		
		if ($archive!=$oldarchive)
			{
			resource_log($ref,"s",0,"",$oldarchive,$archive);
			}

		if ($access!=$oldaccess)
			{
			resource_log($ref,"a",0,"",$oldaccess,$access);
			}

		
		}
		
	# For access level 3 (custom) - also save custom permissions
	if (getvalescaped("access",0)==3) {save_resource_custom_access($ref);}

	# Update XML metadata dump file
	update_xml_metadump($ref);		
	
	hook("aftersaveresourcedata");

	if (count($errors)==0) {return true;} else {return $errors;}
	}
	


function set_resource_defaults($ref) 
	{	
	# Save all the resource defaults
	global $userresourcedefaults;
	if ($userresourcedefaults!="")
		{
		$s=explode(";",$userresourcedefaults);
		for ($n=0;$n<count($s);$n++)
			{
			$e=explode("=",$s[$n]);
			# Find field(s) - multiple fields can be returned to support several fields with the same name.
			$f=sql_array("select ref value from resource_type_field where name='" . escape_check($e[0]) . "'");
			if (count($f)==0) {exit ("Field(s) with short name '" . $e[0] . "' not found in resource defaults for this user group.");}
			for ($m=0;$m<count($f);$m++)
				{
				update_field($ref,$f[$m],$e[1]);
				}
			}
		}
	}

function save_resource_data_multi($collection)
	{
	# Save all submitted data for collection $collection, this is for the 'edit multiple resources' feature
	# Loop through the field data and save (if necessary)
	$list=get_collection_resources($collection);
	$ref=$list[0];
	$fields=get_resource_field_data($ref,true);
	global $auto_order_checkbox;
	$expiry_field_edited=false;

	for ($n=0;$n<count($fields);$n++)
		{
		if (getval("editthis_field_" . $fields[$n]["ref"],"")!="")
			{
			if ($fields[$n]["type"]==2)
				{
				# construct the value from the ticked boxes
				$val=","; # Note: it seems wrong to start with a comma, but this ensures it is treated as a comma separated list by split_keywords(), so if just one item is selected it still does individual word adding, so 'South Asia' is split to 'South Asia','South','Asia'.
				$options=trim_array(explode(",",$fields[$n]["options"]));
				if ($auto_order_checkbox) {sort($options);}
				
				for ($m=0;$m<count($options);$m++)
					{
					$name=$fields[$n]["ref"] . "_" . md5($options[$m]);
					if (getval($name,"")=="yes")
						{
						if ($val!=",") {$val.=",";}
						$val.=$options[$m];
						}
					}
				}
			elseif ($fields[$n]["type"]==4 || $fields[$n]["type"]==6)
				{
				# date/expiry date type, construct the value from the date dropdowns
				$val=sprintf("%04d", getvalescaped("field_" . $fields[$n]["ref"] . "-y",""));
				if ((int)$val<=0) 
					{
					$val="";
					}
				elseif (($field=getvalescaped("field_" . $fields[$n]["ref"] . "-m",""))!="") 
					{
					$val.="-" . $field;
					if (($field=getvalescaped("field_" . $fields[$n]["ref"] . "-d",""))!="") 
						{
						$val.="-" . $field;
						if (($field=getval("field_" . $fields[$n]["ref"] . "-h",""))!="")
							{
							$val.=" " . $field . ":";
							if (($field=getvalescaped("field_" . $fields[$n]["ref"] . "-i",""))!="") 
								{
									$val.=$field;
								} 
							else 
								{
									$val.="00";
								}
							}
						}
					}
				}
			elseif ($fields[$n]["type"] == 3)
				{
				$val=getvalescaped("field_" . $fields[$n]["ref"],"");				
				// if it doesn't already start with a comma, add one
				if (substr($val,0,1) != ',')
					{
					$val = ','.$val;
					}
				}
			else
				{
				$val=getvalescaped("field_" . $fields[$n]["ref"],"");
				}
			$origval=$val;
			# Loop through all the resources and save.
			for ($m=0;$m<count($list);$m++)
				{
				$ref=$list[$m];
				$resource_sql="";

				# Work out existing field value.
				$existing=escape_check(sql_value("select value from resource_data where resource='$ref' and resource_type_field='" . $fields[$n]["ref"] . "'",""));
				
				# Find and replace mode? Perform the find and replace.
				if (getval("modeselect_" . $fields[$n]["ref"],"")=="FR")
					{
					$val=str_replace
						(
						getvalescaped("find_" . $fields[$n]["ref"],""),
						getvalescaped("replace_" . $fields[$n]["ref"],""),
						$existing
						);
					}
				
				# Append text/option(s) mode?
				if (getval("modeselect_" . $fields[$n]["ref"],"")=="AP")
					{
					if ($fields[$n]["type"]!=2 && $fields[$n]["type"]!=3)
						{
						# Automatically append a space when appending text types.
						$val=$existing . " " . $origval;
						}
					else
						{
						# Checkbox/dropdown types can just append immediately (a comma will already be present at the beginning of $origval).
						$val=$existing . $origval;
						}
					}
					
				# Remove text/option(s) mode?
				if (getval("modeselect_" . $fields[$n]["ref"],"")=="RM")
					{
					$val=str_replace($origval,"",$existing);
					}
					
				$val=strip_leading_comma($val);		
				#echo "<li>existing=$existing, new=$val";
				if ($existing!=str_replace("\\","",$val))
					{
					# This value is different from the value we have on record.
					
					# Write this edit to the log.
					resource_log($ref,'m',$fields[$n]["ref"],"",$existing,$val);
		
					# Expiry field? Set that expiry date(s) have changed so the expiry notification flag will be reset later in this function.
					if ($fields[$n]["type"]==6) {$expiry_field_edited=true;}
				
					# If this is a 'joined' field we need to add it to the resource column
					$joins=get_resource_table_joins();
					if (in_array($fields[$n]["ref"],$joins)){
						sql_query("update resource set field".$fields[$n]["ref"]."='".escape_check($val)."' where ref='$ref'");
					}		
						
					# Purge existing data and keyword mappings, decrease keyword hitcounts.
					sql_query("delete from resource_data where resource='$ref' and resource_type_field='" . $fields[$n]["ref"] . "'");
					
					# Insert new data and keyword mappings, increase keyword hitcounts.
					sql_query("insert into resource_data(resource,resource_type_field,value) values('$ref','" . $fields[$n]["ref"] . "','" . escape_check($val) . "')");
		
					$oldval=$existing;
					$newval=$val;
					
					if ($fields[$n]["type"]==3)
						{
						# Prepend a comma when indexing dropdowns
						$newval="," . $val;
						$oldval="," . $oldval;
						}
					
					if ($fields[$n]["keywords_index"]==1)
						{
						# Date field? These need indexing differently.
						$is_date=($fields[$n]["type"]==4 || $fields[$n]["type"]==6); 
						remove_keyword_mappings($ref,i18n_get_indexable($oldval),$fields[$n]["ref"],$fields[$n]["partial_index"],$is_date);
						add_keyword_mappings($ref,i18n_get_indexable($newval),$fields[$n]["ref"],$fields[$n]["partial_index"],$is_date);
						}
					}
				}
			}
		}
		
	# Also save related resources field
	if (getval("editthis_related","")!="")
		{
		$related=explode(",",getvalescaped("related",""));
		# Make sure all submitted values are numeric
		$ok=array();for ($n=0;$n<count($related);$n++) {if (is_numeric(trim($related[$n]))) {$ok[]=trim($related[$n]);}}

		for ($m=0;$m<count($list);$m++)
			{
			$ref=$list[$m];
			sql_query("delete from resource_related where resource='$ref' or related='$ref'"); # remove existing related items
			if (count($ok)>0) {sql_query("insert into resource_related(resource,related) values ($ref," . join("),(" . $ref . ",",$ok) . ")");}
			}
		}

	# Also update archive status
	if (getval("editthis_status","")!="")
		{
		$notifyrefs=array();
		for ($m=0;$m<count($list);$m++)
			{
			$ref=$list[$m];
			$archive=getvalescaped("archive",0);
			$oldarchive=sql_value("select archive value from resource where ref='$ref'",0);
			
			if ($oldarchive!=$archive)
				{
				sql_query("update resource set archive='" . $archive . "' where ref='$ref'");

				# Log
				resource_log($ref,"s",0,"",$oldarchive,$archive);

				if ($oldarchive==-2 && $archive==-1)
					{
					# Notify the admin users of this change.
					$notifyrefs[]=$ref;
					}
				}
			}
		if (count($notifyrefs)>0)
			{
			# Notify the admin users of any submitted resources.
			notify_user_contributed_submitted($notifyrefs);
			}
		}
	
	# Expiry field(s) edited? Reset the notification flag so that warnings are sent again when the date is reached.
	if ($expiry_field_edited)
		{
		if (count($list)>0)
			{
			sql_query("update resource set expiry_notification_sent=0 where ref in (" . join(",",$list) . ")");
			}
		}
	
	# Also update access level
	if (getval("editthis_access","")!="")
		{
		for ($m=0;$m<count($list);$m++)
			{
			$ref=$list[$m];
			$access=getvalescaped("access",0);
			$oldaccess=sql_value("select access value from resource where ref='$ref'","");
			
			if ($access!=$oldaccess)
				{
				sql_query("update resource set access='$access' where ref='$ref'");
				
				resource_log($ref,"a",0,"",$oldaccess,$access);
				}
			
			# For access level 3 (custom) - also save custom permissions
			if ($access==3) {save_resource_custom_access($ref);}
			}
		}
	
	# Update resource type?
	if (getval("editresourcetype","")!="")
		{
		for ($m=0;$m<count($list);$m++)
			{
			$ref=$list[$m];
			update_resource_type($ref,getvalescaped("resource_type",""));
			}
		}
		
	# Update location?
	if (getval("editlocation","")!="")
		{
		$location=explode(",",getvalescaped("location",""));
		if (count($list)>0) 
			{
			if (count($location)==2)
				{
				$geo_lat=(float)$location[0];
				$geo_long=(float)$location[1];
				sql_query("update resource set geo_lat=$geo_lat,geo_long=$geo_long where ref in (" . join(",",$list) . ")");
				}
			elseif (getvalescaped("location","")=="")
				{
				sql_query("update resource set geo_lat=null,geo_long=null where ref in (" . join(",",$list) . ")");
				}
			}
		}

	# Update mapzoom?
	if (getval("editmapzoom","")!="")
		{
		$mapzoom=getvalescaped("mapzoom","");
		if (count($list)>0)
			{
			if ($mapzoom!="")
				{
				sql_query("update resource set mapzoom=$mapzoom where ref in (" . join(",",$list) . ")");
				}
			else
				{
				sql_query("update resource set mapzoom=null where ref in (" . join(",",$list) . ")");
				}
			}
		}

	hook("saveextraresourcedata","",array($list));
		
	# Update XML metadata dump file for all edited resources.
	for ($m=0;$m<count($list);$m++)
		{
		update_xml_metadump($list[$m]);
		}
	
	hook("aftersaveresourcedata");	
	}

function remove_keyword_mappings($ref,$string,$resource_type_field,$partial_index=false,$is_date=false,$optional_column='',$optional_value='')
	{
	# Removes one instance of each keyword->resource mapping for each occurrence of that
	# keyword in $string.
	# This is used to remove keyword mappings when a field has changed.
	# We also decrease the hit count for each keyword.
	if (trim($string)=="") {return false;}
	$keywords=split_keywords($string,true,$partial_index,$is_date);
	for ($n=0;$n<count($keywords);$n++)
		{
		#echo "<li>removing " . $keywords[$n];
			if ($optional_column<>'' && $optional_value<>'')	# Check if any optional column value passed and include this condition
				{
				sql_query("delete from resource_keyword where resource='$ref' and keyword= ANY (select ref from keyword where keyword='" . escape_check($keywords[$n]) . "') and resource_type_field='$resource_type_field' and $optional_column= $optional_value limit 1");
				}
			else{
				sql_query("delete from resource_keyword where resource='$ref' and keyword= ANY (select ref from keyword where keyword='" . escape_check($keywords[$n]) . "') and resource_type_field='$resource_type_field' limit 1");
				}
			sql_query("update keyword set hit_count=hit_count-1 where keyword='" . escape_check($keywords[$n]) . "' limit 1");
		}	
	}
	
if (!function_exists("add_keyword_mappings")){		
function add_keyword_mappings($ref,$string,$resource_type_field,$partial_index=false,$is_date=false,$optional_column='',$optional_value='')
	{
	# For each instance of a keyword in $string, add a keyword->resource mapping.
	# Create keywords that do not yet exist.
	# Increase the hit count of each keyword that matches.
	# Store the position and field the string was entered against for advanced searching.
	if (trim($string)=="") {return false;}
	$keywords=split_keywords($string,true,$partial_index,$is_date);

	for ($n=0;$n<count($keywords);$n++)
		{
		$kw=substr($keywords[$n],0,100); # Trim keywords to 100 chars as this is the length of the keywords column.
		
		global $noadd;
		if (!(in_array($kw,$noadd)))
			{
			#echo "<li>adding " . $keywords[$n];
			$keyword=resolve_keyword($kw);
			if ($keyword===false)
				{
				# This is a new keyword. Create and discover the new keyword ref.
				sql_query("insert into keyword(keyword,soundex,hit_count) values ('" . escape_check($kw) . 	"',left('".soundex(escape_check($kw))."',10),0)");
				$keyword=sql_insert_id();
				#echo "<li>New keyword.";
				}
			# create mapping, increase hit count.
			if ($optional_column<>'' && $optional_value<>'')	# Check if any optional column value passed and add this
				{sql_query("insert into resource_keyword(resource,keyword,position,resource_type_field,$optional_column) values ('$ref','$keyword','$n','$resource_type_field','$optional_value')");}
			else  
				{sql_query("insert into resource_keyword(resource,keyword,position,resource_type_field) values ('$ref','$keyword','$n','$resource_type_field')");}
			sql_query("update keyword set hit_count=hit_count+1 where ref='$keyword' limit 1");
			
			# Log this
			daily_stat("Keyword added to resource",$keyword);
			}	
		}	
	}
}
	
function update_field($resource,$field,$value)
	{
	# Updates a field. Works out the previous value, so this is not efficient if we already know what this previous value is (hence it is not used for edit where multiple fields are saved)

	# Fetch some information about the field
	$fieldinfo=sql_query("select keywords_index,resource_column,partial_index,type from resource_type_field where ref='$field'");
	if (count($fieldinfo)==0) {return false;} else {$fieldinfo=$fieldinfo[0];}
	
	if ($fieldinfo["keywords_index"])
		{
		# Fetch previous value and remove the index for those keywords
		$existing=sql_value("select value from resource_data where resource='$resource' and resource_type_field='$field'","");
		remove_keyword_mappings($resource,i18n_get_indexable($existing),$field,$fieldinfo["partial_index"]);
		
		if (($fieldinfo['type'] == 2 || $fieldinfo['type'] == 3 || $fieldinfo['type'] == 7) && substr($value,0,1) <> ','){
			$value = ','.$value;
		}
		
		$value=strip_leading_comma($value);	
		
		# Index the new value
		add_keyword_mappings($resource,i18n_get_indexable($value),$field,$fieldinfo["partial_index"]);
		}
		
	# Delete the old value (if any) and add a new value.
	sql_query("delete from resource_data where resource='$resource' and resource_type_field='$field' limit 1");
	$value=escape_check($value);
	sql_query("insert into resource_data(resource,resource_type_field,value) values ('$resource','$field','$value')");
	
	if ($value=="") {$value="null";} else {$value="'" . $value . "'";}
		
	# If this is a 'joined' field we need to add it to the resource column
	$joins=get_resource_table_joins();
	if (in_array($field,$joins)){
		sql_query("update resource set field".$field."=".$value." where ref='$resource'");
		}			
		
	}

if (!function_exists("email_resource")){	
function email_resource($resource,$resourcename,$fromusername,$userlist,$message,$access=-1,$expires="",$useremail="",$from_name="",$cc="")
	{
	# Attempt to resolve all users in the string $userlist to user references.

	global $baseurl,$email_from,$applicationname,$lang,$userref;
	
	if ($useremail==""){$useremail=$email_from;}
	
	# remove any line breaks that may have been entered
	$userlist=str_replace("\\r\\n",",",$userlist);

	if (trim($userlist)=="") {return ($lang["mustspecifyoneusername"]);}
	$userlist=resolve_userlist_groups($userlist);
	$ulist=trim_array(explode(",",$userlist));
	$ulist=array_filter($ulist);
	$ulist=array_values($ulist);

	$emails=array();
	$key_required=array();
	
	for ($n=0;$n<count($ulist);$n++)
		{
		$uname=$ulist[$n];
		$email=sql_value("select email value from user where username='" . escape_check($uname) . "'",'');
		if ($email=='')
			{
			# Not a recognised user, if @ sign present, assume e-mail address specified
			if (strpos($uname,"@")===false) {return($lang["couldnotmatchallusernames"]);}
			$emails[$n]=$uname;
			$key_required[$n]=true;
			}
		else
			{
			# Add e-mail address from user account
			$emails[$n]=$email;
			$key_required[$n]=false;
			}
		}

	# Send an e-mail to each resolved user / e-mail address
	$subject="$applicationname: $resourcename";
	if ($fromusername==""){$fromusername=$applicationname;} // fromusername is used for describing the sender's name inside the email
	if ($from_name==""){$from_name=$applicationname;} // from_name is for the email headers, and needs to match the email address (app name or user name)
	
	if ($message!="") {$message="\n\n" . $lang["message"] . ": " . str_replace(array("\\n","\\r","\\"),array("\n","\r",""),$message);}
	for ($n=0;$n<count($emails);$n++)
		{
		$key="";
		# Do we need to add an external access key for this user (e-mail specified rather than username)?
		if ($key_required[$n])
			{
			$k=substr(md5(time()),0,10);
			sql_query("insert into external_access_keys(resource,access_key,user,access,expires) values ('$resource','$k','$userref','$access'," . (($expires=="")?"null":"'" . $expires . "'"). ");");
			$key="&k=". $k;
			}
		
		# make vars available to template
		global $watermark;       
		$templatevars['thumbnail']=get_resource_path($resource,true,"thm",false,"jpg",$scramble=-1,$page=1,($watermark)?(($access==1)?true:false):false);
		if (!file_exists($templatevars['thumbnail'])){
			$resourcedata=get_resource_data($resource);
			$templatevars['thumbnail']="../gfx/".get_nopreview_icon($resourcedata["resource_type"],$resourcedata["file_extension"],false);
		}
		$templatevars['url']=$baseurl . "/?r=" . $resource . $key;
		$templatevars['fromusername']=$fromusername;
		$templatevars['message']=$message;
		$templatevars['resourcename']=$resourcename;
		$templatevars['from_name']=$from_name;
		
		# Build message and send.
		$body=$templatevars['fromusername']." ". $lang["hasemailedyouaresource"] . $templatevars['message']."\n\n" . $lang["clicktoviewresource"] . "\n\n" . $templatevars['url'];
		send_mail($emails[$n],$subject,$body,$fromusername,$useremail,"emailresource",$templatevars,$from_name,$cc);
		
		# log this
		resource_log($resource,"E","",$notes=$ulist[$n]);
		
		}
		
	# Return an empty string (all OK).
	return "";
	}
}

function delete_resource($ref)
	{
	# Delete the resource, all related entries in tables and all files on disk
	
	if ($ref<0) {return false;} # Can't delete the template

	$resource=get_resource_data($ref);
	if (!$resource) {return false;} # Resource not found in database
	
	$current_state=$resource['archive'];
	
	global $resource_deletion_state;
	if (isset($resource_deletion_state) && $current_state!=3) # Really delete if already in the 'deleted' state.
		{
		# $resource_deletion_state is set. Do not delete this resource, instead move it to the specified state.
		sql_query("update resource set archive='" . $resource_deletion_state . "' where ref='" . $ref . "'");

        # log this so that administrator can tell who requested deletion
        resource_log($ref,'x','');
		
		# Remove the resource from any collections
		sql_query("delete from collection_resource where resource='$ref'");
			
		return true;
		}
	
	# Get info
	
	# Is transcoding
	if ($resource['is_transcoding']==1) {return false;} # Can't delete when transcoding

	# Delete files first
	$extensions = array();
	$extensions[]=$resource['file_extension']?$resource['file_extension']:"jpg";
	$extensions[]=$resource['preview_extension']?$resource['preview_extension']:"jpg";
	$extensions[]=$GLOBALS['ffmpeg_preview_extension'];
	$extensions[]='icc'; // also remove any extracted icc profiles
	$extensions=array_unique($extensions);
	
	foreach ($extensions as $extension)
		{
		$sizes=get_image_sizes($ref,true,$extension);
		foreach ($sizes as $size)
			{
			if (file_exists($size['path'])) {unlink($size['path']);}
			}
		}
	
	# Delete any alternative files
	$alternatives=get_alternative_files($ref);
	for ($n=0;$n<count($alternatives);$n++)
		{
		$path=get_resource_path($ref, true, "", true, $alternatives[$n]["file_extension"], -1, 1, false, "", $alternatives[$n]["ref"]);
		if (file_exists($path)) {unlink($path);}
		}
	
	# Log the deletion of this resource for any collection it was in. 
	$in_collections=sql_query("select * from collection_resource where resource = '$ref'");
	if (count($in_collections)>0){
		if (!function_exists("collection_log")){include ("collections_functions.php");}
		for($n=0;$n<count($in_collections);$n++)
			{
			collection_log($in_collections[$n]['collection'],'d',$in_collections[$n]['resource']);
			}
		}
		
	# Delete all database entries
	sql_query("delete from resource where ref='$ref'");
	sql_query("delete from resource_data where resource='$ref'");
	sql_query("delete from resource_dimensions where resource='$ref'");
	sql_query("delete from resource_keyword where resource='$ref'");
	sql_query("delete from resource_related where resource='$ref' or related='$ref'");
	sql_query("delete from collection_resource where resource='$ref'");
	sql_query("delete from resource_custom_access where resource='$ref'");
	sql_query("delete from external_access_keys where resource='$ref'");
	sql_query("delete from resource_alt_files where resource='$ref'");
		
	hook("afterdeleteresource");
	
	return true;
	}

function get_max_resource_ref()
	{
	# Returns the highest resource reference in use.
	return sql_value("select max(ref) value from resource",0);
	}

function get_resource_ref_range($lower,$higher)
	{
	# Returns an array of resource references in the range $lower to $upper.
	return sql_array("select ref value from resource where ref>='$lower' and ref<='$higher' and archive=0 order by ref",0);
	}
	
function copy_resource($from,$resource_type=-1)
	{
	# Create a new resource, copying all data from the resource with reference $from.
	# Note this copies only the data and not any attached file. It's very unlikely the
	# same file would be in the system twice, however users may want to clone an existing resource
	# to avoid reentering data if the resource is very similar.
	# If $resource_type if specified then the resource type for the new resource will be set to $resource_type
	# rather than simply copied from the $from resource.
	
	# Check that the resource exists
	if (sql_value("select count(*) value from resource where ref='$from'",0)==0) {return false;}
	
	# copy joined fields to the resource column
	$joins=get_resource_table_joins();
	$joins_sql="";
	foreach ($joins as $join){
		$joins_sql.=",field$join ";
	}
	
	$add="";

	# First copy the resources row
	sql_query("insert into resource($add resource_type,creation_date,rating,archive,access,created_by $joins_sql) select $add" . (($resource_type==-1)?"resource_type":("'" . $resource_type . "'")) . ",now(),rating,archive,access,created_by $joins_sql from resource where ref='$from';");
	$to=sql_insert_id();
	
	# Copying a resource of the 'pending review' state? Notify, if configured.
	$archive=sql_value("select archive value from resource where ref='$from'",0);
	if ($archive==-1)
		{
		notify_user_contributed_submitted(array($to));
		}
	
	# Set that this resource was created by this user. 
	# This needs to be done if either:
	# 1) The user does not have direct 'resource create' permissions and is therefore contributing using My Contributions directly into the active state
	# 2) The user is contributiting via My Contributions to the standard User Contributed pre-active states.
	global $userref;
	global $always_record_resource_creator;
	if ((!checkperm("c")) || $archive<0 || (isset($always_record_resource_creator) && $always_record_resource_creator))
		{
		# Update the user record
		sql_query("update resource set created_by='$userref' where ref='$to'");

		# Also add the user's username and full name to the keywords index so the resource is searchable using this name.
		global $username,$userfullname;
		add_keyword_mappings($to,$username . " " . $userfullname,-1);
		}
	
	# Now copy all data
	sql_query("insert into resource_data(resource,resource_type_field,value) select '$to',rd.resource_type_field,rd.value from resource_data rd join resource r on rd.resource=r.ref join resource_type_field rtf on rd.resource_type_field=rtf.ref and (rtf.resource_type=r.resource_type or rtf.resource_type=999 or rtf.resource_type=0) where rd.resource='$from'");
	
	# Copy relationships
	sql_query("insert into resource_related(resource,related) select '$to',related from resource_related where resource='$from'");

	# Copy access
	sql_query("insert into resource_custom_access(resource,usergroup,access) select '$to',usergroup,access from resource_custom_access where resource='$from'");

	# Set any resource defaults
	set_resource_defaults($to);

	# Reindex the resource so the resource_keyword entries are created
	reindex_resource($to);
	
	# Log this			
	daily_stat("Create resource",$to);
	resource_log($to,'c',0);

	hook("afternewresource", "", array($to));
	
	return $to;
	}
	
function resource_log($resource,$type,$field,$notes="",$fromvalue="",$tovalue="",$usage=0,$purchase_size="",$purchase_price=0)
	{
	global $userref,$k,$lang;
	
	# Do not log edits to user templates.
	if ($resource<0) {return false;}
	
	# Add difference to file.
	$diff="";
	if (($type=="e" || $type=="m") && $field!="" && ($fromvalue !== $tovalue))
		{
		$diff=log_diff($fromvalue,$tovalue);
		}

	if ($type=="s")
		{
		$diff=$lang["status" . $fromvalue] . " -> " . $lang["status" . $tovalue];
		}

	if ($type=="a")
		{
		$diff=$lang["access" . $fromvalue] . " -> " . $lang["access" . $tovalue];
		}
	
	sql_query("insert into resource_log(date,user,resource,type,resource_type_field,notes,diff,usageoption,purchase_size,purchase_price,access_key) values (now()," . (($userref!="")?"'$userref'":"null") . ",'$resource','$type'," . (($field!="")?"'$field'":"null") . ",'" . escape_check($notes) . "','" . escape_check($diff) . "','$usage','$purchase_size','$purchase_price'," . (isset($k)?"'$k'":"null") . ")");
	}

function get_resource_log($resource)
    {
    # Returns the log for a given resource.
    # The standard field titles are translated using $lang. Custom field titles are i18n translated.

    $log = sql_query("select r.date,u.username,u.fullname,r.type,f.title,r.notes,r.diff,r.usageoption,r.purchase_price,r.purchase_size,r.access_key,ekeys_u.fullname shared_by from resource_log r left outer join user u on u.ref=r.user left outer join resource_type_field f on f.ref=r.resource_type_field left outer join external_access_keys ekeys on r.access_key=ekeys.access_key left outer join user ekeys_u on ekeys.user=ekeys_u.ref where r.resource='$resource' order by r.date");
    for ($n = 0;$n<count($log);$n++)
        {
        $log[$n]["title"] = lang_or_i18n_get_translated($log[$n]["title"], "fieldtitle-");
        }
    return $log;
    }

function get_resource_type_name($type)
	{
	global $lang;
	if ($type==999) {return $lang["archive"];}
	return lang_or_i18n_get_translated(sql_value("select name value from resource_type where ref='$type'",""),"resourcetype-");
	}
	
function get_resource_custom_access($resource)
	{
	# Return a list of usergroups with the custom access level for resource $resource (if set)
	$sql="";
	if (checkperm("E"))
		{
		# Restrict to this group and children groups only.
		global $usergroup,$usergroupparent;
		$sql="where g.parent='$usergroup' or g.ref='$usergroup' or g.ref='$usergroupparent'";
		}
	return sql_query("select g.ref,g.name,g.permissions,c.access from usergroup g left outer join resource_custom_access c on g.ref=c.usergroup and c.resource='$resource' $sql order by (g.permissions like '%v%') desc,g.name");
	}
	
function save_resource_custom_access($resource)
	{
	$groups=get_resource_custom_access($resource);
	sql_query("delete from resource_custom_access where resource='$resource'");
	for ($n=0;$n<count($groups);$n++)
		{
		$usergroup=$groups[$n]["ref"];
		$access=getvalescaped("custom_" . $usergroup,0);
		sql_query("insert into resource_custom_access(resource,usergroup,access) values ('$resource','$usergroup','$access')");
		}
	}
	
function get_custom_access($resource,$usergroup)
	{
	global $custom_access;
	if ($custom_access==false) {return 0;} # Custom access disabled? Always return 'open' access for resources marked as custom.

	return sql_value("select access value from resource_custom_access where resource='$resource' and usergroup='$usergroup'",2);
	}
	
function get_themes_by_resource($ref)
	{
	global $theme_category_levels;

	$themestring="";
	for($n=1;$n<=$theme_category_levels;$n++){
		if ($n==1){$themeindex="";}else{$themeindex=$n;}
		$themestring.=",c.theme".$themeindex;
	}

	$themes=sql_query("select c.ref $themestring ,c.name,u.fullname from collection_resource cr join collection c on cr.collection=c.ref and cr.resource='$ref' and c.public=1 left outer join user u on c.user=u.ref order by length(theme) desc");
	# Combine the theme categories into one string so multiple category levels display correctly.
	$return=array();

	for ($n=0;$n<count($themes);$n++)
		{
		if (checkperm("j*") || checkperm("j" . $themes[$n]["theme"]))
			{
			$theme="";
			for ($x=1;$x<=$theme_category_levels;$x++){
				if ($x==1){$themeindex="";}else{$themeindex=$x;}
				if ($themes[$n]["theme".$themeindex]==""){break;}
				if ($themeindex!=""){$theme.=" / ";}

				if ($themes[$n]["theme".$themeindex]!="") {
					$theme.=$themes[$n]["theme".$themeindex];
				}
			}
			$themes[$n]["theme"]=$theme;
			$return[]=$themes[$n];
			}
		}
      
	return $return;
	}

function update_resource_type($ref,$type)
	{
	sql_query("update resource set resource_type='$type' where ref='$ref'");
	
	# Clear data that is no longer needed (data/keywords set for other types).
	sql_query("delete from resource_data where resource='$ref' and resource_type_field not in (select ref from resource_type_field where resource_type='$type' or resource_type=999 or resource_type=0)");
	sql_query("delete from resource_keyword where resource='$ref' and resource_type_field!=-1 and resource_type_field not in (select ref from resource_type_field where resource_type='$type' or resource_type=999 or resource_type=0)");	
		
	}
	
function relate_to_array($ref,$array)	
	{
	# Relates a resource to each in a simple array of ref numbers
		sql_query("delete from resource_related where resource='$ref' or related='$ref'");  
		sql_query("insert into resource_related(resource,related) values ($ref," . join("),(" . $ref . ",",$array) . ")");
	}		

function get_exiftool_fields($resource_type)
	{
	# Returns a list of exiftool fields, which are basically fields with an 'exiftool field' set.
	return sql_query("select ref,type,exiftool_field,exiftool_filter,options,name from resource_type_field where length(exiftool_field)>0 and (resource_type='$resource_type' or resource_type='0')  order by exiftool_field");
	}

function write_metadata($path,$ref)
	{
	global $exiftool_remove_existing,$storagedir,$exiftool_write,$exiftool_no_process;
	
	# Fetch file extension
	$resource_data=get_resource_data($ref);
	$extension=$resource_data["file_extension"];
	$resource_type=$resource_data["resource_type"];

	$exiftool_fullpath = get_utility_path("exiftool");
	
	if (($exiftool_fullpath!=false) && ($exiftool_write) && !in_array($extension,$exiftool_no_process))
		{
		$filename = pathinfo($path);
		$filename = $filename['basename'];	
		$tmpfile=get_temp_dir() . "/" . $filename;
		copy($path,$tmpfile);
	
		#Now that we have already copied the original file, we can use exiftool's overwrite_original on the tmpfile.
		# Argument -E: escape values for HTML. Used for handling foreign characters in shells not using UTF-8.
		$command = $exiftool_fullpath . " -overwrite_original -E ";
		if ($exiftool_remove_existing) {$command.= "-EXIF:all -XMP:all= -IPTC:all= ";}
		$write_to=get_exiftool_fields($resource_type);
		for($i=0;$i< count($write_to);$i++)
			{
			$fieldtype=$write_to[$i]['type'];
			$field=explode(",",$write_to[$i]['exiftool_field']);
			# write datetype fields as ISO 8601 date ("c") 
			if ($fieldtype=="4"){$writevalue=date("c",strtotime(get_data_by_field($ref,$write_to[$i]['ref'])));}
			else {$writevalue=get_data_by_field($ref,$write_to[$i]['ref']);}
			
			# Remove initial comma (for checkbox lists)
			if (substr($writevalue,0,1)==",") {$writevalue=substr($writevalue,1);}
			
			foreach ($field as $field)
				{
				$command.= escapeshellarg("-" . $field . "=" . htmlentities($writevalue, ENT_QUOTES, "UTF-8")) . " ";
				}
			}
			$command.= " " . escapeshellarg($tmpfile);
			$output=run_command($command);
		return $tmpfile;
		}
	else
		{
		return false;
		}
	}

function delete_exif_tmpfile($tmpfile)
{
	if(file_exists($tmpfile)){unlink ($tmpfile);}
}

function import_resource($path,$type,$title,$ingest=false)
	{
	# Import the resource at the given path
	# This is used by staticsync.php and Camillo's SOAP API
	# Note that the file will be used at it's present location and will not be copied.

	global $syncdir,$staticsync_prefer_embedded_title;

	# Create resource
	$r=create_resource($type);
			
	# Work out extension based on path
	$extension=explode(".",$path);$extension=trim(strtolower(end($extension)));

	# file_path should only really be set to indicate a staticsync location. Otherwise, it should just be left blank.
	if ($ingest){$file_path="";} else {$file_path=escape_check($path);}

	# Store extension/data in the database
	sql_query("update resource set archive=0,file_path='".$file_path."',file_extension='$extension',preview_extension='$extension',file_modified=now() where ref='$r'");		
			
	# Store original filename in field, if set
	if (!$ingest)
		{
		# This file remains in situ; store the full path in file_path to indicate that the file is stored remotely.
		global $filename_field;
		if (isset($filename_field))
			{

			$s=explode("/",$path);
			$filename=end($s);
				
			update_field($r,$filename_field,$filename);
			}
		}
	else
		{
		# This file is being ingested. Store only the filename.
		$s=explode("/",$path);
		$filename=end($s);
		
		global $filename_field;
		if (isset($filename_field))
			{
			update_field($r,$filename_field,$filename);
			}
			
		# Move the file
		global $syncdir;
		$destination=get_resource_path($r,true,"",true,$extension);	
		$result=rename($syncdir . "/" . $path,$destination);
		if ($result===false)
			{
			# The rename failed. The file is possibly still being copied or uploaded and must be ignored on this pass.
			# Delete the resouce just created and return false.
			delete_resource($r);
			return false;
			}
		chmod($destination,0777);	
		}

	# generate title and extract embedded metadata
	# order depends on which title should be the default (embedded or generated)
	if ($staticsync_prefer_embedded_title)
		{
		update_field($r,8,$title);
		extract_exif_comment($r,$extension);
	} else {
		extract_exif_comment($r,$extension);	
		update_field($r,8,$title);
	}

	
	# Ensure folder is created, then create previews.
	get_resource_path($r,false,"pre",true,$extension);	
	
	# Generate previews/thumbnails (if configured i.e if not completed by offline process 'create_previews.php')
	global $enable_thumbnail_creation_on_upload;
	if ($enable_thumbnail_creation_on_upload) {create_previews($r,false,$extension);}

	# Pass back the newly created resource ID.
	return $r;
	}

function get_alternative_files($resource)
	{
	# Returns a list of alternative files for the given resource
	return sql_query("select ref,name,description,file_name,file_extension,file_size,creation_date,alt_type from resource_alt_files where resource='$resource' order by file_size desc");
	}
	
function add_alternative_file($resource,$name,$description="",$file_name="",$file_extension="",$file_size=0,$alt_type='')
	{
	sql_query("insert into resource_alt_files(resource,name,creation_date,description,file_name,file_extension,file_size,alt_type) values ('$resource','" . escape_check($name) . "',now(),'" . escape_check($description) . "','" . escape_check($file_name) . "','" . escape_check($file_extension) . "','" . escape_check($file_size) . "','" . escape_check($alt_type) . "')");
	return sql_insert_id();
	}
	
function delete_alternative_file($resource,$ref)
	{
	# Delete any uploaded file.
	$info=get_alternative_file($resource,$ref);
	$path=get_resource_path($resource, true, "", true, $info["file_extension"], -1, 1, false, "", $ref);
	if (file_exists($path)) {unlink($path);}
	
        // run through all possible extensions/sizes
	$extensions = array();
	$extensions[]=$info['file_extension']?$info['file_extension']:"jpg";
	$extensions[]=isset($info['preview_extension'])?$info['preview_extension']:"jpg";
	$extensions[]=$GLOBALS['ffmpeg_preview_extension'];
        $extensions[]='jpg'; // always look for jpegs, just in case
	$extensions[]='icc'; // always look for extracted icc profiles
	$extensions=array_unique($extensions);
        $sizes = sql_array('select id value from preview_size');
	
        // in some cases, a jpeg original is generated for non-jpeg files like PDFs. Delete if it exists.
        $path=get_resource_path($resource, true,'', true, 'jpg', -1, 1, false, "", $ref);
        if (file_exists($path)) {
            unlink($path);
        }

        // in some cases, a mp3 original is generated for non-mp3 files like WAVs. Delete if it exists.
        $path=get_resource_path($resource, true,'', true, 'mp3', -1, 1, false, "", $ref);
        if (file_exists($path)) {
            unlink($path);
        }

        foreach ($extensions as $extension){
            foreach ($sizes as $size){
                $page = 1;
                $lastpage = 0;
                while ($page <> $lastpage){
                    $lastpage = $page;
                    $path=get_resource_path($resource, true, $size, true, $extension, -1, $page, false, "", $ref);
                    if (file_exists($path)) {
                        unlink($path);
                        $page++;
                    }
                }
            }
        }
        
	# Delete the database row
	sql_query("delete from resource_alt_files where resource='$resource' and ref='$ref'");
	
	# Update disk usage
	update_disk_usage($resource);
	}
	
function get_alternative_file($resource,$ref)
	{
	# Returns the row for the requested alternative file
	$return=sql_query("select ref,name,description,file_name,file_extension,file_size,creation_date,alt_type from resource_alt_files where resource='$resource' and ref='$ref'");
	if (count($return)==0) {return false;} else {return $return[0];}
	}
	
function save_alternative_file($resource,$ref)
	{
	# Saves the 'alternative file' edit form back to the database
	$sql="";
	
	# Uploaded file provided?
	if (array_key_exists("userfile",$_FILES))
    	{
    	# Fetch filename / path
    	$processfile=$_FILES['userfile'];
   	    $filename=strtolower(str_replace(" ","_",$processfile['name']));
    
    	# Work out extension
    	$extension=explode(".",$filename);$extension=trim(strtolower($extension[count($extension)-1]));

		# Find the path for this resource.
    	$path=get_resource_path($resource, true, "", true, $extension, -1, 1, false, "", $ref);

		# Debug
		debug("Uploading alternative file $ref with extension $extension to $path");

		if ($filename!="")
			{
			$result=move_uploaded_file($processfile['tmp_name'], $path);
			if ($result==false)
				{
				exit("File upload error. Please check the size of the file you are trying to upload.");
				}
			else
				{
				chmod($path,0777);
				$file_size=@filesize($path);
				$sql.=",file_name='" . escape_check($filename) . "',file_extension='" . escape_check($extension) . "',file_size='" . $file_size . "',creation_date=now()";
				}
			
			# Preview creation for alternative files (enabled via config)
			global $alternative_file_previews;
			if ($alternative_file_previews)
				{
				create_previews($resource,false,$extension,false,false,$ref);
				}
				
			}
		}
	# Save data back to the database.
	sql_query("update resource_alt_files set name='" . getvalescaped("name","") . "',description='" . getvalescaped("description","") . "',alt_type='" . getvalescaped("alt_type","") . "' $sql where resource='$resource' and ref='$ref'");
	
	# Update disk usage
	update_disk_usage($resource);
	}
	
if (!function_exists("user_rating_save")){	
function user_rating_save($userref,$ref,$rating)
	{
	# Save a user rating for a given resource
	$resource=get_resource_data($ref);
	
	# Recalculate the averate rating
	$total=$resource["user_rating_total"]; if ($total=="") {$total=0;}
	$count=$resource["user_rating_count"]; if ($count=="") {$count=0;}
	
	# modify behavior to allow only one current rating per user (which can be re-edited)
	global $user_rating_only_once;
	if ($user_rating_only_once){
		$ratings=array();
		$ratings=sql_query("select user,rating from user_rating where ref='$ref'");
		
		#Calculate ratings total and get current rating for user if available
		$total=0;
		$current="";
		for ($n=0;$n<count($ratings);$n++){
			$total+=$ratings[$n]['rating'];
			
			if ($ratings[$n]['user']==$userref){
				$current=$ratings[$n]['rating'];
				}
			}
		# Calculate Count
		$count=count($ratings);
		
		# if user has a current rating, subtract the old rating and add the new one.
		if ($current!=""){
			$total=$total-$current+$rating;
			if ($rating == 0) {  //rating remove feature
				sql_query("delete from user_rating where user='$userref' and ref='$ref'");
				$count--;
			} else {
				sql_query("update user_rating set rating='$rating' where user='$userref' and ref='$ref'");
			}
		}
		
		# if user does not have a current rating, add it 
		else {
			if ($rating != 0) {  //rating remove feature
				$total=$total+$rating;
				$count++;
				sql_query("insert into user_rating (user,ref,rating) values ('$userref','$ref','$rating')");
			}
		}

	}	
	else {
		# If not using $user_rating_only_once, Increment the total and count 
		$total+=$rating;
		$count++;
	}
	
	if ($count==0){
		# avoid division by zero
		$average=$total;
	} else {
	# work out a new average.
	$average=ceil($total/$count);
	}	
	
	# Save to the database
	sql_query("update resource set user_rating='$average',user_rating_total='$total',user_rating_count='$count' where ref='$ref'");
		
	}
}

				
function notify_user_contributed_submitted($refs)
	{
	// Send a notification mail to the administrators when resources are moved from "User Contributed - Pending Submission" to "User Contributed - Pending Review"
	global $notify_user_contributed_submitted,$applicationname,$email_notify,$baseurl,$lang;
	if (!$notify_user_contributed_submitted) {return false;} # Only if configured.
	
	$htmlbreak="";
	global $use_phpmailer;
	if ($use_phpmailer){$htmlbreak="<br><br>";}
	
	$list="";
	for ($n=0;$n<count($refs);$n++)
		{
		$url="";
		$url=$baseurl . "/?r=" . $refs[$n];
		
		if ($use_phpmailer){$url="<a href=\"$url\">$url</a>";}
		
		$list.=$htmlbreak . $url . "\n\n";
		}
		
	$list.=$htmlbreak;	
	
	$templatevars['url']=$baseurl . "/pages/search.php?search=!userpending";	
	$templatevars['list']=$list;
		
	$message=$lang["userresourcessubmitted"] . "\n\n". $templatevars['list'] . $lang["viewalluserpending"] . "\n\n" . $templatevars['url'];
	
	send_mail($email_notify,$applicationname . ": " . $lang["status-1"],$message,"","","emailnotifyresourcessubmitted",$templatevars);
	}
	
function notify_user_contributed_unsubmitted($refs)
	{
	// Send a notification mail to the administrators when resources are moved from "User Contributed - Pending Submission" to "User Contributed - Pending Review"
	
	global $notify_user_contributed_unsubmitted,$applicationname,$email_notify,$baseurl,$lang;
	if (!$notify_user_contributed_unsubmitted) {return false;} # Only if configured.
	
	$htmlbreak="";
	global $use_phpmailer;
	if ($use_phpmailer){$htmlbreak="<br><br>";}
	
	$list="";

	for ($n=0;$n<count($refs);$n++)
		{
		$url="";	
		$url=$baseurl . "/?r=" . $refs[$n];
		
		if ($use_phpmailer){$url="<a href=\"$url\">$url</a>";}
		
		$list.=$htmlbreak . $url . "\n\n";
		}
	
	$list.=$htmlbreak;		

	$templatevars['url']=$baseurl . "/pages/search.php?search=!userpending";	
	$templatevars['list']=$list;
		
	$message=$lang["userresourcesunsubmitted"]."\n\n". $templatevars['list'] . $lang["viewalluserpending"] . "\n\n" . $templatevars['url'];

	send_mail($email_notify,$applicationname . ": " . $lang["status-2"],$message,"","","emailnotifyresourcesunsubmitted",$templatevars);
	}	
	
	
function get_fields_with_options()
{
    # Returns a list of fields that have option lists (checking user permissions).
    # The standard field titles are translated using $lang. Custom field titles are i18n translated.
    # Used for 'manage field options' page.

    # Executes query.
    $fields = sql_query("select ref, name, title, type, options ,order_by, keywords_index, partial_index, resource_type, resource_column, display_field, use_for_similar, iptc_equiv, display_template, tab_name, required, smart_theme_name, exiftool_field, advanced_search, simple_search, help_text, display_as_dropdown from resource_type_field where type=2 or type=3 order by resource_type,order_by");

    # Applies permissions and translates field titles in the newly created array.
    $return = array();
    for ($n = 0;$n<count($fields);$n++) {
        if ((checkperm("f*") || checkperm("f" . $fields[$n]["ref"]))
        && !checkperm("f-" . $fields[$n]["ref"])) {
            $fields[$n]["title"] = lang_or_i18n_get_translated($fields[$n]["title"], "fieldtitle-");
            $return[] = $fields[$n];
        }
    }
    return $return;
}

function get_field($field)
{
    # A standard field title is translated using $lang.  A custom field title is i18n translated.

    # Executes query.
    $r = sql_query("select ref, name, title, type, options ,order_by, keywords_index, partial_index, resource_type, resource_column, display_field, use_for_similar, iptc_equiv, display_template, tab_name, required, smart_theme_name, exiftool_field, advanced_search, simple_search, help_text, display_as_dropdown from resource_type_field where ref='$field'");

    # Translates the field title if the searched field is found.
    if (count($r)==0) {
        return false;
    }
    else {
        $r[0]["title"] = lang_or_i18n_get_translated($r[0]["title"], "fieldtitle-");
        return $r[0];
    }
}

function get_field_options_with_stats($field)
	{
	# For a given field, list all options with usage stats.
	# This is for the 'manage field options' page.

	$rawoptions=sql_value("select options value from resource_type_field where ref='$field'","");
	$options=trim_array(explode(",",i18n_get_translated($rawoptions)));
	$rawoptions=trim_array(explode(",",$rawoptions));
	
	# For the given field, fetch a stats count for each keyword.
	$usage=sql_query("select rk.resource_type_field,k.keyword,count(*) c from resource_keyword rk join keyword k on rk.keyword=k.ref where resource_type_field='$field' group by k.keyword");
	
	$return=array();
	for ($n=0;$n<count($options);$n++)
		{
		# Find the option in the usage array and extract the count
		$count=0;
		for ($m=0;$m<count($usage);$m++)
			{
			$keyword=get_keyword_from_option($options[$n]);
			if ($keyword==$usage[$m]["keyword"]) {$count=$usage[$m]["c"];}
			}
			
		$return[]=array("option"=>$options[$n],"rawoption"=>$rawoptions[$n],"count"=>$count);
		}
	return $return;
	}
	
function save_field_options($field)
	{
	# Save the field options after editing.
	global $languages;
	
	$fielddata=get_field($field);
	$options=trim_array(explode(",",$fielddata["options"]));

	for ($n=0;$n<count($options);$n++)
		{
		if (getval("submit_field_" . $n,"")!="")
			{
			# This option/language combination is being renamed.

			# Construct a new option from the posted languages
			$new="";
			foreach ($languages as $langcode=>$langname)
				{
				$val=getvalescaped("field_" . $langcode . "_" . $n,"");
				if ($val!="") {$new.="~" . $langcode . ":" . $val;}
				}

			# Construct a new options value by creating a new array replacing the item in position $n
			$newoptions=array_merge(array_slice($options,0,$n),array($new),array_slice($options,$n+1));

			# Update the options field.
			sql_query("update resource_type_field set options='" . escape_check(join(", ",$newoptions)) . "' where ref='$field'");
			
			# Loop through all matching resources.
			# The matches list uses 'like' so could potentially return values that do not have this option set. However each value list split out and analysed separately.
			$matching=sql_query("select resource,value from resource_data where resource_type_field='$field' and value like '%" . escape_check($options[$n]) . "%'");
			for ($m=0;$m<count($matching);$m++)
				{
				$ref=$matching[$m]["resource"];
				#echo "Processing $ref to update " . $options[$n] . "<br>existing value is " . $matching[$m]["value"] . "<br/>";
								
				$set=trim_array(explode(",",$matching[$m]["value"]));
				
				# Construct a new value omitting the old and adding the new.
				$newval=array();
				for ($s=0;$s<count($set);$s++)
					{
					if ($set[$s]!==$options[$n]) {$newval[]=$set[$s];}
					}
				$newval[]=$new; # Set the new value on the end of this string
				$newval=join(",",$newval);
				
				#echo "Old value = '" . $matching[$m]["value"] . "', new value = '" . $newval . "'";
				
				if ($matching[$m]["value"]!== $newval)
					{
					# Value has changed. Update.

					# Delete existing keywords index for this field.
					sql_query("delete from resource_keyword where resource='$ref' and resource_type_field='$field'");
					
					# Store value and reindex
					update_field($ref,$field,$newval);
					}
				}
			
			}


		if (getval("delete_field_" . $n,"")!="")
			{
			# This field option is being deleted.
			
			# Construct a new options value by creating a new array ommitting the item in position $n
			$new=array_merge(array_slice($options,0,$n),array_slice($options,$n+1));
			
			sql_query("update resource_type_field set options='" . escape_check(join(", ",$new)) . "' where ref='$field'");
			
			# Loop through all matching resources.
			# The matches list uses 'like' so could potentially return values that do not have this option set. However each value list split out and analysed separately.
			$matching=sql_query("select resource,value from resource_data where resource_type_field='$field' and value like '%" . escape_check($options[$n]) . "%'");
			for ($m=0;$m<count($matching);$m++)
				{
				$ref=$matching[$m]["resource"];
				#echo "Processing $ref to remove " . $options[$n] . "<br>existing value is " . $matching[$m]["value"] . "<br/>";
								
				$set=trim_array(explode(",",$matching[$m]["value"]));
				$new=array();
				for ($s=0;$s<count($set);$s++)
					{
					if ($set[$s]!==$options[$n]) {$new[]=$set[$s];}
					}
				$new=join(",",$new);
				
				if ($matching[$m]["value"]!== $new)
					{
					# Value has changed. Update.

					# Delete existing keywords index for this field.
					sql_query("delete from resource_keyword where resource='$ref' and resource_type_field='$field'");
					
					# Store value and reindex
					update_field($ref,$field,$new);
					}
				}
			}
		}
	}
	
function get_resources_matching_keyword($keyword,$field)
	{
	# Returns an array of resource references for resources matching the given keyword string.
	$keyref=resolve_keyword($keyword);echo $keyref;
	return sql_array("select distinct resource value from resource_keyword where keyword='$keyref' and resource_type_field='$field'");
	}
	
function get_keyword_from_option($option)
	{
	# For the given field option, return the keyword that will be indexed.
	$keywords=split_keywords("," . $option);
	return $keywords[1];
	}
	
function add_field_option($field,$option)
	{
	sql_query("update resource_type_field set options=concat(options,', " . escape_check($option) . "') where ref='$field'");
	return true;
	}

if (!function_exists("get_resource_access")){	
function get_resource_access($resource)
	{
	# $resource may be a resource_data array from a search, in which case, many of the permissions checks are already done.	
		
	# Returns the access that the currently logged-in user has to $resource.
	# Return values:
	# 0 = Full Access (download all sizes)
	# 1 = Restricted Access (download only those sizes that are set to allow restricted downloads)
	# 2 = Confidential (no access)
	
	# Load the 'global' access level set on the resource
	# In the case of a search, resource type and global,group and user access are passed through to this point, to avoid multiple unnecessary get_resource_data queries.
	# passthru signifies that this is the case, so that blank values in group or user access mean that there is no data to be found, so don't check again .
	$passthru="no";

	if (!is_array($resource)){
	$resourcedata=get_resource_data($resource,true);
	}
	else {
	$resourcedata=$resource;
	$passthru="yes";
	}
	$ref=$resourcedata['ref'];
	$access=$resourcedata["access"];
	$resource_type=$resourcedata['resource_type'];
	
	global $k;
	if ($k!="")
		{
		# External access - check how this was shared.
		$extaccess=sql_value("select access value from external_access_keys where resource=".$ref." and access_key='" . escape_check($k) . "'",-1);
		if ($extaccess!=-1) {return $extaccess;}
		}
	
	if (checkperm("v"))
		{
		# Permission to access all resources
		# Always return 0
		return 0; 
		}

	if ($access==3)
		{
		# Load custom access level
		if ($passthru=="no"){ 
			global $usergroup;
			$access=get_custom_access($resource,$usergroup);
			//echo "checked group access: ".$access;
			} 
		else {
			$access=$resource['group_access'];
		}
	}

	# Check for user-specific access (overrides any other restriction)
	global $userref;

	if ($passthru=="no"){
		$userspecific=get_custom_access_user($resource,$userref);	
		//echo "checked user access: ".$userspecific;
		} 
	else {
		$userspecific=$resourcedata['user_access'];
		}

		
	if ($userspecific!="")
		{
		return $userspecific;
		}
		
	if ($access==0 && !checkperm("g"))
		{
		# User does not have the 'g' permission. Always return restricted for active resources.
		return 1; 
		}
	
	if (checkperm('X'.$resource_type)){
		// this resource type is always restricted for this user group
		return 1;
	}

	if (checkperm('T'.$resource_type)){
		// this resource type is always confidential/hidden for this user group
		return 2;
	}

	return $access;	
	}
}
	
function get_custom_access_user($resource,$user)
	{
	return sql_value("select access value from resource_custom_access where resource='$resource' and user='$user' and (user_expires is null or user_expires>now())",false);
	}

function resource_download_allowed($resource,$size,$resource_type)
	{

	# For the given resource and size, can the curent user download it?
	# resource type and access may already be available in the case of search, so pass them along to get_resource_access to avoid extra queries
	# $resource can be a resource-specific search result array.
	$access=get_resource_access($resource);

	if (checkperm('X' . $resource_type . "_" . $size) || checkperm('T' . $resource_type . "_" . $size))
		{
		# Block access to this resource type / size?
		# Only if no specific user access override (i.e. they have successfully requested this size).
		global $userref;
		$usercustomaccess = get_custom_access_user($resource,$userref);
		if ($usercustomaccess === false || !($usercustomaccess==='0')) {return false;}
		}

	# Full access
	if ($access==0)
		{
		return true;
		}

	# Special case for purchased downloads.
	global $userref;
	if (isset($userref))
		{
		$complete=sql_value("select cr.purchase_complete value from collection_resource cr join collection c on cr.collection=c.ref where c.user='$userref' and cr.resource='$resource' and cr.purchase_size='" . escape_check($size) . "'",0);
		if ($complete==1) {return true;}
		}

	# Restricted
	if ($access==1)
		{
		if ($size=="")
			{
			# Original file - access depends on the 'restricted_full_download' config setting.
			global $restricted_full_download;
			return $restricted_full_download;
			}
		else
			{
			# Return the restricted access setting for this resource type.
			return (sql_value("select allow_restricted value from preview_size where id='" . escape_check($size) . "'",0)==1);
			}
		}
		
	# Confidential
	if ($access==2)
		{
		return false;
		}
	
	}

function get_edit_access($resource,$status=-999,$metadata=false)
	{
	# For the provided resource and metadata, does the  edit access does the current user have to this resource?
	# Checks the edit permissions (e0, e-1 etc.) and also the group edit filter which filters edit access based on resource metadata.
	
	global $userref,$usereditfilter;
	
	if ($status==-999)
		{
		# Status not provided. Calculate status
		$status=sql_value("select archive value from resource where ref='$resource'",0);
		}
	
	if ($resource==0-$userref) {return true;} # Can always edit their own user template.

	if (!checkperm("e" . $status)) {return false;} # Must have edit permission to this resource first and foremost, before checking the filter.
	
	$gotmatch=false;
	if (trim($usereditfilter)=="")
		{
		$gotmatch = true;
		}
	else
		{
		# An edit filter has been set. Perform edit filter processing to establish if the user can edit this resource.
		
		# Always load metadata, because the provided metadata may be missing fields due to permissions.
		$metadata=get_resource_field_data($resource,false,false);
				
		for ($n=0;$n<count($metadata);$n++)
			{
			$name=$metadata[$n]["name"];
			$value=$metadata[$n]["value"];			
			if ($name!="")
				{
				$match=filter_match($usereditfilter,$name,$value);
				if ($match==1) {return false;} # The match for this field was incorrect, always fail in this event.
				if ($match==2) {$gotmatch=true;} # The match for this field was correct.
				}
			}
		}
	
	if ($gotmatch) {
	  $gotmatch = !hook("denyafterusereditfilter");
	}
	
	# Default after all filter operations, allow edit.
	return $gotmatch;
	}


function filter_match($filter,$name,$value)
	{
	# In the given filter string, does name/value match?
	# Returns:
	# 0 = no match for name
	# 1 = matched name but value was not present
	# 2 = matched name and value was correct
	$s=explode(";",$filter);
	foreach ($s as $condition)
		{
		$s=explode("=",$condition);
		$checkname=$s[0];
		if ($checkname==$name)
			{
			$checkvalues=$s[1];
			
			$s=explode("|",strtoupper($checkvalues));
			$v=trim_array(explode(",",strtoupper($value)));
			foreach ($s as $checkvalue)
				{
				if (in_array($checkvalue,$v)) {return 2;}
				}
			return 1;
			}
		}
	return 0;
	}
	
function log_diff($fromvalue,$tovalue)	
	{
	# Forumlate descriptive text to describe the change made to a metadata field.

	# Remove any database escaping
	$fromvalue=str_replace("\\","",$fromvalue);
	$tovalue=str_replace("\\","",$tovalue);
	
	if (substr($fromvalue,0,1)==",")
		{
		# Work a different way for checkbox lists.
		$fromvalue=explode(",",i18n_get_translated($fromvalue));
		$tovalue=explode(",",i18n_get_translated($tovalue));
		
		# Get diffs
		$inserts=array_diff($tovalue,$fromvalue);
		$deletes=array_diff($fromvalue,$tovalue);

		# Process array diffs into meaningful strings.
		$return="";
		if (count($deletes)>0)
			{
			$return.="- " . join("\n- " , $deletes);
			}
		if (count($inserts)>0)
			{
			if ($return!="") {$return.="\n";}
			$return.="+ " . join("\n+ ", $inserts);
			}
		
		#debug($return);
		return $return;
		}

	# For standard strings, use Text_Diff
		
	require_once dirname(__FILE__).'/../lib/Text_Diff/Diff.php';
	require_once dirname(__FILE__).'/../lib/Text_Diff/Diff/Renderer/inline.php';

	$lines1 = explode("\n",$fromvalue);
	$lines2 = explode("\n",$tovalue);

	$diff     = new Text_Diff('native', array($lines1, $lines2));
	$renderer = new Text_Diff_Renderer_inline();
	$diff=$renderer->render($diff);
	
	$return="";

	# The inline diff syntax places inserts within <ins></ins> tags and deletes within <del></del> tags.

	# Handle deletes
	if (strpos($diff,"<del>")!==false)
		{
		$s=explode("<del>",$diff);
		for ($n=1;$n<count($s);$n++)
			{
			$t=explode("</del>",$s[$n]);
			if ($return!="") {$return.="\n";}
			$return.="- " . trim(i18n_get_translated($t[0]));
			}
		}
	# Handle inserts
	if (strpos($diff,"<ins>")!==false)
		{
		$s=explode("<ins>",$diff);
		for ($n=1;$n<count($s);$n++)
			{
			$t=explode("</ins>",$s[$n]);
			if ($return!="") {$return.="\n";}
			$return.="+ " . trim(i18n_get_translated($t[0]));
			}
		}


	#debug ($return);
	return $return;
	}
	
function update_xml_metadump($resource)
	{
	# Updates the XML metadata dump file when the resource has been altered.
	global $xml_metadump,$xml_metadump_dc_map;
	if (!$xml_metadump || $resource < 0) {return true;} # Only execute when configured and when not a template
	
	$path=dirname(get_resource_path($resource,true,"pre",true)) . "/metadump.xml";
	if (file_exists($path)){$wait=unlink($path);}
	
	$ext = htmlspecialchars(sql_value("select file_extension value from resource where ref = '$resource'",''),ENT_QUOTES);
	
	if ($result = sql_query("select resource_type, name from resource left join resource_type on resource.resource_type = resource_type.ref where resource.ref = '$resource'",false)){
		$rtype = $result[0]['resource_type'];
		$rtypename = htmlspecialchars($result[0]['name'],ENT_QUOTES);
	} else {
		$rtype = '';
		$rtypename = '';
	}

	$f=fopen($path,"w+");
	fwrite($f,"<?xml version=\"1.0\"?>\n");
	fwrite($f,"<record xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" resourcespace:resourceid=\"$resource\"");
	fwrite($f," resourcespace:extension=\"$ext\" resourcespace:resourcetype=\"$rtypename\" resourcespace:resourcetypeid=\"$rtype\" ");
	fwrite($f,">\n\n");
  
  	$data=get_resource_field_data($resource,false,false); # Get field data ignoring permissions
  	for ($n=0;$n<count($data);$n++)
	  	{
	  	if (array_key_exists($data[$n]["name"],$xml_metadump_dc_map))
	  		{
	  		# Dublin Core field
	  		fwrite($f,"<dc:" . $xml_metadump_dc_map[$data[$n]["name"]] . " ");
	  		$endtag="</dc:" . $xml_metadump_dc_map[$data[$n]["name"]] . ">";
	  		}
	  	else
	  		{
	  		# No Dublin Core mapping. RS specific field format.
	  		fwrite($f,"<resourcespace:field ");
	  		$endtag="</resourcespace:field>";
	  		}
	  		
	  	# Value processing
	  	$value=$data[$n]["value"];
	  	if (substr($value,0,1)==",") {$value=substr($value,1);} # Checkbox lists / dropdowns; remove initial comma
	  	
	  	# Write metadata
	  	fwrite($f,"rsfieldtitle=\"" . htmlspecialchars($data[$n]["title"]) . "\" rsembeddedequiv=\"" . htmlspecialchars($data[$n]["exiftool_field"]) . "\" rsfieldref=\"" . htmlspecialchars($data[$n]["resource_type_field"]) . "\" rsfieldtype=\"" . htmlspecialchars($data[$n]["type"]) . "\">" . htmlspecialchars($value) . $endtag . "\n\n");
	  	}

	fwrite($f,"</record>\n");
	fclose($f);
	//chmod($path,0777); // fixme - temporarily make world readable/writable until we have better solution for file permissions

	}

function get_metadata_templates()
	{
	# Returns a list of all metadata templates; i.e. resources that have been set to the resource type specified via '$metadata_template_resource_type'.
	global $metadata_template_resource_type,$metadata_template_title_field;
	return sql_query("select ref,field$metadata_template_title_field from resource where ref>0 and resource_type='$metadata_template_resource_type' order by field$metadata_template_title_field");
	}
 
function get_resource_collections($ref)
	{
	global $userref;
	
	# Returns a list of collections that a resource is used in for the $view_resource_collections option
	$sql="";
   
    # Include themes in my collecions? 
    # Only filter out themes if $themes_in_my_collections is set to false in config.php
   	global $themes_in_my_collections;
   	if (!$themes_in_my_collections)
   		{
   		if ($sql!="") {$sql.=" and ";}
   		$sql.="(length(c.theme)=0 or c.theme is null) ";
   		}
	if ($sql!="") {$sql="where " . $sql;}
   
	$return=sql_query ("select * from 
	(select c.*,u.username,u.fullname,count(r.resource) count from user u join collection c on u.ref=c.user and c.user='$userref' left outer join collection_resource r on c.ref=r.collection group by c.ref
	union
	select c.*,u.username,u.fullname,count(r.resource) count from user_collection uc join collection c on uc.collection=c.ref and uc.user='$userref' and c.user<>'$userref' left outer join collection_resource r on c.ref=r.collection left join user u on c.user=u.ref group by c.ref) clist where clist.ref in (select collection from collection_resource cr where cr.resource=$ref)");
	
	return $return;
	}
	
function download_summary($resource)
	{
	# Returns a summary of downloads by usage type
	return sql_query("select usageoption,count(*) c from resource_log where resource='$resource' and type='D' group by usageoption order by usageoption");
	}
	
	
function check_use_watermark(){
	# access status must be available prior to this.
	# This function checks whether to use watermarks or not.
	# Two cases:
	# if access is restricted and the group has "w"
	# if $watermark is set and it's an external share.
	
	global $access,$k,$watermark;
	if ($access==1 &&    (checkperm('w') || ($k!="" && isset($watermark)) )    ){return true;} else {return false;} 
}

function autocomplete_blank_fields($resource)
	{
	# Fill in any blank fields for the resource
	
	# Fetch resource type
	$resource_type=sql_value("select resource_type value from resource where ref='$resource'",0);
	
	# Fetch field list
	$fields=sql_query("select ref,autocomplete_macro from resource_type_field where (resource_type=0 || resource_type='$resource_type') and length(autocomplete_macro)>0");
	foreach ($fields as $field)
		{
		$value=sql_value("select value from resource_data where resource='$resource' and resource_type_field='" . $field["ref"] . "'","");
		if (strlen(trim($value))==0)
			{
			# Empty value. Autocomplete and set.
			$value=eval($field["autocomplete_macro"]);	
			update_field($resource,$field["ref"],$value);
			}
		}	
	}


function get_resource_files($ref,$includeorphan=false){
    // returns array of all files associated with a resource
    // if $includeorphan set to true, will also return all files in the
    // resource dir even if the system doesn't understand why they're there.

    $filearray = array();
    $file_checklist = array();

    global $config_windows;
    if ($config_windows){ $sep = "\\"; } else { $sep = "/"; }


    $sizearray = sql_array("select id value from preview_size",false);
    $original_ext = sql_value("select file_extension value from resource where ref = '".escape_check($ref)."'",'');

    $rootpath=dirname(get_resource_path($ref,true,"pre",true));

    // get listing of all files in resource dir to compare mark off as we find them
    if (is_dir($rootpath)) {
    if ($dh = opendir($rootpath)) {
            while (($file = readdir($dh)) !== false) {
                if (!($file == '.' || $file == '..')){
                    $file_checklist[$rootpath.$sep.$file] = 1;
                }
            }
            closedir($dh);
        }
    }

    // first get the resource itself
    $original = get_resource_path($ref,true,'',false,$original_ext);
    if (file_exists($original)){
	    array_push($filearray,$original);
	    unset($file_checklist[$original]);
    }

    // in some cases, the system also generates a jpeg equivalent of the original, so check for that
    $original = get_resource_path($ref,true,'',false,'jpg');
    if (file_exists($original)){
	    array_push($filearray,$original);
    	unset($file_checklist[$original]);
    }

    // in some cases, the system also generates an mp3 equivalent of the original, so check for that
    $original = get_resource_path($ref,true,'',false,'mp3');
    if (file_exists($original)){
    	array_push($filearray,$original);
    	unset($file_checklist[$original]);
    }

    // in some cases, the system also generates an extracted icc profile, so check for that
    $original = get_resource_path($ref,true,'',false,'icc');
    if (file_exists($original)){
    	array_push($filearray,$original);
    	unset($file_checklist[$original]);
    }


    # check for pages
    $page = 1;
    $misscount = 0;
    // just to be safe, we'll try at least 4 pages ahead to make sure none got skipped
    while($misscount < 4){
        $thepath = get_resource_path($ref,true,"scr",false,'jpg',-1,$page,"","","");
        if (file_exists($thepath)){
            array_push($filearray,$thepath);
            unset($file_checklist[$thepath]);
            $page++;
        } else {
            $misscount++;
            $page++;
        }
    }        

    // now look for other sizes
    foreach($sizearray as $size){
        $thepath = get_resource_path($ref,true,$size,false,'jpg');
        if (file_exists($thepath)){
            array_push($filearray,$thepath);
            unset($file_checklist[$thepath]);
        }
    }


    // get alternative files
    $altfiles = get_alternative_files($ref);
    foreach($altfiles as $altfile){
        // first get original
        $alt_ext = sql_value("select file_extension value from resource_alt_files where ref = '" . $altfile['ref'] . "'",'');
        $thepath = get_resource_path($ref,true,'',false,$alt_ext,-1,1,false,"",$altfile["ref"]);
        if (file_exists($thepath)){
            array_push($filearray,$thepath);
            unset($file_checklist[$thepath]);
        }


        // now check for previews
        foreach($sizearray as $size){
            $thepath = get_resource_path($ref,true,$size,false,"jpg",-1,1,false,"",$altfile["ref"]);
            if (file_exists($thepath)){
                array_push($filearray,$thepath);
                unset($file_checklist[$thepath]);
            }
        }

        # check for pages
        $page = 1;
        while($page <> 0){
            $thepath = get_resource_path($ref,true,"scr",false,'jpg',-1,$page,"","",$altfile['ref']);
            if (file_exists($thepath)){
                array_push($filearray,$thepath);
                unset($file_checklist[$thepath]);
                $page++;
            } else {
                $page = 0;
            }
        }
        // in some cases, the system also generates a jpeg equivalent of the original, so check for that
        $original = get_resource_path($ref,true,'',false,'jpg',-1,1,'','',$altfile['ref']);
	if (file_exists($original)){
	        array_push($filearray,$original);
        	unset($file_checklist[$original]);
    	}

        // in some cases, the system also generates a mp3 equivalent of the original, so check for that
        $original = get_resource_path($ref,true,'',false,'mp3',-1,1,'','',$altfile['ref']);
	if (file_exists($original)){
	        array_push($filearray,$original);
       		unset($file_checklist[$original]);
	}

        // in some cases, the system also generates an extracted icc profile, so check for that
        $original = get_resource_path($ref,true,'',false,'icc',-1,1,'','',$altfile['ref']);
	if (file_exists($original)){
	        array_push($filearray,$original);
       		unset($file_checklist[$original]);
	}
    }


    // check for metadump
    $thefile="$rootpath/metadump.xml";
    if (file_exists($thefile)){
        array_push($filearray,$thefile);
        unset($file_checklist[$thefile]);
    }

    // check for ffmpeg previews
    global $ffmpeg_preview_extension;
    $flvfile=get_resource_path($ref,true,"pre",false,$ffmpeg_preview_extension);
    if (file_exists($flvfile)){
        array_push($filearray,$flvfile);
        unset($file_checklist[$flvfile]);
    }


    if (count($file_checklist)>0){
	foreach (array_keys($file_checklist) as $thefile){
		error_log("ResourceSpace: Orphaned file, resource $ref: $thefile");
	        if ($includeorphan) {
			array_push($filearray,$thefile);
		}
       }
    }
    return array_unique($filearray);
}

if (!function_exists("reindex_resource")){
function reindex_resource($ref)
	{
	# Reindex a resource. Delete all resource_keyword rows and create new ones.
	
	# Delete existing keywords
	sql_query("delete from resource_keyword where resource='$ref'");

	# Index fields
	$data=get_resource_field_data($ref,false,false); # Fetch all fields and do not use permissions.
	for ($m=0;$m<count($data);$m++)
		{
		if ($data[$m]["keywords_index"]==1)
			{
			#echo $data[$m]["value"];
			$value=$data[$m]["value"];
			if ($data[$m]["type"]==3 || $data[$m]["type"]==2)
				{
				# Prepend a comma when indexing dropdowns
				$value="," . $value;
				}
			
			# Date field? These need indexing differently.
			$is_date=($data[$m]["type"]==4 || $data[$m]["type"]==6);
			
			add_keyword_mappings($ref,i18n_get_indexable($value),$data[$m]["ref"],$data[$m]["partial_index"],$is_date);		
			}
		}
	
	# Also index contributed by field.
	$resource=get_resource_data($ref);
	$userinfo=get_user($resource["created_by"]);
	add_keyword_mappings($ref,$userinfo["username"] . " " . $userinfo["fullname"],-1);		
	
	# Always index the resource ID as a keyword
	remove_keyword_mappings($ref, $ref, -1);
	add_keyword_mappings($ref, $ref, -1);
	
	}
}

function get_page_count($resource,$alternative=-1)
    {
    # gets page count for multipage previews from resource_dimensions table.
    # also handle alternative file multipage previews by switching $resource array if necessary
    # $alternative specifies an actual alternative file
    $ref=$resource['ref'];
    if ($alternative!=-1)
        {
        $pagecount=sql_value("select page_count value from resource_alt_files where ref=$alternative","");
        $resource=get_alternative_file($ref,$alternative);
        }
    else
        {
        $pagecount=sql_value("select page_count value from resource_dimensions where resource=$ref","");
        }
    if ($pagecount!=""){return $pagecount;}
    # or, populate this column with exiftool (for installations with many pdfs already previewed and indexed, this allows pagecount updates on the fly when needed):
    # use exiftool. 
    # locate exiftool
    $exiftool_fullpath = get_utility_path("exiftool");
    if ($exiftool_fullpath==false){exit("Could not find 'exiftool' utility. $command'");}
    else
        {
        $command = $exiftool_fullpath;
        if ($resource['file_extension']=="pdf" && $alternative==-1)
            {
            $file=get_resource_path($ref,true,"",false,"pdf");
            }
        else if ($alternative==-1)
            {
            # some unoconv files are not pdfs but this needs to use the auto-alt file
            $alt_ref=sql_value("select ref value from resource_alt_files where resource=$ref and unoconv=1","");
            $file=get_resource_path($ref,true,"",false,"pdf",-1,1,false,"",$alt_ref);
            }
        else
            {
            $file=get_resource_path($ref,true,"",false,"pdf",-1,1,false,"",$alternative);
            }
    
        $command=$command." -sss -pagecount $file";
        $output=run_command($command);
        $pages=str_replace("Page Count","",$output);
        $pages=str_replace(":","",$pages);
        $pages=trim($pages);

    if (!is_numeric($pages)){ $pages = 1; } // default to 1 page if we didn't get anything back

        if ($alternative!=-1)
            {
            sql_query("update resource_alt_files set page_count='$pages' where ref=$alternative");
            }
        else
            {
            sql_query("update resource_dimensions set page_count='$pages' where resource=$ref");
            }
        return $pages;
    }
}


function update_disk_usage($resource)
	{
	# Scan the appropriate filestore folder and update the disk usage fields on the resource table.
	$dir=dirname(get_resource_path($resource,true,"",false));
	if (!file_exists($dir)) {return false;} # Folder does not yet exist.
	$d = dir($dir); 
	$total=0;
	while ($f = $d->read())
		{
		if ($f!=".." && $f!=".")
			{
			$s=filesize_unlimited($dir . "/" .$f);
			#echo "<br/>-". $f . " : " . $s;
			$total+=$s;
			}
		}
	#echo "<br/>total=" . $total;
	sql_query("update resource set disk_usage='$total',disk_usage_last_updated=now() where ref='$resource'");
	return true;
	}

function update_disk_usage_cron()
	{
	# Update disk usage for all resources that have not yet been updated or have not been updated in the past 30 days.
	# Limit to a reasonable amount so that this process is spread over several cron intervals for large data sets.
	$resources=sql_array("select ref value from resource where ref>0 and disk_usage_last_updated is null or datediff(now(),disk_usage_last_updated)>30 limit 20000");
	foreach ($resources as $resource)
		{
		update_disk_usage($resource);
		}
	}

function get_total_disk_usage()
	{
	# Returns sum of all resource disk usage
	return sql_value("select sum(disk_usage) value from resource",0);
	}

function overquota()
	{
	# Return true if the system is over quota
	global $disksize;
	if (isset($disksize))
		{
		# Disk quota functionality. Calculate the usage by the $storagedir folder only rather than the whole disk.
		# Unix only due to reliance on 'du' command
		$avail=$disksize*(1024*1024*1024);
		$used=get_total_disk_usage();
		
		$free=$avail-$used;
		if ($free<=0) {return true;}
		}
	return false;
	}
