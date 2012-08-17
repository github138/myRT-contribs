<?php
/*
 * Link Management
 *
 *	Features:
 *		- create links between ports
 *		- create backend links between ports
 *		- visually display links / chains
 *			 e.g.
 * 			(Object)>[port] -- front --> [port]<(Object) == back == > (Object)>[port] -- front --> [port]<(Object)
 * 		- Link object backend ports by name (e.g. handy for patch panels)
 *		- change/create CableID (needs jquery.jeditable.mini.js)
 *		- change/create Port Reservation Comment (needs jquery.jeditable.mini.js)
 *
 *	Usage:
 *		1. select "Link Management" tab
 *		2. you should see link chains of all linked ports
 *		3. to display all ports hit "Show All Ports" in the left upper corner
 *		4. to link all ports with the same name of two different objects use "Link Object Ports by Name"
 *			a. select the other object you want to backend link to
 *			b. "show back ports" gives you the list of possible backend links
 *				!! Important port names have to be the same on both objects !!
 *				e.g. (Current Object):Portname -?-> Portname:(Selected Object)
 *			c. select all backend link to create (Ctrl + a for all)
 *			d. Enter backend CableID for all selected links
 *			e. "Link back" create the backend links
 *		5. If you have an backend link within the same Object the link isn't shown until
 *		   "Expand Backend Links on same Object" is hit
 *
 *
 * INSTALL:
 *
 *	- create LinkBackend Table in your RackTables database

CREATE TABLE `LinkBackend` (
  `porta` int(10) unsigned NOT NULL DEFAULT '0',
  `portb` int(10) unsigned NOT NULL DEFAULT '0',
  `cable` char(64) DEFAULT NULL,
  PRIMARY KEY (`porta`,`portb`),
  UNIQUE KEY `porta` (`porta`),
  UNIQUE KEY `portb` (`portb`),
  CONSTRAINT `LinkBackend-FK-a` FOREIGN KEY (`porta`) REFERENCES `Port` (`id`) ON DELETE CASCADE,
  CONSTRAINT `LinkBackend-FK-b` FOREIGN KEY (`portb`) REFERENCES `Port` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

 * Multilink

CREATE TABLE `LinkBackend` (
  `porta` int(10) unsigned NOT NULL DEFAULT '0',
  `portb` int(10) unsigned NOT NULL DEFAULT '0',
  `cable` char(64) DEFAULT NULL,
  PRIMARY KEY (`porta`,`portb`),
  KEY `LinkBackend_FK_b` (`portb`),
  CONSTRAINT `LinkBackend_FK_a` FOREIGN KEY (`porta`) REFERENCES `Port` (`id`) ON DELETE CASCADE,
  CONSTRAINT `LinkBackend_FK_b` FOREIGN KEY (`portb`) REFERENCES `Port` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8

 *	- copy linkmgmt.php to inc/ directory
 *	- copy jquery.jeditable.mini.js to js/ directory (http://www.appelsiini.net/download/jquery.jeditable.mini.js)
 * 	- add "include 'inc/linkmgmt.php';" to inc/local.php
 *
 * TESTED on FreeBSD 9.0, nginx/1.0.11, php 5.3.9
 *	and RackTables 0.19.11
 *
 * (c)2012 Maik Ehinger <m.ehinger@ltur.de>
 */

/*************************
 * Change Log
 *
 * 15.01.12	new loopdetection
 * 18.01.12	code cleanups
 * 23.01.12	add href to printport
 * 24.01.12	add opHelp
 * 25.01.12	max loop count handling changed
 *		add port label to port tooltip
 * 28.02.12 	changed printportlistrow() first from TRUE to FALSE
 *		add portlist::hasbackend()
 * 29.02.12	fix update cable id for backend links
 *			add linkmgmt_commitUpdatePortLink
 * 04.03.12	add set_reserve_comment and set_link permission handling
 * 18.07.12	add transform:rotate to Back Link image
 * 19.07.12	new Description with usage
 * 01.08.12	fix whitespaces
 *		make portlist::urlparams, urlparamsarray, hasbackend static
 * 03.08.12	fix display order for objects without links
 * 06.08.12	add port count to Link by Name
 *		change "Link by Name" dialog design
 *
 *
 */

/*************************
 * TODO
 *
 * - code cleanups
 * - bug fixing
 *
 * - Multiport support for port types AC-in (16) AC-out (1322) DC (1399)
 * 	allow multi backend links
 *	fetch multi links select ...
 *
 * - cleanup getobjectlist and findspareports function
 *		both use similar sql query
 *
 * - csv list
 *
 * - fix $opspec_list for unlink
 *
 */

require_once 'inc/popup.php';

$tab['object']['linkmgmt'] = 'Link Management';
$tabhandler['object']['linkmgmt'] = 'linkmgmt_tabhandler';
//$trigger['object']['linkmgmt'] = 'linkmgmt_tabtrigger';

$ophandler['object']['linkmgmt']['update'] = 'linkmgmt_opupdate';
$ophandler['object']['linkmgmt']['unlinkPort'] = 'linkmgmt_opunlinkPort';
$ophandler['object']['linkmgmt']['PortLinkDialog'] = 'linkmgmt_opPortLinkDialog';
$ophandler['object']['linkmgmt']['Help'] = 'linkmgmt_opHelp';

/* -------------------------------------------------- */

$lm_multilink_port_types = array(
				16, /* AC-in */
				1322, /* AC-out */
				1399, /* DC */
				);

/* -------------------------------------------------- */

$lm_cache = array(
		'allowcomment' => TRUE, /* RackCode ${op_set_reserve_comment} */
		'allowlink' => TRUE, /* RackCode ${op_set_link} */
		'rackinfo' => array(),
		);

/* -------------------------------------------------- */

//function linkmgmt_tabtrigger() {
//	return 'std';
//} /* linkmgmt_tabtrigger */

/* -------------------------------------------------- */

function linkmgmt_opHelp() {
?>
	<table cellspacing=10><tr><th>Help</th><tr>
		<tr><td width=150></td><td width=150 style="font-weight:bold;color:<?php echo portlist::CURRENT_OBJECT_BGCOLOR; ?>">Current Object</td></tr>
		<tr><td></td><td bgcolor=<?php echo portlist::CURRENT_PORT_BGCOLOR; ?>>[current port]</td></tr>
		<tr><td>front link</td><td>[port]<(Object)</td><td>back link</td></tr>
		<tr><td>back link</td><td>(Object)>[port]</td><td>front link</td></tr>
		<tr><td></td><td><pre>----></pre></td><td>Front link</td></tr>
		<tr><td></td><td><pre>====></pre></td><td>Backend link</td></tr>
		<tr><td></td><td>Link Symbol</td><td>Create new link</td></tr>
		<tr><td></td><td>Cut Symbol</td><td>Delete link</td></tr>

	</table>

<?php
	exit;
} /* opHelp */

