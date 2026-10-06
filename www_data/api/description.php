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
require_once "../lib/day_format.php";

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
		if (!boolval(db_select_description_exists($db, $body["id"], $no_user_account))) {
			http_error(404, "not_found", "'id' does not match a known description.");
		}
		$id = intval($body["id"]);
	}

	if (empty($body["name"]) || !isset($body["type"])) {
		http_error(400, "missing_fields", "'name' and 'type' are required.");
	}

	if (!is_string($body["type"]) || !isset(DESCRIPTION_TYPE_BY_NAME[$body["type"]])) {
		http_error(400, "invalid_type", "'type' must be one of: " . implode(", ", array_keys(DESCRIPTION_TYPE_BY_NAME)) . ".");
	}

	// the name as it will be stored: what is checked for a duplicate is what is inserted
	[$name, $problem] = day_format_text($body["name"], "name", DAY_LIMIT_DESCRIPTION_CHARS);
	if (!is_null($problem)) {
		http_error(400, "invalid_name", $problem);
	}
	if ($name === '') {
		http_error(400, "missing_fields", "'name' and 'type' are required.");
	}

	$type = DESCRIPTION_TYPE_BY_NAME[$body["type"]];
	$last_write_client_UTC = http_client_timestamp($body["lastWriteClientUtc"] ?? null);

	$is_new = is_null($id);
	$id = data_save_description($db, $no_user_account, $id, $name, $type, $last_write_client_UTC);
	if (is_null($id)) {
		http_error(409, "duplicate_name", "This description name already exists: " . $name);
	}

	http_data($is_new ? 201 : 200, [
		"id" => $id,
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

	if (!boolval(db_select_description_exists($db, $_GET["id"], $no_user_account))) {
		http_error(404, "not_found", "'id' does not match a known description.");
	}

	data_delete_description($db, $no_user_account, intval($_GET["id"]));

	http_no_content();
}

// LISTING ALL DESCRIPTIONS
else {
	$descriptions = array_map('data_description_to_json', db_select_description_with_count($db, $no_user_account));
	log_note(["n" => count($descriptions)]);
	http_data(200, $descriptions);
}
