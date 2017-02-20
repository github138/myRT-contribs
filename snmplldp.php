<?php

/********************************************
 *
 * RackTables 0.20.x snmp LLDP extension v0.0.1
 *
 *	!! experimental !!
 *
 *	displays SNMP LLDP information
 *	create missing links
 *
 *
 * needs PHP >= 5.4.0
 *	saved SNMP settings ( see snmpgeneric.php extension )
 *	also RT port names and SNMP port names must be the same ( should work fine with snmpgeneric.php created ports )
 *
 * (c)2017 Maik Ehinger <m.ehinger@ltur.de>
 */

/*********
 * TODO
 *
 * - CDP
 * - remote address
 * - better link checking (first-last port)
 *
 */

/****
 * INSTALL
 * 	just place file in plugins directory
 *	create following table
CREATE TABLE `LLDPCache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `object_id` int(10) unsigned NOT NULL,
  `src` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `location` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `chassisidsubtype` tinyint(4) DEFAULT NULL,
  `chassisid` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `portidsubtype` tinyint(4) DEFAULT NULL,
  `portid` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `portdesc` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `sysname` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `sysdesc` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `manaddr` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `locportidsubtype` tinyint(4) DEFAULT NULL,
  `locportid` char(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
 *
 */

/**
 * The newest version of this plugin can be found at:
 *
 * https://github.com/github138/myRT-contribs/tree/develop-0.20.x
 *
 */

/* RackTables Debug Mode */
//$debug_mode=1;

$tab['object']['snmplldp'] = 'SNMP LLDP';
$tabhandler['object']['snmplldp'] = 'snmplldp_tabhandler';
$trigger['object']['snmplldp'] = 'snmplldp_tabtrigger';

$ophandler['object']['snmplldp']['set'] = 'snmplldp_opset';

function snmplldp_tabtrigger() {
	// display tab only on IPv4 Objects
	return considerConfiguredConstraint (spotEntity ('object', getBypassValue()), 'IPV4OBJ_LISTSRC') ? 'std' : '';
} /* snmplldp_tabtrigger */

function snmplldp_tabhandler($object_id)
{

	if(isset($_GET['debug']))
		$debug = $_GET['debug'];
	else
		$debug = 0;

	$object = spotEntity('object', $object_id);

	lldp_getsnmp($object, $debug);

	printlldp($object);

} /* snmplldp_tabhandler */

function snmplldp_opset()
{
	foreach ($_POST as $porta_id => $portb_id)
	{
		linkPorts($porta_id, $portb_id, 'LLDP');

		$porta = fetchPortList('Port.id = ?', array ($porta_id));
		$portb = fetchPortList('Port.id = ?', array ($portb_id));

		showSuccess('Ports '.$porta[0]['object_name'].'['.$porta[0]['name'].'] '.$portb[0]['object_name'].'['.$portb[0]['name'].'] linked');
	}
} /* snmplldp_opset */

/* -------------------------------------------------- */

