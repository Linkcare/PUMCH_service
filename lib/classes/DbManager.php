<?php

class DbManager {
    var $Host;
    var $User;
    var $Passwd;
    var $Database;
    var $Persistent;
    var $asSysDba = false;

    function SetHost($inputHost) {
        $this->Host = $inputHost;
    }

    function SetUser($inputUser) {
        $this->User = $inputUser;
    }

    function SetPasswd($inputPasswd) {
        $this->Passwd = $inputPasswd;
    }

    function SetDatabase($inputDatabase) {
        $this->Database = $inputDatabase;
    }

    function SetPersistent($persistent = true) {
        $this->Persistent = $persistent;
    }

    /**
     *
     * @param boolean $asSysDba
     */
    function connectAsSysDba($asSysDba) {
        $this->asSysDba = $asSysDba;
    }

    function GetHost() {
        return $this->Host;
    }

    function GetUser() {
        return $this->User;
    }

    function GetPasswd() {
        return $this->Passwd;
    }

    function GetDatabase() {
        return $this->Database;
    }

    function GetPersistent() {
        return $this->Persistent;
    }

    function StrToBD($value) {
        if ($value == "") {
            return ("null");
        }
        return ("'" . $value . "'");
    }

    function ConnectServer() {}

    function DisconnectServer() {}

    /**
     *
     * @return DbManagerResults
     */
    function ExecuteQuery($query) {}

    /**
     *
     * @return DbManagerResults
     */
    function ExecuteBindQuery($query, $arrVariables, $log = false) {}

    /**
     *
     * @return DbManagerResults
     */
    function ExecuteLOBQuery($query, $arrVariables, $arrBlobNames, $log = false) {}

    /**
     *
     * @return bool
     */
    function LOBAppend($query, $arrVariables, $lobName, $lobValue, $log = false) {}

    function setAutocommit($autocommit) {}

    function begin_transaction() {}

    function commit() {}

    function rollback() {}

    function getRowsAffected() {}

    /**
     * Returns an ErrorCode object with information about the execution of the last query
     * Note that this function always returns a non-null ErrorCode object.
     * To check if an error happened is necessary to inspect the contents of the errCode property
     *
     * @return DbError
     */
    function getError() {}

    /**
     *
     * @param string $sequenceName
     * @return int
     */
    function getNextSequence($sequenceName) {}
}

