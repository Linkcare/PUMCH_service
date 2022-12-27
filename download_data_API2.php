<?php
require_once ("lib/default_conf.php");
$GLOBALS['SIMULATE_KANGXIN_API'] = false;

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

function dumpPatientInfo($patients, $output) {
    $fields = getFieldList();

    if (empty($patients)) {
        return;
    }

    foreach ($patients as $patient) {
        foreach ($fields as $f) {
            $methodName = "get" . $f;
            if (method_exists($patient, $methodName)) {
                $v = mb_substr($patient->{$methodName}(), 0, 50, 'UTF-8');
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
    $fields[] = 'name';
    $fields[] = 'sex';
    $fields[] = 'birthDate';
    $fields[] = 'age';
    $fields[] = 'currentAddress';
    $fields[] = 'nation';
    $fields[] = 'identityNumber';
    $fields[] = 'phone';
    $fields[] = 'contactName';
    $fields[] = 'contactPhone';
    $fields[] = 'realtion';
    $fields[] = 'sickNum';
    $fields[] = 'residenceNo';
    $fields[] = 'nthHosptial';
    $fields[] = 'actualHospitalDays';
    $fields[] = 'admissionTime';
    $fields[] = 'admissionDepartment';
    $fields[] = 'admissionWard';
    $fields[] = 'hospitalized';
    $fields[] = 'operationLevel';
    $fields[] = 'operationSurgeon';
    $fields[] = 'operationCode';
    $fields[] = 'operationDate';
    $fields[] = 'operationName';
    $fields[] = 'operationType';
    $fields[] = 'operationName1';
    $fields[] = 'operationName2';
    $fields[] = 'operationName3';
    $fields[] = 'operationName4';
    $fields[] = 'drugAllergy';
    $fields[] = 'doctor';
    $fields[] = 'responsibleNurse';
    $fields[] = 'dischargeTime';
    $fields[] = 'dischargeDepartment';
    $fields[] = 'dischargeWard';
    $fields[] = 'dischargeDiseaseCode';
    $fields[] = 'dischargeMainDiagnosis';
    $fields[] = 'dischargeInstructions';
    $fields[] = 'dischargeOtherDiagnoses';
    $fields[] = 'dischargeOtherDiagnoses';
    $fields[] = 'otherDiseaseCodes';
    $fields[] = 'note';

    return $fields;
}

$pageNum = 1;
$pageSize = 20;
$api = KangxinAPI::getInstance();

$output = initFile('patients_kangxin.txt');
if (!$output) {
    exit(1);
}

$patients = [];
do {
    try {
        $patients = $api->requestPatientList($pageSize, $pageNum++);
        dumpPatientInfo($patients, $output);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} while (count($patients) == $pageSize);
fclose($output);
