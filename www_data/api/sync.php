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

// absent or empty: from the start (a client's first sync)
$requested = $_GET['fromTimestamp'] ?? "";
$from_timestamp = !is_string($requested) ? null : (trim($requested) === "" ? SYNC_FROM_START : http_from_iso8601(trim($requested)));

if (!date_validate_timestamp($from_timestamp ?? "")) {
	http_error(400, "invalid_timestamp", "'fromTimestamp' is not a valid UTC timestamp (YYYY-MM-DD hh:mm:ss).");
}

$sync = data_sync($db, $user_account, $from_timestamp);
log_note(["n" => count($sync["days"]), "full" => $from_timestamp === SYNC_FROM_START]);
http_data(200, $sync);
