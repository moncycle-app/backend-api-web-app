# MONCYCLE.APP

A menstrual cycle tracking app designed for natural birth control methods (aka natural family planning).

More info 👉 [https://moncycle.app](https://moncycle.app)  

Source code 👉 [https://github.com/moncycle-app/backend-api-web-app](https://github.com/moncycle-app/backend-api-web-app)  

Support us on Tipeee 👉 [https://fr.tipeee.com/moncycleapp](https://fr.tipeee.com/moncycleapp)  

---

### License

Creative Commons **CC BY-NC-SA**  

Attribution – Non-Commercial Use – Share Alike  

Full license details 👉 [https://creativecommons.org/licenses/by-nc-sa/4.0/](https://creativecommons.org/licenses/by-nc-sa/4.0/)  

Legal code 👉 [https://creativecommons.org/licenses/by-nc-sa/4.0/legalcode.fr](https://creativecommons.org/licenses/by-nc-sa/4.0/legalcode.fr)  

---

### Installation

To self-host your own instance of Moncycle.app, you can use:  
- [YunoHost](https://install-app.yunohost.org/?app=moncycle)  
- [Docker](https://hub.docker.com/r/jeanio/moncycle.app)  

> **Note for Docker users:** The database must be installed manually. The SQL file is available at [www_data/script/db/table.sql](https://github.com/moncycle-app/backend-api-web-app/blob/coding/www_data/script/db/table.sql)  

---

### System Requirements

Tested with:  
- **PHP 8.3**  
- **MariaDB 11.1.3**  

---

### Security

- The `www-data/script` directory **must be protected** and should **not** be publicly accessible.  
- The script `www-data/script/cron.php` deletes expired session tokens. It is crucial to run it **once per day**:  
  - More than once per day → may send duplicate emails.  
  - Less than once per day → expired tokens may not be deleted on time, causing missed emails.  

---

### Docker Environment Variables

| Variable | Description |
|----------|-------------|
| DB_HOST | MariaDB server hostname |
| DB_ID | MariaDB login ID |
| DB_NAME | MariaDB database name |
| DB_PORT | MariaDB connection port |
| DB_PASSWORD | MariaDB password |
| SMTP_HOST | SMTP server hostname |
| SMTP_PORT | SMTP server port |
| SMTP_MAIL | SMTP email address (also used for authentication) |
| SMTP_PASSWORD | SMTP password |
| REGISTRATION_ENABLED | Enable account creation for MONCYCLE.APP (boolean, default: true). Formerly `CREATION_COMPTE`, still read. |
| LOGIN_ENABLED | Enable authentication for MONCYCLE.APP (boolean, default: true). Formerly `CONNEXION_COMPTE`, still read. |
| CSV_SEP | Separator for CSV exports |
| PDF_BILLINGS_BORDERS | Draw the lines of the Billings PDF chart (boolean, default: true; false for a chart with no line at all) |
| LOGIN_ATTEMPTS_DECAY_MINUTES | Failed logins on an account older than this restart the count (integer, default: 60; 0 counts nothing, so no captcha and no lockout) |
| LOGIN_CAPTCHA_THRESHOLD | Failed logins on an account after which a captcha is asked (integer, default: 3; 0 never asks) |
| LOGIN_LOCKOUT_THRESHOLD | Failed logins on an account after which a wrong password locks it (integer, default: 15; 0 never locks) |
| LOGIN_IP_MAX_ATTEMPTS | Failed logins from one IP before HTTP 429 (integer, default: 30; 0 for no limit) |
| APP_URL | URL of the hosted app (used for correct links in emails) |
| PHP_CACHE | Enable PHP OPcache (default: `On`) |
| PHP_SHOW_ERR | Show PHP errors in browser (default: `Off`) |
| PHP_SECURE_COOKIES | Restrict cookies to HTTPS only (boolean, default: true) |

> For development, it is recommended to disable cache, display PHP errors, and disable cookie security.

Booleans read `true`/`false`, `on`/`off`, `yes`/`no` or `1`/`0`, in any case; an unset or empty variable takes its default.

> **Configuration files:** the internal constants of the app (DB codes, API vocabularies, limits, PDF layouts, ...) are all in [www_data/constants.php](www_data/constants.php), loaded by `config.php`. A `config.php` written by hand (from `config.exemple.php`, outside Docker) must start with `require_once __DIR__ . "/constants.php";`, as the example does.
