<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";

// ---------------------------------------------------------------------------
// Passwords and tokens
// ---------------------------------------------------------------------------

function sec_random_password($length=12){
	$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
	$pass = [];
	$alphaLength = strlen($alphabet)-1;
	for ($i = 0; $i < $length; $i++) {
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
// because the token itself already carries 256 chars of entropy (see sec_random_password).
function sec_hash_token($token) {
	return hash("sha256", $token);
}

// the session token cookie of the request, "" when there is none (or when it is a list: a client can
// send "MONCYCLEAPP_TOKEN[]=x", and PHP reads it as one)
function sec_cookie_token(): string {
	$token = $_COOKIE[COOKIE_AUTH_TOKEN] ?? "";
	return is_string($token) ? $token : "";
}

// The SameSite attribute of the session cookie (COOKIE_SAMESITE). None is only honoured on a Secure
// cookie: a browser drops a SameSite=None cookie that is not, so it falls back to Lax.
function sec_cookie_samesite(): string {
	if (COOKIE_SAMESITE === "none") return PHP_SECURE_COOKIES ? "None" : "Lax";
	return COOKIE_SAMESITE === "lax" ? "Lax" : "Strict";
}

function sec_set_token_cookie(string $token, string $lifetime): void {
	setcookie(COOKIE_AUTH_TOKEN, $token, [
		'expires' => strtotime($lifetime),
		'path' => '/',
		'secure' => PHP_SECURE_COOKIES,
		'httponly' => true,
		'samesite' => sec_cookie_samesite(),
	]);
}

function sec_clear_token_cookie(): void {
	foreach ([COOKIE_AUTH_TOKEN, COOKIE_AUTH_TOKEN_LEGACY] as $name) {
		setcookie($name, '', [
			'expires' => -1,
			'path' => '/',
			'secure' => PHP_SECURE_COOKIES,
			'httponly' => true,
			'samesite' => sec_cookie_samesite(),
		]);
	}
}

// The account of the token the request carries (cookie, or "Authorization: Bearer"), or null. The
// account and the session it finds are the ones every line of the request's log carries.
function sec_auth_token($db) {
	$auth_token = $_COOKIE[COOKIE_AUTH_TOKEN_LEGACY] ?? ""; // legacy, to be removed in a few release
	if (!is_string($auth_token)) $auth_token = "";
	if (sec_cookie_token() !== "") $auth_token = sec_cookie_token();
	$head = getallheaders();
	if (isset($head["Authorization"]) && str_contains($head["Authorization"], "Bearer ")) $auth_token = explode(' ', trim($head["Authorization"]), 2)[1];
	if (strlen($auth_token) > 0) {
		$user_account = db_select_user_account_auth_token($db, sec_hash_token($auth_token));
		if (!is_null($user_account) && boolval($user_account["user_enabled"] ?? false)) {
			db_update_auth_token_use($db, $user_account["no_auth_token"]);
			log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($user_account["no_auth_token"])]);
			return $user_account;
		}
		log_event("auth.token_rejected", ["why" => is_null($user_account) ? "unknown" : "disabled", "uid" => $user_account["no_user_account"] ?? null]);
	}
	return null;
}

function sec_exit_if_logged_out($user_account) {
	if (is_null($user_account)) {
		log_note(["err" => "unauthorized"]);
		http_response_code(401);
		echo json_encode(["error" => ["code" => "unauthorized", "message" => "Authentication required."]]);
		exit;
	}
}

function sec_redirect_if_logged_out($user_account) {
	if (is_null($user_account)) {
		header('Location: auth');
		http_response_code(401);
		exit;
	}
}

// The name a session is stored under: what it is for, then the browser's user agent. A header can
// be 8 KB and is not always UTF-8, a column is 256 characters of it: the agent is made valid and
// cut to fit, on a character boundary.
function sec_device_name(string $kind): string {
	$user_agent = mb_scrub($_SERVER['HTTP_USER_AGENT'] ?? '');
	if (strlen($user_agent) > AUTH_TOKEN_USER_AGENT_BYTES) $user_agent = mb_strcut($user_agent, 0, AUTH_TOKEN_USER_AGENT_BYTES) . " ...";
	return "$kind | $user_agent";
}

