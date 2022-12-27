<?php

class DbManagerResultsOracle extends DbManagerResults {
    var $rst;
    var $rs;
    var $pdo;

    function setResultSet($pRst, $pdo = true) {
        $this->pdo = $pdo;
        $this->rst = $pRst;
    }

    function Next() {
        if ($this->rst) {
            if ($this->pdo) {
                $this->rst->fetch(PDO::FETCH_ASSOC);
            } else {
                $this->rs = oci_fetch_array($this->rst);
            }
        } else {
            return false;
        }
        return ($this->rs);
    }

    function GetField($fieldName) {
        if (!isset($this->rs[$fieldName])) {
            return null;
        } else {
            if (is_object($this->rs[$fieldName])) {
                return $this->rs[$fieldName]->load();
            } // clob oci
            else {
                return ($this->rs[$fieldName]);
            }
        }
    }

    function GetLOBChunk($fieldName, $startPos, $length) {
        if ($startPos < 0 || !isset($this->rs[$fieldName]) || !is_object($this->rs[$fieldName])) {
            return null;
        }

        $size = $this->rs[$fieldName]->size();
        if ($startPos >= $size) {
            return null;
        }
        if ($startPos + $length >= $size) {
            $length = $size - $startPos;
        }

        $this->rs[$fieldName]->seek($startPos);
        return $this->rs[$fieldName]->read($length);
    }
}
