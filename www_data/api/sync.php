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

if (!isset($_GET['fromTimestamp'])) {
	http_error(400, "missing_parameter", "'fromTimestamp' query parameter (UTC, YYYY-MM-DD hh:mm:ss) is required.");
}

$from_timestamp = is_string($_GET['fromTimestamp']) ? http_from_iso8601(trim($_GET['fromTimestamp'])) : null;

if (!date_validate_timestamp($from_timestamp ?? "")) {
	http_error(400, "invalid_timestamp", "'fromTimestamp' is not a valid UTC timestamp (YYYY-MM-DD hh:mm:ss).");
}

http_data(200, data_sync($db, $user_account, $from_timestamp));
