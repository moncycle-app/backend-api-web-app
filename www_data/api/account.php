<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";
require_once "../lib/date.php";
require_once "../lib/http.php";

header('Content-Type: application/json');

$db = db_open();

$user_account = sec_auth_token($db);
sec_exit_si_non_connecte($user_account);

$body = http_json_body();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

	if (!isset($body["password"]) || empty($body["password"])) {
		http_error(400, "missing_password", "'password' is required to confirm account deletion.");
	}

	$full_account = db_select_user_account_par_mail($db, $user_account["email1"])[0] ?? [];

	if (!isset($full_account["password"]) || !password_verify($body["password"], $full_account["password"])) {
		http_error(401, "invalid_password", "Incorrect password.");
	}

	db_delete_user_account($db, $user_account["no_user_account"]);
	setcookie("MONCYCLEAPP_TOKEN", '', -1, '/');

	http_no_content();
}

// UPDATE OF ACCOUNT INFORMATION (POST)
$field_updates = [];
$updated_account = $user_account;

if (isset($body["name"]) && strlen((string) $body["name"]) > 0) {
	$updated_account["name"] = $body["name"];
	$field_updates[] = "name";
}
else {
	$updated_account["name"] = $user_account["name_user_account"];
}

if (isset($body["secondaryEmail"]) && (empty($body["secondaryEmail"]) || filter_var($body["secondaryEmail"], FILTER_VALIDATE_EMAIL))) {
	$updated_account["email2"] = $body["secondaryEmail"];
	$field_updates[] = "secondaryEmail";
}

if (isset($body["method"]) || isset($body["temperatureTracking"])) {
	$method = strtolower(trim((string) ($body["method"] ?? NFP_METHOD_BILLINGS)));
	$temperature_tracking = boolval($body["temperatureTracking"] ?? false);
	if ($method === strtolower(NFP_METHOD_FERTILITY_CARE)) $nfp_method = $temperature_tracking ? NFP_METHOD_ID_FERTILITYCARE_TEMP : NFP_METHOD_ID_FERTILITYCARE;
	else $nfp_method = $temperature_tracking ? NFP_METHOD_ID_BILLINGS_TEMP : NFP_METHOD_ID_BILLINGS;
	$updated_account["nfp_method"] = $nfp_method;
	$field_updates[] = "method";
}

if (isset($body["birthYear"]) && !empty($body["birthYear"])) {
	$age = intval($body["birthYear"]);
	if ($age && $age >= 1) {
		$updated_account["age"] = $age;
		$field_updates[] = "birthYear";
	}
}

if (isset($body["timelineAscending"])) {
	$updated_account["timeline_asc"] = boolval($body["timelineAscending"]) ? 1 : 0;
	$field_updates[] = "timelineAscending";
}

if (isset($body["research"])) {
	$updated_account["research"] = boolval($body["research"]) ? 1 : 0;
	$field_updates[] = "research";
}

if (isset($body["sponsor"])) {
	$updated_account["sponsor"] = boolval($body["sponsor"]) ? 1 : 0;
	$field_updates[] = "sponsor";
}

$last_write_client_UTC = http_from_iso8601($body["lastWriteClientUtc"] ?? null);
if (!$last_write_client_UTC || !date_validate_timestamp($last_write_client_UTC)) {
	$last_write_client_UTC = date('Y-m-d H:i:s');
}
$updated_account["last_write_client_UTC"] = $last_write_client_UTC;

if (!empty($field_updates)) {
	db_update_user_account_param(
		$db, $updated_account["name"], $updated_account["email2"], $updated_account["nfp_method"], $updated_account["age"],
		$updated_account["sponsor"], $updated_account["timeline_asc"], $updated_account["research"], $updated_account["last_write_client_UTC"],
		$user_account["no_user_account"]
	);
}

http_data(200, [
	"name" => $updated_account["name"],
	"fieldsUpdated" => $field_updates,
	"lastWriteClientUtc" => http_iso8601($last_write_client_UTC),
]);
