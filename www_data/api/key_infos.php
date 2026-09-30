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

$cycles = db_select_cycles($db, $user_account["no_user_account"]);
$pregnancys = db_select_pregnancys($db, $user_account["no_user_account"]);

// split the single 1-4 "nfp_method" int (stored value, unchanged) into an explicit
// method + temperatureTracking pair, same vocabulary as /api/register
$method_by_nfp_method = [
	1 => ["method" => "billings", "temperatureTracking" => true],
	2 => ["method" => "billings", "temperatureTracking" => false],
	3 => ["method" => "fertilityCare", "temperatureTracking" => true],
	4 => ["method" => "fertilityCare", "temperatureTracking" => false],
];
$method_info = $method_by_nfp_method[intval($user_account["nfp_method"])] ?? ["method" => "billings", "temperatureTracking" => false];

http_data(200, [
	"userId" => $user_account["no_user_account"],
	"email" => $user_account["email1"],
	"secondaryEmail" => $user_account["email2"],
	"method" => $method_info["method"],
	"temperatureTracking" => $method_info["temperatureTracking"],
	"birthYear" => $user_account["age"],
	"name" => $user_account["name_user_account"],
	"inscriptionDate" => http_iso8601($user_account["inscription_date"]),
	"sponsor" => boolval($user_account["sponsor"]),
	"research" => boolval($user_account["research"]),
	"timelineAscending" => boolval($user_account["timeline_asc"]),
	"allCyclesFirstDay" => $cycles,
	"allPregnancyDates" => $pregnancys,
	"totpState" => sec_totp_state_name($user_account["totp_state"]),
	"lastWriteClientUtc" => http_iso8601($user_account["last_write_client_UTC"]),
]);