// Opens a session: stores a new token for the account, sets its cookie, and returns it. The new
// session is the one the rest of the request's log names.
function sec_auth_success($db, $user_account, $device=null) {
	$auth_token = sec_random_password(256);

	$no_auth_token = db_insert_auth_token($db, $user_account["no_user_account"], $device ?? sec_device_name("AUTH"), "FR", sec_hash_token($auth_token));
	log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($no_auth_token)]);
	db_update_user_account_logged_in($db, $user_account["no_user_account"]);
	sec_set_token_cookie($auth_token, '+5 years');

	return $auth_token;
}

function sec_obfuscate($str) {
	return substr($str, 0, 3) . " [masqué] " . substr($str, -3);
}

// the row with the given secret columns masked, for the data export
function sec_obfuscate_columns(array $row, array $columns): array {
	foreach ($columns as $column) {
		if (isset($row[$column])) $row[$column] = sec_obfuscate($row[$column]);
	}
	return $row;
}

// ---------------------------------------------------------------------------
// Where a write comes from (CSRF)
// ---------------------------------------------------------------------------

// "scheme://host[:port]" of a URL in lower case, or "" when it is not one.
function sec_origin_of_url(string $url): string {
	$parts = parse_url($url);
	if (!is_array($parts) || empty($parts["scheme"]) || empty($parts["host"])) return "";
	return strtolower($parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : ""));
}

// May a browser at this origin write to this API? Its own origin (the host the request was sent to; the
// scheme is not compared: behind a TLS proxy the app sees plain http), the web app's (APP_URL), and the
// ones the operator lists (WEB_APP_ORIGINS). Nothing is assumed about the web app sharing the API's
// origin: a staging site or a front end on a sibling subdomain is one more entry.
function sec_origin_allowed(string $origin): bool {
	$origin = strtolower($origin);
	$host = $_SERVER['HTTP_HOST'] ?? "";
	if (is_string($host) && $host !== "" && preg_match('#^https?://' . preg_quote(strtolower($host), '#') . '\z#', $origin)) return true;
	return $origin === sec_origin_of_url(APP_URL) || in_array($origin, WEB_APP_ORIGINS, true);
}

// Ends the request with a 403 when a browser sends a write (any method but GET, HEAD, OPTIONS) from a
// page that is not ours, whoever is logged in: the session cookie rides along on such a request, and
// login is itself a target (login CSRF). Origin decides first: a browser sends it on every write, and
// "Origin: null" (a sandboxed page, a redirect) is refused with the rest. Only when it is absent does
// Sec-Fetch-Site speak: anything but same-origin or none is refused ("same-site" is a sibling subdomain:
// a web app hosted there is listed, and its Origin says so). A request with neither header (curl, a
// native client) is no browser's write and goes through to the authentication.
function sec_exit_if_cross_origin(): void {
	if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) return;

	$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
	$fetch_site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
	if (is_string($origin)) $refused = !sec_origin_allowed($origin);
	elseif (is_string($fetch_site)) $refused = !in_array(strtolower($fetch_site), ['same-origin', 'none'], true);
	else $refused = false;

	if ($refused) http_error(403, "cross_origin_refused", "This request comes from a page that is not allowed to write to this API.");
}

// ---------------------------------------------------------------------------
// Two-factor authentication
// ---------------------------------------------------------------------------

// the string vocabulary (TOTP_STATE_NAMES, constants.php) the JSON API exposes instead of
// the raw 0-3 DB int -- shared by api/totp.php and api/key_infos.php so the two never drift apart.
function sec_totp_state_name($state) {
	if (!is_int($state)) return "unknown";
	return TOTP_STATE_NAMES[$state] ?? "unknown";
}

// Starts the set-up: a new secret is stored (state "init") and returned with what the user needs
// to add it to an authenticator app.
function sec_totp_begin($db, array $user_account): array {
	$totp = TOTP::generate();
	$totp->setLabel($user_account["email1"]);
	$totp->setIssuer('MONCYCLE.APP');
	$totp->setParameter('image', APP_URL . "img/moncycleapp512.jpg");
	db_update_user_account_totp_secret($db, $totp->getSecret(), $user_account["no_user_account"]);
	db_update_user_account_totp_state($db, TOTP_STATE_INIT, $user_account["no_user_account"]);

	$qr_writer = new Writer(new ImageRenderer(new RendererStyle(150), new SvgImageBackEnd()));
	return [
		"totpState" => sec_totp_state_name(TOTP_STATE_INIT),
		"initSecret" => $totp->getSecret(),
		"otpauth" => $totp->getProvisioningUri(),
		"qrcode" => $qr_writer->writeString($totp->getProvisioningUri()),
	];
}

