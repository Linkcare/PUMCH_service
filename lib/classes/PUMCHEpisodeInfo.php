<?php

class PUMCHEpisodeInfo {
    /** @var stdClass */
    private $originalObject;
    /**
     * This member indicates if any change in the properties of the object must be tracked
     *
     * @var boolean
     */
    private $trackChanges = true;
    private $changeList = [];
    /**
     * When change tracking is set, whenever a new Procedure is added to the list of procedures it will also be tracked to know that the list of
     * procedures has been modified
     *
     * @var PUMCHProcedure
     */
    private $newProcedures = [];

    // "scheduled": "788360",

    /** @var string*/
    private $patientId;
    /** @var string*/
    private $episodeId;
    /** @var string*/
    private $operationId;
    /** @var string*/
    private $deptStayed;
    /** @var string*/
    private $department;
    /** @var string*/
    private $bedNo;
    /** @var string*/
    private $procedureId;
    /** @var string*/
    private $operatingRoomNo;
    /** @var string*/
    private $operatingDatetime;
    /** @var string*/
    private $diagBeforeOperation;
    /** @var string*/
    private $emergencyIndicator;
    /** @var string*/
    private $surgeonName;
    /** @var string*/
    private $surgeonCode;
    /** @var string*/
    private $surgeonName1;
    /** @var string*/
    private $surgeonCode1;
    /** @var string*/
    private $anesthesiaDoctorName;
    /** @var string*/
    private $anesthesiaDoctor;
    /** @var string*/
    private $anesthesiaDoctorName2;
    /** @var string*/
    private $anesthesiaDoctor2;
    /** @var string*/
    private $anesthesiaDoctorName3;
    /** @var string*/
    private $anesthesiaDoctor3;
    /** @var string*/
    private $anesthesiaDoctorName4;
    /** @var string*/
    private $anesthesiaDoctor4;
    /** @var string*/
    private $anesthesiaMethod;
    /** @var string*/
    private $operationPosition;
    /** @var string*/
    private $name;
    /** @var string*/
    private $sex;
    /** @var string*/
    private $age;
    /** @var string*/
    private $inRoomDatetime;
    /** @var string*/
    private $outRoomDatetime;
    /** @var string*/
    private $operStatus;

    /** @var string*/
    private $phone;
    /** @var string*/
    private $cardId;
    /** @var string*/
    private $updateTime;

    /** @var PUMCHProcedure[] */
    private $procedures = [];

    /**
     * ******* GETTERS *******
     */
    /**
     * Returns the string representation of the object as it was received
     *
     * @return stdClass
     */
    public function getOriginalObject() {
        return $this->originalObject;
    }

    /**
     * Internal Patient ID in PUMCH hospital
     *
     * @return string
     */
    public function getPatientId() {
        return $this->patientId;
    }

    /**
     * Internal episode Id in PUMCH hospital
     *
     * @return string
     */
    public function getEpisodeId() {
        return $this->episodeId;
    }

    /**
     * Operation Id in PUMCH hospital
     *
     * @return string
     */
    public function getOperationId() {
        return $this->operationId;
    }

    /**
     * Inpatient department code
     *
     * @return string
     */
    public function getDeptStayed() {
        return $this->deptStayed;
    }

    /**
     * Inpatient department name (ward)
     *
     * @return string
     */
    public function getDepartment() {
        return $this->department;
    }

    /**
     * Bed number
     *
     * @return string
     */
    public function getBedNo() {
        return $this->bedNo;
    }

    /**
     * Id of the procedure.
     * This is a value that is not retrieved from the PUMCH database and is assigned automatically by the integration service. It is a sequential
     * number for each of the operations of a patient
     *
     * @return string
     */
    public function getProcedureId() {
        return $this->procedureId;
    }

    /**
     * Operating room number
     *
     * @return string
     */
    public function getOperatingRoomNo() {
        return $this->operatingRoomNo;
    }

    /**
     * Operation datetime
     *
     * @return string
     */
    public function getOperatingDatetime() {
        return $this->operatingDatetime;
    }

    /**
     * Preoperative diagnosis
     *
     * @return string
     */
    public function getDiagBeforeOperation() {
        return $this->diagBeforeOperation;
    }

