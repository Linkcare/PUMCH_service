<?php

class PUMCHProcedure {
    /**
     * This member indicates if any change in the properties of the object must be tracked
     *
     * @var boolean
     */
    private $trackChanges = true;
    private $changeList = [];

    /** @var string*/
    private $operationId;
    private $operationName;

    public function __construct($operationId = null) {
        $this->operationId = $operationId;
    }

    /**
     * ******* GETTERS *******
     */

    /**
     *
     * @return string
     */
    public function getOperationId() {
        return $this->operationId;
    }

    /**
     *
     * @return string
     */
    public function getOperationName() {
        return $this->operationName;
    }

    /**
     * ******* SETTERS *******
     */

    /**
     *
     * @param string $value
     */
    public function setOperationName($value) {
        $this->trackPropertyChange('operationName', $value, $this->operationName);
        $this->operationName = trim($value);
    }

    /**
     * ******* METHODS *******
     */

    /**
     *
     * @param stdClass $operationInfo
     */
    public function update($operationInfo) {
        $this->setOperationName($operationInfo->operationName);
    }

    /**
     * Returns true if any property of the object has been modified
     *
     * @return boolean
     */
    public function hasChanges() {
        return count($this->changeList) > 0;
    }

    /**
     *
     * @param stdClass $operationInfo
     * @return PUMCHProcedure
     */
    static public function fromJson($operationInfo, $operationId) {
        $procedure = new PUMCHProcedure($operationId);
        /* This is the first time that we create the object, so it is not necessary to track the changes */
        $procedure->trackChanges = false;
        $procedure->update($operationInfo);
        /* From this moment we want to track the changes in any of the object properties */
        $procedure->trackChanges = true;
        return $procedure;
    }

    /**
     * When the value of a property is changed, this function stores a copy of the previous value
     *
     * @param string $propertyName
     * @param string $newValue
     * @param string $previousValue
     */
    private function trackPropertyChange($propertyName, $newValue, $previousValue) {
        if (!$this->trackChanges) {
            return;
        }
        if (isNullOrEmpty($newValue)) {
            $newValue = null;
        }
        if (isNullOrEmpty($previousValue)) {
            $previousValue = null;
        }
        if ($newValue !== $previousValue) {
            $this->changeList[$propertyName] = $previousValue;
        }
    }
}