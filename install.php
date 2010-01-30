<?php

//for translation only
if (false) {
_("Dial System FAX");
}

if (! function_exists("out")) {
	function out($text) {
		echo $text."<br />";
	}
}

if (! function_exists("outn")) {
	function outn($text) {
		echo $text;
	}
}

global $db;

$sql[]='CREATE TABLE IF NOT EXISTS `fax_details` (
  `key` varchar(50) default NULL,
  `value` varchar(510) default NULL,
  UNIQUE KEY `key` (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;';


$sql[]='CREATE TABLE IF NOT EXISTS `fax_incoming` (
  `cidnum` varchar(20) default NULL,
  `extension` varchar(50) default NULL,
  `detection` varchar(20) default NULL,
  `detectionwait` varchar(5) default NULL,
  `destination` varchar(50) default NULL,
  `legacy_email` varchar(50) default NULL
)';

$sql[]='CREATE TABLE IF NOT EXISTS `fax_users` (
  `user` varchar(15) default NULL,
  `faxenabled` varchar(10) default NULL,
  `faxemail` varchar(50) default NULL,
  UNIQUE KEY `user` (`user`)
)';


foreach ($sql as $statement){
	$check = $db->query($statement);
	if (DB::IsError($check)){
		die_freepbx( "Can not execute $statement : " . $check->getMessage() .  "\n");
	}
}
/* migrate simu_fax from core to fax module, including in miscdests module in case it is being used as a destination.
   this migration is a bit "messy" but assures that any simu_fax settings or destinations being used in the dialplan
   will migrate silently and continue to work.
 */
outn(_("Moving simu_fax feature code from core.."));
$check = $db->query("UPDATE featurecodes set modulename = 'fax' WHERE modulename = 'core' AND featurename = 'simu_fax'");
if (DB::IsError($check)){
  out(_("unknown error"));
} else {
  out(_("done"));
}
outn(_("Updating simu_fax in miscdest table.."));
$check = $db->query("UPDATE miscdests set destdial = '{fax:simu_fax}' WHERE destdial = '{core:simu_fax}'");
if (DB::IsError($check)){
  out(_("not needed"));
} else {
  out(_("done"));
}
$fcc = new featurecode('fax', 'simu_fax');
$fcc->setDescription('Dial System FAX');
$fcc->setDefault('666');
$fcc->update();
unset($fcc);

//check to make sure that min/maxrate and ecm are set; if not set them to defaults
$settings=sql('SELECT * FROM fax_details', 'getAssoc', 'DB_FETCHMODE_ASSOC');
foreach($settings as $setting => $value){$set[$setting]=$value['0'];}
if(!is_array($set)){$set=array();}//never return a null value
if(!$set['minrate']){$sql[]='REPLACE INTO fax_details (`key`, `value`) VALUES ("minrate","14400")';}
if(!$set['maxrate']){$sql[]='REPLACE INTO fax_details (`key`, `value`) VALUES ("maxrate","14400")';}
if(!$set['ecm']){$sql[]='REPLACE INTO fax_details (`key`, `value`) VALUES ("ecm","yes")';}

foreach ($sql as $statement){
	$check = $db->query($statement);
	if (DB::IsError($check)){
		die_freepbx( "Can not execute $statement : " . $check->getMessage() .  "\n");
	}
}

