<?php

class APITeam {
    private $id;
    private $code;
    private $name;
    private $type;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APITeam
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $team = new APITeam();
        $team->id = NullableString($xmlNode->ref);
        $team->code = NullableString($xmlNode->code);
        if (!$team->code) {
            $team->code = NullableString($xmlNode->team_code);
        }
        $team->name = NullableString($xmlNode->name);
        $team->type = NullableString($xmlNode->unit);
        return $team;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */

    /**
     *
     * @return int
     */
    public function getId() {
        return $this->id;
    }

    /**
     *
     * @return string
     */
    public function getCode() {
        return $this->code;
    }

    /**
     *
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     *
     * @return string
     */
    public function getType() {
        return $this->type;
    }
}