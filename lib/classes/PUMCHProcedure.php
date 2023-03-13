<?php

class PUMCHProcedure {
    /** @var string*/
    private $operationCode;
    private $operationName;

    /**
     * ******* GETTERS *******
     */

    /**
     *
     * @return string
     */
    public function getOperationCode() {
        return $this->operationCode;
    }

    /**
     *
     * @return string
     */
    public function getOperationName() {
        return $this->operationName;
    }

    /**
     * ******* METHODS *******
     */

    /**
     *
     * @param stdClass $procedureInfo
     * @return PUMCHProcedure
     */
    static public function fromJson($procedureInfo) {
        $procedure = new PUMCHProcedure();
        $procedure->operationCode = $procedureInfo->operationCode;
        $procedure->operationName = $procedureInfo->operationName;

        return $procedure;
    }
}