/* -------------------------------------------------- */

function linkmgmt_opupdate() {

	if(!isset($_POST['id']))
		exit;

	$ids = explode('_',$_POST['id'],3);
	$retval = strip_tags($_POST['value']);

	if(isset($ids[1])) {
		if(permitted(NULL, NULL, 'set_link'))
			if(isset($ids[2]) && $ids[2] == 'back')
				linkmgmt_commitUpdatePortLink($ids[0], $ids[1], $retval, TRUE);
			else
				linkmgmt_commitUpdatePortLink($ids[0], $ids[1], $retval);
		else
			$retval = "Permission denied!";
	} else {
		if(permitted(NULL, NULL, 'set_reserve_comment'))
			commitUpdatePortComment($ids[0], $retval);
		else
			$retval = "Permission denied!";
	}

	/* return what jeditable should display after edit */
	echo $retval;

	exit;
} /* opupdate */

/* -------------------------------------------------- */

/* similar to commitUpatePortLink in database.php with backend support */
function linkmgmt_commitUpdatePortLink($port_id1, $port_id2, $cable = NULL, $backend = FALSE) {

	/* TODO check permissions */

	if($backend)
		$table = 'LinkBackend';
	else
		$table = 'Link';

	return usePreparedExecuteBlade
		(
			"UPDATE $table SET cable=\"".(mb_strlen ($cable) ? $cable : NULL).
			"\" WHERE ( porta = ? and portb = ?) or (portb = ? and porta = ?)",
			array (
				$port_id1, $port_id2,
				$port_id1, $port_id2)
		);

} /* linkmgmt_commitUpdatePortLink */

/* -------------------------------------------------- */

function linkmgmt_opunlinkPort() {
	$port_id = $_REQUEST['port_id'];
	$linktype = $_REQUEST['linktype'];

	/* check permissions */
	if(!permitted(NULL, NULL, 'set_link')) {
		exit;
	}

	if($linktype == 'back')
		$table = 'LinkBackend';
	else
		$table = 'Link';

	$retval = usePreparedDeleteBlade ($table, array('porta' => $port_id, 'portb' => $port_id), 'OR');

	if($retval == 0)
		echo " Link not found";
	else
		echo " $retval Links deleted";

	header('Location: ?page='.$_REQUEST['page'].'&tab='.$_REQUEST['tab'].'&object_id='.$_REQUEST['object_id']);
	exit;
} /* opunlinkPort */

/* -------------------------------------------------- */

function linkmgmt_oplinkPort() {

	$linktype = $_REQUEST['linktype'];
	$cable = $_REQUEST['cable'];

	/* check permissions */
	if(!permitted(NULL, NULL, 'set_link')) {
		echo("Permission denied!");
		return;
	}

	if(!isset($_REQUEST['link_list'])) {
		//portlist::var_dump_html($_REQUEST);
		$porta = $_REQUEST['port'];
		$portb = $_REQUEST['remote_port'];

		$link_list[] = "${porta}_${portb}";
	} else
		$link_list = $_REQUEST['link_list'];

	foreach($link_list as $link){

		$ids = preg_split('/[^0-9]/',$link);
		$porta = $ids[0];;
		$portb = $ids[1];

		$ret = linkmgmt_linkPorts($porta, $portb, $linktype, $cable);

		error_log("$ret - $porta - $portb");
		$port_info = getPortInfo ($porta);
		$remote_port_info = getPortInfo ($portb);
		showSuccess(
                        sprintf
                        (
                                'Port %s %s successfully linked with port %s %s',
                                formatPortLink ($port_info['id'], $port_info['name'], NULL, NULL),
				$linktype,
                                formatPort ($remote_port_info),
				$linktype
                        )
                );
	}



	addJS (<<<END
window.opener.location.reload(true);
window.close();
END
                , TRUE);

	return;
} /* oplinkPort */

/* -------------------------------------------------- */

/*
 * same as in database.php extendend with linktype
 */
function linkmgmt_linkPorts ($porta, $portb, $linktype, $cable = NULL)
{
        if ($porta == $portb)
                throw new InvalidArgException ('porta/portb', $porta, "Ports can't be the same");

	if($linktype == 'back')
		$table = 'LinkBackend';
	else
		$table = 'Link';

        global $dbxlink;
        $dbxlink->exec ('LOCK TABLES '.$table.' WRITE');
        $result = usePreparedSelectBlade
        (
                'SELECT COUNT(*) FROM '.$table.' WHERE porta IN (?,?) OR portb IN (?,?)',
                array ($porta, $portb, $porta, $portb)
        );

	/* TODO multilink */

        if ($result->fetchColumn () != 0)
        {
                $dbxlink->exec ('UNLOCK TABLES');
                return "$linktype Port ${porta} or ${portb} is already linked";
        }
        $result->closeCursor ();
        if ($porta > $portb)
        {
                $tmp = $porta;
                $porta = $portb;
                $portb = $tmp;
        }
        $ret = FALSE !== usePreparedInsertBlade
        (
                $table,
                array
                (
                        'porta' => $porta,
                        'portb' => $portb,
                        'cable' => mb_strlen ($cable) ? $cable : ''
                )
        );
        $dbxlink->exec ('UNLOCK TABLES');
        $ret = $ret and FALSE !== usePreparedExecuteBlade
        (
                'UPDATE Port SET reservation_comment=NULL WHERE id IN(?, ?)',
                array ($porta, $portb)
        );
        return $ret ? '' : 'query failed';
}

/* -------------------------------------------------- */

/*
 * similar to renderPopupHTML in popup.php
 */