    /**
     * This value is true if the patient comes from emergency clinic
     *
     * @param callable $mapValueFn Function to map the internal value to another value
     * @return string
     */
    public function getEmergencyIndicator($mapValueFn = null) {
        if (!$mapValueFn) {
            return $this->emergencyIndicator;
        }
        return $mapValueFn($this->emergencyIndicator);
    }

    /**
     * Name of the operating surgeon
     *
     * @return string
     */
    public function getSurgeonName() {
        return $this->surgeonName;
    }

    /**
     * Code of the operating surgeon in PUMCH system
     *
     * @return string
     */
    public function getSurgeonCode() {
        return $this->surgeonCode;
    }

    /**
     * Name of the operating surgeon assistant
     *
     * @return string
     */
    public function getSurgeonName1() {
        return $this->surgeonName1;
    }

    /**
     * Code of the operating surgeon assistant in PUMCH system
     *
     * @return string
     */
    public function getSurgeonCode1() {
        return $this->surgeonCode1;
    }

    /**
     * Name of the Anesthesia doctor
     *
     * @return string
     */
    public function getAnesthesiaDoctorName() {
        return $this->anesthesiaDoctorName;
    }

    /**
     * Code of the Anesthesia doctor
     *
     * @return string
     */
    public function getAnesthesiaDoctorCode() {
        return $this->anesthesiaDoctor;
    }

    /**
     * Name of the Anesthesia doctor 2
     *
     * @return string
     */
    public function getAnesthesiaDoctorName2() {
        return $this->anesthesiaDoctorName2;
    }

    /**
     * Code of the Anesthesia doctor 2
     *
     * @return string
     */
    public function getAnesthesiaDoctorCode2() {
        return $this->anesthesiaDoctor2;
    }

    /**
     * Name of the Anesthesia doctor 3
     *
     * @return string
     */
    public function getAnesthesiaDoctorName3() {
        return $this->anesthesiaDoctorName3;
    }

    /**
     * Code of the Anesthesia doctor 3
     *
     * @return string
     */
    public function getAnesthesiaDoctorCode3() {
        return $this->anesthesiaDoctor3;
    }

    /**
     * Name of the Anesthesia doctor 4
     *
     * @return string
     */
    public function getAnesthesiaDoctorName4() {
        return $this->anesthesiaDoctorName4;
    }

    /**
     * Code of the Anesthesia doctor 4
     *
     * @return string
     */
    public function getAnesthesiaDoctorCode4() {
        return $this->anesthesiadoctor4;
    }

    /**
     *
     * @return string
     */
    public function getAnesthesiaMethod() {
        return $this->anesthesiaMethod;
    }

    /**
     *
     * @return string
     */
    public function getOperationPosition() {
        return $this->operationPosition;
    }

    /**
     *
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Patient gender
     *
     * @param callable $mapValueFn Function to map the internal value to another value
     * @return string
     */
    public function getSex($mapValueFn = null) {
        if (!$mapValueFn) {
            return $this->sex;
        }
        return $mapValueFn($this->sex);
    }

    /**
     *
     * @return string
     */
    public function getAge($mapValueFn = null) {
        if (!$mapValueFn) {
            return $this->age;
        }
        return $mapValueFn($this->age);
    }

    /**
     *
     * @return string
     */
    public function getInRoomDatetime() {
        return $this->inRoomDatetime;
    }

    /**
     *
     * @return string
     */
    public function getoutRoomDatetime() {
        return $this->outRoomDatetime;
    }

    /**
     * Surgical status
     * <ul>
     * <li>S: 已排手术Surgery scheduled</li>
     * <li>IC: 手术中取消Canceled during surgery</li>
     * <li>C: 取消Canceled</li>
     * </ul>
     *
     * @return string
     */
    public function getOperStatus() {
        return $this->operStatus;
    }

    /**
     * Mobile phone
     *
     * @return string
     */
    public function getPhone() {
        if (!isNullOrEmpty($this->phone) && !startsWith('+', $this->phone)) {
            return '+86' . $this->phone;
        }
        return $this->phone;
    }

    /**
     * National Id Card number
     *
     * @return string
     */
    public function getCardId() {
        return $this->cardId;
    }

