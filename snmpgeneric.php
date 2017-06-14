<?php
/********************************************
 *
 * RackTables 0.20.x snmpgeneric extension v0.1
 *
 * needs PHP 5 >= 5.4.0 (SNMP Class)
 *
 *
 *	sync an RackTables object with an SNMP device.
 *
 *	Should work with almost any SNMP capable device.
 *
 *	reads SNMP tables:
 *		- system
 *		- ifTable
 *		- ifxTable
 *		- ipAddrTable (ipv4 only)
 *		- ipAddressTable (ipv4 + ipv6)
 *		- ipv6AddrAddress (ipv6)
 *
 *	Features:
 *		- update object attributes
 *		- create networks
 *		- create ports
 *		- add and bind ip addresses
 *		- create new objects
 *		- save snmp settings per object (uses comment field)
 *		- scriptable (e.g. crontab)
 *
 *	Known to work with:
 *		- Enterasys SecureStacks, S-Series
 *		- cisco 2620XM (thx to Rob)
 *		- hopefully many others
 *
 *
 *	Usage:
 *
 *		1. select "SNMP generic sync" tap
 *		2. select your SNMP config (host, v1, v2c or v3, ...)
 *		3. hit "Show List"
 *		4. you will see a selection of all information that could be retrieved
 *		5. select what should be updated and/or created
 *		6. hit "Create" Button to make changes to RackTables
 *		7. repeat step 1. to 6. as often as you like / need
 *
 *
 * TESTED on FreeBSD 10.3, nginx/1.8.0, php 7, NET-SNMP 5.7.3
 *	and RackTables 0.20.12
 *
 * (c)2017 Maik Ehinger <m.ehinger@ltur.de>
 */

/****
 * INSTALL
 * 	just place file in plugins directory
 *
 *	Increase max_input_vars in php.ini if not all ports were added on one run.
 *
 */

/***************************
 * Change Log
 *
 *	v0.1	restructure plugin
 *		scriptable
 *		use PHP SNMP Class
 *		use more RackTables functions
 *
 ***************************/

/**
 * The newest version of this plugin can be found at:
 *
 * https://github.com/github138/myRT-contribs/tree/develop-0.20.x
 *
 */

/* TODOs
 *
 *  - code cleanup
 *
 *  - test if device supports mibs
 *  - gethostbyaddr / gethostbyname host list
 *  - correct iif_name display if != 1
 *
 *  - set more Object attributs / fields
 *
 *  - Input variables exceeded 1000
 *
 */

/* RackTables Debug Mode */
//$debug_mode=1;

$rt_base_path = dirname(get_included_files()[0]).'/';
if (php_sapi_name () == 'cli')
{
	$script_mode = true;

	session_start ();
        session_write_close ();

	$rt_base_path = '../wwwroot/'; # plugins directory
	if ( !file_exists ($rt_base_path.'inc/init.php'))
	{
		$rt_base_path = ''; # wwwroot directory

		if ( !file_exists ($rt_base_path.'inc/init.php'))
			echo "Racktables includes could not be found. Please run from Racktables wwwroot or plugins directory.\n";
			echo "Try set $rt_base_path!\n";
	}

	require_once ($rt_base_path.'inc/init.php');
}

require_once ($rt_base_path.'inc/snmp.php');

$tab['object']['snmpgeneric'] = 'SNMP Generic sync';
$tabhandler['object']['snmpgeneric'] = 'snmpgeneric_tabhandler';
$trigger['object']['snmpgeneric'] = 'snmpgeneric_tabtrigger';

$ophandler['object']['snmpgeneric']['create'] = 'snmpgeneric_opcreate';

/* snmptranslate command */
$sg_cmd_snmptranslate = '/usr/local/bin/snmptranslate';

/* create ports without connector */
$sg_create_noconnector_ports = FALSE;

/* deselect add port for this snmp port types */
$sg_ifType_ignore = array (
	  '1',	/* other */
	 '24',	/* softwareLoopback */
	 '23',	/* ppp */
	 '33',	/* rs232 */
	 '34',	/* para */
	 '53',	/* propVirtual */
	 '77',	/* lapd */
	'131',	/* tunnel */
	'136',	/* l3ipvlan */
	'160',	/* usb */
	'161',	/* ieee8023adLag */
);

/* ifType to RT oif_id mapping */
$sg_ifType2oif_id = array (
	/* 440 causes SQLSTATE[23000]: Integrity constraint violation:
	 *				1452 Cannot add or update a child row:
	 *					a foreign key constraint fails
	 */
	//  '1' => 440,	/* other => unknown 440 */
	  '1' => 1469,	/* other => virutal port 1469 */
	  '6' => 24,	/* ethernetCsmacd => 1000BASE-T 24 */
	 '24' => 1469,	/* softwareLoopback => virtual port 1469 */
	 '33' => 1469,	/*  rs232 => RS-232 (DB-9) 681 */
	 '34' => 1469,	/* para => virtual port 1469 */
	 '53' => 1469,	/* propVirtual => virtual port 1469 */
	 '62' => 19,	/* fastEther => 100BASE-TX 19 */
	'131' => 1469,	/* tunnel => virtual port 1469 */
	'136' => 1469,	/* l3ipvlan => virtual port 1469 */
	'160' => 1469,	/* usb => virtual port 1469 */
	'161' => 1469,	/* ieee8023adLag => virtual port 1469 */
);

/* -------------------------------------------------- */

/* snmp vendor list http://www.iana.org/assignments/enterprise-numbers */

$sg_known_sysObjectIDs = array
(
	/* ------------ default ------------ */
	'default' => array
	(
	//	'text' => 'default',
		'pf' => array ('snmpgeneric_pf_entitymib'),
		'attr' => array
		(
			 2 => array ('pf' => 'snmpgeneric_pf_hwtype'),					/* HW Typ*/
			 3 => array ('oid' => 'sysName.0'),
				/* FQDN check only if regex matches */
			 //3 => array ('oid' => 'sysName.0', 'regex' => '/^[^ .]+(\.[^ .]+)+\.?/', 'uncheck' => 'no FQDN'),
			 4 => array ('pf' => 'snmpgeneric_pf_swtype', 'uncheck' => 'experimental'),	/* SW type */
			 14 => array ('oid' => 'sysContact.0'),						/* Contact person */
			// 1235 => array ('value' => 'Constant'),
		),
		'port' => array
		(
			// 'AC-in' => array ('porttypeid' => '1-16', 'uncheck' => 'uncheck reason/comment'),
			// 'name' => array ('porttypeid' => '1-24', 'ifDescr' => 'visible label'),
		),
	),

	/* ------------ ciscoSystems --------------- */
/*	'9' => array
 *	(
 *		'text' => 'ciscoSystems',
 *	),
 */
	'9.1' => array
	(
		'text' => 'ciscoProducts',
		'attr' => array (
				4 => array ('pf' => 'snmpgeneric_pf_catalyst'), /* SW type/version */
				16 => array ('pf' => 'snmpgeneric_pf_ciscoflash'), /*  flash memory */

				),

	),
	/* ------------ Microsoft --------------- */
	'311' => array
	(
		'text' => 'Microsoft',
		'attr' => array (
				4 => array ('pf' => 'snmpgeneric_pf_swtype', 'oid' => 'sysDescr.0', 'regex' => '/.* Windows Version (.*?) .*/', 'replacement' => 'Windows \\1', 'uncheck' => 'TODO RT matching'), /*SW type */
				),
	),
	/* ------------ Enterasys --------------- */
	'5624' => array
	(
		'text' => 'Enterasys',
		'attr' => array (
				4 => array ('pf' => 'snmpgeneric_pf_enterasys'), /* SW type/version */
				),
	),

	/* Enterasys N3 */
	'5624.2.1.53' => array
	(
		'dict_key' => 2021,
		'text' => 'Matrix N3',
	),

	'5624.2.2.284' => array
	(
		'dict_key' => 50002,
		'text' => 'Securestack C2',
	),

	'5624.2.1.98' => array
	(
		'dict_key' => 50002,
		'text' => 'Securestack C3',
	),

	'5624.2.1.100' => array
	(
		'dict_key' => 50002,
		'text' => 'Securestack B3',
	),

	'5624.2.1.128' => array
	(
		'dict_key' => 1970,
		'text' => 'S-series SSA130',
	),

	'5624.2.1.129' => array
	(
		'dict_key' => 1970,
		'text' => 'S-series SSA150',
	),

	'5624.2.1.137' => array
	(
		'dict_key' => 1987,
		'text' => 'Securestack B5 POE',
	),

	/* S3 */
	'5624.2.1.131' => array
	(
		'dict_key' => 1974,
		'text' => 'S-series S3',
	),

	/* S4 */
	'5624.2.1.132' => array
	(
		'dict_key' => 1975,
		'text' => 'S-series S4'
	),

	/* S8 */
	'5624.2.1.133' => array
	(
		'dict_key' => 1977,
		'text' => 'S-series S8'
	),

	'5624.2.1.165' => array
	(
		'dict_key' => 1971,
		'text' => 'S-series Bonded SSA',
	),

	/* ------------ net-snmp --------------- */
	'8072' => array
	(
		'text' => 'net-snmp',
		'attr' => array(
				4 => array ('pf' => 'snmpgeneric_pf_swtype', 'oid' => 'sysDescr.0', 'regex' => '/(.*?) .*? (.*?) .*/', 'replacement' => '\\1 \\2', 'uncheck' => 'TODO RT matching'), /*SW type */
				),
	),

	/* ------------ Frauenhofer FOKUS ------------ */
	'12325' => array
	(
		'text' => 'Fraunhofer FOKUS',
		'attr' => array (
				4 => array ('pf' => 'snmpgeneric_pf_swtype', 'oid' => 'sysDescr.0', 'regex' => '/.*? .*? (.*? .*).*/', 'replacement' => '\\1', 'uncheck' => 'TODO RT matching'), /*SW type */
				),
	),

	'12325.1.1.2.1.1' => array
	(
		'dict_key' => 42, /* Server model noname/unknown */
		'text'	=> 'BSNMP - mini SNMP daemon (bsnmpd)',
	),

) + $known_switches;
/* add snmp.php known_switches */

/* ------------ Sample function --------------- */
/*
 * Sample Precessing Function (pf)
 */
function snmpgeneric_pf_sample (&$snmp, &$sysObjectID, $attr_id)
{

	$object = &$sysObjectID['object'];
	$attr = &$sysObjectID['attr'][$attr_id];

	if (!isset ($attr['oid']))
		return;

	/* output success banner */
	showSuccess ('Found sysObjectID '.$sysObjectID['value']);

	/* access attribute oid setting and do snmpget */
	$oid = $attr['oid'];
	$value = $snmp->get ($oid);

	/* set new attribute value */
	$attr['value'] = $value;

	/* do not check attribute per default */
	$attr['uncheck'] = "comment";

	/* set informal comment */
	$attr['comment'] = "comment";

	/* add additional ports */
 //	$sysObjectID['port']['name'] = array ('porttypeid' => '1-24', 'ifPhysAddress' => '001122334455', 'ifDescr' => 'visible label', 'uncheck' => 'comment', 'disabled' => 'porttypeid select disabled');

	/* set other attribute */
//	$sysObjectID['attr'][1234]['value'] = 'attribute value';

} /* snmpgeneric_pf_sample */

/* ------------ Enterasys --------------- */

function snmpgeneric_pf_enterasys (&$snmp, &$sysObjectID, $attr_id)
{

		$attrs = &$sysObjectID['attr'];

		//snmpgeneric_pf_entitymib ($snmp, $sysObjectID, $attr_id);

		/* TODO find correct way to get Bootroom and Firmware versions */

		/* Model */
		/*if (preg_match ('/.*\.([^.]+)$/', $sysObjectID['value'], $matches))
		 *{
		 *	showNotice ('Device '.$matches[1]);
		 *}
		 */

		/* TODO SW type */
		//$attrs[4]['value'] = 'Enterasys'; /* SW type */
		$attrs[4]['key'] = '0'; /* SW type dict key 0 = NOT SET*/

		/* set SW version only if not already set by entitymib */
		if (isset ($attrs[5]['value']) && !empty ($attrs[5]['value']))
		{

			/* SW version from sysDescr */
			if (preg_match ('/^Enterasys .* Inc\. (.+) [Rr]ev ([^ ]+) ?(.*)$/', $snmp->get ('sysDescr.0'), $matches))
			{

				$attrs[5]['value'] = $matches[2]; /* SW version */

			//	showSuccess ("Found Enterasys Model ".$matches[1]);
			}

		} /* SW version */

		/* add serial port */
		//$sysObjectID['port']['console'] = array ('porttypeid' => '1-29',  'ifDescr' => 'console', 'disabled' => 'disabled');

}

