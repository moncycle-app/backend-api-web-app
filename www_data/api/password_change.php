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
$no_auth_token = $user_account["no_auth_token"]; // the caller's own session, the one that stays open

$body = http_json_body();

if (empty($body["oldPassword"]) || empty($body["newPassword"])) {
	http_error(400, "missing_fields", "'oldPassword' and 'newPassword' are required.");
}

if (isset($body["logoutOtherDevices"]) && !is_bool($body["logoutOtherDevices"])) {
	http_error(400, "invalid_parameter", "'logoutOtherDevices' must be true or false.");
}
$logout_other_devices = ($body["logoutOtherDevices"] ?? false) === true;

$user_account = db_select_user_account_by_email($db, $user_account["email1"]) ?? [];

if (strlen($body["newPassword"]) < PASSWORD_MIN_LENGTH) {
	http_error(422, "password_too_short", "New password is too short (minimum " . PASSWORD_MIN_LENGTH . " characters).");
}

if (isset($user_account["password"]) && password_verify($body["newPassword"], $user_account["password"])) {
	http_error(422, "password_unchanged", "New password is identical to the previous password.");
}

if (!isset($user_account["password"]) || !password_verify($body["oldPassword"], $user_account["password"])) {
	log_event("account.refused", ["act" => "password_change", "err" => "invalid_password"]);
	http_error(401, "invalid_password", "Old password is incorrect.");
}

$logged_out_devices = db_transaction($db, function () use ($db, $body, $user_account, $no_auth_token, $logout_other_devices) {
	db_update_password($db, sec_hash($body["newPassword"]), $user_account["no_user_account"]);
	return $logout_other_devices ? db_delete_auth_tokens_other($db, $user_account["no_user_account"], $no_auth_token) : 0;
});

log_event("account.password_changed", ["out" => $logged_out_devices]);
http_data(200, ["changed" => true, "loggedOutDevices" => $logged_out_devices]);
