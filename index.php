<?php
require_once ("lib/default_conf.php");
?>
<html>
    <head>
        <meta charset="UTF-8">
        <title>PUMCH Hospital Integration Service Info</title>

        <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
        <link rel="stylesheet" type="text/css" href="css/font-awesome.min.css">

        <script src="js/jquery-3.5.1.min.js"></script>
   		<script src="js/bootstrap.min.js"></script>

        <style>
            table, th, td {
                border-collapse: collapse;
                white-space: nowrap;
            }
            table {
                width: 100%;
            }
            th, td {
                padding: 10px 10px 10px 10px;
            }
            th.status, td.status {
                max-width: 60px;
                min-width: 60px; 
            }
            th.process, td.process {
                max-width: 250px;
                min-width: 250px;
            }
            th.date, td.date {
                max-width: 170px;
                min-width: 170px;
            }
            th.duration, td.duration {
                max-width: 75px;
                min-width: 75px;
                text-align: right;
            }
            th.message, td.message {
                max-width: 380px;
                min-width: 380px;
            }
        </style>
    </head>
	<body>	
		<div class="container col-lg-12 col-md-12 col-sm-12">
			<div style="text-align: center; margin-top: 20px; margin-bottom: 20px;">
				<h3>PUMCH HOSPITAL INTEGRATION SERVICE v<?php
    echo $GLOBALS['VERSION']?></h3>
			</div>
			<div style="display: flex;">
				<h5 style="text-decoration: underline;">Process status</h5>
				<div style="flex: 1;"></div>
				<div class="j_refresh_process" style="margin-right: 20px; cursor: pointer; color: #4469FC; font-size: 22px;"><i class="fa fa-refresh" aria-hidden="true"></i></div>
			</div>
			<div class="j_table_contents" style="overflow-x:auto; margin-top: -20px;"></div>
			<script>
				var detailsUniqueId = 1;
				var processTableHeader = '<tr><th class="status"></th><th class="process">Process</th><th class="date">Last execution</th><th class="duration">Duration</th><th class="message">Message</th></tr>';
				var detailsTableHeader = '<tr><th></th><th>Date</th><th>Message</th></tr>';
			
				$(document).on ("click", ".j_show_details", function () {
					var rows = $('.' + $(this).attr('table-class'));
					if(rows.css('display') !== 'none'){
						$(this).find('i.j_process_details_icon').removeClass('fa-caret-down');
						$(this).find('i.j_process_details_icon').addClass('fa-caret-right');
						rows.hide();
					}else{
						$(this).find('i.j_process_details_icon').removeClass('fa-caret-right');
						$(this).find('i.j_process_details_icon').addClass('fa-caret-down');
						rows.show();
					}
				});

				$('.j_refresh_process').click(function(){
					// Clear the contents
					$('.j_table_contents').html('');
					// Reload
					getData();
				});

				function getData(){
					$.post('service_status.php', function(info){
						$('.j_table_contents').append('<table style="margin-top: 20px;">' + processTableHeader + '</table><hr>');
                  		$.each(info, function(key, item) {
                      		// Get the STATUS icon
                      		switch (item.status) { 
                      		case '0': 
                      			var status_icon = '<i class="fa fa-spinner" style="font-size: 22px;" aria-hidden="true"></i>';
                      			break;
                      		case '1': 
                      			var status_icon = '<i class="fa fa-check" style="font-size: 22px; color: green;" aria-hidden="true"></i>';
                      			break;
                      		case '2': 
                      			var status_icon = '<i class="fa fa-times" style="font-size: 22px; color: red;" aria-hidden="true"></i>';
                      			break;
                      		}
                  			// Fill the table body
                  			if(item.details && item.details.length > 0){
                  				var tableBody = '<tr><td class="status"><span class="j_show_details" style="cursor: pointer;" table-class="j_table_details_' + detailsUniqueId +'">' + status_icon + '<i class="fa fa-caret-right j_process_details_icon" style="padding: 10px 0px 0px 10px;" aria-hidden="true"></i></span></td><td class="process">' + item.process + '</td><td class="date">' + item.date + '</td><td class="duration">' + item.duration + '</td><td class="message">' + item.message + '</td></tr>';
                  			}else{
                  				var tableBody = '<tr><td class="status">' + status_icon +'</td><td class="process">' + item.process + '</td><td class="date">' + item.date + '</td><td class="duration">' + item.duration + '</td><td class="message">' + item.message + '</td></tr>';
                  			}
    
                  			// Set the details section if there are any
    						if(item.details && item.details.length > 0){
    							// Then the table where they are
    							// First the header
    							var detailsSection = '<table class="j_table_details_' + detailsUniqueId +'" style="display: none;">' + detailsTableHeader;
    							// Second, the contents
    							$.each(item.details, function(key, detail) {
    								detailsSection += '<tr><td></td><td style="vertical-align: top;">' + detail.date + '</td><td>' + detail.message + '</td></tr>';
    							});
    							// Finally, close the table tag
    							detailsSection += '</table>';
    							// Increase the global details section unique id
    							detailsUniqueId += 1;
    						}else{
    							var detailsSection = '';
    						}
                  			
                  			$('.j_table_contents').append('<table>' + tableBody + '</table>' + detailsSection + '<hr>');
                  		});
					});
				}
				getData();
			</script>
		</div>
	</body>
</html>
