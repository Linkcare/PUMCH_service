<?php
session_start();

/*
 * CUSTOMIZABLE CONFIGURATION VARIABLES
 * To override the default values defined below, create a file named conf/configuration.php in the service root directory and replace the value of the
 * desired variables by a custom value
 */
/**
 * ** REQUIRED CONFIGURATION PARAMETERS ***
 */
/* Administrator username for accessing to the status page of the service */
$GLOBALS['SUPERADMIN_USER'] = 'pumch';
/* Administrator password for accessing to the status page of the service */
$GLOBALS['SUPERADMIN_PASSWORD'] = 'pumch';
/* Expiration time (in seconds) of the session initiated in the status page of the service */
$GLOBALS['SUPERADMIN_SESSION_EXPIRE'] = 300;

/* Url of the Linkcare API */
$GLOBALS['WS_LINK'] = 'https://api.linkcareapp.com/ServerWSDL.php';

/* Credentials of the SERVICE USER that will connect to the Linkare platform and import the patients */
$GLOBALS['SERVICE_USER'] = 'service';
$GLOBALS['SERVICE_PASSWORD'] = 'password';
$GLOBALS['SERVICE_TEAM'] = 'PUMCH';

/* Language of the "service" user. This will be the default language of new patients created by the service */
$GLOBALS['LANGUAGE'] = 'ZH';

/* Endpoint URL of the PUMCH API */
$GLOBALS['PUMCH_API_URL'] = 'https://hcrm.pumch.cn';
/* API Username */
$GLOBALS['PUMCH_API_USERNAME'] = 'linkcare';
/* API Password */
$GLOBALS['PUMCH_API_PASSWORD'] = 'linkcare_password';

/* Program code of the Subscription to store the information about the episodes received from PUMCH */
$GLOBALS['PUMCH_EPISODES_PROGRAM_CODE'] = 'PUMCH_ADMISSIONS';
/* Team code of the PUMCH EPISODES Subscription owner */
$GLOBALS['PUMCH_EPISODES_TEAM_CODE'] = 'PUMCH';

/*
 * Program code of a Subscription of a secondary PROGRAM. When a new episode is received from PUMCH, a new ADMISSION will also be created in this
 * SUBSCRIPTION. The goal is to let a Case Manager decide whether the patient must be enrolled in any Post Intervention Follow-up PROGRAM
 */
$GLOBALS['DAY_SURGERY_PROGRAM_CODE'] = 'DSFU';
/*
 * Associative array with the TEAM CODEs of the DAY SURGERY Subscription owner. The key of each item should be the department name (as it is received
 * from the PUMCH API) and the item value is the associated TEAM CODE.
 * Example: ['日间口腔科(西院)' => 'PUMCH_DENTISTY', '日间基本外科(西院)' => 'PUMCH_BASIC_SURGERY']
 */
$GLOBALS['DAY_SURGERY_TEAM_CODES'] = [];
/* Array of surgery department names that should only create an ADMISSION in the PUMCH ADMISIONS care plan, but not in the DAY SURGERY care plan. */
$GLOBALS['IGNORE_DEPT_NAMES'] = [];

/*
 * Day interval to reject ADMISSIONS that have status 'enrolled' in the "DAY SURGERY FOLLOW UP" PROGRAM but discharged in the "PUMCH EPISODES"
 * PROGRAM.
 * If the discharge ocurred before the configured period, the enrolled ADMISSION will be rejected
 */
$GLOBALS['REJECT_ENROLLED_AFTER_DAYS'] = 2;

/*
 * Team where the PUMCH Case Managers will be added as members.
 * The Case Manager is the doctor assigned to the episode
 * This doctors will be registered in the Linkcare platform and added as "Case Manager" members of the Team indicated in this configuration parameter
 */
$GLOBALS['CASE_MANAGERS_TEAM'] = '';
/*
 * Team where the PUMCH Surgeons will be added as members.
 * This doctors will be registered in the Linkcare platform and added as "Case Manager" members of the Team indicated in this configuration parameter
 */
$GLOBALS['SURGEONS_TEAM'] = '';
/*
 * Team where the PUMCH anesthesists will be added as members.
 * This anesthesists will be registered in the Linkcare platform and added as "Staff" members of the Team indicated in this configuration parameter
 */
$GLOBALS['ANESTHESIA_TEAM'] = '';

/*
 * Date of the oldest procedure that will be requested to the PUMCH API. This value normally is only used during
 * the first load, because once the DB is feeded with an initial number of records, tha date of the last record will be
 * used in further requests to the PUMCH API to receive only incremental updates
 */
