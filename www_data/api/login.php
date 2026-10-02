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
require_once "../lib/http.php";

use OTPHP\TOTP;

header('Content-Type: application/json');

$body = http_json_body();

try {

	$db = db_open();

	$user_account = sec_auth_token($db);
	if (!is_null($user_account)) {
		http_data(200, ["userId" => $user_account["no_user_account"], "alreadyAuthenticated" => true]);
	}

	// no email/password shape at all: distinct from a wrong/empty value below, and -- as in the
	// previous implementation -- does not count against the per-IP throttle counter.
	if (!isset($body["email"]) || !isset($body["password"]) || !filter_var($body["email"], FILTER_VALIDATE_EMAIL)) {
		http_error(400, "missing_credentials", "'email' and 'password' are required.");
	}

	$client_ip = sec_client_ip();

	$user_account = db_select_user_account_par_mail($db, $body["email"])[0] ?? [];

	$captcha_required = sec_login_captcha_required($user_account);
	$account_locked = sec_login_account_locked($user_account);
	// surfaced on every outcome below (successes included), same as the previous implementation:
	// it's a hint for the client's *next* attempt, not tied to whether this one succeeded.
	$meta = $captcha_required ? ["captchaRequired" => true] : [];

	if (!CONNEXION_COMPTE) {
		db_insert_login_attempt_ip($db, $client_ip);
		http_error(403, "login_disabled", "Login is disabled.", $meta);
	}

	if (LOGIN_IP_MAX_ATTEMPTS > 0 && db_count_login_attempt_ip($db, $client_ip) >= LOGIN_IP_MAX_ATTEMPTS) {
		// already throttled: don't insert again, or a blocked IP could grow this table forever
		http_error(429, "rate_limited", "Too many attempts from this network, please try again later.", $meta);
	}

	if (empty($body["email"]) || empty($body["password"])) {
		db_insert_login_attempt_ip($db, $client_ip);
		http_error(400, "missing_credentials", "'email' and 'password' are required.", $meta);
	}

	if ($captcha_required && !sec_login_captcha_verify($db, $body["captcha"] ?? "")) {
		db_insert_login_attempt_ip($db, $client_ip);
		http_error(403, "captcha_required", "Missing or incorrect captcha.", $meta);
	}

	if (isset($user_account["user_enabled"]) && !boolval($user_account["user_enabled"])) {
		db_insert_login_attempt_ip($db, $client_ip);
		http_error(403, "account_disabled", "Account deactivated.", $meta);
	}

	if (isset($user_account["password"]) && password_verify($body["password"], $user_account["password"])) {
		unset($user_account["password"]);

		$usr_totp_code = 0;
		if (isset($body["code"]) && strlen((string) $body["code"]) > 0) $usr_totp_code = intval(preg_replace('/\s+/', '', (string) $body["code"]));

		if ($user_account["totp_state"] != TOTP_STATE_ACTIVE) {
			$token = sec_auth_succes($db, $user_account);
			http_data(200, array_merge(["token" => $token, "userId" => $user_account["no_user_account"], "totpUsed" => false], $meta));
		}
		elseif ($usr_totp_code > 0 && (TOTP::createFromSecret($user_account["totp_secret"]))->verify($usr_totp_code)) {
			unset($user_account["totp_secret"]);
			$token = sec_auth_succes($db, $user_account);
			http_data(200, array_merge(["token" => $token, "userId" => $user_account["no_user_account"], "totpUsed" => true], $meta));
		}
		else {
			// wrong or missing TOTP code -- one outcome either way, matching the previous
			// implementation's single "outcome 4"
			db_update_co_echoue($db, $body["email"]);
			db_insert_login_attempt_ip($db, $client_ip);
			$code = $usr_totp_code > 0 ? "totp_invalid" : "totp_required";
			$message = $usr_totp_code > 0 ? "Correct password, but the TOTP code is incorrect." : "Correct password, but a TOTP code is required.";
			http_error(401, $code, $message, $meta);
		}
	}

	// hard lockout only kicks in on a WRONG password/TOTP: the account owner can still log in
	// with the correct credentials, so this can't be abused to lock a victim out
	elseif ($account_locked) {
		db_update_co_echoue($db, $body["email"]);
		db_insert_login_attempt_ip($db, $client_ip);
		http_error(403, "account_locked", "Account temporarily locked after too many failed attempts.", $meta);
	}
	else {
		db_update_co_echoue($db, $body["email"]);
		db_insert_login_attempt_ip($db, $client_ip);
		http_error(401, "invalid_credentials", "Incorrect password or non-existent account.", $meta);
	}

}
catch (\Throwable $e) {
	http_error(500, "unexpected_error", $e->getMessage());
}
