<?php

class PUMCHAPI {
    private static $instance;
    private $endpoint;
    private $timeout = 30;
    /** @var int */
    private $delay;
    private $lastRequestTime = 0;
    private static $cache = [];

    private function __construct($endpoint, $timeout = null) {
        $this->endpoint = trim($endpoint, '/') . '/';
        if (is_numeric($timeout) && intval($timeout) > 0) {
            $this->timeout = intval($timeout);
        }
        /* Time between requests to the PUMCH API to avoid blocking the server */
        $this->delay = $GLOBALS['PUMCH_REQUEST_DELAY'];
    }

    static public function getInstance() {
        if (!self::$instance) {
            self::$instance = new PUMCHAPI($GLOBALS['PUMCH_API_URL'], $GLOBALS['PUMCH_API_TIMEOUT']);
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
            $params['opdatetime'] = $fromDate;
            $resp = $this->invokeAPI('queryOperationXyqData', $params, false);
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

        foreach ($resp as $info) {
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
    private function invokeAPI($function, $params, $sendAsJson = true, $usePOST = false) {
        $this->httpStatus = null;

        $endpoint = $this->endpoint . $function;
        $errorMsg = null;
        $headers = [];

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

        $APIResponse = curl_exec($curl);
        if ($APIResponse === false) {
            $errorMsg = 'CURL error: ' . curl_error($curl);
        }

        curl_close($curl);

        if ($errorMsg) {
            throw new ServiceException(ErrorCodes::API_COMM_ERROR, $errorMsg);
        }
        if (!$APIResponse && !startsWith('2', $this->httpStatus)) {
            // The did not provide any information but responded with an HTTP error status
            throw new ServiceException(ErrorCodes::API_ERROR_STATUS, $this->httpStatus);
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
        if (preg_match('/^HTTP[^\s]*\s(\d+)*/', $header_line, $matches)) {
            $this->httpStatus = $matches[1];
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

        $errorCode = $result->code;
        if (!isNullOrEmpty($errorCode) && $errorCode != 0) {
            // API returned an error message
            throw new ServiceException(ErrorCodes::API_FUNCTION_ERROR, trim($result->code . ': ' . $result->msg));
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
              "crm_id": "7fce43c8-7b12-4023-896c-f615f43ef267",
              "scheduled": "789001",
              "inpatientID": "inpt01",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "20",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-27 13:00:00",
              "diagbeforeoperation": "宫腔粘连",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "杜俊平",
              "anesthesiadoctor2": "S2022002069",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "经宫腔镜粘连分离术",
              "name": "孟雪倩",
              "sex": "女",
              "age": "30岁",
              "inRoomDatetime": "2022-12-27 10:05:00",
              "outRoomDatetime": "2022-12-27 11:00:00",
              "operStatus": "S",
              "patientID": "ptid01",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789002",
              "inpatientID": "inpt02",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "19",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-27 12:00:00",
              "diagbeforeoperation": "子宫内膜增生",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "杜俊平",
              "anesthesiadoctor2": "S2022002069",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "子宫内膜息肉切除术(妇产科)",
              "name": "尤素梅",
              "sex": "女",
              "age": "35岁",
              "inRoomDatetime": "2022-12-27 08:16:00",
              "outRoomDatetime": "2022-12-27 09:10:00",
              "operStatus": "S",
              "patientID": "ptid02",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789003",
              "inpatientID": "inpt03",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "21",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-27 11:00:00",
              "diagbeforeoperation": "子宫内膜息肉",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "杜俊平",
              "anesthesiadoctor2": "S2022002069",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫腔镜检查+治疗",
              "name": "闫海娥",
              "sex": "女",
              "age": "42岁",
              "inRoomDatetime": "2022-12-27 12:56:00",
              "outRoomDatetime": "2022-12-27 13:50:00",
              "operStatus": "S",
              "patientID": "ptid03",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789004",
              "inpatientID": "inpt04",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "18",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-27 10:00:00",
              "diagbeforeoperation": "宫颈病变",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "杜俊平",
              "anesthesiadoctor2": "S2022002069",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "leep",
              "name": "刘薇",
              "sex": "女",
              "age": "41岁",
              "inRoomDatetime": "2022-12-27 11:20:00",
              "outRoomDatetime": "2022-12-27 12:00:00",
              "operStatus": "S",
              "patientID": "ptid04",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789005",
              "inpatientID": "inpt05",
              "deptstayed": "日间整形美容外科(西院)",
              "department": "日间病房西院",
              "bedNo": "28",
              "operatingroomno": "西602",
              "operatingdatetime": "2022-12-27 10:00:00",
              "diagbeforeoperation": "上唇缺损",
              "emergencyindicator": "择期",
              "surgeonname": "龙笑",
              "surgeonname1": "杜奉舟",
              "anesthesiadoctorname": "权翔",
              "anesthesiadoctor": "10542",
              "anesthesiadoctorname2": "毕耀丹",
              "anesthesiadoctor2": "12592",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "仰卧位",
              "operationName": "上唇缺损修复术",
              "name": "李文静",
              "sex": "女",
              "age": "29岁",
              "inRoomDatetime": "2022-12-27 10:23:00",
              "outRoomDatetime": "2022-12-27 12:30:00",
              "operStatus": "S",
              "patientID": "ptid05",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789006",
              "inpatientID": "inpt06",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "16",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-27 09:00:00",
              "diagbeforeoperation": "子宫内膜息肉",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "杜俊平",
              "anesthesiadoctor2": "S2022002069",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫腔镜检查+治疗",
              "name": "马晓卉",
              "sex": "女",
              "age": "31岁",
              "inRoomDatetime": "2022-12-27 12:04:00",
              "outRoomDatetime": "2022-12-27 12:55:00",
              "operStatus": "S",
              "patientID": "ptid06",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789007",
              "inpatientID": "inpt07",
              "deptstayed": "日间妇科内分泌中心(西院)",
              "department": "日间病房西院",
              "bedNo": "24",
              "operatingroomno": "西601",
              "operatingdatetime": "2022-12-27 08:00:00",
              "diagbeforeoperation": "子宫内膜不典型增生",
              "emergencyindicator": "择期",
              "surgeonname": "邓姗",
              "surgeonname1": null,
              "anesthesiadoctorname": "权翔",
              "anesthesiadoctor": "10542",
              "anesthesiadoctorname2": "张杨阳",
              "anesthesiadoctor2": "60245",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫腔镜检查",
              "name": "宫婷",
              "sex": "女",
              "age": "34岁",
              "inRoomDatetime": "2022-12-27 09:36:00",
              "outRoomDatetime": "2022-12-27 10:45:00",
              "operStatus": "S",
              "patientID": "ptid07",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789008",
              "inpatientID": "inpt08",
              "deptstayed": "",
              "department": "日间病房西院",
              "bedNo": "",
              "operatingroomno": "西601",
              "operatingdatetime": "2022-12-27 08:00:00",
              "diagbeforeoperation": "子宫内膜息肉",
              "emergencyindicator": "择期",
              "surgeonname": "邓姗",
              "surgeonname1": null,
              "anesthesiadoctorname": "权翔",
              "anesthesiadoctor": "10542",
              "anesthesiadoctorname2": "张杨阳",
              "anesthesiadoctor2": "60245",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫腔镜子宫内膜息肉切除术",
              "name": "陈洁",
              "sex": "女",
              "age": "40岁",
              "inRoomDatetime": "2022-12-27 08:25:00",
              "outRoomDatetime": "2022-12-27 09:30:00",
              "operStatus": "C",
              "patientID": "ptid08",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789009",
              "inpatientID": "inpt09",
              "deptstayed": "日间整形美容外科(西院)",
              "department": "日间病房西院",
              "bedNo": "57",
              "operatingroomno": "西602",
              "operatingdatetime": "2022-12-27 08:00:00",
              "diagbeforeoperation": "局限性硬皮病",
              "emergencyindicator": "择期",
              "surgeonname": "龙笑",
              "surgeonname1": "肖一丁",
              "anesthesiadoctorname": "权翔",
              "anesthesiadoctor": "10542",
              "anesthesiadoctorname2": "毕耀丹",
              "anesthesiadoctor2": "12592",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "仰卧位",
              "operationName": "自体脂肪注射移植术",
              "name": "王士明",
              "sex": "男",
              "age": "28岁",
              "inRoomDatetime": "2022-12-27 08:14:00",
              "outRoomDatetime": "2022-12-27 10:15:00",
              "operStatus": "S",
              "patientID": "ptid09",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789010",
              "inpatientID": "inpt09",
              "deptstayed": "日间整形美容外科(西院)",
              "department": "日间病房西院",
              "bedNo": "57",
              "operatingroomno": "西602",
              "operatingdatetime": "2022-12-27 08:00:00",
              "diagbeforeoperation": "局限性硬皮病",
              "emergencyindicator": "择期",
              "surgeonname": "龙笑",
              "surgeonname1": "肖一丁",
              "anesthesiadoctorname": "权翔",
              "anesthesiadoctor": "10542",
              "anesthesiadoctorname2": "毕耀丹",
              "anesthesiadoctor2": "12592",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "仰卧位",
              "operationName": "副)脂肪抽吸术(吸引器法)≤15*20cm",
              "name": "王士明",
              "sex": "男",
              "age": "28岁",
              "inRoomDatetime": "2022-12-27 08:14:00",
              "outRoomDatetime": "2022-12-27 10:15:00",
              "operStatus": "S",
              "patientID": "ptid09",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789011",
              "inpatientID": "inpt10",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "17",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-27 08:00:00",
              "diagbeforeoperation": "宫颈病变",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "杜俊平",
              "anesthesiadoctor2": "S2022002069",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫颈环形电切术",
              "name": "盛梅",
              "sex": "女",
              "age": "48岁",
              "inRoomDatetime": "2022-12-27 09:15:00",
              "outRoomDatetime": "2022-12-27 09:55:00",
              "operStatus": "S",
              "patientID": "ptid10",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789012",
              "inpatientID": "inpt11",
              "deptstayed": "日间妇科内分泌中心(西院)",
              "department": "日间病房西院",
              "bedNo": "26",
              "operatingroomno": "西601",
              "operatingdatetime": "2022-12-27 08:00:00",
              "diagbeforeoperation": "粘膜下子宫肌瘤",
              "emergencyindicator": "择期",
              "surgeonname": "邓姗",
              "surgeonname1": null,
              "anesthesiadoctorname": "权翔",
              "anesthesiadoctor": "10542",
              "anesthesiadoctorname2": "张杨阳",
              "anesthesiadoctor2": "60245",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫腔镜检查",
              "name": "谢媛媛",
              "sex": "女",
              "age": "41岁",
              "inRoomDatetime": "2022-12-27 10:43:00",
              "outRoomDatetime": "2022-12-27 11:40:00",
              "operStatus": "S",
              "patientID": "ptid11",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "788013",
              "inpatientID": "inpt12",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "09",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-26 12:00:00",
              "diagbeforeoperation": "宫颈病变",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "张宁晨",
              "anesthesiadoctor2": "60242",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫颈环形电切术",
              "name": "刘慧娟",
              "sex": "女",
              "age": "38岁",
              "inRoomDatetime": "2022-12-26 12:26:00",
              "outRoomDatetime": "2022-12-26 13:15:00",
              "operStatus": "S",
              "patientID": "ptid12",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789014",
              "inpatientID": "inpt13",
              "deptstayed": "日间血管外科(西院)",
              "department": "日间病房西院",
              "bedNo": "29",
              "operatingroomno": "西609",
              "operatingdatetime": "2022-12-26 12:00:00",
              "diagbeforeoperation": "右侧大隐静脉曲张",
              "emergencyindicator": "择期",
              "surgeonname": "李方达",
              "surgeonname1": "顾光超",
              "anesthesiadoctorname": "崔旭蕾",
              "anesthesiadoctor": "10685",
              "anesthesiadoctorname2": "金迪",
              "anesthesiadoctor2": "12590",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "监护性麻醉管理",
              "operationPosition": "仰卧位",
              "operationName": "右侧小腿曲张静脉剥脱、结扎术",
              "name": "邓子龙",
              "sex": "男",
              "age": "37岁",
              "inRoomDatetime": "2022-12-26 13:40:00",
              "outRoomDatetime": "2022-12-26 15:45:00",
              "operStatus": "S",
              "patientID": "ptid13",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789015",
              "inpatientID": "inpt14",
              "deptstayed": "日间骨科(西院)",
              "department": "日间病房西院",
              "bedNo": "61",
              "operatingroomno": "西601",
              "operatingdatetime": "2022-12-26 12:00:00",
              "diagbeforeoperation": "椎间盘突出",
              "emergencyindicator": "择期",
              "surgeonname": "边焱焱",
              "surgeonname1": "余可谊",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "毕耀丹",
              "anesthesiadoctor2": "12592",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "俯卧位",
              "operationName": "经皮内镜椎板间入路椎间盘摘除术",
              "name": "郭晓奥",
              "sex": "女",
              "age": "35岁",
              "inRoomDatetime": "2022-12-26 10:20:00",
              "outRoomDatetime": "2022-12-26 12:55:00",
              "operStatus": "S",
              "patientID": "ptid14",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "788016",
              "inpatientID": "inpt15",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "10",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-26 11:00:00",
              "diagbeforeoperation": "宫颈病变",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "张宁晨",
              "anesthesiadoctor2": "60242",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫颈环形电切术",
              "name": "果秀花",
              "sex": "女",
              "age": "32岁",
              "inRoomDatetime": "2022-12-26 11:47:00",
              "outRoomDatetime": "2022-12-26 12:20:00",
              "operStatus": "S",
              "patientID": "ptid15",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "789017",
              "inpatientID": "inpt16",
              "deptstayed": "日间骨科(西院)",
              "department": "日间病房西院",
              "bedNo": "58",
              "operatingroomno": "西601",
              "operatingdatetime": "2022-12-26 08:00:00",
              "diagbeforeoperation": "半月板损伤",
              "emergencyindicator": "择期",
              "surgeonname": "钱军",
              "surgeonname1": "鲁昕",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "毕耀丹",
              "anesthesiadoctor2": "12592",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "仰卧位",
              "operationName": "右膝关节镜检查+部分半月板切除成形术",
              "name": "郝志化",
              "sex": "男",
              "age": "30岁",
              "inRoomDatetime": "2022-12-26 08:28:00",
              "outRoomDatetime": "2022-12-26 10:15:00",
              "operStatus": "S",
              "patientID": "ptid16",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "788018",
              "inpatientID": "inpt17",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "07",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-26 08:00:00",
              "diagbeforeoperation": "子宫内膜增厚",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "张宁晨",
              "anesthesiadoctor2": "60242",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "经宫腔镜子宫内膜息肉切除术每增加1个息肉加收",
              "name": "王忠芬",
              "sex": "女",
              "age": "42岁",
              "inRoomDatetime": "2022-12-26 08:19:00",
              "outRoomDatetime": "2022-12-26 09:10:00",
              "operStatus": "S",
              "patientID": "ptid17",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "788019",
              "inpatientID": "inpt18",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "11",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-26 08:00:00",
              "diagbeforeoperation": "子宫内膜增厚",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "张宁晨",
              "anesthesiadoctor2": "60242",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "经宫腔镜子宫内膜息肉切除术每增加1个息肉加收",
              "name": "佟燕辉",
              "sex": "女",
              "age": "45岁",
              "inRoomDatetime": "2022-12-26 09:20:00",
              "outRoomDatetime": "2022-12-26 10:25:00",
              "operStatus": "S",
              "patientID": "ptid18",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
            },
            {
              "scheduled": "788020",
              "inpatientID": "inpt19",
              "deptstayed": "日间医疗中心妇产科学系(西院)(普通)",
              "department": "日间病房西院",
              "bedNo": "10",
              "operatingroomno": "西604",
              "operatingdatetime": "2022-12-26 11:00:00",
              "diagbeforeoperation": "宫颈病变",
              "emergencyindicator": "择期",
              "surgeonname": "仝佳丽",
              "surgeonname1": "张国瑞",
              "anesthesiadoctorname": "朱波",
              "anesthesiadoctor": "10124",
              "anesthesiadoctorname2": "张宁晨",
              "anesthesiadoctor2": "60242",
              "anesthesiadoctorname3": null,
              "anesthesiadoctor3": null,
              "anesthesiadoctorname4": null,
              "anesthesiaDoctor4": null,
              "anesthesiaMethod": "全身麻醉",
              "operationPosition": "截石位",
              "operationName": "宫颈环形电切术",
              "name": "佟燕辉",
              "sex": "女",
              "age": "45岁",
              "inRoomDatetime": "2023-01-05 09:20:00",
              "outRoomDatetime": "2023-01-05 10:25:00",
              "operStatus": "S",
              "patientID": "ptid18",
              "phone": null,
              "lastUpdateDateTime": "2023-03-01",
              "createDateTime": "2023-03-01"
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