/* ------------ Cisco --------------- */

/* logic from snmp.php */
function snmpgeneric_pf_catalyst (&$snmp, &$sysObjectID, $attr_id)
{
		$attrs = &$sysObjectID['attr'];
		$ports = &$sysObjectID['port'];

		/* sysDescr multiline on C5200 */
                if (preg_match ('/.*, Version ([^ ]+), .*/', $snmp->sysDescr, $matches))
		{
			$exact_release = $matches[1];
		$major_line = preg_replace ('/^([[:digit:]]+\.[[:digit:]]+)[^[:digit:]].*/', '\\1', $exact_release);

	                $ios_codes = array
		(
				'12.0' => 244,
				'12.1' => 251,
				'12.2' => 252,
		);

			$attrs[5]['value'] = $exact_release;

			if (array_key_exists ($major_line, $ios_codes))
			{
				$attrs[4]['value'] = $ios_codes[$major_line];
				$attrs[4]['key'] = $ios_codes[$major_line];
			}

		} /* sw type / version */

                $sysChassi = $snmp->get ('1.3.6.1.4.1.9.3.6.3.0');
                if ($sysChassi !== FALSE or $sysChassi !== NULL)
			$attrs[1]['value'] = str_replace ('"', '', $sysChassi);

		$ports['con0'] = array ('porttypeid' => '1-29',  'ifDescr' => 'console'); // RJ-45 RS-232 console

		if (preg_match ('/Cisco IOS Software, C2600/', $snmp->sysDescr))
			$ports['aux0'] = array ('porttypeid' => '1-29', 'ifDescr' => 'auxillary'); // RJ-45 RS-232 aux port

                // blade devices are powered through internal circuitry of chassis
                if ($sysObjectID['value'] != '9.1.749' and $sysObjectID['value'] != '9.1.920')
		{
			$ports['AC-in'] = array ('porttypeid' => '1-16');
		}

} /* snmpgeneric_pf_catalyst */

/* -------------------------------------------------- */
function snmpgeneric_pf_ciscoflash (&$snmp, &$sysObjectID, $attr_id)
{
	/*
	 * ciscoflashMIB = 1.3.6.1.4.1.9.9.10
	 */
	/*
		|   16 | uint   | flash memory, MB            |
	*/
	$attrs = &$sysObjectID['attr'];

	$ciscoflash = $snmp->walk ('1.3.6.1.4.1.9.9.10.1.1.2'); /* ciscoFlashDeviceTable */

	if (!$ciscoflash)
		return;

	$flash = array_keys ($ciscoflash, 'flash');

	foreach ($flash as $oid)
	{
		if (!preg_match ('/(.*)?\.[^\.]+\.([^\.]+)$/',$oid,$matches))
			continue;

		$index = $matches[2];
		$prefix = $matches[1];

		showSuccess ("Found Flash: ".$ciscoflash[$prefix.'.8.'.$index]." ".$ciscoflash[$prefix.'.2.'.$index]." bytes");

		$attrs[16]['value'] = ceil ($ciscoflash[$prefix.'.2.'.$index] / 1024 / 1024); /* ciscoFlashDeviceSize */

	}

	/*
	 * ciscoMemoryPoolMIB = 1.3.6.1.4.1.9.9.48
	 *		ciscoMemoryPoolUsed .1.1.1.5
	 *		ciscoMemoryPoolFree .1.1.1.6
	 */

	$ciscomem = $snmp->walk ('1.3.6.1.4.1.9.9.48');

	if (!empty ($ciscomem))
	{

		$used = 0;
		$free = 0;

		foreach ($ciscomem as $oid => $value)
		{

			switch (preg_replace ('/.*?(\.1\.1\.1\.[^\.]+)\.[^\.]+$/','\\1',$oid))
			{
				case '.1.1.1.5':
					$used += $value;
					break;
				case '.1.1.1.6':
					$free += $value;
					break;
			}

		}

		$attrs[17]['value'] = ceil (($free + $used) / 1024 / 1024); /* RAM, MB */
	}

} /* snmpgeneric_pf_ciscoflash */

/* -------------------------------------------------- */
/* -------------------------------------------------- */

/* HW Type processor function */
function snmpgeneric_pf_hwtype (&$snmp, &$sysObjectID, $attr_id)
{

	$attr = &$sysObjectID['attr'][$attr_id];

	if (isset ($sysObjectID['dict_key']))
	{

		$value = $sysObjectID['dict_key'];
		showSuccess ("Found HW type dict_key: $value");

		/* return array of attr_id => attr_value) */
		$attr['value'] = $value;
		$attr['key'] = $value;

	}
	else
	{
		showNotice ("HW type dict_key not set - Unknown OID");
	}

} /* snmpgeneric_pf_hwtype */

/* -------------------------------------------------- */

/* SW type processor function */
/* experimental */
/* Find a way to match RT SW types !? */
function snmpgeneric_pf_swtype (&$snmp, &$sysObjectID, $attr_id)
{

	/* 4 = SW type */

	$attr = &$sysObjectID['attr'][$attr_id];

	$object = &$sysObjectID['object'];

	$objtype_id = $object['objtype_id'];

	if (isset ($attr['oid']))
		$oid = $attr['oid'];
	else
		$oid = 'sysDescr.0';

	$raw_value = $snmp->get ($oid);

	$replacement = '\\1';

	if (isset ($attr['regex']))
	{
		$regex = $attr['regex'];

		if (isset ($attr['replacement']))
			$replacement = $attr['replacement'];

	}
	else
	{
		$list = array ('bsd','linux','centos','suse','fedora','ubuntu','windows','solaris','vmware');

		$regex = '/.* ([^ ]*('.implode ($list,'|').')[^ ]*) .*/i';
		$replacement = '\\1';
	}

	$value = preg_replace ($regex, $replacement, $raw_value, -1, $count);
	//$attr['value'] = $value;

	if (!empty ($value) && $count > 0)
	{
		/* search dict_key for value in RT Dictionary */
		/* depends on object type server(13)/switch(14)/router(15) */
		$result = usePreparedSelectBlade
		(
			'SELECT dict_key,dict_value FROM Dictionary WHERE chapter_id in (13,14,15) and dict_value like ? order by dict_key desc limit 1',
			array ('%'.$value.'%')
		);
		$row = $result->fetchAll (PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);

		if (!empty ($row))
		{
			$RTvalue = key ($row);

			if (isset ($attr['comment']))
				$attr['comment'] .= ", $value ($RTvalue) ".$row[$RTvalue];
			else
				$attr['comment'] = "$value ($RTvalue) ".$row[$RTvalue];

			showSuccess ("Found SW type: $value ($RTvalue) ".$row[$RTvalue]);
			$value = $RTvalue;
		}

		/* set attr value */
		$attr['value'] = $value;
		$attr['key'] = $value;
	//	unset ($attr['uncheck']);

	}

	if (isset ($attr['comment']))
		$attr['comment'] .= ' (experimental)';
	else
		$attr['comment'] = '(experimental)';

} /* snmpgeneric_pf_swtype */

/* -------------------------------------------------- */
/* try to set SW version
 * and add some AC ports
 *
 */
/* needs more testing */
function snmpgeneric_pf_entitymib (&$snmp, &$sysObjectID, $attr_id)
{

	global $script_mode;

	/* $attr_id == NULL -> device pf */

	$attrs = &$sysObjectID['attr'];
	$ports = &$sysObjectID['port'];

	$entPhysicalClass = $snmp->walk ('.1.3.6.1.2.1.47.1.1.1.1.5'); /* entPhysicalClass */

	if (empty ($entPhysicalClass))
		return;

	showNotice ("Found Entity Table (Experimental)");

/*		PhysicalClass
 *		1:other
 *		2:unknown
 *		3:chassis
 *		4:backplane
 *		5:container
 *		6:powerSupply
 *		7:fan
 *		8:sensor
 *		9:module
 *		10:port
 *		11:stack
 *		12:cpu
 */

	/* chassis */

	/* always index = 1 ??? */
	$chassis = array_keys ($entPhysicalClass, '3'); /* 3 chassis */

	if (0)
	if (!empty ($chassis))
	{
		echo '<table>';

		foreach ($chassis as $key => $oid)
		{
			/* get index */
			if (!preg_match ('/\.(\d+)$/',$oid, $matches))
				continue;

			$index = $matches[1];

			$name = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.7.$index");
			$serialnum = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.11.$index");
			$mfgname = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.12.$index");
			$modelname = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.13.$index");

			//showNotice ("$name $mfgname $modelname $serialnum");

			echo ("<tr><td>$name</td><td>$mfgname</td><td>$modelname</td><td>$serialnum</td>");
		}
		unset ($key);
		unset ($oid);

		echo '</table>';
	} /* chassis */



	/* modules */

	$modules = array_keys ($entPhysicalClass, '9'); /* 9 Modules */

	if (!empty ($modules))
	{

		$rows = array ();
		foreach ($modules as $key => $oid)
		{

			/* get index */
			if (!preg_match ('/\.(\d+)$/',$oid, $matches))
				continue;

			$index = $matches[1];

			$row['name'] = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.7.$index");

			if (!$row['name'])
				continue;

			$row['hardwarerev'] = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.8.$index");
			$row['firmwarerev'] = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.9.$index");
			$row['softwarerev'] = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.10.$index");
			$row['serialnum'] = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.11.$index");
			$row['mfgname'] = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.12.$index");
			$row['modelname'] = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.13.$index");

			/* set SW version to first module software version */
			if ($key == 0 )
			{

				$attrs[5]['value'] = $row['softwarerev']; /* SW version */
				$attrs[5]['comment'] = 'entity MIB';
			}

			$rows[] = $row;

		}
		unset ($key);
		unset ($oid);


		if (!$script_mode)
		{
			startPortlet ('Modules');

			$columns = array (
				array ('row_key' => 'name', 'th_text' => 'Name'),
				array ('row_key' => 'mfgname', 'th_text' => 'MfgName'),
				array ('row_key' => 'modelname', 'th_text' => 'ModelName'),
				array ('row_key' => 'hardwarerev', 'th_text' => 'HardwareRev'),
				array ('row_key' => 'firmwarerev', 'th_text' => 'FirmwareRev'),
				array ('row_key' => 'softwarerev', 'th_text' => 'SoftwareRev'),
				array ('row_key' => 'serialnum', 'th_text' => 'SerialNum')
			);

			renderTableViewer ($columns, $rows);
			finishPortlet ();
		}
	}


	/* add AC ports */
	$powersupply = array_keys ($entPhysicalClass, '6'); /* 6 powerSupply */
	$count = 1;
	foreach ($powersupply as $oid)
	{

		/* get index */
		if (!preg_match ('/\.(\d+)$/',$oid, $matches))
			continue;

		$index = $matches[1];
		$descr = $snmp->get (".1.3.6.1.2.1.47.1.1.1.1.2.$index");

		$ports['AC-'.$count] = array ('porttypeid' => '1-16', 'ifDescr' => $descr, 'comment' => 'entity MIB', 'uncheck' => '');
		$count++;
	}
	unset ($oid);
}

/* -------------------------------------------------- */

/*
 * regex processor function
 * needs 'oid' and  'regex'
 * uses first back reference as attribute value
 */
function snmpgeneric_pf_regex (&$snmp, &$sysObjectID, $attr_id)
{

	$attr = &$sysObjectID['attr'][$attr_id];

	if (isset ($attr['oid']) && isset ($attr['regex']))
	{

		$oid = $attr['oid'];
		$regex = $attr['regex'];

		$raw_value = $snmp->get ($oid);


		if (isset ($attr['replacement']))
			$replace = $attr['replacement'];
		else
			$replace = '\\1';

		$value = preg_replace ($regex,$replace, $raw_value);

		/* return array of attr_id => attr_value) */
		$attr['value'] = $value;

	}
	// else Warning ??

} /* snmpgeneric_pf_regex */

/* -------------------------------------------------- */

$sg_portiifoptions= getPortIIFOptions ();
$sg_portiifoptions[-1] = 'sfp'; /* generic sfp */

$sg_portoifoptions= getPortOIFOptions ();

/* -------------------------------------------------- */

const SG_BOX_IGNORE	= 0;
const SG_BOX_UNCHECK	= 1;
const SG_BOX_CHECK	= 2;
const SG_BOX_PROBLEM	= 4;

/* -------------------------------------------------- */

/* ------------- CLI -----------------*/

function print_help ()
{
	echo "\n";
	echo "-o object id\n";
	echo "-n dry-run\n";
	echo "-p create ports\n";
	echo "-u update ports same as -lmt\n";
	echo "-l update labels\n";
	echo "-m update macs\n";
	echo "-t update porttypes\n";
	echo "-s add ip spaces\n";
	echo "-i allocate ips\n";
	echo "-a update attributes\n";
	echo "\n";
	echo "--all same as -aisup\n";
	echo "-h Print help\n";
	echo "\n";
}

