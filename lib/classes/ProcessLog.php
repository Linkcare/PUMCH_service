<?php

class ProcessLog {
    /** @var int */
    private $id;
    /** @var string */
    private $logDate;
    /** @var string */
    private $processId;
    /** @var string */
    private $message;
    private $updateNeeded = [];

    public function __construct($processId = null, $message = null) {
        $this->processId = $processId;
        $this->logDate = currentDate();
        $this->message = $message;
    }

    /**
     * ******* GETTERS *******
     */
    /**
     *
     * @return number
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Id of the process that generates the log
     *
     * @return string
     */
    public function getProcessId() {
        return $this->processId;
    }

    /**
     * Date when the log was generated
     *
     * @return string
     */
    public function getLogDate() {
        return $this->logDate;
    }

    /**
     * Log message
     *
     * @return string
     */
    public function getMessage() {
        return $this->message;
    }

    /**
     * ******* SETTERS *******
     */
    public function setProcessId($processId) {
        if ($processId == $this->processId) {
            return;
        }
        $this->processId = $processId;
        $this->updateNeeded[':processId'] = 'ID_PROCESS'; // Name of the field in DB
    }

    /**
     * Log message
     *
     * @param string $value
     */
    public function setMessage($value) {
        $value = substr($value, 0, 1024);
        if ($value == $this->message) {
            return;
        }
        $this->message = $value;
        $this->updateNeeded[':logMsg'] = 'MESSAGE'; // Name of the field in DB
    }

    /**
     * ******* METHODS *******
     */
    /**
     *
     * @param int $id
     * @return ProcessLog[]
     */
    static public function loadProcessLogs($id) {
        $sql = 'SELECT * FROM PROCESS_LOG WHERE ID_PROCESS=:id ORDER BY LOG_DATE';
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $id);
        $logs = [];
        while ($rst->Next()) {
            $logs[] = self::loadDBRecord($rst);
        }

        return $logs;
    }

    /**
     *
     * @param DbManagerResults $rst
     * @return ProcessLog
     */
    static private function loadDBRecord($rst) {
        $obj = new ProcessLog();
        $obj->id = $rst->GetField('ID_LOG');
        $obj->processId = $rst->GetField('ID_PROCESS');
        $obj->logDate = $rst->GetField('LOG_DATE');
        $obj->message = $rst->GetField('MESSAGE');

        return $obj;
    }

    /**
     */
    public function save() {
        $isNew = false;
        if (!$this->id) {
            // Create a new record
            $this->id = Database::getInstance()->getNextSequence('ID_LOG_SEQ');
            $isNew = true;
        }

        $arrVariables[':id'] = $this->id;
        $arrVariables[':processId'] = $this->processId;
        $arrVariables[':logDate'] = $this->logDate;
        $arrVariables[':logMsg'] = $this->message;

        $sql = null;
        if ($isNew) {
            $sql = 'INSERT INTO PROCESS_LOG (ID_LOG, ID_PROCESS, LOG_DATE, MESSAGE)
                    VALUES (:id, :processId, :logDate, :logMsg)';
        } elseif (count($this->updateNeeded) > 0) {
            $sqlUpdates = [];
            foreach ($this->updateNeeded as $varName => $fieldName) {
                $sqlUpdates[] .= "$fieldName = $varName";
            }
            $updateStr = implode(',', $sqlUpdates);
            $sql = "UPDATE PROCESS_HISTORY SET $updateStr WHERE ID_PROCESS=:id";
        }

        if ($sql) {
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
            $error = Database::getInstance()->getError();
            if (!$error->getCode()) {
                $this->updateNeeded = [];
            }
        }
    }
}