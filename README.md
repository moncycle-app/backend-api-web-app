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

An unset or empty variable takes its default; **required** ones have none and the app does not work without them.

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | **required** | MariaDB server hostname. |
| `DB_PORT` | `3306` | MariaDB server port. |
| `DB_NAME` | **required** | MariaDB database name. |
| `DB_ID` | **required** | MariaDB login. |
| `DB_PASSWORD_FILE` | – | Path of a file holding the MariaDB password, typically a Docker secret (`/run/secrets/db_password`). Preferred over `DB_PASSWORD`, which it overrides when the file is readable. |
| `DB_PASSWORD` | – | MariaDB password in clear; set it or `DB_PASSWORD_FILE`. |
| `SMTP_HOST` | **required** | SMTP server hostname, used for the welcome, password-recovery, cycle, reminder and deletion-warning mails. |
| `SMTP_PORT` | `465` | SMTP server port (implicit TLS only, no STARTTLS). |
| `SMTP_MAIL` | **required** | Sender address, also used as the SMTP login. |
| `SMTP_PASSWORD_FILE` | – | Path of a file holding the SMTP password, typically a Docker secret. Preferred over `SMTP_PASSWORD`, which it overrides when the file is readable. |
| `SMTP_PASSWORD` | – | SMTP password in clear; set it or `SMTP_PASSWORD_FILE`. |
| `APP_URL` | `https://tableau.moncycle.app/` | Public URL of your instance, **with a trailing `/`**: it builds the links in mails and the TOTP QR code. Set it, or the links point to the official instance. |
| `REGISTRATION_ENABLED` | `true` | Allow account creation. Formerly `CREATION_COMPTE`, still read. |
| `LOGIN_ENABLED` | `true` | Allow login. Formerly `CONNEXION_COMPTE`, still read. |
| `CSV_SEP` | `;` | Separator of the CSV exports (with `;` temperatures use a decimal comma, otherwise a point). |
| `PDF_BILLINGS_BORDERS` | `true` | Draw the lines of the Billings PDF chart; `false` gives a chart with no line at all. |
| `LOGIN_ATTEMPTS_DECAY_MINUTES` | `60` | Failed logins on an account older than this restart the count; `0` counts nothing, so no captcha and no lockout. |
| `LOGIN_CAPTCHA_THRESHOLD` | `3` | Failed logins on an account after which a captcha is asked; `0` never asks. |
| `LOGIN_LOCKOUT_THRESHOLD` | `15` | Failed logins on an account after which a wrong password locks it; `0` never locks. |
| `LOGIN_IP_MAX_ATTEMPTS` | `30` | Failed logins from one IP before HTTP 429; `0` for no limit. |
| `APP_SCRIPT_ALLOW` | `127.0.0.1 ::1` | IPs allowed to call `script/` over HTTP, space separated; everyone else gets a 403. |
| `PHP_SECURE_COOKIES` | `true` | Send cookies over HTTPS only; turn off for plain-HTTP dev. |
| `PHP_CACHE` | `1` | `1` enables PHP OPcache and the Apache browser-cache headers; `Off` disables every cache (dev). With OPcache on, restart the container after each edit of a bind-mounted `www_data`. |
| `PHP_SHOW_ERR` | `Off` | `On` shows PHP errors in the browser; they are always logged to `docker logs`. |
| `PHP_ERROR_REPORTING` | `24575` | PHP `error_reporting` as **a number**: `24575` is `E_ALL` without deprecations (the QR library raises some on every `/api/totp`), `32767` is `E_ALL`. |
| `PHP_ASSERTIONS` | `-1` | PHP `zend.assertions`: `-1` compiles assertions out (prod), `1` enables them (dev). |

Booleans read `true`/`false`, `on`/`off`, `yes`/`no` or `1`/`0`, in any case.

The `PHP_*` variables read by `php.ini` (`PHP_CACHE`, `PHP_SHOW_ERR`, `PHP_ERROR_REPORTING`, `PHP_ASSERTIONS`) are the exception: leave one out rather than empty, an empty value is not replaced by the default (`PHP_ERROR_REPORTING=` means 0: nothing reported).

> **Configuration files:** the internal constants of the app (DB codes, API vocabularies, limits, PDF layouts, ...) are all in [www_data/constants.php](www_data/constants.php), loaded by `config.php`. A `config.php` written by hand (from `config.exemple.php`, outside Docker) must start with `require_once __DIR__ . "/constants.php";`, as the example does.

---

### Dev vs prod

The image is **prod by default**: forgetting a variable never turns debugging on.  
Dev is opt-in, with one env file: copy [dev.env.example](dev.env.example) to `dev.env` (git-ignored) and give it to the container, `docker run --env-file dev.env ...` or `env_file: ./dev.env` in compose.  
It shows PHP errors, reports everything, enables assertions, turns off OPcache and every HTTP cache, and lets cookies travel over plain HTTP.  
Prod needs none of it: no `env_file`, see [docker-compose.exemple.yml](docker-compose.exemple.yml).  
The code is baked in the image: a deploy is a rebuild (`composer install` honours `composer.lock`; update the dependencies on purpose with `composer update`, and commit the lock).  
