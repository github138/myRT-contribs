<?php
//
// Network ping plugin.
// Version 0.4
//
// Written by Tommy Botten Jensen
// patched for Racktables 0.20.3 by Vladimir Kushnir
// patched for Racktables 0.20.5 by Rob Walker
//
// The purpose of this plugin is to map your IP ranges with the reality of your
// network using ICMP.
//
// History
// Version 0.1:  Initial release
// Version 0.2:  Added capability for racktables 0.20.3
// Version 0.3:  Fix to use ip instead of ip_bin when calling ipaddress page
// Version 0.4:  add different ping types 'single', 'curl' and 'local' (see $pingtype)
//
// Requirements:
// You need 'fping' from your local repo or http://fping.sourceforge.net/
// Racktables must be hosted on a system that has ICMP PING access to your hosts
//
// Installation:
// 1)  Copy script to plugins folder as ping.php
// 2)  Install fping if you did not read the "requirements"
// 3)  Adjust the $pingtimeout value below to match your network.


// Depot Tab for objects.
$tab['ipv4net']['ping'] = 'Ping overview';
$tabhandler['ipv4net']['ping'] = 'PingTab';
$ophandler['ipv4net']['ping']['importPingData'] = 'importPingData';
$ophandler['ipv4net']['ping']['executePing'] = 'ping_executePing';


function importPingData() {
 // Stub connection for now :(
}

/*
 * pingtype
 *	'local' : uses fping -g to ping whole network ~4 secs for /24 and pingtimeout = 500
 *	'curl' : uses curl to parallelize ping ~7 secs (depends mostly on web server perfromace) for /24 and pingtimeout = 500
 *	'single' : uses fping ping each ip after another ~116 secs for /24 and pingtimeout = 500
 */
$pingtype = 'local';
$pingtimeout = "50";

/*
 * used to ping one ip address
 * callied by curl requests
 */
function ping_executePing()
{
	global $pingtimeout;
	if(!isset($_GET['ip']))
	{
		echo "Missing ip!";
		exit;
	}

	$straddr = $_GET['ip'];

	$starttime = microtime(true);

	$pingreply = false;

	system("/usr/local/sbin/fping -q -c 1 -t $pingtimeout $straddr",$pingreply);

	$stoptime = microtime(true);

	echo json_encode(array( 'ip' => $straddr, 'pingreply' => $pingreply, 'time' => ($stoptime - $starttime), 'start' => $starttime));

	exit;
}

/*
 * fping whole network
 * 	uses fpings -g option
 */
function ping_localfping($net)
{
	global $pingtimeout;

	$output = array();
	$retval = false;
	$cmd = "/usr/local/sbin/fping -q -C 1 -i 10 -t $pingtimeout -g $net 2>&1";

	$starttime = microtime(true);
	exec($cmd,$output, $retval);
	$stoptime = microtime(true);

	$runtime = $stoptime - $starttime;

	$results = array('runtime' => $runtime);

	$idx = 0;
	foreach($output as $line)
	{
		list($ipaddr, $time) = explode(':',$line);

		$ipaddr = trim($ipaddr);
		$time = trim($time);

		if($time == "-")
			$time = false;

		$idx++;
		$results[$ipaddr] = array('pingreply' => !$time , 'time' => ($time ? $time : $runtime), 'start' => $starttime);
	}

	$results['idx'] = $idx;
	$results['runtime'] = $runtime;

	return $results;
}

/*
 * makes curl request for ervery ip
 *	uses ping_executePing();
 * execution time depends on web server performance ( parallel requests )
 */
