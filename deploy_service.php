<?php
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    return;
}

require_once ("lib/default_conf.php");
setSystemTimeZone();
header('Content-type: text/html');

try {
    Database::connect($GLOBALS['INTEGRATION_DBSERVER'], $GLOBALS['INTEGRATION_DATABASE'], $GLOBALS['ADMIN_DBUSER'], $GLOBALS['ADMIN_DBPASSWORD']);
} catch (Exception $e) {
    echo htmlentities($e->getMessage()) . '<br>';
    exit(0);
}

$deployFunctions = new DeployFunctions();

$res = $deployFunctions->deployServiceSchema($GLOBALS['INTEGRATION_DBUSER'], $GLOBALS['INTEGRATION_DBPASSWORD']);
foreach ($res as $msg) {
    echo htmlentities($msg) . '<br>';
}