function printlldp($object)
{
	$object_id = $object['id'];

	$result = usePreparedSelectBlade(
				'SELECT * from LLDPCache WHERE object_id = ? ORDER BY location',
				array ($object_id)
			);

	$ret = $result->fetchAll (PDO::FETCH_ASSOC);

	if ($ret === FALSE)
		return FALSE;

	startPortlet ("Local LLDP Data");
	// local lldp data
	$loc = array_shift ($ret);

	$columns = array(
			array ('row_key' => 0),
			array ('row_key' => 1)
			);

	$rows[] = array ('Chassis ID:', $loc['chassisid']);
	$rows[] = array ('SysName:', $loc['sysname']);
	$rows[] = array ('SysDesc:', $loc['sysdesc']);
	$rows[] = array ('ManAddr:', $loc['manaddr']);
	renderTableViewer($columns, $rows);
	finishPortlet ();

	foreach ($ret as $key => $rem)
	{
		// search linked object/port
		$result = usePreparedSelectBlade(
				'SELECT * from LLDPCache WHERE location = "loc" AND chassisid = ? ORDER BY sysname, portdesc',
				array ($rem['chassisid'])
			);

		$remotes = $result->fetchAll (PDO::FETCH_ASSOC);

		if (count ($remotes) > 1)
			showWarning("More than one Chassis IDs ${rem['chassisid']} found");

		$ret[$key]['remote_name'] = NULL;
		$ret[$key]['link'] = NULL;
		$ret[$key]['descr'] = NULL;

		foreach ($remotes as $chassis)
		{
			$remote_object = spotEntity('object', $chassis['object_id']);
			$ret[$key]['remote_name'] = $remote_object['name'];

			switch($rem['portidsubtype'])
			{
				case 3:
					// macAddress
					$portsubtype = 'l2address';
					break;
				case 1:
					// interfacealias
				case 5:
					// interfacename
				default:
					$portsubtype = 'name';
					break;
			}

			$rem_ports = fetchPortList ("Port.object_id = ? AND Port.$portsubtype = ?", array ($chassis['object_id'], $rem['portid']));

			$rem_port_info = $remote_object['name']."[${rem['portid']}]";

			if (count ($rem_ports) == 0)
			{
				showwarning ("Remote Port $rem_port_info info not found!!");
				$ret[$key]['descr'] = 'remote port missing';
				continue;
			}

			if (count ($rem_ports) > 1)
			{
				showWarning ("More than one remote port $rem_port_info found");
				$ret[$key]['descr'] = 'multiple remote ports';
				continue;
			}

			switch($rem['locportidsubtype'])
			{
				case 3:
					// macAddress
					$locportsubtype = 'l2address';
					break;
				case 1:
					// interfacealias
				case 5:
					// interfacename
				default:
					$locportsubtype = 'name';
					break;
			}

			$loc_ports = fetchPortList ("Port.object_id = ? AND Port.$locportsubtype = ?", array ($object_id, $rem['locportid']));

			$loc_port_info = $object['name']."[${rem['locportid']}]";

			if (count ($loc_ports) == 0)
			{
				showWarning("Local port $loc_port_info not found!!");
				$ret[$key]['descr'] = 'local port missing';
				continue;
			}

			if (count ($loc_ports) > 1)
			{
				showWarning("More than one Local Port $loc_port_info found");
				$ret[$key]['descr'] = 'multiple local ports';
				continue;
			}

			if (!arePortsCompatible ($loc_ports[0], $rem_ports[0]))
			{
				showWarning("Ports $loc_port_info $rem_port_info incompatible");
				$ret[$key]['descr'] = 'incompatible';
				continue;
			}

			$result = usePreparedSelectBlade
			(
				'SELECT COUNT(*) FROM Link WHERE porta IN (?,?) AND portb IN (?,?)',
				array ($loc_ports[0]['id'], $rem_ports[0]['id'], $loc_ports[0]['id'], $rem_ports[0]['id'])
			);
			if ($result->fetchColumn () != 0)
			{
				showWarning("Ports $loc_port_info $rem_port_info already linked");
				$ret[$key]['descr'] = 'exists';
				continue;
			}
			else
			{
				$result = usePreparedSelectBlade
				(
					'SELECT * FROM Link WHERE porta IN (?,?) OR portb IN (?,?)',
					array ($loc_ports[0]['id'], $rem_ports[0]['id'], $loc_ports[0]['id'], $rem_ports[0]['id'])
				);
				$links = $result->fetchAll (PDO::FETCH_ASSOC);
				if ( count ($links) != 0)
				{

					foreach ($links as $link)
					{
						switch (TRUE)
						{
							case $link['porta'] == $loc_ports[0]['id']:
							case $link['portb'] == $loc_ports[0]['id']:
								$linked = 'loc';
								break;
							case $link['porta'] == $rem_ports[0]['id']:
							case $link['portb'] == $rem_ports[0]['id']:
								$linked = 'rem';
								break;
						}

						showWarning ($linked.' Port '.${$linked.'_port_info'}.' is already linked to a different port');
					}

					$ret[$key]['descr'] = 'different link';
					continue;
				}
			}

			$ret[$key]['link'] = '<input type=checkbox name='.$loc_ports[0]['id'].' value='.$rem_ports[0]['id'].'>';
			$ret[$key]['descr'] = 'linkable';

		}

	}

	$columns = array (
			array ('row_key' => 'locportid', 'th_text' => 'Port'),
			array ('row_key' => 'chassisid', 'th_text' => 'Remote Chassis ID'),
			array ('row_key' => 'remote_name', 'th_text' => 'Remote Object Name'),
			array ('row_key' => 'portid', 'th_text' => 'Remote Port Name'),
			array ('row_key' => 'sysname', 'th_text' => 'Remote LLDP System'),
			array ('row_key' => 'manaddr', 'th_text' => 'ManAddr'),
			array ('row_key' => 'link', 'th_text' => 'Link', 'td_escape' => FALSE),
			array ('row_key' => 'descr')
		);

	$ret[] = array (
			'locportid' => NULL,
			'chassisid' => NULL,
			'remote_name' => NULL,
			'portid' => NULL,
			'sysname' => NULL,
			'manaddr' => NULL,
			'link' => '<input type=submit value=Link>',
			'descr' => NULL
		);

	startPortlet ("Remote LLDP Data");
	echo '<form method=post action='.makeHrefProcess (array ('page' => 'object', 'tab' => 'snmplldp', 'op' => 'set')).'>';
	renderTableViewer ($columns, $ret);
	echo '</form>';
	finishPortlet ();


}
/* -------------------------------------------------- */
function lldp_getsnmp(&$object, $debug = false)
{
	$object_id = $object['id'];
	$object_name = $object['name'];

	if(isset($object['SNMP']))
	{
		if($debug)
			echo "INFO: No SNMP Object \"$object_name\" ID: $object_id<br>";
		return null;
	}

	if(!considerConfiguredConstraint($object, 'IPV4OBJ_LISTSRC'))
	{
		if($debug)
			echo "INFO: No IPv4 Object \"$object_name\" ID: $object_id<br>";

		return False;
	}

	/* get object saved SNMP settings */
	$snmpconfig = explode(':', strtok($object['comment'],"\n\r"));

	if($snmpconfig[0] != "SNMP")
	{

		if($debug)
			echo "INFO: No saved SNMP Settings for \"$object_name\" ID: $object_id<br>";

		return False;
	}

	/* set objects SNMP ip address */
	$ipv4 = $snmpconfig[1];

	if(!$ipv4)
	{
		echo "ERROR: no ip for \"$object_name!!\"<br>";

		return False;
	}

	$object['SNMP']['IP'] = $ipv4;

	if(count($snmpconfig) < 4 )
	{
		echo "SNMP Error: Missing Setting for $object_name ($ipv4)";

		return False;
	}

	/* SNMP prerequisites successfull */

	$s = 	new sl_lldpsnmp($snmpconfig[2], $ipv4, $snmpconfig[3], $snmpconfig);

	if(!$s->error)
	{

		/* get snmp data */
		$lldp = $s->getlldp($object_id);

		if($debug && $s->error)
			echo $s->getError();

		if($lldp)
			return $lldp;
		else
		{

			echo "SNMP Error: ".$s->getError()." for $object_name ($ipv4)<br>";
			return False;
		}

	}
	else
	{
		echo "SNMP Config Error: ".$s->error." for \"$object_name\"<br>";
		return False;
	}

	return null;

} // sl_getsnmp
/* ------------------ */

