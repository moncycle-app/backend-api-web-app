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

define("REGISTRATION_ENABLED", false);
define("LOGIN_ENABLED", false);

define("CSV_SEP", ";");

// Lines of the Billings PDF chart: the grid of its table, the outline of a white stamp. Set to
// false for a chart with no line at all. The FertilityCare grid is made of its lines and keeps them.
define("PDF_BILLINGS_BORDERS", true);

define("PHP_SECURE_COOKIES", true);

// The SameSite attribute of the session cookie: strict, lax or none. Strict costs nothing while the web app
// and the API share an origin or sit on sibling subdomains of one site. A web app on another site needs lax
// (page navigations) or none (requests from scripts; only with PHP_SECURE_COOKIES, else it is lax).
define("COOKIE_SAMESITE", "strict");

// The browser origins ("https://app.example.org") that may write to the API besides its own and APP_URL's:
// a staging site, a dev server, another front end. A write from any other origin is refused (403
// cross_origin_refused). Empty: none.
define("WEB_APP_ORIGINS", []);

// The reverse proxies in front of the app (addresses or CIDR ranges). Only a request that comes from one of
// them has its X-Forwarded-For believed, to find the real client address (the login throttling and the log
// use it). Empty: every request is a client, whatever it sends.
define("TRUSTED_PROXIES", []);

// The news banner of the web app: the https page whose h4, p, b, i, br, ul, li, a and time it shows (nothing else
// gets through). Empty: no banner and no request to anybody.
define("NEWS_URL", "https://www.moncycle.app/actu.html");

// Login brute-force defense. A value of 0 turns the feature it sets off.
// Failed attempts on an account older than this many minutes restart at 1 (0: they are not
// counted at all, so there is no captcha and no lockout); db_update_login_failure() binds it too.
define("LOGIN_ATTEMPTS_DECAY_MINUTES", 60);
// Failed attempts on an account after which login asks for a captcha (0: never).
define("LOGIN_CAPTCHA_THRESHOLD", 3);
// ... after which a wrong password locks the account; the right one still logs in (0: never).
define("LOGIN_LOCKOUT_THRESHOLD", 15);
// Failed attempts from one IP, in the window of db_count_login_attempt_ip(), before HTTP 429 (0: no limit).
define("LOGIN_IP_MAX_ATTEMPTS", 30);

// Logs (lib/log.php, README "Logs").
// The least severe level written: off, error, warning, info, debug.
define("LOG_LEVEL", "info");
// The categories written: auth, account, data, export, mail, system, http. Empty: all.
define("LOG_CATEGORIES", []);
// stdout, stderr, or the absolute path of a file the web server can append to. Anything else is stdout.
define("LOG_OUTPUT", "stdout");
// json: one object per line. text: "<time> <level> <event> key=value ..." for a person reading a terminal.
define("LOG_FORMAT", "json");
// The client address in the log: full, truncate (a.b.c.0, or the first 3 groups of an IPv6) or none.
define("LOG_IP", "full");
// The request header whose value, when it is plain, is the request id of the log (else one is made).
// Empty: no header is read, an id is always made.
define("LOG_REQUEST_ID_HEADER", "X-Request-Id");
