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

// the typed readers of the environment variables below (env_string, env_int, env_bool)
require_once __DIR__ . "/lib/env.php";

define("APP_URL", env_string("APP_URL", "https://tableau.moncycle.app/"));

define("DB_HOST", env_string("DB_HOST"));
define("DB_ID",   env_string("DB_ID"));
define("DB_NAME", env_string("DB_NAME"));
define("DB_PORT", env_int("DB_PORT", 3306));

if (getenv("DB_PASSWORD_FILE") && strlen(getenv("DB_PASSWORD_FILE"))>0 && is_readable(getenv("DB_PASSWORD_FILE"))) {
	define("DB_PASSWORD", trim(file_get_contents(getenv("DB_PASSWORD_FILE"))));
}
else define("DB_PASSWORD", env_string("DB_PASSWORD"));

define("SMTP_HOST", env_string("SMTP_HOST"));
define("SMTP_PORT", env_int("SMTP_PORT", 465));
define("SMTP_MAIL", env_string("SMTP_MAIL"));

if (getenv("SMTP_PASSWORD_FILE") && strlen(getenv("SMTP_PASSWORD_FILE"))>0 && is_readable(getenv("SMTP_PASSWORD_FILE"))) {
	define("SMTP_PASSWORD", trim(file_get_contents(getenv("SMTP_PASSWORD_FILE"))));
}
else define("SMTP_PASSWORD", env_string("SMTP_PASSWORD"));

// (CREATION_COMPTE and CONNEXION_COMPTE, the French names of these two, are still read)
define("REGISTRATION_ENABLED", env_bool("REGISTRATION_ENABLED", env_bool("CREATION_COMPTE", true)));
define("LOGIN_ENABLED", env_bool("LOGIN_ENABLED", env_bool("CONNEXION_COMPTE", true)));

define("CSV_SEP", env_string("CSV_SEP", ";"));

// Lines of the Billings PDF chart: the grid of its table, the outline of a white stamp.
// PDF_BILLINGS_BORDERS=false draws a chart with no line at all. The FertilityCare grid is made of
// its lines and keeps them.
define("PDF_BILLINGS_BORDERS", env_bool("PDF_BILLINGS_BORDERS", true));

define("PHP_SECURE_COOKIES", env_bool("PHP_SECURE_COOKIES", true));

// Login brute-force defense. A value of 0 turns the feature it sets off.
// Failed attempts on an account older than this many minutes restart at 1 (0: they are not
// counted at all, so there is no captcha and no lockout); db_update_login_failure() binds it too.
define("LOGIN_ATTEMPTS_DECAY_MINUTES", env_int("LOGIN_ATTEMPTS_DECAY_MINUTES", 60));
// Failed attempts on an account after which login asks for a captcha (0: never).
define("LOGIN_CAPTCHA_THRESHOLD", env_int("LOGIN_CAPTCHA_THRESHOLD", 3));
// ... after which a wrong password locks the account; the right one still logs in (0: never).
define("LOGIN_LOCKOUT_THRESHOLD", env_int("LOGIN_LOCKOUT_THRESHOLD", 15));
// Failed attempts from one IP, in the window of db_count_login_attempt_ip(), before HTTP 429 (0: no limit).
define("LOGIN_IP_MAX_ATTEMPTS", env_int("LOGIN_IP_MAX_ATTEMPTS", 30));

// Logs (lib/log.php, README "Logs"). A value that is not one of the allowed ones takes the default:
// a typo never turns the log off.
// The least severe level written: off, error, warning, info, debug.
define("LOG_LEVEL", env_choice("LOG_LEVEL", LOG_LEVEL_VALUES, "info"));
// Comma list of the categories written: auth, account, data, export, mail, system, http. Empty: all.
define("LOG_CATEGORIES", env_list("LOG_CATEGORIES", LOG_CATEGORY_VALUES));
// stdout, stderr, or the absolute path of a file (in Docker, inside /var/log/moncycle). Anything else is stdout.
define("LOG_OUTPUT", env_string("LOG_OUTPUT", "stdout"));
// json: one object per line. text: "<time> <level> <event> key=value ..." for a person reading a terminal.
define("LOG_FORMAT", env_choice("LOG_FORMAT", LOG_FORMAT_VALUES, "json"));
// The client address in the log: full, truncate (a.b.c.0, or the first 3 groups of an IPv6) or none.
define("LOG_IP", env_choice("LOG_IP", LOG_IP_VALUES, "full"));
// The request header whose value, when it is plain, is the request id of the log (else one is made).
// Unlike the others an empty value is a value: no header is read, an id is always made.
define("LOG_REQUEST_ID_HEADER", getenv("LOG_REQUEST_ID_HEADER") === false ? "X-Request-Id" : trim(getenv("LOG_REQUEST_ID_HEADER")));
