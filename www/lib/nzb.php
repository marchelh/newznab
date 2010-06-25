<?php
require_once("config.php");
require_once(WWW_DIR."/lib/framework/db.php");
require_once(WWW_DIR."/lib/site.php");
require_once(WWW_DIR."/lib/groups.php");
require_once(WWW_DIR."/lib/nntp.php");
require_once(WWW_DIR."/lib/category.php");

class NZB 
{
	function NZB() 
	{
		//
		// TODO:Move all these to site table.
		//
		if(isset($_SERVER['HTTP_USER_AGENT']) && strlen($_SERVER['HTTP_USER_AGENT']) > 0)
			$this->n = "\n<BR>";
		else
			$this->n = "\n";
		$this->maxMssgs = 20000; //fetch this amount of messages at the time
		$this->howManyMsgsToGoBackForNewGroup = 50000; //how far back to go, use 0 to get all
	}
	
	//
	// Writes out the nzb when processing releases. Moved out of smarty due to memory issues
	// of holding all parts in an array.
	//
	function writeNZBforReleaseId($relid, $relguid, $name, $catId, $path, $echooutput=false)
	{

		$db = new DB();
		$binaries = array();
		$cat = new Category();
		$catrow = $cat->getById($catId);

		$fp = gzopen($path, "w"); 
		if ($fp)
		{
			gzwrite($fp, "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n"); 
			gzwrite($fp, "<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd\">\n"); 
			gzwrite($fp, "<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\">\n\n"); 
			gzwrite($fp, "<head>\n"); 
			if ($catrow)
				gzwrite($fp, " <meta type=\"category\">".htmlentities($catrow["title"], ENT_QUOTES)."</meta>\n"); 
			if ($name != "")
				gzwrite($fp, " <meta type=\"name\">".$name."</meta>\n"); 
			gzwrite($fp, "</head>\n\n"); 
	
			$result = $db->queryDirect(sprintf("SELECT binaries.*, UNIX_TIMESTAMP(date) AS unixdate, groups.name as groupname FROM binaries inner join groups on binaries.groupID = groups.ID WHERE binaries.releaseID = %d ORDER BY binaries.name", $relid));
			while ($binrow = mysql_fetch_array($result, MYSQL_BOTH)) 
			{				
				$groups = array();
				$groupsRaw = explode(' ', $binrow['xref']);
				foreach($groupsRaw as $grp) 
					if (preg_match('/^([a-z0-9\.\-_]+)(:\d+)?$/i', $grp, $match)) 
						$groups[] = $match[1];
				
				if (count($groups) == 0)
					$groups[] = $binrow["groupname"];

				gzwrite($fp, "<file poster=\"".htmlentities($binrow["fromname"], ENT_QUOTES)."\" date=\"".$binrow["unixdate"]."\" subject=\"".htmlentities($binrow["name"], ENT_QUOTES)." (1/".$binrow["totalParts"]."\">\n"); 
				gzwrite($fp, " <groups>\n"); 
				foreach ($groups as $group)
					gzwrite($fp, "  <group>".$group."</group>\n"); 
				gzwrite($fp, " </groups>\n"); 
				gzwrite($fp, " <segments>\n"); 

				$resparts = $db->queryDirect(sprintf("SELECT size, partnumber, messageID FROM parts WHERE binaryID = %d ORDER BY partnumber", $binrow["ID"]));
				while ($partsrow = mysql_fetch_array($resparts, MYSQL_BOTH)) 
				{				
					gzwrite($fp, "  <segment bytes=\"".$partsrow["size"]."\" number=\"".$partsrow["partnumber"]."\">".htmlentities($partsrow["messageID"], ENT_QUOTES)."</segment>\n"); 
				}
				gzwrite($fp, " </segments>\n</file>\n"); 
			}
			gzwrite($fp, "<!-- generated by newznab -->\n</nzb>"); 
			gzclose($fp); 
		}
	}
	
	//
	// builds a full path to the nzb file on disk. nzbs are stored in a subdir of their first char.
	//
	function getNZBPath($releaseGuid, $sitenzbpath = "", $createIfDoesntExist = false)
	{
		if ($sitenzbpath == "")
		{
			$s = new Site;
			$site = $s->get();
			$sitenzbpath = $site->nzbpath;
		}

		$nzbpath = $sitenzbpath.substr($releaseGuid, 0, 1)."/";

		if ($createIfDoesntExist && !file_exists($nzbpath))
				mkdir($nzbpath);
		
		return $nzbpath.$releaseGuid.".nzb.gz";
	}

