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
require_once "../lib/http.php";
require_once "../lib/log.php";

header('Content-Type: application/json');

try {
	$db = db_open();
	if (boolval(db_count_user_accounts($db))) http_data(200, ["status" => "oookkk"]);
	http_error(503, "database_unreachable", "Database query did not return the expected result.");
} catch (\Throwable $e) {
	log_exception($e);
	http_error(503, "database_unreachable", "Database is not reachable.");
}
