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

if (!isset($_GET['fromTimestamp'])) {
	http_error(400, "missing_parameter", "'fromTimestamp' query parameter (UTC, YYYY-MM-DD hh:mm:ss) is required.");
}

$from_timestamp = http_from_iso8601(trim($_GET['fromTimestamp']));

if (!date_validate_timestamp($from_timestamp)) {
	http_error(400, "invalid_timestamp", "'fromTimestamp' is not a valid UTC timestamp (YYYY-MM-DD hh:mm:ss).");
}

$days = array_map(
	fn($row) => day_to_json(data_construnct_day($db, $row["date_obs"], $no_user_account, $row)),
	db_select_day_timelines_modified($db, $from_timestamp, $no_user_account)
);

http_data(200, [
	"days" => $days,
	"descriptions" => array_map('data_description_to_json', db_select_description_with_count_modified($db, $from_timestamp, $no_user_account)),
]);
