<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../vendor/autoload.php";
require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";

use Gregwar\Captcha\CaptchaBuilder;

$captcha = new CaptchaBuilder;
$captcha->build();

$db = db_open();

$cookie_token = $_COOKIE["MONCYCLEAPP_TOKEN"] ?? "";
$token_exists = false;

if (strlen($cookie_token) > 0) {
	$db_ret = db_select_auth_token_captcha($db, $cookie_token);
	$token_exists = isset($db_ret[0]["no_auth_token"]);
}

// no cookie yet, or it points to a token that no longer exists (expired/purged): issue a fresh one
if (!$token_exists) {
	$cookie_token = sec_password_aleatoire(64);
	$ua = $_SERVER['HTTP_USER_AGENT'];
	if (strlen($ua) > 200) {
		$ua = substr($ua,0,200);
		$ua .= " ...";
	}
	db_insert_auth_token($db, NULL, "CAPTCHA | " . $ua, "FR", $cookie_token, 3);
	$arr_cookie_options = array (
		'expires' => strtotime('+2 days'),
		'path' => '/',
		'secure' => PHP_SECURE_COOKIES,
		'httponly' => true,
	);
	setcookie("MONCYCLEAPP_TOKEN", $cookie_token, $arr_cookie_options);
}

// SECURITY: must be keyed by auth_token_str (the cookie value), not no_auth_token,
// otherwise this silently updates 0 rows and the displayed image never matches what's stored
db_update_auth_token_captcha($db, $cookie_token, $captcha->getPhrase());
header('Content-type: image/jpeg');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
$captcha->output();
exit;