$sg_args = array ();
$sg_dry_run = FALSE;

function sg_checkArgs ($argname, $msg = NULL, &$count = NULL, $ignore_dry_run = FALSE)
{
	global $sg_args, $script_mode, $sg_dry_run;

	/* always true for HTML */
	if (! $script_mode)
		return TRUE;

	if (isset ($sg_args[$argname]))
	{
			if ($script_mode && $msg)
				echo "$msg\n";

			if ($count !== NULL)
				$count++;
	}

	$ret = isset ($sg_args[$argname]);

	if ($ignore_dry_run)
		return $ret;
	else
		return $ret && !$sg_dry_run;
}

if ($script_mode)
{
	array_shift ($argv);

	$object_id = NULL;

	$arg = array_shift ($argv);

	while ($arg)
	{
		switch ($arg)
		{
			case '--all':
				$arg = 'aislmtp';
			default:
				$args = str_split ($arg);

				foreach ($args as $option)
				{
					switch ($option)
					{
						case '-':
							continue;
							break;
						case 'h':
							print_help ();
							exit;
							break;
						case 'o':
							$object_id = array_shift ($argv);
							break;
						case 'u':
							$sg_args['l'] = TRUE;
							$sg_args['m'] = TRUE;
							$sg_args['t'] = TRUE;
							break;
						case 'n':
							$sg_dry_run = TRUE;
						case 'p':
						case 'l':
						case 'm':
						case 't':
						case 's':
						case 'i':
						case 'a':
							$sg_args[$option] = TRUE;
							break;
						default:
							echo "unknown option \"$option\"\n";
					}
				}
		}

		$arg = array_shift ($argv);
	}

	if (!$object_id || !is_numeric ($object_id))
	{
		echo "Missing Argument Object_id\n";
		exit;
	}

	$object = spotEntity ('object', $object_id);

	echo "found object: \"${object['name']}\" object_id: $object_id\n";

	if (!considerConfiguredConstraint ($object, 'IPV4OBJ_LISTSRC'))
	{
		echo "no IP object\n";
		exit;
	}

	if ($sg_dry_run)
		echo "Running in dry-run mode!\n";

	$snmpconfig = snmpgeneric_getSNMPconfig ($object);

	$snmpdev = NULL;

	$data = snmpgeneric_getsnmp ($snmpconfig, $snmpdev);

	snmpgeneric_process ($data, $object, $snmpdev);

	$count = snmpgeneric_datacreate ($object['id'], $data);

	echo "$count changes\n";

	if ($sg_dry_run)
		echo "Dry-run Mode! No changes made!\n";

	/* return number of changes */
	exit ($count);
} /* script_mode */

/* ----------------------- HTML --------------------------- */

function snmpgeneric_tabhandler ($object_id)
{
	if (isset ($_POST['asnewobject']) && $_POST['asnewobject'] == "1")
	{
		$newobject_name = $_POST['object_name'];
		$newobject_label = $_POST['object_label'];
		$newobject_type_id = $_POST['object_type_id'];
		$newobject_asset_no = $_POST['object_asset_no'];

		if (sg_checkObjectNameUniqueness ($newobject_name, $newobject_type_id))
		{

			$object_id = commitAddObject ($newobject_name, $newobject_label, $newobject_type_id, $newobject_asset_no);

			$_POST['asnewobject'] = "0";

			parse_str ($_SERVER['QUERY_STRING'],$query_string);

			$query_string['object_id'] = $object_id;

			$_SERVER['QUERY_STRING'] = http_build_query ($query_string);

			list ($path, $qs) = explode ('?',$_SERVER['REQUEST_URI'],2);
			$_SERVER['REQUEST_URI'] = $path.'?'.$_SERVER['QUERY_STRING'];


			// switch to new object
			echo '<body>';
			echo '<body onload="document.forms[\'newobject\'].submit();">';

			echo '<form method=POST id=newobject action='.$_SERVER['REQUEST_URI'].'>';

			foreach ($_POST as $name => $value)
			{
				echo "<input type=hidden name=$name value=$value>";
			}

			echo '<input type=submit id="submitbutton" tabindex="1" value="Show List">';
			echo '</from></body>';
			exit;
		}
		else
		{
			showError ("Object with name: \"$newobject_name\" already exists!!!");
			$_POST['snmpconfig'] = "0";
		}
	}

	// save snmp settings
	if (isset ($_POST['save']) && $_POST['save'] == "1")
	{
		// TODO save only on success !!

		$object = spotEntity ('object', $object_id);

		$snmpvalues[0] = 'SNMP';
		$snmpnames = array ('host', 'version', 'community');
		if ($_POST['version'] == "v3")
			$snmpnames = array_merge ($snmpnames, array ('sec_level','auth_protocol','auth_passphrase','priv_protocol','priv_passphrase'));

		foreach ($snmpnames as $key => $value)
		{
			if (isset ($_POST[$value]))
			{
				switch ($value)
				{
					case "host":
						$snmpvalues[$key + 1] = str_replace (':', '#', $_POST[$value]);
						break;
					case "auth_passphrase":
					case "priv_passphrase":
						$snmpvalues[$key + 1] = base64_encode ($_POST[$value]);
						break;

					default: $snmpvalues[$key + 1] = $_POST[$value];
				}
			}
		}

		$newsnmpstr = implode ($snmpvalues,":");

		$snmpstr = strtok ($object['comment'],"\n\r");

		$snmpstrarray = explode (':', $snmpstr);

		$setcomment = "set";
                if ($snmpstrarray[0] == "SNMP")
		{
			if ($newsnmpstr == $snmpstr)
				$setcomment = "ok";
			else
				$setcomment = "update";
		}

		if ($setcomment != "ok")
		{

			if ($setcomment == "update")
				$comment = str_replace ($snmpstr,$newsnmpstr, $object['comment']);
			else
				$comment = "$newsnmpstr\n".$object['comment'];

		//	echo "$snmpnewstr ".$object['comment']." --> $comment";

			commitUpdateObject ($object_id, $object['name'], $object['label'], $object['has_problems'], $object['asset_no'], $comment );
			showNotice ("$setcomment SNMP Settings: $newsnmpstr");

		}

	}

	if (isset ($_POST['snmpconfig']) && $_POST['snmpconfig'] == '1')
	{
		snmpgeneric_list ($object_id);
	}
	else
	{
		snmpgeneric_snmpconfig ($object_id);
	}
} /* snmpgeneric_tabhandler */

/* -------------------------------------------------- */

function snmpgeneric_tabtrigger ()
{
	// display tab only on IPv4 Objects
	return considerConfiguredConstraint (spotEntity ('object', getBypassValue ()), 'IPV4OBJ_LISTSRC') ? 'std' : '';
} /* snmpgeneric_tabtrigger */

/* -------------------------------------------------- */
function snmpgeneric_getSNMPconfig ($object)
{
	$snmpstr = strtok ($object['comment'],"\n\r");
	$snmpstrarray = explode (':', $snmpstr);

	if ($snmpstrarray[0] == "SNMP")
	{
		/* keep it compatible with older version */
		switch ($snmpstrarray[2])
		{
			case "v1":
				$snmpstrarray[2] = 'v1';
				break;
			case "v2":
			case "v2c":
			case "v2C":
				$snmpstrarray[2] = 'v2c';
				break;
			case "v3":
				$snmpstrarray[2] = 'v3';
				break;
		}

		$snmpnames = array ('SNMP','host', 'version', 'community');
		if ($snmpstrarray[2] == "v3")
			$snmpnames = array_merge ($snmpnames, array ('sec_level','auth_protocol','auth_passphrase','priv_protocol','priv_passphrase'));

		$snmpvalues = array ();
		foreach ($snmpnames as $key => $value)
		{
			if (isset ($snmpstrarray[$key]))
			{
				switch ($key)
				{
					case 1:
						$snmpvalues[$value] = str_replace ('#', ':', $snmpstrarray[$key]);
						break;
					case 6:
					case 8:
						$snmpvalues[$value] = base64_decode ($snmpstrarray[$key]);
						break;

					default: $snmpvalues[$value] = $snmpstrarray[$key];
				}
			}
		}

		unset ($snmpvalues['SNMP']);

		return $snmpvalues;
	}
	else
		return array ();
} /* snmpgeneric_getSNMPconfig */


