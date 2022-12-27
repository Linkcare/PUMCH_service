<?php
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

// Link the config params
require_once ("lib/default_conf.php");

setSystemTimeZone();
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    return;
}

// Response is always returned as JSON
header('Content-type: application/json');

$action = $_GET['function'];

$serviceResponse = new ServiceResponse(ServiceResponse::IDLE, 'Request received for action: ' . $action);

$logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], $GLOBALS['LOG_DIR']);
$connectionSuccessful = false;
try {
    Database::connect($GLOBALS['INTEGRATION_DBSERVER'], $GLOBALS['INTEGRATION_DATABASE'], $GLOBALS['INTEGRATION_DBUSER'],
            $GLOBALS['INTEGRATION_DBPASSWORD']);

    // Connect as service user, reusing existing session if possible
    apiConnect(null, $GLOBALS['SERVICE_USER'], $GLOBALS['SERVICE_PASSWORD'], APIRole::SERVICE, $GLOBALS['SERVICE_TEAM'], true, $GLOBALS['LANGUAGE']);
    $connectionSuccessful = true;
} catch (Exception $e) {
    $serviceResponse->setCode(ServiceResponse::ERROR);
    $serviceResponse->setMessage('Error initializing service: ' . $e->getMessage());
    $logger->error($serviceResponse->getMessage());
    echo $serviceResponse->toString();
    exit(1);
}

if ($connectionSuccessful) {
    if ($action == 'fetch_and_import') {
        // This action name is a shortcut for executing 2 actions sequentially
        $actionList = ['fetch_pumch_records', 'import_patients'];
    } else {
        $actionList = [$action];
    }

    foreach ($actionList as $action) {
        $processHistory = new ProcessHistory($action);
        $processHistory->save();

        try {
            switch ($action) {
                case 'import_patients' :
                    $logger->trace('CREATION ADMISSIONS IN CARE PLAN');
                    $service = new ServiceFunctions(LinkcareSoapAPI::getInstance(), PUMCHAPI::getInstance());
                    $serviceResponse = $service->importPatients($processHistory);
                    break;
                case 'fetch_pumch_records' :
                    $logger->trace('IMPORTING PATIENT RECORDS FROM KANGXIN');
                    $service = new ServiceFunctions(LinkcareSoapAPI::getInstance(), PUMCHAPI::getInstance());
                    $serviceResponse = $service->fetchPUMCHRecords($processHistory);
                    break;
                case 'review_day_surgery_enrolled' :
                    $logger->trace('CHECKING EXPIRED ENROLLMENTS IN DAY SURGERY');
                    $service = new ServiceFunctions(LinkcareSoapAPI::getInstance(), PUMCHAPI::getInstance());
                    $serviceResponse = $service->reviewDaySurgeryEnrolled($processHistory);
                    break;
                default :
                    $serviceResponse->setCode(ServiceResponse::ERROR);
                    $serviceResponse->setMessage('function "' . $action . '" not implemented');
                    break;
            }
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage($e->getMessage());
        }

        if ($serviceResponse->getCode() == ServiceResponse::ERROR) {
            $processHistory->setStatus(ProcessHistory::STATUS_FAILURE);
        } else {
            $processHistory->setStatus(ProcessHistory::STATUS_SUCCESS);
        }
        $processHistory->setOutputMessage($serviceResponse->getMessage());
        $processHistory->setEndDate(currentDate());
        $processHistory->save();

        if ($serviceResponse->getCode() == ServiceResponse::ERROR) {
            break;
        }
    }
}

if ($serviceResponse->getCode() == ServiceResponse::ERROR) {
    $logger->error($serviceResponse->getMessage());
} else {
    $logger->trace($serviceResponse->getMessage());
    $details = $processHistory->getLogs();
    foreach ($details as $msg) {
        $logger->error($msg->getMessage(), 2);
    }
}

echo $serviceResponse->toString();
