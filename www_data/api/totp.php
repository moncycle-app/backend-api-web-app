<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/api.php";

[$db, $user_account] = api_start();

$is_active = $user_account["totp_state"] == TOTP_STATE_ACTIVE;

// START THE SET-UP: a new secret and its QR code
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

	if ($is_active) {
		http_error(409, "totp_already_enabled", "Two-factor authentication is already enabled.");
	}

	$setup = sec_totp_begin($db, $user_account);
	log_event("account.totp_setup_started");
	http_data(200, $setup);
}

// CONFIRM IT WITH A FIRST CODE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$body = http_json_body();

	if ($is_active) {
		http_error(409, "totp_already_enabled", "Two-factor authentication is already enabled.");
	}

	if (empty($body["code"]) || intval($body["code"]) <= 0) {
		http_error(400, "totp_code_missing", "TOTP one-time code not provided.");
	}

	if (!sec_totp_code_valid($user_account, $body["code"])) {
		log_event("account.refused", ["act" => "totp_enable", "err" => "totp_invalid"]);
		http_error(401, "totp_invalid", "Entered TOTP code is not valid.");
	}

	db_update_user_account_totp_state($db, TOTP_STATE_ACTIVE, $user_account["no_user_account"]);
	log_event("account.totp_enabled");

	http_data(200, ["totpState" => sec_totp_state_name(TOTP_STATE_ACTIVE)]);
}

// TURN IT OFF, WITH A CODE
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

	if (empty($_GET["code"]) || intval($_GET["code"]) <= 0) {
		http_error(400, "totp_code_missing", "TOTP one-time code not provided.");
	}

	if (!$is_active) {
		http_error(409, "totp_not_enabled", "Two-factor authentication is not enabled.");
	}

	if (!sec_totp_code_valid($user_account, $_GET["code"])) {
		log_event("account.refused", ["act" => "totp_disable", "err" => "totp_invalid"]);
		http_error(401, "totp_invalid", "Entered TOTP code is not valid.");
	}

	db_update_user_account_totp_state($db, TOTP_STATE_DISABLED, $user_account["no_user_account"]);
	db_update_user_account_totp_secret($db, null, $user_account["no_user_account"]);
	log_event("account.totp_disabled");

	http_data(200, ["totpState" => sec_totp_state_name(TOTP_STATE_DISABLED)]);
}
