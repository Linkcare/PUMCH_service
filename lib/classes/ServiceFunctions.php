<?php

/**
 * ******************************** REST FUNCTIONS *********************************
 */
class ServiceFunctions {
    /** @var LinkcareSoapAPI */
    private $apiLK;
    /** @var PUMCHAPI */
    private $apiPUMCH;

    /** @var APIUser[] List of professionals cached in memory */
    private $professionals = [];

    /* Other Constants */
    const PATIENT_HISTORY_TASK_CODE = 'PUMCH_IMPORT';
    const EPISODE_FORM_CODE = 'PUMCH_IMPORT_FORM';
    const EPISODE_CHANGE_EVENT_CODE = 'EVENT_EPISODE_UPDATE';

    /**
     *
     * @param LinkcareSoapAPI $apiLK
     * @param PUMCHAPI $apiPUMCH
     */
    public function __construct($apiLK, $apiPUMCH) {
        $this->apiLK = $apiLK;
        $this->apiPUMCH = $apiPUMCH;
    }

    /**
     * Retrieves the complete list of episodes from PUMCH Hospital and stores the records in an intermediate table indicating which ones have
     * changed
     *
     * @param ProcessHistory $processHistory
     * @return ServiceResponse;
     */
    public function fetchPUMCHRecords($processHistory) {
        $serviceResponse = new ServiceResponse(ServiceResponse::IDLE, 'Process started');
        /* Time between requests to the KNGXIN API to avoid blocking the server */
        $this->apiPUMCH->setDelay($GLOBALS['PUMCH_REQUEST_DELAY']);

        $processed = 0;
        $importFailed = 0;
        $newRecords = 0;
        $updatedRecords = 0;
        $ignoredRecords = 0;
        $errMsg = null;

        $fromDate = RecordPool::getLastUpdateTime();
        if (!$fromDate) {
            /*
             * The DB is empty, so this is the first time we are fetching records from PUMCH.
             * We will use the preconfigured minimum date.
             */
            $fromDate = $GLOBALS['MINIMUM_DATE'];
        }

        try {
            $operationsFetched = $this->apiPUMCH->requestPatientList($fromDate);
            $maxRecords = count($operationsFetched);
            ServiceLogger::getInstance()->debug("Patients requested to PUMCH from date $fromDate: $maxRecords");
        } catch (Exception $e) {
            $maxRecords = 0;
            $errMsg = 'ERROR in the request to the PUMCH API: ' . $e->getMessage();
            $processHistory->addLog($errMsg);
            ServiceLogger::getInstance()->error($errMsg);
        }

        $page = 1;
        $pageSize = 20;
        while ($processed < $maxRecords) {
            // We will process the records by pages to update the ProcessHistory so that a progress message is added per each page processed
            $patientsToImport = array_slice($operationsFetched, ($page - 1) * $pageSize, $pageSize);

            foreach ($patientsToImport as $patientInfo) {
                /** @var PUMCHEpisodeInfo $patientInfo */
                ServiceLogger::getInstance()->debug(
                        'Processing patient ' . sprintf('%03d', $processed) . ': ' . $patientInfo->getName() . ' (SickId: ' .
                        $patientInfo->getPatientId() . ', Episode: ' . $patientInfo->getEpisodeId() . ')', 1);
                try {
                    $ret = $this->processFetchedRecord($patientInfo);
                    switch ($ret) {
                        case 0 :
                            $ignoredRecords++;
                            break;
                        case 1 :
                            $newRecords++;
                            break;
                        case 2 :
                            $updatedRecords++;
                            break;
                    }
                } catch (Exception $e) {
                    $importFailed++;
                    $processHistory->addLog($e->getMessage());
                    ServiceLogger::getInstance()->error($e->getMessage(), 2);
                }
                $processed++;
                if ($processed >= $maxRecords) {
                    break;
                }
            }
            $page++;

            // Save progress log
            $progress = round(100 * ($maxRecords ? $processed / $maxRecords : 1), 1);
            $outputMessage = "Processed from $fromDate: $processed ($progress%), New: $newRecords, Updated: $updatedRecords, Ignored: $ignoredRecords, Failed: $importFailed";
            $processHistory->setOutputMessage($outputMessage);
            $processHistory->save();
        }

        if ($errMsg || $importFailed > 0) {
            $serviceResponse->setCode($serviceResponse::ERROR);
        }

        $outputMessage = "Processed from $fromDate: $processed ($progress%), New: $newRecords, Updated: $updatedRecords, Ignored: $ignoredRecords, Failed: $importFailed";
        $serviceResponse->setMessage($outputMessage);
        return $serviceResponse;
    }