// does the one-time code (spaces allowed) match the account's secret? The web client sends the code as a
// number, which loses a leading zero ("091710" arrives as 91710): it is given its zeros back first, or one
// code in ten would be refused.
function sec_totp_code_valid(array $user_account, $code): bool {
	if (!is_string($code) && !is_int($code)) return false;
	$number = intval(preg_replace('/\s+/', '', (string) $code));
	$totp = TOTP::createFromSecret($user_account["totp_secret"]);
	return $number > 0 && $totp->verify(str_pad((string) $number, $totp->getDigits(), "0", STR_PAD_LEFT));
}

// ---------------------------------------------------------------------------
// Captcha: the answer is stored on the visitor's token, burnt as soon as it is read
// ---------------------------------------------------------------------------

// Stores the answer of the captcha shown to the visitor, giving them a token to hang it on when
// they have none (or one that has been purged).
function sec_captcha_issue($db, string $phrase): void {
	$cookie_token = sec_cookie_token();
	$known = $cookie_token !== "" && !is_null(db_select_auth_token_captcha($db, $cookie_token));

	// A visitor logged in keeps their session: its cookie is not replaced by a captcha's, which would end
	// the session in this browser. They have no use for a captcha, login and register refuse a logged-in caller.
	// (A session is stored hashed, a captcha's token as it is: that is why $known above is false for one.)
	if (!$known && $cookie_token !== "" && !is_null(db_select_user_account_auth_token($db, sec_hash_token($cookie_token)))) return;

	if (!$known) {
		$cookie_token = sec_random_password(64);
		db_insert_auth_token($db, NULL, sec_device_name("CAPTCHA"), "FR", $cookie_token, 3);
		sec_set_token_cookie($cookie_token, '+2 days');
	}

	// SECURITY: keyed by auth_token_str (the cookie value), not no_auth_token, otherwise this
	// silently updates 0 rows and the image shown never matches what is stored
	db_update_auth_token_captcha($db, $cookie_token, $phrase);
}

// The answer stored for this visitor, or null; it can be read once.
function sec_captcha_take($db): ?string {
	if (sec_cookie_token() === "") return null;

	$stored = db_select_auth_token_captcha($db, sec_cookie_token());
	if (is_null($stored)) return null;

	db_update_auth_token_use($db, $stored["no_auth_token"]);
	// SECURITY: this prevents captcha re-use. Reading the answer is not enough: requests sent together
	// with one captcha all read it above, and the burn is what says which of them has it.
	return db_update_auth_token_captcha_burn($db, sec_cookie_token()) ? $stored["captcha"] : null;
}

function sec_captcha_matches(?string $expected, $answer): bool {
	if (!is_string($answer) && !is_int($answer)) return false;
	return !is_null($expected) && strlen(trim((string) $answer)) > 0 && trim((string) $answer) === $expected;
}

function sec_captcha_verify($db, $answer): bool {
	return sec_captcha_matches(sec_captcha_take($db), $answer);
}

// ---------------------------------------------------------------------------
// Login and its brute-force defence
// ---------------------------------------------------------------------------

// Is the address in one of the ranges ("10.0.0.0/8", "fd00::/8", or one address)? An IPv4-mapped
// IPv6 address ("::ffff:10.0.0.1", what a dual-stack socket gives) is read as the IPv4 one.
function sec_ip_in_ranges(string $ip, array $ranges): bool {
	$unmap = fn(string $packed) => strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ? substr($packed, 12) : $packed;
	$packed = inet_pton($ip);
	if ($packed === false) return false;
	$packed = $unmap($packed);

	foreach ($ranges as $range) {
		[$base, $bits] = array_pad(explode("/", $range, 2), 2, null);
		$base_packed = inet_pton($base);
		if ($base_packed === false) continue;
		$base_packed = $unmap($base_packed);
		if (strlen($base_packed) !== strlen($packed)) continue;

		// a bare address is a range of one; an IPv4-mapped base keeps its prefix length in IPv4 bits
		$bits = is_null($bits) ? strlen($base_packed) * 8 : intval($bits) - (strlen($base_packed) === 4 && str_contains($base, ":") ? 96 : 0);
		if ($bits < 0) continue;
		$whole_bytes = intdiv($bits, 8);
		if (substr($packed, 0, $whole_bytes) !== substr($base_packed, 0, $whole_bytes)) continue;
		if ($bits % 8 === 0) return true;
		$mask = (0xFF << (8 - $bits % 8)) & 0xFF;
		if ((ord($packed[$whole_bytes]) & $mask) === (ord($base_packed[$whole_bytes]) & $mask)) return true;
	}
	return false;
}

