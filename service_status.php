<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    return;
}

require_once ("lib/default_conf.php");
header('Content-type: application/json');

$status = [];

if ($_SESSION['logged_in']) {
    // Check whether the user has been inactive for a long time
    $elapsedSinceLastActivity = microtime(true) - $_SESSION['last_activity'];
    if ($elapsedSinceLastActivity > $GLOBALS['SUPERADMIN_SESSION_EXPIRE']) {
        $_SESSION['last_activity'] = null;
        $_SESSION['logged_in'] = false;
        echo (json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return;
    }
    $_SESSION['last_activity'] = microtime(true);
} else {
    echo (json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return;
}

setSystemTimeZone();

try {
    Database::connect($GLOBALS['INTEGRATION_DBSERVER'], $GLOBALS['INTEGRATION_DATABASE'], $GLOBALS['INTEGRATION_DBUSER'],
            $GLOBALS['INTEGRATION_DBPASSWORD']);
} catch (APIException $e) {
    exit(1);
} catch (Exception $e) {
    exit(1);
}

$serviceNames = ['import_patients' => 'Import operations in PHM', 'fetch_pumch_records' => 'Fetch operations from PUMCH',
        'review_followup_enrolled' => 'Reject expired enrollments in DAY SURGERY FOLLOWUP'];

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