    /**
     * Imports in the Linkcare Platform al the changed records received from PUMCH
     *
     * @param ProcessHistory $processHistory
     * @return ServiceResponse
     */
    public function importPatients($processHistory) {
        $serviceResponse = new ServiceResponse(ServiceResponse::IDLE, 'Process started');

        // Verify the existence of the required SUBSCRIPTIONS
        try {
            // Locate the SUBSCRIPTION for storing episode information
            $kxEpisodesSubscription = $this->apiLK->subscription_get($GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'], $GLOBALS['TEAM_CODE']);
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage(
                    'ERROR LOADING SUBSCRIPTION (Care plan: ' . $GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'] . ', Team: ' . $GLOBALS['TEAM_CODE'] .
                    ') FOR IMPORTING PATIENTS: ' . $e->getMessage());
            $processHistory->addLog($serviceResponse->getMessage());
            return $serviceResponse;
        }

        try {
            // Locate the SUBSCRIPTION of the "Day Surgery" PROGRAM for creating new ADMISSIONS of a patient
            $daySurgerySubscription = $this->apiLK->subscription_get($GLOBALS['DAY_SURGERY_PROGRAM_CODE'], $GLOBALS['TEAM_CODE']);
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage(
                    'ERROR LOADING SUBSCRIPTION (Care plan: ' . $GLOBALS['DAY_SURGERY_PROGRAM_CODE'] . ', Team: ' . $GLOBALS['TEAM_CODE'] .
                    ') FOR DAY SURGERY PATIENTS: ' . $e->getMessage());
            $processHistory->addLog($serviceResponse->getMessage());
            return $serviceResponse;
        }

        $MaxEpisodes = $GLOBALS['PATIENT_MAX'];
        if ($MaxEpisodes <= 0) {
            $MaxEpisodes = 10000000;
        }

        $page = 1;
        $pageSize = $GLOBALS['PATIENT_PAGE_SIZE'];
        $processed = 0;
        $importFailed = [];
        $success = [];
        $totalExpectedEpisodes = RecordPool::countTotalChanged();
        ServiceLogger::getInstance()->debug('Process a maximum of: ' . $MaxEpisodes . ' episodes');

        // Reset import errors from previous executions and try to process again
        RecordPool::resetErrors();

        // Start process loop
        while ($processed < $MaxEpisodes) {
            /*
             * Retrieve the list of episodes received from PUMCH marked as "changed"
             * We always request for page 1 because as long as we process each page, the processed episodes are marked as "not changed", so when we do
             * the next request, the rest of episodes have shifted to the first page
             */
            $changedEpisodes = RecordPool::loadChanged($pageSize, 1);

            ServiceLogger::getInstance()->debug("Episodes requested: $pageSize (page $page), received: " . count($changedEpisodes));
            $page++;
            if (count($changedEpisodes) < $pageSize) {
                // We have reached the last page, because we received less episodes than the requested
                $MaxEpisodes = $processed + count($changedEpisodes);
            }
            foreach ($changedEpisodes as $episodeOperations) {
                /** @var RecordPool[] $episodeOperations */
                /*
                 * The information received from each clinical episode consists on several records, where each record contains the
                 * information about one intervention.
                 * First of all we will create a single Patient object with all the operations of the episode.
                 * Additionally, whenever we receive updated information about the episode, we want to track the changes received so that we can
                 * inform the Case Manager about wich things have changed. For that reason, we have a copy of the last information loaded in the PHM
                 * so that we can compare with the new information.
                 */
                $prevPUMCHData = array_filter(
                        array_map(function ($op) {
                            /** @var RecordPool $op */
                            return $op->getPrevRecordContent();
                        }, $episodeOperations));

                $patientInfo = null;

                $PUMCHData = array_map(function ($x) {
                    /** @var RecordPool $x */
                    return $x->getRecordContent();
                }, $episodeOperations);

                if (!empty($prevPUMCHData)) {
                    // This clinical episode was been processed before, so first we load the previous information
                    $patientInfo = PUMCHEpisodeInfo::fromJson($prevPUMCHData);
                    /*
                     * Now update the information that we already had about the episode with the new information received. This allows to keep track
                     * of the changes
                     */
                    $patientInfo->update($PUMCHData);
                } else {
                    // This is the first time we receive information about a clinical episode for this patient
                    $patientInfo = PUMCHEpisodeInfo::fromJson($PUMCHData);
                }

                ServiceLogger::getInstance()->debug(
                        'Importing patient ' . sprintf('%03d', $processed) . ': ' . $patientInfo->getName() . ' (SickId: ' .
                        $patientInfo->getPatientId() . ', Episode: ' . $patientInfo->getEpisodeId() . ')', 1);
                try {
                    $this->importIntoPHM($patientInfo, $kxEpisodesSubscription, $daySurgerySubscription);
                    $success[] = $patientInfo;
                    foreach ($episodeOperations as $record) {
                        // Preserve the informat¡on successfully imported so that we can track changes when updated information is received
                        $record->setPrevRecordContent($record->getRecordContent());
                        $record->setChanged(0);
                    }
                } catch (Exception $e) {
                    $importFailed[] = $patientInfo;
                    foreach ($episodeOperations as $record) {
                        $record->setChanged(2);
                    }
                    $processHistory->addLog($e->getMessage());
                    ServiceLogger::getInstance()->error($e->getMessage(), 1);
                }
                $processed++;
                foreach ($episodeOperations as $record) {
                    $record->save();
                }
                if ($processed >= $MaxEpisodes) {
                    break;
                }
            }

            $progress = round(100 * ($totalExpectedEpisodes ? $processed / $totalExpectedEpisodes : 1), 1);

            $outputMessage = "Processed: $processed ($progress%), Success: " . count($success) . ', Failed: ' . count($importFailed);
            // Save progress log
            $processHistory->setOutputMessage($outputMessage);
            $processHistory->save();
        }

        $outputMessage = "Processed: $processed ($progress%), Success: " . count($success) . ', Failed: ' . count($importFailed);
        if (count($success) + count($importFailed) == 0) {
            $outputStatus = ServiceResponse::IDLE;
        } elseif (count($importFailed) > 0) {
            $outputStatus = ServiceResponse::ERROR;
        } else {
            $outputStatus = ServiceResponse::SUCCESS;
        }

        $serviceResponse->setCode($outputStatus);
        $serviceResponse->setMessage($outputMessage);
        return $serviceResponse;
    }

    /**
     * Checks whether there exist Admissions in stage "Enroll" in the "DAY SURGERY" PROGRAM and rejects them if the corresponding PUMCH
     * Admission was discharged N days ago (number of days is configurable)
     *
     * @param ProcessHistory $processHistory
     * @return ServiceResponse
     */
    public function reviewDaySurgeryEnrolled($processHistory) {
        $serviceResponse = new ServiceResponse(ServiceResponse::IDLE, 'Process started');

        // Verify the existence of the required SUBSCRIPTIONS
        try {
            // Locate the SUBSCRIPTION for storing episode information
            $episodesSubscription = $this->apiLK->subscription_get($GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'], $GLOBALS['TEAM_CODE']);
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage(
                    'ERROR LOADING SUBSCRIPTION (Care plan: ' . $GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'] . ', Team: ' . $GLOBALS['TEAM_CODE'] .
                    ') FOR IMPORTING PATIENTS: ' . $e->getMessage());
            $processHistory->addLog($serviceResponse->getMessage());
            return $serviceResponse;
        }

        try {
            // Locate the SUBSCRIPTION of the "Day Surgery" PROGRAM for creating new ADMISSIONS of a patient
            $daySurgerySubscription = $this->apiLK->subscription_get($GLOBALS['DAY_SURGERY_PROGRAM_CODE'], $GLOBALS['TEAM_CODE']);
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage(
                    'ERROR LOADING SUBSCRIPTION (Care plan: ' . $GLOBALS['DAY_SURGERY_PROGRAM_CODE'] . ', Team: ' . $GLOBALS['TEAM_CODE'] .
                    ') FOR DAY SURGERY PATIENTS: ' . $e->getMessage());
            $processHistory->addLog($serviceResponse->getMessage());
            return $serviceResponse;
        }

        $admissions = $this->apiLK->admission_list_program($daySurgerySubscription->getProgram()->getId(), 'ENROLLED', null, null, 1000, 0,
                'enrol_date', 'asc', 'ALL', $daySurgerySubscription->getId());

        $rejectFailed = 0;
        $numRejected = 0;
        $remainEnrolled = 0;
        foreach ($admissions as $adm) {
            ServiceLogger::getInstance()->debug(
                    'Reviewing admission ' . $adm->getId() . ' (patient: ' . $adm->getCase()->getNickname() . '). Enrol date: ' . $adm->getEnrolDate());
            $dayDiff = (strtotime(currentDate($GLOBALS['DEFAULT_TIMEZONE'])) - strtotime($adm->getEnrolDate())) / 86400;
            if ($dayDiff < $GLOBALS['REJECT_ENROLLED_AFTER_DAYS']) {
                $remainEnrolled++;
                continue;
            }
            /*
             * Find the last Admission of the patient in the PUMCH episodes PROGRAM
             * If it is discharged, then check when was it discharged, and if it happened before a predetermined time lapse.
             */
            $pumchAdmissions = $this->apiLK->case_admission_list($adm->getCaseId(), true, $episodesSubscription->getId());
            $lastDischarge = null;
            foreach ($pumchAdmissions as $pumchAdm) {
                if (in_array($pumchAdm->getStatus(), [APIAdmission::STATUS_ACTIVE, APIAdmission::STATUS_ENROLLED])) {
                    // The patient has an active ADMISSION in the "PUMCH ADMISSIONS" PROGRAM. It is not necessary to reject the enrolled ADMISSION
                    $lastDischarge = null;
                    break;
                } elseif ($pumchAdm->getStatus() == APIAdmission::STATUS_DISCHARGED) {
                    if (!$lastDischarge || $lastDischarge < $pumchAdm->getDischargeDate()) {
                        $lastDischarge = $pumchAdm->getDischargeDate();
                    }
                }
            }
            if ($lastDischarge) {
                $dayDiff = (strtotime(currentDate($GLOBALS['DEFAULT_TIMEZONE'])) - strtotime($lastDischarge)) / 86400;
                if ($dayDiff > $GLOBALS['REJECT_ENROLLED_AFTER_DAYS']) {
                    try {
                        $this->apiLK->admission_reject($adm->getId());
                        $numRejected++;
                    } catch (Exception $e) {
                        $rejectFailed++;
                        $errMsg = 'ERROR trying to reject the Admission ' . $adm->getId() . ' of the ' . $GLOBALS['DAY_SURGERY_PROGRAM_CODE'] .
                                ' Care Plan: ' . $e->getMessage();
                        $processHistory->addLog($errMsg);
                        ServiceLogger::getInstance()->error($errMsg);
                    }
                } else {
                    $remainEnrolled++;
                }
            } else {
                $remainEnrolled++;
            }
        }

        $processed = count($admissions);
        $outputMessage = "Total Processed: $processed, Remain enrolled: $remainEnrolled, Rejected: $numRejected, Failed: $rejectFailed";
        if ($processed == 0) {
            $outputStatus = ServiceResponse::IDLE;
        } elseif ($rejectFailed > 0) {
            $outputStatus = ServiceResponse::ERROR;
        } else {
            $outputStatus = ServiceResponse::SUCCESS;
        }

        $serviceResponse->setCode($outputStatus);
        $serviceResponse->setMessage($outputMessage);

        return $serviceResponse;
    }

    /**
     *
     * Stores an episode record in the local DB.
     * Possible return values:
     * <ul>
     * <li>0: Record ignored because the record already existed and has not changed</li>
     * <li>1: The record is new</li>
     * <li>2: The record already existed but it has been updated</li>
     * </ul>
     *
     * @param PUMCHEpisodeInfo $PUMCHRecord
     * @throws ServiceException
     * @return int
     */
    private function processFetchedRecord($PUMCHRecord) {
        /** @var PUMCHProcedure $operation */
        $operation = reset($PUMCHRecord->getProcedures());

        $record = RecordPool::getInstance($PUMCHRecord->getPatientId(), $PUMCHRecord->getEpisodeId(), $PUMCHRecord->getProcedureId());
        if (!$record) {
            $record = new RecordPool($PUMCHRecord->getPatientId(), $PUMCHRecord->getEpisodeId(), $PUMCHRecord->getProcedureId());
            $record->setAdmissionDate($PUMCHRecord->getAdmissionTime());
            $record->setOperationDate($PUMCHRecord->getInRoomDatetime());
            $record->setRecordContent($PUMCHRecord->getOriginalObject());
            $record->setLastUpdate($PUMCHRecord->getUpdateTime());
            $ret = 1;
        } elseif ($record->equals($PUMCHRecord->getOriginalObject())) {
            $ret = 0;
        } else {
            $record->setAdmissionDate($PUMCHRecord->getAdmissionTime());
            $record->setOperationDate($PUMCHRecord->getInRoomDatetime());
            $record->setRecordContent($PUMCHRecord->getOriginalObject());
            $record->setLastUpdate($PUMCHRecord->getUpdateTime());
            $ret = 2;
        }

        $error = $record->save();
        if ($error && $error->getCode()) {
            // Error saving the record
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getCode() . ' - ' . $error->getMessage());
        }

        return $ret;
    }

    /**
     * Imports or updates a patient fecthed from PUMCH into the Linkcare Platform
     *
     * @param PUMCHEpisodeInfo $episodeInfo
     * @param APISubscription $episodeSubscription
     * @param APISubscription $daySurgerySubscription
     */
    private function importIntoPHM($episodeInfo, $episodeSubscription, $daySurgerySubscription) {
        $errMsg = '';
        $errCode = null;

        // Create or update the Patient in Linkcare platform
        try {
            $patient = $this->createPatient($episodeInfo, $episodeSubscription);
        } catch (ServiceException $se) {
            $errMsg = 'Import service generated an exception: ' . $se->getMessage();
            $errCode = $se->getCode();
        } catch (APIException $ae) {
            $errMsg = 'Linkcare API returned an exception: ' . $ae->getMessage();
            $errCode = $ae->getCode();
        } catch (Exception $e) {
            $errMsg = 'Unexpected exception: ' . $e->getMessage();
            $errCode = ErrorCodes::UNEXPECTED_ERROR;
        }
        if ($errMsg) {
            $errMsg = 'ERROR CREATING PATIENT ' . $episodeInfo->getName() . '(operationId: ' . $episodeInfo->getOperationId() . ', patientId:' .
                    $episodeInfo->getPatientId() . '): ' . $errMsg;
            throw new ServiceException($errCode, $errMsg);
        }

        // Create a new Admission for the patient or find the existing one
        try {
            // Check whether the Admission already exists
            $admission = $this->findAdmission($patient, $episodeInfo, $episodeSubscription);
            if (!$admission) {
                ServiceLogger::getInstance()->debug('Creating new Admission for patient in PUMCH Admissions care plan', 2);
                $admission = $this->createEpisodeAdmission($patient, $episodeInfo, $episodeSubscription);
                $isNewEpisode = true;
            } else {
                $isNewEpisode = false;
                ServiceLogger::getInstance()->debug('Using existing Admission for patient in PUMCH Admissions care plan', 2);
            }

            $referral = null;
            if ($episodeInfo->getSurgeonCode() && $GLOBALS['CASE_MANAGERS_TEAM']) {
                $referral = $this->createProfessional($episodeInfo->getSurgeonCode(), $episodeInfo->getSurgeonName(), $GLOBALS['CASE_MANAGERS_TEAM'],
                        APIRole::CASE_MANAGER);
            }

            $episodeInfoForm = $this->updateEpisodeData($admission, $episodeInfo, $referral);

            $admissionModified = false;
            if ($episodeInfo->getDischargeTime()) {
                // Discharge the Admission if necessary
                if ($admission->getStatus() != APIAdmission::STATUS_DISCHARGED) {
                    $admission->discharge(null, $episodeInfo->getDischargeTime());
                } elseif ($admission->getStatus() == APIAdmission::STATUS_DISCHARGED &&
                        $admission->getDischargeDate() != $episodeInfo->getDischargeTime()) {
                    // The ADMISSION was discharged, but the date has changed
                    $admission->setDischargeDate($episodeInfo->getDischargeTime());
                    $admissionModified = true;
                }
            }
            if (!$admission->getActiveReferralId() && $referral) {
                /*
                 * The Admission does not have a referral assigned, but we know the doctor assigned to the episode, so we can assign the referral
                 */
                $admission->setActiveReferralId($referral->getId());
                $admission->setActiveReferralTeamId($GLOBALS['CASE_MANAGERS_TEAM']);
                $admissionModified = true;
            }
            if ($admissionModified) {
                $admission->save();
            }

            $isNewDaySurgeryAdmission = false;
            $daySurgeryAdmission = null;
            if ($episodeInfo->shouldCreateDaySurgeryAdmission()) {
                // Create or update an associate ADMISSION in the "DAY SURGERY" PROGRAM
                if (!$isNewEpisode) {
                    $daySurgeryAdmission = $this->findDaySurgeryAdmission($episodeInfo->getOperatingDatetime(), $episodeInfoForm, $patient,
                            $daySurgerySubscription);
                }
                if (!$daySurgeryAdmission) {
                    /*
                     * It is necessary to create an associated ADMISSION in the "DAY SURGERY"
                     * PROGRAM
                     */
                    ServiceLogger::getInstance()->debug('Create new ADMISSION in DAY SURGERY care plan', 2);
                    $daySurgeryAdmission = $this->createDaySurgeryAdmission($patient, $episodeInfo, $episodeInfoForm, $daySurgerySubscription,
                            $isNewDaySurgeryAdmission);
                }
            }
        } catch (ServiceException $se) {
            $errMsg = 'Import service generated an exception: ' . $se->getMessage();
            $errCode = $se->getCode();
        } catch (APIException $ae) {
            $errMsg = 'Linkcare API returned an exception: ' . $ae->getMessage();
            $errCode = $ae->getCode();
        } catch (Exception $e) {
            $errMsg = 'Unexpected exception: ' . $e->getMessage();
            $errCode = ErrorCodes::UNEXPECTED_ERROR;
        }
        if ($errMsg) {
            $errMsg = 'ERROR CREATING/UPDATING ADMISSION FOR PATIENT ' . $episodeInfo->getName() . '(operationId: ' . $episodeInfo->getOperationId() .
                    ', patientId:' . $episodeInfo->getPatientId() . '): ' . $errMsg;
            throw new ServiceException($errCode, $errMsg);
        }
    }

    /**
     * ******************************** INTERNAL FUNCTIONS *********************************
     */

    /**
     * Creates a new patient (or updates it if already exists) in Linkcare database using as reference the information in $importInfo
     *
     * @param PUMCHEpisodeInfo $importInfo
     * @param APISubscription $subscription
     * @return APICase
     */
    private function createPatient($importInfo, $subscription = null) {
        // Check if there already exists a patient with the PUMCH SickId
        $searchCondition = new StdClass();
        if (!$importInfo->getPatientId()) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, 'patientId is not informed. It is mandatory to provide a patient Identifier.');
        }

