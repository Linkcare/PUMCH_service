<?php
require_once ("lib/default_conf.php");
$GLOBALS['SIMULATE_KANGXIN_API'] = true;

function initFile($fileName) {
    $output = fopen($fileName, "w");
    if (!$output) {
        echo ("Error opening file $fileName<br>");
        return null;
    }

    // Header row
    fputs($output, implode('·', getFieldList()) . "\n");

    return $output;
}

/**
 *
 * @param KangxinPatientInfo[] $patients
 * @param unknown $output
 */
function dumpPatientInfo($patients, $output) {
    $fields = getFieldList();

    if (empty($patients)) {
        return;
    }

    $operationFields[] = 'applyOperatNo';
    $operationFields[] = 'operationType';
    $operationFields[] = 'operationDoctor';
    $operationFields[] = 'operationName';
    $operationFields[] = 'operationDate';
    $operationFields[] = 'operationName1';
    $operationFields[] = 'operationName2';
    $operationFields[] = 'operationName3';
    $operationFields[] = 'operationName4';
    $operationFields[] = 'processOrder';

    foreach ($patients as $patient) {
        foreach ($fields as $f) {
            if (in_array($f, $operationFields)) {
                $object = reset($patient->getProcedures());
            } else {
                $object = $patient;
            }
            $methodName = "get" . $f;
            if (method_exists($object, $methodName)) {
                $v = mb_substr($object->{$methodName}(), 0, 50, 'UTF-8');
                $v = str_replace("\n", " / ", $v);
                fputs($output, $v);
            }
            fputs($output, '·');
        }
        fputs($output, "\n");
    }
}

function getFieldList() {
    $fields[] = 'sickId';
    $fields[] = 'sickNum';
    $fields[] = 'residenceNo';
    $fields[] = 'visitNumber';
    $fields[] = 'name';
    $fields[] = 'sex';
    $fields[] = 'birthDate';
    $fields[] = 'ethnicity';
    $fields[] = 'nationality';
    $fields[] = 'idCard';
    $fields[] = 'idCardType';
    $fields[] = 'phone';
    $fields[] = 'contactName';
    $fields[] = 'contactPhone';
    $fields[] = 'relation';
    $fields[] = 'marital';
    $fields[] = 'kind';
    $fields[] = 'profession';
    $fields[] = 'drugAllergy';
    $fields[] = 'admissionTime';
    $fields[] = 'admissionDept';
    $fields[] = 'dischargeTime';
    $fields[] = 'dischargeDept';
    $fields[] = 'doctor';
    $fields[] = 'hospitalAdmission';
    $fields[] = 'responsibleNurse';
    $fields[] = 'hospitalized';
    $fields[] = 'admissionDiag';
    $fields[] = 'dischargeDiag';
    $fields[] = 'dischargeStatus';
    $fields[] = 'dischargeSituation';
    $fields[] = 'dischargeInstructions';
    $fields[] = 'applyOperatNo';
    $fields[] = 'operationType';
    $fields[] = 'operationDoctor';
    $fields[] = 'operationName';
    $fields[] = 'operationDate';
    $fields[] = 'operationName1';
    $fields[] = 'operationName2';
    $fields[] = 'operationName3';
    $fields[] = 'operationName4';
    $fields[] = 'processOrder';
    $fields[] = 'updateTime';

    return $fields;
}

$pageNum = 1;
$pageSize = 1000000;
$api = KangxinAPI::getInstance();

$output = initFile('patients_kangxin.txt');
if (!$output) {
    exit(1);
}

$patients = [];
do {
    try {
        $patients = $api->requestPatientList($pageSize, $pageNum++, '2022-05-01');
        dumpPatientInfo($patients, $output);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} while (count($patients) == $pageSize);
fclose($output);
