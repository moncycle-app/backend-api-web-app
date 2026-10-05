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

try {

	[$db, $user_account] = api_start(false);

	$body = http_json_body();

	if (!is_null($user_account)) {
		http_data(200, ["userId" => $user_account["no_user_account"], "alreadyAuthenticated" => true]);
	}

	$login = sec_login($db, $body);

	if (isset($login["data"])) http_data($login["status"], $login["data"]);
	http_error($login["status"], $login["code"], $login["message"], $login["meta"]);

}
catch (\Throwable $e) {
	log_exception($e);
	http_error(500, "unexpected_error", $e->getMessage());
}
