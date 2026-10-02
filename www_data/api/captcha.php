<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

use Gregwar\Captcha\CaptchaBuilder;

require_once "../vendor/autoload.php";
require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";

$captcha = new CaptchaBuilder;
$captcha->build();

sec_captcha_issue(db_open(), $captcha->getPhrase());

header('Content-type: image/jpeg');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
$captcha->output();
exit;
