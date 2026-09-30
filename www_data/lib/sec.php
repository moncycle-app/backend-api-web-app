<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

define("TOTP_STATE_NEVER_USED", 0);
define("TOTP_STATE_DISABLED", 1);
define("TOTP_STATE_INIT", 2);
define("TOTP_STATE_ACTIVE", 3);

// the string vocabulary the JSON API exposes instead of the raw 0-3 DB int -- shared by
// api/totp.php and api/key_infos.php so the two never drift apart.
function sec_totp_state_name($state) {
	return match ($state) {
		TOTP_STATE_NEVER_USED => "never_used",
		TOTP_STATE_DISABLED => "disabled",
		TOTP_STATE_INIT => "init",
		TOTP_STATE_ACTIVE => "active",
		default => "unknown",
	};
}

// login brute-force defense thresholds. LOGIN_ATTEMPTS_DECAY_MINUTES must match the
// "60 MINUTE" literal in db_update_co_echoue()'s SQL (lib/db.php) since that query decays
// the counter itself; keep both in sync if this changes.
define("LOGIN_ATTEMPTS_DECAY_MINUTES", 60);
define("LOGIN_CAPTCHA_THRESHOLD", 3);
define("LOGIN_LOCKOUT_THRESHOLD", 15);
define("LOGIN_IP_MAX_ATTEMPTS", 30);

function sec_password_aleatoire($taille=12){
	$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
	$pass = [];
	$alphaLength = strlen($alphabet)-1;
	for ($i = 0; $i < $taille; $i++) {
		$n = random_int(0, $alphaLength);
		$pass[] = $alphabet[$n];
	}
	return implode($pass);
}

function sec_hash($text) {
	return password_hash($text, PASSWORD_BCRYPT);
}

// fast, non-salted hash used to store/look up auth tokens: unlike sec_hash(), it must be
// deterministic so a token can be matched with a plain `WHERE = :hash` query. Safe here
// because the token itself already carries 256 chars of entropy (see sec_password_aleatoire).
function sec_hash_token($token) {
	return hash("sha256", $token);
}

function sec_auth_token($db) {
	$auth_token = "";
	if (isset($_COOKIE["MONCYCLEAPP_JETTON"]) && strlen($_COOKIE["MONCYCLEAPP_JETTON"])>0) $auth_token = $_COOKIE["MONCYCLEAPP_JETTON"]; // legacy, to be removed in a few release
	if (isset($_COOKIE["MONCYCLEAPP_TOKEN"]) && strlen($_COOKIE["MONCYCLEAPP_TOKEN"])>0) $auth_token = $_COOKIE["MONCYCLEAPP_TOKEN"];
	$head = getallheaders();
	if (isset($head["Authorization"]) && str_contains($head["Authorization"], "Bearer ")) $auth_token = explode(' ', trim($head["Authorization"]), 2)[1];
	if (strlen($auth_token)>0) {
		$user_account = db_select_user_account_auth_token($db, sec_hash_token($auth_token));
		if (isset($user_account[0]) && isset($user_account[0]["user_enabled"]) && boolval($user_account[0]["user_enabled"])) {
			db_update_auth_token_use($db, $user_account[0]["no_auth_token"]);
			return $user_account[0];
		}
	}
	return null;	
}

function sec_exit_si_non_connecte($user_account) {
	if (is_null($user_account)) {
		http_response_code(401);
		echo json_encode(["error" => ["code" => "unauthorized", "message" => "Authentication required."]]);
		exit;
	}
}

function sec_redirect_non_connecte($user_account) {
	if (is_null($user_account)) {
		header('Location: auth');
		http_response_code(401);
		exit;
	}
}

function sec_auth_succes($db, $user_account, $appareil=null) {
	$auth_token = sec_password_aleatoire(256);

	db_insert_auth_token($db, $user_account["no_user_account"], $appareil ?? ("AUTH | " . $_SERVER['HTTP_USER_AGENT']), "FR", sec_hash_token($auth_token));
	db_update_user_account_connecte($db, $user_account["no_user_account"]);

	$arr_cookie_options = array (
		'expires' => strtotime('+5 years'), 
		'path' => '/',
		'secure' => PHP_SECURE_COOKIES,
		'httponly' => true,
	);

	setcookie("MONCYCLEAPP_TOKEN", $auth_token, $arr_cookie_options);

	return $auth_token;
}

function sec_offuscate_str($str) {
	return substr($str, 0, 3) . " [masqué] " . substr($str, -3);
}

// NOTE: trusts REMOTE_ADDR only. If the app is deployed behind a reverse proxy / CDN,
// this must be adapted to read the real client IP from a header set by that trusted edge
// (never trust a client-supplied X-Forwarded-For directly: it can be spoofed to dodge or
// to frame another IP for the throttling below).
function sec_client_ip() {
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

// number of recent failed login attempts on this account, ignoring attempts older than
// LOGIN_ATTEMPTS_DECAY_MINUTES so a stale streak doesn't linger forever
function sec_login_effective_attempts($user_account) {
	if (!isset($user_account["nb_connection_attempts"]) || empty($user_account["last_failed_attempt"])) return 0;

	$cutoff = new DateTime("-" . LOGIN_ATTEMPTS_DECAY_MINUTES . " minutes");
	if (new DateTime($user_account["last_failed_attempt"]) < $cutoff) return 0;

	return intval($user_account["nb_connection_attempts"]);
}

function sec_login_captcha_required($user_account) {
	return sec_login_effective_attempts($user_account) >= LOGIN_CAPTCHA_THRESHOLD;
}

function sec_login_account_locked($user_account) {
	return sec_login_effective_attempts($user_account) >= LOGIN_LOCKOUT_THRESHOLD;
}

// validates the captcha answer against the phrase tied to the visitor's MONCYCLEAPP_TOKEN
// cookie (same mechanism used by register.php / api/captcha.php). Always burns the stored
// captcha so it can't be replayed, whether or not the answer was correct.
function sec_login_captcha_verify($db, $captcha_input) {
	if (!isset($_COOKIE["MONCYCLEAPP_TOKEN"]) || strlen($_COOKIE["MONCYCLEAPP_TOKEN"])<=0) return false;

	$db_ret = db_select_auth_token_captcha($db, $_COOKIE["MONCYCLEAPP_TOKEN"]);
	if (!isset($db_ret[0]["no_auth_token"]) || is_null($db_ret[0]["captcha"])) return false;

	$captcha = $db_ret[0]["captcha"];

	// SECURITY: this prevents captcha re-use
	db_update_auth_token_captcha($db, $_COOKIE["MONCYCLEAPP_TOKEN"], null);

	return strlen(trim($captcha_input))>0 && trim($captcha_input) === $captcha;
}
