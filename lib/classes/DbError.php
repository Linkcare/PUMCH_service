<?php

class DbError {
    private $code;
    private $message;

    public function __construct($code = null, $message = null) {
        $this->code = $code;
        $this->message = $message;
    }

    public function getCode() {
        return $this->code;
    }

    public function getMessage() {
        return $this->message;
    }
}

