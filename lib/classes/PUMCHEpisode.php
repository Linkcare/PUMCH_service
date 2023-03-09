<?php

class PUMCHEpisode {
    /**
     * This member indicates if any change in the properties of the object must be tracked
     *
     * @var boolean
     */
    private $trackChanges = true;
    private $changeList = [];

    /** @var string*/
    private $crmId;
    /** @var string*/
    private $patientId;
    /** @var string*/
    private $episodeId;
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
    private $phone;

    /** @var PUMCHOperationInfo[] */
    private $operations = [];

    /**
     * ******* GETTERS *******
     */

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
     * Returns the list of operations of this episode
     *
     * @return PUMCHOperationInfo[]
     */
    public function getOperations() {
        return $this->operations;
    }

    /**
     * Returns the admission date.
     * It corresponds to the date of the first operation
     */
    public function getAdmissionTime() {
        $firstDate = null;
        foreach ($this->operations as $op) {
            if ($firstDate === null || $firstDate > $op->getOperatingDatetime()) {
                $firstDate = $op->getOperatingDatetime();
            }
        }

        return $firstDate;
    }

    /**
     * Returns the discharge date.
     * It corresponds to the end datetime of the last operation
     */
    public function getDischargeTime() {
        $lastDate = null;
        foreach ($this->operations as $op) {
            if ($lastDate === null || $lastDate < $op->getOperatingDatetime()) {
                $lastDate = $op->getOperatingDatetime();
            }
        }

        return $lastDate;
    }

    /**
     * Code of the referral (doctor) assigned to the episode
     *
     * @return string
     */
    public function getReferralCode() {
        // Currently this value is not informed
        return '';
    }

    /**
     *
     * Name of the referral (doctor) assigned to the episode
     *
     * @return string
     */
    public function getReferralName() {
        // Currently this value is not informed
        return '';
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
     * Mobile phone
     *
     * @param string $value
     */
    public function setPhone($value) {
        $this->assignAndTrackPropertyChange('phone', $value);
    }

    /**
     *
     * @param stdClass $episodeOperations
     */
    public function update($episodeOperations) {
        if (empty($episodeOperations)) {
            return;
        }
        if (!is_array($episodeOperations)) {
            $episodeOperations = [$episodeOperations];
        }

        /*
         * All operations of an episode should contain the same patient information.
         * Nevertheless it may happen that some fields have been updated in the last operations, so we must ensure that we get the correct
         * information. For example, the phone number could have been updated in the last operation but missing in the first one.
         * For this reason we will sort the received records by operationDate and use the last one to obtain the most recent information about
         * the patient
         */
        usort($episodeOperations,
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
        $lastOperation = end($episodeOperations);

        if (isNullOrEmpty($lastOperation->patientID)) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, 'Operation ' . $lastOperation->scheduled . ' arrived without Patient ID');
        }
        if (isNullOrEmpty($lastOperation->inpatientID)) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, 'Operation ' . $lastOperation->scheduled . ' arrived without episode Id (inpatientID)');
        }

        $this->setCrmId($lastOperation->crmID);
        $this->setPatientId($lastOperation->patientID);
        $this->setEpisodeId($lastOperation->inpatientID);
        $this->setName($lastOperation->name);
        $this->setSex($lastOperation->sex);
        $this->setAge($lastOperation->age);
        $this->setBirthday($lastOperation->birthDay);
        $this->setIdCardType($lastOperation->idType);
        $this->setIdCard($lastOperation->idCard);
        $this->setPhone($lastOperation->phone);

        foreach ($episodeOperations as $operationRecord) {
            $operationId = NullableString($operationRecord->scheduled);
            if (array_key_exists($operationId, $this->operations)) {
                // This operation already exists in the episode. Update the information
                $operation = $this->operations[$operationId];
                $operation->update($operationRecord);
            } else {
                // New operation of the episode received
                $this->operations[$operationId] = PUMCHOperationInfo::fromJson($operationRecord);
            }
        }
    }

    /**
     * Creates a PUMCHEpisode object from the information received from the PUMCH hospital
     *
     * @param stdClass $operationRecords
     * @return PUMCHEpisode
     */
    static function fromJson($operationRecords) {
        $patientInfo = new PUMCHEpisode();
        if (empty($operationRecords)) {
            return $patientInfo;
        }

        /* This is the first time that we create the object, so it is not necessary to track the changes */
        $patientInfo->trackChanges = false;
        $patientInfo->update($operationRecords);
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

