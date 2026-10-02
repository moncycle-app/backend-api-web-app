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

$body = http_json_body();

if (empty($body["oldPassword"]) || empty($body["newPassword"])) {
	http_error(400, "missing_fields", "'oldPassword' and 'newPassword' are required.");
}

$user_account = db_select_user_account_par_mail($db, $user_account["email1"])[0] ?? [];

if (strlen($body["newPassword"]) < PASSWORD_MIN_LENGTH) {
	http_error(422, "password_too_short", "New password is too short (minimum " . PASSWORD_MIN_LENGTH . " characters).");
}

if (isset($user_account["password"]) && password_verify($body["newPassword"], $user_account["password"])) {
	http_error(422, "password_unchanged", "New password is identical to the previous password.");
}

if (!isset($user_account["password"]) || !password_verify($body["oldPassword"], $user_account["password"])) {
	http_error(401, "invalid_password", "Old password is incorrect.");
}

db_udpate_password_par_nouser_account($db, sec_hash($body["newPassword"]), $user_account["no_user_account"]);

http_data(200, ["changed" => true]);
