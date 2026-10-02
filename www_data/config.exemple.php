<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// the fixed constants of the app (DB codes, vocabularies, limits, export layouts)
require_once __DIR__ . "/constants.php";

define("APP_URL", "https://tableau.moncycle.app/");

define("DB_HOST", "");
define("DB_ID", "");
define("DB_NAME", "");
define("DB_PORT", 3306);
define("DB_PASSWORD", "");

define("SMTP_HOST", '');
define("SMTP_PORT", 465);
define("SMTP_MAIL", "");
define("SMTP_PASSWORD", "");

define("CREATION_COMPTE", false);
define("CONNEXION_COMPTE", false);

define("CSV_SEP", ";");

// Lines of the Billings PDF chart: the grid of its table, the outline of a white stamp. Set to
// false for a chart with no line at all. The FertilityCare grid is made of its lines and keeps them.
define("PDF_BILLINGS_BORDERS", true);

define("PHP_SECURE_COOKIES", true);

// Login brute-force defense. A value of 0 turns the feature it sets off.
// Failed attempts on an account older than this many minutes restart at 1 (0: they are not
// counted at all, so there is no captcha and no lockout); db_update_co_echoue() binds it too.
define("LOGIN_ATTEMPTS_DECAY_MINUTES", 60);
// Failed attempts on an account after which login asks for a captcha (0: never).
define("LOGIN_CAPTCHA_THRESHOLD", 3);
// ... after which a wrong password locks the account; the right one still logs in (0: never).
define("LOGIN_LOCKOUT_THRESHOLD", 15);
// Failed attempts from one IP, in the window of db_count_login_attempt_ip(), before HTTP 429 (0: no limit).
define("LOGIN_IP_MAX_ATTEMPTS", 30);