    /**
     *
     * @return string
     */
    public function getUpdateTime() {
        return $this->updateTime;
    }

    /**
     *
     * @return PUMCHProcedure[]
     */
    public function getProcedures() {
        return $this->procedures;
    }

    /**
     * ******* SETTERS *******
     */

    /**
     * Internal Patient ID in PUMCH hospital
     *
     * @param string $value
     */
    public function setPatientId($value) {
        $this->patientId = $value;
    }

    /**
     * Internal Episode ID in PUMCH hospital
     *
     * @param string $value
     */
    public function setEpisodeId($value) {
        $this->episodeId = $value;
    }

    /**
     * Internal operation ID in PUMCH hospital
     *
     * @param string $value
     */
    public function setOperationId($value) {
        $this->operationId = $value;
    }

    /**
     * Inpatient department code
     *
     * @param string $value
     */
    public function setDeptStayed($value) {
        $this->assignAndTrackPropertyChange('deptStayed', $value);
    }

    /**
     * Inpatient department name (ward)
     *
     * @param string $value
     */
    public function setDepartment($value) {
        $this->assignAndTrackPropertyChange('department', $value);
    }

    /**
     * Bed number
     *
     * @param string $value
     */
    public function setBedNo($value) {
        $this->assignAndTrackPropertyChange('bedNo', $value);
    }

    /**
     * Id of the procedure.
     * This is a value that is not retrieved from the PUMCH database and is assigned automatically by the integration service. It is a sequential
     * number for each of the operations of a patient
     *
     * @param string $value
     */
    public function setProcedureId($value) {
        $this->assignAndTrackPropertyChange('procedureId', $value);
    }

    /**
     * Operating room number
     *
     * @param string $value
     */
    public function setOperatingRoomNo($value) {
        $this->assignAndTrackPropertyChange('operatingRoomNo', $value);
    }

    /**
     * Operation datetime
     *
     * @param string $value
     */
    public function setOperatingDatetime($value) {
        $this->assignAndTrackPropertyChange('operatingDatetime', $value);
    }

    /**
     * Preoperative diagnosis
     *
     * @param string $value
     */
    public function setDiagBeforeOperation($value) {
        $this->assignAndTrackPropertyChange('diagBeforeOperation', $value);
    }

    /**
     * Indicate whether the patient comes from emergency clinic
     *
     * @param string $value
     */
    public function setEmergencyIndicator($value) {
        $this->assignAndTrackPropertyChange('emergencyIndicator', $value);
    }

    /**
     * Name of the operating surgeon
     *
     * @param string $value
     */
    public function setSurgeonName($value) {
        $this->assignAndTrackPropertyChange('surgeonName', $value);
    }

    /**
     * Code of the operating surgeon in PUMCH system
     *
     * @param string $value
     */
    public function setSurgeonCode($value) {
        $this->assignAndTrackPropertyChange('surgeonCode', $value);
    }

    /**
     * Name of the operating surgeon assistant
     *
     * @param string $value
     */
    public function setSurgeonName1($value) {
        $this->assignAndTrackPropertyChange('surgeonName1', $value);
    }

    /**
     * Code of the operating surgeon assistant in PUMCH system
     *
     * @param string $value
     */
    public function setSurgeonCode1($value) {
        $this->assignAndTrackPropertyChange('surgeonCode1', $value);
    }

    /**
     * Name of the Anesthesia doctor
     *
     * @param string $value
     */
    public function setAnesthesiaDoctorName($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctorName', $value);
    }

    /**
     * Code of the Anesthesia doctor
     *
     * @param string $value
     */
    public function setAnesthesiaDoctorCode($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctor', $value);
    }

    /**
     * Name of the Anesthesia doctor 2
     *
     * @param string $value
     */
    public function setAnesthesiaDoctorName2($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctorName2', $value);
    }

    /**
     * Code of the Anesthesia doctor 2
     *
     * @param string $value
     */
    public function setAnesthesiaDoctorCode2($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctor2', $value);
    }

    /**
     * Code of the Anesthesia doctor 3
     */
    public function setAnesthesiaDoctorName3($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctorName3', $value);
    }

