<?php

class PUMCHOperationInfo {
    /** @var stdClass */
    private $originalObject;
    /**
     * This member indicates if any change in the properties of the object must be tracked
     *
     * @var boolean
     */
    private $trackChanges = true;
    private $changeList = [];
    private $proceduresChanged;

    /** @var string*/
    private $crmId;
    /** @var string*/
    private $patientId;
    /** @var string*/
    private $episodeId;
    /** @var string*/
    private $operationId;
    /** @var string*/
    private $deptCode;
    /** @var string*/
    private $deptName;
    /** @var string*/
    private $bedNo;
    /** @var PUMCHProcedure[] */
    private $procedures = [];
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
    private $birthday;
    /** @var string*/
    private $idCardType;
    /** @var string*/
    private $idCard;
    /** @var string*/
    private $inRoomDatetime;
    /** @var string*/
    private $outRoomDatetime;
    /** @var string*/
    private $operStatus;

    /** @var string*/
    private $phone;

    /** @var string*/
    private $createDateTime;
    /** @var string*/
    private $updateDateTime;

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
     * AIMedicine's CRM patient ID
     *
     * @return string
     */
    public function getCrmId() {
        return $this->crmId;
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
    public function getDeptCode() {
        return $this->deptCode;
    }

    /**
     * Inpatient department name (ward)
     *
     * @return string
     */
    public function getDeptName() {
        return $this->deptName;
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
     * List of procedures of the operation
     *
     * @return PUMCHProcedure[]
     */
    public function getProcedures() {
        return $this->procedures;
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
    public function getBirthday() {
        return $this->birthday;
    }

    /**
     *
     * @return string
     */
    public function getIdCardType() {
        return $this->idCardType;
    }

    /**
     *
     * @return string
     */
    public function getIdCard() {
        return $this->idCard;
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
     *
     * @return string
     */
    public function getCreateDateTime() {
        return $this->createDateTime;
    }

    /**
     *
     * @return string
     */
    public function getUpdateDateTime() {
        return $this->updateDateTime;
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
     * AIMedicine's CRM patient ID
     *
     * @param string $value
     */
    public function setCrmId($value) {
        $this->crmId = $value;
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
    public function setDeptCode($value) {
        $this->assignAndTrackPropertyChange('deptCode', $value);
    }

    /**
     * Inpatient department name (ward)
     *
     * @param string $value
     */
    public function setDeptName($value) {
        $this->assignAndTrackPropertyChange('deptName', $value);
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
     * Add a new procedure name to the operation
     *
     * @param PUMCHProcedure $procedure
     */
    public function addProcedure($procedure) {
        foreach ($this->procedures as $p) {
            if ($p->getOperationCode() == $procedure->getOperationCode()) {
                // The operation is already in the list
                return;
            }
        }
        if ($this->trackChanges) {
            $this->proceduresChanged = true;
        }
        $this->procedures[] = $procedure;
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
    public function setBirthday($value) {
        $this->assignAndTrackPropertyChange('birthday', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setIdCardType($value) {
        $this->assignAndTrackPropertyChange('idCardType', $value);
    }

    /**
     *
     * @param string $value
     */
    public function setIdCard($value) {
        $this->assignAndTrackPropertyChange('idCard', $value);
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
     *
     * @param string $value
     */
    public function setCreateDateTime($value) {
        $this->createDateTime = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setUpdateDateTime($value) {
        $this->updateDateTime = $value;
    }

    /**
     * ******* METHODS *******
     */

    /**
     * Updates a PUMCHOperationInfo object from the information received from the PUMCH hospital
     *
     * @param stdClass $operation
     */
    public function update($operation) {
        if (!$operation) {
            return;
        }

        $this->originalObject = $operation;

        if (isNullOrEmpty($operation->scheduled)) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, 'Operation ' . $operation->scheduled . ' arrived without Operation Id');
        }

        $this->setCrmId($operation->crmID);
        $this->setPatientId($operation->patientID);
        $this->setEpisodeId($operation->inpatientID);
        $this->setOperationId($operation->scheduled);
        $this->setDeptCode($operation->deptCode);
        $this->setDeptName($operation->deptName);
        $this->setBedNo($operation->bedNO);
        $this->setOperatingRoomNo($operation->operatingRoomNO);
        $this->setOperatingDatetime($operation->operatingDatetime);
        $this->setDiagBeforeOperation($operation->diagBeforeOperation);
        $this->setEmergencyIndicator($operation->emergencyIndicator);
        $this->setSurgeonName($operation->surgeonName);
        // $this->setSurgeonCode($lastOperation->surgeon); // Currently we are not receiving the surgeon code
        $this->setSurgeonName1($operation->surgeonName1);
        // $this->setSurgeonCode1($lastOperation->surgeon1); // Currently we are not receiving the surgeon code assistant

        $this->setAnesthesiaDoctorName($operation->anesthesiaDoctorName);
        $this->setAnesthesiaDoctorCode($operation->anesthesiaDoctorNO);
        $this->setAnesthesiaDoctorName2($operation->anesthesiaDoctorName2);
        $this->setAnesthesiaDoctorCode2($operation->anesthesiaDoctorNO2);
        $this->setAnesthesiaDoctorName3($operation->anesthesiaDoctorName3);
        $this->setAnesthesiaDoctorCode3($operation->anesthesiaDoctorNO3);
        $this->setAnesthesiaDoctorName4($operation->anesthesiaDoctorName4);
        $this->setAnesthesiaDoctorCode4($operation->anesthesiaDoctorNO4);
        $this->setAnesthesiaMethod($operation->anesthesiaMethod);
        $this->setOperationPosition($operation->operationPosition);
        if (isset($operation->operationList)) {
            foreach ($operation->operationList as $procedure) {
                $this->addProcedure(PUMCHProcedure::fromJson($procedure));
            }
        }
        $this->setName($operation->name);
        $this->setSex($operation->sex);
        $this->setAge($operation->age);
        $this->setInRoomDatetime($operation->inRoomDatetime);
        $this->setoutRoomDatetime($operation->outRoomDatetime);
        $this->setOperStatus($operation->operStatus);
        $this->setPhone($operation->phone);
        $this->setIdCardType($operation->idType);
        $this->setIdCard($operation->idCard);
        $this->setBirthday($operation->birthDay);
        $this->setCreateDateTime($operation->createDatetime ?? $this->getOperatingDatetime());
        $this->setUpdateDateTime($operation->lastUpdateDatetime ?? $this->getCreateDateTime());
    }

    /**
     * Returns true if any property of the object has been modified
     *
     * @return boolean
     */
    public function hasChanges() {
        if (count($this->changeList) > 0 || $this->proceduresChanged) {
            return true;
        }
        return false;
    }

    /**
     * Creates a PUMCHOperationInfo object from the information received from the PUMCH hospital
     *
     * @param stdClass $operation
     * @return PUMCHOperationInfo
     */
    static function fromJson($operation) {
        $patientInfo = new PUMCHOperationInfo();
        if (empty($operation)) {
            return $patientInfo;
        }

        /* This is the first time that we create the object, so it is not necessary to track the changes */
        $patientInfo->trackChanges = false;
        $patientInfo->update($operation);
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