class sl_lldpsnmp extends SNMP
{

	public $error = false;

	function __construct($version, $hostname, $community, $security = null)
	{

		$hostname = str_replace('#', ':', $hostname);

		switch($version)
		{
			case "1":
			case "v1":
					$version = parent::VERSION_1;
					break;
			case "2":
			case "2c":
			case "v2c":
					$version = parent::VERSION_2c;
					break;
			case "3":
			case "v3":
					$version = parent::VERSION_3;
					break;
		}

		parent::__construct($version, $hostname, $community);

		if($version == SNMP::VERSION_3)
		{
			if($security !== null && count($security) == 9)
			{
				$auth_passphrase = base64_decode($security[6]);
				$priv_passphrase = base64_decode($security[8]);

				if(!$this->setsecurity($security[4], $security[5], $auth_passphrase, $security[7], $priv_passphrase))
				{

					$this->error = "Security Error for v3 ($hostname)";
					return;
				}

			}
			else
			{
				$this->error = "Missing security settings for v3 ($hostname)";
				return;
			}
		}

		$this->quick_print = 1;
		$this->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
	}

	/* lldp */
	function getlldp($object_id)
	{
		function strnormalize($str, $type = NULL, $subtype = NULL)
		{
			switch(TRUE)
			{
				case $type == 'chassis' && $subtype == 4:
				case $type == 'port' && $subtype == 3:
					return str_replace('"', '', str_replace(' ', '', $str));
					break;
				default:
					return preg_replace('/^"|"$/', '', $str);
					break;
			}
		}

		$LldpChassisIdSubtype = array(
				1 => 'chassisComponent',
				2 => 'interfaceAlias',
				3 => 'portComponent',
				4 => 'macAddress',
				5 => 'networkAddress',
				6 => 'interfaceName',
				7 => 'local'
				);

		$LldpPortIdSubtype = array(
				1 => 'interfaceAlias',
				2 => 'portComponent',
				3 => 'macAddress',
				4 => 'networkAddress',
				5 => 'interfaceName',
				6 => 'agentCircuitId',
				7 => 'local'
				);

		//$oid_lldpmib =		'.1.0.8802.1.1.2'; //
		$oid_lldpStatsRemTablesLastChangeTime =	'.1.0.8802.1.1.2.1.2.1.0';
		$oid_lldplocchassisidsubtype =	'.1.0.8802.1.1.2.1.3.1.0';
		$oid_lldplocchassisid =		'1.0.8802.1.1.2.1.3.2.0';
		$oid_lldplocsysname =		'.1.0.8802.1.1.2.1.3.3.0';
		$oid_lldplocsysdesc =		'.1.0.8802.1.1.2.1.3.4.0';
		$oid_lldplocporttable =		'.1.0.8802.1.1.2.1.3.7';
		$oid_lldplocmanaddrtable =	'.1.0.8802.1.1.2.1.3.8';

		//$oid_lldpremtable =		'.1.0.8802.1.1.2.1.4.1'; // !! uses TimeFilter
		$oid_lldpremchassisidsubtype =	'.1.0.8802.1.1.2.1.4.1.1.4';
		$oid_lldpremchassisid =		'.1.0.8802.1.1.2.1.4.1.1.5';
		$oid_lldpremportidsubtype =	'.1.0.8802.1.1.2.1.4.1.1.6';
		$oid_lldpremportid =		'.1.0.8802.1.1.2.1.4.1.1.7';
		$oid_lldpremportdesc =		'.1.0.8802.1.1.2.1.4.1.1.8';
		$oid_lldpremsysname =		'.1.0.8802.1.1.2.1.4.1.1.9';
		$oid_lldpremsysdesc =		'.1.0.8802.1.1.2.1.4.1.1.10';
		//$oid_lldpremaddrtable =	'.1.0.8802.1.1.2.1.4.2'; // !! uses TimeFilter
		$oid_lldpremmanaddrifsubtype=	'.1.0.8802.1.1.2.1.4.2.1.3';

		// @ supprress warning
		$lldplocchassis = @$this->get (array (
							$oid_lldpStatsRemTablesLastChangeTime,
							$oid_lldplocchassisidsubtype,
							$oid_lldplocchassisid,
							$oid_lldplocsysname,
							$oid_lldplocsysdesc
					), TRUE);

		if($lldplocchassis === false)
		{
			$this->error = true;
			return;
		}

		$lldplocmanaddrtable = $this->walk ($oid_lldplocmanaddrtable, FALSE);
		$locmanaddr = preg_replace ('/.*?((\d+\.){3}\d+)$/', '$1', key ($lldplocmanaddrtable));

		// clear LLDP cache for object
		usePreparedDeleteBlade('LLDPCache', array ('object_id' => $object_id, 'src' => 'SNMP'));

		$lldplocporttable = $this->walk($oid_lldplocporttable, TRUE);

		$locchassisidsubtype = $lldplocchassis[$oid_lldplocchassisidsubtype];

		// add to LLDPCache
		usePreparedInsertBlade('LLDPCache', array(
						'object_id' => $object_id,
						'src' => 'SNMP',
						'location' => 'loc',
						'chassisidsubtype' => $locchassisidsubtype,
						'chassisid' => strnormalize($lldplocchassis[$oid_lldplocchassisid], 'chassis', $locchassisidsubtype),
						'sysname' => strnormalize($lldplocchassis[$oid_lldplocsysname]),
						'sysdesc' => strnormalize($lldplocchassis[$oid_lldplocsysdesc]),
						'manaddr' => $locmanaddr,
						)
		);

		$timemark = $lldplocchassis[$oid_lldpStatsRemTablesLastChangeTime];

		$lldpremchassisidsubtype = $this->walk("$oid_lldpremchassisidsubtype.$timemark", TRUE); // TimeFilter
		$lldpremchassisid = $this->walk("$oid_lldpremchassisid.$timemark", TRUE);
		$lldpremportidsubtype = $this->walk("$oid_lldpremportidsubtype.$timemark", TRUE);
		$lldpremportid = $this->walk("$oid_lldpremportid.$timemark", TRUE);
		$lldpremportdesc = $this->walk("$oid_lldpremportdesc.$timemark", TRUE);
		$lldpremsysname = $this->walk("$oid_lldpremsysname.$timemark", TRUE);
		$lldpremsysdesc = $this->walk("$oid_lldpremsysdesc.$timemark", TRUE);

		$lldpremmanaddrifsubtype = $this->walk ($oid_lldpremmanaddrifsubtype, FALSE);
		$lldpremmanaddrs = array();
		foreach ($lldpremmanaddrifsubtype as $key => $subtype)
		{
			preg_match('/\.(\d+\.\d+)\.\d+\.\d+\.((?:\d+\.){3}\d+)$/', $key, $matches);
			$lldpremmanaddrs[$matches[1]] = $matches[2];
		}

		foreach ($lldpremchassisidsubtype as $key => $subtype)
		{

			$localportnum = preg_replace('/(\d+)\.\d+$/', '$1', $key);
			$chassisid = strnormalize($lldpremchassisid[$key], 'chassis', $subtype);

			if (empty ($chassisid))
				continue;

			$remmanaddr = isset ($lldpremmanaddrs[$key]) ? $lldpremmanaddrs[$key] : '';

			usePreparedInsertBlade('LLDPCache', array(
						'object_id' => $object_id,
						'src' => 'SNMP',
						'location' => 'rem',
						'chassisidsubtype' => $subtype,
						'chassisid' => $chassisid,
						'portidsubtype' => $lldpremportidsubtype[$key],
						'portid' => strnormalize($lldpremportid[$key], 'port', $lldpremportidsubtype[$key]),
						'portdesc' => (isset($lldpremportdesc[$key]) ? strnormalize ($lldpremportdesc[$key]) : ''),
						'sysname' => (isset($lldpremsysname[$key]) ? strnormalize ($lldpremsysname[$key]) : ''),
						'sysdesc' => (isset($lldpremsysdesc[$key]) ? strnormalize ($lldpremsysdesc[$key]) : ''),
						'locportidsubtype' => $lldplocporttable["1.2.$localportnum"],
						'locportid' => strnormalize($lldplocporttable["1.3.$localportnum"], 'port', $lldplocporttable["1.2.$localportnum"]),
						'manaddr' => $remmanaddr
						)
			);
		}

		return TRUE;
	}

} // sl_lldpsnmp

