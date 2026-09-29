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
	7 => "Missing or incorrect captcha.",
	8 => "Account temporarily locked after too many failed attempts.",
	9 => "Too many attempts from this network, please try again later.",
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

		$client_ip = sec_client_ip();

		$user_account = db_select_user_account_par_mail($db, $_POST["email1"])[0] ?? [];

		$captcha_required = sec_login_captcha_required($user_account);
		$account_locked   = sec_login_account_locked($user_account);

		if ($captcha_required) $output["captcha_required"] = true;

		if (!CONNEXION_COMPTE) $output["outcome"] = 1;
		elseif (db_count_login_attempt_ip($db, $client_ip) >= LOGIN_IP_MAX_ATTEMPTS) {
			$output["outcome"] = 9;
		}
		elseif (empty($_POST["email1"]) || empty($_POST["password"])) {
			$output["outcome"] = 2;
		}
		elseif ($captcha_required && !sec_login_captcha_verify($db, $_POST["captcha"] ?? "")) {
			$output["outcome"] = 7;
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
		// hard lockout only kicks in on a WRONG password/TOTP: the account owner can still log
		// in with the correct credentials, so this can't be abused to lock a victim out
		elseif ($account_locked) {
			db_update_co_echoue($db, $_POST["email1"]);
			$output["outcome"] = 8;
		}
		else {
			db_update_co_echoue($db, $_POST["email1"]);
			$output["outcome"] = 5;
		}

		// IP throttling counts every non-successful attempt against this endpoint, not just
		// wrong passwords, so captcha-only spam and account spraying are throttled too.
		// Outcome 9 (already throttled) is excluded so hammering a blocked IP doesn't keep
		// growing the table.
		if (!in_array($output["outcome"], [100, 101, 9], true)) {
			db_insert_login_attempt_ip($db, $client_ip);
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
