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
require_once "../lib/date.php";
require_once "../lib/sec.php";
require_once "../lib/http.php";

header('Content-Type: application/json');

$db = db_open();

$user_account = sec_auth_token($db);
sec_exit_si_non_connecte($user_account);

$type_by_name = ["undefined" => 0, "observation" => 1, "sensation" => 2];
$name_by_type = [0 => "undefined", 1 => "observation", 2 => "sensation"];

function description_to_json(array $row, array $name_by_type): array {
	return [
		"id" => intval($row["no_description"]),
		"name" => $row["name"],
		"type" => $name_by_type[intval($row["type"])] ?? "undefined",
		"useCount" => intval($row["use_count"] ?? 0),
	];
}

// CREATION/MODIFICATION OF A DESCRIPTION
if ($_SERVER['REQUEST_METHOD'] == "POST") {

	$body = http_json_body();

	$desc_no = null;
	if (isset($body["id"]) && !empty($body["id"])) {
		if (!filter_var($body["id"], FILTER_VALIDATE_INT)) {
			http_error(400, "invalid_id", "'id' must match the description to edit.");
		}
		if (!boolval(db_select_description_no_exist($db, $body["id"], $user_account["no_user_account"]))) {
			http_error(404, "not_found", "'id' does not match a known description.");
		}
		$desc_no = intval($body["id"]);
	}

	if (!isset($body["name"]) || empty($body["name"]) || !isset($body["type"])) {
		http_error(400, "missing_fields", "'name' and 'type' are required.");
	}

	if (!isset($type_by_name[$body["type"]])) {
		http_error(400, "invalid_type", "'type' must be one of: " . implode(", ", array_keys($type_by_name)) . ".");
	}

	if (boolval(db_select_description_name_exist($db, $body["name"], $user_account["no_user_account"], $desc_no ?? 0))) {
		http_error(409, "duplicate_name", "This description name already exists: " . $body["name"]);
	}

	$desc_name = trim($body["name"]);
	$desc_type = $type_by_name[$body["type"]];

	$last_write_client_UTC = http_from_iso8601($body["lastWriteClientUtc"] ?? null);
	if (!$last_write_client_UTC || !date_validate_timestamp($last_write_client_UTC)) {
		$last_write_client_UTC = date('Y-m-d H:i:s');
	}

	try {

		$db->exec("START TRANSACTION");

		$is_new = is_null($desc_no);
		if ($is_new) $desc_no = db_insert_description($db, $user_account["no_user_account"], $desc_name, $desc_type, $last_write_client_UTC);
		else db_update_description_name_type($db, $user_account["no_user_account"], $desc_no, $desc_name, $desc_type, $last_write_client_UTC);

		$db->exec("COMMIT");

	} catch (\Throwable $th) {
		$db->exec("ROLLBACK");
		throw $th;
	}

	http_data($is_new ? 201 : 200, [
		"id" => intval($desc_no),
		"name" => $desc_name,
		"type" => $name_by_type[$desc_type],
		"lastWriteClientUtc" => http_iso8601($last_write_client_UTC),
	]);
}

// DELETION OF A DESCRIPTION
elseif ($_SERVER['REQUEST_METHOD'] == "DELETE") {

	if (!isset($_GET["id"]) || empty($_GET["id"])) {
		http_error(400, "missing_id", "'id' query parameter is required.");
	}

	if (!filter_var($_GET["id"], FILTER_VALIDATE_INT)) {
		http_error(400, "invalid_id", "'id' must match the description to delete.");
	}

	if (!boolval(db_select_description_no_exist($db, $_GET["id"], $user_account["no_user_account"]))) {
		http_error(404, "not_found", "'id' does not match a known description.");
	}

	db_delete_descriptions($db, $_GET["id"], $user_account["no_user_account"]);

	http_no_content();
}

// LISTING ALL DESCRIPTIONS
else {

	$rows = db_select_description_with_count($db, $user_account["no_user_account"]);
	http_data(200, array_map(fn($row) => description_to_json($row, $name_by_type), $rows));
}
