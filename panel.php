<?php
/*
 * RackTables PanelView Script
 *
 * !!! THIS IS NOT A RackTables PLUGIN !!!
 *
 * To use it just place it in the wwwroot directory of RackTables
 *
 * Then goto
 *
 * http://address.to.your.server/racktables/panel.php
 *
 *
 * needs PHP >= 5.4.0
 *      saved SNMP settings ( see snmpgeneric.php extension )
 *      also RT port names and SNMP port names must be the same ( should work fine with snmpgeneric.php created ports )
 *
 * (c)2015 Maik Ehinger <m.ehinger@ltur.de>
 */

/**
 * The newest version of this script can be found at:
 *
 * https://github.com/github138/myRT-contribs/tree/develop-0.20.x
 *
 */

/*
 * What it does..
 *
 *	Displays object ports and their remote, start or end port.
 *	Displays the link chain of each port (Detail)
 *	Displays the SNMP link state and speed
 *	Displays object "Zone" tag as part of the port status
 *	Display without login
 *
 *	Screenshots can be found here:
 *	https://github.com/github138/myRT-contribs/tree/develop-0.20.x
 *
 */

/*
 * TODO
 *
 * printable list csv
 * add links to create, delete links and other RT funktions
 *
 */


/* RT debug mode */
$debug_mode = 0;

