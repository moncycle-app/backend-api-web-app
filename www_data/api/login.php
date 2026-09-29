<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../vendor/autoload.php";

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/date.php";
require_once "../lib/sec.php";

use OTPHP\TOTP;

header('Content-Type: application/json');

$result_code = [
	0 => "",
	1 => "Login is disabled.",
	2 => "Email and/or password missing.",
	3 => "Account deactivated.",
	4 => "Correct password, but TOTP code is incorrect or missing.",
	5 => "Incorrect password or non-existent account.",
	6 => "Missing data",
	100 => "Account successfuly authentificated (password only).",
	101 => "Account successfuly authentificated (password + TOTP).",
	102 => "Account already authentificated."
];

$output = [];
$output["outcome"] = 0;


try {

	$db = db_open();

	$user_account = sec_auth_token($db);
	if (!is_null($user_account)) {
		$output["no_user_account"] = $user_account["no_user_account"];
		$output["outcome"] = 102;
	}

	elseif (isset($_POST["email1"]) && isset($_POST["password"]) && filter_var($_POST["email1"], FILTER_VALIDATE_EMAIL)) {

		$user_account = db_select_user_account_par_mail($db, $_POST["email1"])[0] ?? [];

		if (isset($user_account["nb_connection_attempts"]) && intval($user_account["nb_connection_attempts"])>=5) sleep(5);
		elseif (!isset($user_account["nb_connection_attempts"]) && rand(0,5)==0) sleep(5);

		if (!CONNEXION_COMPTE) $output["outcome"] = 1;
		elseif (empty($_POST["email1"]) || empty($_POST["password"])) {
			$output["outcome"] = 2;
		}
		elseif (isset($user_account["user_enabled"]) && !boolval($user_account["user_enabled"])) {
			$output["outcome"] = 3;
		}
		elseif (isset($user_account["password"]) && password_verify($_POST["password"], $user_account["password"])) {
			unset($user_account["password"]);
			unset($_POST["password"]);

			$usr_totp_code = 0;
			if (isset($_POST["code"]) && strlen($_POST["code"])>0) $usr_totp_code = intval(preg_replace('/\s+/','',$_POST["code"]));

			if ($user_account["totp_state"] != TOTP_STATE_ACTIVE) {
				// AUTH SUCCESS
				$output["auth_token"] = sec_auth_succes($db, $user_account);
				$output["outcome"] = 100;
				$output["no_user_account"] = $user_account["no_user_account"];
				
			}
			elseif ($usr_totp_code>0 && (TOTP::createFromSecret($user_account["totp_secret"]))->verify($usr_totp_code)) {
				unset($user_account["totp_secret"]);
				unset($_POST["code"]);
				// AUTH SUCCESS
				$output["auth_token"] = sec_auth_succes($db, $user_account);
				$output["outcome"] = 101;
				$output["no_user_account"] = $user_account["no_user_account"];
			}
			else {
				db_update_co_echoue($db, $_POST["email1"]);
				$output["outcome"] = 4;
			}

		}
		else {
			db_update_co_echoue($db, $_POST["email1"]);
			$output["outcome"] = 5;
		}
	}


	else {
		$output["outcome"] = 6;
	}

}
catch (Exception $e){
	
	$output["exception_error"] = $e->getMessage();

}

$output["message"] = $result_code[$output["outcome"]];
echo json_encode($output);
