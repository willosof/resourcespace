<?php
# Search functions
# Functions to perform searches (read only)
#  - For resource indexing / keyword creation, see resource_functions.php

if (!function_exists("do_search")) {
function do_search($search,$restypes="",$order_by="relevance",$archive=0,$fetchrows=-1,$sort="desc",$access_override=false,$starsearch="",$ignore_filters=false,$return_disk_usage=false)
	{	
	debug("search=$search restypes=$restypes archive=$archive");
	
	# globals needed for hooks	 
	global $sql,$order,$select,$sql_join,$sql_filter,$orig_order,$checkbox_and,$collections_omit_archived,$search_sql_double_pass_mode;
	
	# Takes a search string $search, as provided by the user, and returns a results set
	# of matching resources.
	# If there are no matches, instead returns an array of suggested searches.
	# $restypes is optionally used to specify which resource types to search.
	# $access_override is used by smart collections, so that all all applicable resources can be judged regardless of the final access-based results
	
	# resolve $order_by to something meaningful in sql
	$orig_order=$order_by;
	global $date_field;
	$order=array("relevance"=>"score $sort, user_rating $sort, hit_count $sort, field$date_field $sort,r.ref $sort","popularity"=>"user_rating $sort,hit_count $sort,field$date_field $sort,r.ref $sort","rating"=>"r.rating $sort, user_rating $sort, score $sort,r.ref $sort","date"=>"field$date_field $sort,r.ref $sort","colour"=>"has_image $sort,image_blue $sort,image_green $sort,image_red $sort,field$date_field $sort,r.ref $sort","country"=>"country $sort,r.ref $sort","title"=>"title $sort,r.ref $sort","file_path"=>"file_path $sort,r.ref $sort","resourceid"=>"r.ref $sort","resourcetype"=>"resource_type $sort,r.ref $sort","titleandcountry"=>"title $sort,country $sort","random"=>"RAND()");
	if (!in_array($order_by,$order)&&(substr($order_by,0,5)=="field")){
		$order[$order_by]="$order_by $sort";
	}

	hook("modifyorderarray");


	# Recognise a quoted search, which is a search for an exact string
	$quoted_string=false;
	if (substr($search,0,1)=="\"" && substr($search,-1,1)=="\"") {$quoted_string=true;$search=substr($search,1,-1);}


	$order_by=$order[$order_by];
	$keywords=split_keywords($search);
	$search=trim($search);

	# -- Build up filter SQL that will be used for all queries

	$sql_filter="";
	# append resource type filtering
	if ($restypes!="")
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$restypes_x=explode(",",$restypes);
		$sql_filter.="resource_type in ('" . join("','",$restypes_x) . "')";
		}
	
	if ($starsearch!="")
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$sql_filter.="user_rating >= '$starsearch'";
		}	

	# If returning disk used by the resources in the search results ($return_disk_usage=true) then wrap the returned SQL in an outer query that sums disk usage.
	$sql_prefix="";$sql_suffix="";
	if ($return_disk_usage) {$sql_prefix="select sum(disk_usage) total_disk_usage,count(*) total_resources from (";$sql_suffix=") resourcelist";}

	# append resource type restrictions based on 'T' permission	
	# look for all 'T' permissions and append to the SQL filter.
	global $userpermissions;
	$rtfilter=array();
	for ($n=0;$n<count($userpermissions);$n++)
		{
		if (substr($userpermissions[$n],0,1)=="T")
			{
			$rt=substr($userpermissions[$n],1);
			if (is_numeric($rt)&&!$access_override) {$rtfilter[]=$rt;}
			}
		}
	if (count($rtfilter)>0)
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$sql_filter.="resource_type not in (" . join(",",$rtfilter) . ")";
		}
	
	# append "use" access rights, do not show restricted resources unless admin
	if (!checkperm("v")&&!$access_override)
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$sql_filter.="r.access<>'2'";
		}
		
	# append archive searching (don't do this for collections, archived resources can still appear in collections)
	if (substr($search,0,11)!="!collection" || ($collections_omit_archived && !checkperm("e2")))
		{
		global $pending_review_visible_to_all;
		if ($archive==0 && $pending_review_visible_to_all)
			{
			# If resources pending review are visible to all, when listing only active resources include
			# pending review (-1) resources too.
			if ($sql_filter!="") {$sql_filter.=" and ";}
			$sql_filter.="(archive='0' or archive=-1)";
			}
		else
			{
			# Append normal filtering.
			if ($sql_filter!="") {$sql_filter.=" and ";}
			$sql_filter.="archive='$archive'";
			}
		}
	
	
	# append ref filter - never return the batch upload template (negative refs)
	if ($sql_filter!="") {$sql_filter.=" and ";}
	$sql_filter.="r.ref>0";
	
	# ------ Advanced 'custom' permissions, need to join to access table.
	$sql_join="";
	global $k;
	if ((!checkperm("v")) &&!$access_override)
		{
		global $usergroup;global $userref;
		# one extra join (rca2) is required for user specific permissions (enabling more intelligent watermarks in search view)
		# the original join is used to gather group access into the search query as well.
		$sql_join=" left outer join resource_custom_access rca2 on r.ref=rca2.resource and rca2.user='$userref'  and (rca2.user_expires is null or rca2.user_expires>now()) and rca2.access<>2  ";	
		$sql_join.=" left outer join resource_custom_access rca on r.ref=rca.resource and rca.usergroup='$usergroup' and rca.access<>2 ";
		
		if ($sql_filter!="") {$sql_filter.=" and ";}
		# If rca.resource is null, then no matching custom access record was found
		# If r.access is also 3 (custom) then the user is not allowed access to this resource.
		# Note that it's normal for null to be returned if this is a resource with non custom permissions (r.access<>3).
		$sql_filter.=" not(rca.resource is null and r.access=3)";
		}
		
	# Join thumbs_display_fields to resource table 	
	$select="r.ref, r.resource_type, r.has_image, r.is_transcoding, r.hit_count, r.creation_date, r.rating, r.user_rating, r.user_rating_count, r.user_rating_total, r.file_extension, r.preview_extension, r.image_red, r.image_green, r.image_blue, r.thumb_width, r.thumb_height, r.archive, r.access, r.colour_key, r.created_by, r.file_modified, r.file_checksum, r.request_count, r.new_hit_count, r.expiry_notification_sent, r.preview_tweaks, r.file_path ";	
	
	$modified_select=hook("modifyselect");
	if ($modified_select){$select.=$modified_select;}	
	$modified_select2=hook("modifyselect2");
	if ($modified_select2){$select.=$modified_select2;}	

	# Return disk usage for each resource if returning sum of disk usage.
	if ($return_disk_usage) {$select.=",r.disk_usage";}

	# select group and user access rights if available, otherwise select null values so columns can still be used regardless
	# this makes group and user specific access available in the basic search query, which can then be passed through access functions
	# in order to eliminate many single queries.
	if ((!checkperm("v")) &&!$access_override)
		{
		$select.=",rca.access group_access,rca2.access user_access ";
		}
	else {
		$select.=",null group_access, null user_access ";
	}
	
	# add 'joins' to select (adding them 
	$joins=get_resource_table_joins();
	foreach( $joins as $datajoin)
		{
		$select.=",r.field".$datajoin." ";
		}	
	
	# Prepare SQL to add join table for all provided keywods
	
	$suggested=$keywords; # a suggested search
	$fullmatch=true;
	$c=0;$t="";$t2="";$score="";
	
	$keysearch=true;
	
	 # Do not process if a numeric search is provided (resource ID)
	global $config_search_for_number, $category_tree_search_use_and;
	if ($config_search_for_number && is_numeric($search)) {$keysearch=false;}
	
	
	
	if ($keysearch)
		{
		for ($n=0;$n<count($keywords);$n++)
			{
			$keyword=$keywords[$n];
			if (substr($keyword,0,1)!="!")
				{
				global $date_field;
				$field=0;#echo "<li>$keyword<br/>";
				if (strpos($keyword,":")!==false && !$ignore_filters)
					{	
					$kw=explode(":",$keyword,2);
					if ($kw[0]=="day")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field like '____-__-" . $kw[1] . "%' ";
						}
					elseif ($kw[0]=="month")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field like '____-" . $kw[1] . "%' ";
						}
					elseif ($kw[0]=="year")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field like '" . $kw[1] . "%' ";
						}
					else
						{
						$ckeywords=explode(";",$kw[1]);
						
						# Fetch field info
						$fieldinfo=sql_query("select ref,type from resource_type_field where name='" . escape_check($kw[0]) . "'",0);
						if (count($fieldinfo)==0)
							{
							debug("Field short name not found.");return false;
							}
						else
							{
							$fieldinfo=$fieldinfo[0];
							}
						
						# Special handling for dates
						if ($fieldinfo["type"]==4 || $fieldinfo["type"]==6) 
							{
							$ckeywords=array(str_replace(" ","-",$kw[1]));
							}
						
						$field=$fieldinfo["ref"];

						#special SQL generation for category trees to use AND instead of OR
						if(
							($fieldinfo["type"] == 7 && $category_tree_search_use_and)
						||
							($fieldinfo["type"] == 2 && $checkbox_and)
						) {
							for ($m=0;$m<count($ckeywords);$m++) {
								$keyref=resolve_keyword($ckeywords[$m]);
								if (!($keyref===false)) {
									$c++;
		
									# Add related keywords
									$related=get_related_keywords($keyref);
									$relatedsql="";
									
									for ($r=0;$r<count($related);$r++)
										{
										$relatedsql.=" or k" . $c . ".keyword='" . $related[$r] . "'";
										}
									# Form join
									//$sql_join.=" join (SELECT distinct k".$c.".resource,k".$c.".hit_count from resource_keyword k".$c." where k".$c.".keyword='$keyref' $relatedsql) t".$c." ";
									$sql_join.=" join resource_keyword k" . $c . " on k" . $c . ".resource=r.ref and k" . $c . ".resource_type_field='" . $field . "' and (k" . $c . ".keyword='$keyref' $relatedsql)";
	
	
									if ($score!="") {$score.="+";}
									$score.="k" . $c . ".hit_count";
									
									# Log this
									daily_stat("Keyword usage",$keyref);
								}							
								
							}
						} else {
							$c++;
							$sql_join.=" join resource_keyword k" . $c . " on k" . $c . ".resource=r.ref and k" . $c . ".resource_type_field='" . $field . "'";
									
							if ($score!="") {$score.="+";}
							$score.="k" . $c . ".hit_count";
																			
							# work through all options in an OR approach for multiple selects on the same field
							# where k.resource=type_field=$field and (k*.keyword=3 or k*.keyword=4) etc
	
							$keyjoin="";
							for ($m=0;$m<count($ckeywords);$m++)
								{
								$keyref=resolve_keyword($ckeywords[$m]);
								if ($keyref===false) {$keyref=-1;}
								
								if ($m!=0) {$keyjoin.=" OR ";}
								$keyjoin.="k" . $c. ".keyword='$keyref'";
				
								# Also add related.
								$related=get_related_keywords($keyref);
								for ($o=0;$o<count($related);$o++)
									{
									$keyjoin.=" OR k" . $c . ".keyword='" . $related[$o] . "'";
									}
									
								# Log this
								daily_stat("Keyword usage",$keyref);
								
								}
							if ($keyjoin!="") {$sql_join.=" and (" . $keyjoin . ")";}
							}
						}
					}
				else
					{

					# Normal keyword (not tied to a field) - searches all fields
					
					# If ignoring field specifications then remove them.
					if (strpos($keyword,":")!==false && $ignore_filters)
						{
						$s=explode(":",$keyword);$keyword=$s[1];
						}

					# Omit resources containing this keyword?
					$omit=false;
					if (substr($keyword,0,1)=="-") {$omit=true;$keyword=substr($keyword,1);}
					
					
					
					global $noadd, $wildcard_always_applied;
					if (in_array($keyword,$noadd)) # skip common words that are excluded from indexing
						{
						$skipped_last=true;
						}
					else
						{
						# Handle wildcards
						if (strpos($keyword,"*")!==false || $wildcard_always_applied)
							{
							if ($wildcard_always_applied && strpos($keyword,"*")===false) {$keyword.="*";} # Suffix asterisk if none supplied and using $wildcard_always_applied mode.
							
							# Keyword contains a wildcard. Expand.
							$c++;
							
							global $use_temp_tables;
							if (!$use_temp_tables){
								global $wildcard_expand_limit;
								$wildcards=sql_array("select ref value from keyword where keyword like '" . escape_check(str_replace("*","%",$keyword)) . "' order by hit_count desc limit " . $wildcard_expand_limit);
		
								# Form join							
								$sql_join.=" join resource_keyword k" . $c . " on k" . $c . ".resource=r.ref and k" . $c . ".keyword in ('" . join("','",$wildcards) . "')";
								$sql_exclude_fields = hook("excludefieldsfromkeywordsearch");
                if (!empty($sql_exclude_fields)) {
                  $sql_join.=" and k" . $c . ".resource_type_field not in (". $sql_exclude_fields .")";
                }
                
								#echo $sql_join;
							} else {
								 //begin code for temporary table wildcard expansion
								
								// use a global counter to avoide temporary table naming collisions
                                                                global $temptable_counter;
                                                                if (!isset($temptable_counter)){$temptable_counter = 0;}
                                                                $temptable_counter++;
                                                                $thetemptable = 'wcql' . $c . '_' . $temptable_counter;
                                                                $sql_exclude_fields = hook("excludefieldsfromkeywordsearch");
                                                                $temptable_exclude='';
                                                                if (!empty($sql_exclude_fields)) {
                                                                  $temptable_exclude="and rk.resource_type_field not in (". $sql_exclude_fields .")";
                                                                }

                                                                sql_query("create temporary table $thetemptable (resource bigint unsigned)");
                                                                sql_query("insert into $thetemptable select distinct r.ref from resource r
                                                                        left join resource_keyword rk on r.ref = rk.resource $temptable_exclude
                                                                        left join keyword k  on rk.keyword = k.ref
                                                                        where k.keyword like '" . escape_check(str_replace("*","%",$keyword)) . "'");

                                                                        $sql_join .= " join $thetemptable on $thetemptable.resource = r.ref ";
							}



							}
						else		
							{
							# Not a wildcard. Normal matching.

							$keyref=resolve_keyword($keyword); # Resolve keyword. Ignore any wildcards when resolving. We need wildcards to be present later but not here.
							if ($keyref===false && !$omit)
								{
								$fullmatch=false;
								$soundex=resolve_soundex($keyword);
								if ($soundex===false)
									{
									# No keyword match, and no keywords sound like this word. Suggest dropping this word.
									$suggested[$n]="";
									}
								else
									{
									# No keyword match, but there's a word that sounds like this word. Suggest this word instead.
									$suggested[$n]="<i>" . $soundex . "</i>";
									}
								}
							else
								{
								# Key match, add to query.
								$c++;
		
								# Add related keywords
								$related=get_related_keywords($keyref);$relatedsql="";
								for ($m=0;$m<count($related);$m++)
									{
									$relatedsql.=" or k" . $c . ".keyword='" . $related[$m] . "'";
									}
								
								
								# Form join
								global $use_temp_tables;
								if (substr($search,0,8)=="!related") {$use_temp_tables=false;} // temp tables can't be used twice (unions)
								$sql_exclude_fields = hook("excludefieldsfromkeywordsearch");
								if (!$use_temp_tables)
									{
									// Not using temporary tables
									
									# Quoted string support
									$positionsql="";
									if ($quoted_string)
										{
										if ($c>1)
											{
											$last_key_offset=1;
											if (isset($skipped_last) && $skipped_last) {$last_key_offset=2;} # Support skipped keywords - if the last keyword was skipped (listed in $noadd), increase the allowed position from the previous keyword. Useful for quoted searches that contain $noadd words, e.g. "black and white" where "and" is a skipped keyword.
											$positionsql="and k" . $c . ".position=k" . ($c-1) . ".position+" . $last_key_offset;
											}								
										}


					                if (!empty($sql_exclude_fields)) 
					                	{
					                    $sql_join.=" and k" . $c . ".resource_type_field not in (". $sql_exclude_fields .")";
						                }

									if (!$omit)
										{
										# Include in query
						                $sql_join.=" join resource_keyword k" . $c . " on k" . $c . ".resource=r.ref and (k" . $c . ".keyword='$keyref' $relatedsql) $positionsql";
								                  	
										if ($score!="") {$score.="+";}
										$score.="k" . $c . ".hit_count";
										}
									else
										{
										# Exclude matching resources from query (omit feature)
										if ($sql_filter!="") {$sql_filter.=" and ";}
										$sql_filter .= "r.ref not in (select resource from resource_keyword where keyword='$keyref')"; # Filter out resources that do contain the keyword.
										}						                
						                
									}
								else
									{ //use temp tables
									if (!isset($temptable_counter)){$temptable_counter = 0;}
                                    $temptable_counter++;
                                    $jtemptable = 'jtt' . $c . '_' . $temptable_counter;
                                    sql_query("drop table IF EXISTS $jtemptable ",false);
					                $exclude_sql='';                  

									# Quoted string support
									$positionsql="";
									if ($quoted_string)
										{
										if ($c>1)
											{
											$last_key_offset=1;
											if (isset($skipped_last) && $skipped_last) {$last_key_offset=2;} # Support skipped keywords - if the last keyword was skipped (listed in $noadd), increase the allowed position from the previous keyword. Useful for quoted searches that contain $noadd words, e.g. "black and white" where "and" is a skipped keyword.
											$positionsql="and $jtemptable.position=" . 'jtt' . ($c-1) . '_' . ($temptable_counter-1) . ".position+" . $last_key_offset;
											}								
										}

					                
									if (!empty($sql_exclude_fields))
										{
									  	$exclude_sql="and k" . $c . ".resource_type_field not in (". $sql_exclude_fields .")";
										}
									$test=sql_query("create temporary table $jtemptable SELECT distinct k".$c.".resource,k".$c.".hit_count,k".$c.".position from 	resource_keyword k".$c." where (k".$c.".keyword='$keyref' $relatedsql)  $exclude_sql");
									
									if (!$omit)
										{
										# Include in query
										$sql_join .= " join $jtemptable on $jtemptable.resource = r.ref $positionsql";

										if ($score!="") {$score.="+";}
										$score.=$jtemptable . ".hit_count";
										}
									else
										{
										# Exclude matching resources from query (omit feature)
										if ($sql_filter!="") {$sql_filter.=" and ";}
										$sql_filter .= "r.ref not in (select resource from $jtemptable)"; # Filter out resources that do contain the keyword.
										}
										
								}
								
								# Log this
								daily_stat("Keyword usage",$keyref);
								}
							}
						$skipped_last=false;
						}
					}
				}
			}
		}
	# Could not match on provided keywords? Attempt to return some suggestions.
	if ($fullmatch==false)
		{
		if ($suggested==$keywords)
			{
			# Nothing different to suggest.
			debug("No alternative keywords to suggest.");
			return "";
			}
		else
			{
			# Suggest alternative spellings/sound-a-likes
			$suggest="";
			if (strpos($search,",")===false) {$suggestjoin=" ";} else {$suggestjoin=", ";}
			for ($n=0;$n<count($suggested);$n++)
				{
				if ($suggested[$n]!="")
					{
					if ($suggest!="") {$suggest.=$suggestjoin;}
					$suggest.=$suggested[$n];
					}
				}
			debug ("Suggesting $suggest");
			return $suggest;
			}
		}
	# Some useful debug.
	#echo("keywordjoin=" . $sql_join);
	#echo("<br>Filter=" . $sql_filter);
	#echo("<br>Search=" . $search);
        hook("additionalsqlfilter");
	
	# ------ Search filtering: If search_filter is specified on the user group, then we must always apply this filter.
	global $usersearchfilter;
	$sf=explode(";",$usersearchfilter);
	if (strlen($usersearchfilter)>0)
		{
		for ($n=0;$n<count($sf);$n++)
			{
			$s=explode("=",$sf[$n]);
			if (count($s)!=2) {exit ("Search filter is not correctly configured for this user group.");}

			# Find field(s) - multiple fields can be returned to support several fields with the same name.
			$f=sql_array("select ref value from resource_type_field where name='" . escape_check($s[0]) . "'");
			if (count($f)==0) {exit ("Field(s) with short name '" . $s[0] . "' not found in user group search filter.");}
			
			# Find keyword(s)
			$ks=explode("|",strtolower(escape_check($s[1])));
			$modifiedsearchfilter=hook("modifysearchfilter");
			if ($modifiedsearchfilter){$ks=$modifiedsearchfilter;} 
			$kw=sql_array("select ref value from keyword where keyword in ('" . join("','",$ks) . "')");
			#if (count($k)==0) {exit ("At least one of keyword(s) '" . join("', '",$ks) . "' not found in user group search filter.");}
					
			$sql_join.=" join resource_keyword filter" . $n . " on r.ref=filter" . $n . ".resource and filter" . $n . ".resource_type_field in ('" . join("','",$f) . "') and filter" . $n . ".keyword in ('" . join("','",$kw) . "') ";	
			}
		}
		
	$userownfilter=	hook("userownfilter");
	if ($userownfilter){$sql_join.=$userownfilter;} 
	
	# Handle numeric searches when $config_search_for_number=false, i.e. perform a normal search but include matches for resource ID first
	global $config_search_for_number;
	if (!$config_search_for_number && is_numeric($search))
		{
		# Always show exact resource matches first.
		$order_by="(r.ref='" . $search . "') desc," . $order_by;
		}
	
	# --------------------------------------------------------------------------------
	# Special Searches (start with an exclamation mark)
	# --------------------------------------------------------------------------------
	
	# Can only search for resources that belong to themes
	if (checkperm("J"))
		{
		$sql_join.=" join collection_resource jcr on jcr.resource=r.ref join collection jc on jcr.collection=jc.ref and length(jc.theme)>0 ";
		}
		
	# ------ Special searches ------
	# View Last
	if (substr($search,0,5)=="!last") 
		{
		# Replace r2.ref with r.ref for the alternative query used here.
		$order_by=str_replace("r.ref","r2.ref",$order_by);
		if ($orig_order=="relevance") {$order_by="r2.ref desc";}

		# Extract the number of records to produce
		$last=explode(",",$search);
		$last=str_replace("!last","",$last[0]);
		
		if (!is_numeric($last)) {$last=1000;} # 'Last' must be a number. SQL injection filter.
		
		# Fix the order by for this query (special case due to inner query)
		$order_by=str_replace("r.rating","rating",$order_by);

		return sql_query($sql_prefix . "select distinct *,r2.hit_count score from (select $select from resource r $sql_join  where $sql_filter order by ref desc limit $last ) r2 order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	# View Resources With No Downloads
	if (substr($search,0,12)=="!nodownloads") 
		{
		if ($orig_order=="relevance") {$order_by="ref desc";}

		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where $sql_filter and ref not in (select distinct object_ref from daily_stat where activity_type='Resource download') order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	# Duplicate Resources (based on file_checksum)
	if (substr($search,0,11)=="!duplicates") 
		{
			// old code disabled due to performance issues
			//return sql_query("select distinct r.hit_count score, $select from resource r $sql_join  where $sql_filter and file_checksum in (select file_checksum from (select file_checksum,count(*) dupecount from resource group by file_checksum) r2 where r2.dupecount>1) order by file_checksum",false,$fetchrows);

			// new code relies on MySQL temporary tables being enabled, as well as checksums
			// if either is not turned on, just give up.

			global $use_temp_tables;
			global $file_checksums;

			if ($use_temp_tables && $file_checksums){


				  global $temptable_counter;
                                  if (!isset($temptable_counter)){$temptable_counter = 0;}
                                  $temptable_counter++;
                                  $thetemptable = 'dupehashx' . '_' . $temptable_counter;

				$dupequery = "select distinct r.hit_count score, $select from resource r $sql_join join $thetemptable on r.file_checksum = $thetemptable.hash where $sql_filter order by file_checksum";
				sql_query("create temporary table $thetemptable (`hash` varchar(255) NOT NULL,`hashcount` int(10) default NULL, KEY `Index 1` (`hash`))",false);
				sql_query("insert into $thetemptable select file_checksum, count(file_checksum) from resource where archive = 0 and ref > 0 and file_checksum <> '' and file_checksum is not null group by file_checksum having count(file_checksum) > 1",false);
				$duperesult = sql_query($dupequery,false,$fetchrows);
				return $duperesult;
			} else {
				return false;
			}



		}
	
	# View Collection
	if (substr($search,0,11)=="!collection")
		{
		if ($orig_order=="relevance") {$order_by="c.sortorder asc,c.date_added desc,r.ref";}
		$colcustperm=$sql_join;
		if (getval("k","")!="") {$sql_filter="ref>0";} # Special case if a key has been provided.
		
		# Extract the collection number
		$collection=explode(" ",$search);$collection=str_replace("!collection","",$collection[0]);
		$collection=explode(",",$collection);// just get the number
		$collection=$collection[0];
		# smart collections update
		global $allow_smart_collections;
		if ($allow_smart_collections){
			$smartsearch=sql_value("select savedsearch value from collection where ref=$collection",null);
			if ($smartsearch!=0){
				$smartsearch=sql_query("select * from collection_savedsearch where ref=$smartsearch");
				$smartsearch=$smartsearch[0];
				$results=do_search($smartsearch['search'], $smartsearch['restypes'], "relevance", $smartsearch['archive'],-1,"desc",true);
				# results is a list of the current search without any restrictions
				# we need to compare against the current collection contents to minimize inserts and deletions
				$current=sql_query("select resource from collection_resource where collection=$collection");
				$current_contents=array(); $results_contents=array();
				if (!empty($current)){foreach($current as $current_item){ $current_contents[]=$current_item['resource'];}}
				if (!empty($results)&&is_array($results)){foreach($results as $results_item){ $results_contents[]=$results_item['ref'];}}
				
					for ($n=0;$n<count($results_contents);$n++)
						{
						if (!in_array($results_contents[$n],$current_contents)){ add_resource_to_collection($results_contents[$n],$collection,true);}
						}
					

					for ($n=0;$n<count($current_contents);$n++)
						{
						if (!in_array($current_contents[$n],$results_contents)){ remove_resource_from_collection($current_contents[$n],$collection,true);}
						}	
					
				}
			}		

		return sql_query($sql_prefix . "select distinct c.date_added,c.comment,c.purchase_size,c.purchase_complete,r.hit_count score,length(c.comment) commentset, $select from resource r  join collection_resource c on r.ref=c.resource $colcustperm  where c.collection='" . $collection . "' and $sql_filter group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	# View Related
	if (substr($search,0,8)=="!related")
		{
		# Extract the resource number
		$resource=explode(" ",$search);$resource=str_replace("!related","",$resource[0]);
		$order_by=str_replace("r.","",$order_by); # UNION below doesn't like table aliases in the order by.
		
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r join resource_related t on (t.related=r.ref and t.resource='" . $resource . "') $sql_join  where 1=1 and $sql_filter group by r.ref 
		UNION
		select distinct r.hit_count score, $select from resource r join resource_related t on (t.resource=r.ref and t.related='" . $resource . "') $sql_join  where 1=1 and $sql_filter group by r.ref 
		order by $order_by" . $sql_suffix,false,$fetchrows);
		}
		
	# Geographic search
	if (substr($search,0,4)=="!geo")
		{
		$geo=explode("t",str_replace(array("m","p"),array("-","."),substr($search,4))); # Specially encoded string to avoid keyword splitting
		$bl=explode("b",$geo[0]);
		$tr=explode("b",$geo[1]);	
		$sql="select r.hit_count score, $select from resource r $sql_join where 

					geo_lat > '" . escape_check($bl[0]) . "'
              and   geo_lat < '" . escape_check($tr[0]) . "'		
              and   geo_long > '" . escape_check($bl[1]) . "'		
              and   geo_long < '" . escape_check($tr[1]) . "'		
                          
		 and $sql_filter group by r.ref order by $order_by";
		return sql_query($sql_prefix . $sql . $sql_suffix,false,$fetchrows);
		}


	# Colour search
	if (substr($search,0,7)=="!colour")
		{
		$colour=explode(" ",$search);$colour=str_replace("!colour","",$colour[0]);

		$sql="select r.hit_count score, $select from resource r $sql_join
				where 
					colour_key like '" . escape_check($colour) . "%'
              	or  colour_key like '_" . escape_check($colour) . "%'
                          
		 and $sql_filter group by r.ref order by $order_by";
		return sql_query($sql_prefix . $sql . $sql_suffix,false,$fetchrows);
		}		

		
	# Similar to a colour
	if (substr($search,0,4)=="!rgb")
		{
		$rgb=explode(":",$search);$rgb=explode(",",$rgb[1]);
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where has_image=1 and $sql_filter group by r.ref order by (abs(image_red-" . $rgb[0] . ")+abs(image_green-" . $rgb[1] . ")+abs(image_blue-" . $rgb[2] . ")) asc limit 500" . $sql_suffix,false,$fetchrows);
		}
		
	# Similar to a colour by key
	if (substr($search,0,10)=="!colourkey")
		{
		# Extract the colour key
		$colourkey=explode(" ",$search);$colourkey=str_replace("!colourkey","",$colourkey[0]);
		
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where has_image=1 and left(colour_key,4)='" . $colourkey . "' and $sql_filter group by r.ref" . $sql_suffix,false,$fetchrows);
		}
	
	global $config_search_for_number;
	if (($config_search_for_number && is_numeric($search)) || substr($search,0,9)=="!resource")
        {
		$theref = escape_check($search);
		$theref = preg_replace("/[^0-9]/","",$theref);
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where ref='$theref' and $sql_filter group by r.ref" . $sql_suffix);
        }

	# Searching for pending archive
	if (substr($search,0,15)=="!archivepending")
		{
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where archive=1 and ref>0 group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	if (substr($search,0,12)=="!userpending")
		{
		if ($orig_order=="rating") {$order_by="request_count desc," . $order_by;}
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where archive=-1 and ref>0 group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		}
		
	# View Contributions
	if (substr($search,0,14)=="!contributions") 
		{
		global $userref;
		
		# Extract the user ref
		$cuser=explode(" ",$search);$cuser=str_replace("!contributions","",$cuser[0]);
		
		if ($userref==$cuser) {$sql_filter="archive='$archive'";$sql_join="";} # Disable permissions when viewing your own contributions - only restriction is the archive status
		$select=str_replace(",rca.access group_access,rca2.access user_access ",",null group_access, null user_access ",$select);
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where created_by='" . $cuser . "' and r.ref > 0 and $sql_filter group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	# Search for resources with images
	if ($search=="!images") return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where has_image=1 group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);

	# Search for resources not used in Collections
	if (substr($search,0,7)=="!unused") 
		{
		return sql_query($sql_prefix . "SELECT distinct $select FROM resource r $sql_join  where r.ref>0 and r.ref not in (select c.resource from collection_resource c) and $sql_filter" . $sql_suffix,false,$fetchrows);
		}	
	
	# Search for a list of resources
	if (substr($search,0,5)=="!list") 
		{	
		$resources=explode(" ",$search);
		$resources=str_replace("!list","",$resources[0]);
		$resources=explode(",",$resources);// separate out any additional keywords
		$resources=escape_check($resources[0]);
		if (strlen(trim($resources))==0){
			$resources="where r.ref IS NULL";
		}
		else {	
		$resources="where (r.ref='".str_replace(":","' OR r.ref='",$resources) . "')";
		}
	
		return sql_query($sql_prefix . "SELECT distinct r.hit_count score, $select FROM resource r $sql_join $resources and $sql_filter order by $order_by" . $sql_suffix,false,$fetchrows);
		}		

	# Within this hook implementation, set the value of the global $sql variable:
	# Since there will only be one special search executed at a time, only one of the
	# hook implementations will set the value.  So, you know that the value set
	# will always be the correct one (unless two plugins use the same !<type> value).
	$sql="";
	hook ("addspecialsearch");	
	if($sql != "")
	{
		debug("Addspecialsearch hook returned useful results.");
		return sql_query($sql_prefix . $sql . $sql_suffix,false,$fetchrows);
	}

	# -------------------------------------------------------------------------------------
	# Standard Searches
	# -------------------------------------------------------------------------------------
	
	# We've reached this far without returning.
	# This must be a standard (non-special) search.
	
	# Construct and perform the standard search query.
	#$sql="";
	if ($sql_filter!="")
		{
		if ($sql!="") {$sql.=" and ";}
		$sql.=$sql_filter;
		}

	# Append custom permissions	
	$t.=$sql_join;
	
	if ($score=="") {$score="r.hit_count";} # In case score hasn't been set (i.e. empty search)
	global $max_results;
	if (($t2!="") && ($sql!="")) {$sql=" and " . $sql;}
	
	# Compile final SQL

	# Performance enhancement - set return limit to number of rows required
	if ($search_sql_double_pass_mode && $fetchrows!=-1) {$max_results=$fetchrows;}

	$results_sql=$sql_prefix . "select distinct $score score, $select from resource r" . $t . "  where $t2 $sql group by r.ref order by $order_by limit $max_results" . $sql_suffix;

	# Debug
	debug("\n" . $results_sql);

	# Execute query
	$result=sql_query($results_sql,false,$fetchrows);

	# Performance improvement - perform a second count-only query and pad the result array as necessary
	if ($search_sql_double_pass_mode && count($result)>0 && count($result)>=$max_results)
		{
		$count_sql="select count(distinct r.ref) value from resource r" . $t . "  where $t2 $sql";
		$count=sql_value($count_sql,0);
		$result=array_pad($result,$count,0);
		}

	debug("Search found " . count($result) . " results");
	if (count($result)>0) {return $result;}
	
	# (temp) - no suggestion for field-specific searching for now - TO DO: modify function below to support this
	if (strpos($search,":")!==false) {return "";}
	
	# All keywords resolved OK, but there were no matches
	# Remove keywords, least used first, until we get results.
	$lsql="";
	$omitmatch=false;
	for ($n=0;$n<count($keywords);$n++)
		{
		if (substr($keywords[$n],0,1)=="-")
			{
			$omitmatch=true;
			$omit=$keywords[$n];
			}
		if ($lsql!="") {$lsql.=" or ";}
		$lsql.="keyword='" . $keywords[$n] . "'";
		}
	if ($omitmatch)
		{
		return trim_spaces(str_replace(" " . $omit . " "," "," " . join(" ",$keywords) . " "));		
		}
	if ($lsql!="")
		{
		$least=sql_value("select keyword value from keyword where $lsql order by hit_count asc limit 1","");
		return trim_spaces(str_replace(" " . $least . " "," "," " . join(" ",$keywords) . " "));
		}
	else
		{
		return array();
		}
	}
}


function resolve_soundex($keyword)
	{
	# returns the most commonly used keyword that sounds like $keyword, or failing a soundex match,
	# the most commonly used keyword that starts with the same few letters.
	$soundex=sql_value("select keyword value from keyword where soundex='".soundex($keyword)."' order by hit_count desc limit 1",false);
	if (($soundex===false) && (strlen($keyword)>=4))
		{
		# No soundex match, suggest words that start with the same first few letters.
		return sql_value("select keyword value from keyword where keyword like '" . substr($keyword,0,4) . "%' order by hit_count desc limit 1",false);
		}
	return $soundex;
	}
	
function suggest_refinement($refs,$search)
	{
	# Given an array of resource references ($refs) and the original
	# search query ($search), produce a list of suggested search refinements to 
	# reduce the result set intelligently.
	$in=join(",",$refs);
	$suggest=array();
	# find common keywords
	$refine=sql_query("select k.keyword,count(*) c from resource_keyword r join keyword k on r.keyword=k.ref and r.resource in ($in) and length(k.keyword)>=3 and length(k.keyword)<=15 and k.keyword not like '%0%' and k.keyword not like '%1%' and k.keyword not like '%2%' and k.keyword not like '%3%' and k.keyword not like '%4%' and k.keyword not like '%5%' and k.keyword not like '%6%' and k.keyword not like '%7%' and k.keyword not like '%8%' and k.keyword not like '%9%' group by k.keyword order by c desc limit 5");
	for ($n=0;$n<count($refine);$n++)
		{
		if (strpos($search,$refine[$n]["keyword"])===false)
			{
			$suggest[]=$search . " " . $refine[$n]["keyword"];
			}
		}
	return $suggest;
	}
	
function get_advanced_search_fields($archive=false)
	{
	# Returns a list of fields suitable for advanced searching.	
	$return=array();
	$fields=sql_query("select ref, name, title, type, options ,order_by, keywords_index, partial_index, resource_type, resource_column, display_field, use_for_similar, iptc_equiv, display_template, tab_name, required, smart_theme_name, exiftool_field, advanced_search, simple_search, help_text, display_as_dropdown from resource_type_field where advanced_search=1 and keywords_index=1 and length(name)>0 " . (($archive)?"":"and resource_type<>999") . " order by resource_type,order_by");
	# Apply field permissions
	for ($n=0;$n<count($fields);$n++)
		{
		if ((checkperm("f*") || checkperm("f" . $fields[$n]["ref"]))
		&& !checkperm("f-" . $fields[$n]["ref"]) && !checkperm("T" . $fields[$n]["resource_type"]))
		{$return[]=$fields[$n];}
		}
	return $return;
	}

function render_search_field($field,$value="",$autoupdate,$class="stdwidth",$forsearchbar=false,$limit_keywords=array())
	{
	# Renders the HTML for the provided $field for inclusion in a search form, for example the
	# advanced search page. Standard field titles are translated using $lang.  Custom field titles are i18n translated.
	#
	# $field	an associative array of field data, i.e. a row from the resource_type_field table.
	# $name		the input name to use in the form (post name)
	# $value	the default value to set for this field, if any
	
	global $auto_order_checkbox,$lang,$category_tree_open,$minyear;
	$name="field_" . $field["ref"];
	
	if (!$forsearchbar)
		{
		?>
		<div class="Question">
		<label><?php echo lang_or_i18n_get_translated($field["title"], "fieldtitle-")?></label>
		<?php
		}
	else
		{
		?>
		<div class="SearchItem">
		<?php echo lang_or_i18n_get_translated($field["title"], "fieldtitle-")?></br>
		<?php
		}

	switch ($field["type"]) {
		case 0: # -------- Text boxes
		case 1:
		case 5:
		?><input class="<?php echo $class ?>" type=text name="field_<?php echo $field["ref"]?>" value="<?php echo htmlspecialchars($value)?>" <?php if ($autoupdate) { ?>onChange="UpdateResultCount();"<?php } ?> onKeyPress="if (!(updating)) {setTimeout('UpdateResultCount()',2000);updating=true;}"><?php
		break;
	
		case 2: 
		case 3:
		# -------- Show a check list or dropdown for dropdowns and check lists?
		# By default show a checkbox list for both (for multiple selections this enabled OR functionality)
		
		# Translate all options
		$options=trim_array(explode(",",$field["options"]));
		
		$adjusted_dropdownoptions=hook("adjustdropdownoptions");
		if ($adjusted_dropdownoptions){$options=$adjusted_dropdownoptions;}
		
		$option_trans=array();
		$option_trans_simple=array();
		for ($m=0;$m<count($options);$m++)
			{
			$trans=i18n_get_translated($options[$m]);
			$option_trans[$options[$m]]=$trans;
			$option_trans_simple[]=$trans;
			}

		if ($auto_order_checkbox) {asort($option_trans);}
		$options=array_keys($option_trans); # Set the options array to the keys, so it is now effectively sorted by translated string		
		
		if ($field["display_as_dropdown"])
			{
			# Show as a dropdown box
			$set=trim_array(explode(";",cleanse_string($value,true)));
			?><select class="<?php echo $class ?>" name="field_<?php echo $field["ref"]?>" <?php if ($autoupdate) { ?>onChange="UpdateResultCount();"<?php } ?>><option value=""></option><?php
			foreach ($option_trans as $option=>$trans)
				{
				if (trim($trans)!="")
					{
					?>
					<option value="<?php echo htmlspecialchars(trim($trans))?>" <?php if (in_array(cleanse_string($trans,true),$set)) {?>selected<?php } ?>><?php echo htmlspecialchars(trim($trans))?></option>
					<?php
					}
				}
			?></select><?php
			}
		else
			{
			# Show as a checkbox list (default)
			
			$set=trim_array(explode(";",cleanse_string($value,true)));
			$wrap=0;
			$l=average_length($option_trans_simple);
			$cols=10;
			if ($l>5)  {$cols=6;}
			if ($l>10) {$cols=4;}
			if ($l>15) {$cols=3;}
			if ($l>25) {$cols=2;}

			# Filter the options array for blank values and ignored keywords.
			$newoptions=array();
			foreach ($options as $option)
				{
				if ($option!="" && (count($limit_keywords)==0 || in_array($option,$limit_keywords)))
					{
					$newoptions[]=$option;
					}
				}
			$options=$newoptions;

			
			$height=ceil(count($options)/$cols);
			global $checkbox_ordered_vertically;
			if ($checkbox_ordered_vertically)
				{
				if(!hook('rendersearchchkboxes')):
				# ---------------- Vertical Ordering (only if configured) -----------
				?><table cellpadding=2 cellspacing=0><tr><?php
				for ($y=0;$y<$height;$y++)
					{
					for ($x=0;$x<$cols;$x++)
						{
						# Work out which option to fetch.
						$o=($x*$height)+$y;
						if ($o<count($options))
							{
							$option=$options[$o];
							$trans=$option_trans[$option];

							$name=$field["ref"] . "_" . md5($option);
							if ($option!="")
								{
								?>
								<td valign=middle><input type=checkbox id="<?php echo $name?>" name="<?php echo $name?>" value="yes" <?php if (in_array(cleanse_string($trans,true),$set)) {?>checked<?php } ?> <?php if ($autoupdate) { ?>onClick="UpdateResultCount();"<?php } ?>></td><td valign=middle><?php echo htmlspecialchars($trans)?>&nbsp;&nbsp;</td>
								<?php
								}
							else
								{
								?><td></td><td></td><?php
								}
							}
						}
					?></tr><tr><?php
					}
				?></tr></table><?php
				endif;
				}
			else
				{
				# ---------------- Horizontal Ordering (Standard) ---------------------				
				?><table cellpadding=2 cellspacing=0><tr><?php
				foreach ($option_trans as $option=>$trans)
					{
					$wrap++;if ($wrap>$cols) {$wrap=1;?></tr><tr><?php }
					$name=$field["ref"] . "_" . md5($option);
					if ($option!="")
						{
						?>
						<td valign=middle><input type=checkbox id="<?php echo $name?>" name="<?php echo $name?>" value="yes" <?php if (in_array(cleanse_string(i18n_get_translated($option),true),$set)) {?>checked<?php } ?> <?php if ($autoupdate) { ?>onClick="UpdateResultCount();"<?php } ?>></td><td valign=middle><?php echo htmlspecialchars($trans)?>&nbsp;&nbsp;</td>
						<?php
						}
					}
				?></tr></table><?php
				}
				
			}
		break;
		
		case 4:
		case 6: # ----- Date types
		$found_year='';$found_month='';$found_day='';
		$s=explode("-",$value);
		if (count($s)>=3)
			{
			$found_year=$s[0];
			$found_month=$s[1];
			$found_day=$s[2];
			}
		?>		
		<select name="<?php echo $name?>_year" class="SearchWidth" style="width:100px;" <?php if ($autoupdate) { ?>onChange="UpdateResultCount();"<?php } ?>>
		  <option value=""><?php echo $lang["anyyear"]?></option>
		  <?php
		  $y=date("Y");
		  for ($d=$minyear;$d<=$y;$d++)
			{
			?><option <?php if ($d==$found_year) { ?>selected<?php } ?>><?php echo $d?></option><?php
			}
		  ?>
		</select>
		<select name="<?php echo $name?>_month" class="SearchWidth" style="width:100px;" <?php if ($autoupdate) { ?>onChange="UpdateResultCount();"<?php } ?>>
		  <option value=""><?php echo $lang["anymonth"]?></option>
		  <?php
		  for ($d=1;$d<=12;$d++)
			{
			$m=str_pad($d,2,"0",STR_PAD_LEFT);
			?><option <?php if ($d==$found_month) { ?>selected<?php } ?> value="<?php echo $m?>"><?php echo $lang["months"][$d-1]?></option><?php
			}
		  ?>
		</select>
		<select name="<?php echo $name?>_day" class="SearchWidth" style="width:100px;" <?php if ($autoupdate) { ?>onChange="UpdateResultCount();"<?php } ?>>
		  <option value=""><?php echo $lang["anyday"]?></option>
		  <?php
		  for ($d=1;$d<=31;$d++)
			{
			$m=str_pad($d,2,"0",STR_PAD_LEFT);
			?><option <?php if ($d==$found_day) { ?>selected<?php } ?> value="<?php echo $m?>"><?php echo $m?></option><?php
			}
		  ?>
		</select>
		<?php		
		break;
		
		
		case 7: # ----- Category Tree
		$options=$field["options"];
		$set=trim_array(explode(";",cleanse_string($value,true)));
		if ($forsearchbar)
			{
			# On the search bar?
			# Produce a smaller version of the category tree in a single dropdown - max two levels
			?>
			<select class="<?php echo $class ?>" name="field_<?php echo $field["ref"]?>"><option value=""></option><?php
			$class=explode("\n",$options);

			for ($t=0;$t<count($class);$t++)
				{
				$s=explode(",",$class[$t]);
				if (count($s)==3 && $s[1]==0)
					{
					# Found a first level
					?>
					<option <?php if (in_array(cleanse_string($s[2],true),$set)) {?>selected<?php } ?>><?php echo $s[2] ?></option>
					<?php
					
					# Parse tree again looking for level twos at this point
					for ($u=0;$u<count($class);$u++)
						{
						$v=explode(",",$class[$u]);
						if (count($v)==3 && $v[1]==$s[0])
							{
							# Found a first level
							?>
							<option value="<?php echo $s[2] . "," . $v[2] ?>" <?php if (in_array(cleanse_string($s[2],true),$set) && in_array(cleanse_string($v[2],true),$set)) {?>selected<?php } ?>>&nbsp;-&nbsp;<?php echo $v[2] ?></option>
							<?php
							}						
						}
					}
				}			
			?>
			</select>
			<?php
			}
		else
			{
			# For advanced search and elsewhere, include the category tree.
			include "../pages/edit_fields/7.php";
			}
		break;
		
		case 9: #-- Dynamic keywords list
		$value=str_replace(";",",",$value); # Different syntax used for keyword separation when searching.
		include "../pages/edit_fields/9.php";
		break;		
		}
	?>
	<div class="clearerleft"> </div>
	</div>
	<?php
	}

function search_form_to_search_query($fields,$fromsearchbar=false)
	{
	# Take the data in the the posted search form that contained $fields, and assemble
	# a search query string that can be used for a standard search.
	#
	# This is used to take the advanced search form and assemble it into a search query.
	
	global $auto_order_checkbox,$checkbox_and;
	$search="";
	if (getval("year","")!="")
		{
		if ($search!="") {$search.=", ";}
		$search.="year:" . getval("year","");	
		}
	if (getval("month","")!="")
		{
		if ($search!="") {$search.=", ";}
		$search.="month:" . getval("month","");	
		}
	if (getval("day","")!="")
		{
		if ($search!="") {$search.=", ";}
		$search.="day:" . getval("day","");	
		}
	if (getval("allfields","")!="")
		{
		if ($search!="") {$search.=", ";}
		$search.=join(", ",explode(" ",getvalescaped("allfields",""))); # prepend 'all fields' option
		}
	if (getval("resourceids","")!="")
		{
		$listsql="!list" . join(":",trim_array(split_keywords(getvalescaped("resourceids",""))));
		$search=$listsql . " " . $search;
		}
		
	for ($n=0;$n<count($fields);$n++)
		{
		switch ($fields[$n]["type"])
			{
			case 0: # -------- Text boxes
			case 1:
			case 5:
			$name="field_" . $fields[$n]["ref"];
			$value=getvalescaped($name,"");
			if ($value!="")
				{
				$vs=split_keywords($value);
				for ($m=0;$m<count($vs);$m++)
					{
					if ($search!="") {$search.=", ";}
					$search.=$fields[$n]["name"] . ":" . strtolower($vs[$m]);
					}
				}
			break;
			
			case 2: # -------- Dropdowns / check lists
			case 3:
			if ($fields[$n]["display_as_dropdown"])
				{
				# Process dropdown box
				$name="field_" . $fields[$n]["ref"];
				$value=getvalescaped($name,"");
				if ($value!="")
					{
					/*
					$vs=split_keywords($value);
					for ($m=0;$m<count($vs);$m++)
						{
						if ($search!="") {$search.=", ";}
						$search.=$fields[$n]["name"] . ":" . strtolower($vs[$m]);
						}
					*/
					if ($search!="") {$search.=", ";}
					$search.=$fields[$n]["name"] . ":" . $value;					
					}
				}
			else
				{
				# Process checkbox list
				$options=trim_array(explode(",",$fields[$n]["options"]));
				$p="";
				$c=0;
				for ($m=0;$m<count($options);$m++)
					{
					$name=$fields[$n]["ref"] . "_" . md5($options[$m]);
					$value=getvalescaped($name,"");
					if ($value=="yes")
						{
						$c++;
						if ($p!="") {$p.=";";}
						$p.=strtolower(i18n_get_translated($options[$m]));
						}
					}
				if ($c==count($options) && !$checkbox_and)
					{
					# all options ticked - omit from the search (unless using AND matching)
					$p="";
					}
				if ($p!="")
					{
					if ($search!="") {$search.=", ";}
					$search.=$fields[$n]["name"] . ":" . $p;
					}
				}
			break;

			case 4:
			case 6:
			$name="field_" . $fields[$n]["ref"];
			$datepart="";
			if (getval($name . "_year","")!="")
				{
				$datepart.=getval($name . "_year","");
				if (getval($name . "_month","")!="")
					{
					$datepart.="-" . getval($name . "_month","");
					if (getval($name . "_day","")!="")
						{
						$datepart.="-" . getval($name . "_day","");
						}
					}
				}
			if ($datepart!="")
				{
				if ($search!="") {$search.=", ";}
				$search.=$fields[$n]["name"] . ":" . $datepart;
				}

			break;

			case 7: 
			case 9: # -------- Category tree and dynamic keywords
			$name="field_" . $fields[$n]["ref"];
			$value=getvalescaped($name,"");
			$selected=trim_array(explode(",",$value));
			$p="";
			for ($m=0;$m<count($selected);$m++)
				{
				if ($selected[$m]!="")
					{
					if ($p!="") {$p.=";";}
					$p.=$selected[$m];
					}

				# Resolve keywords to make sure that the value has been indexed prior to including in the search string.
				$keywords=split_keywords($selected[$m]);
				foreach ($keywords as $keyword) {resolve_keyword($keyword,true);}
				}
			if ($p!="")
				{
				if ($search!="") {$search.=", ";}
				$search.=$fields[$n]["name"] . ":" . $p;
				}
			break;
			}
		}
	return $search;
	}

if (!function_exists("refine_searchstring")){
function refine_searchstring($search){
	#
	# DISABLED TEMPORARILY
	#
	# This causes an issue when using advanced search with check boxes.
	# A keyword containing spaces will break the search when used with another keyword. 
	#
	global $use_refine_searchstring;
	if (!$use_refine_searchstring){return $search;}

	global $noadd;
	// splits field-specific searches appropriately, and removes duplicate keywords
	$search=str_replace ("\xe2\x80\x8b","",$search);

	$keywords=split_keywords($search);

	global $cattreefields; if (!isset($cattreefields)){
		$cattreefields=get_category_tree_fields();
	}
	
	$fixedkeywords=array();
	foreach ($keywords as $keyword){
		if (strpos($keyword,":")>0){
			$keywordar=explode(":",$keyword,2);
			$keyname=$keywordar[0];
			if (substr($keyname,0,1)!="!"){
				if (!in_array($keyname,$cattreefields)){
					$keyvalues=explode(" ",$keywordar[1]);
				} else {
					$keyvalues=array($keywordar[1]);
				}
				foreach ($keyvalues as $keyvalue){
					if (!in_array($keyvalue,$noadd)){ 
						$fixedkeywords[]=$keyname.":".$keyvalue;
					}
				}
			}
			else {
				$keywords=explode(" ",$keyword);
				$fixedkeywords[]=$keywords[0];} // for searches such as !list
		}
		else {
			if (!in_array($keyword,$noadd)){ 
				$fixedkeywords[]=$keyword;
			}
		}
	}
	$keywords=$fixedkeywords;
	$keywords=array_unique($keywords);
	$search=implode(",",$keywords);
	return $search;
}
}
