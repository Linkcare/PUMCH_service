<?php

class PUMCHAPI {
    private static $instance;
    private $username;
    private $password;
    private $endpoint;
    private $timeout = 30;
    /** @var int */
    private $delay;
    private $lastRequestTime = 0;
    private static $cache = [];
    private $httpStatus;
    private $httpMessage;

    private function __construct($username, $password, $endpoint, $timeout = null) {
        $this->username = $username;
        $this->password = $password;
        $this->endpoint = trim($endpoint, '/') . '/';
        if (is_numeric($timeout) && intval($timeout) > 0) {
            $this->timeout = intval($timeout);
        }
        /* Time between requests to the PUMCH API to avoid blocking the server */
        $this->delay = $GLOBALS['PUMCH_REQUEST_DELAY'];
    }

    static public function getInstance() {
        if (!self::$instance) {
            self::$instance = new PUMCHAPI($GLOBALS['PUMCH_API_USERNAME'], $GLOBALS['PUMCH_API_PASSWORD'], $GLOBALS['PUMCH_API_URL'], $GLOBALS['PUMCH_API_TIMEOUT']);
        }
        return self::$instance;
    }

    public function setDelay($seconds) {
        $this->delay = $seconds;
    }

    /**
     * Invokes the PUMCH API to retrieve a list of patients that should be imported in the Linkcare platform
     *
     * @param string $fromDate
     * @throws ServiceException
     * @return PUMCHOperationInfo[]
     */
    public function requestPatientList($fromDate) {
        $waitTime = $this->delay - (microtime(true) - $this->lastRequestTime);
        if ($waitTime > 0) {
            usleep($waitTime * 1000000);
        }

        if ($GLOBALS['SIMULATE_PUMCH_API']) {
            $resp = $this->simulatedData();
        } else {
            // First we need to authenticate and obtain a token to be able to request data to PUMCH
            $headers = [];
            $params = [];
            $params['username'] = $this->username;
            $params['password'] = $this->password;
            try {
                $resp = $this->invokeAPI('token/login', $params, $headers, false);
                $token = $resp->token;
            } catch (ServiceException $e) {
                $errMsg = 'Authentication error: ' . $e->getErrorMessage();
                throw new ServiceException($e->getCode(), $errMsg);
            }

            $headers = [];
            $params = [];
            $params['opdatetime'] = $fromDate;
            $headers[] = 'token: ' . $token;
            $resp = $this->invokeAPI('xhdataapi/queryOperationXyqData', $params, $headers, false);
            if (is_array($resp) && $GLOBALS['ANONYMIZE_DATA']) {
                foreach ($resp as $info) {
                    self::anonymizeOperationData($info);
                }
            }
        }

        $this->lastRequestTime = microtime(true);

        if (!is_array($resp)) {
            throw new ServiceException(ErrorCodes::API_INVALID_DATA_FORMAT, 'healthData/opernInfos function should return an array as response');
        }

        // Group all operations having the same operation ID in a single record (multiple procedures of the same operation)
        $groupedRecords = [];
        foreach ($resp as $info) {
            if (isset($info->operationName) && !is_array($info->operationName)) {
                if (array_key_exists($info->scheduled, $groupedRecords)) {
                    $op = $groupedRecords[$info->scheduled];
                    $op->operationName[] = $info->operationName; // Add the name of the procedure
                } else {
                    $info->operationName = [$info->operationName];
                    $groupedRecords[$info->scheduled] = $info;
                }
            } else {
                $groupedRecords[$info->scheduled] = $info;
            }
        }
        foreach ($groupedRecords as $info) {
            $operations[] = PUMCHOperationInfo::fromJson($info);
        }
        return $operations;
    }

