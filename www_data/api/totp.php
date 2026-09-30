<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

header('Content-Type: application/json');

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";
require_once "../lib/http.php";
require_once "../vendor/autoload.php";

use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$db = db_open();

$user_account = sec_auth_token($db);
sec_exit_si_non_connecte($user_account);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

	if ($user_account["totp_state"] == TOTP_STATE_ACTIVE) {
		http_error(409, "totp_already_enabled", "Two-factor authentication is already enabled.");
	}

	$totp = TOTP::generate();
	$totp->setLabel($user_account["email1"]);
	$totp->setIssuer('MONCYCLE.APP');
	$totp->setParameter('image', APP_URL . "img/moncycleapp512.jpg");
	db_update_user_account_totp_secret($db, $totp->getSecret(), $user_account["no_user_account"]);
	db_update_user_account_totp_state($db, TOTP_STATE_INIT, $user_account["no_user_account"]);
	$renderer = new ImageRenderer(new RendererStyle(150), new SvgImageBackEnd());
	$writer = new Writer($renderer);

	http_data(200, [
		"totpState" => sec_totp_state_name(TOTP_STATE_INIT),
		"initSecret" => $totp->getSecret(),
		"otpauth" => $totp->getProvisioningUri(),
		"qrcode" => $writer->writeString($totp->getProvisioningUri()),
	]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$body = http_json_body();

	if ($user_account["totp_state"] == TOTP_STATE_ACTIVE) {
		http_error(409, "totp_already_enabled", "Two-factor authentication is already enabled.");
	}

	if (!isset($body["code"]) || empty($body["code"]) || intval($body["code"]) <= 0) {
		http_error(400, "totp_code_missing", "TOTP one-time code not provided.");
	}

	$otp_obj = TOTP::createFromSecret($user_account["totp_secret"]);
	if (!$otp_obj->verify(intval($body["code"]))) {
		http_error(401, "totp_invalid", "Entered TOTP code is not valid.");
	}

	db_update_user_account_totp_state($db, TOTP_STATE_ACTIVE, $user_account["no_user_account"]);

	http_data(200, ["totpState" => sec_totp_state_name(TOTP_STATE_ACTIVE)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

	if (!isset($_GET["code"]) || empty($_GET["code"]) || intval($_GET["code"]) <= 0) {
		http_error(400, "totp_code_missing", "TOTP one-time code not provided.");
	}

	if ($user_account["totp_state"] != TOTP_STATE_ACTIVE) {
		http_error(409, "totp_not_enabled", "Two-factor authentication is not enabled.");
	}

	$otp_obj = TOTP::createFromSecret($user_account["totp_secret"]);
	if (!$otp_obj->verify(intval($_GET["code"]))) {
		http_error(401, "totp_invalid", "Entered TOTP code is not valid.");
	}

	db_update_user_account_totp_state($db, TOTP_STATE_DISABLED, $user_account["no_user_account"]);
	db_update_user_account_totp_secret($db, null, $user_account["no_user_account"]);

	http_data(200, ["totpState" => sec_totp_state_name(TOTP_STATE_DISABLED)]);
}
