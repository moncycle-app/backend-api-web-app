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
- **PHP 8.4**  
- **MariaDB 11.1.3**  

---

### Security

- `script/` (cron, stats, migrations) answers only to the container itself: `APP_SCRIPT_ALLOW` (default `127.0.0.1 ::1`) is the list of IPs allowed in, everyone else gets a 403. Behind a reverse proxy Apache sees the proxy's address, not the caller's, so do not open it to an IP unless Apache gets the real client address.  
- Run `script/cron.php` **once per day**, from the host, through the container: `docker exec <container> php /var/www/html/script/cron.php`. It deletes expired session tokens and sends the cycle mails:  
  - More than once per day → may send duplicate emails.  
  - Less than once per day → expired tokens may not be deleted on time, causing missed emails.  
  - Through the CLI it has no time limit (`max_execution_time` is 30 s over HTTP, which can cut it short with many accounts).  
- Apache refuses (403) what is not public: `config*.php`, `constants.php`, `lib/`, `vendor/` (except the three libraries the pages load), `composer.*`, `*.sql`, `*.md`.  
- Logs go to `docker logs` (Apache access log, Apache and PHP errors, with the client IP). Rotate them (see `docker-compose.exemple.yml`): they hold IP addresses.  

**Limits** (`server_conf/`):  
- Apache: request body 32 MB (`LimitRequestBody`: a larger one gets a 413 before PHP reads anything), 30 s `Timeout`, 30 workers of ~21 MB each. Below that wall PHP reads a JSON body into memory: measured, a 20 MB body is read in full and one of 33 MB ends in a `memory_limit` fatal error (500), so keep the proxy's limit far lower (see below).  
- PHP: `memory_limit` 32M (do not lower it without measuring the peak memory of a multi-year export), `max_execution_time` 30, `file_uploads` Off.  
- `post_max_size` (256K) is coupled with `NFP_LIMIT_BODY_BYTES` in [constants.php](www_data/constants.php): change both or neither. It does **not** cap a raw JSON body (read from `php://input`): the caps are Apache's 32 MB and, for `/api/import`, the app's own 256K check.  

**What the reverse proxy in front must do** (the image serves plain HTTP and is made to sit behind one):  
- TLS and an http → https redirect. The image sends `Strict-Transport-Security` itself when the request carries `X-Forwarded-Proto: https`: make the proxy set that header. If the proxy already sends HSTS, remove that line from `server_conf/zz-moncycleapp.conf` to avoid a duplicate.  
- Rate-limit `/api/login`, `/api/register` and `/api/recover_password`. The last one sleeps 1 to 5 s on purpose and holds one of the 30 workers meanwhile.  
- A body limit of 256 KB (`client_max_body_size 256k` with nginx), far stricter than Apache's.  
- Block `/script/` too (defence in depth).  

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
| APP_SCRIPT_ALLOW | IPs allowed to call `script/` over HTTP, space separated (default: `127.0.0.1 ::1`) |
| PHP_CACHE | Caches (default: `1`). On: PHP OPcache, which never re-reads a file (restart the container after each edit of a bind-mounted `www_data`), and the browser cache headers of Apache. `Off`: no cache at all (dev) |
| PHP_SHOW_ERR | Show PHP errors in the browser (default: `Off`; errors are always logged to `docker logs`) |
| PHP_ERROR_REPORTING | PHP `error_reporting`, **a number** (a name such as `E_ALL` reads as 0 and silences everything). Default: `24575` (`E_ALL` without deprecations; the QR library raises some on every `/api/totp`). `32767` is `E_ALL` |
| PHP_ASSERTIONS | PHP `zend.assertions` (default: `-1`, assertions not compiled; `1` for dev) |
| PHP_SECURE_COOKIES | Restrict cookies to HTTPS only (boolean, default: true) |

Booleans read `true`/`false`, `on`/`off`, `yes`/`no` or `1`/`0`, in any case; an unset or empty variable takes its default.

The `PHP_*` variables read by `php.ini` (`PHP_CACHE`, `PHP_SHOW_ERR`, `PHP_ERROR_REPORTING`, `PHP_ASSERTIONS`) are the exception: leave one out rather than empty, an empty value is not replaced by the default (`PHP_ERROR_REPORTING=` means 0: nothing reported).

> **Configuration files:** the internal constants of the app (DB codes, API vocabularies, limits, PDF layouts, ...) are all in [www_data/constants.php](www_data/constants.php), loaded by `config.php`. A `config.php` written by hand (from `config.exemple.php`, outside Docker) must start with `require_once __DIR__ . "/constants.php";`, as the example does.

---

### Dev vs prod

The image is **prod by default**: forgetting a variable never turns debugging on.  
Dev is opt-in, with one env file: copy [dev.env.example](dev.env.example) to `dev.env` (git-ignored) and give it to the container, `docker run --env-file dev.env ...` or `env_file: ./dev.env` in compose.  
It shows PHP errors, reports everything, enables assertions, turns off OPcache and every HTTP cache, and lets cookies travel over plain HTTP.  
Prod needs none of it: no `env_file`, see [docker-compose.exemple.yml](docker-compose.exemple.yml).  
The code is baked in the image: a deploy is a rebuild (`composer install` honours `composer.lock`; update the dependencies on purpose with `composer update`, and commit the lock).  