function linkmgmt_opPortLinkDialog() {
//	portlist::var_dump_html($_REQUEST);
header ('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en" style="height: 100%;">
<?php

	$text = '<div style="background-color: #f0f0f0; border: 1px solid #3c78b5; padding: 10px; text-align: center;
 margin: 5px;">';

	if(permitted(NULL,NULL,"set_link"))
		if (isset ($_REQUEST['do_link'])) {
			$text .= getOutputOf ('linkmgmt_oplinkPort');
		}
		else
			if(isset($_REQUEST['byname']))
				$text .= getOutputOf ('linkmgmt_renderPopupPortSelectorbyName');
			else
				$text .= getOutputOf ('linkmgmt_renderPopupPortSelector');
	else
		$text .= "Permission denied!";

        $text .= '</div>';

	echo '<head><title>RackTables pop-up</title>';
        printPageHeaders();
        echo '</head>';
        echo '<body style="height: 100%;">' . $text . '</body>';
?>
</html>
<?php
	exit;
} /* opPortLinkDialog */

/* -------------------------------------------------- */

/*
 * like findSparePorts in popup.php extended with linktype
 */
function linkmgmt_findSparePorts($port_info, $filter, $linktype) {

	/* TODO multilink */

	if($linktype == 'back')
	{
		$linktable = 'Link';
		$linkbacktable = 'LinkBackend';
	}
	else
	{
		$linktable = 'LinkBackend';
		$linkbacktable = 'Link';
	}

	$qparams = array();

	// all ports with no link
	/* port:object -> linked port:object */
	$query = 'SELECT Port.id, CONCAT(RackObject.name, " : ", Port.name,
			IFNULL(CONCAT(" -- ", '.$linktable.'.cable," --> ",lnkPort.name, " : ", lnkObject.name),"") )
		FROM Port';

	$join = ' left join '.$linkbacktable.' on Port.id in ('.$linkbacktable.'.porta,'.$linkbacktable.'.portb)
		left join RackObject on RackObject.id = Port.object_id
		left join '.$linktable.' on Port.id in ('.$linktable.'.porta, '.$linktable.'.portb)
		left join Port as lnkPort on lnkPort.id = (('.$linktable.'.porta ^ '.$linktable.'.portb) ^ Port.id)
		left join RackObject as lnkObject on lnkObject.id = lnkPort.object_id';

	if($linktype == 'front')
	{
		$join .= ' INNER JOIN PortInnerInterface pii ON Port.iif_id = pii.id
			INNER JOIN Dictionary d ON d.dict_key = Port.type';
		// porttype filter (non-strict match)
		$join .= ' INNER JOIN (
			SELECT Port.id FROM Port
			INNER JOIN
			(
				SELECT DISTINCT pic2.iif_id
					FROM PortInterfaceCompat pic2
					INNER JOIN PortCompat pc ON pc.type2 = pic2.oif_id';

                if ($port_info['iif_id'] != 1)
                {
                        $join .= " INNER JOIN PortInterfaceCompat pic ON pic.oif_id = pc.type1 WHERE pic.iif_id = ?";
                        $qparams[] = $port_info['iif_id'];
                }
                else
                {
                        $join .= " WHERE pc.type1 = ?";
                        $qparams[] = $port_info['oif_id'];
                }
                $join .= " AND pic2.iif_id <> 1
			 ) AS sub1 USING (iif_id)
			UNION
			SELECT Port.id
			FROM Port
			INNER JOIN PortCompat ON type1 = type
			WHERE iif_id = 1 and type2 = ?
			) AS sub2 ON sub2.id = Port.id";
			$qparams[] = $port_info['oif_id'];
	}

	 // self and linked ports filter
        $where = " WHERE Port.id <> ? ".
		    "AND $linkbacktable.porta is NULL ";
        $qparams[] = $port_info['id'];

	 // rack filter
        if (! empty ($filter['racks']))
        {
                $where .= 'AND Port.object_id IN (SELECT DISTINCT object_id FROM RackSpace WHERE rack_id IN (' .
                        questionMarks (count ($filter['racks'])) . ')) ';
                $qparams = array_merge ($qparams, $filter['racks']);
        }

	// object_id filterr
        if (! empty ($filter['object_id']))
        {
                $where .= 'AND RackObject.id = ? ';
                $qparams[] = $filter['object_id'];
        }
	else
	// objectname filter
        if (! empty ($filter['objects']))
        {
                $where .= 'AND RackObject.name like ? ';
                $qparams[] = '%' . $filter['objects'] . '%';
        }
        // portname filter
        if (! empty ($filter['ports']))
        {
                $where .= 'AND Port.name LIKE ? ';
                $qparams[] = '%' . $filter['ports'] . '%';
        }

	$query .= $join.$where;

        // ordering
        $query .= ' ORDER BY RackObject.name';

	$result = usePreparedSelectBlade ($query, $qparams);

	$row = $result->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);

	/* [id] => displaystring */
	return $row;

} /* findSparePorts */

/* -------------------------------------------------- */

/*
 * similar to findSparePorts but finds Ports with same name
 */
function linkmgmt_findSparePortsbyName($object_id, $remote_object, $linktype) {

	// all ports with same name on object and remote_object and without existing backend link
	$query = 'select CONCAT(Port.id,"_",rPort.id), CONCAT(RackObject.name, " : ", Port.name, " -?-> ", rPort.name, " : ", rObject.name)
		from Port
		left join LinkBackend on Port.id in (LinkBackend.porta,LinkBackend.portb)
		left join RackObject on RackObject.id = Port.object_id
		left join Port as rPort on rPort.name = Port.Name
		left join RackObject as rObject on rObject.id = rPort.object_id
		left join LinkBackend as rLinkBackend on rPort.id in (rLinkBackend.porta, rLinkBackend.portb)';

	$qparams = array();

	 // self and linked ports filter
        $query .= " WHERE Port.object_id = ? ".
		  "AND rPort.object_id = ? ".
		  "AND LinkBackend.porta is NULL ".
		  "AND rLinkBackend.porta is NULL ";
        $qparams[] = $object_id;
        $qparams[] = $remote_object;

        // ordering
        $query .= ' ORDER BY Port.name';

	$result = usePreparedSelectBlade ($query, $qparams);

	$row = $result->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);

	/* [id] => displaystring */
	return $row;

} /* findSparePortsbyName */

/* -------------------------------------------------- */

/*
 * like renderPopupPortSelector in popup.php extenden with linktype
 */