$GLOBALS['MINIMUM_DATE'] = '2022-12-20';

/*
 * The Patient and Professional identifiers are not globally unique. They are only unique in a particular Hospital.
 * The following configuration variable defines the Team associated to the hospital organization
 */
$GLOBALS['HOSPITAL_TEAM'] = 'PUMCH';

/*
 * Database credentials
 */
// DB Credentials of a user with read/write privileges on the tables used by the service
$GLOBALS['INTEGRATION_DATABASE'] = 'linkcare';
$GLOBALS['INTEGRATION_DBSERVER'] = 'xxx.linkcareapp.com';
$GLOBALS['INTEGRATION_DBUSER'] = 'PUMCH_INTEGRATION';
$GLOBALS['INTEGRATION_DBPASSWORD'] = 'yyy';
/*
 * DB Credentials of a user with administrative privileges for creating schemas and tables.
 * This credentials are necessary only for the initial deploy of the service, and can be removed later.
 */
$GLOBALS['ADMIN_DBUSER'] = '';
$GLOBALS['ADMIN_DBPASSWORD'] = '';

/**
 * ** OPTIONAL CONFIGURATION PARAMETERS ***
 */
/* Default timezone used by the service. It is used when it is necessary to generate dates in a specific timezone */
$GLOBALS['DEFAULT_TIMEZONE'] = 'Asia/Shanghai';
/* Log level. Possible values: debug,trace,warning,error,none */
$GLOBALS['LOG_LEVEL'] = 'error';
/* Directory to store logs in disk. If null, logs will only be generated on stdout */
$GLOBALS['LOG_DIR'] = null;

$GLOBALS['DISABLE_COMM_CHANNELS'] = ['phone'];
/*
 * Maximum number of patients that should be imported to the Linkcare platform in one execution. 0 means no limit (continue while there are records to
 * process)
 */
$GLOBALS['PATIENT_MAX'] = 10000;
/* Number of patients process */
$GLOBALS['PATIENT_PAGE_SIZE'] = 50;

/* Maximum time (in seconds) to wait for a response of the PUMCH API to consider that it is not responding and cancel the request */
$GLOBALS['PUMCH_API_TIMEOUT'] = 300;
/* Time (in seconds) between successive requests to the PUMCH API to avoid blocking the server */
$GLOBALS['PUMCH_REQUEST_DELAY'] = 5;

/**
 * SIMULATION CONFIGURATION PARAMETERS
 */
/*
 * Indicate if the service will use simulated requests to the PUMCH API. If true, instead of calling the real API, fake date will be used (as if it
 * were returned by the API)
 */
$GLOBALS['SIMULATE_PUMCH_API'] = false;
/* Parameter to anonymize patient data received from the API */
$GLOBALS['ANONYMIZE_DATA'] = false;

/* LOAD CUSTOMIZED CONFIGURATION */
if (file_exists(__DIR__ . '/../conf/configuration.php')) {
    include_once __DIR__ . '/../conf/configuration.php';
}

/*
 * INTERNAL CONFIGURATION VARIABLES (not customizable)
 */
require_once 'classes/Database.php';
require_once 'classes/ServiceLogger.php';
require_once 'classes/ErrorCodes.php';
require_once 'classes/ServiceException.php';
require_once 'classes/PUMCHItemCodes.php';
require_once 'classes/PUMCHProcedure.php';
require_once 'classes/PUMCHOperationInfo.php';
require_once 'classes/PUMCHEpisode.php';
require_once 'classes/PUMCHAPI.php';
require_once 'classes/ProcessHistory.php';
require_once 'classes/RecordPool.php';
require_once 'utils.php';
require_once 'WSAPI/WSAPI.php';
require_once 'classes/ServiceResponse.php';
require_once 'classes/ServiceFunctions.php';
require_once 'classes/DeployFunctions.php';
require_once 'functions.php';

date_default_timezone_set($GLOBALS['DEFAULT_TIMEZONE']);

/*
 * Name of the Linkcare IDENTIFIER to store the Patient Id. Patient identifiers are not globally unique. They are only unique in an specific Team
 * (tipically a Hospital)
 */
$GLOBALS['PATIENT_IDENTIFIER'] = 'PARTICIPANT_REF';
$GLOBALS['PROFESSIONAL_IDENTIFIER'] = 'EMPLOYEE_REF';
$GLOBALS['AIMED_CRM_IDENTIFIER'] = 'AIMED_REF';

$GLOBALS['VERSION'] = '1.5';
