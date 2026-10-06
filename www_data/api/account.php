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
require_once "../lib/account.php";
require_once "../lib/data.php";
require_once "../lib/mail.php";

[$db, $user_account] = api_start();

$body = http_json_body();

// DELETION OF THE ACCOUNT
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

	if (!is_string($body["password"] ?? null) || empty($body["password"])) {
		http_error(400, "missing_password", "'password' is required to confirm account deletion.");
	}

	if (!account_password_matches($db, $user_account, $body["password"])) {
		log_event("account.refused", ["act" => "delete", "err" => "invalid_password"]);
		http_error(401, "invalid_password", "Incorrect password.");
	}

	data_delete_account($db, $user_account, "user_request");
	sec_clear_token_cookie();

	http_no_content();
}

// UPDATE OF ACCOUNT INFORMATION

// The secondary address gets every cycle by mail: a session alone cannot redirect them, the password confirms
$new_email2 = account_secondary_email_change($user_account, $body);
if (!is_null($new_email2)) {
	if (!is_string($body["password"] ?? null) || empty($body["password"])) {
		http_error(400, "missing_password", "'password' is required to change 'secondaryEmail'.");
	}
	if (!account_password_matches($db, $user_account, $body["password"])) {
		log_event("account.refused", ["act" => "secondary_email", "err" => "invalid_password"]);
		http_error(401, "invalid_password", "Incorrect password.");
	}
}

[$new, $changed] = account_apply_json($user_account, $body);
$last_write_client_UTC = http_client_timestamp($body["lastWriteClientUtc"] ?? null);

if (!empty($changed)) {
	db_update_user_account_settings($db, $user_account["no_user_account"], $new, $last_write_client_UTC);
	log_event("account.settings_changed", ["fld" => $changed]);
	if (!is_null($new_email2)) mail_send_secondary_email_changed($user_account["email1"], $new_email2);
}

http_data(200, [
	"name" => $new["name"],
	"fieldsUpdated" => $changed,
	"lastWriteClientUtc" => http_iso8601($last_write_client_UTC),
]);
