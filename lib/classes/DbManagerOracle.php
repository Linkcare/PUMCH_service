<?php

class DbManagerOracle extends DbManager {
    private $conn;
    private $nrows;
    private $pdo;
    private $res;
    /** @var DbError */
    private $error;
    public $should_commit;
    private $Port = '1521';

    function __construct() {
        $this->should_commit = true;
    }

    function setURI($uri) {
        $dict = parse_url($uri);
        $this->Host = isset($dict['host']) ? $dict['host'] : 'localhost';
        $this->User = $dict['user'];
        $this->Passwd = $dict['pass'];
        $this->Port = isset($dict['port']) ? $dict['port'] : $this->Port;
        $this->Database = trim($dict['path'], "/");
    }

    function ConnectServer($pdo = true, $persistant = true) {
        $this->pdo = $pdo;
        $MAX_intentos = 5;
        $intentos = 0;
        $sessionMode = $this->asSysDba ? OCI_SYSDBA : null;
        while ($this->conn == null && $intentos < $MAX_intentos) {
            $error = null;
            try {
                // by default use OCI8 connection
                if ($this->pdo) {
                    $this->conn = new PDO("oci:dbname=//" . $this->Host . "/" . $this->Database . ";charset=AL32UTF8", $this->User, $this->Passwd);
                } else {
                    if ($persistant) {
                        $this->conn = oci_pconnect($this->User, $this->Passwd, $this->Host . ':' . $this->Port . '/' . $this->Database, 'AL32UTF8',
                                $sessionMode);
                    } else {
                        $this->conn = oci_connect($this->User, $this->Passwd, $this->Host . ':' . $this->Port . '/' . $this->Database, 'AL32UTF8',
                                $sessionMode);
                    }
                }
                if (!$this->conn) {
                    $error = oci_error();
                }
                $intentos++;
            } catch (PDOException $e) {
                sleep(0.01);
                $intentos++;
            }
        }
        if ($intentos == $MAX_intentos) {
            // file_put_contents("conn_error.log", "Error al conectar a Oracle: " . $e->getMessage(), FILE_APPEND);
            throw new Exception("Error al conectar a Oracle: {user: $this->User DB: $this->Database Host: $this->Host }");
        } else {
            /* Fix the format that the obtained DATE fields from the DB will have */
            $sql = "ALTER SESSION SET NLS_DATE_FORMAT = 'yyyy-mm-dd hh24:mi:ss'";
            $this->ExecuteQuery($sql);

            return ($this->conn);
        }
    }

    function DisconnectServer() {
        oci_close($this->conn);
    }

    function ExecuteQuery($query, $log = false) {
        $this->clearError();
        $this->nrows = 0;

        $commit = ($this->should_commit);

        $isQuery = true;
        if (strtoupper(substr(trim($query), 0, 6)) == 'DELETE' || strtoupper(substr(trim($query), 0, 6)) == 'UPDATE' ||
                strtoupper(substr(trim($query), 0, 6)) == 'INSERT') {
            $isQuery = false;
        }

        $this->res = oci_parse($this->conn, $query);
        $error = oci_error($this->conn);
        if (!$error) {
            oci_execute($this->res, ($commit ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT));
            $error = oci_error($this->res);
            if (!$error) {
                $error = oci_error($this->conn);
            }
            $this->nrows = oci_num_rows($this->res);
        }

        $this->SetError($error);

        if ($isQuery) {
            $this->results = new DbManagerResultsOracle();
            $this->results->setResultSet($this->res, $this->pdo);
        }

        if ($log) {
            $callers = debug_backtrace();
            if (!empty($callers[1])) {
                $function = array_key_exists('class', $callers[1]) ? $callers[1]['class'] . ':' . $callers[1]['function'] : $callers[1]['function'];
                $file = $callers[0]['file'];
                $line = $callers[0]['line'];
                $file = end(explode('/', $file)) . PHP_EOL . $line;
                $msg = $function . ' ' . $file . ' ' . $query;
            }
        }

        return ($this->results);
    }

