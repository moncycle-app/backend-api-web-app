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

log_start(sec_client_ip());
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
// can use this to find out who has one. Logged before the sleep: a worker killed meanwhile must not lose it.
$account = db_select_user_account_by_email($db, $email);
if (!is_null($account)) {
	$password = sec_random_password();
	db_update_password_by_email($db, sec_hash($password), $email);
	log_context(["uid" => intval($account["no_user_account"])]);
	log_event("account.password_reset");
	mail_send_new_password($email, $password);
	sleep(rand(1, 4));
}
else {
	log_event("account.password_reset_unknown");
	sleep(rand(1, 5));
}

http_data(200, ["sent" => true]);
