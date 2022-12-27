<?php

class DeployFunctions {

    private function userExists($userName) {
        $arrVariables[':usrName'] = $userName;
        $sql = "SELECT USERNAME FROM DBA_USERS  WHERE USERNAME =:usrName";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }

        return $rst->Next() != null;
    }

    /**
     * Checks the existence of a table
     * If an $ownerName is provided, then assume that we are using an administrative account to check the existence of another user's object
     *
     * @param string $objName
     * @param string $ownerName
     * @throws ServiceException
     */
    private function tableExists($objName, $ownerName = null) {
        $arrVariables[':objName'] = $objName;
        if ($ownerName) {
            $arrVariables[':ownerName'] = $ownerName;
            $sql = "SELECT TABLE_NAME FROM DBA_TABLES WHERE TABLE_NAME=:objName AND OWNER=:ownerName";
        } else {
            $sql = "SELECT TABLE_NAME FROM USER_TABLES WHERE TABLE_NAME=:objName";
        }

        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }

        return $rst->Next() != null;
    }

    /**
     * Checks the existence of a column in a table
     * If an $ownerName is provided, then assume that we are using an administrative account to check the existence of another user's object
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $ownerName
     * @throws ServiceException
     */
    private function columnExists($tableName, $columnName, $ownerName = null) {
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':colName'] = $columnName;
        if ($ownerName) {
            $arrVariables[':ownerName'] = $ownerName;
            $sql = "SELECT TABLE_NAME FROM DBA_TAB_COLUMNS WHERE TABLE_NAME=:tableName AND COLUMN_NAME=:colName AND OWNER=:ownerName";
        } else {
            $sql = "SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE TABLE_NAME=:tableName AND COLUMN_NAME=:colName";
        }

        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }

        return $rst->Next() != null;
    }

    /**
     * Checks the existence of an index
     * If an $ownerName is provided, then assume that we are using an administrative account to check the existence of another user's object
     *
     * @param string $objName
     * @param string $ownerName
     * @throws ServiceException
     */
    private function indexExists($objName, $ownerName = null) {
        $arrVariables[':objName'] = $objName;
        if ($ownerName) {
            $arrVariables[':ownerName'] = $ownerName;
            $sql = "SELECT INDEX_NAME FROM DBA_INDEXES WHERE INDEX_NAME=:objName AND TABLE_OWNER=:ownerName";
        } else {
            $sql = "SELECT INDEX_NAME FROM USER_INDEXES WHERE INDEX_NAME=:objName";
        }

        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }

        return $rst->Next() != null;
    }

    /**
     * Checks the existence of an index
     * If an $ownerName is provided, then assume that we are using an administrative account to check the existence of another user's object
     *
     * @param string $objName
     * @param string $ownerName
     * @throws ServiceException
     */
    private function sequenceExists($objName, $ownerName = null) {
        $arrVariables[':objName'] = $objName;
        if ($ownerName) {
            $arrVariables[':ownerName'] = $ownerName;
            $sql = "SELECT SEQUENCE_NAME FROM DBA_SEQUENCES WHERE SEQUENCE_NAME=:objName AND SEQUENCE_OWNER=:ownerName";
        } else {
            $sql = "SELECT SEQUENCE_NAME FROM USER_SEQUENCES WHERE SEQUENCE_NAME=:objName";
        }

        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }

        return $rst->Next() != null;
    }

    /**
     * Creates a new USER and grants connect privileges
     *
     * @param string $userName
     * @param string $password
     * @throws ServiceException
     */
    private function createUser($userName, $password) {
        $creationQueries[] = "CREATE USER $userName IDENTIFIED BY $password";
        $creationQueries[] = "ALTER USER $userName QUOTA UNLIMITED ON USERS";
        $creationQueries[] = "GRANT CONNECT TO $userName";
        foreach ($creationQueries as $sql) {
            Database::getInstance()->ExecuteQuery($sql);
            $error = Database::getInstance()->getError();
            if ($error->getCode()) {
                throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
            }
        }
    }

    /**
     * Creates a new table
     * If an $ownerName is provided, then assume that we are using an administrative account to check the existence of another user's object
     *
     * @param string $objName
     * @param string $ownerName
     * @param string $createQuery
     * @throws ServiceException
     */
    private function createTable($objName, $ownerName = null, $createQuery) {
        Database::getInstance()->ExecuteQuery($createQuery);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }
        return 2;
    }

    /**
     * Creates a new index
     * If an $ownerName is provided, then assume that we are using an administrative account to check the existence of another user's object
     *
     * @param string $objName
     * @param string $ownerName
     * @param string $createQuery
     * @throws ServiceException
     */
    private function createIndex($objName, $ownerName = null, $createQuery) {
        Database::getInstance()->ExecuteQuery($createQuery);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }
        return 2;
    }

    /**
     * Creates a new sequence
     * If an $ownerName is provided, then assume that we are using an administrative account to check the existence of another user's object
     *
     * @param string $objName
     * @param string $ownerName
     * @param string $createQuery
     * @throws ServiceException
     */
    private function createSequence($objName, $ownerName = null, $createQuery) {
        Database::getInstance()->ExecuteQuery($createQuery);
        $error = Database::getInstance()->getError();
        if ($error->getCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, $error->getMessage());
        }
    }

    /**
     * Generates the DB structure necesary for the Service to work
     *
     * @param string $ownerName
     */
    public function deployServiceSchema($ownerName, $password) {
        $result = [];

        if ($this->userExists($ownerName)) {
            $result[] = "Schema $ownerName: OK";
        } else {
            try {
                $this->createUser($ownerName, $password);
                $result[] = "Schema $ownerName: OK";
            } catch (Exception $e) {
                $result[] = "Schema $ownerName: ERROR " . $e->getMessage();
            }
        }

        /* Create table RECORD_POOL */
        if ($this->tableExists('RECORD_POOL', $ownerName)) {
            $result[] = "Table RECORD_POOL: OK";
        } else {
            $sql = "CREATE TABLE $ownerName.RECORD_POOL (
                    ID_RECORD_POOL NUMBER NULL,
                    ID_PATIENT VARCHAR2(100) NULL,
                    ID_EPISODE VARCHAR2(100) NULL,
                    ID_OPERATION VARCHAR2(100) NULL,
                    OPERATION_DATE VARCHAR2(100) NULL,
                    ADMISSION_DATE DATE NULL,
                    CREATION_DATE DATE NOT NULL,
                    LAST_UPDATE DATE NOT NULL,
                    RECORD_CONTENT CLOB NULL,
                    PREV_RECORD_CONTENT CLOB NULL,
                    CHANGED NUMBER NULL,
                CONSTRAINT RECORD_POOL_PK PRIMARY KEY (ID_RECORD_POOL)
                )";
            try {
                $this->createTable('RECORD_POOL', $ownerName, $sql);
                $result[] = "Table RECORD_POOL: OK";
            } catch (Exception $e) {
                $result[] = "Table RECORD_POOL: ERROR " . $e->getMessage();
                return $result;
            }
        }

        /* Create table PROCESS_HISTORY */
        if ($this->tableExists('PROCESS_HISTORY', $ownerName)) {
            $result[] = "Table PROCESS_HISTORY: OK";
        } else {
            $sql = "CREATE TABLE $ownerName.PROCESS_HISTORY (
                    ID_PROCESS NUMBER NOT NULL,
                    PROCESS_NAME VARCHAR(64) NOT NULL,
                    START_DATE DATE NOT NULL,
                    END_DATE DATE NULL,
                    OUTPUT_MESSAGE VARCHAR(1024),
                    STATUS NUMBER(1) NOT NULL,
                CONSTRAINT PROCESS_HISTORY_PK PRIMARY KEY (ID_PROCESS)
                )";
            try {
                $this->createTable('PROCESS_HISTORY', $ownerName, $sql);
            } catch (Exception $e) {
                $result[] = "Table PROCESS_HISTORY: ERROR " . $e->getMessage();
                return $result;
            }
        }

        /* Create table PROCESS_LOG */
        if ($this->tableExists('PROCESS_LOG', $ownerName)) {
            $result[] = "Table PROCESS_LOG: OK";
        } else {
            $sql = "CREATE TABLE $ownerName.PROCESS_LOG (
                ID_LOG NUMBER NOT NULL,
                LOG_DATE DATE NOT NULL,
                ID_PROCESS NUMBER NOT NULL,
                MESSAGE VARCHAR2(1024) NOT NULL,
                CONSTRAINT PROCESS_LOG_PK PRIMARY KEY (ID_LOG)
                )";
            try {
                $this->createTable('PROCESS_LOG', $ownerName, $sql);
                $result[] = "Table PROCESS_LOG: OK";
            } catch (Exception $e) {
                $result[] = "Table PROCESS_LOG: ERROR " . $e->getMessage();
                return $result;
            }
        }

        // Create indexes
        $sqlIndexes = [];
        $sqlIndexes['RECORD_POOL_PATIENT_IDX'] = "CREATE UNIQUE INDEX $ownerName.RECORD_POOL_PATIENT_IDX ON $ownerName.RECORD_POOL (ID_PATIENT,ID_EPISODE,ID_OPERATION)";
        $sqlIndexes['RECORD_POOL_CHANGED_IDX'] = "CREATE INDEX $ownerName.RECORD_POOL_CHANGED_IDX ON $ownerName.RECORD_POOL (CHANGED)";
        $sqlIndexes['RECORD_POOL_ADMISSION_DATE_IDX'] = "CREATE INDEX $ownerName.RECORD_POOL_ADMISSION_DATE_IDX ON $ownerName.RECORD_POOL (ID_PATIENT,ADMISSION_DATE)";
        $sqlIndexes['RECORD_POOL_EPISODE_IDX'] = "CREATE INDEX $ownerName.RECORD_POOL_EPISODE_IDX ON $ownerName.RECORD_POOL (ID_PATIENT,ID_EPISODE)";
        $sqlIndexes['PROCESS_HISTORY_DATE_IDX'] = "CREATE INDEX $ownerName.PROCESS_HISTORY_DATE_IDX ON $ownerName . PROCESS_HISTORY(START_DATE, PROCESS_NAME)";
        $sqlIndexes['PROCESS_LOG_IDX'] = "CREATE INDEX $ownerName.PROCESS_LOG_IDX ON $ownerName . PROCESS_LOG(ID_PROCESS)";
        foreach ($sqlIndexes as $idxName => $sql) {
            if ($this->indexExists($idxName, $ownerName)) {
                $result[] = "Index $idxName: OK";
                continue;
            }
            try {
                $this->createIndex($idxName, $ownerName, $sql);
                $result[] = "Index $idxName: OK";
            } catch (Exception $e) {
                $result[] = "Index $idxName: ERROR " . $e->getMessage();
                return $result;
            }
        }

        $sqlSequences['ID_RECORD_POOL_SEQ'] = "CREATE SEQUENCE $ownerName.ID_RECORD_POOL_SEQ INCREMENT BY 1 START WITH 1";
        $sqlSequences['ID_PROCESS_SEQ'] = "CREATE SEQUENCE $ownerName.ID_PROCESS_SEQ INCREMENT BY 1 START WITH 1";
        $sqlSequences['ID_LOG_SEQ'] = "CREATE SEQUENCE $ownerName.ID_LOG_SEQ INCREMENT BY 1 START WITH 1";
        foreach ($sqlSequences as $seqName => $sql) {
            if ($this->sequenceExists($seqName, $ownerName)) {
                $result[] = "Sequence $seqName: OK";
                continue;
            }
            try {
                $this->createSequence($seqName, $ownerName, $sql);
                $result[] = "Sequence $seqName: OK";
            } catch (Exception $e) {
                $result[] = "Sequence $seqName: ERROR " . $e->getMessage();
                return $result;
            }
        }

        $result[] = "SERVICE CORRECTLY DEPLOYED";

        return $result;
    }
}

