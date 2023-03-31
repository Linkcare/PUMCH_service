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

    /** @var APISubscription[] List of subscriptions cached in memory */
    private $cachedSubscriptions = [];
    private $failedSubscriptions = [];

    /* Other Constants */
    const PATIENT_HISTORY_TASK_CODE = 'PUMCH_IMPORT';
    const OPERATION_FORM_CODE = 'PUMCH_IMPORT_FORM';

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
        if ($fromDate) {
            // use only the date part
            $fromDate = explode(' ', $fromDate)[0];
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
                /** @var PUMCHOperationInfo $patientInfo */
                ServiceLogger::getInstance()->debug(
                        'Processing patient ' . sprintf('%03d', $processed) . ': ' . $patientInfo->getName() . ' (Patient Id: ' .
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
            $kxEpisodesSubscription = $this->loadSubscription($GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'], $GLOBALS['PUMCH_EPISODES_TEAM_CODE']);
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage($e->getMessage());
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
            foreach ($changedEpisodes as $episodeOperationRecords) {
                /** @var RecordPool[] $episodeOperationRecords */
                /*
                 * The information received from each clinical episode consists on several records, where each record contains the
                 * information about one operation.
                 * First of all we will create a single Patient object with all the operations of the episode.
                 * Additionally, whenever we receive updated information about the episode, we want to track the changes received so that we can
                 * inform the Case Manager about wich things have changed. For that reason, we have a copy of the last information loaded in the PHM
                 * so that we can compare with the new information.
                 */
                $prevPUMCHData = array_filter(
                        array_map(function ($op) {
                            /** @var RecordPool $op */
                            return $op->getPrevRecordContent();
                        }, $episodeOperationRecords));

                $episodeInfo = null;

                $PUMCHData = array_map(function ($x) {
                    /** @var RecordPool $x */
                    return $x->getRecordContent();
                }, $episodeOperationRecords);

                if (!empty($prevPUMCHData)) {
                    // This clinical episode was been processed before, so first we load the previous information
                    $episodeInfo = PUMCHEpisode::fromJson($prevPUMCHData);
                    /*
                     * Now update the information that we already had about the episode with the new information received. This allows to keep track
                     * of the changes
                     */
                    $episodeInfo->update($PUMCHData);
                } else {
                    // This is the first time we receive information about a clinical episode for this patient
                    $episodeInfo = PUMCHEpisode::fromJson($PUMCHData);
                }

                ServiceLogger::getInstance()->debug(
                        'Importing operations for episode ' . sprintf('%03d', $processed) . ': ' . $episodeInfo->getName() . ' (Patient Id: ' .
                        $episodeInfo->getPatientId() . ', Episode Id: ' . $episodeInfo->getEpisodeId() . ')', 1);
                try {
                    $this->importIntoPHM($episodeInfo, $kxEpisodesSubscription);
                    $success[] = $episodeInfo;
                    foreach ($episodeOperationRecords as $record) {
                        // Preserve the informat¡on successfully imported so that we can track changes when updated information is received
                        $record->setPrevRecordContent($record->getRecordContent());
                        $record->setChanged(0);
                    }
                } catch (Exception $e) {
                    $importFailed[] = $episodeInfo;
                    foreach ($episodeOperationRecords as $record) {
                        $record->setChanged(2);
                    }
                    $processHistory->addLog($e->getMessage());
                    ServiceLogger::getInstance()->error($e->getMessage(), 1);
                }
                $processed++;
                foreach ($episodeOperationRecords as $record) {
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
            $episodesSubscription = $this->apiLK->subscription_get($GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'], $GLOBALS['PUMCH_EPISODES_TEAM_CODE']);
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage(
                    'ERROR LOADING SUBSCRIPTION (Care plan: ' . $GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'] . ', Team: ' .
                    $GLOBALS['PUMCH_EPISODES_TEAM_CODE'] . ') FOR IMPORTING PATIENTS: ' . $e->getMessage());
            $processHistory->addLog($serviceResponse->getMessage());
            return $serviceResponse;
        }

        try {
            // Locate the SUBSCRIPTION of the "Day Surgery" PROGRAM for creating new ADMISSIONS of a patient
            $daySurgerySubscription = $this->apiLK->subscription_get($GLOBALS['DAY_SURGERY_PROGRAM_CODE'], $GLOBALS['DAY_SURGERY_TEAM_CODES']);
        } catch (Exception $e) {
            $serviceResponse->setCode(ServiceResponse::ERROR);
            $serviceResponse->setMessage(
                    'ERROR LOADING SUBSCRIPTION (Care plan: ' . $GLOBALS['DAY_SURGERY_PROGRAM_CODE'] . ', Team: ' . $GLOBALS['DAY_SURGERY_TEAM_CODES'] .
                    ') FOR DAY SURGERY PATIENTS: ' . $e->getMessage());
            $processHistory->addLog($serviceResponse->getMessage());
            return $serviceResponse;
        }

        $admissions = $this->apiLK->admission_list_program($daySurgerySubscription->getProgram()->getId(), 'INCOMPLETE,ENROLLED', null, null, 1000, 0,
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
     * @param PUMCHOperationInfo $PUMCHRecord
     * @throws ServiceException
     * @return int
     */
    private function processFetchedRecord($PUMCHRecord) {
        $record = RecordPool::getInstance($PUMCHRecord->getPatientId(), $PUMCHRecord->getEpisodeId(), $PUMCHRecord->getOperationId());
        if (!$record) {
            $record = new RecordPool($PUMCHRecord->getPatientId(), $PUMCHRecord->getEpisodeId(), $PUMCHRecord->getOperationId());
            $record->setAdmissionDate($PUMCHRecord->getInRoomDatetime());
            $record->setOperationDate($PUMCHRecord->getOutRoomDatetime());
            $record->setRecordContent($PUMCHRecord->getOriginalObject());
            $record->setLastUpdate($PUMCHRecord->getUpdateDateTime());
            $ret = 1;
        } elseif ($record->equals($PUMCHRecord->getOriginalObject())) {
            $ret = 0;
        } else {
            $record->setAdmissionDate($PUMCHRecord->getInRoomDatetime());
            $record->setOperationDate($PUMCHRecord->getOutRoomDatetime());
            $record->setRecordContent($PUMCHRecord->getOriginalObject());
            $record->setLastUpdate($PUMCHRecord->getUpdateDateTime());
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
     * @param PUMCHEpisode $episodeInfo
     * @param APISubscription $episodeSubscription
     */
    private function importIntoPHM($episodeInfo, $episodeSubscription) {
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
            $errMsg = 'ERROR CREATING PATIENT ' . $episodeInfo->getName() . '(episodeId: ' . $episodeInfo->getEpisodeId() . ', patientId:' .
                    $episodeInfo->getPatientId() . '): ' . $errMsg;
            throw new ServiceException($errCode, $errMsg);
        }

        try {
            // Create a new Admission for the patient or find the existing one in the care plan PUMCH_ADMISSIONS
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
            if ($episodeInfo->getReferralCode() && $GLOBALS['CASE_MANAGERS_TEAM']) {
                $referral = $this->createProfessional($episodeInfo->getReferralCode(), $episodeInfo->getReferralName(), $GLOBALS['CASE_MANAGERS_TEAM'],
                        APIRole::CASE_MANAGER);
            }

            foreach ($episodeInfo->getOperations() as $operation) {
                $operationForms[$operation->getOperationId()] = $this->storeOperationData($admission, $operation);
            }

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

            /* Create or update an ADMISSION in the "DAY SURGERY" Care Plan for each operation */
            foreach ($episodeInfo->getOperations() as $operation) {
                if (!array_key_exists($operation->getDeptName(), $GLOBALS['DAY_SURGERY_TEAM_CODES'])) {
                    $msg = 'No TEAM configured for the department code: ' . $operation->getDeptName() . '. Cannot find the subscription for care plan ' .
                            $GLOBALS['DAY_SURGERY_PROGRAM_CODE'];
                    ServiceLogger::getInstance()->debug($msg, 2);
                    throw new ServiceException(ErrorCodes::CONFIG_ERROR, $msg);
                }
                $teamCode = $GLOBALS['DAY_SURGERY_TEAM_CODES'][$operation->getDeptName()];
                // Find Subscription to create the Admission
                $daySurgerySubscription = $this->loadSubscription($GLOBALS['DAY_SURGERY_PROGRAM_CODE'], $teamCode);
                $operationForm = $operationForms[$operation->getOperationId()];
                if (!$isNewEpisode) {
                    $daySurgeryAdmission = $this->findDaySurgeryAdmission($operation->getInRoomDatetime(), $operationForm, $patient,
                            $daySurgerySubscription);
                }
                if (!$daySurgeryAdmission) {
                    /*
                     * It is necessary to create an associated ADMISSION in the "DAY SURGERY"
                     * PROGRAM
                     */
                    ServiceLogger::getInstance()->debug('Create new ADMISSION in DAY SURGERY care plan', 2);
                    $daySurgeryAdmission = $this->createDaySurgeryAdmission($patient, $operation, $operationForm, $daySurgerySubscription,
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
            $errMsg = 'ERROR CREATING/UPDATING ADMISSION FOR PATIENT ' . $episodeInfo->getName() . '(episodeId: ' . $episodeInfo->getEpisodeId() .
                    ', patientId:' . $episodeInfo->getPatientId() . '): ' . $errMsg;
            throw new ServiceException($errCode, $errMsg);
        }
    }

    /**
     * ******************************** INTERNAL FUNCTIONS *********************************
     */
    /**
     * Loads the information of a Subscription (defined by the combination of a Program Code and a Team Code)
     *
     * @param string $programCode
     * @param string $teamCode
     * @throws APIException
     * @return APISubscription
     */
    public function loadSubscription($programCode, $teamCode) {
        $key = $programCode . '/' . $teamCode;
        if (array_key_exists($key, $this->failedSubscriptions)) {
            // We tried before to load this subscription, and an error happened. No need to try again
            throw $this->failedSubscriptions[$key];
        }

        try {
            // Locate the SUBSCRIPTION of the "Day Surgery" PROGRAM for creating new ADMISSIONS of a patient
            if (array_key_exists($key, $this->cachedSubscriptions)) {
                $subscription = $this->cachedSubscriptions[$key];
            } else {
                $subscription = $this->apiLK->subscription_get($programCode, $teamCode);
                $this->cachedSubscriptions[$key] = $subscription;
            }
        } catch (Exception $e) {
            $exception = new APIException($e->getCode(), "ERROR LOADING SUBSCRIPTION (Care plan: $programCode, Team: $teamCode): " . $e->getMessage());
            $this->failedSubscriptions[$key] = $exception;
            throw $exception;
        }

        return $subscription;
    }

    /**
     * Creates a new patient (or updates it if already exists) in Linkcare database using as reference the information in $importInfo
     *
     * @param PUMCHOperationInfo $importInfo
     * @param APISubscription $subscription
     * @return APICase
     */
    private function createPatient($importInfo, $subscription = null) {
        // Check if there already exists a patient with the PUMCH Patient Id
        $searchCondition = new StdClass();
        if (!$importInfo->getPatientId()) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, 'patientId is not informed. It is mandatory to provide a patient Identifier.');
        }

        if ($importInfo->getCrmId()) {
            // We have the ID of the patient in the AIMedicine CRM. We can use it as the patient reference
            $caseId = 'AIMED|' . $importInfo->getCrmId();
        } else {
            $searchCondition->identifier = new StdClass();
            $searchCondition->identifier->code = $GLOBALS['PATIENT_IDENTIFIER'];
            $searchCondition->identifier->value = $importInfo->getPatientId();
            $searchCondition->identifier->team = $GLOBALS['HOSPITAL_TEAM'];
            $found = $this->apiLK->case_search(json_encode($searchCondition));
            $caseId = null;
            if (!empty($found)) {
                $caseId = $found[0]->getId();
            }
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
        } elseif ($importInfo->getAge()) {
            $contactInfo->setAge($importInfo->getAge([$this, 'mapAgeValue']));
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
            try {
                $this->apiLK->case_set_contact($caseId, $contactInfo, null, $programId, $teamId);
            } catch (Exception $e) {
                /*
                 * There was an error updating the contact information of the patient.
                 * We will not throw an error in this case because the patient already exists and the only problem is that some fields could not be
                 * updated
                 */
                ServiceLogger::getInstance()->debug('Could not update contact information of patient ' . $importInfo->getPatientId(), 2);
            }
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
     * @param string $teamId
     * @param string $roleId
     * @param string $roleId
     * @throws APIException
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

        try {
            if (!$userId) {
                $userId = $this->apiLK->team_user_insert($contactInfo, $teamId, $roleId);
            } else {
                $userId = $this->apiLK->team_member_add($contactInfo, $teamId, $userId, 'USER', $roleId);
            }
        } catch (APIException $e) {
            $message = $e->getMessage() . " (user $employeeRef in team $teamId)";
            throw new APIException($e->getCode(), $message);
        }

        $professional = $this->apiLK->user_get($userId);
        $this->professionals[$employeeRef] = $professional;
        return $professional;
    }

    /**
     * Search an existing Admission that corresponds to the selected Kanxin episode
     *
     * @param APICase $case
     * @param PUMCHOperationInfo $importInfo
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
            $episodeForms = $episodeTask->findForm(self::OPERATION_FORM_CODE);
            foreach ($episodeForms as $form) {
                $item = $form->findQuestion(PUMCHItemCodes::INPATIENT_ID);
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
     * @param PUMCHEpisode $episodeInfo
     * @param APISubscription $subscription
     * @return APIAdmission
     */
    private function createEpisodeAdmission($case, $episodeInfo, $subscription) {
        $setupParameters = new stdClass();

        $setupParameters->{PUMCHItemCodes::PATIENT_ID} = $episodeInfo->getPatientId();
        $setupParameters->{PUMCHItemCodes::INPATIENT_ID} = $episodeInfo->getEpisodeId();

        return $this->apiLK->admission_create($case->getId(), $subscription->getId(), $episodeInfo->getAdmissionTime(), null, true, $setupParameters);
    }

    /**
     * Creates a new Admission for a patient in the "PUMCH Day Surgery" PROGRAM
     *
     * @param APICase $case
     * @param PUMCHOperationInfo $episodeInfo
     * @param APIForm $operationForm
     * @param APISubscription $subscription
     * @param boolean &$isNew
     * @return APIAdmission
     */
    private function createDaySurgeryAdmission($case, $episodeInfo, $operationForm, $subscription, &$isNew) {
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
            if ($isActive || $episodeInfo->getoutRoomDatetime() < $found->getEnrolDate()) {
                $isNew = false;
                $admission = $found;
            }
        }

        if (!$admission) {
            $admission = $this->apiLK->admission_create($case->getId(), $subscription->getId(), null, null, true);
        }

        if ($operationForm && $q = $operationForm->findQuestion(PUMCHItemCodes::DAY_SURGERY_ADMISSION)) {
            /*
             * Update the ID of the Admission created in the FORM where the rest of the information about the episode is stored.
             */
            $q->setAnswer($admission->getId());
            $this->apiLK->form_set_answer($operationForm->getId(), $q->getId(), $admission->getId());
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
     * @param PUMCHOperationInfo $operation
     * @return APIForm
     */
    private function storeOperationData($admission, $operation) {
        $filter = new TaskFilter();
        $filter->setTaskCodes(self::PATIENT_HISTORY_TASK_CODE);
        $episodeOperationList = $admission->getTaskList(1, 0, $filter);

        /*
         * First of all we need to find out which is the TASK that corresponds to the episode informed.
         * Depending on the result of the search can:
         * - If the TASK was found, update its contents with the new information
         * - If the TASK was not found, create a new one
         */
        $operationTask = null;

        foreach ($episodeOperationList as $task) {
            $episodeForms = $task->findForm(self::OPERATION_FORM_CODE);
            foreach ($episodeForms as $form) {
                $item = $form->findQuestion(PUMCHItemCodes::OPERATION_ID);
                if (!$item->getValue()) {
                    /*
                     * We have found a TASK where the information is not fulfilled (operationId not informed). We assume temporarily that this is the
                     * TASK to update unless another TASK with the expected operationId appears
                     */
                    $operationTask = $task;
                    $operationForm = $form;
                } elseif ($item->getValue() == $operation->getOperationId()) {
                    // The episode already exists
                    $operationTask = $task;
                    $operationForm = $form;
                    break;
                }
            }
            if ($operationForm) {
                break;
            }
        }
        if (!$operationTask) {
            /* We havent found the operationID. Wee need to create a new TASK to store the operation information */
            $operationTask = $admission->insertTask(self::PATIENT_HISTORY_TASK_CODE, $operation->getInRoomDatetime());
            $episodeForms = $operationTask->findForm(self::OPERATION_FORM_CODE);
            $operationForm = empty($episodeForms) ? null : reset($episodeForms);
        }

        if (!$operationForm) {
            // The FORM for storing the Operation information does not exist and there is no one empty to use. We need to create a new one in the TASK
            $activities = $operationTask->activityInsert(self::PATIENT_HISTORY_TASK_CODE);
            foreach ($activities as $act) {
                if (!$act instanceof APIForm) {
                    continue;
                }
                if ($act->getFormCode() == self::OPERATION_FORM_CODE) {
                    $operationForm = $act;
                    break;
                }
            }
        }

        if (!$operationForm) {
            // Error: could not create a new FORM to store the episode information
            throw new ServiceException(ErrorCodes::API_COMM_ERROR, 'FAILED insertion of the FORM with FORM_CODE = ' . self::OPERATION_FORM_CODE .
                    ' (from the TASK_TEMPLATE ' . self::PATIENT_HISTORY_TASK_CODE . ') to store the information of an operation');
        }

        $arrQuestions = [];
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::LAST_IMPORT, currentDate($GLOBALS['DEFAULT_TIMEZONE']));
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::CRM_ID, $operation->getCrmId());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::PATIENT_ID, $operation->getPatientId());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::INPATIENT_ID, $operation->getEpisodeId());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::OPERATION_ID, $operation->getOperationId());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::DEPT_NAME, $operation->getDeptName());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::DEPT_WARD, $operation->getDeptWard());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::BED_NO, $operation->getBedNo());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::OPERATING_ROOM_NO, $operation->getOperatingRoomNo());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::OPERATING_DATETIME, $operation->getOperatingDatetime());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::DIAG_BEFORE, $operation->getDiagBeforeOperation());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::EMERGENCY_INDICATOR,
                $operation->getEmergencyIndicator([$this, 'mapEmergencyValueToOptionId']));
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::SURGEON_NAME, $operation->getSurgeonName());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::SURGEON_NAME1, $operation->getSurgeonName1());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME, $operation->getAnesthesiaDoctorName());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE, $operation->getAnesthesiaDoctorCode());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME2,
                $operation->getAnesthesiaDoctorName2());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE2,
                $operation->getAnesthesiaDoctorCode2());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME3,
                $operation->getAnesthesiaDoctorName3());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE3,
                $operation->getAnesthesiaDoctorCode3());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_NAME4,
                $operation->getAnesthesiaDoctorName4());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_DOCTOR_CODE4,
                $operation->getAnesthesiaDoctorCode4());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ANESTHESIA_METHOD, $operation->getAnesthesiaMethod());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::OPERATION_POSITION, $operation->getOperationPosition());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::NAME, $operation->getName());
        $arrQuestions[] = $this->updateOptionQuestionValue($operationForm, PUMCHItemCodes::SEX, null, $operation->getSex([$this, 'mapSexValue']));
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::AGE, $operation->getAge());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::BIRTHDAY, $operation->getBirthday());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ID_CARD_TYPE, $operation->getIdCardType());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::ID_CARD, $operation->getIdCard());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::PHONE, $operation->getPhone());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::IN_ROOM_DATETIME, $operation->getInRoomDatetime());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::OUT_ROOM_DATETIME, $operation->getoutRoomDatetime());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::OPER_STATUS, $operation->getOperStatus());
        $arrQuestions[] = $this->updateTextQuestionValue($operationForm, PUMCHItemCodes::LAST_UPDATE, $operation->getUpdateDateTime());

        // Procedure information stored as a table (1 row)
        $ix = 1;
        $procedures = $operation->getProcedures();
        if (!empty($procedures) && ($arrayHeader = $operationForm->findQuestion(PUMCHItemCodes::PROCEDURE_TABLE)) &&
                $arrayHeader->getType() == APIQuestion::TYPE_ARRAY) {
            foreach ($procedures as $p) {
                $arrQuestions[] = $this->updateArrayTextQuestionValue($operationForm, $arrayHeader->getId(), $ix, PUMCHItemCodes::OPERATION_CODE,
                        $p->getOperationCode());
                $arrQuestions[] = $this->updateArrayTextQuestionValue($operationForm, $arrayHeader->getId(), $ix, PUMCHItemCodes::OPERATION_NAME,
                        $p->getOperationName());

                $ix++;
            }
        }

        // Remove null entries
        $arrQuestions = array_filter($arrQuestions);

        if (!empty($arrQuestions)) {
            $this->apiLK->form_set_all_answers($operationForm->getId(), $arrQuestions, true);
        }

        $surgeon = null;
        if ($operation->getSurgeonCode() && $GLOBALS['SURGEONS_TEAM']) {
            $surgeon = $this->createProfessional($operation->getSurgeonCode(), $operation->getSurgeonName(), $GLOBALS['SURGEONS_TEAM'],
                    APIRole::CASE_MANAGER);
        }

        if ($surgeon) {
            // Assign the Task to the referral of the Admission (if not assigned yet)
            foreach ($operationTask->getAssignments() as $assignment) {
                if ($assignment->getUserId() == $surgeon->getId() && ($assignment->getRoleId() == APIRole::CASE_MANAGER)) {
                    $alreadyAssigned = true;
                    break;
                }
            }

            if (!$alreadyAssigned) {
                $operationTask->clearAssignments();
                $assignment = new APITaskAssignment(APIRole::CASE_MANAGER, $GLOBALS['CASE_MANAGERS_TEAM'], $surgeon->getId());
                $operationTask->addAssignments($assignment);
            }
        }

        // Assign the Task to the anesthesists
        if ($GLOBALS['ANESTHESIA_TEAM']) {
            $anesthesists = [];
            $anesthesists[] = ['code' => $operation->getAnesthesiaDoctorCode(), 'name' => $operation->getAnesthesiaDoctorName()];
            $anesthesists[] = ['code' => $operation->getAnesthesiaDoctorCode2(), 'name' => $operation->getAnesthesiaDoctorName2()];
            $anesthesists[] = ['code' => $operation->getAnesthesiaDoctorCode3(), 'name' => $operation->getAnesthesiaDoctorName3()];
            $anesthesists[] = ['code' => $operation->getAnesthesiaDoctorCode4(), 'name' => $operation->getAnesthesiaDoctorName4()];

            $assignementsChanged = false;
            $currentAssignments = $operationTask->getAssignments();
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

            $assignementsChanged = $assignementsChanged || (count($newAssignments) != $operationTask->getAssignments());

            if ($assignementsChanged) {
                $operationTask->clearAssignments();
                $operationTask->addAssignments($newAssignments);
            }
        }

        if ($operation->getInRoomDatetime()) {
            $dateParts = explode(' ', $operation->getInRoomDatetime());
            $date = $dateParts[0];
            $time = $dateParts[1];
            $operationTask->setDate($date);
            $operationTask->setHour($time);
        }

        $operationTask->setLocked(true);
        $operationTask->save();

        return $operationForm;
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
     * Maps a Age value to a numeric value in years
     *
     * @param string $value
     * @return number
     */
    public function mapAgeValue($value) {
        $matches = null;
        if (is_numeric($value)) {
            return $value;
        }

        if (preg_match('/^(\d+)岁$/', $value, $matches)) {
            return $matches[1];
        }

        return null;
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
            case '5000' : // Chinese ID
                return 'NAT_ZH';
            case '5001' : // Chinese Household ID
                return 'NAT_ZH_HOUSEHOLD';
            case '5002' : // Passport
                return 'PASS';
            case '5003' : // Chinese Military ID
                return 'NAT_ZH_MIL';
            case '5004' :
                return 'DRIV_ZH'; // Driver license
            case '5005' : // Mainland Travel Permit for Hong Kong and Macao Residents
                return 'NAT_ZH_HK_MACAO';
            case '5006' : // Mainland Travel Permit for Taiwan Residents
                return 'NAT_ZH_TAIWAN';
            case '5007' : // Other
                /* It is not a good idea to have an "OTHER" IDENTIFIER. it is not possible to guarantee whether it will have a unique value */
                return 'OTHER';
            case '5008' : // Alien Residence Permit
                return 'NAT_ZH_FOREIGNERS';
        }
        return null;
    }
}