        $searchCondition->identifier = new StdClass();
        $searchCondition->identifier->code = $GLOBALS['PATIENT_IDENTIFIER'];
        $searchCondition->identifier->value = $importInfo->getPatientId();
        $searchCondition->identifier->team = $GLOBALS['HOSPITAL_TEAM'];
        $found = $this->apiLK->case_search(json_encode($searchCondition));
        $caseId = null;
        if (!empty($found)) {
            $caseId = $found[0]->getId();
        }

        $contactInfo = new APIContact();

        if ($importInfo->getName()) {
            $contactInfo->setCompleteName($importInfo->getName());
        }
        if ($importInfo->getSex()) {
            $contactInfo->setGender($importInfo->getSex([$this, 'mapSexValue']));
        }
        if ($importInfo->getBirthday()) {
            $contactInfo->setBirthdate($importInfo->getBirthday());
        }

        if ($importInfo->getPhone()) {
            $phone = new APIContactChannel();
            $phone->setValue($importInfo->getPhone());
            $phone->setCategory('mobile');
            $contactInfo->addPhone($phone);
        }

        // Add the internal ID of the patient in PUMCH Hospital as an IDENTIFIER object in Linkcare platform
        $uniqueIdentifier = new APIIdentifier($GLOBALS['PATIENT_IDENTIFIER'], $importInfo->getPatientId());
        $uniqueIdentifier->setTeamId($GLOBALS['HOSPITAL_TEAM']);
        $contactInfo->addIdentifier($uniqueIdentifier);

