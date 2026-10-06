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
require_once "../lib/day_format.php";
require_once "../lib/mail.php";

[$db, $user_account] = api_start(false);

$body = http_json_body();
// the address is stored as it is looked up: trimmed, in lower case (anything but a string is refused below)
if (is_string($body["email"] ?? null)) $body["email"] = account_email_normalise($body["email"]);

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

if (!account_birth_year_valid(intval($body["birthYear"]))) {
	http_error(400, "invalid_birth_year", "Birth year is not realistic.");
}

[$first_name, $problem] = day_format_text($body["firstName"], "firstName", ACCOUNT_LIMIT_NAME_CHARS);
if (!is_null($problem)) {
	http_error(400, "invalid_first_name", $problem);
}

$discovered_comment = null;
if (isset($body["discoveredComment"])) {
	[$discovered_comment, $problem] = day_format_text($body["discoveredComment"], "discoveredComment", ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS);
	if (!is_null($problem)) {
		http_error(400, "invalid_discovered_comment", $problem);
	}
}

// the password is generated, and only sent by mail
$password = sec_random_password();
$nfp_method = account_method_id_from_json($body);
$new_account_no = db_insert_user_account(
	$db, $first_name, $nfp_method, intval($body["birthYear"]), $body["email"], sec_hash($password),
	$discovered_comment, boolval($body["okForResearch"] ?? false)
);

// the address was taken between the check above and the insert: a second registration of the same
// address sent at the same time
if (is_null($new_account_no)) {
	log_event("account.refused", ["act" => "register", "err" => "account_exists"]);
	http_error(409, "account_exists", "Account already exist.");
}

log_context(["uid" => intval($new_account_no)]);
log_event("account.registered", ["nfp" => $nfp_method]);

http_data(201, [
	"userId" => $new_account_no,
	"email" => $body["email"],
	"name" => $first_name,
	"welcomeEmailSent" => mail_send_welcome($first_name, $body["email"], $password),
]);
