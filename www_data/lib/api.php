<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// What every JSON endpoint starts with, and so the lib files every endpoint can count on.
// An endpoint that needs more (doc_export.php, nfp_import.php, mail.php...) requires them itself.

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/sec.php";

// Starts the log of the request, answers in JSON, ends with a 503 in maintenance, refuses a write from a page
// that is not ours (a 403 before anything is read), opens the database and finds who is calling: [$db, $user_account]. With
// $login_required the request ends here with a 401 when nobody is logged in; without it $user_account is
// null for a visitor. An exception nobody catches is logged and answered with a 500 "unexpected_error",
// whose message tells nothing.
function api_start(bool $login_required = true): array {
	log_start(sec_client_ip());
	set_exception_handler(function (\Throwable $e) {
		log_exception($e);
		http_error(500, "unexpected_error", "Unexpected error.");
	});
	header('Content-Type: application/json');
	http_exit_if_maintenance();
	sec_exit_if_cross_origin();
	$db = db_open();
	$user_account = sec_auth_token($db);
	if ($login_required) sec_exit_if_logged_out($user_account);
	return [$db, $user_account];
}
