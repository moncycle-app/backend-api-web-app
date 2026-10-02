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
require_once "../lib/account.php";

[$db, $user_account] = api_start();

$nfp_method = intval($user_account["nfp_method"]);

http_data(200, [
	"userId" => $user_account["no_user_account"],
	"email" => $user_account["email1"],
	"secondaryEmail" => $user_account["email2"],
	"method" => account_method_name($nfp_method),
	"temperatureTracking" => account_tracks_temperature($nfp_method),
	"birthYear" => $user_account["age"],
	"name" => $user_account["name_user_account"],
	"inscriptionDate" => http_iso8601($user_account["inscription_date"]),
	"sponsor" => boolval($user_account["sponsor"]),
	"research" => boolval($user_account["research"]),
	"timelineAscending" => boolval($user_account["timeline_asc"]),
	"allCyclesFirstDay" => db_select_cycles($db, $user_account["no_user_account"]),
	"allPregnancyDates" => db_select_pregnancies($db, $user_account["no_user_account"]),
	"totpState" => sec_totp_state_name($user_account["totp_state"]),
	"lastWriteClientUtc" => http_iso8601($user_account["last_write_client_UTC"]),
]);
