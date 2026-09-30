<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";
require_once "../lib/http.php";

header('Content-Type: application/json');

$db = db_open();

$user_account = sec_auth_token($db);
sec_exit_si_non_connecte($user_account);

$body = http_json_body();

if (!isset($body["oldPassword"]) || empty($body["oldPassword"]) || !isset($body["newPassword"]) || empty($body["newPassword"])) {
	http_error(400, "missing_fields", "'oldPassword' and 'newPassword' are required.");
}

$user_account = db_select_user_account_par_mail($db, $user_account["email1"])[0] ?? [];

if (strlen($body["newPassword"]) < 8) {
	http_error(422, "password_too_short", "New password is too short (minimum 8 characters).");
}

if (isset($user_account["password"]) && password_verify($body["newPassword"], $user_account["password"])) {
	http_error(422, "password_unchanged", "New password is identical to the previous password.");
}

if (!isset($user_account["password"]) || !password_verify($body["oldPassword"], $user_account["password"])) {
	http_error(401, "invalid_password", "Old password is incorrect.");
}

db_udpate_password_par_nouser_account($db, sec_hash($body["newPassword"]), $user_account["no_user_account"]);

http_data(200, ["changed" => true]);