function pv_html_start($debug = false)
{
	echo <<<ENDECHO
<html>
<head>
<style type="text/css">
	.table-row-0 { }
	.table-row-1 { background:#ccc }

	.ifoperstatus { font-size:10pt; }
	.ifoperstatus-default { background-color:#ddd; }
	.ifoperstatus-1, .ifoperstatus-up { background-color:#00ff00; }
	.ifoperstatus-2, .ifoperstatus-down { background-color:#ff0000; }
	.ifoperstatus-3, .ifoperstatus-testing { background-color:#ffff66; }
	.ifoperstatus-4, .ifoperstatus-unknown { background-color:#ffffff; }
	.ifoperstatus-5, .ifoperstatus-dormant { background-color:#90bcf5; }
	.ifoperstatus-6, .ifoperstatus-notPresent { }
	.ifoperstatus-7, .ifoperstatus-lowerLayerDown { }

	.zone-default { }
	.zone-LAN { background-color:#3399ff; }
	.zone-DMZ { background-color:#ff7e00; }

	.port-groups { border-spacing:1px;display:table; }
	.port-group { display:table-cell;border:3px solid #000;background-color:#c0c0c0; }

	.port-column { display:table-cell;position:relative; }

	.port { position:relative;width:42px;height:250px;border:2px solid #000;overflow:hidden; }
	.port-pos-1 { margin-bottom:1px; }
	.port-pos-2 { }
	.port-pos-0 { margin-top:1px; }

	.port-header { position:absolute }
	.port-header-pos-1 { top:0px; }
	.port-header-pos-0 { bottom:0px; }

	.port-status { position:absolute;min-width:42px;text-align:center;font-size:10pt; }

	.port-status-pos-1 { top:35px; }
	.port-status-pos-0 { bottom:35px; }

	.port-status-remote { position:absolute;min-width:42px;text-align:center;font-size:10pt; }
	.port-status-remote-pos-1, .port-status-start-pos-1, .port-status-end-pos-1 { bottom:0px; }
	.port-status-remote-pos-0, .port-status-start-pos-0, .port-status-end-pos-0 { top:0px; }

	.port-info { position:absolute;width:90%;background-color:#ddd;overflow:hidden; }
	.port-info-start, .port-info-end { position:absolute;width:90%;background-color:#ddd;overflow:hidden;display:none; }
	.port-info-pos-1 { top: 80px; }
	.port-info-pos-0 { bottom: 80px;}

	.port-rotate-container { white-space:nowrap;font-size:11pt;height:150px }
	.port-rotate { position:absolute; }

	.port-rotate-pos-1 { bottom:40px;transform-origin:bottom left;transform:rotate(-90deg) translate(0, 100%); }
	.port-rotate-pos-0 { top:40px;left:100%;transform-origin:top left;transform:rotate(90deg) translate(0, 0); }

	.port-top { vertical-align:top; }
	.port-bottom { vertical-align:bottom; }

	.port-name {  font-size:10pt;margin:0px auto;width:40px;text-align:center; }
	.port-number { font-size:8pt;color:#eee; }

	.port-detail { position:fixed;z-index:1000;top:0px;right:0px;border:3px solid #000;background-color:#fff }
	.port-detail-links { background-color:#ccc }
	.hidden { display:none; }
	.info-footer { }
</style>
</head>
<body onload="setportinfo('start');">
ENDECHO;
	echo "<div class=\"info-footer\" id=\"info\"".($debug ? '' : ' style="display:none;"' )."></div>";
};

/* =============================== Start =========================================== */

	// redirect pix calls to index.php...
	// doen't work without login !
	if(0)
	if(isset($_GET['module']))
	{
		header('location: index.php?'.$_SERVER['QUERY_STRING']);
		exit;
	}

try {

	// readonly access !?!?
	/* set to false if login wanted */
	$script_mode = true;

	session_start();
	session_write_close();

	/* ------- RackTables include start ------ */
	require_once("inc/init.php");
	require_once("inc/interface.php"); // renderCell

	if(isset($_GET['debug']))
	{
		$debug=$_GET['debug'];
		if(empty($debug))
			$debug = 0;
	}
	else
		$debug=0;

/* ----------------------------------------------------- */

	$pv_cache = array();

/* ------------------- AJAX Requests -------------------------- */

	if(isset($_GET['json']) && isset($_GET['object_id']))
	{
		ob_start();

		if($debug > 3)
			memory_get_usage(true)." MEM used Start<br>";

		$pv_cache = $_SESSION['pv_cache'];

		$object_id = $_GET['object_id'];

		$object = $pv_cache['objects'][$object_id];

		$json = pv_processajaxobject($object, $debug);

		if($debug > 3)
			echo memory_get_usage(true)." MEM used END<br>";

		/* set debug output */
		if(ob_get_length())
			$json['debug'] = ob_get_contents();

		ob_end_clean();

		echo json_encode($json);
		exit;
	}
/* ------------------- END AJAX Requests -------------------------- */

	$pv_local_cache = array();

/* =============================== Start Output HTML =========================================== */

	pv_html_start($debug);
	if($debug > 3)
		echo memory_get_usage(true)." MEM used Start<br>";
	echo "<a style=\"font-size:10pt;\">".date('d.m.Y H:i:s',time())."</a>";

/* ================= Overview Page ========================================== */
	if(!isset($_GET['object_id']))
	{

		echo "<br>Please select the object to display<br><br>";

		$background = array( "#ffffff", "#e6e6e6");

		echo "<table cellspacing=\"10px\"><tr><th>Panel</th><th width=\"50px;\"><th></th><th width=\"100px;\"></th><th></th><th width=\"50px;\"></th></tr>";
		$alternate = 0;

		// display Patchpanels first
		$objlist = scanRealmByText('object','{$typeid_9}');

		$i = 0;
		foreach($objlist as $object)
		{

			$panel = $object['name'];
			$nports = $object['nports'];
			$object_id = $object['id'];

			$_GET = array('object_id' => $object_id) + $_GET;

			if (($i % 2) == 0)
			{
				echo "<tr class=\"table-row-$alternate\";>";
				$html = "";
			}


			$html = "<td><a href=?".http_build_query($_GET).">$panel ($nports ports)</a></td><td></td><td>".p_getRackInfo($object,'',$debug)."</td><td></td>$html";

			if (($i % 2) == 1)
			{
				echo "$html</tr>";
				$alternate = !$alternate;
			}

			$i++;
		}

		$objlist = scanRealmByText('object','{$typeid_8}');
		foreach($objlist as $object)
		{
			$panel = $object['name'];
			$nports = $object['nports'];
			$object_id = $object['id'];

			$_GET = array('object_id' => $object_id) + $_GET;

			echo "<tr class=\"table-row-$alternate\";><td><a href=?".http_build_query($_GET).">$panel ($nports ports)</a></td><td></td>";
			//renderCell($object);
			echo "<td>".p_getRackInfo($object,'',$debug)."</td>";
			echo "</tr>";

			$alternate = !$alternate;
		}
		echo "</table>";

		echo "</body></html>";

		exit;
	}

/* ================= Object View Page ========================================== */

	$object_id = $_GET['object_id'];

	$object = pv_prepareobject($object_id, $debug);
	$pv_cache['objects'][$object_id] = $object;

	// Save pv_cache for ajax requests
	session_start();
	pv_session_start($debug, "portidlist");
	$_SESSION['pv_cache'] = $pv_cache;
	pv_session_write_close($debug, "portidlist");

	if(0)
	{
		var_dump_html($object);
		var_dump_html($pv_cache);
	}

	echo '<script src="js/jquery-1.4.4.min.js"></script>';

	if(0)
	{
	echo "<pre>";
	var_dump($_SERVER);
	echo "</pre>";
	}

//	$object = spotEntity('object', $object_id);
	$object_name = $object['name'];
	$nports = $object['nports'];

	echo "<br><a href=panel.php".($debug ? "?debug=$debug" : '' ).">Select Object</a>";

//	amplifyCell($object);

	echo "<table><tr><td>";
	renderCell($object);
	echo "</td><td>";
	echo p_getRackInfo($object,'',$debug);
	echo "</td></tr></table>";

	if(0)
	{
	echo "<pre>";
	var_dump($object);
	echo "</pre>";
	}

	echo "(".$object['nports']." ports)<br>";

	echo "<br><a href=\"\" id=\"infotoggleremote\" onclick=\"setportinfo('remote'); return false;\" style=\"display:none;\">Show remote ports</a>";
	echo " <a href=\"\" id=\"infotogglestart\" onclick=\"setportinfo('start'); return false;\">Show start ports</a>";
	echo " <a href=\"\" id=\"infotoggleend\" onclick=\"setportinfo('end'); return false;\">Show end ports</a>";

	$linkcount = 0;

	switch($object['objtype_id'])
	{
		// PatchPanel
		case 9:
			$linkcount = pv_layout_default($object, 12);
			break;
		// Network Switch
		case 8:
			$linkcount = pv_layout_default($object, 8, true, true);
			break;
		default:
			$linkcount = pv_layout_default($object, 0, true, false, 1);
	}

	if(0)
		var_dump_html($object);

	if(0)
		var_dump_html($pv_cache['portidlist']);
	// --- JS ---

	// to js

	$pv_cache['portidlist']['count'] = count($pv_cache['portidlist']['objects']);
	$portidlist = json_encode($pv_cache['portidlist']);
	/*
		JS
		setport is call for every connected port
	 */

echo <<<ENDSCRIPT
	<div id="requests"><div id="reqcounter">-</div></div>
	<script>

	/*
	$( ".port-rotate-container" ).css('visibility', 'hidden');
	$( ".port-rotate-container" ).css('height', '45px');
	$( ".port" ).css('height', '150px');
	*/
	function setportinfo(string)
	{

		if(string == 'remote')
		{
			$( ".port-info" ).show();
			$( ".port-info-start" ).hide();
			$( ".port-info-end" ).hide();
			$( "#infotoggleremote" ).hide();
			$( "#infotogglestart" ).show();
			$( "#infotoggleend" ).show();
		}

		if(string == 'start')
		{
			$( ".port-info" ).hide();
			$( ".port-info-start" ).show();
			$( ".port-info-end" ).hide();
			$( "#infotoggleremote" ).show();
			$( "#infotogglestart" ).hide();
			$( "#infotoggleend" ).show();
		}

		if(string == 'end')
		{
			$( ".port-info" ).hide();
			$( ".port-info-start" ).hide();
			$( ".port-info-end" ).show();
			$( "#infotoggleremote" ).show();
			$( "#infotogglestart" ).show();
			$( "#infotoggleend" ).hide();
		}

	}

	function setdetail(elem, hide)
	{
		var a = $( "#port" + elem.id + "-detail");

		a.toggle();
	}

	 function setports( data, textStatus, jqHXR ) {
		var msg = "Done.";

		if(data.debug)
		{
			$( "#info" ).html($( "#info" ).html() + "DEBUG: " + data.name + ": " + data.debug);
			msg = data.debug;
		}

		$( "#reqcounter" ).html(parseInt($( "#reqcounter" ).html()) - 1);

		$( "#req" + data.id ).html(data.name + " " + msg);

		for(var index in data.ports)
		{
			setport(data, data.ports[index]);
		}
	 }

	function setportstatus( obj, port, detail)
	{

		id = port.id;

		if(detail)
			tagidsuffix = "-detail";
		else
			tagidsuffix = "";

		if(obj.zone)
			zone = "<a class=\"zone-" + obj.zone + "\">" + obj.zone + "</a>";
		else
			zone = ""

		if(!detail)
		{
			//$( "#port" + id + "-status" + tagidsuffix ).html(
			$( "div[name='port" + id + "-status" + tagidsuffix + "']" ).html(
					"<table class=\"ifoperstatus ifoperstatus-" + port.snmpinfos.operstatus + "\"><tr><td>"
					+  (port.snmpinfos.ipv4 ? zone + "<br>" + port.snmpinfos.speed : "x")
				//	+  ( port.snmpinfos.vlan ? "<br>" + port.snmpinfos.vlan : "" )
					+ "</td></tr></table>");
			return;
		}

		//$( "#port" + id + "-status" + tagidsuffix ).html(port.snmpinfos.alias
		$( "div[name='port" + id + "-status" + tagidsuffix + "']" ).html(
					port.snmpinfos.alias
					+ "<table class=\"ifoperstatus ifoperstatus-" + port.snmpinfos.operstatus + "\"><tr><td>"
					+ (port.snmpinfos.ipv4 ? port.snmpinfos.ipv4 : "") + "<br>" + port.snmpinfos.operstatus + "<br>" + zone
					+ ( port.snmpinfos.vlan ? "<br>" + port.snmpinfos.vlan_name + " (" + port.snmpinfos.vlan + ")" : "" )
					+ "</td></tr></table>");
	}

	function setport( obj, port ) {

		if(port.debug)
			$( "#info" ).html($( "#info" ).html() + port.name + " " + port.debug);

		if(port.snmpinfos)
		{
			setportstatus(obj, port, false);
			setportstatus(obj, port, true);
		}

		if(port.remote)
		{
			if(port.remote.snmpinfos)
			{
				setportstatus(obj, port.remote, false);
				setportstatus(obj, port.remote, true);
			}
		}

		//console.log("Start" + port.id);
	}

	function ajaxerror(jqHXR, textStatus, qXHR, errorThrown)
	{
		$( "#info" ).html($( "#info" ).html() + "<br>" + textStatus + " " + qXHR + " " + errorThrown);
	}

	var r_obj_ids = jQuery.parseJSON('$portidlist');

	$( "#reqcounter" ).html(r_obj_ids.count);

	for (var r_obj_id in r_obj_ids.objects)
	{

		var r_obj = r_obj_ids.objects[r_obj_id];
		$( "#requests" ).append( "<div id=\"req" + r_obj_id.trim() + "\">" + r_obj.name + " waiting ...</div>" );
		$('body').scrollTop(0);
		{
		$.ajax({
			dataType: "json",
			url: "{$_SERVER['PHP_SELF']}",
			data: {
				json: "get",
				object_id: "$object_id",
				r_object_id: r_obj_id,
				debug: $debug
			},
			error: ajaxerror,
			success: setports
		});
		}
	}

</script>
ENDSCRIPT;

	echo "Object has $linkcount ports linked<br><a href=panel.php".($debug ? "?debug=$debug" : '' ).">Select Object</a>";

//	renderRack($object['rack_id']);

	echo "</body></html>";

	} catch (Exception $e)
	{
		printexception($e);
	}

/* ========================== END HTML =========================== */

/*
 *   from RT database.php fetchPortList()
 *	with Link table selection
 */
function pv_fetchPortList ($sql_where_clause, $query_params = array(), $linktable = 'Link')
{
	$query = <<<END
SELECT
	Port.id,
	Port.name,
	Port.object_id,
	Object.name AS object_name,
	Port.l2address,
	Port.label,
	Port.reservation_comment,
	Port.iif_id,
	Port.type AS oif_id,
	(SELECT PortInnerInterface.iif_name FROM PortInnerInterface WHERE PortInnerInterface.id = Port.iif_id) AS iif_name,
	(SELECT PortOuterInterface.oif_name FROM PortOuterInterface WHERE PortOuterInterface.id = Port.type) AS oif_name,

	IF(la.porta, la.cable, lb.cable) AS cableid,
	IF(la.porta, pa.id, pb.id) AS remote_id,
	IF(la.porta, pa.name, pb.name) AS remote_name,
	IF(la.porta, pa.object_id, pb.object_id) AS remote_object_id,
	IF(la.porta, oa.name, ob.name) AS remote_object_name,

	(SELECT COUNT(*) FROM PortLog WHERE PortLog.port_id = Port.id) AS log_count,
	PortLog.user,
	UNIX_TIMESTAMP(PortLog.date) as time
FROM
	Port
	INNER JOIN Object ON Port.object_id = Object.id

	LEFT JOIN $linktable AS la ON la.porta = Port.id
	LEFT JOIN Port AS pa ON pa.id = la.portb
	LEFT JOIN Object AS oa ON pa.object_id = oa.id
	LEFT JOIN $linktable AS lb on lb.portb = Port.id
	LEFT JOIN Port AS pb ON pb.id = lb.porta
	LEFT JOIN Object AS ob ON pb.object_id = ob.id

	LEFT JOIN PortLog ON PortLog.id = (SELECT id FROM PortLog WHERE PortLog.port_id = Port.id ORDER BY date DESC LIMIT 1)
WHERE
	$sql_where_clause
END;

	$result = usePreparedSelectBlade ($query, $query_params);
	$ret = array();
	while ($row = $result->fetch (PDO::FETCH_ASSOC))
	{
		$row['l2address'] = l2addressFromDatabase ($row['l2address']);
		$row['linked'] = isset ($row['remote_id']) ? 1 : 0;

		// last changed log
		$row['last_log'] = array();
		if ($row['log_count'])
		{
			$row['last_log']['user'] = $row['user'];
			$row['last_log']['time'] = $row['time'];
		}
		unset ($row['user']);
		unset ($row['time']);

		$ret[] = $row;
	}
	return $ret;
} /* pv_fetchPortList */

function pv_getPortInfo ($port_id, $linktable = 'Link')
{
        $result = pv_fetchPortList ('Port.id = ?', array ($port_id), $linktable);
        return empty ($result) ? NULL : $result[0];
} /* pv_getPortInfo */

function pv_getObjectPortsAndLinks ($object_id)
{
        $ret = pv_fetchPortList ("Port.object_id = ?", array ($object_id));
        return sortPortList ($ret, TRUE);
} /* pv_getObjectPortsAndLinks */

/* -------------------------------------------------------------------------- */

function pv_layout_default(&$object, $groupports = 8, $bottomstart = false, $modules = false, $portrows = 2)
{
	$i = 0;
	$portcolumn = "";
	$linkcount = 0;

	$lastmodule = null;
	$nomodul = array();

	echo "<div class=\"port-groups\">";
	foreach($object['ports'] as $key => $port)
	{

		$object['portnames'][$port['name']] = $port;

		$port_id = $port['id'];
		$port_name = $port['name'];

		$pname = $port_name;
		$module = "";
		$pport = "";
		// split name in name, module, port
		//if(preg_match('/^(?:([a-zA-Z]+)(?:[\W]?)?([\d]+)?[\W]?([\d]+)$/', $port_name, $match))
		if(preg_match('/^([a-zA-Z]+)?(?:[\W]?([\d]+)?[\W])?([\d]+)?$/', $port_name, $match))
			if(count($match) == 4)
				list($tmp,$pname,$module,$pport) = $match;

		//echo "N: $pname M: $pmodul P: $pport<br>";

		if($port['linked'])
			$linkcount++;


		if($module == "")
		{
			$nomodul[] = pv_layout_port($port, count($nomodul) + 1, 1);
			continue;
		}


		if($modules)
		{
			// port modules
			if($module != $lastmodule)
			{
				if(($i % $portrows) != 0)
					echo "$portcolumn</div>"; // port-column

				if($groupports)
					if(($i % $groupports) != 0)
						echo "</div>"; // port-group

				echo "</div>"; // port-groups

				$i = 0;
				$portcolumn = "";
				echo "Modul: $module";
				echo "<br><div class=\"port-groups\">";
			}

			$lastmodule = $module;

		}

		$i++;

		if($groupports)
			if(($i % $groupports) == 1)
				echo "<div class=\"port-group\">";

		if($portrows == 2)
		{
			// print each row different
			if(($i % $portrows) == 1)
				$pos = ($bottomstart ? 0 : 1); // 0 = bottom; 1 = top
			else
				$pos = ($bottomstart ? 1 : 0); // 0 = bottom; 1 = top
		}
		else
			$pos = ($bottomstart ? 0 : 1); // 0 = bottom; 1 = top

		$portdiv = pv_layout_port($port, $i, $pos);

		if(!$bottomstart)
			$portcolumn = "$portcolumn$portdiv";
		else
			$portcolumn = "$portdiv$portcolumn";

		if(($i % $portrows) == 0)
		{
			echo "<div class=\"port-column\">";
			echo "$portcolumn</div>";
			$portcolumn = "";
		}

		if($groupports)
			if(($i % $groupports) == 0)
				echo "</div>";
	}

	if(($i % $portrows) != 0)
	{
		$fillcount = $portrows - ($i % $portrows);

		$fill = "";
		for($f=0;$f<$fillcount;$f++)
			$fill .= "<div class=\"port\"></div>";

		if(!$bottomstart)
			$portcolumn .= $fill;
		else
			$portcolumn = "$fill$portcolumn";

		echo "<div class=\"port-column\">";
		echo "$portcolumn</div>"; // port-column
	}

	if($groupports)
		if(($i % $groupports) != 0)
			echo "</div>"; // port-group

	echo "</div>"; // port-groups

	/* Port without modul */
	if($nomodul)
	{
		echo "Other Ports:<br><div id=\"nomodule\" class=\"port-groups\">";
		foreach($nomodul as $portdiv)
			echo "<div class=\"port-column\">$portdiv</div>";
		echo "</div>";
	}

	return $linkcount;

} // layout_default

function pv_layout_port($port, $number, $pos)
{
	global $pv_local_cache;

	$port_id = $port['id'];
	$port_name = $port['name'];

	$title = "Name: $port_name - No: $number - ID: $port_id";

	$portdiv = "<div id=\"$port_id\" class=\"port port-pos-$pos\" onmouseover=\"setdetail(this,false);\" onmouseout=\"setdetail(this,true);\">";
	$portheader = "<div class=\"port-header port-header-pos-$pos\">";
	$portlabel = "<div class=\"port-number\">$number</div>";
	$portname = "<div class=\"port-name\">$port_name</div>";

	if(isset($pv_local_cache[$port_id]['linkchain']))
		$linkchain = $pv_local_cache[$port['id']]['linkchain'];
	else
	{
		echo "port linkchain not set !!<br>";
		$linkchain = new pv_linkchain($port['id']);
	}

	if(0)
		var_dump_html($linkchain);

	$details = "<table><tr><td>No.: $number (ID: ".$port['id'].")<br>".$port['object_name']."<br>".$port['name']."<br>"
		.$port['label']."<br>".$port['reservation_comment']
		."<div name=\"port${port_id}-status-detail\" id=\"port${port_id}-status-detail\">No Status</div></td>";

	if($linkchain->linked)
		$details .= "<td class=\"port-detail-links\">".$linkchain->getchainhtml()."</td>";

	$link = "<div class=\"ifoperstatus ifoperstatus-default\">x</div>";
	$portstatusremote = "<div class=\"port-status-remote port-status-remote-pos-$pos\" title=\"$title\"> $link </div>";

	if($port['linked'])
	{
		$remote_port_id = $port['remote_id'];
		$details .= "<td>Remote:<br>".$port['cableid']."<br>".$port['remote_object_name']."<br>".$port['remote_name']."<div name=\"port${remote_port_id}-status-detail\" id=\"port${remote_port_id}-status-detail\">No Remote Status</div></td>";

		$portstatusremote = "<div name=\"port${remote_port_id}-status\" id=\"port${remote_port_id}-status\" class=\"port-status-remote port-status-remote-pos-$pos\" title=\"$title\">$link</div>";

	}

	$details .= "</tr></table>";

	$portdetail = "<div id=\"port${port_id}-detail\" class=\"port-detail hidden\" onclick=\"togglevisibility(this,true);\">$details</div>";

	$portstatus = "<div name=\"port${port_id}-status\" id=\"port${port_id}-status\" class=\"port-status port-status-pos-$pos\" title=\"$title\"> - </div>";

	$remote_object_name = "<a href=\"?object_id=".$port['remote_object_id']."\">".$port['remote_object_name']."</a>";

	$loop_txt = "";

	/* link chain end */
	if($linkchain->linked)
	{
		$end_port_id = $linkchain->end;
		$end_port = $linkchain->ports[$end_port_id]['Link'];
		$loop_txt = ($linkchain->loop ? "LOOP!<br>" : "" );
		$end_text = "$loop_txt";

		if($end_port_id != $port_id)
		{
			$end_object_name = "<a href=\"?object_id=".$end_port['object_id']."\">".$end_port['object_name']."</a>";
			$end_text .= "$end_object_name<br>".$end_port['name'];
		}
		else
			$end_text .= "self end";

		$start_port_id = $linkchain->start;
		$start_port = $linkchain->ports[$start_port_id]['Link'];
		$start_text = "$loop_txt";

		if($start_port_id != $port_id)
		{
			$start_object_name = "<a href=\"?object_id=".$start_port['object_id']."\">".$start_port['object_name']."</a>";
			$start_text .= "$start_object_name<br>".$start_port['name'];
		}
		else
			$start_text .= "self start";

	}
	else
	{
		$end_port_id = 0;
		$end_text = "n/a end";

		$start_port_id = 0;
		$start_text = "n/a start";
	}


	if($start_port_id != $port_id)
		$portstatusstart = "<div name=\"port${start_port_id}-status\" id=\"port${start_port_id}-status\" class=\"port-status port-status-start-pos-$pos\">$link</div>";
	else
		$portstatusstart = "";

	$portrotatecontainerstart = "<div class=\"port-rotate-container\"><div class=\"port-rotate port-rotate-pos-$pos\">$start_text</div></div>";
	if($end_port_id != $port_id)
		$portstatusend = "<div name=\"port${end_port_id}-status\" id=\"port${end_port_id}-status\" class=\"port-status port-status-end-pos-$pos\">$link</div>";
	else
		$portstatusend = "";

	$portrotatecontainerend = "<div class=\"port-rotate-container\"><div class=\"port-rotate port-rotate-pos-$pos\">$end_text</div></div>";

	$portrotatecontainer = "<div class=\"port-rotate-container\"><div class=\"port-rotate port-rotate-pos-$pos\">$loop_txt$remote_object_name<br>".$port['remote_name']."</div></div>";

	if($pos)
	{
		$portheader .= "$portlabel$portname</div>";
		$portinfo = "<div class=\"port-info port-info-pos-$pos\">$portrotatecontainer$portstatusremote</div>";

		$portinfoend = "<div class=\"port-info-end port-info-pos-$pos\">$portrotatecontainerend$portstatusend</div>";

		$portinfostart = "<div class=\"port-info-start port-info-pos-$pos\">$portrotatecontainerstart$portstatusstart</div>";

		$portdiv .= "$portheader$portstatus$portinfo$portinfostart$portinfoend</div>$portdetail";
	}
	else
	{
		$portheader .= "$portname$portlabel</div>";
		$portinfo = "<div class=\"port-info port-info-pos-$pos\">$portstatusremote$portrotatecontainer</div>";

		$portinfoend = "<div class=\"port-info-end port-info-pos-$pos\">$portstatusend$portrotatecontainerend</div>";

		$portinfostart = "<div class=\"port-info-start port-info-pos-$pos\">$portstatusstart$portrotatecontainerstart</div>";

		$portdiv .= "$portinfo$portinfostart$portinfoend$portstatus$portheader</div>$portdetail";
	}

	return $portdiv;
}

/* --------------------------------------------------------- */
function p_getRackInfo($object, $style = '', $debug = 0) {

		global $pv_cache;
                $rack_id = $object['rack_id'];

		if(!$rack_id)
                        return  '<span style="'.$style.'">Unmounted</span>';

		$msg = '';
		if(!isset($pv_cache['racks'][$rack_id]))
		{
			if($debug)
				$msg = "Rack not in cache..<br>";

			$rack = spotEntity('rack', $rack_id);
			$pv_cache['racks'][$rack_id] = $rack;

		}
		else
		{
			if($debug)
				$msg = "Rack in cache..<br>";
			$rack = $pv_cache['racks'][$rack_id];
		}

		if(0)
		var_dump_html($rack);

		return $msg.'<a style="'.$style.'" href="'.makeHref(array('page'=>'location', 'location_id'=>$rack['location_id'])).'">'.$rack['location_name']
			.'</a><br><a style="'.$style.'" href="'.makeHref(array('page'=>'row', 'row_id'=>$rack['row_id'])).'">'.$rack['row_name']
                        .'</a>/<a style="'.$style.'" href="'.makeHref(array('page'=>'rack', 'rack_id'=>$rack['id'])).'">'
                        .$rack['name'].'</a>';

        } /* p_renderRack */
/* --------------------------------------------------------- */
/* return Object with additional infos
 * also set portidlist with all ports with link to this object ( directly connected or via linkchain ) grouped by object_id
 *
 * ! space before object_id key which prevents sorting of js array in the browser
 */
function pv_prepareobject($object_id, $debug)
{
	global $pv_cache, $pv_local_cache;

	$objcache = array();

	$object = spotEntity('object', $object_id);
	$object['ports'] = pv_getObjectPortsAndLinks ($object_id);

	if($debug)
		echo "\"".$object['name']."\" builing object port list..ID: $object_id<br>";

	/* prepare object */

	/* check ipv4 support */
	$object['IPV4OBJ'] =  considerConfiguredConstraint ($object, 'IPV4OBJ_LISTSRC');

	$object['zone'] = pv_get_zone($object);

	$objcache[$object_id] = $object;

	$portidlist['count'] = -1;
	$portidlist['objects'] = array();

	if($object['IPV4OBJ'])
	{
		/* Add current object to snmp objects (all ports) */
		$portidlist['objects'][" $object_id"]['name'] = $object['name'];
		$portidlist['objects'][" $object_id"]['value'] = 'all';
		$portidlist['objects'][" $object_id"]['ports'] = array();
	}

	$i = 0;
	foreach($object['ports'] as &$port)
	{
		$i++;

		$port_id = $port['id'];
		$remote_port_id = $port['remote_id'];

		$port['number'] = $i;

		// get linked remote ports only
		if($remote_port_id)
		{
			//$remote_object check
			$remote_object_id =  $port['remote_object_id'];
			if(!isset($objcache[$remote_object_id]))
			{
				$remote_object = spotEntity('object', $remote_object_id);
				$remote_object['IPV4OBJ'] = considerConfiguredConstraint ($remote_object, 'IPV4OBJ_LISTSRC');
				$objcache[$remote_object_id] = $remote_object;
			}
			else
				$remote_object = $objcache[$remote_object_id];

			if($remote_object['IPV4OBJ'])
			{
				$portidlist['objects'][" ".$port['remote_object_id']]['name'] = $remote_object['name'];
				$portidlist['objects'][" ".$port['remote_object_id']]['ports'][$remote_port_id] = "remote";
				$pv_cache['port_ids'][$remote_port_id] = pv_getPortInfo($remote_port_id);
			}
		}

		// check link chain
		$lc = new pv_linkchain($port_id, $objcache);
		$objcache = $lc->objcache;

		if($lc->start != $port_id && $lc->start != $remote_port_id)
		{
			$start_port = $lc->ports[$lc->start];
			$remote_object_id =  $start_port['Link']['object_id'];
			if(!isset($objcache[$remote_object_id]))
			{
				echo "NOT IN CAHCE";
				$remote_object = spotEntity('object', $remote_object_id);
				$remote_object['IPV4OBJ'] = considerConfiguredConstraint ($remote_object, 'IPV4OBJ_LISTSRC');
				$objcache[$remote_object_id] = $remote_object;
			}
			else
				$remote_object = $objcache[$remote_object_id];

			if($remote_object['IPV4OBJ'])
			{
				$portidlist['objects'][" ".$start_port['Link']['object_id']]['name'] = $remote_object['name'];
				$portidlist['objects'][" ".$start_port['Link']['object_id']]['ports'][$start_port['Link']['id']] = "start";
				$pv_cache['port_ids'][$lc->start] = $start_port['Link'];
			}
		}

		if($lc->end != $port_id && $lc->end != $remote_port_id)
		{
			$end_port = $lc->ports[$lc->end];
			$remote_object_id =  $end_port['Link']['object_id'];
			if(!isset($objcache[$remote_object_id]))
			{
				$remote_object = spotEntity('object', $remote_object_id);
				$remote_object['IPV4OBJ'] = considerConfiguredConstraint ($remote_object, 'IPV4OBJ_LISTSRC');
				$objcache[$remote_object_id] = $remote_object;
			}
			else
				$remote_object = $objcache[$remote_object_id];

			if($remote_object['IPV4OBJ'])
			{
				$portidlist['objects'][" ".$end_port['Link']['object_id']]['name'] = $remote_object['name'];
				$portidlist['objects'][" ".$end_port['Link']['object_id']]['ports'][$end_port['Link']['id']] = "end";
				$pv_cache['port_ids'][$lc->end] = $end_port['Link'];
			}
		}

		$pv_local_cache[$port_id]['linkchain'] = $lc;

		$pv_cache['port_ids'][$port_id] = $port;
	}

	$pv_cache['portidlist'] = $portidlist;

	return $object;
}

/* ---------------------------------------------------- */

function pv_processajaxobject(&$object, $debug = false)
{
	global $pv_cache;

	$object_id = $object['id'];
	$remote_object_id = $_GET['r_object_id'];

	if($object_id != $remote_object_id)
	{
		$object = spotEntity('object', $remote_object_id);
		$object['IPV4OBJ'] =  considerConfiguredConstraint ($object, 'IPV4OBJ_LISTSRC');

		// TODO pv_get_8021q_domain($object);

		$object['zone'] = pv_get_zone($object);

	}

	$json = array(
			'id' => $object['id'],
			'name' => $object['name'],
			'zone' => $object['zone'],
			//'url' => makeHref(array('page' => 'object', 'object_id' => $object['id'], 'tab' => 'default' )),
			'ports' => array()
		);

	if(!$object['IPV4OBJ'])
	{
		$json['SNMP'] = 0;
		return $json;
	}

	$json['SNMP'] = 1;
	// get snmp data
	$object['iftable'] = pv_getsnmp($object, $debug);

	if(!isset($object['SNMP']))
		return $json;

	if($object_id != $remote_object_id)
	{
		foreach($pv_cache['portidlist']['objects'][$remote_object_id]['ports'] as $port_id => $type)
		{
			$port = $pv_cache['port_ids'][$port_id];
			$port['snmpinfos'] = pv_getportsnmp($object, $port, $debug);

			if($port['snmpinfos'])
				$json['ports'][] = $port;
		}
	}
	else
	{
		foreach($object['ports'] as $port)
		{
			$port['snmpinfos'] = pv_getportsnmp($object, $port, $debug);

			if($port['snmpinfos'])
				$json['ports'][] = $port;
		}
	}

	return $json;
}

function pv_getportsnmp(&$object, $port, $debug = false)
{

	$ipv4 = $object['SNMP']['IP'];
	$zone = $object['zone'];

	$port_name = $port['name'];

	// SNMP up / down
	if(!isset($object['iftable'][$port_name]))
		return false;

	$ifoperstatus = $object['iftable'][$port_name]['status'];

	$ifspeed = $object['iftable'][$port_name]['speed'];

	$ifalias = $object['iftable'][$port_name]['alias'];

	$vlan="";
        $vlan_name="";

        if(isset($object['iftable'][$port_name]['vlan']))
        {
                $vlan = $object['iftable'][$port_name]['vlan'];
                $vlan_name = $object['iftable'][$port_name]['vlan_name'];
        }

        return array(
                'ipv4' => $ipv4,
                'operstatus' => $ifoperstatus,
                'alias' => $ifalias,
                'speed' => $ifspeed,
                'name' => $port_name,
                'vlan' => $vlan,
                'vlan_name' => $vlan_name,
        );

	return $retval;

} // pv_getportsnmp

function pv_getsnmp(&$object, $debug = false)
{

	global $pv_cache;

	$object_id = $object['id'];
	$object_name = $object['name'];

	if(isset($pv_cache['objects'][$object_id]['SNMP']))
	{
		if($debug)
			echo "INFO: No SNMP Object \"$object_name\" ID: $object_id<br>";
		return null;
	}

	if(!$object['IPV4OBJ'])
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

	if(0)
		var_dump_html($snmpconfig);

	if(!$ipv4)
	{
		echo "ERROR: no ip for \"$object_name!!\"<br>";

		return False;
	}

	$object['SNMP']['IP'] = $ipv4;

	if(count($snmpconfig) < 4 )
	{
		echo "SNMP Error: Missing Setting for $object_name ($ipv4)<br>";

		return False;
	}

	if($debug)
		echo "SNMP: get for $object_name ($ipv4)<br>";

	/* SNMP prerequisites successfull */

	$s = new pv_ifxsnmp($snmpconfig[2], $ipv4, $snmpconfig[3], $snmpconfig);

	if(!$s->error)
	{

		/* get snmp data */
		$iftable = $s->getiftable();

		if($debug && $s->error)
			echo $s->getError()."<br>";

		if($iftable)
			return $iftable;
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

} // pv_getsnmp
/* ------------------ */

function pv_get_8021q_domain($object)
{
	if(!isset($object['8021q_domain_id']))
		return  '';

	$dom_id = $object['8021q_domain_id'];

	$result = usePreparedSelectBlade ("SELECT description FROM VLANDomain WHERE id = ?", array ($dom_id));

	$dom_result = $result->fetchAll (PDO::FETCH_COLUMN, 0);

        return $dom_result[0];
}

function pv_get_zone($object)
{
	/* get zone tags */
	$zone = array();

	$tagtree = buildTagChainFromIds(getTagDescendents(getTagByName("Zone")['id']));

        foreach($tagtree as $tag)
        {
		if(tagOnChain($tag, $object['etags']))
			$zone[] = $tag['tag'];
	}
	$zone = implode(",",$zone);

	return $zone;
}

function pv_session_start($debug = false, $msg = "" )
{
	if($debug > 1)
		echo "DEBUG: Session +START".($msg ? " $msg" : "" )."<br>";

	startSession();
}

function pv_session_write_close($debug = false, $msg = "" )
{
	if($debug > 1)
		echo "DEBUG: Session -close".($msg ? " $msg" : "" )."<br>";

	session_write_close();
}

/* --------------------------------------------------------- */


class pv_linkchain {

	public $start = null;
	public $end = null;
	public $init = null;
	public $linked = null;

	public $loop = false;

	public $ports = array();

	public $objcache = null;

	function __construct($port_id, &$objectcache = null)
	{

		$this->init = $port_id;
		$this->objcache = $objectcache;

		// Link
		$this->end = $this->_getlinks($port_id, false);

		if(!$this->loop)
			$this->start = $this->_getlinks($port_id, true);
		else
			$this->start = $this->last;

		if($this->start == $this->end)
			$this->linked = false;
		else
			$this->linked = true;


	}

	//recursive
	function _getlinks($port_id, $back = false)
	{
		if($back)
			$linktable = 'LinkBackend';
		else
			$linktable = 'Link';

		if(isset($this->ports[$port_id][$linktable]))
		{
			$this->loop = true;

			if(!$back)
				$linktable = 'LinkBackend';
			else
				$linktable = 'Link';

			return $this->ports[$this->last][$linktable]['remote_id'];
		}

		$port = pv_getPortInfo($port_id, $linktable);
		$this->ports[$port_id][$linktable] = $port;

		// check object IPv4
		$object_id =  $port['object_id'];
		if(!isset($this->objcache[$object_id]))
		{
			$object = spotEntity('object', $object_id);
			$object['IPV4OBJ'] = considerConfiguredConstraint ($object, 'IPV4OBJ_LISTSRC');
			$this->objcache[$object_id] = $object;
		}
		else
			$object = $this->objcache[$object_id];

		if($object['IPV4OBJ'])
			$this->last = $port_id;

		$remote_id = $this->ports[$port_id][$linktable]['remote_id'];

		if($remote_id)
		{
			/* set reverse link on remote port */
			$this->ports[$remote_id][$linktable] = pv_getPortInfo($remote_id, $linktable);

			return $this->_getlinks($remote_id, !$back);
		}

		return $port_id;
	}

	function getchain()
	{
		$remote_id = $this->start;

		// if not Link use LinkBackend
		$back = $this->ports[$remote_id]['Link']['remote_id'];

		$chain = "";

		for(;$remote_id;)
		{
			$back = !$back;

			if($back)
			{
				$linktable = 'LinkBackend';
				$arrow = ' => ';
			}
			else
			{
				$linktable = 'Link';
				$arrow = ' --> ';
			}

			$port = $this->ports[$remote_id][$linktable];

			$text = $port['object_name']." [".$port['name']."]";

			if($this->init == $remote_id)
				$chain .= "<b>$text</b>";
			else
				$chain .= $text;

			$remote_id = $port['remote_id'];

			if($remote_id)
				$chain .= $arrow."<br>";

			if($this->loop && $remote_id == $this->start)
				return $chain."LOOP!<br>";

		}

		return $chain;
	}

	function getchainhtml()
	{
		$remote_id = $this->start;

		// if not Link use LinkBackend
		$back = $this->ports[$remote_id]['Link']['remote_id'];

		$chain = "<table>";

		for(;$remote_id;)
		{
			$back = !$back;

			if($back)
			{
				$linktable = 'LinkBackend';
				$arrow = ' => ';
			}
			else
			{
				$linktable = 'Link';
				$arrow = ' --> ';
			}

			$port = $this->ports[$remote_id][$linktable];

			if($this->init == $remote_id)
				$chain .= "<tr><td><b>".$port['object_name']."</b></td><td><b> [".$port['name']."]</b></td>";
			else
				$chain .= "<tr><td>".$port['object_name']."</td><td> [".$port['name']."]</td>";

			if($remote_id == $this->start || $remote_id == $this->end)
				$chain .= "<td><div name=\"port${remote_id}-status\"></div></td>";
			else
				$chain .= "<td></td>";

			$remote_id = $port['remote_id'];

			if($remote_id)
				$chain .= "<td>$arrow</td></tr>";
			else
				$chain .= "<td></td></tr>";

			if($this->loop && $remote_id == $this->start)
			{
				$chain .= "LOOP!<br>";
				break;
			}

		}

		$chain .= "</table>";

		return $chain;
	}
} // pv_linkchain

/* --------------------------------------------------------- */

class pv_ifxsnmp extends SNMP {

	public $error = false;

	function __construct($version, $hostname, $community, $security = null)
	{

		switch($version)
		{
			case "1":
			case "v1":
					$version = parent::VERSION_1;
					break;
			case "2":
			case "v2C":
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

	function getiftable()
	{
		$oid_ifindex = '.1.3.6.1.2.1.2.2.1.1'; // iftable
		$oid_ifoperstatus = '.1.3.6.1.2.1.2.2.1.8'; //iftable
		$oid_ifspeed = '.1.3.6.1.2.1.2.2.1.5'; //iftable
		$oid_ifdescr = '.1.3.6.1.2.1.2.2.1.2'; //iftable
		$oid_ifhighspeed = '.1.3.6.1.2.1.31.1.1.1.15'; //ifXtable
		$oid_ifname = '.1.3.6.1.2.1.31.1.1.1.1'; //ifXtable
		$oid_ifalias = '.1.3.6.1.2.1.31.1.1.1.18'; //ifXtable

		$ifindex = $this->walk($oid_ifindex); // iftable

		if($ifindex === FALSE)
		{
			return FALSE;
			exit;
		}

		$ifname = $this->walk($oid_ifname, TRUE); //ifXtable

		if($ifname == false)
			$ifname = $this->walk($oid_ifdescr, TRUE); //ifXtable

		$ifalias = $this->walk($oid_ifalias, TRUE); //ifXtable

		$ifspeed = $this->walk($oid_ifspeed, TRUE); //iftable
		$ifhighspeed = $this->walk($oid_ifhighspeed, TRUE); //ifXtable

		$this->enum_print = false;
		$ifoperstatus = $this->walk($oid_ifoperstatus, TRUE); //iftable

		$retval = array();
		foreach($ifindex as $index)
		{

			$retval[$ifname[$index]]['ifindex'] = $index;

			$retval[$ifname[$index]]['status'] = $ifoperstatus[$index];

			$retval[$ifname[$index]]['alias'] = $ifalias[$index];

			$highspeed = $ifhighspeed[$index];
			if($highspeed)
				$speed = $highspeed;
			else
				$speed = $ifspeed[$index];

			if($speed >= 1000000) // 1Mbit
				$speed /= 1000000;

			$speed = ($speed >= 1000 ? ($speed / 1000)."Gb" : $speed."Mb" );

			$retval[$ifname[$index]]['speed'] = "$speed";

		}

		$this->get8021qvlan($retval);

		if(0)
			var_dump_html($retval);

		return $retval;
	}

	/* append vlan to each port in retval */
        function get8021qvlan(&$retval)
        {
                //$oid_dot1dBasePort =          '.1.3.6.1.2.1.17.1.4.1.1';
                $oid_dot1dBasePortIfIndex =     '.1.3.6.1.2.1.17.1.4.1.2'; // dot1 index -> if index
                $oid_dot1qPvid =                '.1.3.6.1.2.1.17.7.1.4.5.1.1';
                $oid_dot1qVlanStaticName =      '.1.3.6.1.2.1.17.7.1.4.3.1.1';

                // @ supprress warning
                $dot1dbaseportifindex = @$this->walk($oid_dot1dBasePortIfIndex, TRUE);

                if($dot1dbaseportifindex === false)
                {
                        $this->error = true;
                        return;
                }

                $dot1qpvid = $this->walk($oid_dot1qPvid, TRUE);
                $dot1qvlanstaticname = $this->walk($oid_dot1qVlanStaticName, TRUE);

                $ifindexdot1dbaseport = array_flip($dot1dbaseportifindex);

                $ret = array();
                foreach($retval as $ifname => &$port)
                {
                        if(!isset($ifindexdot1dbaseport[$port['ifindex']]))
                                continue;

                        $dot1index = $ifindexdot1dbaseport[$port['ifindex']];
                        $vlan = $dot1qpvid[$dot1index];
                        $retval[$ifname]['vlan'] = $vlan;
                        $retval[$ifname]['vlan_name'] = $dot1qvlanstaticname[$vlan];
                }

        }

}

/* -------------------------------------- */
function var_dump_html(&$var, $text = "")
{
	echo "<pre>$text<br>";
	var_dump($var);
	echo "</pre>";
}
