<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";
require_once "../lib/mail.php";
require_once "../lib/http.php";
require_once '../vendor/phpmailer/phpmailer/src/Exception.php';
require_once '../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once '../vendor/phpmailer/phpmailer/src/SMTP.php';

define('NFP_METHOD_BILLINGS_TEMP',      1);
define('NFP_METHOD_BILLINGS',           2);
define('NFP_METHOD_FERTILITYCARE',      3);
define('NFP_METHOD_FERTILITYCARE_TEMP', 4);

$body = http_json_body();

$db = db_open();

// IF ACCOUNT IS LOGGED IN
$user_account = sec_auth_token($db);
if (!is_null($user_account)) {
	http_error(409, "already_authenticated", "Account already logged in.", ["userId" => $user_account["no_user_account"]]);
}

// IF ACCOUNT CREATION IS DISABLED
if (!CREATION_COMPTE) {
	http_error(403, "registration_disabled", "Account creation has been disabled.");
}

// GETTING CAPTCHA VALUE
$auth_token = "";
$captcha = null;
if (isset($_COOKIE["MONCYCLEAPP_TOKEN"]) && strlen($_COOKIE["MONCYCLEAPP_TOKEN"]) > 0) {
	$auth_token = $_COOKIE["MONCYCLEAPP_TOKEN"];
	$db_ret = db_select_auth_token_captcha($db, $auth_token);
	if (isset($db_ret[0]["no_auth_token"])) {
		db_update_auth_token_use($db, $db_ret[0]["no_auth_token"]);
		$captcha = $db_ret[0]["captcha"];

		// SECURITY: this prevents captcha re-use
		db_update_auth_token_captcha($db, $auth_token, null);
	}
}

// CHECKING USER INPUT
if (!isset($body["firstName"]) || !isset($body["email"]) || !isset($body["birthYear"])) {
	http_error(400, "missing_fields", "'firstName', 'email' and 'birthYear' are required.");
}

if (!filter_var($body["email"], FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}

if (!isset($body["captcha"]) || strlen(trim((string) $body["captcha"])) <= 0 || is_null($captcha) || trim((string) $body["captcha"]) != $captcha) {
	http_error(403, "captcha_invalid", "Error in captcha input.");
}

if (boolval(db_select_user_account_existe($db, $body["email"])[0]["user_account_existe"])) {
	http_error(409, "account_exists", "Account already exist.");
}

if (intval($body["birthYear"]) < (intval(date("Y")) - 100) || intval($body["birthYear"]) > intval(date("Y"))) {
	http_error(400, "invalid_birth_year", "Birth year is not realistic.");
}

// CREATING USER ACCOUNT
// "method" and "temperatureTracking" replace the previous single 1-4 "method" integer that
// conflated the NFP method with whether temperature is also tracked.
$method = strtolower(trim((string) ($body["method"] ?? "billings")));
$temperature_tracking = boolval($body["temperatureTracking"] ?? false);

if ($method === "fertilitycare") $nfp_method = $temperature_tracking ? NFP_METHOD_FERTILITYCARE_TEMP : NFP_METHOD_FERTILITYCARE;
else $nfp_method = $temperature_tracking ? NFP_METHOD_BILLINGS_TEMP : NFP_METHOD_BILLINGS;

$pass_text = sec_password_aleatoire();
$pass_hash = sec_hash($pass_text);

$new_account_no = db_insert_user_account($db, $body["firstName"], $nfp_method, $body["birthYear"], $body["email"], $pass_hash, $body["discoveredComment"] ?? null, boolval($body["okForResearch"] ?? false));

// MAIL SENDING TO USER
try {
	$mail = mail_init();
	$mail->addAddress($body["email"], $body["email"]);

	$mail->isHTML(false);
	$mail->Subject = 'Bienvenue et mot de passe';
	$mail->Body = mail_body_creation_user_account($body["firstName"], $pass_text, $body["email"]);
	$mail->AltBody = 'Bienvenue sur MONCYCLE.APP! Votre mot de passe: ' . $pass_text;

	$mail->send();
} catch (\Throwable $e) {
	http_data(201, [
		"userId" => $new_account_no,
		"email" => $body["email"],
		"name" => $body["firstName"],
		"welcomeEmailSent" => false,
	]);
}

http_data(201, [
	"userId" => $new_account_no,
	"email" => $body["email"],
	"name" => $body["firstName"],
	"welcomeEmailSent" => true,
]);
