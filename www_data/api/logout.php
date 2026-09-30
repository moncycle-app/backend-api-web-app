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
require_once "../lib/sec.php";

$db = db_open();

$user_account = sec_auth_token($db);

if (!is_null($user_account)) {
	db_delete_auth_token($db, $user_account["no_auth_token"], $user_account["no_user_account"]);
}

setcookie("MONCYCLEAPP_TOKEN", '', -1, '/');
setcookie("MONCYCLEAPP_JETTON", '', -1, '/'); // legacy, to be removed in a few release

// this is only ever reached via a plain <a href> top-level navigation (account.html), never
// an XHR/fetch call, so a real redirect is what actually works here -- a 204 (or any JSON
// body) just leaves the browser sitting on this URL instead of navigating anywhere.
http_response_code(303);
header('Location: /auth');