function linkmgmt_renderPopupPortSelector()
{
        assertUIntArg ('port');
        $port_id = $_REQUEST['port'];
	$linktype = $_REQUEST['linktype'];
	$object_id = $_REQUEST['object_id'];
        $port_info = getPortInfo ($port_id);

        if(isset ($_REQUEST['in_rack']))
		$in_rack = $_REQUEST['in_rack'] != 'off';
	else
		$in_rack = true;

//	portlist::var_dump_html($port_info);
//	portlist::var_dump_html($_REQUEST);

        // fill port filter structure
        $filter = array
        (
                'racks' => array(),
                'objects' => '',
                'object_id' => '',
                'ports' => '',
        );

	$remote_object = NULL;
	if(isset($_REQUEST['remote_object']))
	{
		$remote_object = $_REQUEST['remote_object'];

		if($remote_object != 'NULL')
			$filter['object_id'] = $remote_object;
	}

        if (isset ($_REQUEST['filter-obj']))
                $filter['objects'] = $_REQUEST['filter-obj'];
        if (isset ($_REQUEST['filter-port']))
                $filter['ports'] = $_REQUEST['filter-port'];
        if ($in_rack)
        {
                $object = spotEntity ('object', $port_info['object_id']);
                if ($object['rack_id'])
                        $filter['racks'] = getProximateRacks ($object['rack_id'], getConfigVar ('PROXIMITY_RANGE'));
        }

	$objectlist = array('NULL' => '- Show All -');
	$objectlist = $objectlist + linkmgmt_getObjectsList($port_info, $filter, $linktype, 'default', NULL);

	$spare_ports = linkmgmt_findSparePorts ($port_info, $filter, $linktype);

	$maxsize  = getConfigVar('MAXSELSIZE');
	$objectcount = count($objectlist);

        // display search form
        echo 'Link '.$linktype.' of ' . formatPort ($port_info) . ' to...';
        echo '<form method=POST>';
        startPortlet ($linktype.' Port list filter');
       // echo '<input type=hidden name="module" value="popup">';
       // echo '<input type=hidden name="helper" value="portlist">';

        echo '<input type=hidden name="port" value="' . $port_id . '">';
        echo '<table><tr><td valign="top"><table><tr><td>';

	echo '<table align="center"><tr>';
        echo '<td class="tdleft"><label>Object name:<br><input type=text size=8 name="filter-obj" value="' . htmlspecialchars ($filter['objects'], ENT_QUOTES) . '"></label></td>';
        echo '<td class="tdleft"><label>Port name:<br><input type=text size=6 name="filter-port" value="' . htmlspecialchars ($filter['ports'], ENT_QUOTES) . '"></label></td>';
        echo '<td class="tdleft" valign="bottom"><input type="hidden" name="in_rack" value="off" /><label><input type=checkbox value="1" name="in_rack"'.($in_rack ? ' checked="checked"' : '').'>Nearest racks</label></td>';
        echo '</tr></table>';

	echo '</td></tr><tr><td>';
        echo 'Object name (count ports)<br>';
        echo getSelect ($objectlist, array ('name' => 'remote_object',
						'size' => ($objectcount <= $maxsize ? $objectcount : $maxsize)),
						 $remote_object, FALSE);

	echo '</td></tr></table></td>';
        echo '<td valign="top"><br><input type=submit value="show '.$linktype.' ports"></td>';
        finishPortlet();
        echo '</td><td>';

        // display results
        startPortlet ('Compatible spare '.$linktype.' ports');
	if($linktype == 'back')
		$notlinktype = 'front';
	else
		$notlinktype = 'back';

	echo "spare $linktype Object:Port -- $notlinktype cableID -->  $notlinktype Port:Object<br>";

        if (empty ($spare_ports))
                echo '(nothing found)';
        else
        {
                echo getSelect ($spare_ports, array ('name' => 'remote_port', 'size' => getConfigVar ('MAXSELSIZE')), NULL, FALSE);
                echo "<p>$linktype Cable ID: <input type=text id=cable name=cable>";
                echo "<p><input type='submit' value='Link $linktype' name='do_link'>";
        }
        finishPortlet();
        echo '</td></tr></table>';
        echo '</form>';

} /* linkmgmt_renderPopUpPortSelector */

/* -------------------------------------------------- */

/*
 * similar to renderPopupPortSelector but let you select the destination object
 * and displays possible backend links with ports of the same name
 */
function linkmgmt_renderPopupPortSelectorbyName()
{
	$linktype = $_REQUEST['linktype'];
	$object_id = $_REQUEST['object_id'];

	$object = spotEntity ('object', $object_id);

	$objectlist = linkmgmt_getObjectsList(NULL, NULL, $linktype, 'name', $object_id);

	$objectname = (isset($objectlist[$object_id]) ? $objectlist[$object_id] : $object['name']." (0)" );

	/* remove self from list */
	unset($objectlist[$object_id]);

	if(isset($_REQUEST['remote_object']))
		$remote_object = $_REQUEST['remote_object'];
	else
	{
		/* choose first object from list */
		$keys = array_keys($objectlist);

		if(isset($keys[0]))
			$remote_object = $keys[0];
		else
			$remote_object = NULL;
	}

	if($remote_object)
		$link_list = linkmgmt_findSparePortsbyName($object_id, $remote_object, $linktype);

        // display search form
        echo 'Link '.$linktype.' of ' . formatPortLink($object_id, $objectname, NULL, NULL) . ' Ports by Name to...';
        echo '<form method=POST>';

        echo '<table align="center"><tr><td>';
        startPortlet ('Object list');

	$maxsize  = getConfigVar('MAXSELSIZE');
	$objectcount = count($objectlist);

        echo 'Object name (count ports)<br>';
        echo getSelect ($objectlist, array ('name' => 'remote_object',
						'size' => ($objectcount <= $maxsize ? $objectcount : $maxsize)),
						 $remote_object, FALSE);
        echo '</td><td><input type=submit value="show '.$linktype.' ports>"></td>';
        finishPortlet();

        echo '<td>';
        // display results
        startPortlet ('Possible Backend Link List');
	echo "Select links to create:<br>";
        if (empty ($link_list))
                echo '(nothing found)';
        else
        {
		$linkcount = count($link_list);

                echo getSelect ($link_list, array ('name' => 'link_list[]',
					'multiple' => 'multiple',
					'size' => ($linkcount <= $maxsize ? $linkcount : $maxsize)),
					NULL, FALSE);
                echo "<p>$linktype Cable ID: <input type=text id=cable name=cable>";
                echo "<p><input type='submit' value='Link $linktype' name='do_link'>";
        }
        finishPortlet();
        echo '</td></tr></table>';
        echo '</form>';

} /* linkmgmt_renderPopUpPortSelector */

/* ------------------------------------------------ */

/*
 * returns a list of all objects with unlinked ports
 * type 'default':
	'name':  that match those of src_object_id
 */
