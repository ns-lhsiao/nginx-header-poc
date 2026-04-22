#!/usr/bin/php
<?php
require_once(__DIR__ . "/helper_functions.php");

function usage() {
	echo "Usage: php scanOrphanSteerexcepRis.php [-o <output_dir>] [-t <tenant_id>]\n";
	echo "  -o  Output directory for per-tenant orphan reports (default: ./orphan_steerexcep_output)\n";
	echo "  -t  Optional: scan a single tenant ID instead of all tenants\n";
	echo "\nMySQL credentials are read from /opt/ns/common/remote/mysql via helper_functions.php\n";
	echo "\nThis script finds steering exception entries in RIS whose setting_id no longer exists in bypass_settings_v2.\n";
}

function getAllTenantDBNames($db) {
	$sql = "SELECT tenant_id, ui_hostname, dbname FROM org_info";
	$result = $db->query($sql);
	if (!$result) {
		echo "ERROR: Failed to query org_info: {$db->error}\n";
		return array();
	}

	$retval = array();
	while ($row = $result->fetch_assoc()) {
		$obj = new stdClass();
		$obj->tenant_id = $row['tenant_id'];
		$obj->dbname = $row['dbname'];
		$obj->ui_hostname = $row['ui_hostname'];
		$retval[] = $obj;
	}
	$result->close();
	return $retval;
}

function getRisSteerexcepIds($tenantId) {
	$allSettingIds = array();
	$offset = 0;
	$limit = 200;

	while (true) {
		$url = "http://referential-integrity-service.swg-mp/internal/v1/objectconsumers/steerexcep?limit={$limit}&offset={$offset}";
		$headers = array(
			"x-netskope-tenantid: {$tenantId}",
			"x-netskope-caller-id: webui-php"
		);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $httpCode !== 200) {
			return array('error' => "RIS API failed (HTTP {$httpCode})", 'ids' => array());
		}

		$data = json_decode($response, true);
		if (!$data || !isset($data['elements'])) {
			return array('error' => "RIS API returned invalid JSON", 'ids' => array());
		}

		foreach ($data['elements'] as $element) {
			if (isset($element['setting_id'])) {
				$allSettingIds[] = $element['setting_id'];
			}
		}

		$totalCount = isset($data['total_count']) ? (int)$data['total_count'] : 0;
		$offset += $limit;

		if ($offset >= $totalCount || count($data['elements']) < $limit) {
			break;
		}
	}

	return array('error' => null, 'ids' => $allSettingIds, 'total' => $totalCount);
}

function getDbSteerexcepIds($db) {
	$sql = "SELECT id FROM bypass_settings_v2";
	$result = $db->query($sql);
	if (!$result) {
		return array('error' => "DB query failed: {$db->error}", 'ids' => array());
	}

	$ids = array();
	while ($row = $result->fetch_assoc()) {
		$ids[] = (string)$row['id'];
	}
	$result->close();
	return array('error' => null, 'ids' => $ids);
}

function dbTableExists($db, $tableName) {
	$query_str = "SHOW TABLES LIKE '" . $tableName . "'";
	$query_results = $db->query($query_str);
	return $query_results && $query_results->num_rows == 1;
}

// --- Main ---

$options = getopt("o:t:h");
if (isset($options['h'])) {
	usage();
	exit(0);
}

$outputDir = isset($options['o']) ? $options['o'] : __DIR__ . "/orphan_steerexcep_output";
$singleTenantId = isset($options['t']) ? $options['t'] : null;

if (!is_dir($outputDir)) {
	mkdir($outputDir, 0755, true);
}

$coreDB = getMysqlConnection("core_data");
if ($coreDB->connect_errno) {
	die("Failed to connect to core_data\n");
}

$allTenants = getAllTenantDBNames($coreDB);
$coreDB->close();

if ($singleTenantId) {
	$allTenants = array_filter($allTenants, function($t) use ($singleTenantId) {
		return $t->tenant_id == $singleTenantId;
	});
	if (empty($allTenants)) {
		die("Tenant ID {$singleTenantId} not found in org_info\n");
	}
	$allTenants = array_values($allTenants);
}

$totalTenants = count($allTenants);
$tenantsWithOrphans = 0;
$tenantsSkipped = 0;
$tenantsClean = 0;
$tenantsError = 0;
$summaryLines = array();

echo "Scanning {$totalTenants} tenants for orphan steerexcep RIS entries...\n";
echo "Output directory: {$outputDir}\n";
echo "================================================================================\n\n";

