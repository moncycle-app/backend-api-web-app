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
require_once "../lib/data.php";

[$db, $user_account] = api_start();
$no_user_account = $user_account["no_user_account"];

// CREATION/MODIFICATION OF A DESCRIPTION
if ($_SERVER['REQUEST_METHOD'] == "POST") {

	$body = http_json_body();

	$id = null;
	if (!empty($body["id"])) {
		if (!filter_var($body["id"], FILTER_VALIDATE_INT)) {
			http_error(400, "invalid_id", "'id' must match the description to edit.");
		}
		if (!boolval(db_select_description_no_exist($db, $body["id"], $no_user_account))) {
			http_error(404, "not_found", "'id' does not match a known description.");
		}
		$id = intval($body["id"]);
	}

	if (empty($body["name"]) || !isset($body["type"])) {
		http_error(400, "missing_fields", "'name' and 'type' are required.");
	}

	if (!isset(DESCRIPTION_TYPE_BY_NAME[$body["type"]])) {
		http_error(400, "invalid_type", "'type' must be one of: " . implode(", ", array_keys(DESCRIPTION_TYPE_BY_NAME)) . ".");
	}

	if (boolval(db_select_description_name_exist($db, $body["name"], $no_user_account, $id ?? 0))) {
		http_error(409, "duplicate_name", "This description name already exists: " . $body["name"]);
	}

	$name = trim($body["name"]);
	$type = DESCRIPTION_TYPE_BY_NAME[$body["type"]];
	$last_write_client_UTC = http_client_timestamp($body["lastWriteClientUtc"] ?? null);

	$is_new = is_null($id);
	if ($is_new) $id = db_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC);
	else db_update_description_name_type($db, $no_user_account, $id, $name, $type, $last_write_client_UTC);

	http_data($is_new ? 201 : 200, [
		"id" => intval($id),
		"name" => $name,
		"type" => DESCRIPTION_TYPE_NAMES[$type],
		"lastWriteClientUtc" => http_iso8601($last_write_client_UTC),
	]);
}

// DELETION OF A DESCRIPTION
elseif ($_SERVER['REQUEST_METHOD'] == "DELETE") {

	if (empty($_GET["id"])) {
		http_error(400, "missing_id", "'id' query parameter is required.");
	}

	if (!filter_var($_GET["id"], FILTER_VALIDATE_INT)) {
		http_error(400, "invalid_id", "'id' must match the description to delete.");
	}

	if (!boolval(db_select_description_no_exist($db, $_GET["id"], $no_user_account))) {
		http_error(404, "not_found", "'id' does not match a known description.");
	}

	db_delete_descriptions($db, $_GET["id"], $no_user_account);

	http_no_content();
}

// LISTING ALL DESCRIPTIONS
else {
	http_data(200, array_map('data_description_to_json', db_select_description_with_count($db, $no_user_account)));
}