function linkmgmt_getObjectsList($port_info, $filter, $linktype, $type = 'default', $src_object_id = NULL) {

	/* TODO multilink ports */

	if($linktype == 'back')
	{
		$linktable = 'Link';
		$linkbacktable = 'LinkBackend';
	}
	else
	{
		$linktable = 'LinkBackend';
		$linkbacktable = 'Link';
	}

	$qparams = array();

	$query = 'SELECT RackObject.id, CONCAT(RackObject.name, " (", count(Port.id), ")") as name
			FROM RackObject';

	$join = ' JOIN Port on RackObject.id = Port.object_id
			LEFT JOIN '.$linkbacktable.' on Port.id in ('.$linkbacktable.'.porta, '.$linkbacktable.'.portb)';

	if($linktype == 'front')
	{
		$join .= ' INNER JOIN PortInnerInterface pii ON Port.iif_id = pii.id
			INNER JOIN Dictionary d ON d.dict_key = Port.type';
		// porttype filter (non-strict match)
		$join .= ' INNER JOIN (
			SELECT Port.id FROM Port
			INNER JOIN
			(
				SELECT DISTINCT pic2.iif_id
					FROM PortInterfaceCompat pic2
					INNER JOIN PortCompat pc ON pc.type2 = pic2.oif_id';

                if ($port_info['iif_id'] != 1)
                {
                        $join .= " INNER JOIN PortInterfaceCompat pic ON pic.oif_id = pc.type1 WHERE pic.iif_id = ?";
                        $qparams[] = $port_info['iif_id'];
                }
                else
                {
                        $join .= " WHERE pc.type1 = ?";
                        $qparams[] = $port_info['oif_id'];
                }
                $join .= " AND pic2.iif_id <> 1
			 ) AS sub1 USING (iif_id)
			UNION
			SELECT Port.id
			FROM Port
			INNER JOIN PortCompat ON type1 = type
			WHERE iif_id = 1 and type2 = ?
			) AS sub2 ON sub2.id = Port.id";
			$qparams[] = $port_info['oif_id'];
	}

	if($type == 'name')
		$join .= ' JOIN Port as srcPort on srcPort.name = Port.Name';
	else
		$join .= ' JOIN Port as srcPort on srcPort.id = Port.id';

	$join .= ' LEFT JOIN '.$linkbacktable.' as srcLinkBackend on srcPort.id in (srcLinkBackend.porta, srcLinkBackend.portb)';

	/* WHERE */
	$where = ' WHERE '.$linkbacktable.'.porta is NULL AND '.$linkbacktable.'.portb is NULL
			AND srcLinkBackend.porta is NULL AND srcLinkBackend.portb is NULL';


	if($src_object_id !== NULL)
	{
		$where .= ' AND srcPort.object_id = ?';
		$qparams[] = $src_object_id;
	}

	if($port_info !== NULL )
	{
		$where .= ' AND srcPort.id != ?';
		$qparams[] = $port_info['id'];
	}

	 // rack filter
        if (! empty ($filter['racks']))
        {
                $where .= 'AND Port.object_id IN (SELECT DISTINCT object_id FROM RackSpace WHERE rack_id IN (' .
                        questionMarks (count ($filter['racks'])) . ')) ';
                $qparams = array_merge ($qparams, $filter['racks']);
        }

	// objectname filter
        if (! empty ($filter['objects']))
        {
                $where .= 'AND RackObject.name like ? ';
                $qparams[] = '%' . $filter['objects'] . '%';
        }

        // portname filter
        if (! empty ($filter['ports']))
        {
                $where .= 'AND Port.name LIKE ? ';
                $qparams[] = '%' . $filter['ports'] . '%';
        }

	$query .= $join.$where;
	$query .= ' GROUP by RackObject.id';
	$query .= ' ORDER by RackObject.Name';

	$result = usePreparedSelectBlade ($query, $qparams);

	$row = $result->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);

	return $row;
}

/* ------------------------------------------------ */

function linkmgmt_tabhandler($object_id) {
	global $lm_cache;

	$target = makeHrefProcess(portlist::urlparams('op','update'));

	addJS('js/jquery.jeditable.mini.js');

	/* TODO  if (permitted (NULL, 'ports', 'set_reserve_comment')) */
	/* TODO Link / unlink permissions  */

	$lm_cache['allowcomment'] = permitted(NULL, NULL, 'set_reserve_comment'); /* RackCode {$op_set_reserve_comment} */
	$lm_cache['allowlink'] = permitted(NULL, NULL, 'set_link'); /* RackCode {$op_set_link} */

	//portlist::var_dump_html($lm_cache);

	/* init jeditable fields/tags */
	if($lm_cache['allowcomment'])
		addJS('$(document).ready(function() { $(".editcmt").editable("'.$target.'",{placeholder : "add comment"}); });' , TRUE);

	if($lm_cache['allowlink'])
		addJS('$(document).ready(function() { $(".editcable").editable("'.$target.'",{placeholder : "edit cableID"}); });' , TRUE);


	/* linkmgmt for current object */
	linkmgmt_renderObjectLinks($object_id);

	/* linkmgmt for every child */
	//$parents = getEntityRelatives ('parents', 'object', $object_id);
	$children = getEntityRelatives ('children', 'object', $object_id); //'entity_id'

	//portlist::var_dump_html($children);

	foreach($children as $child) {
		echo '<h1>Links for Child: '.$child['name'].'</h1>';
		linkmgmt_renderObjectLinks($child['entity_id']);
	}

//	$plist->var_dump_html($plist->list);


	return;

} /* tabhandler */

/* -------------------------------------------------- */
function linkmgmt_renderObjectLinks($object_id) {

	$object = spotEntity ('object', $object_id);
        $object['attr'] = getAttrValues($object_id);

	/* get ports */
	/* calls getObjectPortsAndLinks */
	amplifyCell ($object);

	//$ports = getObjectPortsAndLinks($object_id);
	$ports = $object['ports'];

	/* reindex array so key starts at 0 */
	$ports = array_values($ports);

	/* URL param handling */
	if(isset($_GET['allports'])) {
		$allports = $_GET['allports'];
	} else
		$allports = FALSE;

	if(isset($_GET['allback'])) {
		$allback = $_GET['allback'];
	} else
		$allback = FALSE;

	echo '<table><tr>';

	if($allports) {

		echo '<td width=200><a href="'.makeHref(portlist::urlparams('allports','0','0'))
			.'">Hide Ports without link</a></td>';
	} else
		echo '<td width=200><a href="'.makeHref(portlist::urlparams('allports','1','0'))
			.'">Show All Ports</a></td>';

	echo '<td width=200><span onclick=window.open("'.makeHrefProcess(portlist::urlparamsarray(
                                array('op' => 'PortLinkDialog','linktype' => 'back','byname' => '1'))).'","name","height=700,width=800,scrollbars=yes");><a>Link Object Ports by Name</a></span></td>';

	if($allback) {

		echo '<td width=200><a href="'.makeHref(portlist::urlparams('allback','0','0'))
			.'">Collapse Backend Links on same Object</a></td>';
	} else
		echo '<td width=200><a href="'.makeHref(portlist::urlparams('allback','1','0'))
			.'">Expand Backend Links on same Object</a></td>';

	/* Help */
	echo '<td width=200><span onclick=window.open("'.makeHrefProcess(portlist::urlparamsarray(
                                array('op' => 'Help'))).'","name","height=400,width=500");><a>Help</a></span></td>';

	if(isset($_REQUEST['hl_port_id']))
		$hl_port_id = $_REQUEST['hl_port_id'];
	else
		$hl_port_id = NULL;

	echo '</tr></table>';

	echo '<br><br><table>';

	/*  switch display order depending on backend links */
	$first = portlist::hasbackend($object_id);

	$rowcount = 0;
	foreach($ports as $key => $port) {

		$plist = new portlist($port, $object_id, $allports, $allback);

		if($plist->printportlistrow($first, $hl_port_id, ($rowcount % 2 ? portlist::ALTERNATE_ROW_BGCOLOR : "#ffffff")) )
			$rowcount++;

	}

	echo "</table>";

} /* renderObjectLinks */

