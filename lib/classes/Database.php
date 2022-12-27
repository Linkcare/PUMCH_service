<?php
include_once ('DbError.php');
include_once ('DbManager.php');
include_once ('DbManagerResults.php');
include_once ('DbManagerOracle.php');
include_once ('DbManagerResultsOracle.php');

class Database {

    /* @var DbManager $backend */
    private static $backend = null;

    /**
     * Function that initiates the DbMnager $backend variable
     *
     * @return boolean in order to check for the function's success
     */
    static public function init($connString = null) {
        self::$backend = new DbManagerOracle();
        self::$backend->setURI($connString);
        self::$backend->ConnectServer(false);
    }

    /**
     *
     * @param string $server
     * @param string $databaseName
     * @param string $user
     * @param string $password
     */
    static public function connect($server, $databaseName, $user, $password, $asSysDba = false) {
        $db = new DbManagerOracle();
        $db->setHost($server);
        $db->setUser($user);
        $db->SetPasswd($password);
        $db->SetDatabase($databaseName);
        $db->connectAsSysDba($asSysDba);
        $db->ConnectServer(false);
        self::$backend = $db;
    }

    /**
     * Returns the DbManager $backend instance in order to execute queries
     *
     * @return DbManager $backend instance
     */
    static public function getInstance() {
        return self::$backend;
    }
}