	//
	// Update all active groups categories and descriptions
	//
	function updateAllGroups() 
	{
		$n = $this->n;
		$groups = new Groups;
		$res = $groups->getActive();

		if ($res)
		{
			$nntp = new Nntp();
			$nntp->doConnect();

			foreach($res as $groupArr) 
			{
				$this->message = array();
				$this->updateGroup($nntp, $groupArr);
			}
			
			$nntp->doQuit();	
		}
		else
		{
			echo "No groups specified. Ensure site.groupfilter is populated and run group-update.$n";
		}		
	}	

	function updateGroup($nntp, $groupArr) 
	{
		$db = new DB();
		$n = $this->n;
		$attempts = 0;

		$data = $nntp->selectGroup($groupArr['name']);
		if(PEAR::isError($data)) 
		{
			echo "Could not select group: {$groupArr['name']}$n";
			die();
		}
		
		//get first and last part numbers from newsgroup
		$last = $orglast = $data['last'];
		if($groupArr['last_record'] == 0) 
		{
			//
			// for new newsgroups - determine here how far you want to go back.
			//
			if($data['first'] > ($data['last'] - $this->howManyMsgsToGoBackForNewGroup))
				$first = $data['first'];
			else
				$first = $data['last'] - $this->howManyMsgsToGoBackForNewGroup;	
		$db->query(sprintf("UPDATE groups SET first_record = %s WHERE ID = %d", $db->escapeString($first), $groupArr['ID']));
		} 
		else 
		{
			$first = $groupArr['last_record'] + 1;
		}

		//calculate total number of parts
		$total = $last - $first;

		//if total is bigger than 0 it means we have new parts in the newsgroup
		if($total > 0) 
		{

			echo "Group ".$data["group"]." has ".$data['first']." - ".$last." = {$total} (Total parts) - Local last = ".$groupArr['last_record'].$n;

			$done = false;

			//get all the parts (in portions of $this->maxMssgs to not use too much memory)
			while($done === false) 
			{
				if($total > $this->maxMssgs) 
				{
					if($first + $this->maxMssgs > $orglast) 
					{
						$last = $orglast;
					} 
					else 
					{
						$last = $first + $this->maxMssgs;
					}
				}

				if($last - $first < $this->maxMssgs) 
				{
					$fetchpartscount = $last - $first;
				} 
				else 
				{
					$fetchpartscount = $this->maxMssgs;
				}
				echo "Getting {$fetchpartscount} parts (".($orglast - $last)." in queue)";
				flush();

				//get headers from newsgroup
				echo " getting $first to $last: $n";
				$msgs = $nntp->getOverview($first."-".$last, true, false);

				//loop headers, figure out parts
				foreach($msgs AS $msg) 
				{
                    $pattern = '/\((\d+)\/(\d+)\)$/i';
                    if (!isset($msg['Subject']) || !preg_match($pattern, $msg['Subject'], $matches))
                    {
                        // not a binary post most likely.. continue with next
                        continue;
                    }

					if(is_numeric($matches[1]) && is_numeric($matches[2])) 
					{
                        array_map('trim', $matches);
                        $subject = trim(preg_replace($pattern, '', $msg['Subject']));

						if(!isset($this->message[$subject])) 
						{
							$this->message[$subject] = $msg;
							$this->message[$subject]['MaxParts'] = (int)$matches[2];
							$this->message[$subject]['Date'] = strtotime($this->message[$subject]['Date']);
						}
						if((int)$matches[1] > 0) 
						{
							$this->message[$subject]['Parts'][(int)$matches[1]] = array('Message-ID' => substr($msg['Message-ID'],1,-1), 'number' => $msg['Number'], 'part' => (int)$matches[1], 'size' => $msg['Bytes']);
						}
					}
				}

				$count = 0;
				$updatecount = 0;
				$partcount = 0;

				if(isset($this->message) && count($this->message)) 
				{

					//insert binaries and parts into database. when binary already exists; only insert new parts
					foreach($this->message AS $subject => $data) 
					{
						if(isset($data['Parts']) && count($data['Parts']) > 0 && $subject != '') 
						{
							$res = $db->queryOneRow(sprintf("SELECT ID FROM binaries WHERE name = %s AND fromname = %s AND groupID = %d", $db->escapeString($subject), $db->escapeString($data['From']), $groupArr['ID']));
							if(!$res) 
							{
								$binaryID = $db->queryInsert(sprintf("INSERT INTO binaries (name, fromname, date, xref, totalparts, groupID, dateadded) VALUES (%s, %s, FROM_UNIXTIME(%s), %s, %s, %d, now())", $db->escapeString($subject), $db->escapeString($data['From']), $db->escapeString($data['Date']), $db->escapeString($data['Xref']), $db->escapeString($data['MaxParts']), $groupArr['ID']));
								$count++;
							} 
							else 
							{
								$binaryID = $res["ID"];
								$updatecount++;
							}

							foreach($data['Parts'] AS $partdata) 
							{
								$partcount++;
								$db->queryInsert(sprintf("INSERT INTO parts (binaryID, messageID, number, partnumber, size, dateadded) VALUES (%d, %s, %s, %s, %s, now())", $binaryID, $db->escapeString($partdata['Message-ID']), $db->escapeString($partdata['number']), $db->escapeString(round($partdata['part'])), $db->escapeString($partdata['size'])));
							}
						}
					}
					
					//
					// update the group with the last update record.
					//
					$db->query(sprintf("UPDATE groups SET last_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($last), $groupArr['ID']));
					
					echo "Received $count new binaries$n";
					echo "Updated $updatecount binaries$n";

					//when last = orglast; all headers are downloaded; not ? than go on with next $this->maxMssgs messages
					if($last == $orglast) 
						$done = true;
					else 
						$first = $last + 1;

					unset($this->message);
					unset($msgs);
					unset($msg);
					unset($data);
					
				} 
				else 
				{
                    // TODO: fix some max attemps variable.. somewhere
					$attempts++;
					echo "No binary parts found in group.  Attempt {$attempts} of 1..$n";
					if($attempts == 1) 
					{
						echo "Skipping group$n";
						break;
					}
					sleep(1);
				}
			}
			
		} 
		else 
		{
			echo "No new records for ".$data["group"]." (first $first last $last total $total) grouplast ".$groupArr['last_record']."$n";
		}
	}


	function scantest() 
	{
		$groups = new Groups;
		$res = $groups->getActive();
		$nntp = new Nntp();
		$nntp->doConnect();
		foreach($res as $groupArr)
		{
			if($groupArr['name']=='alt.binaries.teevee')
			{
				$this->update($nntp,$groupArr['name'],'68425551','68427551');
			}
			else
				continue;
		}
	}

    // TODO: check if the new regex for extracting parts is working.. as per updateGroup() function
	function update($nntp, $group, $begin, $end, $direction=TRUE) //scan headers; inputs need to be valid, freshness check upstream 
	{	$temp_begin = $begin;
		$temp_end = $end;
		$db = new DB();
		$attempts = 0;
		$data = $nntp->selectGroup($group);
		if(PEAR::isError($data)) 
		{
			echo "Could not open group $group or that group's details.  Check that it is a valid group.\n";
			die();
		}
		
		//$data example
		//[group] => alt.binaries.sounds.mp3.electronic
		//[first] => 5494095
		//[last] =>  7111079
		//[count] => 1616985
		//I lied, we're going to check for validity anyway.
		if($begin < $data['first'] || $end > $data['last'] || $end <= $first || $begin==0 || $end==0)
			{
			echo "Error seeking past hard limit, group $group first ".$data['first']." last ".$data['last']." begin $begin end $end$n"; 
			die();	
		}
		$done = false;
		//limit temporary queue
		if($direction==TRUE && $end > $begin + $this->maxMssgs)
			$temp_end = $begin + $this->maxMssgs;
		if($direction==FALSE && $begin < $end - $this->maxMssgs)
			$temp_end = $end - $this->maxMssgs;
		//get all the parts (in portions of $this->maxMssgs to not use too much memory)
		while($done === false) 
		{
			flush();

				//get headers from newsgroup
			$msgs = $nntp->getOverview($temp_begin."-".$temp_end, true, false);
			foreach($msgs AS $msg) 
			{

                $pattern = '/\((\d+)\/(\d+)\)$/i';
                if (!preg_match($pattern, $msg['Subject'], $matches))
                {
                    // not a binary post most likely.. continue with next
                    continue;
                }

                if(is_numeric($matches[1]) && is_numeric($matches[2])) 
                {
                    array_map('trim', $matches);
                    $subject = trim(preg_replace($pattern, '', $msg['Subject']));

					if(!isset($this->message[$subject])) 
					{
						$this->message[$subject] = $msg;
						$this->message[$subject]['MaxParts'] = (int)$matches[2];
						$this->message[$subject]['Date'] = strtotime($this->message[$subject]['Date']);
					}
					
                    if ((int)$matches[1] > 0) 
					{
						$this->message[$subject]['Parts'][(int)$matches[1]] = array('Message-ID' => substr($msg['Message-ID'],1,-1), 'number' => $msg['Number'], 'part' => (int)$matches[1], 'size' => $msg['Bytes']);
					}
				}
			}
			$count = 0;
			$updatecount = 0;
			$partcount = 0;
			if(count($this->message)) 
			{
				//insert binaries and parts into database. when binary already exists; only insert new parts
				foreach($this->message AS $subject => $data) 
				{
					if(isset($data['Parts']) && count($data['Parts']) > 0 && $subject != '') 
					{
						$res = $db->queryOneRow(sprintf("SELECT ID FROM binaries WHERE name = %s AND fromname = %s AND groupID = %d", $db->escapeString($subject), $db->escapeString($data['From']), $groupArr['ID']));
						if(!$res) 
						{
							$binaryID = $db->queryInsert(sprintf("INSERT INTO binaries (name, fromname, date, xref, totalparts, groupID) VALUES (%s, %s, FROM_UNIXTIME(%s), %s, %s, %d)", $db->escapeString($subject), $db->escapeString($data['From']), $db->escapeString($data['Date']), $db->escapeString($data['Xref']), $db->escapeString($data['MaxParts']), $groupArr['ID']));
							$count++;
						} 
						else 
						{
							$binaryID = $res["ID"];
							$updatecount++;
						}
							foreach($data['Parts'] AS $partdata) 
						{
							$partcount++;
							$db->queryInsert(sprintf("INSERT INTO parts (binaryID, messageID, number, partnumber, size) VALUES (%d, %s, %s, %s, %s)", $binaryID, $db->escapeString($partdata['Message-ID']), $db->escapeString($partdata['number']), $db->escapeString(round($partdata['part'])), $db->escapeString($partdata['size'])));
						}
					}
				}
				
				//
				// update the group with the last update record.
				//
				if($direction==TRUE)
					$db->query(sprintf("UPDATE groups SET last_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($temp_end), $groupArr['ID']));
				else
					$db->query(sprintf("UPDATE groups SET last_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($temp_begin), $groupArr['ID']));
				
				echo "Received $count new binaries, Updated $updatecount binaries$n";
					//when last = orglast; all headers are downloaded; not ? than go on with next $this->maxMssgs messages
				if(($direction==TRUE && $temp_end==$end) || ($direction==FALSE && $temp_begin==$begin))
					$done = true;
				else
				{
					if($direction==TRUE)
					{
						$temp_begin = $temp_end + 1;
						if($temp_end + $this->maxMssgs + 1 < $end)
							$temp_end = $temp_end + $this_maxMssgs + 1;
						else
							$temp_end = $end;
					}
					if($direction==FALSE)
						$temp_end = $temp_begin - 1;
					{
						if($temp_begin - $this->maxMssgs - 1 > $begin)
							$temp_begin = $temp_begin - $this->maxMssgs - 1;
						else
							$temp_begin = $begin;
					}
				}
				unset($this->message);
				unset($msgs);
				unset($msg);
				unset($data);
				
			} 
			else 
			{
				$attempts++;
				echo "Error fetching group $group messages $begin to $end$n";
				if($attempts == 1) 
				{
					break;
				}
				sleep(1);
			}
		} 
	}

	function nzbFileList($nzb) 
	{
	    $result = array();
	   
	    $nzb = str_replace("\x0F", "", $nzb);
	   	$num_pars = 0;
	    $xml = @simplexml_load_string($nzb);
	    if (!$xml || strtolower($xml->getName()) != 'nzb') 
	    {
	      return false;
	    }

	    $i=0;
	    foreach($xml->file as $file) 
	    {
          //subject
          $title = $file->attributes()->subject;
          if (preg_match('/\.par2/i', $title)) 
          	$num_pars++;

          $result[$i]['title'] = "$title";
 
          //filesize
          $filesize = 0;
          foreach($file->segments->segment as $segment)
          	$filesize += $segment->attributes()->bytes;

          $result[$i]['size'] = $filesize;
         
          $i++;
	    }
	   
	    return $result;
	}
	
}
?>