/* --------------------------------------------------- */
/* -------------------------------------------------- */

/*
 * Portlist class
 * gets all linked ports to spezified port
 * and prints this list as table row
 *
 */
class portlist {

	public $list = array();

	private $object_id;
	private $port_id;
	private $port;

	private $first_id;
	private $front_count;

	private $last_id;
	private $back_count;

	private $count = 0;

	private $allback = FALSE;

	const B2B_LINK_BGCOLOR = '#d8d8d8';
	const CURRENT_PORT_BGCOLOR = '#ffff99';
	const CURRENT_OBJECT_BGCOLOR = '#ff0000';
	const HL_PORT_BGCOLOR = '#00ff00';
	const ALTERNATE_ROW_BGCOLOR = '#f0f0f0';

	/* TODO multilink */
	/* Possible LOOP detected after count links print only */
	const MAX_LOOP_COUNT = 130;

	private $loopcount;

	function __construct($port, $object_id, $allports = FALSE, $allback = FALSE) {

		$this->object_id = $object_id;

		$this->port = $port;

		$port_id = $port['id'];

		$this->port_id = $port_id;

		$this->first_id = $port_id;
		$this->last_id = $port_id;

		$this->allback = $allback;

		/* Front Port */
		$this->count = 0;
		$this->_getportlist($this->_getportdata($port_id),FALSE);
		$this->front_count = $this->count;

		/* Back Port */
		$this->count = 0;
		$this->_getportlist($this->_getportdata($port_id), TRUE, FALSE);
		$this->back_count = $this->count;

		$this->count = $this->front_count + $this->back_count;


		if(!$allports)
			if($this->count == 0 || ( ($this->count == 1) && (!empty($this->list[$port_id]['back'])) ) ) {
				$this->list = array();
				$this->first_id = NULL;
			}

	//	$this->var_dump_html($this->list);

	} /* __construct */


	/*
         * gets front and back port of src_port
	 * and adds it to the list
	 */
	/* !!! recursive */
	function _getportlist(&$src_port, $back = FALSE, $first = TRUE) {

		$src_port_id = $src_port['id'];

		if($back)
			$linktype = 'back';
		else
			$linktype = 'front';

		if(!empty($src_port[$linktype])) {

			/* multilink */
			foreach($src_port[$linktype] as $src_link) {
				$dst_port_id = $src_link['id'];

				if(!$this->_loopdetect($src_port,$dst_port_id,$src_link,$linktype)) {
					//error_log("no loop $linktype>".$dst_port_id);
					$this->count++;
					$this->_getportlist($this->_getportdata($dst_port_id), !$back, $first);
				}
			}

		} else {
			if($first) {
				$this->first_id = $src_port_id;
			//	$this->front_count = $this->count; /* doesn't work on loops */
			} else {
				$this->last_id = $src_port_id;
			//	$this->back_count = $this->count; /* doesn't work on loops */
			}

		}

	} /* _getportlist */

	/*
	 * as name suggested
	 */
	function _loopdetect(&$src_port, $dst_port_id, &$src_link, $linktype) {

		/* TODO multilink*/
		if(array_key_exists($dst_port_id, $this->list)) {

		//	$dst_port = $this->list[$dst_port_id];

			$src_link['loop'] = $dst_port_id;

		//	echo "LOOP :".$src_port['id']."-->".$dst_port_id;

			return TRUE;

		} else {
			//error_log(__FUNCTION__."$dst_port_id not exists");
			return FALSE;
		}

	} /* _loopdetect */

	/*
	 * get all data for one port
	 *	name, object, front link, back link
	 */
	function &_getportdata($port_id) {
		/* sql bitwise xor: porta ^ portb */
		//select cable, ((porta ^ portb) ^ 4556) as port from Link where (4556 in (porta, portb));

		//error_log("_getportdata $port_id");

		/* TODO single sql ? */

		$result = usePreparedSelectBlade
		(
			'SELECT Port.id, Port.name, Port.label, Port.type, Port.l2address, Port.object_id, Port.reservation_comment,
					RackObject.name as "obj_name"
				 from Port
				 join RackObject on RackObject.id = Port.object_id
				 where Port.id = ?',
				array($port_id)
		);
		$datarow = $result->fetchAll(PDO::FETCH_ASSOC);

		$result = usePreparedSelectBlade
		(
				'SELECT Port.id, Link.cable, Port.name,
				 CONCAT(Link.porta,"_",Link.portb) as link_id from Link
				 join Port
				 where (? in (Link.porta,Link.portb)) and ((Link.porta ^ Link.portb) ^ ? ) = Port.id',
				array($port_id, $port_id)
		);
		$frontrow = $result->fetchAll(PDO::FETCH_ASSOC);

		$result = usePreparedSelectBlade
		(
				'SELECT Port.id, LinkBackend.cable, Port.name,
				 CONCAT(LinkBackend.porta,"_",LinkBackend.portb,"_back") as link_id from LinkBackend
				 join Port
				 where (? in (LinkBackend.porta,LinkBackend.portb)) and ((LinkBackend.porta ^ LinkBackend.portb) ^ ? ) = Port.id',
				array($port_id, $port_id)
		);
		$backrow = $result->fetchAll(PDO::FETCH_ASSOC);

		$retval = $datarow[0];

		if(!empty($frontrow))
			$retval['front']= $frontrow;
		else
			$retval['front'] = array();

		if(!empty($backrow))
			$retval['back'] = $backrow;
		else
			$retval['back'] = array();

	//	$this->var_dump_html($retval);

		/* return reference */
		return ($this->list[$port_id] = &$retval);

	} /* _getportdata */

	/*
	 */
	function printport(&$port) {
		/* set bgcolor for current port */
		if($port['id'] == $this->port_id) {
			$bgcolor = 'bgcolor='.self::CURRENT_PORT_BGCOLOR;
			$idtag = ' id='.$port['id'];
		} else {
			$bgcolor = '';
			$idtag = '';
		}

		$mac = trim(preg_replace('/(..)/','$1:',$port['l2address']),':');

		$title = "Label: ${port['label']}\nMAC: $mac\nPortID: ${port['id']}";

		echo '<td'.$idtag.' align=center '.$bgcolor.' title="'.$title.'"><pre>[<a href="'
			.makeHref(array('page'=>'object', 'tab' => 'linkmgmt', 'object_id' => $port['object_id'], 'hl_port_id' => $port['id']))
			.'#'.$port['id']
			.'">'.$port['name'].'</a>]</pre></td>';

	} /* printport */