    function ExecuteBindQuery($query, $arrVariables, $log = false) {
        $this->clearError();
        $this->nrows = 0;

        // if this is not an array with variables than this is only unique :id variable in query
        if (!is_array($arrVariables)) {
            $arrVariables = [':id' => $arrVariables];
        }

        $this->removeUnusedVariables($query, $arrVariables);

        $autoCommit = ($this->should_commit);

        $isQuery = true;
        if (strtoupper(substr(trim($query), 0, 6)) == 'DELETE' || strtoupper(substr(trim($query), 0, 6)) == 'UPDATE' ||
                strtoupper(substr(trim($query), 0, 6)) == 'INSERT') {
            $isQuery = false;
        }

        // if database is locked don't permit any update or deletion
        if ($GLOBALS["READ_ONLY"]) {
            if (!$isQuery) {
                return;
            }
        }
        $lobs = null;
        $this->res = oci_parse($this->conn, $query);
        $error = oci_error($this->conn);
        if (!$error) {
            foreach (array_keys($arrVariables) as $key) {
                if (startsWith(':clob_', $key) || startsWith(':blob_', $key)) {
                    $bindType = (startsWith(':clob_', $key) ? OCI_B_CLOB : OCI_B_BLOB);
                    $lobs[$key] = oci_new_descriptor($this->conn, OCI_D_LOB);
                    oci_bind_by_name($this->res, $key, $lobs[$key], -1, $bindType);
                } else {
                    oci_bind_by_name($this->res, $key, $arrVariables[$key], -1);
                }
            }
            if (!$lobs) {
                // no clobs in query:
                oci_execute($this->res, ($autoCommit ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT));
                $error = oci_error($this->res);
            } else {
                // clobs in query: first execute it
                // file_put_contents("/var/www/dev4.linkcare.es/ws/tmp/log.log", $query.PHP_EOL.json_encode($arrVariables).PHP_EOL, FILE_APPEND);
                oci_execute($this->res, OCI_DEFAULT);
                $error = oci_error($this->res);

                // file_put_contents("/var/www/dev4.linkcare.es/ws/tmp/log.log", " >>>>> EXECUTED".PHP_EOL, FILE_APPEND);
                $ok = true;
                if (!$error) {
                    foreach ($lobs as $key => $lob) {
                        // then save clobs
                        if (!$lob->save($arrVariables[$key])) {
                            $ok = false;
                        }
                    }
                }
                if ($autoCommit) {
                    if ($ok && !$error) {
                        oci_commit($this->conn);
                    } else {
                        oci_rollback($this->conn);
                    }
                }
            }
            if (!$error) {
                $this->nrows = oci_num_rows($this->res);
                $error = oci_error($this->conn);
            }
        }

        $this->SetError($error);

        if ($isQuery) {
            $this->results = new DbManagerResultsOracle();
            $this->results->setResultSet($this->res, $this->pdo);
        }

        if ($log) {
            if ($GLOBALS["SQL_LOGS"]) {
                $callers = debug_backtrace();
                if (!empty($callers[1])) {
                    $function = array_key_exists('class', $callers[1]) ? $callers[1]['class'] . ':' . $callers[1]['function'] : $callers[1]['function'];
                    $file = $callers[0]['file'];
                    $line = $callers[0]['line'];
                    $file = end(explode('/', $file)) . PHP_EOL . $line;
                    $msg = $function . ' ' . $file . ' ' . $query;
                }
            }
        }

        return ($this->results);
    }

