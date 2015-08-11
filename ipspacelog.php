<?php

/********************************************
 *
 * Display the last 100 IP Log Entries
 *
 */

$tab['ipv4space']['log'] = 'Log';
$tabhandler['ipv4space']['log'] = 'ipv4spacelog_tabhandler';

function ipv4spacelog_tabhandler()
{
	$result = usePreparedSelectBlade('SELECT * from IPv4Log order by date desc limit 100');

	$rows = $result->fetchAll(PDO::FETCH_ASSOC);

	startPortlet("Log");

	echo "<center><table>";
	echo "<th>Date</th><th>IP</th><th>User</th><th>Message</th>";

	$odd = true;
	foreach($rows as $row)
	{
		$ip_bin = ip4_int2bin ($row['ip']);
		$straddr = ip4_format ($ip_bin);

		echo "<tr class=\"".($odd ? "row_odd" : "row_even")."\"><td>".$row['date']."</td><td><a href=\"".makeHref (array ('page' => 'ipaddress', 'ip' => $straddr))."\">$straddr</td><td>".$row['user']."</td><td align=\"left\">".$row['message']."</td></tr>";
		$odd = !$odd;
	}
	echo "</table></center>";

	finishPortlet("Log");
}

?>