	/*
	 */
	function printcomment(&$port) {

		if(!empty($port['reservation_comment'])) {
			$prefix = '<b>Reserved: </b>';
		} else
			$prefix = '';

		echo '<td>'.$prefix.'<i><a class="editcmt" id='.$port['id'].'>'.$port['reservation_comment'].'</a></i></td>';

	} /* printComment */


	/*
	 */
	function printobject($object_id, $object_name) {
		if($object_id == $this->object_id) {
                        $color='color: '.self::CURRENT_OBJECT_BGCOLOR;
                } else {
                        $color='';
                }

                echo '<td><table align=center cellpadding=5 cellspacing=0 border=1><tr><td align=center><a style="font-weight:bold;'
                        .$color.'" href="'.makeHref(array('page'=>'object', 'tab' => 'linkmgmt', 'object_id' => $object_id))
                        .'"><pre>'.$object_name.'</pre></a><pre>'.$this->_getRackInfo($object_id, 'font-size:80%')
                        .'</pre></td></tr></table></td>';

	} /* printobject */

	/*
	 */
	function printlink(&$src_link, $linktype) {

		if($linktype == 'back')
			$arrow = '====>';
		else
			$arrow = '---->';

		/* link */
		echo '<td align=center>';

		echo '<pre><a class="editcable" id='.$src_link['link_id'].'>'.$src_link['cable']
			."</a></pre><pre>$arrow</pre>"
			.$this->_printUnLinkPort($src_link['id'], $src_link, $linktype);

		echo '</td>';
	} /* printlink */

	/*
	 * print cableID dst_port:dst_object
	 */
	function _printportlink($src_port_id, $dst_port_id, &$src_link, $back = FALSE) {

		//$port_id = $src_link['id'];

/*
	DEBUG
		echo "$src_port_id --><br>";
		$this->var_dump_html($src_link);
		echo "-->$dst_port_id<br>";
*/
		$dst_port = $this->list[$dst_port_id];
		$object_id = $dst_port['object_id'];
		$obj_name = $dst_port['obj_name'];

		$loop = FALSE;

		if($back) {
			$linktype = 'back';
		} else {
			$linktype = 'front';
		}

		$sameobject = FALSE;

		if(isset($src_link['loop']))
			$loop = TRUE;

		if($src_link != NULL) {

		/* TODO multilink */
	//	foreach($port[$linktype] as &$link) {

		//	$src_port_id = $dst_port[$linktype]['id'];
			$src_object_id = $this->list[$src_port_id]['object_id'];

			if(!$this->allback && $object_id == $src_object_id && $back) {
				$sameobject = TRUE;
			} else {
				$this->printlink($src_link, $linktype);
			}
	//	}
		} else {
			$this->_LinkPort($dst_port_id, $linktype);

			if(!$back)
				$this->printcomment($dst_port);
		}

		if($back) {
			if(!$sameobject)
				$this->printobject($object_id,$obj_name);
			echo "<td>></td>";

			/* align ports nicely */
			if($dst_port['id'] == $this->port_id)
				echo '</td></tr></table></td><td><table align=left><tr>';
		}

		/* print [portname] */
		$this->printport($dst_port);

		if($loop)
			echo '<td bgcolor=#ff9966>LOOP</td>';

		if(!$back) {

			/* align ports nicely */
			if($dst_port['id'] == $this->port_id)
				echo '</td></tr></table></td><td><table align=left><tr>';

			echo "<td><</td>";
			$this->printobject($object_id,$obj_name);

			if(empty($dst_port['back']))
				$this->_LinkPort($dst_port_id, 'back');
		} else
			if(empty($dst_port['front'])) {
				$this->printcomment($dst_port);
				$this->_LinkPort($dst_port_id, 'front');
			}

		if($loop) {
			if(isset($src_link['loopmaxcount']))
				$reason = " (MAX LOOP COUNT reached)";
			else
				$reason = '';

			showWarning("Possible Loop on Port ($linktype) ".$dst_port['name'].$reason);
			return FALSE;
		}

		return TRUE;

	} /* _printportlink */

	/*
	 * print <tr>..</tr>
	 */
	function printportlistrow($first = TRUE, $hl_port_id = NULL, $rowbgcolor = '#ffffff') {

		$this->loopcount = 0;

		if($this->first_id == NULL)
			return false;

		if($first)
			$id = $this->first_id;
		else
			$id = $this->last_id;


		if($hl_port_id == $this->port_id)
			$hlbgcolor = "bgcolor=".self::HL_PORT_BGCOLOR;
		else
			$hlbgcolor = "bgcolor=$rowbgcolor";

		$link = NULL;

		$port = $this->list[$id];

		$title = "linkcount: ".$this->count." (".$this->front_count."/".$this->back_count.")";

		/* Current Port */
		echo '<tr '.$hlbgcolor.'><td nowrap="nowrap" bgcolor='.self::CURRENT_PORT_BGCOLOR.' title="'.$title.'">'.$this->port['name'].': </td>';

		echo "<td><table align=right><tr><td>";

		/* TODO use linkcount for correct order */

		$back = empty($this->list[$id]['back']);

		$this->_printportlink(NULL, $id, $link, $back);

		$this->_printportlist($id, !$back);
		echo "</td></tr></table></td></tr>";

		/* horizontal line */
                echo '<tr><td height=1 colspan=3 bgcolor=#e0e0e0></td></tr>';

		return true;

	} /* printportlist */

	/*
	 * print <td>
	 * prints all ports in a list starting with start_port_id
	 */
	/* !!! recursive */
	function _printportlist($src_port_id, $back = FALSE) {

                if($back)
                        $linktype = 'back';
                else
                        $linktype = 'front';

		if(!empty($this->list[$src_port_id][$linktype])) {

			$linkcount = count($this->list[$src_port_id][$linktype]);

			if($linkcount > 1)
				echo "<td><table>";

			$lastkey = $linkcount - 1;

			foreach($this->list[$src_port_id][$linktype] as $key => &$link) {

				if($linkcount > 1) {
					echo "<tr style=\"background-color:".( $key % 2 ? self::ALTERNATE_ROW_BGCOLOR : "#ffffff" )."\">";
				}

				$dst_port_id = $link['id'];

				$this->loopcount++;

				if($this->loopcount > self::MAX_LOOP_COUNT) {
				//	$src_port_name = $this->list[$src_port_id]['name'];
				//	$dst_port_name = $this->list[$dst_port_id]['name'];

					$link['loop'] = $dst_port_id;
					$link['loopmaxcount'] = $dst_port_id;

					/* loop warning is handeld in _printportlink() */
					//showWarning("MAX LOOP COUNT reached $src_port_name -> $dst_port_name".self::MAX_LOOP_COUNT);
					//return; /* return after _printportlink */
				}

				if(!$this->_printportlink($src_port_id, $dst_port_id, $link, $back))
				{
					return;
				}

				$this->_printportlist($dst_port_id,!$back);
			
				if($linkcount > 1) {
					echo "</tr>".( $key != $lastkey ? "<tr><td height=1 colspan=100% bgcolor=#c0c0c0><td></tr>" : "");
				}
			}

			if($linkcount > 1)
				echo "</table></td>";
		}
	} /* _printportlist */