for ($i = 0; $i < $totalTenants; $i++) {
	$tenant = $allTenants[$i];
	$tenantId = $tenant->tenant_id;
	$dbName = $tenant->dbname;
	$hostname = $tenant->ui_hostname;
	$progress = ($i + 1) . "/{$totalTenants}";

	echo "[{$progress}] Tenant {$tenantId} ({$hostname})... ";

	defineMysqlVars();
	$tenantDB = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, $dbName, MYSQL_PORT);
	if ($tenantDB->connect_errno) {
		echo "SKIP (DB connection failed)\n";
		$tenantsError++;
		$summaryLines[] = "{$tenantId},{$hostname},{$dbName},ERROR,DB connection failed";
		continue;
	}

	if (!dbTableExists($tenantDB, 'bypass_settings_v2')) {
		$tenantDB->close();
		echo "SKIP (no bypass_settings_v2 table)\n";
		$tenantsSkipped++;
		$summaryLines[] = "{$tenantId},{$hostname},{$dbName},SKIP,no bypass_settings_v2";
		continue;
	}

	$risResult = getRisSteerexcepIds($tenantId);
	if ($risResult['error']) {
		$tenantDB->close();
		echo "ERROR ({$risResult['error']})\n";
		$tenantsError++;
		$summaryLines[] = "{$tenantId},{$hostname},{$dbName},ERROR,{$risResult['error']}";
		continue;
	}

	$risIds = $risResult['ids'];
	$risTotal = isset($risResult['total']) ? $risResult['total'] : count($risIds);

	if (empty($risIds)) {
		$tenantDB->close();
		echo "CLEAN (0 RIS entries)\n";
		$tenantsClean++;
		$summaryLines[] = "{$tenantId},{$hostname},{$dbName},CLEAN,0 RIS entries";
		continue;
	}

	$dbResult = getDbSteerexcepIds($tenantDB);
	$tenantDB->close();

	if ($dbResult['error']) {
		echo "ERROR ({$dbResult['error']})\n";
		$tenantsError++;
		$summaryLines[] = "{$tenantId},{$hostname},{$dbName},ERROR,{$dbResult['error']}";
		continue;
	}

	$dbIds = $dbResult['ids'];
	$dbIdSet = array_flip($dbIds);

	$orphanIds = array();
	foreach ($risIds as $risId) {
		if (!isset($dbIdSet[$risId])) {
			$orphanIds[] = $risId;
		}
	}

	$dbCount = count($dbIds);

	if (empty($orphanIds)) {
		echo "CLEAN (RIS:{$risTotal}, DB:{$dbCount})\n";
		$tenantsClean++;
		$summaryLines[] = "{$tenantId},{$hostname},{$dbName},CLEAN,RIS:{$risTotal} DB:{$dbCount}";
	} else {
		$orphanCount = count($orphanIds);
		echo "ORPHANS FOUND: {$orphanCount} (RIS:{$risTotal}, DB:{$dbCount})\n";
		$tenantsWithOrphans++;
		$summaryLines[] = "{$tenantId},{$hostname},{$dbName},ORPHAN,{$orphanCount} orphans (RIS:{$risTotal} DB:{$dbCount})";

		$reportFile = $outputDir . "/{$tenantId}.txt";
		$report = "Tenant ID: {$tenantId}\n";
		$report .= "Hostname: {$hostname}\n";
		$report .= "Database: {$dbName}\n";
		$report .= "Scan Time: " . date('Y-m-d H:i:s T') . "\n";
		$report .= "RIS steerexcep count: {$risTotal}\n";
		$report .= "DB bypass_settings_v2 count: {$dbCount}\n";
		$report .= "Orphan count: {$orphanCount}\n";
		$report .= "================================================================================\n";
		$report .= "Orphan setting_ids (in RIS but NOT in bypass_settings_v2):\n";
		foreach ($orphanIds as $id) {
			$report .= "  {$id}\n";
		}
		file_put_contents($reportFile, $report);
	}
}

echo "\n================================================================================\n";
echo "SUMMARY\n";
echo "================================================================================\n\n";
echo "Total tenants scanned: {$totalTenants}\n";
echo "Tenants with orphans:  {$tenantsWithOrphans}\n";
echo "Tenants clean:         {$tenantsClean}\n";
echo "Tenants skipped:       {$tenantsSkipped}\n";
echo "Tenants with errors:   {$tenantsError}\n";

$summaryFile = $outputDir . "/summary.csv";
$csvContent = "tenant_id,hostname,dbname,status,detail\n";
foreach ($summaryLines as $line) {
	$csvContent .= $line . "\n";
}
file_put_contents($summaryFile, $csvContent);
echo "\nSummary written to: {$summaryFile}\n";

if ($tenantsWithOrphans > 0) {
	echo "\nTenants with orphan entries:\n";
	echo "----------------------------------------------------------------\n";
	foreach ($summaryLines as $line) {
		if (strpos($line, ',ORPHAN,') !== false) {
			$parts = explode(',', $line, 5);
			echo "  Tenant {$parts[0]} ({$parts[1]}): {$parts[4]}\n";
		}
	}
}

echo "\n================================================================================\n";
echo "Per-tenant orphan reports written to: {$outputDir}/\n";
echo "================================================================================\n";
