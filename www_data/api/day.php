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

// READING OBSERVATION(S)
if ($_SERVER['REQUEST_METHOD'] == "GET") {

	$dates = [];
	if (isset($_GET["date"])) {
		$dates = is_string($_GET["date"]) ? array_map('date_parse_ymd', explode(",", $_GET["date"])) : [null];
		if (in_array(null, $dates, true)) {
			http_error(400, "invalid_date", "'date' must be one or more YYYY-MM-DD values separated by commas.");
		}
	}

	$range = [];
	foreach (["start_date", "end_date"] as $name) {
		if (!isset($_GET[$name])) continue;
		$range[$name] = date_parse_ymd($_GET[$name]) ?? http_error(400, "invalid_date", "'$name' must be in YYYY-MM-DD format.");
	}
	if (count($range) === 1) {
		http_error(400, "invalid_date_range", "'start_date' and 'end_date' must be used together.");
	}

	$result = [];
	foreach ($dates as $date) {
		$result[$date] = day_format_to_json(data_construct_day($db, $date, $no_user_account));
	}

	$all_days = [];
	if ($range) $all_days = db_select_day_timelines_frame($db, $range["start_date"], $range["end_date"], $no_user_account);
	elseif (empty($result)) $all_days = db_select_all_day_timeline($db, $no_user_account);

	// the days come in date order: the cycle is the one the last first day started
	$cycle_date = null;
	foreach ($all_days as $row) {
		if ($row["cycle_1st_day"]) $cycle_date = $row["date_obs"];
		$result[$row["date_obs"]] = day_format_to_json(data_construct_day($db, $row["date_obs"], $no_user_account, $row, $cycle_date));
	}

	log_note(["n" => count($result), "full" => empty($dates) && !$range]);
	http_data(200, $result);
}

// CREATION/UPDATE OF AN OBSERVATION
elseif ($_SERVER['REQUEST_METHOD'] == "POST") {

	$body = http_json_body();

	$date = date_parse_ymd($body['date'] ?? null) ?? http_error(400, "invalid_date", "'date' is required and must be a real date in YYYY-MM-DD format.");

	[$day, $problems] = day_format_validate($body);
	if (!empty($problems)) {
		http_error(400, "invalid_day", "The day cannot be stored.", ["issues" => $problems]);
	}

	data_reactivate_account($db, $user_account);

	$is_new = data_save_day($db, $no_user_account, $date, $day, http_client_timestamp($body['lastWriteClientUtc'] ?? null));
	if (is_null($is_new)) {
		http_error(409, "stale_write", "This day was written more recently than this change: it was not saved.");
	}

	http_data($is_new ? 201 : 200, day_format_to_json(data_construct_day($db, $date, $no_user_account)));
}

// DELETION OF AN OBSERVATION
elseif ($_SERVER['REQUEST_METHOD'] == "DELETE") {

	$date = date_parse_ymd($_GET['date'] ?? null) ?? http_error(400, "invalid_date", "'date' query parameter is required and must be in YYYY-MM-DD format.");

	if (!data_clear_day($db, $no_user_account, $date, http_client_timestamp($_GET['lastWriteClientUtc'] ?? null))) {
		http_error(409, "stale_write", "This day was written more recently than this deletion: it was not cleared.");
	}

	http_no_content();
}

else {
	http_error(405, "method_not_allowed", "Supported methods: GET, POST, DELETE.");
}