    function ExecuteLOBQuery($query, $arrVariables, $arrBlobNames, $log = false) {
        $this->clearError();
        $this->nrows = 0;
        $this->removeUnusedVariables($query, $arrVariables);
        $this->removeUnusedVariables($query, $arrBlobNames);

        if (!is_array($arrVariables)) {
            $arrVariables = [':id' => $arrVariables];
        }
        if (empty($arrBlobNames)) {
            $arrBlobNames = [];
        }
        $autoCommit = ($this->should_commit);

        // if database is locked don't permit any update or deletion
        if ($GLOBALS["READ_ONLY"]) {
            return;
        }
        $lobs = null;

        if (!empty($arrBlobNames)) {
            $query = self::buildLobInsert($query, $arrBlobNames);
        }

        $this->res = oci_parse($this->conn, $query);
        $error = oci_error($this->conn);
        if (!$error) {
            foreach (array_keys($arrVariables) as $key) {
                if (!in_array($key, $arrBlobNames)) {
                    oci_bind_by_name($this->res, $key, $arrVariables[$key], -1);
                }
            }
            foreach (array_keys($arrBlobNames) as $key) {
                $bindType = (startsWith(':clob_', $key) ? OCI_B_CLOB : OCI_B_BLOB);
                $lobs[$key] = oci_new_descriptor($this->conn, OCI_D_LOB);
                oci_bind_by_name($this->res, $key, $lobs[$key], -1, $bindType);
            }

            if (!$lobs) {
                // no clobs in query:
                oci_execute($this->res, ($autoCommit ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT));
                $error = oci_error($this->res);
            } else {
                oci_execute($this->res, OCI_DEFAULT);
                $error = oci_error($this->res);

                $ok = true;
                if (!$error) {
                    foreach ($lobs as $key => $lob) {
                        // then save clobs
                        if (!$lob->save($arrVariables[$key])) {
                            $ok = false;
                        }
                    }
                }
                if ($autoCommit) {
                    if ($ok && !$error) {
                        oci_commit($this->conn);
                    } else {
                        oci_rollback($this->conn);
                    }
                }
                foreach ($lobs as $key => $lob) {
                    $lob->free();
                }
            }
            if (!$error) {
                $this->nrows = oci_num_rows($this->res);
                $error = oci_error($this->conn);
            }
        }

        $this->SetError($error);

        if ($log) {
            if ($GLOBALS["SQL_LOGS"]) {
                $callers = debug_backtrace();
                if (!empty($callers[1])) {
                    $function = array_key_exists('class', $callers[1]) ? $callers[1]['class'] . ':' . $callers[1]['function'] : $callers[1]['function'];
                    $file = $callers[0]['file'];
                    $line = $callers[0]['line'];
                    $file = end(explode('/', $file)) . PHP_EOL . $line;
                    $msg = $function . ' ' . $file . ' ' . $query;
                }
            }
        }

        return ($this->results);
    }

