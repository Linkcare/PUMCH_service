<?php
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    return;
}

require_once ("lib/default_conf.php");
setSystemTimeZone();
header('Content-type: application/json');

try {
    Database::connect($GLOBALS['INTEGRATION_DBSERVER'], $GLOBALS['INTEGRATION_DATABASE'], $GLOBALS['INTEGRATION_DBUSER'],
            $GLOBALS['INTEGRATION_DBPASSWORD']);
    // apiConnect($session);
    apiConnect(null, $GLOBALS['SERVICE_USER'], $GLOBALS['SERVICE_PASSWORD'], 47, $GLOBALS['SERVICE_TEAM'], true);
} catch (APIException $e) {
    exit(1);
} catch (Exception $e) {
    exit(1);
}

$serviceNames = ['import_patients' => 'Import episodes in PHM', 'fetch_kangxin_records' => 'Fetch operations from Kangxin',
        'review_followup_enrolled' => 'Reject expired enrollments in DISCHARGE FOLLOWUP'];

$status = [];
foreach ($serviceNames as $name => $description) {
    $processInfo = ProcessHistory::findLast($name);
    if (!$processInfo) {
        continue;
    }

    $info = new stdClass();
    $info->process = $description;
    $info->status = $processInfo->getStatus();
    $info->date = dateInTimezone($processInfo->getStartDate(), $GLOBALS['DEFAULT_TIMEZONE']);

    $startDate = $processInfo->getStartDate();
    if ($endDate = $processInfo->getEndDate()) {
        $info->duration = (strtotime($endDate) - strtotime($startDate)) . 's';
    } else {
        $info->duration = (strtotime(currentDate()) - strtotime($startDate)) . 's';
    }
    $info->message = $processInfo->getOutputMessage();
    foreach ($processInfo->getLogs() as $log) {
        $item = new stdClass();
        $item->date = dateInTimezone($log->getLogDate(), $GLOBALS['DEFAULT_TIMEZONE']);
        $item->message = $log->getMessage();
        $info->details[] = $item;
    }

    $status[] = $info;
}

echo (json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));