/*
incoming columns:

faxexten: disabled
          default (check what global is)
          device_num

determine what default is, if a device then treat as that default device, if system
then treat as it was system here, and if disabled then treat as that.

legacy_email:
  null -> not in legacy mode
  blank or value -> in legacy mode

*/
outn(_("Checking if legacy fax needs migrating.."));
$sql = "SELECT `extension`, `cidnum`, `faxexten`, `faxemail`, `wait`, `answer` FROM `incoming`";
$legacy_settings = $db->getAll($sql, DB_FETCHMODE_ASSOC);
if(!DB::IsError($legacy_settings)) {
	out(_("starting migration"));

  // First step, need to get global settings and if not present use defaults
  //
  $sql = "SELECT variable, value FROM globals WHERE variable IN ('FAX_RX', 'FAX_RX_EMAIL', 'FAX_RX_FROM')";
  $globalvars = $db->getAll($sql, DB_FETCHMODE_ASSOC);

  foreach ($globalvars as $globalvar) {
	  $global[trim($globalvar['variable'])] = $globalvar['value'];	
  }
  $fax_rx =          isset($global['FAX_RX'])       ? $global['FAX_RX'] : 'disabled';
  $fax_rx_email =    isset($global['FAX_RX_EMAIL']) ? $global['FAX_RX_EMAIL'] : '';
  $sender_address  = isset($global['FAX_RX_FROM'])  ? $global['FAX_RX_FROM'] : '';

  // Now some sanity settings, can't email the fax if no email present
  if ($fax_rx_email == '') {
    $fax_rx = 'disabled';
  }

  // TODO Update Module Defaults Here
  // insert_general_values()
  //
  $global_migrate = array();
  $global_migrate[] = array('sender_address',$sender_address);
  $global_migrate[] = array('fax_rx_email',$fax_rx_email);

	outn(_("migrating defaults.."));
	$compiled = $db->prepare("REPLACE INTO `fax_details` (`key`, `value`) VALUES (?,?)");
	$result = $db->executeMultiple($compiled,$global_migrate);
	if(DB::IsError($result)) {
    out(_("failed"));
		die_freepbx( "Fatal error during migration: " . $result->getMessage() .  "\n");
	} else {
    out(_("migrated"));
  }

	$detection_type = array(0 => 'dahdi', 1 => 'dahdi', 2 => 'nvfax');
	$non_converts = array();

  if (count($legacy_settings)) {
    foreach($legacy_settings as $row) {
      $legacy_email = null;
      if ($row['faxexten'] == 'default') {
        $row['faxexten'] = $fax_rx;
      } else if ($row['faxexten'] == '') {
        $row['faxexten'] = 'disabled';
			}
			if ($row['wait'] < 2) {
        $detectionwait = '2';
      } elseif ($row['wait'] > 10) {
        $detectionwait = '10';
      } else {
        $detectionwait = $row['wait'];
      }
      $detection = $detection_type[$row['answer']];
      switch ($row['faxexten']) {
        case 'disabled':
          continue; // go back to foreach for now
        break;

        case 'system':
          $legacy_email = $row['faxemail'] ? $row['faxemail'] : $fax_rx_email;

          // Now some sanity, if faxemail is blank then it won't work and we treat as disabled
          //
          if (!$legacy_email) {
            continue;
          }
          $destination = '';
			    $insert_array[] = array($row['extension'], $row['cidnum'], $detection, $detectionwait, $destination, $legacy_email);
        break;

        default:
          if (ctype_digit($row['faxexten'])) {
            $sql = "SELECT `user` FROM `devices` WHERE `id` = '".$row['faxexten']."'";
            $user = $db->getOne($sql); 
            if (ctype_digit($user)) {
              $destination = "from-did-direct,$user,1";
            } else {
							$non_converts[] = array('extension' => $row['extension'], 'cidnum' => $row['cidnum'], 'device' => $row['faxexten'], 'user' => $user);
              continue;
            }
          }
			  $insert_array[] = array($row['extension'], $row['cidnum'], $detection, $detectionwait, $destination, $legacy_email);
        break;
      }
    }

		$compiled = $db->prepare("INSERT INTO `fax_incoming` (`extension`, `cidnum`, `detection`, `detectionwait`, `destination`, `legacy_email`) VALUES (?,?,?,?,?,?)");
		$result = $db->executeMultiple($compiled,$insert_array);
		if(DB::IsError($result)) {
      out("Fatal error migrating to fax module..legacy data retained in incoming and globals tables");
		  die_freepbx( "Fatal error during migration: " . $result->getMessage() .  "\n");
		} else {
			$migrate_array = array('faxexten', 'faxemail', 'wait', 'answer');
			foreach ($migrate_array as $field) {
				outn(sprintf(_("Removing field %s from incoming table.."),$field));
				$sql = "ALTER TABLE `incoming` DROP `".$field."`";
				$results = $db->query($sql);
				if (DB::IsError($results)) { 
					out(_("not present"));
				} else {
					out(_("removed"));
				}
			}
			outn(_("Removing old globals.."));
      $sql = "DELETE FROM globals WHERE variable IN ('FAX_RX', 'FAX_RX_EMAIL', 'FAX_RX_FROM')";

			$results = $db->query($sql);
			if (DB::IsError($results)) { 
				out(_("failed"));
			} else {
				out(_("removed"));
			}

	    $failed_faxes = count($non_converts);
      outn(_("Checking for failed migrations.."));
	    if ($failed_faxes) {
        $notifications = notifications::create($db);
		    $extext = _("The following Inbound Routes had FAX processing that failed migration because they were accessing a device with no associated user. They have been disabled and will need to be updated. Click delete icon on the right to remove this notice.")."<br />";
		    foreach ($non_converts as $did) {
          $didval = trim($did['extension']) == '' ? _("blank") : $did['extension'];
          $cidval = trim($did['cidnum']) == '' ? _("blank") : $did['cidnum'];
			    $extext .= "DID: ".$didval." CIDNUM: ".$cidval." PREVIOUS DEVICE: ".$did['device']."<br />";
		    }
		    $notifications->add_error('fax', 'FAXMIGRATE', sprintf(_('%s FAX Migrations Failed'),$failed_faxes), $extext, '', true, true);
        out(sprintf(_('%s FAX Migrations Failed, check notification panel for details'),$failed_faxes));
	    } else {
        out(_("all migrations succeded successfully"));
      }
		}
  } else {
	  out(_("No Inbound Routes to migrate"));
  }
} else {
	out(_("already done"));
}
?>