function ping_curlfping($startip, $endip)
{
	global $pingtimeout;

	// curl request options
	$curl_opts = array(
			CURLOPT_HEADER => 0,
			CURLOPT_COOKIE => $_SERVER['HTTP_COOKIE'],
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_RETURNTRANSFER => true
			);

	$results = array();
	$ch = array();

	$starttime = microtime(true);

	$cmh = curl_multi_init();
//	curl_multi_setopt($cmh, CURLMOPT_MAXCONNECTS, 10);
//	curl_multi_setopt($cmh, CURLMOPT_PIPELINING, 0);

	$url = ($_SERVER['HTTPS']  == 'on' ? 'https://' : 'http://' ).$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'].'&module=redirect&op=executePing&ip=';
	
	// max parallel requests
	$max_requests = 50;
	$active = null;

	$idx = 0;

	for ($ip = $startip; $ip <= $endip; $ip++)
	{

		// curl ..
		$ip_bin = ip4_int2bin($ip); 
		$straddr = ip4_format ($ip_bin);
		$ch[$straddr] = curl_init();
		curl_setopt_array($ch[$straddr], $curl_opts);
		curl_setopt($ch[$straddr], CURLOPT_URL, $url.$straddr);

		curl_multi_add_handle($cmh,$ch[$straddr]);
		
		$idx++;
	
		if($idx >= $max_requests)
		do
		{

			curl_multi_exec($cmh, $active);
			if(curl_multi_select($cmh))
			{

				$reqinfo = curl_multi_info_read($cmh);
				for(;$reqinfo;)
				{
					if($reqinfo['result'] == CURLE_OK)
					{
						// finished request
						$info = curl_getinfo($reqinfo['handle']);
						if($info['http_code'] == 200)
						{
							$content = curl_multi_getcontent($reqinfo['handle']);
							$json = json_decode($content, true);
							$results[$json['ip']] = $json;
							$results[$json['ip']]['info'] = $info;
						}
						else
							echo "NOT 200<br>";

						if($ip<$endip)
						{
							// curl ..
							$ip++;
							$ip_bin = ip4_int2bin($ip);
							$straddr = ip4_format ($ip_bin);
							$ch[$straddr] = curl_init();
							curl_setopt_array($ch[$straddr], $curl_opts);
							curl_setopt($ch[$straddr], CURLOPT_URL, $url.$straddr);

							curl_multi_add_handle($cmh,$ch[$straddr]);

							$idx++;
							curl_multi_exec($cmh, $active);
						}

						curl_multi_remove_handle($cmh,$reqinfo['handle']);
						$reqinfo = curl_multi_info_read($cmh);
					}
				}
			}
		} while($active >= $max_requests || (($ip >= $endip) && ($active > 0)) );

	}
	//echo "END-->$active<br>";
	curl_multi_close($cmh);

	$stoptime = microtime(true);

	$results['idx'] = $idx;
	$results['runtime'] = $stoptime - $starttime;

	return $results;

}