	/*
         *  returns linked Row / Rack Info for object_id
         *
         */
        function _getRackInfo($object_id, $style = '') {
                global $lm_cache;

                $rackinfocache = $lm_cache['rackinfo'];

                /* if not in cache get it */
                if(!array_key_exists($object_id,$rackinfocache)) {

                        /* SQL from database.php SQLSchema 'object' */
                        $result = usePreparedSelectBlade
                        (
                                'SELECT rack_id, Rack.name as Rack_name, row_id, RackRow.name as Row_name
                                FROM RackSpace
                                LEFT JOIN EntityLink on RackSpace.object_id = EntityLink.parent_entity_id
                                JOIN Rack on Rack.id = RackSpace.rack_id
                                JOIN RackRow on RackRow.id = Rack.row_id
                                WHERE ( RackSpace.object_id = ? ) or (EntityLink.child_entity_id = ?)
                                ORDER by rack_id asc limit 1',
                                array($object_id, $object_id)
                         );
                         $row = $result->fetchAll(PDO::FETCH_ASSOC);

                        if(!empty($row)) {

                                $rackinfocache[$object_id] = $row[0];
                        }

                }

                $obj = &$rackinfocache[$object_id];

                if(empty($obj))
                        return  '<span style="'.$style.'">Unmounted</span>';
                else
                        return '<a style="'.$style.'" href='.makeHref(array('page'=>'row', 'row_id'=>$obj['row_id'])).'>'.$obj['Row_name']
                                .'</a>/<a style="'.$style.'" href='.makeHref(array('page'=>'rack', 'rack_id'=>$obj['rack_id'])).'>'
                                .$obj['Rack_name'].'</a>';

        } /* _getRackInfo */


	/*
	 * return link symbol
	 *
	 */
       function _LinkPort($port_id, $linktype = 'front') {
		global $lm_cache;

		if(!$lm_cache['allowlink'])
			return;

               $helper_args = array
                        (
                                'port' => $port_id,
                        );

                echo "<td align=center>";

		/*
		if($linktype == 'front') {

                        echo "<span";
                        $popup_args = 'height=700, width=400, location=no, menubar=no, '.
                                'resizable=yes, scrollbars=yes, status=no, titlebar=no, toolbar=no';
                        echo " ondblclick='window.open(\"" . makeHrefForHelper ('portlist', $helper_args);
                        echo "\",\"findlink\",\"${popup_args}\");'";
                        // end of onclick=
                        echo " onclick='window.open(\"" . makeHrefForHelper ('portlist', $helper_args);
                        echo "\",\"findlink\",\"${popup_args}\");'";
                        // end of onclick=
                        echo '>';
                        printImageHREF ('plug', 'Link this port');
                        echo "</span>";

		} else {
		*/
			/* backend link */

			echo '<span onclick=window.open("'.makeHrefProcess(portlist::urlparamsarray(
				array('op' => 'PortLinkDialog','port' => $port_id,'linktype' => $linktype ))).'","name","height=800,width=800");'
                        .'>';
                        $img = getImageHREF ('plug', $linktype.' Link this port');

			if($linktype == 'back')
				$img = str_replace('<img',
					'<img style="transform:rotate(180deg);-o-transform:rotate(180deg);-ms-transform:rotate(180deg);-moz-transform:rotate(180deg);-webkit-transform:rotate(180deg);"',
					$img);

			echo $img;
                        echo "</span>";

	//	}

		echo "</td>";

        } /* _LinkPort */

	/*
	 * return link cut symbol
	 *
         * TODO $opspec_list
	 */
	function _printUnLinkPort($src_port_id, &$src_link, $linktype) {
		global $lm_cache;

		if(!$lm_cache['allowlink'])
			return '';

		$src_port = $this->list[$src_port_id];

		$dst_port = $this->list[$src_link['id']];

		/* use RT unlink for front link, linkmgmt unlink for back links */
		if($linktype == 'back')
			$tab = 'linkmgmt';
		else
			$tab = 'ports';

		return '<a href='.
                               makeHrefProcess(array(
					'op'=>'unlinkPort',
					'port_id'=>$src_port_id,
					'object_id'=>$this->object_id,
					'tab' => $tab,
					'linktype' => $linktype)).
                       ' onclick="return confirm(\'unlink ports '.$src_port['name']. ' -> '.$dst_port['name']
					.' ('.$linktype.') with cable ID: '.$src_link['cable'].'?\');">'.
                       getImageHREF ('cut', $linktype.' Unlink this port').'</a>';

	} /* _printUnLinkPort */


	/*
	 *
         */
	static function urlparams($name, $value, $defaultvalue = NULL) {

                $urlparams = $_GET;

	        if($value == $defaultvalue) {

			/* remove param */
			unset($urlparams[$name]);

		} else {

			$urlparams[$name] = $value;

		}

                return $urlparams;

        } /* urlparams */

	/*
         * $params = array('name' => 'value', ...)
         */
	static function urlparamsarray($params) {

                $urlparams = $_GET;

		foreach($params as $name => $value) {

	                if($value == NULL) {

				/* remove param */
				unset($urlparams[$name]);

			} else {

				$urlparams[$name] = $value;

			}
		}

                return $urlparams;

        } /* urlparamsarray */

	/* */
	static function hasbackend($object_id) {
		/* sql bitwise xor: porta ^ portb */
		//select cable, ((porta ^ portb) ^ 4556) as port from Link where (4556 in (porta, portb));

		$result = usePreparedSelectBlade
		(
				'SELECT count(*) from Port
				 join LinkBackend on (porta = id or portb = id )
				 where object_id = ?',
				array($object_id)
		);
		$retval = $result->fetchColumn();

		return $retval != 0;

	} /* hasbackend */

	/* for debugging only */
	function var_dump_html(&$var) {
		echo "<pre>------------------Start Var Dump -------------------------\n";
		var_dump($var);
		echo "\n---------------------END Var Dump ------------------------</pre>";
	}

} /* portlist */

/* -------------------------------------------------- */

?>