// The address the request comes from, for the login throttling and the log. It is REMOTE_ADDR, the peer
// of the connection, unless that peer is one of TRUSTED_PROXIES: then X-Forwarded-For is read from its
// right end, where the proxies we trust wrote, and the first address that is not one of them is the
// client's. Never read the header of an untrusted peer: it is the client's own and can say anything, to
// dodge the throttling or to frame another address. A header that is garbage where the client should be
// is no help: the peer stays the answer.
function sec_client_ip() {
	$peer = $_SERVER['REMOTE_ADDR'] ?? '';
	if (empty(TRUSTED_PROXIES) || !sec_ip_in_ranges($peer, TRUSTED_PROXIES)) return $peer;

	$forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
	if (!is_string($forwarded)) return $peer;
	foreach (array_reverse(explode(",", $forwarded)) as $hop) {
		$hop = trim($hop);
		if (filter_var($hop, FILTER_VALIDATE_IP) === false) return $peer;
		if (!sec_ip_in_ranges($hop, TRUSTED_PROXIES)) return $hop;
	}
	return $peer;
}

// number of recent failed login attempts on this account, ignoring attempts older than
// LOGIN_ATTEMPTS_DECAY_MINUTES so a stale streak doesn't linger forever. The login settings are
// in the config file, and each one at 0 turns its feature off: a decay of 0 counts no attempt
// at all, so neither the captcha nor the lockout can trigger.
function sec_login_effective_attempts($user_account) {
	if (LOGIN_ATTEMPTS_DECAY_MINUTES <= 0) return 0;
	if (!isset($user_account["nb_connection_attempts"]) || empty($user_account["last_failed_attempt"])) return 0;

	$cutoff = new DateTime("-" . LOGIN_ATTEMPTS_DECAY_MINUTES . " minutes");
	if (new DateTime($user_account["last_failed_attempt"]) < $cutoff) return 0;

	return intval($user_account["nb_connection_attempts"]);
}

function sec_login_captcha_required($user_account) {
	return LOGIN_CAPTCHA_THRESHOLD > 0 && sec_login_effective_attempts($user_account) >= LOGIN_CAPTCHA_THRESHOLD;
}

function sec_login_account_locked($user_account) {
	return LOGIN_LOCKOUT_THRESHOLD > 0 && sec_login_effective_attempts($user_account) >= LOGIN_LOCKOUT_THRESHOLD;
}