// Display the ping overview:
function PingTab($id) {

	global $pingtimeout, $pingtype;

	$debug = false; // output timing informations

	if (isset($_REQUEST['pg']))
		$page = $_REQUEST['pg'];
	else
		$page=0;
	global $pageno, $tabno;
	$maxperpage = getConfigVar ('IPV4_ADDRS_PER_PAGE');
	$range = spotEntity ('ipv4net', $id);
	loadIPAddrList ($range);

	echo "<center><h1>${range['ip']}/${range['mask']}</h1><h2>${range['name']}</h2></center>\n";

	echo "<table class=objview border=0 width='100%'><tr><td class=pcleft>";
	startPortlet ('icmp ping comparrison:');
	$startip = ip4_bin2int ($range['ip_bin']);
	$endip = ip4_bin2int (ip_last ($range));
	$realstartip = $startip;
	$realendip = $endip;
	$numpages = 0;
	if ($endip - $startip > $maxperpage)
	{
		$numpages = ($endip - $startip) / $maxperpage;
		$startip = $startip + $page * $maxperpage;
		$endip = $startip + $maxperpage - 1;
	}
	echo "<center>";
	if ($numpages)
		echo '<h3>' . ip4_format (ip4_int2bin ($startip)) . ' ~ ' . ip4_format (ip4_int2bin ($endip)) . '</h3>';
	for ($i=0; $i<$numpages; $i++)
		if ($i == $page)
			echo "<b>$i</b> ";
		else
			echo "<a href='".makeHref(array('page'=>$pageno, 'tab'=>$tabno, 'id'=>$id, 'pg'=>$i))."'>$i</a> ";
	echo "</center>";

	echo "<table class='widetable' border=0 cellspacing=0 cellpadding=5 align='center'>\n";
	echo "<tr><th>address</th><th>name</th><th>response</th></tr>\n";
	$box_counter = 1;
	$cnt_ok = $cnt_noreply = $cnt_mismatch = 0;
	$start_totaltime = microtime(true);

	$results = array();

	switch($pingtype)
	{
		case 'local':
			echo "using local ping";
			$results = ping_localfping($range['ip']."/".$range['mask']);
			break;
		case 'curl':
			echo "using curl ping";
			$results = ping_curlfping($startip, $endip);
			break;
		default:
			echo "using single ping";
			$start_runtime = microtime(true);
			$idx = 0;
			// singel
			for ($ip = $startip; $ip <= $endip; $ip++)
			{
				$idx++;
				$ip_bin = ip4_int2bin($ip);
				$straddr = ip4_format ($ip_bin);
				$pingreplay = false;
				$starttime = microtime(true);
				system("/usr/local/sbin/fping -q -c 1 -t $pingtimeout $straddr",$pingreply);
				$stoptime = microtime(true);
				$results[$straddr] = array( 'pingreply' => $pingreply, 'time' => $stoptime - $starttime, 'start' => $starttime);
			}
			$stop_runtime = microtime(true);
			$results['idx'] = $idx;
			$results['runtime'] = $stop_runtime - $start_runtime;

	}

	$idx = $results['idx'];

	// print results
	for ($ip = $startip; $ip <= $endip; $ip++)
	{
		$ip_bin = ip4_int2bin($ip); 
		$straddr = ip4_format ($ip_bin);
		$addr = isset ($range['addrlist'][$ip_bin]) ? $range['addrlist'][$ip_bin] : array ('name' => '', 'reserved' => 'no');

		if(!isset($results[$straddr]))
			$results[$straddr] = array('pingreply' => true, 'time' => "-", 'start' => '-'); // pingreply true = no reply/timeout

		if($pingtype == 'curl')
			if($results[$straddr]['info']['http_code'] != 200)
			{
				echo "$addr: HTTP Response: ".$results[$straddr]['info']['http_code']."<br>";
				continue;
			}

		$pingreply = $results[$straddr]['pingreply'];


		// FIXME: This is a huge and ugly IF/ELSE block. Prettify anyone?
		if (!$pingreply) {
			if ( (!empty($addr['name']) and ($addr['reserved'] == 'no')) or (!empty($addr['allocs']))) {
				echo '<tr class=trok';
				$cnt_ok++;
			}
			else {
				echo ( $addr['reserved'] == 'yes' ) ? '<tr class=trwarning':'<tr class=trerror';
				$cnt_mismatch++;
			}
		}
		else {
			if ( (!empty($addr['name']) and ($addr['reserved'] == 'no')) or !empty($addr['allocs']) ) {
				echo '<tr class=trwarning';
				$cnt_noreply++;
			}
			else {
				echo '<tr';
			}
		}			
		echo "><td class='tdleft";
		if (isset ($range['addrlist'][$ip_bin]['class']) and strlen ($range['addrlist'][$ip_bin]['class']))
			echo ' ' . $range['addrlist'][$ip_bin]['class'];
		echo "'><a href='".makeHref(array('page'=>'ipaddress', 'ip'=>$straddr))."'>${straddr}</a></td>";
		echo "<td class=tdleft>${addr['name']}</td><td class=tderror>";
		if (!$pingreply)
			echo "Yes";
		else
			echo "No";
		if($debug)
		{
			echo "</td><td>".$results[$straddr]['time']."</td>";
			echo "<td>".$results[$straddr]['start'];
			if($pingtype == 'curl')
				echo "</td><td>".$results[$straddr]['info']['total_time'];
		}
		echo "</td></tr>\n";
	}

	echo "</td></tr>";
	echo "</table>";
	echo "</form>";
	finishPortlet();

	echo "</td><td class=pcright>";

	startPortlet ('stats');
	echo "<table border=0 width='100%' cellspacing=0 cellpadding=2>";
	echo "<tr class=trok><th class=tdright>OKs:</th><td class=tdleft>${cnt_ok}</td></tr>\n";
	echo "<tr class=trwarning><th class=tdright>Did not reply:</th><td class=tdleft>${cnt_noreply}</td></tr>\n";
	if ($cnt_mismatch)
		echo "<tr class=trerror><th class=tdright>Unallocated answer:</th><td class=tdleft>${cnt_mismatch}</td></tr>\n";
	echo "</table>\n";
	finishPortlet();

	echo "</td></tr></table>\n";

	if($debug)
	{
		$stop_totaltime = microtime(true);
		echo "Total Ping Run Time: ".$results['runtime']."<br>";
		echo "Total Page Time: ".($stop_totaltime - $start_totaltime)."<br>";
	}

}
?>
