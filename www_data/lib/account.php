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
	return account_method_id((string) ($body["method"] ?? NFP_METHOD_BILLINGS), boolval($body["temperatureTracking"] ?? false));
}

// ---------------------------------------------------------------------------
// POST /api/account
// ---------------------------------------------------------------------------

// What a body changes of the account row: [the values db_update_user_account_settings() takes, the
// JSON names of the fields it changes]. An absent or unusable field leaves the account as it is.
function account_apply_json(array $account, array $body): array {
	$new = [
		"name" => $account["name_user_account"], "email2" => $account["email2"], "nfp_method" => $account["nfp_method"],
		"age" => $account["age"], "sponsor" => $account["sponsor"], "timeline_asc" => $account["timeline_asc"], "research" => $account["research"],
		"auto_mail_export" => $account["auto_mail_export"],
	];
	$changed = [];

	if (isset($body["name"]) && strlen((string) $body["name"]) > 0) {
		$new["name"] = $body["name"];
		$changed[] = "name";
	}
	if (isset($body["secondaryEmail"]) && (empty($body["secondaryEmail"]) || filter_var($body["secondaryEmail"], FILTER_VALIDATE_EMAIL))) {
		$new["email2"] = $body["secondaryEmail"];
		$changed[] = "secondaryEmail";
	}
	if (isset($body["method"]) || isset($body["temperatureTracking"])) {
		$new["nfp_method"] = account_method_id_from_json($body);
		$changed[] = "method";
	}
	if (!empty($body["birthYear"]) && intval($body["birthYear"]) >= 1) {
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
// who the user is, their settings, the shape of their timeline. $user_account is the row
// sec_auth_token() read. Nothing secret in it.
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
	];
}
