<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

define("APP_URL", getenv("APP_URL") ?? "https://tableau.moncycle.app/");

define("DB_HOST",     getenv("DB_HOST")     ?? "");
define("DB_ID",       getenv("DB_ID")       ?? "");
define("DB_NAME",     getenv("DB_NAME")     ?? "");
define("DB_PORT",     getenv("DB_PORT")     ?? "");

if (getenv("DB_PASSWORD_FILE") && strlen(getenv("DB_PASSWORD_FILE"))>0 && is_readable(getenv("DB_PASSWORD_FILE"))) {
	define("DB_PASSWORD", trim(file_get_contents(getenv("DB_PASSWORD_FILE"))) ?? "");
}
else define("DB_PASSWORD", getenv("DB_PASSWORD") ?? "");

define("SMTP_HOST",     getenv("SMTP_HOST")     ?? "");
define("SMTP_PORT",     getenv("SMTP_PORT")     ?? "");
define("SMTP_MAIL",     getenv("SMTP_MAIL")     ?? "");

if (getenv("SMTP_PASSWORD_FILE") && strlen(getenv("SMTP_PASSWORD_FILE"))>0 && is_readable(getenv("SMTP_PASSWORD_FILE"))) {
	define("SMTP_PASSWORD", trim(file_get_contents(getenv("SMTP_PASSWORD_FILE"))) ?? "");
}
else define("SMTP_PASSWORD", getenv("SMTP_PASSWORD") ?? "");

define("CREATION_COMPTE",  getenv("CREATION_COMPTE")  ? filter_var(getenv("CREATION_COMPTE"),  FILTER_VALIDATE_BOOLEAN) : true);
define("CONNEXION_COMPTE", getenv("CONNEXION_COMPTE") ? filter_var(getenv("CONNEXION_COMPTE"), FILTER_VALIDATE_BOOLEAN) : true);

define("CSV_SEP", getenv("CSV_SEP") ? getenv("CSV_SEP") : ";");

define("PHP_SECURE_COOKIES", getenv("PHP_SECURE_COOKIES")=="Off" ? false : true);
