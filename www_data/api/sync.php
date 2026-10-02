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

if (!isset($_GET['fromTimestamp'])) {
	http_error(400, "missing_parameter", "'fromTimestamp' query parameter (UTC, YYYY-MM-DD hh:mm:ss) is required.");
}

$from_timestamp = http_from_iso8601(trim($_GET['fromTimestamp']));

if (!date_validate_timestamp($from_timestamp)) {
	http_error(400, "invalid_timestamp", "'fromTimestamp' is not a valid UTC timestamp (YYYY-MM-DD hh:mm:ss).");
}

$days = db_select_day_timelines_modified($db, $from_timestamp, $user_account["no_user_account"]);
for ($i = 0; $i < count($days); $i += 1) {
	$days[$i] = day_to_json(data_construnct_day($db, $days[$i]["date_obs"], $user_account["no_user_account"], $days[$i]));
}

$descriptions = db_select_description_with_count_modified($db, $from_timestamp, $user_account["no_user_account"]);
$descriptions = array_map('data_description_to_json', $descriptions);

http_data(200, [
	"days" => $days,
	"descriptions" => $descriptions,
]);