        if ($importInfo->getIdCard() && ($identifierName = self::IdentifierNameFromCardType($importInfo->getIdCardType()))) {
            $nationalId = new APIIdentifier($identifierName, $importInfo->getIdCard());
            $contactInfo->addIdentifier($nationalId);
        }

        if ($caseId) {
            $programId = $subscription ? $subscription->getProgram()->getId() : null;
            $teamId = $subscription ? $subscription->getTeam()->getId() : null;
            $this->apiLK->case_set_contact($caseId, $contactInfo, null, $programId, $teamId);
            $patient = $this->apiLK->case_get($caseId);
        } else {
            $patient = $this->apiLK->case_insert($contactInfo, $subscription ? $subscription->getId() : null, true);
            $preferences = $patient->getPreferences();
            $preferences->setEditableByCase(false);
            $patient->save();
        }

        return $patient;
    }

    /**
     *
     * @param string $employeeRef
     * @param string $name
     * @param string $roleId
     * @return APIUser
     */
    private function createProfessional($employeeRef, $name, $teamId, $roleId) {
        if (isNullOrEmpty($employeeRef)) {
            return null;
        }
        if (array_key_exists($employeeRef, $this->professionals)) {
            // Cached in memory. Not necessary to update or insert the professional
            return $this->professionals[$employeeRef];
        }
        // Check if there already exists a professional with the PUMCH employee Id
        $searchCondition = new StdClass();
        $searchCondition->identifier = new StdClass();
        $searchCondition->identifier->code = $GLOBALS['PROFESSIONAL_IDENTIFIER'];
        $searchCondition->identifier->value = $employeeRef;
        $searchCondition->identifier->team = $GLOBALS['HOSPITAL_TEAM'];
        $found = $this->apiLK->user_search(json_encode($searchCondition));
        if (!empty($found)) {
            $userId = $found[0]->getId();
        }

        $contactInfo = new APIContact();
        if ($name) {
            $contactInfo->setCompleteName($name);
        }

        // Add the internal ID of the professional in PUMCH Hospital as an IDENTIFIER object in Linkcare platform
        $employeeId = new APIIdentifier($GLOBALS['PROFESSIONAL_IDENTIFIER'], $employeeRef);
        $employeeId->setTeamId($GLOBALS['HOSPITAL_TEAM']);
        $contactInfo->addIdentifier($employeeId);

        if (!$userId) {
            $userId = $this->apiLK->team_user_insert($contactInfo, $teamId, $roleId);
        } else {
            $userId = $this->apiLK->team_member_add($contactInfo, $teamId, $userId, 'USER', $roleId);
        }

        $professional = $this->apiLK->user_get($userId);
        $this->professionals[$employeeRef] = $professional;
        return $professional;
    }

    /**
     * Search an existing Admission that corresponds to the selected Kanxin episode
     *
     * @param APICase $case
     * @param PUMCHEpisodeInfo $importInfo
     * @param APISubscription $subscription
     */
    private function findAdmission($case, $importInfo, $subscription) {
        $filter = new TaskFilter();
        $filter->setSubscriptionIds($subscription->getId());
        $filter->setTaskCodes(self::PATIENT_HISTORY_TASK_CODE);
        $episodeList = $case->getTaskList(1000, 0, $filter);

        /*
         * First of all we need to find out which is the TASK that corresponds to the episode informed.
         * Depending on the result of the search can:
         * - If the TASK was found, update its contents with the new information
         * - If the TASK was not found, create a new one
         */

        if (empty($episodeList)) {
            return null;
        }
        $foundEpisodeTask = null;
        foreach ($episodeList as $episodeTask) {
            $episodeForms = $episodeTask->findForm(self::EPISODE_FORM_CODE);
            foreach ($episodeForms as $form) {
                $item = $form->findQuestion(PUMCHItemCodes::EPISODE_ID);
                if ($item->getValue() == $importInfo->getEpisodeId()) {
                    // The episode already exists
                    $foundEpisodeTask = $episodeTask;
                    break;
                }
            }
            if ($foundEpisodeTask) {
                break;
            }
        }

        if ($foundEpisodeTask) {
            // There already exists a Task for the PUMCH episode
            return $this->apiLK->admission_get($foundEpisodeTask->getAdmissionId());
        }
        return null;
    }

    /**
     * Creates a new Admission for a patient in the "PUMCH Episodes" PROGRAM
     *
     * @param APICase $case
     * @param PUMCHEpisodeInfo $episodeInfo
     * @param APISubscription $subscription
     * @return APIAdmission
     */
    private function createEpisodeAdmission($case, $episodeInfo, $subscription) {
        $setupParameters = new stdClass();

        $setupParameters->{PUMCHItemCodes::PATIENT_ID} = $episodeInfo->getPatientId();
        $setupParameters->{PUMCHItemCodes::EPISODE_ID} = $episodeInfo->getEpisodeId();
        $setupParameters->{PUMCHItemCodes::OPERATION_ID} = $episodeInfo->getOperationId();

        return $this->apiLK->admission_create($case->getId(), $subscription->getId(), $episodeInfo->getAdmissionTime(), null, true, $setupParameters);
    }

    /**
     * Creates a new Admission for a patient in the "PUMCH Day Surgery" PROGRAM
     *
     * @param APICase $case
     * @param PUMCHEpisodeInfo $episodeInfo
     * @param APIForm $episodeInfoForm
     * @param APISubscription $subscription
     * @param boolean &$isNew
     * @return APIAdmission
     */
    private function createDaySurgeryAdmission($case, $episodeInfo, $episodeInfoForm, $subscription, &$isNew) {
        /*
         * First of all check whether it is really necessary to create a new ADMISSION.
         * If any ADMISSION exists with a enroll date posterior to the admission date received from PUMCH, then it is not necessary to create a new
         * one.
         */
        $existingAdmissions = $this->apiLK->case_admission_list($case->getId(), true, $subscription->getId());
        /** @var APIAdmission $found */
        $found = null;
        $isNew = true;
        if (!empty($existingAdmissions)) {
            // Sort descending by enroll date so that the most recent Admission is the first of the list
            usort($existingAdmissions,
                    function ($adm1, $adm2) {
                        /** @var APIAdmission $adm1 */
                        /** @var APIAdmission $adm2 */
                        return strcmp($adm2->getEnrolDate(), $adm1->getEnrolDate());
                    });
            $found = reset($existingAdmissions);
        }

        if ($found) {
            /*
             * If there already exists an ADMISSION, maybe it is not necessary to create a new one.
             * There are 2 situations where it is not necessary to create a new ADMISSION
             * 1) The last ADMISSION found is active
             * 2) The last ADMISSION found is not active, but it has an enroll date posterior to the admission date received from PUMCH. This
             * situation is strange, but it may mean that we are receiving an update of an old record
             */
            $isActive = !in_array($found->getStatus(), [APIAdmission::STATUS_DISCHARGED, APIAdmission::STATUS_REJECTED]);
            if ($isActive || $episodeInfo->getAdmissionTime() < $found->getEnrolDate()) {
                $isNew = false;
                $admission = $found;
            }
        }

        if (!$admission) {
            $admission = $this->apiLK->admission_create($case->getId(), $subscription->getId(), null, null, true);
        }

        if ($episodeInfoForm && $q = $episodeInfoForm->findQuestion(PUMCHItemCodes::DAY_SURGERY_ADMISSION)) {
            /*
             * Update the ID of the Admission created in the FORM where the rest of the information about the episode is stored.
             */
            $q->setAnswer($admission->getId());
            $this->apiLK->form_set_answer($episodeInfoForm->getId(), $q->getId(), $admission->getId());
        }

        return $admission;
    }

    /**
     * Finds the ADMISSION created in the Day Surgery PROGRAM that is related to a PUMCH clinical episode.
     * The information about the related ADMISSION is stored in an ITEM of the FORM that holds all the information about the clinical episode
     *
     * @param APIForm $episodeInfoForm
     * @param APICase $case
     * @param APISubscription $subscription
     * @return APIAdmission
     */
    private function findDaySurgeryAdmission($operationDate, $episodeInfoForm, $case, $subscription) {
        if (!$episodeInfoForm) {
            return null;
        }

        $q = $episodeInfoForm->findQuestion(PUMCHItemCodes::DAY_SURGERY_ADMISSION);
        $daySurgeryAdmissionId = $q ? $q->getAnswer() : null;

        if ($daySurgeryAdmissionId) {
            try {
                $daySurgeryAdmission = $this->apiLK->admission_get($daySurgeryAdmissionId);
            } catch (APIException $e) {
                /*
                 * If cannot load the associated Day Surgery ADMISSION, it may mean that it has been deleted, which shouldn't be a problem.
                 * If any other case, generate an error
                 */
                if ($e->getCode() != "ADMISSION.NOT_FOUND") {
                    throw $e;
                }
            }
            return $daySurgeryAdmission;
        }

        /*
         * We don't have any Admission ID stored in the episode info FORM
         * Try to find an active ADMISSION
         */
        $existingAdmissions = $this->apiLK->case_admission_list($case->getId(), true, $subscription->getId());
        // Sort Admission by enroll date descending
        usort($existingAdmissions,
                function ($a1, $a2) {
                    /** @var APIAdmission $a1 */
                    /** @var APIAdmission $a2 */
                    return -strcmp($a1->getEnrolDate(), $a2->getEnrolDate());
                });
        /** @var APIAdmission $found */
        foreach ($existingAdmissions as $admission) {
            if ($admission->getEnrolDate() > $operationDate) {
                return $admission;
            }
        }

        return null;
    }

    /**
     * Updates the information related with a specific episode of the patient.
     * There exists a TASK with TASK_CODE = XXXXX for each episode.<br>
     * The return value is the APIForm with the information about the episode
     *
     * @param APIAdmission $admission
     * @param PUMCHEpisodeInfo $episodeInfo
     * @param APIUser $referral
     * @return APIForm
     */
    private function updateEpisodeData($admission, $episodeInfo, $referral) {
        $filter = new TaskFilter();
        $filter->setTaskCodes(self::PATIENT_HISTORY_TASK_CODE);
        $episodeList = $admission->getTaskList(1, 0, $filter);

        /*
         * First of all we need to find out which is the TASK that corresponds to the episode informed.
         * Depending on the result of the search can:
         * - If the TASK was found, update its contents with the new information
         * - If the TASK was not found, create a new one
         */
        $episodeTask = reset($episodeList);

        if (!$episodeTask) {
            /* We need to create a new TASK to store the episode information */
            $episodeTask = $admission->insertTask(self::PATIENT_HISTORY_TASK_CODE, $episodeInfo->getAdmissionTime());
        }

        $episodeForms = $episodeTask->findForm(self::EPISODE_FORM_CODE);
        foreach ($episodeForms as $form) {
            $item = $form->findQuestion(PUMCHItemCodes::EPISODE_ID);
            if (!$item->getValue()) {
                $emptyForm = $form;
            } elseif ($item->getValue() == $episodeInfo->getEpisodeId()) {
                // The episode already exists
                $episodeInfoForm = $form;
                break;
            }
        }

        $episodeInfoForm = $episodeInfoForm ?? $emptyForm;

        if (!$episodeInfoForm) {
            // The FORM for storing the Episode information does not exist and there is no one empty to use. We need to create a new one in the TASK
            $activities = $episodeTask->activityInsert(self::PATIENT_HISTORY_TASK_CODE);
            foreach ($activities as $act) {
                if (!$act instanceof APIForm) {
                    continue;
                }
                if ($act->getFormCode() == self::EPISODE_FORM_CODE) {
                    $episodeInfoForm = $act;
                    break;
                }
            }
        }

        if (!$episodeInfoForm) {
            // Error: could not create a new FORM to store the episode information
            throw new ServiceException(ErrorCodes::API_COMM_ERROR, 'The FORM with FORM_CODE = ' . self::EPISODE_FORM_CODE . ' (from the TASK_TEMPLATE ' .
                    self::PATIENT_HISTORY_TASK_CODE . ') to store the information of a patient was not inserted');
        }

        $arrQuestions = [];
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::LAST_IMPORT, currentDate());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::PATIENT_ID, $episodeInfo->getPatientId());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::EPISODE_ID, $episodeInfo->getEpisodeId());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::OPERATION_ID, $episodeInfo->getOperationId());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::DEPT_STAYED, $episodeInfo->getDeptStayed());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::DEPARTMENT, $episodeInfo->getDepartment());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::BED_NO, $episodeInfo->getBedNo());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::OPERATING_ROOM_NO, $episodeInfo->getOperatingRoomNo());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::OPERATING_DATETIME, $episodeInfo->getOperatingDatetime());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::DIAG_BEFORE, $episodeInfo->getDiagBeforeOperation());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::EMERGENCY_INDICATOR,
                $episodeInfo->getEmergencyIndicator([$this, 'mapEmergencyValueToOptionId']));
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::SURGEON_NAME, $episodeInfo->getSurgeonName());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::SURGEON_NAME1, $episodeInfo->getSurgeonName1());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME,
                $episodeInfo->getAnesthesiaDoctorName());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE,
                $episodeInfo->getAnesthesiaDoctorCode());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME2,
                $episodeInfo->getAnesthesiaDoctorName2());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE2,
                $episodeInfo->getAnesthesiaDoctorCode2());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME3,
                $episodeInfo->getAnesthesiaDoctorName3());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE3,
                $episodeInfo->getAnesthesiaDoctorCode3());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME4,
                $episodeInfo->getAnesthesiaDoctorName4());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE4,
                $episodeInfo->getAnesthesiaDoctorCode4());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ANESTHESIA_METHOD, $episodeInfo->getAnesthesiaMethod());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::OPERATION_POSITION, $episodeInfo->getOperationPosition());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::NAME, $episodeInfo->getName());
        $arrQuestions[] = $this->updateOptionQuestionValue($episodeInfoForm, PUMCHItemCodes::SEX, null, $episodeInfo->getSex([$this, 'mapSexValue']));
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::AGE, $episodeInfo->getAge());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::BIRTHDAY, $episodeInfo->getBirthday());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ID_CARD_TYPE, $episodeInfo->getIdCardType());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::ID_CARD, $episodeInfo->getIdCard());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::PHONE, $episodeInfo->getPhone());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::IN_ROOM_DATETIME, $episodeInfo->getInRoomDatetime());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::OUT_ROOM_DATETIME, $episodeInfo->getoutRoomDatetime());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::OPER_STATUS, $episodeInfo->getOperStatus());
        $arrQuestions[] = $this->updateTextQuestionValue($episodeInfoForm, PUMCHItemCodes::LAST_UPDATE, $episodeInfo->getUpdateTime());

        // Procedure information stored as a table (1 row)
        $ix = 1;
        $procedures = $episodeInfo->getProcedures();
        if (!empty($procedures) && ($arrayHeader = $episodeInfoForm->findQuestion(PUMCHItemCodes::PROCEDURE_TABLE)) &&
                $arrayHeader->getType() == APIQuestion::TYPE_ARRAY) {
            foreach ($procedures as $procedure) {
                $arrQuestions[] = $this->updateArrayTextQuestionValue($episodeInfoForm, $arrayHeader->getId(), $ix, PUMCHItemCodes::PROCEDURE_ID,
                        $procedure->getOperationId());
                $arrQuestions[] = $this->updateArrayTextQuestionValue($episodeInfoForm, $arrayHeader->getId(), $ix, PUMCHItemCodes::PROCEDURE_NAME,
                        $procedure->getOperationName());

                $ix++;
            }
        }

        // Remove null entries
        $arrQuestions = array_filter($arrQuestions);

        if (!empty($arrQuestions)) {
            $this->apiLK->form_set_all_answers($episodeInfoForm->getId(), $arrQuestions, true);
        }

        if ($referral) {
            // Assign the Task to the referral of the Admission (if not assigned yet)
            foreach ($episodeTask->getAssignments() as $assignment) {
                if ($assignment->getUserId() == $referral->getId() && ($assignment->getRoleId() == APIRole::CASE_MANAGER)) {
                    $alreadyAssigned = true;
                    break;
                }
            }

            if (!$alreadyAssigned) {
                $episodeTask->clearAssignments();
                $assignment = new APITaskAssignment(APIRole::CASE_MANAGER, $GLOBALS['CASE_MANAGERS_TEAM'], $referral->getId());
                $episodeTask->addAssignments($assignment);
            }
        }

        // Assign the Task to the anesthesists
        if ($GLOBALS['ANESTHESIA_TEAM']) {
            $anesthesists = [];
            $anesthesists[] = ['code' => $episodeInfo->getAnesthesiaDoctorCode(), 'name' => $episodeInfo->getAnesthesiaDoctorName()];
            $anesthesists[] = ['code' => $episodeInfo->getAnesthesiaDoctorCode2(), 'name' => $episodeInfo->getAnesthesiaDoctorName2()];
            $anesthesists[] = ['code' => $episodeInfo->getAnesthesiaDoctorCode3(), 'name' => $episodeInfo->getAnesthesiaDoctorName3()];
            $anesthesists[] = ['code' => $episodeInfo->getAnesthesiaDoctorCode4(), 'name' => $episodeInfo->getAnesthesiaDoctorName4()];

            $assignementsChanged = false;
            $currentAssignments = $episodeTask->getAssignments();
            $newAssignments = [];
            foreach ($anesthesists as $doctorInfo) {
                $code = $doctorInfo['code'];
                $name = $doctorInfo['name'];
                if (!$code) {
                    continue;
                }
                if (!$name) {
                    $name = $code;
                }
                $doctor = $this->createProfessional($code, $name, $GLOBALS['ANESTHESIA_TEAM'], APIRole::STAFF);
                $alreadyAssigned = false;
                foreach ($currentAssignments as $assignment) {
                    if ($assignment->getUserId() == $doctor->getId() && ($assignment->getRoleId() == APIRole::STAFF)) {
                        $alreadyAssigned = true;
                        break;
                    }
                }
                if (!$alreadyAssigned) {
                    $assignementsChanged = true;
                }
                $newAssignments[] = new APITaskAssignment(APIRole::STAFF, $GLOBALS['ANESTHESIA_TEAM'], $doctor->getId());
                // CURRENTLY THE LINKCARE PLATFORM DOES NOT ALLOW TO ASSIGN A TASK TO THE SAME ROLE TWICE, SO BY NOW WE ONLY ASSIGN THE TASK TO THE
                // FIRST ANESTHESIST
                break;
            }

            $assignementsChanged = $assignementsChanged || (count($newAssignments) != $episodeTask->getAssignments());

            if ($assignementsChanged) {
                $episodeTask->clearAssignments();
                $episodeTask->addAssignments($newAssignments);
            }
        }

        if ($episodeInfo->getAdmissionTime()) {
            $dateParts = explode(' ', $episodeInfo->getAdmissionTime());
            $date = $dateParts[0];
            $time = $dateParts[1];
            $episodeTask->setDate($date);
            $episodeTask->setHour($time);
        }

        $episodeTask->setLocked(true);
        $episodeTask->save();

        return $episodeInfoForm;
    }

    /**
     * Sets the value of a Question in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string $value Value to assign to the question
     * @return APIQuestion
     */
    private function updateTextQuestionValue($form, $itemCode, $value) {
        if ($q = $form->findQuestion($itemCode)) {
            $q->setAnswer($value);
        }
        return $q;
    }

    /**
     * Sets the value of a Question in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string $value Value to assign to the question
     * @return APIQuestion
     */
    private function updateArrayTextQuestionValue($form, $arrayRef, $row, $itemCode, $value) {
        if ($q = $form->findArrayQuestion($arrayRef, $row, $itemCode)) {
            $q->setAnswer($value);
        }
        return $q;
    }

    /**
     * Sets the value of a multi-options Question (checkbox, radios) in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string|string[] $optionId Id/s of the options assigned as the answer to the question
     * @param string|string[] $optionValues Value/s of the options assigned as the answer to the question
     * @return APIQuestion
     */
    private function updateOptionQuestionValue($form, $itemCode, $optionId, $optionValues = null) {
        $ids = is_array($optionId) ? implode('|', $optionId) : $optionId;
        $values = is_array($optionValues) ? implode('|', $optionValues) : $optionValues;

        if ($q = $form->findQuestion($itemCode)) {
            $q->setOptionAnswer($ids, $values);
        }
        return $q;
    }

    /**
     * Sets the value of a multi-options Question (checkbox, radios) in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string|string[] $optionId Id/s of the options assigned as the answer to the question
     * @param string|string[] $optionValues Value/s of the options assigned as the answer to the question
     * @return APIQuestion
     */
    private function updateArrayOptionQuestionValue($form, $arrayRef, $row, $itemCode, $optionId, $optionValues = null) {
        $ids = is_array($optionId) ? implode('|', $optionId) : $optionId;
        $values = is_array($optionValues) ? implode('|', $optionValues) : $optionValues;

        if ($q = $form->findArrayQuestion($arrayRef, $row, $itemCode)) {
            $q->setOptionAnswer($ids, $values);
        }
        return $q;
    }

    /**
     * Maps a Sex value to standard value used in Linkcare Platform
     *
     * @param string $value
     * @return number
     */
    public function mapSexValue($value) {
        if (in_array($value, ['0', '男', 'm', 'M'])) {
            $value = 'M';
        } elseif ($value) {
            $value = 'F';
        }
        return $value;
    }

    /**
     * Maps a Sex value to standard value used in Linkcare Platform
     *
     * @param string $value
     * @return number
     */
    public function mapEmergencyValueToOptionId($value) {
        if (in_array($value, ['急诊'])) {
            $value = 1;
        } else {
            $value = 2;
        }
        return $value;
    }

    static private function IdentifierNameFromCardType($cardType) {
        switch ($cardType) {
            case '01' : // Chinese ID
                return 'NAT_ZH';
            case '02' : // Chinese Military ID
                return 'NAT_ZH_MIL';
            case '03' : // Passport
                return 'PASS';
            case '04' : // Other
                /* It is not a good idea to have an "OTHER" IDENTIFIER. it is not possible to guarantee whether it will have a unique value */
                return 'OTHER';
            case '05' : // Chinese Household ID
                return 'NAT_ZH_HOUSEHOLD';
            case '06' : // Alien Residence Permit
                return 'NAT_ZH_FOREIGNERS';
            case '07' : // Mainland Travel Permit for Hong Kong and Macao Residents
                return 'NAT_ZH_HK_MACAO';
            case '08' : // Mainland Travel Permit for Taiwan Residents
                return 'NAT_ZH_TAIWAN';
        }
        return null;
    }
}