    /**
     * Code of the Anesthesia doctor 3
     *
     * @param string $value
     */
    public function setAnesthesiaDoctorCode3($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctor3', $value);
    }

    /**
     * Name of the Anesthesia doctor 4
     *
     * @param string $value
     */
    public function setAnesthesiaDoctorName4($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctorName4', $value);
    }

    /**
     * Code of the Anesthesia doctor 4
     */
    public function setAnesthesiaDoctorCode4($value) {
        $this->assignAndTrackPropertyChange('anesthesiaDoctor4', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setAnesthesiaMethod($value) {
        $this->assignAndTrackPropertyChange('anesthesiaMethod', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setOperationPosition($value) {
        $this->assignAndTrackPropertyChange('operationPosition', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setName($value) {
        $this->assignAndTrackPropertyChange('name', $value);
    }

    /**
     * Patient gender
     *
     * @param string $value
     */
    public function setSex($value) {
        $this->assignAndTrackPropertyChange('sex', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setAge($value) {
        $this->assignAndTrackPropertyChange('age', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setInRoomDatetime($value) {
        $this->assignAndTrackPropertyChange('inRoomDatetime', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setoutRoomDatetime($value) {
        $this->assignAndTrackPropertyChange('outRoomDatetime', $value);
    }

    /**
     * Surgical status
     * <ul>
     * <li>S: 已排手术Surgery scheduled</li>
     * <li>IC: 手术中取消Canceled during surgery</li>
     * <li>C: 取消Canceled</li>
     * </ul>
     *
     * @param string $value
     */
    public function setOperStatus($value) {
        $this->assignAndTrackPropertyChange('operStatus', $value);
    }

    /**
     * Mobile phone
     *
     * @param string $value
     */
    public function setPhone($value) {
        $this->assignAndTrackPropertyChange('phone', $value);
    }

    /**
     * National Id Card number
     *
     * @param string $value
     */
    public function setCardId($value) {
        $this->assignAndTrackPropertyChange('cardId', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setUpdateTime($value) {
        $this->updateTime = $value;
    }

    /**
     * ******* METHODS *******
     */
    /**
     * In PUMCH each operation corresponds to an Admission, and it doesn't exist any value to know the "Admission time".
     * The operation start time (inRoomDatetime) can be considered the Admission date
     *
     * @return string
     */
    public function getAdmissionTime() {
        return $this->getInRoomDatetime();
    }

    /**
     * In PUMCH each operation corresponds to an Admission, and it doesn't exist any value to know the "Discharge time".
     * The discharge is always done the same day than the operation so we assign a discharge date just before the midnight of the operation date
     *
     * @return string
     */
    public function getDischargeTime() {
        $day = $this->getOutRoomDatetime();
        if (!$day) {
            $day = $this->getInRoomDatetime();
        }
        if ($day) {
            $day = explode(' ', $day)[0] . ' ' . '23:59:59';
        }

        return $day;
    }

    /**
     * Returns true when the operation meets the conditions to create an Admission in the Day Surgery care plan
     *
     * @return boolean
     */
    public function shouldCreateDaySurgeryAdmission() {
        // The department must be "Day surgery inpatient west campus"
        if ($this->department != '日间病房西院') {
            return false;
        }

        if (!in_array($this->operatingRoomNo, ['西601', '西602', '西603', '西604', '西609', '西610'])) {
            return false;
        }

        if (!$this->outRoomDatetime) {
            return false;
        }

        if ($this->operStatus != 'S') {
            return false;
        }

        return true;
    }

    /**
     * Extracts the information about an operation from a record received from the PUMCH hospital.
     * A new PUMCHOperation object will be added to the list of operations of the episode
     *
     * @param stdClass $info
     */
    public function addOperation($info, $operationId) {
        if (isNullOrEmpty($info->operationName)) {
            return;
        }
        if (array_key_exists($info->operationName, $this->procedures)) {
            $procedure = $this->procedures[$operationId];
            $procedure->update($info);
        } else {
            $procedure = PUMCHProcedure::fromJson($info, $operationId);
            $this->procedures[$operationId] = $procedure;
            if ($this->trackChanges) {
                // A new procedure has been created and change trackin is active, so we store a list of new procedures added
                $this->newProcedures[$operationId] = $procedure;
            }
        }
    }

    /**
     * Updates a PUMCHEpisodeInfo object from the information received from the PUMCH hospital
     *
     * @param stdClass $episodeProcedures
     */
    public function update($episodeProcedures) {
        if (empty($episodeProcedures)) {
            return;
        }
        if (!is_array($episodeProcedures)) {
            $episodeProcedures = [$episodeProcedures];
        }

        /*
         * All procedures of an episode should contain the same information. The only difference is the procedure name
         * Nevertheless it may happen that some fields have been updated in the last procedures, so we must ensure that we get the correct
         * information. For example,
         * the outRoomDateTime may be empty in the first procedure, but with a non null value in the last procedure.
         * For this reason we will sort the received records by the outRoomDatetime and use the last one to obtain the most recent information about
         * the episode
         */
        usort($episodeProcedures,
                function ($a, $b) {
                    if (!$a->outRoomDatetime && !$b->outRoomDatetime) {
                        // No record has outRoomDatetime, so we keep the order in which they were received
                        return (0);
                    }
                    if (!$a->outRoomDatetime && $b->outRoomDatetime) {
                        // One record has a non-null outRoomDatetime, so we consider that record as the oldest one
                        return (-1);
                    }
                    if ($a->outRoomDatetime && !$b->outRoomDatetime) {
                        // One record has a non-null outRoomDatetime, so we consider that record as the oldest one
                        return (1);
                    }
                    return (strcmp($a->outRoomDatetime, $b->outRoomDatetime));
                });

        /* @var RecordPool $lastOperation */
        $lastOperation = end($episodeProcedures);

        $this->originalObject = $lastOperation;

        if (isNullOrEmpty($lastOperation->patientId)) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, 'Operation ' . $lastOperation->scheduled . ' arrived without Patient ID');
        }
        if (isNullOrEmpty($lastOperation->inRoomDatetime)) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, 'Operation ' . $lastOperation->scheduled . ' arrived without inRoomDatetime');
        }

        $this->setPatientId($lastOperation->patientId);
        $this->setCardId($lastOperation->cardId);
        $this->setEpisodeId($lastOperation->scheduled);
        $this->setOperationId($lastOperation->scheduled);
        $this->setDeptStayed($lastOperation->deptstayed);
        $this->setDepartment($lastOperation->department);
        $this->setBedNo($lastOperation->bedNo);
        $this->setProcedureId($lastOperation->procedureId);
        $this->setOperatingRoomNo($lastOperation->operatingroomno);
        $this->setOperatingDatetime($lastOperation->operatingdatetime);
        $this->setDiagBeforeOperation($lastOperation->diagbeforeoperation);
        $this->setEmergencyIndicator($lastOperation->emergencyindicator);
        $this->setSurgeonName($lastOperation->surgeonname);
        // $this->setSurgeonCode($lastOperation->surgeon); // Currently we are not receiving the surgeon code
        $this->setSurgeonName1($lastOperation->surgeonname1);
        // $this->setSurgeonCode1($lastOperation->surgeon1); // Currently we are not receiving the surgeon code assistant

        $this->setAnesthesiaDoctorName($lastOperation->anesthesiadoctorname);
        $this->setAnesthesiaDoctorCode($lastOperation->anesthesiadoctor);
        $this->setAnesthesiaDoctorName2($lastOperation->anesthesiadoctorname2);
        $this->setAnesthesiaDoctorCode2($lastOperation->anesthesiadoctor2);
        $this->setAnesthesiaDoctorName3($lastOperation->anesthesiadoctorname3);
        $this->setAnesthesiaDoctorCode3($lastOperation->anesthesiadoctor3);
        $this->setAnesthesiaDoctorName4($lastOperation->anesthesiadoctorname4);
        $this->setAnesthesiaDoctorCode4($lastOperation->anesthesiaDoctor4);
        $this->setAnesthesiaMethod($lastOperation->anesthesiaMethod);
        $this->setOperationPosition($lastOperation->operationPosition);
        $this->setName($lastOperation->name);
        $this->setSex($lastOperation->sex);
        $this->setAge($lastOperation->age);
        $this->setInRoomDatetime($lastOperation->inRoomDatetime);
        $this->setoutRoomDatetime($lastOperation->outRoomDatetime);
        $this->setOperStatus($lastOperation->operStatus);
        $this->setPhone($lastOperation->phone);
        $this->setUpdateTime($lastOperation->update_time ?? $lastOperation->operatingdatetime);

        // Now create the list of procedures of this episode
        foreach ($episodeProcedures as $operation) {
            if (isNullOrEmpty($operation->operationName)) {
                return;
            }
            /*
             * Use the procedure name as the identifier of the operation.
             * The PUMCH integration API may return repeated procedure names, but we must keep only different procedure names and remove duplicates
             */

            $this->addOperation($operation, $operation->operationName);
        }
    }

    /**
     * Returns true if any property of the object has been modified
     *
     * @return boolean
     */
    public function hasChanges() {
        if (count($this->changeList) > 0 || count($this->newProcedures) > 0) {
            return true;
        }
        foreach ($this->getProcedures() as $proc) {
            if ($proc->hasChanges()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a message composed by OBJECT CODES informing about the relevant changes detected.
     * Object Codes allow to handle language localization because the literals are defined in the PROGRAM
     *
     * @return string
     */
    function generateChangeMessage() {
        $itemCodes = [];
        if (!$this->hasChanges()) {
            return null;
        }

        // Check changes in operations
        if (count($this->newProcedures) > 0) {
            // New procedures added
            $itemCodes[] = 'OPERATION_NEW';
        } else {
            foreach ($this->getProcedures() as $proc) {
                if ($proc->hasChanges()) {
                    $itemCodes[] = 'OPERATION_UPDATE';
                    break;
                }
            }
        }

        // Check changes in discharge information
        if ($this->operationPosition && array_key_exists('dischargeTime', $this->changeList) && isNullOrEmpty($this->changeList['dischargeTime'])) {
            // Discharge information has been added
            $itemCodes[] = 'DISCHARGE_NEW';
        } else {
            $fieldsToCheck = ['dischargeDept', 'dischargeDiag', 'dischargeInstructions', 'dischargeSituation', 'dischargeStatus', 'dischargeTime'];
            foreach ($fieldsToCheck as $fName) {
                if (array_key_exists($fName, $this->changeList)) {
                    $itemCodes[] = 'DISCHARGE_UPDATE';
                    break;
                }
            }
        }

        foreach ($itemCodes as $itemCode) {
            $messages[] = "@TASK{PCI_DCH_LITERALS}.FORM{PCI_DCH_EPISODE_UPDATE_MSGS}.ITEM{" . $itemCode . "}.TITLE";
        }

        return implode("\n", $messages);
    }

    /**
     * Creates a PUMCHEpisodeInfo object from the information received from the PUMCH hospital
     *
     * @param stdClass $episodeOperations
     * @return PUMCHEpisodeInfo
     */
    static function fromJson($episodeOperations) {
        $patientInfo = new PUMCHEpisodeInfo();
        if (empty($episodeOperations)) {
            return $patientInfo;
        }
        if (!is_array($episodeOperations)) {
            $episodeOperations = [$episodeOperations];
        }

        /* This is the first time that we create the object, so it is not necessary to track the changes */
        $patientInfo->trackChanges = false;
        $patientInfo->update($episodeOperations);
        /* From this moment we want to track the changes in any of the object properties */
        $patientInfo->trackChanges = true;

        return $patientInfo;
    }

    /**
     * When the value of a property is changed, this function stores a copy of the previous value
     *
     * @param string $propertyName
     * @param string $newValue
     * @param string $previousValue
     */
    private function assignAndTrackPropertyChange($propertyName, $newValue) {
        if (isNullOrEmpty($newValue)) {
            $newValue = null;
        }
        $previousValue = $this->{$propertyName};
        $this->{$propertyName} = $newValue;

        if (!$this->trackChanges) {
            return;
        }

        if (isNullOrEmpty($previousValue)) {
            $previousValue = null;
        }
        if ($newValue !== $previousValue) {
            $this->changeList[$propertyName] = $previousValue;
        }
    }
}