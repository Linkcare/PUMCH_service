<?php

class APIForm {
    const STATUS_OPEN = 'OPEN';
    const STATUS_CLOSED = 'CLOSED';
    const STATUS_CANCELLED = 'CANCELLED';
    private $id;
    private $formCode;
    private $name;
    private $description;
    private $parentId;
    private $date;
    private $status;
    /** @var APIQuestion[] $questions */
    private $questions = null;
    /** @var LinkcareSoapAPI $api */
    private $api;

    public function __construct() {
        $this->api = LinkcareSoapAPI::getInstance();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIForm
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $form = new APIForm();
        $form->id = NullableString($xmlNode->ref);
        if ($xmlNode->code) {
            $form->formCode = NullableString((string) $xmlNode->code);
        } else {
            $form->formCode = NullableString((string) $xmlNode->form_code);
        }

        // form_get_summary retuns the information of the FORM into th subnode "data"
        $formInfoNode = $xmlNode->data ? $xmlNode->data : $xmlNode;
        if ($formInfoNode->short_name) {
            // form_get() returns the name in node "short_name")
            $form->name = NullableString($formInfoNode->short_name);
        } else {
            $form->name = NullableString($formInfoNode->name);
        }
        $form->description = NullableString($formInfoNode->description);
        $form->parentId = NullableInt($formInfoNode->parent_id);
        $form->date = NullableString($formInfoNode->date);
        $form->status = NullableString($formInfoNode->status);

        $questions = [];
        if ($formInfoNode->questions) {
            foreach ($formInfoNode->questions->question as $qNode) {
                $questions[] = APIQuestion::parseXML($qNode);
            }
            $form->questions = array_filter($questions);
        }
        return $form;
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
    public function getFormCode() {
        return $this->formCode;
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
    public function getDescription() {
        return $this->description;
    }

    /**
     *
     * @return int
     */
    public function getParentId() {
        return $this->parentId;
    }

    /**
     *
     * @return string
     */
    public function getDate() {
        return $this->date;
    }

    /**
     *
     * @return string
     */
    public function getStatus() {
        return $this->status;
    }

    /**
     *
     * @return APIQuestion[]
     */
    public function getQuestions() {
        if ($this->questions === null) {
            $this->questions = [];
            $f = $this->api->form_get_summary($this->id, true);
            $this->questions = $f->getQuestions();
        }

        return $this->questions ?? [];
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */
    /**
     *
     * @return boolean
     */
    public function isClosed() {
        return $this->status == self::STATUS_CLOSED;
    }

    /**
     *
     * @return boolean
     */
    public function isOpen() {
        return $this->status == self::STATUS_OPEN;
    }

    /**
     *
     * @return boolean
     */
    public function isCancelled() {
        return $this->status == self::STATUS_CANCELLED;
    }

    /**
     * Opens a FORM.
     * The function will do nothing if the FORM is already open
     */
    public function open() {
        if ($this->isOpen()) {
            return;
        }
        $this->api->form_open($this->id);
    }

    /**
     * Closes a FORM.
     * The function will do nothing if the FORM is not open
     */
    public function close() {
        if (!$this->isOpen()) {
            return;
        }
        $this->api->form_close($this->id);
    }

    /**
     *
     * @param APIQuestion $q
     */
    public function addQuestion($q) {
        $this->questions[] = $q;
    }

    /**
     * Searches the QUESTION with the $questionId indicated
     *
     * @param int $questionId
     * @return APIQuestion
     */
    public function findQuestion($questionId) {
        foreach ($this->getQuestions() as $q) {
            if ($q->getQuestionTemplateId() == $questionId || $q->getItemCode() == $questionId) {
                return $q;
            }
        }

        return null;
    }

    /**
     * Searches the array QUESTION at row $row with the $questionId indicated
     *
     * @param int $row
     * @param int $questionId
     * @return APIQuestion
     */
    public function findArrayQuestion($arrayRef, $row, $questionId) {
        $referenceQuestion = null;
        foreach ($this->getQuestions() as $q) {
            if ($arrayRef != $q->getArrayRef()) {
                continue;
            }
            if ($q->getQuestionTemplateId() == $questionId || $q->getItemCode() == $questionId) {
                if ($q->getRow() == $row) {
                    return $q;
                }
                if ($q->getRow() == 1) {
                    $referenceQuestion = $q;
                }
            }
        }
        /*
         * If we arrive here, it means that the specifued row does not exist yet. We have to add a new row andd add the question (using as reference
         * the question with the same ID in the first row)
         */
        if ($referenceQuestion) {
            $q = clone $referenceQuestion;
        } else {
            $q = new APIQuestion();
            $q->setItemCode($questionId);
            $q->setArrayRef($arrayRef);
        }

        $q->setRow($row);
        return $q;
    }
}