// The outcome of a login attempt, for the endpoint to answer with:
//   success: ["status" => 200, "data" => [...]]
//   refusal: ["status" => 4xx, "code" => ..., "message" => ..., "meta" => [...]]
// Every refusal but a malformed body and a rate-limited IP is also counted against the IP, and a
// wrong password or TOTP code against the account (the captcha and lockout thresholds).
// Every outcome is logged: the refusals as `auth.login_failed`, with the account when the address
// has one and its count of failed attempts, this one included when it was counted against it.
// The address in $body["email"] is read as it is: the endpoint has already normalised it
// (account_email_normalise(), api/login.php), which is how it is stored.
function sec_login($db, array $body): array {
	$user_account = [];

	$refuse = function (int $status, string $code, string $message, array $meta = [], bool $counted_on_account = false) use (&$user_account) {
		$known = isset($user_account["no_user_account"]);
		log_event("auth.login_failed", [
			"err" => $code,
			"att" => $known ? sec_login_effective_attempts($user_account) + intval($counted_on_account) : null,
			"uid" => $known ? $user_account["no_user_account"] : null,
		]);
		return ["status" => $status, "code" => $code, "message" => $message, "meta" => $meta];
	};

	if (!isset($body["email"]) || !isset($body["password"]) || !is_string($body["password"]) || !filter_var($body["email"], FILTER_VALIDATE_EMAIL)) {
		return $refuse(400, "missing_credentials", "'email' and 'password' are required.");
	}

	$client_ip = sec_client_ip();
	$user_account = db_select_user_account_by_email($db, $body["email"]) ?? [];
	$account_locked = sec_login_account_locked($user_account);

	// surfaced on every outcome below (successes included): it is a hint for the client's *next*
	// attempt, not tied to whether this one succeeded.
	$captcha_required = sec_login_captcha_required($user_account);
	$meta = $captcha_required ? ["captchaRequired" => true] : [];

	// a refusal counted against the IP, and against the account when it is a wrong credential
	$counted = function (int $status, string $code, string $message, bool $wrong_credential = false) use ($db, $client_ip, $body, $meta, $refuse) {
		if ($wrong_credential) db_update_login_failure($db, $body["email"]);
		db_insert_login_attempt_ip($db, $client_ip);
		return $refuse($status, $code, $message, $meta, $wrong_credential);
	};

	if (!LOGIN_ENABLED) return $counted(403, "login_disabled", "Login is disabled.");

	// already throttled: not counted again, or a blocked IP could grow this table forever
	if (LOGIN_IP_MAX_ATTEMPTS > 0 && db_count_login_attempt_ip($db, $client_ip) >= LOGIN_IP_MAX_ATTEMPTS) {
		return $refuse(429, "rate_limited", "Too many attempts from this network, please try again later.", $meta);
	}

	if (empty($body["email"]) || empty($body["password"])) return $counted(400, "missing_credentials", "'email' and 'password' are required.");

	if ($captcha_required && !sec_captcha_verify($db, $body["captcha"] ?? "")) return $counted(403, "captcha_required", "Missing or incorrect captcha.");

	if (isset($user_account["user_enabled"]) && !boolval($user_account["user_enabled"])) return $counted(403, "account_disabled", "Account deactivated.");

	// an address with no account is checked against a hash nobody can match, for the time a check takes
	$password_ok = password_verify($body["password"], $user_account["password"] ?? AUTH_DUMMY_PASSWORD_HASH) && isset($user_account["password"]);

	// hard lockout only kicks in on a WRONG password: the account owner can still log in with the
	// correct credentials, so this can't be abused to lock a victim out (an account with a TOTP code
	// is the exception, see below)
	if (!$password_ok) {
		return $account_locked
			? $counted(403, "account_locked", "Account temporarily locked after too many failed attempts.", true)
			: $counted(401, "invalid_credentials", "Incorrect password or non-existent account.", true);
	}

	$totp_active = $user_account["totp_state"] == TOTP_STATE_ACTIVE;
	$code = $body["code"] ?? "";
	if (!is_string($code) && !is_int($code)) $code = "";
	// With the password right, the code is the only thing left to guess: the wrong ones are counted on the
	// account (below), and past the lockout threshold the code is no longer looked at, the right one included.
	// The password alone can then keep a TOTP account locked: the price of a code that cannot be guessed.
	if ($totp_active && $account_locked) {
		return $counted(403, "account_locked", "Account temporarily locked after too many failed attempts.", true);
	}
	if ($totp_active && !sec_totp_code_valid($user_account, $code)) {
		// wrong or missing code: one outcome either way
		$given = strlen((string) $code) > 0 && intval(preg_replace('/\s+/', '', (string) $code)) > 0;
		return $counted(401, $given ? "totp_invalid" : "totp_required",
			$given ? "Correct password, but the TOTP code is incorrect." : "Correct password, but a TOTP code is required.", true);
	}

	// the failures this login ends, read before sec_auth_success() resets them
	$attempts = sec_login_effective_attempts($user_account);
	unset($user_account["password"], $user_account["totp_secret"]);
	$token = sec_auth_success($db, $user_account);
	log_event("auth.login_succeeded", ["att" => $attempts, "totp" => $totp_active]);

	return ["status" => 200, "data" => array_merge([
		"token" => $token,
		"userId" => $user_account["no_user_account"],
		"totpUsed" => $totp_active,
	], $meta)];
}
