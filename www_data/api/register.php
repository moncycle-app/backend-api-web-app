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
require_once "../lib/mail.php";

[$db, $user_account] = api_start(false);

$body = http_json_body();

if (!is_null($user_account)) {
	http_error(409, "already_authenticated", "Account already logged in.", ["userId" => $user_account["no_user_account"]]);
}

if (!REGISTRATION_ENABLED) {
	log_event("account.refused", ["act" => "register", "err" => "registration_disabled"]);
	http_error(403, "registration_disabled", "Account creation has been disabled.");
}

// the captcha answer is burnt as soon as it is read, whatever happens next
$captcha = sec_captcha_take($db);

if (!isset($body["firstName"]) || !isset($body["email"]) || !isset($body["birthYear"])) {
	http_error(400, "missing_fields", "'firstName', 'email' and 'birthYear' are required.");
}

if (!filter_var($body["email"], FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}

if (!sec_captcha_matches($captcha, $body["captcha"] ?? "")) {
	log_event("account.refused", ["act" => "register", "err" => "captcha_invalid"]);
	http_error(403, "captcha_invalid", "Error in captcha input.");
}

if (db_select_user_account_exists($db, $body["email"])) {
	log_event("account.refused", ["act" => "register", "err" => "account_exists"]);
	http_error(409, "account_exists", "Account already exist.");
}

if (intval($body["birthYear"]) < (intval(date("Y")) - 100) || intval($body["birthYear"]) > intval(date("Y"))) {
	http_error(400, "invalid_birth_year", "Birth year is not realistic.");
}

// the password is generated, and only sent by mail
$password = sec_random_password();
$nfp_method = account_method_id_from_json($body);
$new_account_no = db_insert_user_account(
	$db, $body["firstName"], $nfp_method, intval($body["birthYear"]), $body["email"], sec_hash($password),
	$body["discoveredComment"] ?? null, boolval($body["okForResearch"] ?? false)
);
log_context(["uid" => intval($new_account_no)]);
log_event("account.registered", ["nfp" => $nfp_method]);

http_data(201, [
	"userId" => $new_account_no,
	"email" => $body["email"],
	"name" => $body["firstName"],
	"welcomeEmailSent" => mail_send_welcome($body["firstName"], $body["email"], $password),
]);
