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
require_once "../lib/log.php";
require_once "../lib/sec.php";

log_start(sec_client_ip());
http_exit_if_maintenance();
$db = db_open();

$user_account = sec_auth_token($db);

if (!is_null($user_account)) {
	db_delete_auth_token($db, $user_account["no_auth_token"], $user_account["no_user_account"]);
	log_event("auth.logout");
}

sec_clear_token_cookie();

// this is only ever reached via a plain <a href> top-level navigation (account.html), never
// an XHR/fetch call, so a real redirect is what actually works here -- a 204 (or any JSON
// body) just leaves the browser sitting on this URL instead of navigating anywhere.
http_response_code(303);
header('Location: /auth');
