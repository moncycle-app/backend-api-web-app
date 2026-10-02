<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// What every JSON endpoint starts with, and so the lib files every endpoint can count on.
// An endpoint that needs more (doc.php, nfp_file.php, mail.php...) requires them itself.

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/sec.php";

// Answers in JSON, opens the database and finds who is calling: [$db, $user_account]. With
// $login_required the request ends here with a 401 when nobody is logged in; without it
// $user_account is null for a visitor.
function api_start(bool $login_required = true): array {
	header('Content-Type: application/json');
	$db = db_open();
	$user_account = sec_auth_token($db);
	if ($login_required) sec_exit_if_logged_out($user_account);
	return [$db, $user_account];
}