function snmpgeneric_snmpconfig ($object_id)
{

	$object = spotEntity ('object', $object_id);
	//$object['attr'] = getAttrValues ($object_id);
        $endpoints = findAllEndpoints ($object_id, $object['name']);

	addJS ('function showsnmpv3(element)
	{
				var style;
				if(element.value != \'v3\') {
					style = \'none\';
					document.getElementById(\'snmp_community_label\').style.display=\'\';
				} else {
					style = \'\';
					document.getElementById(\'snmp_community_label\').style.display=\'none\';
				}

				var elements = document.getElementsByName(\'snmpv3\');
				for(var i=0;i<elements.length;i++) {
					elements[i].style.display=style;
				}
			};',TRUE);

	addJS ('function shownewobject(element)
	{
				var style;

				if(element.checked) {
					style = \'\';
				} else {
					style = \'none\';
				}

				var elements = document.getElementsByName(\'newobject\');
				for(var i=0;i<elements.length;i++) {
					elements[i].style.display=style;
				}
			};',TRUE);

	addJS ('function checkInput() {
				var host = document.getElementById(\'host\');

				if(host.value == "-1") {
					var newvalue = prompt("Enter Hostname or IP Address","");
					if(newvalue != "") {
						host.options[host.options.length] = new Option(newvalue, newvalue);
						host.value = newvalue;
					}
				}

				if(host.value != "-1" && host.value != "")
					return true;
				else
					return false;
			};',TRUE);

	echo '<body onload="document.getElementById(\'submitbutton\').focus(); showsnmpv3(document.getElementById(\'snmpversion\')); shownewobject(document.getElementById(\'asnewobject\'));">';

	foreach ($endpoints as $key => $value)
	{
		$endpoints[$value] = $value;
		unset ($endpoints[$key]);
	}
	unset ($key);
	unset ($value);

	foreach (getObjectIPv4Allocations ($object_id) as $ip => $value)
	{

		$ip = ip_format ($ip);

		if (!in_array ($ip, $endpoints))
			$endpoints[$ip] = $ip;
	}
	unset ($ip);
	unset ($value);

	foreach (getObjectIPv6Allocations ($object_id) as $value)
	{
		$ip = ip_format (ip_parse ($value['addrinfo']['ip']));

		if (!in_array ($ip, $endpoints))
			$endpoints[$ip] = $ip;
	}
	unset ($value);

	/* ask for ip/host name on submit see js checkInput () */
	$endpoints['-1'] = 'ask me';

	// saved snmp settings
	$snmpconfig = snmpgeneric_getSNMPconfig ($object);

	$snmpconfig += $_POST;

	if (!isset ($snmpconfig['host']))
	{
		$snmpconfig['host'] = -1;

		/* try to find first FQDN or IP */
		foreach ($endpoints as $value)
		{
			if (preg_match ('/^[^ .]+(\.[^ .]+)+\.?/',$value))
			{
				$snmpconfig['host'] = $value;
				break;
			}
		}
		unset ($value);
	}

	if (!isset ($snmpconfig['version']))
		$snmpconfig['version'] = 'v2c';

	if (!isset ($snmpconfig['community']))
		$snmpconfig['community'] = getConfigVar ('DEFAULT_SNMP_COMMUNITY');

	if (empty ($snmpconfig['community']))
		$snmpconfig['community'] = 'public';

	if (!isset ($snmpconfig['sec_level']))
		$snmpconfig['sec_level'] = NULL;

	if (!isset ($snmpconfig['auth_protocol']))
		$snmpconfig['auth_protocol'] = NULL;

	if (!isset ($snmpconfig['auth_passphrase']))
		$snmpconfig['auth_passphrase'] = NULL;

	if (!isset ($snmpconfig['priv_protocol']))
		$snmpconfig['priv_protocol'] = NULL;

	if (!isset ($snmpconfig['priv_passphrase']))
		$snmpconfig['priv_passphrase'] = NULL;

	if (!isset ($snmpconfig['asnewobject']))
		$snmpconfig['asnewobject'] = NULL;

	if (!isset ($snmpconfig['object_type_id']))
		$snmpconfig['object_type_id'] = '8';

	if (!isset ($snmpconfig['object_name']))
		$snmpconfig['object_name'] = NULL;

	if (!isset ($snmpconfig['object_label']))
		$snmpconfig['object_label'] = NULL;

	if (!isset ($snmpconfig['object_asset_no']))
		$snmpconfig['object_asset_no'] = NULL;

	if (!isset ($snmpconfig['save']))
		$snmpconfig['save'] = true;

	echo '<h1 align=center>SNMP Config</h1>';
	echo '<form method=post name="snmpconfig" onsubmit="return checkInput()" action='.$_SERVER['REQUEST_URI'].' />';

        echo '<table cellspacing=0 cellpadding=5 align=center class=widetable>
	<tr><th class=tdright>Host:</th><td>';

	//if ($snmpconfig['asnewobject'] == '1' )
	if ($snmpconfig['host'] != '-1' and !isset ($endpoints[$snmpconfig['host']]))
		$endpoints[$snmpconfig['host']] = $snmpconfig['host'];

	echo getSelect ($endpoints, array ('id' => 'host','name' => 'host'), $snmpconfig['host'], FALSE);

	echo'</td></tr>
	<tr>
                <th class=tdright><label for=snmpversion>Version:</label></th>
                <td class=tdleft>';

	echo getSelect (array ('v1' => 'v1', 'v2c' => 'v2c', 'v3' => 'v3'),
			 array ('name' => 'version', 'id' => 'snmpversion', 'onchange' => 'showsnmpv3(this)'),
			 $snmpconfig['version'], FALSE);

	echo '</td>
        </tr>
        <tr>
                <th id="snmp_community_label" class=tdright><label for=community>Community:</label></th>
                <th name="snmpv3" style="display:none;" class=tdright><label for=community>Security Name:</label></th>
                <td class=tdleft><input type=text name=community value='.$snmpconfig['community'].' ></td>
        </tr>
        <tr name="snmpv3" style="display:none;">
		<th></th>
        </tr>
        <tr name="snmpv3" style="display:none;">
                <th class=tdright><label">Security Level:</label></th>
                <td class=tdleft>';

	echo getSelect (array ('noAuthNoPriv' => 'no Auth and no Priv', 'authNoPriv'=> 'auth without Priv', 'authPriv' => 'auth with Priv'),
			 array ('name' => 'sec_level'),
			 $snmpconfig['sec_level'], FALSE);

	echo '</td></tr>
        <tr name="snmpv3" style="display:none;">
                <th class=tdright><label>Auth Type:</label></th>
                <td class=tdleft>
                <input name=auth_protocol type=radio value=MD5 '.($snmpconfig['auth_protocol'] == 'MD5' ? ' checked="checked"' : '').'/><label>MD5</label>
                <input name=auth_protocol type=radio value=SHA '.($snmpconfig['auth_protocol'] == 'SHA' ? ' checked="checked"' : '').'/><label>SHA</label>
                </td>
        </tr>
        <tr name="snmpv3" style="display:none;">
                <th class=tdright><label>Auth Key:</label></th>
                <td class=tdleft><input type=password id=auth_passphrase name=auth_passphrase value="'.$snmpconfig['auth_passphrase'].'"></td>
        </tr>
        <tr name="snmpv3" style="display:none;">
                <th class=tdright><label>Priv Type:</label></th>
                <td class=tdleft>
                <input name=priv_protocol type=radio value=DES '.($snmpconfig['priv_protocol'] == 'DES' ? ' checked="checked"' : '').'/><label>DES</label>
                <input name=priv_protocol type=radio value=AES '.($snmpconfig['priv_protocol'] == 'AES' ? ' checked="checked"' : '').'/><label>AES</label>
                </td>
        </tr>
        <tr name="snmpv3" style="display:none;">
                <th class=tdright><label>Priv Key</label></th>
                <td class=tdleft><input type=password name=priv_passphrase value="'.$snmpconfig['priv_passphrase'].'"></td>
        </tr>
	</tr>

	<tr>
		<th></th>
		<td class=tdleft>
		<input name=asnewobject id=asnewobject type=checkbox value=1 onchange="shownewobject(this)"'.($snmpconfig['asnewobject'] == '1' ? ' checked="checked"' : '').'>
		<label>Create as new object</label></td>
	</tr>';

//	$newobjectdisplaystyle = ($snmpconfig['asnewobject'] == '1' ? "" : "style=\"display:none;\"");

	echo '<tr name="newobject" style="display:none;">
	<th class=tdright>Type:</th><td class=tdleft>';

	$typelist = withoutLocationTypes (readChapter (CHAP_OBJTYPE, 'o'));
        $typelist = cookOptgroups ($typelist);

	printNiftySelect ($typelist, array ('name' => "object_type_id"), $snmpconfig['object_type_id']);

        echo '</td></tr>

	<tr name="newobject" style="display:none;">
	<th class=tdright>Common name:</th><td class=tdleft><input type=text name=object_name value='.$snmpconfig['object_name'].'></td></tr>
	<tr name="newobject" style="display:none;">
	<th class=tdright>Visible label:</th><td class=tdleft><input type=text name=object_label value='.$snmpconfig['object_label'].'></td></tr>
	<tr name="newobject" style="display:none;">
	<th class=tdright>Asset tag:</th><td class=tdleft><input type=text name=object_asset_no value='.$snmpconfig['object_asset_no'].'></td></tr>

	<tr>
		<th></th>
		<td class=tdleft>
		<input name=save id=save type=checkbox value=1'.($snmpconfig['save'] == '1' ? ' checked="checked"' : '').'>
		<label>Save SNMP settings for object</label></td>
	</tr>
	<td colspan=2>

        <input type=hidden name=snmpconfig value=1>
	<input type=submit id="submitbutton" tabindex="1" value="Show List"></td></tr>

        </table></form>';

} /* snmpgeneric_snmpconfig */

/*---------------------------------------------------------------*/

/* get all needed SNMP data
   return merged port array
*/
function snmpgeneric_getsnmp ($snmpconfig, &$snmpdev)
{
	switch ($snmpconfig['version'])
	{
		case '1':
		case 'v1':
			$version = SNMP::VERSION_1;
			break;
		case '2':
		case 'v2':
		case 'v2C':
		case 'v2c':
			$version = SNMP::VERSION_2c;
			break;
		case '3':
		case 'v3':
			$version = SNMP::VERSION_3;
			break;
	};

	$snmpdev = new SNMP ($version, $snmpconfig['host'], $snmpconfig['community']);

	if ($snmpconfig['version'] == "v3" )
	{
		$snmpdev->setSecurity ($snmpconfig['sec_level'],
					$snmpconfig['auth_protocol'],
					$snmpconfig['auth_passphrase'],
					$snmpconfig['priv_protocol'],
					$snmpconfig['priv_passphrase']
					);
	}

	if($snmpdev->getErrno ())
	{
		showError ($snmpdev->getError ());
		return;
	}

	/* SNMP connect successfull */

	$snmpdev->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
	$snmpdev->valueretrieval = SNMP_VALUE_PLAIN;
	//$snmpdev->valueretrieval = SNMP_VALUE_LIBRARY;
	//$snmpdev->quick_print = 1;

	showSuccess ("SNMP ".$snmpconfig['version']." connect to ${snmpconfig['host']} successfull");

	$snmpdata = array ();

	/* get system data */
	$systemoids = array (
			'sysDescr' =>		'.1.3.6.1.2.1.1.1.0',
			'sysObjectID' =>	'.1.3.6.1.2.1.1.2.0',
			'sysUpTime' =>		'.1.3.6.1.2.1.1.3.0',
			'sysContact' =>		'.1.3.6.1.2.1.1.4.0',
			'sysName' =>		'.1.3.6.1.2.1.1.5.0',
			'sysLocation' =>	'.1.3.6.1.2.1.1.6.0',

			'ifNumber' =>		'.1.3.6.1.2.1.2.1.0'
			);

	$system = $snmpdev->get (array_values ($systemoids));

	foreach ($systemoids as $shortoid => $oid)
	{

		$value = $system[$oid];

		if ($shortoid == 'sysUpTime')
		{
			/* in hundredths of a second */
			$secs = (int)($value / 100);
			$days = (int)($secs / (60 * 60 * 24));
			$secs -= $days * 60 *60 * 24;
			$hours = (int)($secs / (60 * 60));
			$secs -= $hours * 60 * 60;
			$mins = (int)($secs / (60));
			$secs -= $mins * 60;
			$value = "$value ($days $hours:$mins:$secs)";
		}

		$snmpdata['system'][$shortoid]['oid'] = $oid;
		$snmpdata['system'][$shortoid]['shortoid'] = $shortoid;
		$snmpdata['system'][$shortoid]['value'] = $value;

	}
	unset ($shortoid);

	/* snmp iftable */

	$ifoids = array (
		//	'ifEntry' =>		'.1.3.6.1.2.1.2.2.1',
			'ifIndex' =>		'.1.3.6.1.2.1.2.2.1.1',
			'ifDescr' =>		'.1.3.6.1.2.1.2.2.1.2',
			'ifType' =>		'.1.3.6.1.2.1.2.2.1.3',
			'ifSpeed' =>		'.1.3.6.1.2.1.2.2.1.5', // bit
			'ifPhysAddress' =>	'.1.3.6.1.2.1.2.2.1.6',
			'ifOperStatus' =>	'.1.3.6.1.2.1.2.2.1.8',
			'ifInOctets' =>		'.1.3.6.1.2.1.2.2.1.10',
			'ifOutOctets' =>	'.1.3.6.1.2.1.2.2.1.16',

			// ifXTable
		//	'ifXTable' =>		'.1.3.6.1.2.1.31.1.1',
		//	'ifXEntry' =>		'.1.3.6.1.2.1.31.1.1.1',
			'ifName' =>		'.1.3.6.1.2.1.31.1.1.1.1',
			'ifHighSpeed' =>	'.1.3.6.1.2.1.31.1.1.1.15', // Mbit
			'ifConnectorPresent' =>	'.1.3.6.1.2.1.31.1.1.1.17',
			'ifAlias' =>		'.1.3.6.1.2.1.31.1.1.1.18',
			);


	$iftable = array ();

	/* get oids */
	foreach ($ifoids as $shortoid => $oid)
		$iftable[$shortoid] = $snmpdev->walk ($oid, TRUE);

	$snmpdata['ifcount'] = $snmpdata['system']['ifNumber']['value'];

	foreach ($iftable['ifIndex'] as $ifindex)
	{
		$row = array ('ip' => array ());
		foreach ($ifoids as $shortoid => $oid)
		{
			if (isset ($iftable[$shortoid][$ifindex]))
				$value = $iftable[$shortoid][$ifindex];
			else
				$value = NULL;

			switch ($shortoid)
			{
				case 'ifPhysAddress':
					/* format MAC Address */
					if (strlen ($value) == 6 )
					{
						$retval =  unpack ('H12',$value);
						$value = strtoupper ($retval[1]);
					}
					break;
			}
			$row[$shortoid] = $value;
		}

		$snmpdata['ports'][$ifindex] = $row;
	}

	/* END iftable */

	function getNetwork ($ipaddr, $netmask)
	{
		if ($netmask == '0.0.0.0')
			return NULL;

		$ret['maskbits'] = 32-log ((ip2long ($netmask) ^ 0xffffffff)+1,2);
		$ret['net'] = ip2long ($ipaddr) & ip2long ($netmask);
		$ret['bcast'] = $ret['net'] | ( ip2long ($netmask) ^ 0xffffffff);

		$ret['maskbits'] = intval ($ret['maskbits']);
		$ret['net'] = long2ip ($ret['net']);
		$ret['bcast'] = long2ip ($ret['bcast']);

		return $ret;
	}

	$snmpdata['ipspaces'] = array ();

	/* ipAddrTable ipv4 only (deprecated) */
	$ipAdEntIfIndex = $snmpdev->walk ('1.3.6.1.2.1.4.20.1.2', TRUE);
	if (!empty ($ipAdEntIfIndex))
	{
		$ipAdEntNetMask =  $snmpdev->walk ('1.3.6.1.2.1.4.20.1.3', TRUE);;

		foreach ($ipAdEntIfIndex as $ip => $ifindex)
		{
			$net = getNetwork ($ip, $ipAdEntNetMask[$ip]);

			if ($net)
			{
				$net['src'] = 'ipAddrTable';
				$net['addrtype'] = 'ipv4';

				$snmpdata['ports'][$ifindex]['ip'][$ip] = $net;

				$ipspace = $net['net'].'/'.$net['maskbits'];
				$net['prefix'] = $ipspace;
				if (!isset ($snmpdata['ipspaces'][$ipspace]))
					$snmpdata['ipspaces'][$ipspace] = $net;
			}
		}
	}

	/* END ipAddrTable */

	/* ipAddressIfIndex ipv4 and ipv6 */
	/* overwrites ipv4 from ipaddrtable */

	$InetAddressType = array (
		0 => 'unknown',
		1 => 'ipv4',
		2 => 'ipv6',
		3 => 'ipv4z',
		4 => 'ipv6z',
		16 => 'dns'
	);

	$ipAddressIfIndex = $snmpdev->walk ('.1.3.6.1.2.1.4.34.1.3', TRUE); // ipAddressIfIndex

	if (!empty ($ipAddressIfIndex))
	{
		$ipAddressType =  $snmpdev->walk ('.1.3.6.1.2.1.4.34.1.4', TRUE); /* 1 unicast, 2 anycast, 3 braodcast */
		$ipAddressPrefix =  $snmpdev->walk ('.1.3.6.1.2.1.4.34.1.5', TRUE);

		/* ipAddressAddrType.(InetAddressPrefixLength).ipAddressAddr */
		foreach ($ipAddressIfIndex as $oid => $ifindex)
		{
			list ($ipAddressAddrType, $InetAddressPrefixLength, $ipAddressAddr) = explode ('.', $oid, 3);

			$prefixvalue = str_replace (
						".1.3.6.1.2.1.4.32.1.5.$ifindex.$ipAddressAddrType.$InetAddressPrefixLength.",
						'',
						$ipAddressPrefix[$oid]
					);

			$bytes = explode ('.', $prefixvalue);
			$maskbits = array_pop ($bytes);
			$netaddr = implode ('.', $bytes);
			$bcast = NULL;

			$net = array ();
			$net['addrtype'] = $InetAddressType[$ipAddressAddrType];
			switch ($ipAddressAddrType)
			{
				case 1: // ipv4
				case 3: // ipv4z

					/* get broadcast address */
					$intnetmask = (int)(0xffffffff << (32 - $maskbits));
					$intnet = ip2long ($netaddr) & $intnetmask;
					$intbcast = $intnet | ( $intnetmask ^ 0xffffffff);
					$netaddr = long2ip ($intnet);
					$bcast = long2ip ($intbcast);

					break;
				case 2: // ipv6
				case 4: // ipv6z
					break;
			}

			$net['maskbits'] = $maskbits;
			$net['net'] = $netaddr;
			$net['bcast'] = $bcast;
			$net['src'] = 'ipAddressTable';

			$snmpdata['ports'][$ifindex]['ip'][$ipAddressAddr] = $net;

			$ipspace = $net['net'].'/'.$net['maskbits'];
			$net['prefix'] = $ipspace;
			if (!isset ($snmpdata['ipspaces'][$ipspace]) &&  $net['net'] != '0.0.0.0')
				$snmpdata['ipspaces'][$ipspace] = $net;

		}

	} /* ipAddressIfIndex */

	/* END ipAddressIfIndex */

	/* ipv6 MIB  */
	/* overwrites ipv6 from ipaddresstable */
	$ipv6Interfaces = @$snmpdev->get ('.1.3.6.1.2.1.55.1.3.0');

	if ($ipv6Interfaces)
	{
		$ipv6AddrAddress =  $snmpdev->walk ('.1.3.6.1.2.1.55.1.8.1.1');
			if (!empty ($ipv6AddrAddress))
			{
				$ipv6AddrPfxLength = $snmpdev->walk ('.1.3.6.1.2.1.55.1.8.1.2');

				foreach ($ipv6addraddress as $oid => $addr_bin)
				{
					$addr = ip_format ($addr_bin);

					list ($ipAddrAddressType, $ifindex, $tmp) = explode ('.', $oid, 3);

					$maskbits = $ipv6AddrPfxLength[$oid];

					$range = constructIPRange ($addr_bin, $maskbits);

					$net['net'] = ip_format ($range['ip_bin']);
					$net['bcast'] = NULL;
					$net['src'] = 'ipv6AddrTable';
					$net['addrtype'] = 'ipv6'.($ipAddrAddressType == 4 ? 'z' : '');

					$snmpdata['ports'][$ifindex]['ip'][$addr] = $net;

					$ipspace = $net['net'].'/'.$net['maskbits'];
					$net['prefix'] = $ipspace;
					if (!isset ($snmpdata['ipspaces'][$ipspace]) &&  $net['net'] != '0.0.0.0')
						$snmpdata['ipspaces'][$ipspace] = $net;

				}

			}
	}
	/* END ipv6 MIB */

	return $snmpdata;

} /*  snmpgeneric_getsnmp */

/*
 * check ports, ips, assignments, ...
 */
function snmpgeneric_process (&$data, &$object, &$snmpdev)
{
	global $sg_known_sysObjectIDs, $sg_ifType_ignore, $sg_create_noconnector_ports;

	/* need for DeviceBreed */
	$object['attr'] = getAttrValues ($object['id']);

	/* sysObjectID Attributes and Vendor Ports */

	/* get sysObjectID */
	$sysObjectID['raw_value'] = $data['system']['sysObjectID']['value'];

	$sysObjectID['value'] = preg_replace ('/^.*(\.1\.3\.6\.1\.4\.1\.|enterprises\.|joint-iso-ccitt\.)([\.[:digit:]]+)$/', '\\2', $sysObjectID['raw_value']);

	/* array_merge doesn't work with numeric keys !! */
	$sysObjectID['attr'] = array ();
	$sysObjectID['port'] = array ();

	$sysobjid = $sysObjectID['value'];

	/* check for known sysObjectIDs */

	$count = 1;

	while ($count)
	{

		if (isset ($sg_known_sysObjectIDs[$sysobjid]))
		{
			$sysObjectID = $sysObjectID + $sg_known_sysObjectIDs[$sysobjid];

			if (isset ($sg_known_sysObjectIDs[$sysobjid]['attr']))
				$sysObjectID['attr'] = $sysObjectID['attr'] + $sg_known_sysObjectIDs[$sysobjid]['attr'];

			if (isset ($sg_known_sysObjectIDs[$sysobjid]['port']))
				$sysObjectID['port'] = $sysObjectID['port'] + $sg_known_sysObjectIDs[$sysobjid]['port'];

			if (isset ($sg_known_sysObjectIDs[$sysobjid]['text']))
				showSuccess ("found sysObjectID ($sysobjid) ".$sg_known_sysObjectIDs[$sysobjid]['text']);
		}

		$sysobjid = preg_replace ('/\.[[:digit:]]+$/','',$sysobjid, 1, $count);

		/* add default sysobjectid */
		if ($count == 0 && $sysobjid != 'default')
		{
			$sysobjid = 'default';
			$count = 1;
		}
	}

	$sysObjectID['vendor_number'] = $sysobjid;

	/* device pf */
	if (isset ($sysObjectID['pf']))
		foreach ($sysObjectID['pf'] as $function)
		{
			if (function_exists ($function))
			{
				/* call device pf */
				$function ($snmpdev, $sysObjectID, NULL);
			}
			else
			{
				showWarning ("Missing processor function ".$function." for device $sysobjid");
			}
		}


	/* sort attributes maintain numeric keys */
	ksort ($sysObjectID['attr']);

	$updateattr = array ();

	/* needs PHP >= 5 foreach call by reference */
	foreach ($sysObjectID['attr'] as $attr_id => $value)
	{

		$attr = &$sysObjectID['attr'][$attr_id];

		$attr['id'] = $attr_id;

		if (isset ($object['attr'][$attr_id]))
		{
			switch (TRUE)
			{

				case isset ($attr['pf']):
					if (function_exists ($attr['pf']))
					{
						$attr['pf']($snmpdev, $sysObjectID, $attr_id);

					}
					else
					{
						showWarning ("Missing processor function ".$attr['pf']." for attribute $attr_id");
					}

					break;

				case isset ($attr['oid']):
					$attrvalue = $snmpdev->get ($attr['oid']);

					if (isset ($attr['regex']))
					{
						$regex = $attr['regex'];

						if (isset ($attr['replacement']))
						{
							$replacement = $attr['replacement'];
							$attrvalue = preg_replace ($regex, $replacement, $attrvalue);
						}
						else
						{
							if (!preg_match ($regex, $attrvalue))
							{
								if (!isset ($attr['uncheck']))
									$attr['uncheck'] = "regex doesn't match";
							} else
								unset ($attr['uncheck']);
						}
					}

					$attr['value'] = $attrvalue;

					break;

				case isset ($attr['value']):
					break;

				default:
					showError ("Error handling attribute id: $attr_id");

			}

			$attr['name'] = $object['attr'][$attr_id]['name'];

			if (array_key_exists ('key',$object['attr'][$attr_id]))
				$attr['old_key'] = $object['attr'][$attr_id]['key'];

			if (array_key_exists ('value', $object['attr'][$attr_id]))
				$attr['old_value'] = $object['attr'][$attr_id]['value'];

			$comment = array ();

			if (isset ($attr['comment']))
			{
				if (!empty ($attr['comment']))
					$comment[] = $attr['comment'];
			}

			$attr['create'] = SG_BOX_CHECK;

			if (isset ($attr['value']))
			{
				if ($attr['value'] == $object['attr'][$attr_id]['value'])
				{
					$comment[] = 'Current = new value';
					$attr['create'] = SG_BOX_UNCHECK;
				}
			}

			if (!array_key_exists ('value', $attr))
			{
				unset ($sysObjectID['attr'][$attr_id]);
				continue;
			}

			if (isset ($attr['key']) && isset ($object['attr'][$attr_id]['key']))
			{
				if ($attr['key'] == $object['attr'][$attr_id]['key'])
				{
					$comment[] = 'Current = new key';
					$attr['create'] = SG_BOX_UNCHECK;
				}
			}

			$attr['comment'] = implode (', ', $comment);
		}
		else
		{
			showWarning ("Object has no attribute id: $attr_id");
			unset ($sysObjectID['attr'][$attr_id]);
		}
	}
	unset ($attr_id);

	/* sort again in case there where attribs added ,maintain numeric keys */
	ksort ($sysObjectID['attr']);

	$data['sysObjectID'] = $sysObjectID;

	$object['breed'] = sg_detectDeviceBreedByObject ($sysObjectID);

	/* get ports */
	amplifyCell ($object);

	/* set array key to lowercase port name */
	foreach ($object['ports'] as $key => $values)
	{
		$object['ports'][strtolower (shortenIfName ($values['name'], $object['breed']))] = $values;
		unset ($object['ports'][$key]);
	}

	$newporttypeoptions = getNewPortTypeOptions ();

	/* process vendor ports */
	foreach ($data['sysObjectID']['port'] as $name => $port)
	{

		// TODO Update porttype

		$data['sysObjectID']['port'][$name]['ifName'] = $name;

		$data['sysObjectID']['port'][$name]['create'] = SG_BOX_CHECK;

		if (array_key_exists (strtolower ($name),$object['ports']))
			$data['sysObjectID']['port'][$name]['create'] = SG_BOX_PROBLEM;

		$comment = array ();

		if (isset ($port['comment']))
		{
			if (!empty ($port['comment']))
				$comment[] = $port['comment'];
		}

		if (isset ($port['uncheck']))
			$comment[] = $port['uncheck'];

		$data['sysObjectID']['port'][$name]['comment'] = implode ('; ', $comment);
	}
	unset ($name);
	unset ($port);
	// -------------------------------------------------------

	/* ipspaces */
	foreach ($data['ipspaces'] as $key => $net)
	{
		$addrtype = $net['addrtype'];
		$netaddr = $net['net'];
		$maskbits = $net['maskbits'];
		$netid = NULL;
		$linklocal = FALSE;

		/* check for ip space */
		switch ($addrtype)
		{
			case 'ipv4':
			case 'ipv4z':
				if ($maskbits == 32)
					$netid = 'host';
				else
					$netid = getIPv4AddressNetworkId (ip_parse ($netaddr));
				break;
			case 'ipv6':
				if (ip_checkparse ($netaddr) === false)
				{
					/* format ipaddr for ip6_parse */
					$ipaddr =  preg_replace ('/((..):(..))/','\\2\\3',$netaddr);
					$ipaddr =  preg_replace ('/%.*$/','',$ipaddr);
				}
				if (ip_checkparse ($ipaddr) === false)
					continue (2); // 2 because of switch
				$ip6_bin = ip6_parse ($ipaddr);
				$ip6_addr = ip_format ($ip6_bin);
				$netid = getIPv6AddressNetworkId ($ip6_bin);
				$node = constructIPRange ($ip6_bin, $maskbits);
				$netaddr = $node['ip'];
				$linklocal = ($netaddr == 'fe80::');
				break;
			case 'ipv6z':
				/* link local */
				$netid = 'ignore';
				break;
			default:
		}

		if (
			empty ($netid) &&
			$netaddr != '::1' &&
			$netaddr != '127.0.0.1' &&
			$netaddr != '127.0.0.0' &&
			$netaddr != '0.0.0.0' &&
			!$linklocal
		)
		{
			$data['ipspaces'][$key]['create'] = ($maskbits > 0 ? SG_BOX_CHECK : SG_BOX_UNCHECK);
			$data['ipspaces'][$key]['name'] = '';
		}
		else
			unset ($data['ipspaces'][$key]);
	}


	// -------------------------------
	amplifyCell ($object);

	$object['portsbyname'] = array ();
	foreach ($object['ports'] as $port)
		$object['portsbyname'][strtolower (shortenIfName ($port['name'], $object['breed']))] = $port;

	/* ports */
	$data['indexcount'] = count ($data['ports']);

	foreach ($data['ports'] as $key => $port)
	{
		$comment = array ();
		$port_info = NULL;

		$data['ports'][$key]['action'] = SG_BOX_IGNORE;
		$data['ports'][$key]['create'] = SG_BOX_IGNORE; // rename to add ?
		$data['ports'][$key]['labelupdate'] = SG_BOX_IGNORE;
		$data['ports'][$key]['macupdate'] = SG_BOX_IGNORE;
		$data['ports'][$key]['porttypeupdate'] = SG_BOX_IGNORE;
		$data['ports'][$key]['RT_port_id'] = NULL;

		/* check ifName */
		$port['ifName'] = trim ($port['ifName']);
		if (empty ($port['ifName']))
		{
			$data['ports'][$key]['create'] = SG_BOX_UNCHECK;
			$comment[] = "no ifName";

			if ($port['ifAlias'])
			{
				$comment[] = "use ifAlias as ifName";
				$data['ports'][$key]['ifName'] = strtolower (shortenIfName ($port['ifAlias'], $object['breed']));
				$port['ifName'] = $data['ports'][$key]['ifName'];
				$data['ports'][$key]['create'] = SG_BOX_IGNORE;
			}
			else
				if ($port['ifDescr'])
				{
					$comment[] = "use ifDescr as ifName";
					$data['ports'][$key]['ifName'] = strtolower (shortenIfName ($port['ifDescr'], $object['breed']));
					$port['ifName'] = $data['ports'][$key]['ifName'];
					$data['ports'][$key]['create'] = SG_BOX_IGNORE;
				}
		}

		if (!empty ($port['ifName']))
		{
			$data['ports'][$key]['ifName'] = strtolower (shortenIfName ($port['ifName'], $object['breed']));

			if (array_key_exists ($port['ifName'], $object['portsbyname']))
			{
				$port_info = $object['portsbyname'][$port['ifName']];
				$comment[] = "Name exists";
				/* ifalias change */
				if ($port_info['label'] != $port['ifAlias'])
					$data['ports'][$key]['labelupdate'] = SG_BOX_CHECK;

				$data['ports'][$key]['RT_port_id'] = $port_info['id'];
			}
			else
				 $data['ports'][$key]['create'] = SG_BOX_CHECK;
		}

		/* l2address */
		if (!empty ($port['ifPhysAddress']))
		{
			$l2port =  sg_checkL2Address ($port['ifPhysAddress']);

			if (!empty ($l2port))
			{
				$comment[] = "L2Address exists";
				$data['ports'][$key]['macexists'] = $l2port;

				if ($data['ports'][$key]['create'])
					$data['ports'][$key]['create'] = SG_BOX_PROBLEM;
			}

			if ($port_info !== NULL)
			{
				/* mac update needed */
				if (l2addressForDatabase ($port_info['l2address']) != l2addressForDatabase ($port['ifPhysAddress']))
				{
					if (!empty ($l2port))
						$data['ports'][$key]['macupdate'] = SG_BOX_PROBLEM;
					else
						$data['ports'][$key]['macupdate'] = SG_BOX_CHECK;
				}
				else
					$data['ports'][$key]['macupdate'] = SG_BOX_IGNORE;
			}
		}

		/* label *
		if ($port_info && $port['ifAlias'] != $port_info['label'])
			 $data['ports'][$key]['labelupdate'] = SG_BOX_CHECK;


		/* port type id */
		$data['ports'][$key]['porttypeid'] = guessRToif_id ($port['ifType'], $port['ifDescr']);

		if (in_array ($port['ifType'],$sg_ifType_ignore))
		{
			$comment[] = "ignore if type";
			$data['ports'][$key]['create'] = SG_BOX_IGNORE;
		}
		else
		{
			if ($port_info)
			{
				$ptid = $port_info['iif_id']."-".$port_info['oif_id'];
				if ($data['ports'][$key]['porttypeid'] != $ptid)
				{
					$comment[] = "Update Type $ptid -> ".$data['ports'][$key]['porttypeid'];
					$data['ports'][$key]['porttypeupdate'] = SG_BOX_CHECK;
				}
			}
		}

		/* ignore ports without an Connector */
		if (!$sg_create_noconnector_ports && ($port['ifConnectorPresent'] == 2))
		{
			$comment[] = "no Connector";
			$data['ports'][$key]['create'] = SG_BOX_UNCHECK;
		}

		if ($port['ifHighSpeed'] * 1000000 > $port['ifSpeed'])
			$data['ports'][$key]['ifSpeed'] = $port['ifHighSpeed'] * 1000000;

		/* ip allocations */
		foreach ($port['ip'] as $ipaddr => $ip)
		{
			$data['ports'][$key]['ip'][$ipaddr]['allocate'] = SG_BOX_CHECK;
			$data['ports'][$key]['ip'][$ipaddr]['object_id'] = NULL;

			$address = getIPAddress (ip_parse ($ipaddr));
			if (count ($address['allocs'])) // TODO multiple allocs
			{
				if ($object['id'] != $address['allocs'][0]['object_id'])
				{
					$comment[] =  $address['ip'].' allocated '.$address['allocs'][0]['object_name'].' port: '.$address['allocs'][0]['name'];
					$data['ports'][$key]['ip'][$ipaddr]['object_id'] = $address['allocs'][0]['object_id'];
					$data['ports'][$key]['ip'][$ipaddr]['allocate'] = SG_BOX_PROBLEM;
				}
				else
					$data['ports'][$key]['ip'][$ipaddr]['allocate'] = SG_BOX_IGNORE;
			}

			/* reserved addresses */
			if ($address['reserved'] == 'yes')
			{
				$comment[] = $address['ip'].' reserved '.$address['name'];
				$data['ports'][$key]['ip'][$ipaddr]['allocate'] = SG_BOX_UNCHECK;
			}

			if ($ipaddr === $ip['bcast'])
			{
				$comment[] = "$ipaddr broadcast";
				$data['ports'][$key]['ip'][$ipaddr]['allocate'] = SG_BOX_UNCHECK;
			}

			if (
				$ipaddr == '127.0.0.1'
				|| $ipaddr == '0.0.0.0'
				|| $ipaddr == '::1'
				|| $ipaddr == '::'
				|| $ip['net'] == 'fe80::'
			)
			{
				$data['ports'][$key]['ip'][$ipaddr]['allocate'] = SG_BOX_IGNORE;
			}
		}

		$data['ports'][$key]['comment'] = implode ('; ', $comment);
		$data['ports'][$key]['action'] |=
						  $data['ports'][$key]['create']
						| $data['ports'][$key]['labelupdate']
						| $data['ports'][$key]['macupdate']
						| $data['ports'][$key]['porttypeupdate'];
	}

} /* snmpgeneric_process */

/*
 * prepare data for html form
 */
function snmpgeneric_processForm (&$data, $object)
{
	global $sg_portoifoptions;

	/* attributes */
	foreach ($data['sysObjectID']['attr'] as $attr_id => &$attr)
	{
		$attr['boxcolumn'] =
				'<b style="background-color:#00ff00">'
				.'<input style="background-color:#00ff00" class="attribute" type="checkbox" name="sysObjectID[attr]['.$attr_id.'][create]" value="'.SG_BOX_CHECK.'"'
				.($attr['create'] & SG_BOX_CHECK ? 'checked="checked"' : '').'></b>'
				.'<input type="hidden" name=sysObjectID[attr]['.$attr_id.'][value] value="'.$attr['value'].'">';

		$attr['valuecolumn'] =
			$attr['old_value'].(isset ($attr['old_key']) ? ' ('.$attr['old_key'].')' : '');

		$attr['newvaluecolumn'] = $attr['value'];

	}
	unset ($attr_id);

	$newporttypeoptions = getNewPortTypeOptions ();

	/* vendor ports */
	foreach ($data['sysObjectID']['port'] as $name => $port)
	{
		// TODO update ?

		$data['sysObjectID']['port'][$name]['boxcolumn'] =
			'<b style="background-color:'.($port['create'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00')
			.'"><input style="background-color:'.($port['create'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00')
			.'" class="moreport" type="checkbox" name="sysObjectID[port][vendor_'.$name.'][create]" value="'.SG_BOX_CHECK.'"'
			.($port['create'] & SG_BOX_PROBLEM ? ' disabled="disabled"' : ($port['create'] & SG_BOX_CHECK ? 'checked="checked"' : '')).'></b>'
			.'<input type="hidden" name="sysObjectID[port][vendor_'.$name.'][ifName]" value="'.$name.'">';

		// label ?

		if ($port['create'] & SG_BOX_PROBLEM)
		{
			$disabledselect = array ('disabled' => "disabled");
		} else
			$disabledselect = array ();

		$data['sysObjectID']['port'][$name]['porttypecolumn'] =
			getNiftySelect ($newporttypeoptions,
				array ('name' => "sysObjectID[port][vendor_$name][porttypeid]") + $disabledselect, $port['porttypeid']);
				/* disabled formfield won't be submitted ! */
	}
	unset ($name);
	unset ($port);

	/* ipspace */
	foreach ($data['ipspaces'] as $key => $ipspace)
	{

		// TODO disable ip allocs for this ipspace if unchecked
		$data['ipspaces'][$key]['boxcolumn'] =
			'<b style="background-color:#00ff00">'
			.'<input class="ipspace" style="background-color:#00ff00" type="checkbox" name="ipspaces['
			.$key.'][create]" value="'.SG_BOX_CHECK.'"'.($ipspace['create'] & SG_BOX_CHECK ? ' checked="checked"' : '').'></b>'
			.'<input type="hidden" name="ipspaces['.$key.'][addrtype]" value="'.$ipspace['addrtype'].'">';

		$data['ipspaces'][$key]['prefixcolumn'] = '<input type="text" size=50 name="ipspaces['.$key.'][prefix]" value="'.$key.'">';
		$data['ipspaces'][$key]['namecolumn'] = '<input type="text" name="ipspaces['.$key.'][name]">';
		$data['ipspaces'][$key]['reservecolumn'] =
			'<input type="checkbox" name="ipspaces['.$key.'][reserve]" checked="checked" value="'.SG_BOX_CHECK.'">';
	}

	/* ports */
	$newporttypeoptions = getNewPortTypeOptions ();

	foreach ($data['ports'] as $key => $port)
	{
		$actionvalue = $port['create'] & ~SG_BOX_PROBLEM ? 'add' : $port['RT_port_id'];

		$action = $port['action'] & ~SG_BOX_PROBLEM;

		$data['ports'][$key]['ifNamecolumn'] = $port['ifName'];

		if ($action)
			$data['ports'][$key]['ifNamecolumn'] .=
				'<input type="hidden" name="ports['.$port['ifIndex'].'][action]" value="'.$action.'">'
				.'<input type="hidden" name="ports['.$port['ifIndex'].'][RT_port_id]" value="'.$port['RT_port_id'].'">';

		if ($action || !empty ($port['ip']))
			$data['ports'][$key]['ifNamecolumn'] .=
				'<input type="hidden" name="ports['.$port['ifIndex'].'][ifName]" value="'.$port['ifName'].'">';

		$data['ports'][$key]['ifDescrcolumn'] =
					empty ($port['ifDescr']) ?
					$port['ifDescr'] :
					'<input'.(!$action ? ' disabled="disabled"' : '').' readonly="readonly" type="text" size="15" name="ports['.$port['ifIndex'].'][ifDescr]" value="'.$port['ifDescr'].'">';

		$data['ports'][$key]['ifAliascolumn'] =
					'<input'.(!$port['labelupdate'] && !$port['create'] ? ' disabled="disabled"' : '').' type="text" size="15" name="ports['.$port['ifIndex'].'][ifAlias]" value="'.$port['ifAlias'].'">';


		$speed = $port['ifSpeed'];

		$prefix = array ( '', 'k', 'M', 'G');
		$i = 0;
		while ($speed >= 1000)
		{
			$speed = $speed / 1000;
			$i++;
		}

		$data['ports'][$key]['ifSpeed'] = $speed.$prefix[$i];

		$value = l2addressFromDatabase ($port['ifPhysAddress']);
		if (isset ($port['macexists']))
		{
			$l2object_id = key ($port['macexists']);

			$value = '<a href="'.makeHref (array (
					'page'=>'object',
					'tab' => 'ports',
					'object_id' => $l2object_id,
					'hl_port_id' => $port['macexists'][$l2object_id]
					))
					.'">'.$value.'</a>';
		};

		$data['ports'][$key]['ifPhysAddresscolumn'] = ($action ? '<input type="hidden" name="ports['.$port['ifIndex'].'][ifPhysAddress]" value="'.$port['ifPhysAddress'].'">' : '').$value;

		/* ip address */
		if (!empty ($port['ip']))
		{
			$table = '<table>';
			foreach ($port['ip'] as $ipaddr => $ip )
			{
				$href = NULL;

				if ($ip['object_id'])
					$href = makeHref (
						array ('page'=>'object',
							'object_id' => $ip['object_id'],
							'hl_ipv4_addr' => $ipaddr)
					);

				switch ($ip['addrtype'])
				{
					case 'ipv6':
					case 'ipv6z':
						$inputname = 'ipv6';
						break;
					default:
						$inputname = 'ip';
				}

				$table .= '<tr><td>'
					.(!$ip['allocate'] ? '' :
					'<b style="background-color:'.($ip['allocate'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00')
					.'"><input class="'.$inputname.'addr" style="background-color:'
					.($ip['allocate'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00')
					.'" type="checkbox" name="ports['.$port['ifIndex'].'][ip]['.$ipaddr.'][allocate]" value="'.SG_BOX_CHECK.'"'
					.(!$ip['allocate'] ? ' disabled="disabled"' : '')
					.($ip['allocate'] & SG_BOX_CHECK ? ' checked="checked"' : '').'></b>'
					.'<input type=hidden name="ports['.$port['ifIndex'].'][ip]['.$ipaddr.'][addrtype]" value="'.$ip['addrtype'].'">')
					.'</td><td>'
					.($href ? "<a href=$href>$ipaddr/${ip['maskbits']}</a>" : "$ipaddr/${ip['maskbits']}")
					.'</td></tr>';
			}
			$table .= '</table>';
			$data['ports'][$key]['ipcolumn'] = $table;
		}
		else
			$data['ports'][$key]['ipcolumn'] = '';

		/* add port form columns */
		$data['ports'][$key]['portcreatecolumn'] = !$port['create'] ? '' :
					'<b style="background-color:'.($port['create'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00')
					.'"><input class="ports" style="background-color:'.($port['create'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00')
					.'" type="checkbox" name="ports['.$port['ifIndex'].'][create]" value="'.SG_BOX_CHECK.'"'
					.($port['create'] & SG_BOX_CHECK ? ' checked="checked"' : '').'></b>';

		 $data['ports'][$key]['labelupdatecolumn'] =  !$port['labelupdate'] ? '' :
					'<b style="background-color:#00ff00;">'
					.'<input class="label" style="background-color:#00ff00;" type="checkbox" name="ports['.$port['ifIndex'].'][labelupdate]" value="'
					.SG_BOX_CHECK.($port['labelupdate'] & SG_BOX_CHECK ? '" checked="checked"' : '' ).'"></b>';

		$data['ports'][$key]['macupdatecolumn'] = !$port['macupdate'] ? '' :
					'<b style="background-color:'.($port['macupdate'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00').'">'
					.'<input class="mac" style="background-color:'
					.($port['macupdate'] & SG_BOX_PROBLEM ? '#ff0000' : '#00ff00').'" type="checkbox" name="ports['.$port['ifIndex'].'][macupdate]" value="'
					.SG_BOX_CHECK.'"'.($port['macupdate'] & SG_BOX_CHECK ? 'checked="checked"' : '')
					.'></b>';

		$data['ports'][$key]['porttypeupdatecolumn'] = !$port['porttypeupdate'] ? '' :
					'<b style="background-color:#00ff00;">'
					.'<input class="porttype" style="background-color:#00ff00;" type="checkbox" name="ports['.$port['ifIndex'].'][porttypeupdate]" value="'
					.SG_BOX_CHECK.'"'.($port['porttypeupdate'] & SG_BOX_CHECK ? ' checked="checked"' : '').'></b>';

		/* port type id */
		/* add port type to newporttypeoptions if missing */
		if (strpos (serialize ($newporttypeoptions),$port['porttypeid']) === FALSE)
		{
			$portids = explode ('-',$port['porttypeid']);
			$oif_name = $sg_portoifoptions[$portids[1]];
			$newporttypeoptions['auto'] = array ($port['porttypeid'] => "*$oif_name");
		}

		$selectoptions = array ('name' => "ports[${port['ifIndex']}][porttypeid]");

		if (!$port['porttypeupdate'] && ! ($port['create'] & ~SG_BOX_PROBLEM))
			$selectoptions['disabled'] = 'disabled';

		$porttypeidselect = getNiftySelect ($newporttypeoptions, $selectoptions, $port['porttypeid']);

		$data['ports'][$key]['porttypecolumn'] = $porttypeidselect;
	}

	/* insert top row */
	array_unshift ($data['ports'], array (
					'ifIndex' => '',
					'ifDescrcolumn' => '',
					'ifAliascolumn' => '',
					'ifNamecolumn' => '',
					'ifType' => '',
					'ifSpeed' => '',
					'ifPhysAddresscolumn' => '',
					'ifOperStatus' => '',
					'ifInOctets' => '',
					'ifOutOctets' => '',
					'ifConnectorPresent' => '',
					'ipcolumn' => '<input type="checkbox" id="ipaddr" onclick="setchecked(this.id);" checked="checked">IPv4<br>
							<input type="checkbox" id="ipv6addr" onclick="setchecked(this.id);" checked="checked">IPv6',
					'portcreatecolumn' => '<input type="checkbox" id="ports" onclick="setchecked(this.id)">',
					'labelupdatecolumn' => '<input type="checkbox" id="label" onclick="setchecked(this.id);" checked="checked">',
					'macupdatecolumn' => '<input type="checkbox" id="mac" onclick="setchecked(this.id);" checked="checked">',
					'porttypeupdatecolumn' => '<input type="checkbox" id="porttype" onclick="setchecked(this.id);">',
					'porttypecolumn' => '',
					'comment' => '',
						)
	);

	/* add bottom row  with submit button */
	$data['ports'][] = array (
					'ifIndex' => '',
					'ifDescrcolumn' => '',
					'ifAliascolumn' => '',
					'ifNamecolumn' => '',
					'ifType' => '',
					'ifSpeed' => '',
					'ifPhysAddresscolumn' => '',
					'ifOperStatus' => '',
					'ifInOctets' => '',
					'ifOutOctets' => '',
					'ifConnectorPresent' => '',
					'ipcolumn' => '<input id="createbutton" type=submit value="Create Ports/IPs" onclick="return confirm(\'Create selected items?\')">',
					'portcreatecolumn' => '',
					'labelupdatecolumn' => '',
					'macupdatecolumn' => '',
					'porttypeupdatecolumn' => '',
					'porttypecolumn' => '',
					'comment' => '',
	);
} /* processForm */

/*---------------------------------------------------------------*/

function snmpgeneric_list ($object_id)
{

	if (isset ($_POST['snmpconfig']))
	{
		$snmpconfig = $_POST;
	}
	else
	{
		showError ("Missing SNMP Config");
		return;
	}

	/* set focus on submit button */
	echo '<body onload="document.getElementById(\'createbutton\').focus();">';

	addJS ('function setchecked(classname) { var boxes = document.getElementsByClassName(classname);
				 var value = document.getElementById(classname).checked;
				 for(i=0;i<boxes.length;i++) {
					if(boxes[i].disabled == false)
						boxes[i].checked=value;
				 }
		};', TRUE);

	$object = spotEntity ('object', $object_id);

	$snmpdev = NULL;
	$data = snmpgeneric_getsnmp ($snmpconfig, $snmpdev);

	echo '<form name=CreatePorts method=post action='.$_SERVER['REQUEST_URI'].'&module=redirect&op=create>';

	startPortlet ('System Informations');

	$columns = array (
				array ('row_key' => 'shortoid'),
				array ('row_key' => 'value')
			);

	renderTableViewer ($columns, $data['system']);

	finishPortlet ();

	/* process data */
	snmpgeneric_process ($data, $object, $snmpdev);
	snmpgeneric_processForm ($data, $object);

	/* print attributes */
	startPortlet ('Attributes');

	$columns = array (
		array ('row_key' => 'boxcolumn', 'th_text' => '<input type="checkbox" id="attribute" checked="checked" onclick="setchecked(this.id)">', 'td_escape' => FALSE),
		array ('row_key' => 'name', 'th_text' => 'Name'),
		array ('row_key' => 'valuecolumn', 'th_text' => 'Current Value'),
		array ('row_key' => 'newvaluecolumn', 'th_text' => 'New Value'),
		array ('row_key' => 'comment')
	);

	renderTableViewer ($columns, $data['sysObjectID']['attr']);

	finishPortlet ();

	if (!empty ($data['sysObjectID']['port']))
	{
		/* vendor ports */

		$columns = array (
			array ('row_key' => 'boxcolumn', 'th_text' => '<input type="checkbox" id="moreport" checked="checked" onclick="setchecked(this.id)">', 'td_escape' => FALSE),
			array ('row_key' => 'ifName', 'th_text' => 'Name'),
			array ('row_key' => 'porttypecolumn', 'th_text' => 'porttype', 'td_escape' => FALSE),
			array ('row_key' => 'ifDescr', 'th_text' => 'Descr'),
			array ('row_key' => 'comment', 'th_text' => 'Comment'),
		);

		startPortlet ('Vendor / Device specific ports');

		renderTableViewer ($columns, $data['sysObjectID']['port']);
		finishPortlet ();
	}

	/* ip spaces */

	/* print ip spaces table */
	if (!empty ($data['ipspaces']))
	{
		startPortlet ('Create IP Spaces');

		$columns = array (
				array ('row_key' => 'boxcolumn', 'th_text' => '<input type="checkbox" id="ipspace" onclick="setchecked(this.id)" checked=\"checked\">', 'td_escape' => FALSE),
				array ('row_key' => 'addrtype', 'th_text' => 'Type'),
				array ('row_key' => 'prefixcolumn', 'th_text' => 'Prefix', 'td_escape' => FALSE),
				// TODO VLAN
				array ('row_key' => 'namecolumn', 'th_text' => 'Name', 'td_escape' => FALSE),
				// TODO TAGS
				array ('row_key' => 'reservecolumn', 'th_text' => 'Reserve', 'td_escape' => FALSE)
			);

		renderTableViewer ($columns, $data['ipspaces']);
		finishPortlet ();
	}

	addCSS ('.nowrap { white-space: nowrap; }', TRUE);
	addCSS ('.tdrightbordergrey { border-right: 1px solid grey; }
		.tdrightborderwhite { border-right: 1px solid white; }', TRUE);

	$columns = array (
			array ('row_key' => 'ifIndex', 'th_text' => 'ifIndex'),
			array ('row_key' => 'ifDescrcolumn', 'th_text' => 'ifDescr', 'td_escape' => FALSE),
			array ('row_key' => 'ifAliascolumn', 'th_text' => 'ifAlias', 'td_escape' => FALSE),
			array ('row_key' => 'ifNamecolumn', 'th_text' => 'ifName', 'td_escape' => FALSE),
			array ('row_key' => 'ifType', 'th_text' => 'ifType'),
			array ('row_key' => 'ifSpeed', 'th_text' => 'ifSpeed'),
			array ('row_key' => 'ifPhysAddresscolumn', 'th_text' => 'ifPhysAddress', 'td_escape' => FALSE),
			array ('row_key' => 'ifOperStatus', 'th_text' => 'ifOper Status'),
			array ('row_key' => 'ifInOctets', 'th_text' => 'ifInOctets'),
			array ('row_key' => 'ifOutOctets', 'th_text' => 'ifOutOctets'),
			array ('row_key' => 'ifConnectorPresent', 'th_text' => 'ifCon Pres'),
			array ('row_key' => 'ipcolumn', 'th_text' => 'ip', 'td_escape' => FALSE),
			array ('row_key' => 'portcreatecolumn', 'th_text' => 'add port', 'td_class' => 'tdrightborderwhite', 'td_escape' => FALSE),
			array ('row_key' => 'labelupdatecolumn', 'th_text' => 'upd label', 'td_class' => 'tdrightbordergrey', 'td_escape' => FALSE),
			array ('row_key' => 'macupdatecolumn', 'th_text' => 'upd mac', 'td_class' => 'tdrightborderwhite', 'td_escape' => FALSE),
			array ('row_key' => 'porttypeupdatecolumn', 'th_text' => 'upd port type', 'td_escape' => FALSE),
			array ('row_key' => 'porttypecolumn', 'th_text' => 'Interface', 'td_escape' => FALSE),
			array ('row_key' => 'comment', 'th_text' => 'Comment', 'td_class' => 'nowrap')
		);

	startPortlet ('Ports');

	echo 'ifNumber: '.$data['system']['ifNumber']['value'].'<br>indexcount: '.$data['indexcount'].'<br>';

	renderTableViewer ($columns, $data['ports']);

	finishPortlet ();

	/* preserve snmpconfig */
	foreach ($_POST as $key => $value)
	{
		echo '<input type=hidden name='.$key.' value='.$value.' />';
	}
	unset ($key);
	unset ($value);

} // END function  snmpgeneric_list

/* -------------------------------------------------- */
function snmpgeneric_opcreate ()
{
	$object_id = genericAssertion ('object_id', 'uint');

	snmpgeneric_datacreate ($object_id, $_POST);
}

function snmpgeneric_datacreate ($object_id, $data)
{
	global $script_mode, $sg_dry_run;

	$count = 0;
	$attr = getAttrValues ($object_id);

	/* commitUpdateAttrValue ($object_id, $attr_id, $new_value); */
	if (isset ($data['sysObjectID']['attr']))
	{
		foreach ($data['sysObjectID']['attr'] as $attr_id => $newattr)
		{
			if (!isset ($newattr['create']))
				continue;

			if (! ($newattr['create'] & SG_BOX_CHECK))
				continue;

			$value = $newattr['value'];
			if (!empty ($value))
			{
				$msg = 'Attribute "'.$newattr['name']."\" set to $value";
				if (sg_checkArgs ('a', $msg, $count))
				{
					commitUpdateAttrValue ($object_id, $attr_id, $value);
					showSuccess ($msg);
				}
			}
		}
		unset ($attr_id);
		unset ($value);
	}
	/* updateattr */

	/* create ip spaces */
	if (isset ($data['ipspaces']))
	{
		foreach ($data['ipspaces'] as $range => $ipspace)
		{
			if (!isset ($ipspace['create']))
				continue;

			if (! ($ipspace['create'] & SG_BOX_CHECK))
				continue;

			$name = $ipspace['name'];
			$is_reserved = isset ($ipspace['reserve']);
			$addrtype = $ipspace['addrtype'];

			$msg = "$range $name created";
			if (sg_checkArgs ('s', $msg, $count))
			{
				if ($addrtype == 'ipv4' || $addrtype == 'ipv4z')
					createIPv4Prefix ($range, $name, $is_reserved);
				else
					createIPv6Prefix ($range, $name, $is_reserved);

				showSuccess ($msg);
			}
		}
		unset ($range);
		unset ($ipspace);
	}
	/* ip spaces */

	if ( isset($data['sysObjectID']['port']))
		$data['ports'] += $data['sysObjectID']['port'];

	if (isset ($data['ports']))
	{
		foreach ($data['ports'] as $ifIndex => $port)
		{
			$ifName = (isset ($port['ifName']) ? trim ($port['ifName']) : '' );
			$ifPhysAddress = (isset ($port['ifPhysAddress']) ? trim ($port['ifPhysAddress']) : '' );
			$ifAlias = (isset ($port['ifAlias']) ? trim ($port['ifAlias']) : '' );
			$ifDescr = (isset ($port['ifDescr']) ? trim ($port['ifDescr']) : '' );
			$porttypeid = (isset ($port['porttypeid']) ? $port['porttypeid'] : '');

			if (isset ($port['create']) && ($port['create'] & SG_BOX_CHECK))
			{
				if (empty ($ifName))
				{
					showError ('Port without ifName '.$porttypeid.', '.$ifAlias.', '.$ifPhysAddress);
					continue;
				}

				$msg = "Port created $ifName, $porttypeid, $ifAlias, $ifPhysAddress";
				if (sg_checkArgs ('p', $msg, $count))
				{
					commitAddPort ($object_id, $ifName, $porttypeid, $ifAlias, $ifPhysAddress);
					showSuccess ($msg);
				}
			}
			else
			{
				if (
					   isset ($port['labelupdate'])
					|| isset ($port['macupdate'])
					|| isset ($port['porttypeupdate'])
				)
				{
					/* update */

					/* get current prot values */
					$port_id = $port['RT_port_id'];
					$port_info = getPortInfo ($port_id);
					$port_name = $port_info['name'];
					$port_type_id = $port_info['iif_id'].'-'.$port_info['oif_id'];
					$port_label = $port_info['label'];
					$port_l2address = $port_info['l2address'];
					$port_reservation_comment = $port_info['reservation_comment'];

					$update = array ();
					$update_count = 0;

					if (isset ($port['labelupdate']))
						if ($port['labelupdate'] & SG_BOX_CHECK)
						{
							if (sg_checkArgs ('l', NULL, $count, TRUE))
							{
								$update[] = "label: $port_label -> $ifAlias";
								$port_label = $ifAlias;
							}
						}

					if (isset ($port['macupdate']))
						if ($port['macupdate'] & SG_BOX_CHECK)
						{
							if (sg_checkArgs ('m', NULL, $count, TRUE))
							{
								$update[] = "MAC: $port_l2address -> $ifPhysAddress";
								$port_l2address = $ifPhysAddress;
							}
						}

					if (isset ($port['porttypeupdate']))
						if ($port['porttypeupdate'] & SG_BOX_CHECK)
						{
							if (sg_checkArgs ('t', NULL, $count, TRUE))
							{
								$update[] = "typeid: $port_type_id -> ".$port['porttypeid'];
								$port_type_id = $port['porttypeid'];
							}
						}

					if (!empty ($update))
					{
						$msg = "Port $ifName updated ".implode (', ', $update);

						if ($script_mode)
							echo "$msg\n";

						if (!$sg_dry_run)
						{
							commitUpdatePort ($object_id, $port_id, $port_name, $port_type_id, $port_label, $port_l2address, $port_reservation_comment);
							showSuccess ($msg);
						}
					}
				}
			} /* else create */

			if (!empty ($port['ip']))
				foreach ($port['ip'] as $ipaddr => $ip)
				{
					if (!isset ($ip['allocate']))
						continue;

					if (! ($ip['allocate'] & SG_BOX_CHECK))
						continue;

					$msg = "$ipaddr allocated";
					if (sg_checkArgs ('i', $msg, $count))
					{
						if ($ip['addrtype'] == 'ipv4' || $ip['addrtype'] == 'ipv4z')
							bindIPToObject (ip_parse ($ipaddr), $object_id, $ifName, 1); /* connected */
						else
							bindIPv6ToObject (ip6_parse ($ipaddr), $object_id, $ifName, 1); /* connected */

						showSuccess ($msg);
					}
				}
		}
	}

	return $count;

} /* snmpgeneric_datacreate */

/* -------------------------------------------------- */

/* returns RT interface type depending on ifType, ifDescr, .. */
function guessRToif_id ($ifType,$ifDescr = NULL)
{
	global $sg_ifType2oif_id;
	global $sg_portiifoptions;
	global $sg_portoifoptions;

	/* default value */
	$retval = '24'; /* 1000BASE-T */

	if (isset ($sg_ifType2oif_id[$ifType]))
	{
		$retval = $sg_ifType2oif_id[$ifType];
	}

	if (strpos ($retval,'-') === FALSE)
		$retval = "1-$retval";

	/* no ethernetCsmacd */
	if ($ifType != 6)
		return $retval;


	/* try to identify outer and inner interface type from ifDescr */

	switch (true)
	{
		case preg_match ('/fast.?ethernet/i',$ifDescr,$matches):
			// Fast Ethernet
			$retval = 19;
			break;
		case preg_match ('/10.?gigabit.?ethernet/i',$ifDescr,$matches):
			// 10-Gigabit Ethernet
			$retval = 1642;
			break;
		case preg_match ('/gigabit.?ethernet/i',$ifDescr,$matches):
			// Gigabit Ethernet
			$retval = 24;
			break;
	}

	/**********************
	 * ifDescr samples
	 *
	 * Enterasys C3
	 *
	 * Unit: 1 1000BASE-T RJ45 Gigabit Ethernet Frontpanel Port 45 - no sfp inserted
	 * Unit: 1 1000BASE-T RJ45 Gigabit Ethernet Frontpanel Port 47 - sfp 1000BASE-SX inserted
	 *
	 *
	 * Enterasys S4
	 *
         * Enterasys Networks, Inc. 1000BASE Gigabit Ethernet Port; No GBIC/MGBIC Inserted
	 * Enterasys Networks, Inc. 1000BASE-SX Mini GBIC w/LC connector
	 * Enterasys Networks, Inc. 10GBASE SFP+ 10-Gigabit Ethernet Port; No SFP+ Inserted
	 * Enterasys Networks, Inc. 10GBASE-SR SFP+ 10-Gigabit Ethernet Port (850nm Short Wavelength, 33/82m MMF, LC)
	 * Enterasys Networks, Inc. 1000BASE Gigabit Ethernet Port; Unknown GBIC/MGBIC Inserted
	 *
	 */

	foreach ($sg_portiifoptions as $iif_id => $iif_type)
	{

		/* TODO better matching */


		/* find iif_type */
		if (preg_match ('/(.*?)('.preg_quote ($iif_type).')(.*)/i',$ifDescr,$matches))
		{

			$oif_type = "empty ".$iif_type;

			$no = preg_match ('/ no $/i', $matches[1]);

			if (preg_match ('/(\d+[G]?)BASE[^ ]+/i', $matches[1], $basematch))
			{
				$oif_type=$basematch[0];
			}
			else
			{
				if (preg_match ('/(\d+[G]?)BASE[^ ]+/i', $matches[3], $basematch))
					$oif_type=$basematch[0];
			}

			if ($iif_id == -1)
			{
				/* 2 => SFP-100 or 4 => SFP-1000 */

				if (isset ($basematch[1]))
				{
					switch ($basematch[1])
					{
						case '100' :
							$iif_id = 2;
							$iif_type = "SFP-100";
							break;
						default:
						case '1000' :
							$iif_id = 4;
							$iif_type = "SFP-1000";
							break;
					}
				}

				if (preg_match ('/sfp 1000-sx/i',$ifDescr))
					$oif_type = '1000BASE-SX';

				if (preg_match ('/sfp 1000-lx/i',$ifDescr))
					$oif_type = '1000BASE-LX';

			}

			if ($no)
			{
				$oif_type = "empty ".$iif_type;
			}

			$oif_type = preg_replace ('/BASE/',"Base",$oif_type);

			$oif_id = array_search ($oif_type,$sg_portoifoptions);

			if ($oif_id != '')
			{
				$retval = "$iif_id-$oif_id";
			}

			/* TODO check port compat */

			/* stop foreach */
			break;
		}
	}
	unset ($iif_id);
	unset ($iif_type);

	if (strpos ($retval,'-') === FALSE)
		$retval = "1-$retval";

	return $retval;

} /* guessRToif_id */

/* --------------------------------------------------- */
function sg_alreadyUsedL2Address ($address, $my_object_id)
{
        $result = usePreparedSelectBlade
        (
                'SELECT COUNT(*) FROM Port WHERE l2address = ? AND BINARY l2address = ? AND object_id != ?',
                array ($address, $address, $my_object_id)
        );
        $row = $result->fetch (PDO::FETCH_NUM);
        return $row[0] != 0;
}

/* ----------------------------------------------------- */

/* returns object_id and port_id to a given l2address */
function sg_checkL2Address ($address)
{
        $result = usePreparedSelectBlade
        (
                'SELECT object_id,id FROM Port WHERE BINARY l2address = ?',
                array ($address)
        );
        $row = $result->fetchAll (PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);
        return $row;
}

function sg_checkObjectNameUniqueness ($name, $type_id, $object_id = 0)
{
	// Some object types do not need unique names
	// 1560 - Rack
	// 1561 - Row
	$dupes_allowed = array (1560, 1561);
	if (in_array ($type_id, $dupes_allowed))
	return;

	$result = usePreparedSelectBlade
	(
		'SELECT COUNT(*) FROM Object WHERE name = ? AND id != ?',
		array ($name, $object_id)
	);
	$row = $result->fetch (PDO::FETCH_NUM);
	if ($row[0] != 0)
		return false;
	else
		return true;
}

function sg_detectDeviceBreedByObject ($object)
{
	global $breed_by_swcode, $breed_by_hwcode, $breed_by_mgmtcode;

	foreach ($object['attr'] as $record)
	{
		if ($record['id'] == 4 and array_key_exists ($record['key'], $breed_by_swcode))
			return $breed_by_swcode[$record['key']];
		elseif ($record['id'] == 2 and array_key_exists ($record['key'], $breed_by_hwcode))
			return $breed_by_hwcode[$record['key']];
		elseif ($record['id'] == 30 and array_key_exists ($record['key'], $breed_by_mgmtcode))
			return $breed_by_mgmtcode[$record['key']];
	}
	return '';
}

/* ------------------------------------------------------- */
?>
