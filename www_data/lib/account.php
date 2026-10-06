<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The user_account row as the code and the JSON API see it: its method, and what a
// POST /api/account body changes.

require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/sec.php";

// ---------------------------------------------------------------------------
// nfp_method: one 1-4 int in the DB, a method name plus a temperature flag everywhere else
// (NFP_METHOD_BY_ID, constants.php).
// ---------------------------------------------------------------------------

// The method of the account as the NFP format names it. Anything unknown reads as Billings.
function account_method_name(int $nfp_method): string {
	return NFP_METHOD_BY_ID[$nfp_method][0] ?? NFP_METHOD_BILLINGS;
}

function account_tracks_temperature(int $nfp_method): bool {
	return NFP_METHOD_BY_ID[$nfp_method][1] ?? false;
}

// The id of a method name (any case) and temperature flag, as the API takes them. Anything
// that is not FertilityCare is Billings, as before the two fields were split.
function account_method_id(string $method, bool $temperature_tracking): int {
	$method = strtolower(trim($method)) === strtolower(NFP_METHOD_FERTILITY_CARE) ? NFP_METHOD_FERTILITY_CARE : NFP_METHOD_BILLINGS;
	return array_search([$method, $temperature_tracking], NFP_METHOD_BY_ID, true);
}

// The id a body's "method" and "temperatureTracking" ask for; Billings without temperature when absent.
function account_method_id_from_json(array $body): int {
	$method = $body["method"] ?? NFP_METHOD_BILLINGS;
	return account_method_id(is_string($method) ? $method : NFP_METHOD_BILLINGS, boolval($body["temperatureTracking"] ?? false));
}

// ---------------------------------------------------------------------------
// The addresses and the password of the account
// ---------------------------------------------------------------------------

// An email address as it is stored and looked up: trimmed and in lower case, so that Alice@X.test
// and alice@x.test are one account. "" when it is not a string (a client can send a list or a number).
// FILTER_VALIDATE_EMAIL refuses non-ASCII, so strtolower never meets a multibyte character.
function account_email_normalise(mixed $email): string {
	return is_string($email) ? strtolower(trim($email)) : "";
}

// Is this the account's password? The row sec_auth_token() reads holds no hash, so it is read again.
function account_password_matches($db, array $user_account, mixed $password): bool {
	if (!is_string($password) || $password === "") return false;
	$full_account = db_select_user_account_by_email($db, $user_account["email1"]) ?? [];
	return isset($full_account["password"]) && password_verify($password, $full_account["password"]);
}

// ---------------------------------------------------------------------------
// POST /api/account
// ---------------------------------------------------------------------------

// A birth year a person can give: this year, or up to ACCOUNT_BIRTH_YEAR_MAX_AGE years back. The
// statistics average it, and user_account.age is a smallint.
function account_birth_year_valid(int $year): bool {
	$this_year = intval(date("Y"));
	return $year >= $this_year - ACCOUNT_BIRTH_YEAR_MAX_AGE && $year <= $this_year;
}

// The secondary address a body asks for when it is a change: the new address (normalised, "" to clear
// it), or null when the body asks nothing, something unusable, or what the account already has. The
// secondary address receives every cycle by mail, so the endpoint asks for the password before it
// lets a session change it.
function account_secondary_email_change(array $account, array $body): ?string {
	if (!isset($body["secondaryEmail"]) || !is_string($body["secondaryEmail"])) return null;
	$email = account_email_normalise($body["secondaryEmail"]);
	if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
	return $email === account_email_normalise($account["email2"]) ? null : $email;
}

// What a body changes of the account row: [the values db_update_user_account_settings() takes, the
// JSON names of the fields it changes]. An absent or unusable field leaves the account as it is.
// The password that lets a session change "secondaryEmail" is checked by the endpoint, not here.
function account_apply_json(array $account, array $body): array {
	$new = [
		"name" => $account["name_user_account"], "email2" => $account["email2"], "nfp_method" => $account["nfp_method"],
		"age" => $account["age"], "sponsor" => $account["sponsor"], "timeline_asc" => $account["timeline_asc"], "research" => $account["research"],
		"auto_mail_export" => $account["auto_mail_export"],
	];
	$changed = [];

	// a name that is not a string, is empty or is longer than its column is unusable
	[$name] = isset($body["name"]) ? day_format_text($body["name"], "name", ACCOUNT_LIMIT_NAME_CHARS) : [null];
	if (!is_null($name) && $name !== '') {
		$new["name"] = $name;
		$changed[] = "name";
	}
	if (isset($body["secondaryEmail"]) && is_string($body["secondaryEmail"])) {
		$email2 = account_email_normalise($body["secondaryEmail"]);
		if ($email2 === '' || filter_var($email2, FILTER_VALIDATE_EMAIL)) {
			$new["email2"] = $email2;
			$changed[] = "secondaryEmail";
		}
	}
	if (isset($body["method"]) || isset($body["temperatureTracking"])) {
		$new["nfp_method"] = account_method_id_from_json($body);
		$changed[] = "method";
	}
	if (!empty($body["birthYear"]) && account_birth_year_valid(intval($body["birthYear"]))) {
		$new["age"] = intval($body["birthYear"]);
		$changed[] = "birthYear";
	}
	foreach (["timelineAscending" => "timeline_asc", "research" => "research", "sponsor" => "sponsor", "autoMailExport" => "auto_mail_export"] as $json => $column) {
		if (!isset($body[$json])) continue;
		$new[$column] = boolval($body[$json]) ? 1 : 0;
		$changed[] = $json;
	}

	return [$new, $changed];
}

// ---------------------------------------------------------------------------
// GET /api/key_infos
// ---------------------------------------------------------------------------

// The account summary a client starts from, shared by GET /api/key_infos and GET /api/sync:
// who the user is, their settings, the shape of their timeline, and where the news banner comes from
// (NEWS_URL, null when it is off). $user_account is the row sec_auth_token() read. Nothing secret in it.
function account_key_infos($db, array $user_account): array {
	$nfp_method = intval($user_account["nfp_method"]);

	return [
		"userId" => $user_account["no_user_account"],
		"email" => $user_account["email1"],
		"secondaryEmail" => $user_account["email2"],
		"method" => account_method_name($nfp_method),
		"temperatureTracking" => account_tracks_temperature($nfp_method),
		"birthYear" => $user_account["age"],
		"name" => $user_account["name_user_account"],
		"inscriptionDate" => http_iso8601($user_account["inscription_date"]),
		"sponsor" => boolval($user_account["sponsor"]),
		"research" => boolval($user_account["research"]),
		"timelineAscending" => boolval($user_account["timeline_asc"]),
		"autoMailExport" => boolval($user_account["auto_mail_export"]),
		"allCyclesFirstDay" => db_select_cycles($db, $user_account["no_user_account"]),
		"allPregnancyDates" => db_select_pregnancies($db, $user_account["no_user_account"]),
		"totpState" => sec_totp_state_name($user_account["totp_state"]),
		"lastWriteClientUtc" => http_iso8601($user_account["last_write_client_UTC"]),
		"newsUrl" => str_starts_with(NEWS_URL, "https://") ? NEWS_URL : null,
	];
}
