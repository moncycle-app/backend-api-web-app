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
$captcha = sec_captcha_build();

sec_captcha_issue(db_open(), $captcha->getPhrase());
log_event("auth.captcha_issued");

header('Content-type: image/jpeg');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
$captcha->output();
exit;
