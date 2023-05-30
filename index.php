<?php
error_reporting(0);
session_start();

require_once ("lib/default_conf.php");

if ($_SESSION['logged_in']) {
    // Check whether the user has been inactive for a long time
    $elapsedSinceLastActivity = microtime(true) - $_SESSION['last_activity'];
    if ($elapsedSinceLastActivity > $GLOBALS['SUPERADMIN_SESSION_EXPIRE']) {
        $_SESSION['last_activity'] = null;
        $_SESSION['logged_in'] = false;
    }
}

if (!$_SESSION['logged_in']) {
    if (isset($_POST['login']) && isset($_POST['pwd'])) {
        $GLOBALS['PATH_ROOT'] = $_SERVER['DOCUMENT_ROOT'] . '/';

        if (strtoupper($GLOBALS['SUPERADMIN_USER']) == strtoupper($_POST['login'])) {
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = microtime(true);
        } else {
            session_unset();
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

if (isset($_GET['logout'])) {
    $_SESSION['logged_in'] = false;
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta charset="UTF-8">
        <title>PUMCH Hospital Integration Service Info</title>

        <link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
        <link rel="stylesheet" type="text/css" href="css/font-awesome.min.css">

        <script src="js/jquery-3.5.1.min.js"></script>
   		<script src="js/bootstrap.min.js"></script>

        <link rel="stylesheet" type="text/css" href="css/style.css">

    </head>
	<body>
		<div class="container col-lg-12 col-md-12 col-sm-12">
    		<div style="text-align: center; margin-top: 20px; margin-bottom: 20px;">
    			<h3>PUMCH HOSPITAL INTEGRATION SERVICE v<?=$GLOBALS['VERSION'];?>
		<?php
if ($_SESSION['logged_in']) {
    $_SESSION['last_activity'] = microtime(true);
    ?>
		    <a href="index.php?logout=true" class="buttons logout">Logout</a>
        <?php
}
?>
    			</h3>
    		</div>
		</div>
		<?php
if (!$_SESSION['logged_in']) {
    ?>
	    	<div id="body">
                <div id="login">
                    <fieldset>
                        <legend><h3>Login</h3></legend>
                        <form action="?" method="POST">
                            <table width="100%;">
                                <tr>
                                    <td>Username</td>
                                    <td><input id="username" type="text" name="login" value="<?=$_POST['login'];?>">
                                    </td>
                                </tr>
                                <tr>
                                    <td>Password</td>
                                    <td><input type="password" name="pwd" value="<?=$_POST['pwd'];?>"></td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center"><input type="submit" value="Sign in" class="buttons play"/>
                                    </td>
                                </tr>
                            </table>
                    </fieldset>
                    <script>
                        $(document).ready(function() {
                        	$("#username").focus();
                        });
                    </script>
                </div>
            </div>
            
        <?php
} else {
    include_once "views/service_status.html.php";
}
?>
</body>
</html>
