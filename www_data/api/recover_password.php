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
require_once "../lib/mail.php";

header('Content-Type: application/json');
$db = db_open();

$body = http_json_body();
$email = trim((string) ($body["email"] ?? ""));

if (empty($email)) {
	http_error(400, "missing_email", "'email' is required.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}

// The answer is the same whether or not the account exists, and takes as long, so that nobody
// can use this to find out who has one.
if (db_select_user_account_exists($db, $email)) {
	$password = sec_random_password();
	db_update_password_by_email($db, sec_hash($password), $email);
	mail_send_new_password($email, $password);
	sleep(rand(1, 4));
}
else {
	sleep(rand(1, 5));
}

http_data(200, ["sent" => true]);
