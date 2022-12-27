<?php

class DbManagerResults {

    function Next() {
        return (false);
    }

    /**
     *
     * @return mixed
     */
    function GetField($fieldName) {
        return ("");
    }

    function GetLOBChunk($fieldName, $startPos, $lenght) {
        return null;
    }
}
