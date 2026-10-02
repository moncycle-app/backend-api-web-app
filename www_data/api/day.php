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
require_once "../lib/data.php";
require_once "../lib/sec.php";
require_once "../lib/http.php";
require_once "../lib/day_format.php";

header('Content-Type: application/json');

$db = db_open();

$user_account = sec_auth_token($db);
sec_exit_si_non_connecte($user_account);

// READING OBSERVATION(S)
if ($_SERVER['REQUEST_METHOD'] == "GET") {

	$dates_req = [];
	$start_date = null;
	$end_date = null;

	if (isset($_GET["date"]) && preg_match("/^\s*\d{4}-\d{2}-\d{2}(\s*,\s*\d{4}-\d{2}-\d{2})*\s*$/", $_GET["date"])) {
		$dates_req = explode(",", $_GET["date"]);
	}
	elseif (isset($_GET["date"])) http_error(400, "invalid_date", "'date' must be one or more YYYY-MM-DD values separated by commas.");

	if (isset($_GET["start_date"]) && preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $_GET["start_date"])) {
		$start_date = trim($_GET["start_date"]);
	}
	elseif (isset($_GET["start_date"])) http_error(400, "invalid_date", "'start_date' must be in YYYY-MM-DD format.");

	if (isset($_GET["end_date"]) && preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $_GET["end_date"])) {
		$end_date = trim($_GET["end_date"]);
	}
	elseif (isset($_GET["end_date"])) http_error(400, "invalid_date", "'end_date' must be in YYYY-MM-DD format.");

	if (($start_date || $end_date) && !($start_date && $end_date)) {
		http_error(400, "invalid_date_range", "'start_date' and 'end_date' must be used together.");
	}

	$result = [];

	foreach ($dates_req as $date) {
		$date = trim($date);
		$result[$date] = day_to_json(data_construnct_day($db, $date, $user_account["no_user_account"]));
	}

	$all_days = [];
	if ($start_date && $end_date) $all_days = db_select_day_timelines_frame($db, $start_date, $end_date, $user_account["no_user_account"]);
	elseif (empty($result)) $all_days = db_select_all_day_timeline($db, $user_account["no_user_account"]);

	$cycle_date = null;
	for ($i = 0; $i < count($all_days); $i += 1) {
		if ($all_days[$i]["cycle_1st_day"]) $cycle_date = $all_days[$i]["date_obs"];
		$day = data_construnct_day($db, $all_days[$i]["date_obs"], $user_account["no_user_account"], $all_days[$i], $cycle_date);
		$result[$day["date_obs"]] = day_to_json($day);
	}

	http_data(200, $result);
}

// CREATION/UPDATE OF AN OBSERVATION
elseif ($_SERVER['REQUEST_METHOD'] == "POST") {

	$body = http_json_body();

	if (!isset($body['date']) || !is_string($body['date']) || !preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $body['date'])) {
		http_error(400, "invalid_date", "'date' is required and must be in YYYY-MM-DD format.");
	}

	$date = trim($body['date']);
	$date_exploded = explode('-', $date);
	if (!checkdate(intval($date_exploded[1]), intval($date_exploded[2]), intval($date_exploded[0]))) {
		http_error(400, "invalid_date", "'date' is not a real calendar date.");
	}

	if (isset($user_account["is_inactive"]) && boolval($user_account["is_inactive"])) {
		db_update_is_inactive($db, $user_account["no_user_account"], 0);
		$user_account["is_inactive"] = 0;
	}

	$last_write_client_UTC = http_from_iso8601($body['lastWriteClientUtc'] ?? null);
	if (!$last_write_client_UTC || !date_validate_timestamp($last_write_client_UTC)) {
		$last_write_client_UTC = date('Y-m-d H:i:s');
	}

	$fields = day_from_json($body);
	$is_new = false;

	try {

		$db->exec("START TRANSACTION");

		$existing = db_select_day_timeline($db, $date, $user_account["no_user_account"]);
		$is_new = is_null($existing);
		$no_day = $is_new ? db_insert_day_timeline($db, $date, $user_account["no_user_account"]) : $existing["no_day"];

		db_update_day_timeline($db, $date, $user_account["no_user_account"], $last_write_client_UTC, $fields);

		$old_description = db_select_all_description_for_day_timeline($db, $user_account["no_user_account"], $no_day);
		$old_description_no = array_column($old_description, "no_description");

		$new_description_no = [];
		foreach (($body['freeMucusObservation'] ?? []) as $name) {
			$new_description_no[] = data_resolve_description_id($db, $user_account["no_user_account"], $name, DESCRIPTION_TYPE_OBSERVATION, $last_write_client_UTC);
		}
		foreach (($body['freeMucusSensation'] ?? []) as $name) {
			$new_description_no[] = data_resolve_description_id($db, $user_account["no_user_account"], $name, DESCRIPTION_TYPE_SENSATION, $last_write_client_UTC);
		}

		$to_delete_description_no = array_diff($old_description_no, $new_description_no);
		$to_add_description_no = array_diff($new_description_no, $old_description_no);

		foreach ($to_delete_description_no as $no_desc) db_delete_linked_descriptions($db, $no_day, $no_desc);
		foreach ($to_add_description_no as $no_desc) db_insert_link_description_day_timeline($db, $no_day, $no_desc);

		$db->exec("COMMIT");

	} catch (\Throwable $th) {
		$db->exec("ROLLBACK");
		throw $th;
	}

	$updated = day_to_json(data_construnct_day($db, $date, $user_account["no_user_account"]));
	http_data($is_new ? 201 : 200, $updated);
}

// DELETION OF AN OBSERVATION
elseif ($_SERVER['REQUEST_METHOD'] == "DELETE") {

	if (!isset($_GET['date']) || !preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $_GET['date'])) {
		http_error(400, "invalid_date", "'date' query parameter is required and must be in YYYY-MM-DD format.");
	}

	$date = trim($_GET['date']);

	db_update_day_timeline($db, $date, $user_account["no_user_account"], date('Y-m-d H:i:s'));

	http_no_content();
}

else {
	http_error(405, "method_not_allowed", "Supported methods: GET, POST, DELETE.");
}
