# PUMCH Service
Service for integrating the PUMCH hospital HIS with the Linkcare platform.<br>
This project implements several functions published as a REST API. All functions must be invoked using POST method.<br>

The base URL for invoking the published functions is:<br>
- https://deploy_url/rest_service<br>

Published functions can be invoked appending the name of the required function to the base URL.<br>
Example:<br>
- https://base_url/rest_service/import_patients
  
## Service configuration
The file /lib/default_conf.php provides a default configuration.<br>
To customize the configuration create a new file under the directory /conf (at root directory level) called "configuration.php". The default_conf.php contains explanation for the variables that can be customized.<br>
  

## REST services
All functions return a JSON response (Content-type: application/json) with the following structure:<br>

 {<br>
   "status": "idle",<br>
   "message": "Informative message returned by the service"<br>
 }<br>
 
The possible response status are:
- idle: The function was executed but no work was done
- success: The function was executed successfully
- error: The function was executed with errors

### Published functions
- <b>fetch_pumch_records</b>: Sends a request to the hospital API to fetch patients that should be imported in the Linkcare platform. The records fetched from the Hospital are stored in a local intermediate DB. This function checks whether the fetched records have any change compared to the last time they were fetched. If the record fetched is new or presents any change, then it will be marked so that it is processed by the "import_patients" function.
- <b>import_patients</b>: Creates or updates a new Admission for each record fetched from the Hospital in a Care Plan that is a mirror of the Episodes in the Hospital HIS. Additionally it creates an admission in a DAY SURGERY Care Plan if necessary.
- <b>review_day_surgery_enrolled</b>: Checks whether the Admissions created in the DAY SURGERY Care Plan must be automatically rejected. An Admission should be rejected if it still has status "ENROLLED" since a number of days that can be set in the service configuration.
- <b>fetch_and_import</b>: This function is a shortcut to execute succesively the functions "fetch_pumch_records" and "import_patients" in a single call