    /**
     * This function expects a SQL query like "SELECT myLOBField FROM myTable WHERE id=1 FOR UPDATE" and appends contents to the LOB fields indicated
     * in $arrBlobNames
     * $arrVariables is an associative array where the key is the name of the parameter in the SQL query and the contents is the value that will be
     * appended to the blob fields
     *
     * The function returns the final length of the LOB
     *
     * @param string $query
     * @param string[] $arrVariables
     * @param string $lobName
     * @param string $lobValue
     * @param boolean $log
     * @return int
     */
    function LOBAppend($query, $arrVariables, $lobName, $lobValue, $log = false) {
        $this->clearError();
        $this->nrows = 0;
        $finalLength = null;

        if (!is_array($arrVariables)) {
            $arrVariables = [':id' => $arrVariables];
        }
        $autoCommit = ($this->should_commit);

        // if database is locked don't permit any update or deletion
        if ($GLOBALS["READ_ONLY"]) {
            return $finalLength;
        }

        $this->res = oci_parse($this->conn, $query);
        $error = oci_error($this->conn);
        if (!$error) {
            foreach (array_keys($arrVariables) as $key) {
                oci_bind_by_name($this->res, $key, $arrVariables[$key], -1);
            }

            oci_execute($this->res, OCI_DEFAULT);
            $error = oci_error($this->res);

            if (!$error) {
                // Fetch the SELECTed row
                $row = oci_fetch_assoc($this->res);
                if (FALSE === $row) {
                    $error = oci_error($this->conn);
                }
            }

            if (!$error && $row && $row[$lobName]) {
                $row[$lobName]->seek(0, OCI_SEEK_END);
                $row[$lobName]->write($lobValue);
                $finalLength = $row[$lobName]->size();
            }

            if ($autoCommit) {
                if (!$error) {
                    oci_commit($this->conn);
                } else {
                    oci_rollback($this->conn);
                }
            }

            if ($row && $row[$lobName]) {
                $row[$lobName]->free();
            }
        }

        $this->SetError($error);

        if ($log) {
            if ($GLOBALS["SQL_LOGS"]) {
                $callers = debug_backtrace();
                if (!empty($callers[1])) {
                    $function = array_key_exists('class', $callers[1]) ? $callers[1]['class'] . ':' . $callers[1]['function'] : $callers[1]['function'];
                    $file = $callers[0]['file'];
                    $line = $callers[0]['line'];
                    $file = end(explode('/', $file)) . PHP_EOL . $line;
                    // log only "valid" query without variables
                    // log_start_write("", "sql", todayUTC(), $function . PHP_EOL . $file, '', $brCounter->elapsed(), json_encode($arrVariables) .
                    // "\n$query");
                }
            }
        }

        return $finalLength;
    }

    function setAutocommit($autocommit) {}

    function begin_transaction() {
        $this->should_commit = false;
    }

    function commit() {
        oci_commit($this->conn);
    }

    function rollback() {
        oci_rollback($this->conn);
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::getError()
     */
    function getError() {
        return $this->error;
    }

    function getRowsAffected() {
        return $this->nrows;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::getSequence()
     */
    function getNextSequence($sequenceName) {
        $sql = "SELECT $sequenceName.NEXTVAL AS ID FROM DUAL";
        $rst = $this->ExecuteQuery($sql);
        if ($rst->Next()) {

            return intval($rst->GetField('ID'));
        }
        return null;
    }

    private function clearError() {
        $this->error = new DbError();
    }

    private function SetError($e) {
        if (!$e) {
            return;
        }
        if (is_object($e)) {
            $this->error = new DbError($e->code, $e->message);
        } elseif (is_array($e)) {
            $this->error = new DbError($e['code'], $e['message']);
        } else {
            $this->error = new DbError('UNEXPECTED', $e);
        }
    }

    /**
     * Modifies the INSERT SQL $insertQuery adding a 'RETURNING' clause for the bind variables defined in $arrBoundVariables
     * This is necessary for inserting LOB values in a query with bound variables
     *
     * @param string $query
     * @param string[] $arrBoundVariables
     * @return string
     */
    private function buildLobInsert($insertQuery, $arrBoundVariables) {
        foreach ($arrBoundVariables as $varName => $fieldName) {
            if (startsWith(":blob_", $varName)) {
                $insertQuery = str_replace($varName, "EMPTY_BLOB()", $insertQuery);
            } else {
                $insertQuery = str_replace($varName, "EMPTY_CLOB()", $insertQuery);
            }
        }

        $fieldNames = implode(",", array_values($arrBoundVariables));
        $varNames = implode(",", array_keys($arrBoundVariables));

        // The RETURNING clause should look like: RETURNING field_lob_a,field_lob_b INTO :LOB_A,:LOB_B"
        $insertQuery = $insertQuery . " RETURNING $fieldNames INTO $varNames";
        return $insertQuery;
    }

    private function removeUnusedVariables($query, &$arrVariables) {
        if ($arrVariables === null) {
            return;
        }
        $names = array_keys($arrVariables);
        foreach ($names as $varName) {
            if (!preg_match('~' . $varName . '([^\w]|$)~', $query)) {
                unset($arrVariables[$varName]);
            }
        }
    }
}