    /**
     * Invokes a REST function in the PUMCH API
     *
     * @param string $function
     * @param string[] $params
     * @throws ServiceException
     * @return stdClass
     */
    private function invokeAPI($function, $params, $headers = null, $sendAsJson = true, $usePOST = false) {
        $this->httpStatus = null;
        $this->httpMessage = null;

        $endpoint = $this->endpoint . $function;
        $errorMsg = null;
        $headers = is_array($headers) ? $headers : [];

        if ($sendAsJson) {
            $headers[] = 'Content-Type: application/json';
            $params = json_encode($params);
        }

        $options = [CURLOPT_HEADER => false, CURLOPT_AUTOREFERER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $this->timeout, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false, CURLOPT_RETURNTRANSFER => 1];
        if ($usePOST) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $params;
        } elseif (!empty($params)) {
            $endpoint .= '?';
            foreach ($params as $paramName => $paramValue) {
                $endpoint .= $paramName . '=' . urlencode($paramValue) . '&';
            }
        }
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, $options);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, [$this, "handleHeaderLine"]);

        // Check if any error occurred
        if (curl_errno($curl)) {
            $errorMsg = 'CURL error: ' . curl_error($curl);
        }
        if (!$errorMsg) {
            $APIResponse = curl_exec($curl);
            if ($APIResponse === false) {
                $errorMsg = 'CURL error: ' . curl_error($curl);
            }
        }

        curl_close($curl);

        if ($errorMsg) {
            throw new ServiceException(ErrorCodes::API_COMM_ERROR, $errorMsg);
        }
        if (!startsWith('2', $this->httpStatus)) {
            // The did not provide any information but responded with an HTTP error status
            throw new ServiceException(ErrorCodes::API_ERROR_STATUS, $this->httpStatus . ' ' . $this->httpMessage . ' (Endpoint: ' . $this->endpoint .
                    $function . ')');
        }
        return $this->parseAPIResponse($APIResponse);
    }

    /**
     * Handles header lines returned by a CURL command
     *
     * @param resource $curl
     * @param string $header_line
     * @return number
     */
    function handleHeaderLine($curl, $header_line) {
        $matches = null;
        if (preg_match('/^HTTP[^\s]*\s(\d+)(.*)/', $header_line, $matches)) {
            $this->httpStatus = $matches[1];
            $this->httpMessage = $matches[2];
        }
        return strlen($header_line);
    }

    /**
     * Parses the response of the PUMCH API and checks if any error was reported.
     * All responses from the API contain 3 fields:+
     * <ul>
     * <li>code: an error code (or empty if no error occurred)</li>
     * <li>message: Explanation of the error (if any)</li>
     * <li>result: Contents of the response of the API function</li>
     * </ul>
     * If an error is reported by the API, an exception will be thrown. Otherwise the function returns the contents of the response
     *
     * @param string $APIResponse
     * @throws ServiceException::
     * @return stdClass
     */
    private function parseAPIResponse($APIResponse) {
        $result = json_decode($APIResponse);

        if (!$result) {
            // Error decoding JSON format
            throw new ServiceException(ErrorCodes::API_INVALID_DATA_FORMAT);
        }

        /*
         * Response structure
         * {"code":0, "msg":"成功","result": "...."}
         */
        $errorCode = $result->code;
        if (!isNullOrEmpty($errorCode) && $errorCode != 0) {
            // API returned an error message
            throw new ServiceException(ErrorCodes::API_FUNCTION_ERROR, trim($result->code . ': ' . $result->message));
        }

        return $result->result;
    }

    /**
     * Returns simulated patient information
     *
     * @return PUMCHOperationInfo[]
     */
    private function simulatedData() {
        $simulated = '{
          "code": 0,
          "msg": "成功",
          "result": [
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "4000",
                "anesthesiaDoctorNO2": "14952",
                "anesthesiaDoctorNO3": "14953",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "1994-01-30",
                "createDatetime": "2023-03-31 15:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称4",
                "emergencyIndicator": "择期",
                "idCard": "370612199401302513",
                "idType": "5000",
                "inRoomDatetime": "2023-03-31 13:05:00",
                "inpatientID": "3137249",
                "lastUpdateDatetime": "2023-03-31 22:30:00",
                "name": "靓仔",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术1"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 14:00:00",
                "patientID": "46542111",
                "phone": "15653887735",
                "scheduled": "753653",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生5"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "4000",
                "anesthesiaDoctorNO2": "14952",
                "anesthesiaDoctorNO3": "14953",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "1994-01-30",
                "createDatetime": "2023-03-31 15:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称4",
                "emergencyIndicator": "择期",
                "idCard": "370612199401302513",
                "idType": "5000",
                "inRoomDatetime": "2023-03-31 13:05:00",
                "inpatientID": "3137251",
                "lastUpdateDatetime": "2023-03-31 22:30:00",
                "name": "靓仔",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术1"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 14:00:00",
                "patientID": "46542111",
                "phone": "15653887735",
                "scheduled": "753655",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生5"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "4000",
                "anesthesiaDoctorNO2": "14952",
                "anesthesiaDoctorNO3": "14953",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "1994-01-30",
                "createDatetime": "2023-03-31 15:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称4",
                "emergencyIndicator": "择期",
                "idCard": "370612199401302513",
                "idType": "5000",
                "inRoomDatetime": "2023-03-31 13:05:00",
                "inpatientID": "3137250",
                "lastUpdateDatetime": "2023-03-31 22:30:00",
                "name": "靓仔",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术1"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 14:00:00",
                "patientID": "46542111",
                "phone": "15653887735",
                "scheduled": "753654",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生5"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "4000",
                "anesthesiaDoctorNO2": "14952",
                "anesthesiaDoctorNO3": "14953",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称4",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-31 11:05:00",
                "inpatientID": "3137244",
                "lastUpdateDatetime": "2023-03-31 22:00:00",
                "name": "希望在田野",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术1"
                    },
                    {
                        "operationCode": "2",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术2"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 12:00:00",
                "patientID": "486352wx",
                "phone": null,
                "scheduled": "753651",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生5"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "4000",
                "anesthesiaDoctorNO2": "14952",
                "anesthesiaDoctorNO3": "14953",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称4",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-31 11:05:00",
                "inpatientID": "3137244",
                "lastUpdateDatetime": "2023-03-31 22:00:00",
                "name": "希望在田野",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术1"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 12:00:00",
                "patientID": "486352wx456789",
                "phone": null,
                "scheduled": "753651",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生5"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "4000",
                "anesthesiaDoctorNO2": "14952",
                "anesthesiaDoctorNO3": "14953",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称4",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-31 11:05:00",
                "inpatientID": "3137298",
                "lastUpdateDatetime": "2023-03-31 22:00:00",
                "name": "希望在田野",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术1"
                    },
                    {
                        "operationCode": "2",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术2"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 12:00:00",
                "patientID": "486352wx",
                "phone": null,
                "scheduled": "753651",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生5"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-31 11:05:00",
                "inpatientID": "3137242",
                "lastUpdateDatetime": "2023-03-31 14:00:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术1"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 12:00:00",
                "patientID": "48635222",
                "phone": null,
                "scheduled": "753651",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": "0",
                "inRoomDatetime": "2023-03-31 12:15:00",
                "inpatientID": "3137290",
                "lastUpdateDatetime": "2023-03-31 13:50:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 12:10:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 13:00:00",
                "patientID": "46542111",
                "phone": null,
                "scheduled": "753654",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "2000-01-01",
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": "159853",
                "idType": "5001",
                "inRoomDatetime": "2023-03-31 12:15:00",
                "inpatientID": "3137245",
                "lastUpdateDatetime": "2023-03-31 13:50:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 12:10:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 13:00:00",
                "patientID": "12332145",
                "phone": "15901316842",
                "scheduled": "753666",
                "sex": "女",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "2000-01-01",
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": "159853",
                "idType": "5001",
                "inRoomDatetime": "2023-03-31 12:15:00",
                "inpatientID": "3137291",
                "lastUpdateDatetime": "2023-03-31 13:50:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 12:10:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 13:00:00",
                "patientID": "12332145",
                "phone": "15901316842",
                "scheduled": "753664",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-31 12:15:00",
                "inpatientID": "3137242",
                "lastUpdateDatetime": "2023-03-31 13:50:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 12:10:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 13:00:00",
                "patientID": "48635222",
                "phone": null,
                "scheduled": "753652",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "1994-04-21",
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": "350721199404210049",
                "idType": "5000",
                "inRoomDatetime": "2023-03-31 12:15:00",
                "inpatientID": "3137292",
                "lastUpdateDatetime": "2023-03-31 13:50:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 12:10:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 13:00:00",
                "patientID": "45273721",
                "phone": "13635642013",
                "scheduled": "753664",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-31 12:15:00",
                "inpatientID": "3137242",
                "lastUpdateDatetime": "2023-03-31 13:50:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 12:10:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 13:00:00",
                "patientID": "48635222",
                "phone": null,
                "scheduled": "753653",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "1994-04-21",
                "createDatetime": "2023-03-31 13:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称3",
                "emergencyIndicator": "择期",
                "idCard": "350721199404210049",
                "idType": "5000",
                "inRoomDatetime": "2023-03-31 12:15:00",
                "inpatientID": "3137245",
                "lastUpdateDatetime": "2023-03-31 13:50:00",
                "name": "用户4",
                "operStatus": "S",
                "operatingDatetime": "2023-03-31 12:10:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-31 13:00:00",
                "patientID": "45273721",
                "phone": "13635642013",
                "scheduled": "753666",
                "sex": "女",
                "surgeonName": "医生",
                "surgeonName1": "医生4"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-30 05:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称1",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-30 03:05:00",
                "inpatientID": "3137241",
                "lastUpdateDatetime": "2023-03-30 14:00:00",
                "name": "用户1",
                "operStatus": "S",
                "operatingDatetime": "2023-03-30 11:00:00",
                "operatingRoomNO": "西601",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-30 04:00:00",
                "patientID": "47112766",
                "phone": null,
                "scheduled": "753649",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生2"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "3999",
                "anesthesiaDoctorNO2": "14951",
                "anesthesiaDoctorNO3": "14952",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "麻醉医生",
                "anesthesiaDoctorName2": "麻醉医生名",
                "anesthesiaDoctorName3": "麻醉医生2",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": "1991-11-15",
                "createDatetime": "2023-03-30 05:00:00",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "诊断名称2",
                "emergencyIndicator": "择期",
                "idCard": "342501199111153419",
                "idType": "5000",
                "inRoomDatetime": "2023-03-30 03:05:00",
                "inpatientID": "3137242",
                "lastUpdateDatetime": "2023-03-30 14:00:00",
                "name": "用户2",
                "operStatus": "S",
                "operatingDatetime": "2023-03-30 11:00:00",
                "operatingRoomNO": "西602",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术"
                    },
                    {
                        "operationCode": "2",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-30 04:00:00",
                "patientID": "48635222",
                "phone": "15618759969",
                "scheduled": "753650",
                "sex": "男",
                "surgeonName": "医生",
                "surgeonName1": "医生3"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "03982",
                "anesthesiaDoctorNO2": "12591",
                "anesthesiaDoctorNO3": "04573",
                "anesthesiaDoctorNO4": "",
                "anesthesiaDoctorName": "徐庆",
                "anesthesiaDoctorName2": "崔雀玄",
                "anesthesiaDoctorName3": "虞雪融",
                "anesthesiaDoctorName4": "",
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-03-26 02:00:21",
                "crmID": null,
                "deptName": "整形美容外科病房(西院)(国际医疗)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "胸部瘢痕疙瘩3",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": "0",
                "inRoomDatetime": "2023-03-26 02:05:21",
                "inpatientID": "3137240",
                "lastUpdateDatetime": "2023-03-26 02:05:21",
                "name": "秦时1",
                "operStatus": "S",
                "operatingDatetime": "2023-03-26 10:00:00",
                "operatingRoomNO": "西601",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "前胸皮肤肿物切除术+右腋下皮肤肿物切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-26 04:05:21",
                "patientID": "46542111",
                "phone": null,
                "scheduled": "753648",
                "sex": "男",
                "surgeonName": "王友彬",
                "surgeonName1": "孟湉"
            },
            {
                "age": "50岁",
                "anesthesiaDoctorNO": "10684",
                "anesthesiaDoctorNO2": "V20220664",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "李敏娜",
                "anesthesiaDoctorName2": "刘诗鸣",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "13",
                "birthDay": "1978-02-13",
                "createDatetime": "2023-03-23 01:00:17",
                "crmID": null,
                "deptName": "日间妇科内分泌中心(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "子宫腺肌症",
                "emergencyIndicator": "择期",
                "idCard": "111977365982652510",
                "idType": "5000",
                "inRoomDatetime": "2023-03-23 00:15:00",
                "inpatientID": "2445498",
                "lastUpdateDatetime": "2023-03-23 01:30:16",
                "name": "龚二三",
                "operStatus": "S",
                "operatingDatetime": "2023-03-23 08:00:00",
                "operatingRoomNO": "西601",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "宫内节育器放置术"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-03-23 01:15:00",
                "patientID": "40643238",
                "phone": "13333333215",
                "scheduled": "811343",
                "sex": "女",
                "surgeonName": "罗敏",
                "surgeonName1": null
            },
            {
                "age": "11岁",
                "anesthesiaDoctorNO": "60048",
                "anesthesiaDoctorNO2": "60047",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "杨树峰",
                "anesthesiaDoctorName2": "王素梅",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "62",
                "birthDay": "1977-03-18",
                "createDatetime": "2023-03-23 00:30:00",
                "crmID": null,
                "deptName": "日间整形美容外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "硬皮病",
                "emergencyIndicator": "择期",
                "idCard": "265566195003184523",
                "idType": "5000",
                "inRoomDatetime": "2023-03-23 00:21:00",
                "inpatientID": "W275551",
                "lastUpdateDatetime": "2023-03-23 00:30:21",
                "name": "张大彪",
                "operStatus": "S",
                "operatingDatetime": "2023-03-23 08:00:00",
                "operatingRoomNO": "西601",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "自体脂肪注射移植术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-23 00:30:00",
                "patientID": "44317388",
                "phone": "13777777777",
                "scheduled": "751024",
                "sex": "男",
                "surgeonName": "龙笑",
                "surgeonName1": "黄久佐"
            },
            {
                "age": "53岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "w22036",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "尹雪蕾",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "23",
                "birthDay": "1980-08-05",
                "createDatetime": "2023-03-22 06:34:53",
                "crmID": null,
                "deptName": "日间医疗中心妇产科学系(西院)(普通)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "绝经后子宫内膜增厚",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": "0",
                "inRoomDatetime": "2023-03-22 02:46:00",
                "inpatientID": "5171176",
                "lastUpdateDatetime": "2023-03-22 06:34:53",
                "name": "罗二二",
                "operStatus": "S",
                "operatingDatetime": "2023-03-22 11:00:00",
                "operatingRoomNO": "西604",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "宫腔镜检查术"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-03-22 03:35:00",
                "patientID": "19121336",
                "phone": null,
                "scheduled": "802900",
                "sex": "女",
                "surgeonName": "仝佳丽",
                "surgeonName1": "宋爽"
            },
            {
                "age": "31岁",
                "anesthesiaDoctorNO": "10820",
                "anesthesiaDoctorNO2": "S2022004030",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "吴昊",
                "anesthesiaDoctorName2": "陈皓天",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "26",
                "birthDay": "1992-02-15",
                "createDatetime": "2023-03-22 06:34:53",
                "crmID": null,
                "deptName": "日间妇科内分泌中心(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "子宫内膜不典型增生",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": "0",
                "inRoomDatetime": "2023-03-22 03:59:00",
                "inpatientID": "3150679",
                "lastUpdateDatetime": "2023-03-22 06:34:53",
                "name": "冯二二",
                "operStatus": "S",
                "operatingDatetime": "2023-03-22 08:00:00",
                "operatingRoomNO": "西601",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "刮宫术"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-03-22 04:50:00",
                "patientID": "48171083",
                "phone": null,
                "scheduled": "802803",
                "sex": "女",
                "surgeonName": "王阳        ",
                "surgeonName1": null
            },
            {
                "age": "21岁",
                "anesthesiaDoctorNO": "11568",
                "anesthesiaDoctorNO2": "V20220668",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "王瑾",
                "anesthesiaDoctorName2": "鲁晓旭",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "27",
                "birthDay": "2002-08-05",
                "createDatetime": "2023-03-22 02:30:21",
                "crmID": null,
                "deptName": "整形美容外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "右侧面部肿胀",
                "emergencyIndicator": "择期",
                "idCard": "113475626516562365",
                "idType": "5001",
                "inRoomDatetime": "2023-03-22 03:05:21",
                "inpatientID": "3152258",
                "lastUpdateDatetime": "2023-03-22 05:30:21",
                "name": "接化发",
                "operStatus": "S",
                "operatingDatetime": "2023-03-22 11:00:00",
                "operatingRoomNO": "西609",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "面部吸脂"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-03-22 05:05:21",
                "patientID": "48231188",
                "phone": "19999999999",
                "scheduled": "789981",
                "sex": "男",
                "surgeonName": "龙笑",
                "surgeonName1": "肖一丁"
            },
            {
                "age": "52岁",
                "anesthesiaDoctorNO": "10684",
                "anesthesiaDoctorNO2": "V20220664",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "李敏娜",
                "anesthesiaDoctorName2": "刘诗鸣",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "16",
                "birthDay": null,
                "createDatetime": "2023-03-22 03:30:17",
                "crmID": null,
                "deptName": "日间妇科内分泌中心(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "子宫内膜回声不均",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-03-22 02:14:00",
                "inpatientID": "5176258",
                "lastUpdateDatetime": "2023-03-22 03:30:17",
                "name": "龚二二",
                "operStatus": "S",
                "operatingDatetime": "2023-03-22 08:00:00",
                "operatingRoomNO": "西601",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经宫腔镜子宫内膜息肉切除术基价(1个息肉)"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-03-22 03:30:00",
                "patientID": "47775689",
                "phone": null,
                "scheduled": "812304",
                "sex": "女",
                "surgeonName": "罗敏",
                "surgeonName1": null
            },
            {
                "age": "34岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "V20220669",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "关景朋",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "16",
                "birthDay": null,
                "createDatetime": "2023-02-03 07:00:17",
                "crmID": null,
                "deptName": "日间口腔科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "阻生齿",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 06:16:00",
                "inpatientID": "5157127",
                "lastUpdateDatetime": "2023-02-06 00:57:14",
                "name": "白展堂",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 06:00:00",
                "operatingRoomNO": "西610",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "复杂牙拔除"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-03 08:15:00",
                "patientID": "43591142",
                "phone": null,
                "scheduled": "797614",
                "sex": "女",
                "surgeonName": "余立江",
                "surgeonName1": "刘寅冬"
            },
            {
                "age": "35岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "V20220669",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "关景朋",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "62",
                "birthDay": null,
                "createDatetime": "2023-02-03 05:45:45",
                "crmID": null,
                "deptName": "日间口腔科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "阻生齿",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 04:38:00",
                "inpatientID": "5159993",
                "lastUpdateDatetime": "2023-02-03 07:00:17",
                "name": "李四",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 02:00:00",
                "operatingRoomNO": "西610",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "复杂牙拔除"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-03 05:55:00",
                "patientID": "48500298",
                "phone": null,
                "scheduled": "796086",
                "sex": "男",
                "surgeonName": "张韬",
                "surgeonName1": "刘寅冬"
            },
            {
                "age": "28岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "22",
                "birthDay": null,
                "createDatetime": "2023-02-03 05:45:45",
                "crmID": null,
                "deptName": "日间医疗中心妇产科学系(西院)(普通)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "宫颈病变",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 05:13:00",
                "inpatientID": "5167283",
                "lastUpdateDatetime": "2023-02-03 07:00:17",
                "name": "佟掌柜",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 05:00:00",
                "operatingRoomNO": "西604",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "宫颈环形电切术"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-02-03 05:55:00",
                "patientID": "46555629",
                "phone": null,
                "scheduled": "796785",
                "sex": "女",
                "surgeonName": "仝佳丽",
                "surgeonName1": "宋爽"
            },
            {
                "age": "38岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "19",
                "birthDay": null,
                "createDatetime": "2023-02-03 04:40:51",
                "crmID": null,
                "deptName": "日间医疗中心妇产科学系(西院)(普通)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "子宫内膜增生",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 04:18:00",
                "inpatientID": "3134724",
                "lastUpdateDatetime": "2023-02-03 05:45:45",
                "name": "涵涵",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 04:00:00",
                "operatingRoomNO": "西604",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "诊断性刮宫"
                    },
                    {
                        "operationCode": "2",
                        "operationName": "宫腔镜检查术"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-02-03 05:05:00",
                "patientID": "17833253",
                "phone": null,
                "scheduled": "796767",
                "sex": "女",
                "surgeonName": "仝佳丽",
                "surgeonName1": "宋爽"
            },
            {
                "age": "40岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "21",
                "birthDay": null,
                "createDatetime": "2023-02-03 04:19:01",
                "crmID": null,
                "deptName": "日间医疗中心妇产科学系(西院)(普通)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "宫腔占位",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 01:16:00",
                "inpatientID": "5167577",
                "lastUpdateDatetime": "2023-02-03 04:19:01",
                "name": "王五",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 01:00:00",
                "operatingRoomNO": "西604",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经宫腔镜子宫内膜息肉切除术基价(1个息肉)"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-02-03 02:05:00",
                "patientID": "19240738",
                "phone": null,
                "scheduled": "796600",
                "sex": "女",
                "surgeonName": "仝佳丽",
                "surgeonName1": "宋爽"
            },
            {
                "age": "27岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "V20220669",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "关景朋",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "61",
                "birthDay": null,
                "createDatetime": "2023-02-03 04:19:01",
                "crmID": null,
                "deptName": "日间口腔科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "阻生齿",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 00:48:00",
                "inpatientID": "5168135",
                "lastUpdateDatetime": "2023-02-03 04:19:01",
                "name": "张三",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 00:00:00",
                "operatingRoomNO": "西610",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "复杂牙拔除"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-03 03:20:00",
                "patientID": "47027137",
                "phone": null,
                "scheduled": "796089",
                "sex": "男",
                "surgeonName": "张韬",
                "surgeonName1": "刘寅冬"
            },
            {
                "age": "31岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "23",
                "birthDay": null,
                "createDatetime": "2023-02-03 04:19:01",
                "crmID": null,
                "deptName": "日间医疗中心妇产科学系(西院)(普通)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "子宫内膜息肉",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 00:14:00",
                "inpatientID": "2552095",
                "lastUpdateDatetime": "2023-02-03 04:19:01",
                "name": "陈二",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 00:00:00",
                "operatingRoomNO": "西604",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经宫腔镜子宫内膜息肉刨削"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-02-03 01:10:00",
                "patientID": "46547988",
                "phone": null,
                "scheduled": "796585",
                "sex": "女",
                "surgeonName": "仝佳丽       ",
                "surgeonName1": "宋爽"
            },
            {
                "age": "40岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "20",
                "birthDay": null,
                "createDatetime": "2023-02-03 04:19:01",
                "crmID": null,
                "deptName": "日间医疗中心妇产科学系(西院)(普通)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "子宫内膜增厚",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 02:11:00",
                "inpatientID": "3102884",
                "lastUpdateDatetime": "2023-02-03 04:19:01",
                "name": "邓伦",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 02:00:00",
                "operatingRoomNO": "西604",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经宫腔镜子宫内膜息肉切除术基价(1个息肉)"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-02-03 03:10:00",
                "patientID": "42172804",
                "phone": null,
                "scheduled": "796603",
                "sex": "女",
                "surgeonName": "仝佳丽",
                "surgeonName1": "宋爽"
            },
            {
                "age": "38岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "24",
                "birthDay": null,
                "createDatetime": "2023-02-03 04:19:01",
                "crmID": null,
                "deptName": "日间医疗中心妇产科学系(西院)(普通)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "子宫内膜癌",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-03 03:39:00",
                "inpatientID": "2158369",
                "lastUpdateDatetime": "2023-02-03 04:19:01",
                "name": "坤坤",
                "operStatus": "S",
                "operatingDatetime": "2023-02-03 03:00:00",
                "operatingRoomNO": "西604",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "宫腔镜检查术"
                    }
                ],
                "operationPosition": "截石位",
                "outRoomDatetime": "2023-02-03 04:15:00",
                "patientID": "42655860",
                "phone": null,
                "scheduled": "796762",
                "sex": "女",
                "surgeonName": "仝佳丽       ",
                "surgeonName1": "宋爽"
            },
            {
                "age": "34岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "W22013",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "刘岳",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "07",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间基本外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "胆囊结石",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 04:37:00",
                "inpatientID": "2480588",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "健健",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 03:00:00",
                "operatingRoomNO": "西603",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经腹腔镜胆囊切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-02 05:50:00",
                "patientID": "48066434",
                "phone": null,
                "scheduled": "796079",
                "sex": "男",
                "surgeonName": "刘卫",
                "surgeonName1": null
            },
            {
                "age": "30岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "W22013",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "刘岳",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "08",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间基本外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "痔",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 06:12:00",
                "inpatientID": "5159764",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "红太狼",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 04:00:00",
                "operatingRoomNO": "西603",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "外痔切除术"
                    }
                ],
                "operationPosition": "左侧卧位",
                "outRoomDatetime": "2023-02-02 07:55:00",
                "patientID": "45204089",
                "phone": null,
                "scheduled": "796071",
                "sex": "男",
                "surgeonName": "徐徕",
                "surgeonName1": "黄华"
            },
            {
                "age": "16岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "65",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间口腔科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "阻生齿",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 05:32:00",
                "inpatientID": "5150386",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "张瑜",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 03:00:00",
                "operatingRoomNO": "西610",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "复杂牙拔除"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-02 07:40:00",
                "patientID": "42382753",
                "phone": null,
                "scheduled": "796499",
                "sex": "女",
                "surgeonName": "石钿印",
                "surgeonName1": null
            },
            {
                "age": "37岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "W22013",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "刘岳",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "14",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间基本外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "胆囊结石",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 01:32:00",
                "inpatientID": "2416990",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "灰太狼",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 01:00:00",
                "operatingRoomNO": "西603",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经腹腔镜胆囊切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-02 02:55:00",
                "patientID": "45927002",
                "phone": null,
                "scheduled": "796077",
                "sex": "女",
                "surgeonName": "刘卫        ",
                "surgeonName1": null
            },
            {
                "age": "41岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "W22013",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "刘岳",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "15",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间基本外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "胆囊结石",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 00:13:00",
                "inpatientID": "5168345",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "熊二",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 00:00:00",
                "operatingRoomNO": "西603",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经腹腔镜胆囊切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-02 01:25:00",
                "patientID": "46665673",
                "phone": null,
                "scheduled": "796076",
                "sex": "女",
                "surgeonName": "刘卫",
                "surgeonName1": "黄华"
            },
            {
                "age": "48岁",
                "anesthesiaDoctorNO": "10541",
                "anesthesiaDoctorNO2": "W22013",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "陈雯",
                "anesthesiaDoctorName2": "刘岳",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "13",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间基本外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "痔",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 08:00:00",
                "inpatientID": "3155190",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "熊大",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 05:00:00",
                "operatingRoomNO": "西603",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "外痔切除术"
                    }
                ],
                "operationPosition": "左侧卧位",
                "outRoomDatetime": "2023-02-02 09:20:00",
                "patientID": "47218670",
                "phone": null,
                "scheduled": "796074",
                "sex": "男",
                "surgeonName": "徐徕",
                "surgeonName1": null
            },
            {
                "age": "31岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "64",
                "birthDay": "1982-10-01",
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间口腔科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "阻生齿",
                "emergencyIndicator": "择期",
                "idCard": "370883198210010034",
                "idType": "5000",
                "inRoomDatetime": "2023-02-02 01:58:00",
                "inpatientID": "5163733",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "田青",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 04:00:00",
                "operatingRoomNO": "西610",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "复杂牙拔除"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-02 05:10:00",
                "patientID": "47329630",
                "phone": "18611111111",
                "scheduled": "796497",
                "sex": "男",
                "surgeonName": "石钿印",
                "surgeonName1": null
            },
            {
                "age": "14岁",
                "anesthesiaDoctorNO": "05120",
                "anesthesiaDoctorNO2": "V20220683",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "卢素芳",
                "anesthesiaDoctorName2": "周攀科",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "28",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间口腔科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "阻生齿",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 00:19:00",
                "inpatientID": "5161541",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "薛强",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 00:00:00",
                "operatingRoomNO": "西610",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "复杂牙拔除"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-02 01:50:00",
                "patientID": "43403152",
                "phone": null,
                "scheduled": "796610",
                "sex": "男",
                "surgeonName": "石钿印",
                "surgeonName1": null
            },
            {
                "age": "40岁",
                "anesthesiaDoctorNO": "10124",
                "anesthesiaDoctorNO2": "W22013",
                "anesthesiaDoctorNO3": null,
                "anesthesiaDoctorNO4": null,
                "anesthesiaDoctorName": "朱波",
                "anesthesiaDoctorName2": "刘岳",
                "anesthesiaDoctorName3": null,
                "anesthesiaDoctorName4": null,
                "anesthesiaMethod": "全身麻醉",
                "bedNO": "16",
                "birthDay": null,
                "createDatetime": "2023-02-02 13:54:27",
                "crmID": null,
                "deptName": "日间基本外科(西院)",
                "deptWard": "日间病房西院",
                "diagBeforeOperation": "胆囊结石",
                "emergencyIndicator": "择期",
                "idCard": null,
                "idType": null,
                "inRoomDatetime": "2023-02-02 03:07:00",
                "inpatientID": "2480679",
                "lastUpdateDatetime": "2023-02-02 13:54:27",
                "name": "宁宁",
                "operStatus": "S",
                "operatingDatetime": "2023-02-02 02:00:00",
                "operatingRoomNO": "西603",
                "operationList": [
                    {
                        "operationCode": "1",
                        "operationName": "经腹腔镜胆囊切除术"
                    }
                ],
                "operationPosition": "仰卧位",
                "outRoomDatetime": "2023-02-02 04:30:00",
                "patientID": "18682836",
                "phone": null,
                "scheduled": "796078",
                "sex": "女",
                "surgeonName": "刘卫",
                "surgeonName1": "黄华"
            }
          ]
        }';

        $resp = json_decode($simulated);
        return $resp->result;
    }

    /**
     *
     * @param stdClass $opInfo
     */
    static public function anonymizeOperationData($opInfo) {
        if (!$opInfo) {
            return;
        }
        if (!empty($opInfo->phone)) {
            $prefix = '';
            if (startsWith('+', $opInfo->phone)) {
                $prefix = substr($opInfo->phone, 0, 3);
                $phone = substr($opInfo->phone, 3);
            } else {
                $phone = $opInfo->phone;
            }
            $opInfo->phone = $prefix . '999' . $phone;
        }
    }
}