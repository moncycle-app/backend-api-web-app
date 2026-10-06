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
- Run `script/cron.php` **once per day**, from the host, through the container, **as the web user**: `docker exec -u www-data <container> php /var/www/html/script/cron.php`. It deletes expired session tokens and sends the cycle mails. As `www-data`, because `docker exec` runs as root otherwise, and a log file that root created first cannot be appended to by the web server (see [Logs](#logs)):  
  - More than once per day → may send duplicate emails.  
  - Less than once per day → expired tokens may not be deleted on time, causing missed emails.  
  - Through the CLI it has no time limit (`max_execution_time` is 30 s over HTTP, which can cut it short with many accounts).  
- Apache refuses (403) what is not public: `config*.php`, `constants.php`, `lib/`, `vendor/` (except the three libraries the pages load), `composer.*`, `*.sql`, `*.md`.  
- Logs: the app writes its own (see [Logs](#logs)), to `docker logs` by default, next to Apache's and PHP's errors. There is no Apache access log in the image: it recorded query strings (a TOTP code, the dates of a read). The logs hold IP addresses and account ids: rotate them (see `docker-compose.exemple.yml`).  

**Limits** (`server_conf/`):  
- Apache: request body 256 KB (a request to `/api/` whose `Content-Length` is larger gets a 413 `file_too_large` in the API's JSON envelope before any handler runs, so PHP never starts and logs nothing; `LimitRequestBody` is the same wall for a body sent in chunks, which has no `Content-Length`), 30 s `Timeout`, 30 workers of ~21 MB each. Without that early refusal PHP starts, logs "POST Content-Length of N bytes exceeds the limit" and reads a JSON body into memory: measured, a 20 MB body is read in full and one of 33 MB ends in a `memory_limit` fatal error (500).  
- PHP: `memory_limit` 32M (do not lower it without measuring the peak memory of a multi-year export), `max_execution_time` 30, `file_uploads` Off.  
- `post_max_size` (256K) is coupled with `NFP_LIMIT_BODY_BYTES` in [constants.php](www_data/constants.php) and with Apache's `LimitRequestBody` and `Content-Length` test in [zz-moncycleapp.conf](server_conf/zz-moncycleapp.conf): change the three or none. It does **not** cap a raw JSON body (read from `php://input`): the cap is Apache's 256K and, for `/api/import`, the app's own check of the same size.  

**What the reverse proxy in front must do** (the image serves plain HTTP and is made to sit behind one):  
- TLS and an http → https redirect. The image sends `Strict-Transport-Security` itself when the request carries `X-Forwarded-Proto: https`: make the proxy set that header. If the proxy already sends HSTS, remove that line from `server_conf/zz-moncycleapp.conf` to avoid a duplicate.  
- Rate-limit `/api/login`, `/api/register` and `/api/recover_password`. The last one sleeps 1 to 5 s on purpose and holds one of the 30 workers meanwhile.  
- A body limit of 256 KB (`client_max_body_size 256k` with nginx): the same as Apache's, so a request that is too large is refused at the edge before it costs a worker.  
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
| `LOG_LEVEL` | `info` | Least severe level written: `off`, `error`, `warning`, `info` or `debug`. See [Logs](#logs). |
| `LOG_CATEGORIES` | all | Comma list of the categories written: `auth`, `account`, `data`, `export`, `mail`, `system`, `http`. Empty: all. |
| `LOG_OUTPUT` | `stdout` | `stdout`, `stderr`, or the absolute path of a file (in Docker, a file in `/var/log/moncycle`). Anything else is `stdout`. |
| `LOG_FORMAT` | `json` | `json` (one object per line) or `text` (`<time> <level> <event> key=value ...`, for a person reading a terminal). |
| `LOG_IP` | `full` | The client address in the log: `full`, `truncate` (`a.b.c.0`, or the first 3 groups of an IPv6 address) or `none`. |
| `LOG_REQUEST_ID_HEADER` | `X-Request-Id` | Request header whose value, when it is plain (`A-Za-z0-9._:-`, 64 characters at most), is the request id of the log; otherwise one is made. Set it empty to never read one. |
| `APP_SCRIPT_ALLOW` | `127.0.0.1 ::1` | IPs allowed to call `script/` over HTTP, space separated; everyone else gets a 403. |
| `PHP_SECURE_COOKIES` | `true` | Send cookies over HTTPS only; turn off for plain-HTTP dev. |
| `PHP_CACHE` | `1` | `1` enables PHP OPcache and the Apache browser-cache headers; `Off` disables every cache (dev). With OPcache on, restart the container after each edit of a bind-mounted `www_data`. |
| `PHP_SHOW_ERR` | `Off` | `On` shows PHP errors in the browser; they are always logged to `docker logs`. |
| `PHP_ERROR_REPORTING` | `24575` | PHP `error_reporting` as **a number**: `24575` is `E_ALL` without deprecations (the QR library raises some on every `/api/totp`), `32767` is `E_ALL`. |
| `PHP_ASSERTIONS` | `-1` | PHP `zend.assertions`: `-1` compiles assertions out (prod), `1` enables them (dev). |

Booleans read `true`/`false`, `on`/`off`, `yes`/`no` or `1`/`0`, in any case. A `LOG_*` value that is not one of the allowed ones takes its default: a typo never turns the log off.

The `PHP_*` variables read by `php.ini` (`PHP_CACHE`, `PHP_SHOW_ERR`, `PHP_ERROR_REPORTING`, `PHP_ASSERTIONS`) are the exception: leave one out rather than empty, an empty value is not replaced by the default (`PHP_ERROR_REPORTING=` means 0: nothing reported).

> **Configuration files:** the internal constants of the app (DB codes, API vocabularies, limits, PDF layouts, ...) are all in [www_data/constants.php](www_data/constants.php), loaded by `config.php`. A `config.php` written by hand (from `config.exemple.php`, outside Docker) must start with `require_once __DIR__ . "/constants.php";`, as the example does.

---

### Logs

The app holds health data, so its log is built on data minimisation: a line says **what happened, to which account, to which row, from where**, and never the content. "Account 21 wrote day 4412 (2026-09-20)" is logged; what the day holds is not.

- **Where.** `stdout` by default (`docker logs`); `stderr` or a file are options (`LOG_OUTPUT`). Nothing goes to the database: it would copy health-adjacent history into it. Rotation, shipping and retention are yours.
- **What.** Everything that authenticates, changes, deletes, discloses or mails, and every request that reaches the app (`http.request`, which replaces Apache's access log). Static files (pages, scripts, images) do not reach the app and are not logged.
- **How much.** `LOG_LEVEL` is the least severe level written: `error` (the app failed), `warning` (a refusal or odd behaviour a person should review: a failed login, a wrong password from a logged-in user, a password reset for an unknown address), `info` (normal activity, the audit trail), `debug` (troubleshooting: a captcha issued, a token rejected). `LOG_CATEGORIES` keeps only some of the categories (the part of the event name before the dot).
- **It never breaks a request.** Logging does not throw, does not write to the response and adds no SQL query of its own (the two counts of `account.deleted` are the one exception). An unwritable file or a bad setting is swallowed: a line that cannot go to the file goes to `stdout` and `system.log_sink_failed` says so.

**Never in a log:** passwords (given, old, new, generated); session tokens, cookies and their hashes; TOTP secrets, codes and provisioning URIs; captcha phrases and answers; request or response bodies; what a day or a description contains (stamp, comment, temperature, sensations, the text of a description); the contents of an NFP file; the subject and body of a mail (two of them hold a password); e-mail addresses and first names (an account is its `uid`); SQL text and parameters; SMTP and DB credentials; the query string of a URL; the User-Agent. What **is** logged about data, on purpose: the database id of the row concerned and, for a day, its date (`day`, `dt`, `dsc`). Exception messages and the mailer's error text can embed an address or an SQL fragment: they are masked, stripped of control characters and clipped to 200 characters, and a database exception is logged by its SQLSTATE only.

#### A line

One flat JSON object per line, in UTC; the common keys come first, then the event's own. A key whose value is unknown is left out, never `null`.

```
{"ts":"2026-10-05T14:03:22.123Z","lv":"info","ev":"auth.login_succeeded","rid":"9f3a1c0b7d42","uid":21,"sid":118,"ip":"203.0.113.7","att":0,"totp":true}
{"ts":"2026-10-05T14:09:01.456Z","lv":"warning","ev":"auth.login_failed","rid":"c41d8a5e90b3","uid":21,"ip":"198.51.100.4","err":"invalid_credentials","att":4}
{"ts":"2026-10-05T14:10:05.210Z","lv":"info","ev":"data.day_saved","rid":"7be0f2a64c18","uid":21,"sid":118,"ip":"203.0.113.7","day":4412,"dt":"2026-09-20","new":true}
{"ts":"2026-10-05T14:10:12.001Z","lv":"info","ev":"http.request","rid":"7be1d3095e7a","uid":21,"sid":118,"ip":"203.0.113.7","m":"GET","p":"/api/day","st":200,"ms":14,"n":31,"full":true}
```

With `LOG_FORMAT=text`: `2026-10-05T14:10:05.210Z info data.day_saved rid=7be0f2a64c18 uid=21 sid=118 ip=203.0.113.7 day=4412 dt=2026-09-20 new=true`. A value with a space, a quote or a control character is quoted as JSON. A newline can never split a line, in either format.

A string is clipped to 200 characters and a list to 20 items. A line stays under 4000 bytes (below `PIPE_BUF`, so the workers of the server never interleave their lines): when it would be longer, its last keys are dropped and `"cut":true` is added.

**The request id** (`rid`) is shared by every line of a request, and is sent back in the `X-Request-Id` response header. If the request carries the header named by `LOG_REQUEST_ID_HEADER` and its value is plain, that value is used (so a reverse proxy can hand its own id down: `proxy_set_header X-Request-Id $request_id;` with nginx); otherwise 12 hexadecimal characters are made. It is client-controlled, so it only serves correlation. A cron run has one id for the whole run.

**The address** (`ip`) is what Apache sees (`REMOTE_ADDR`): behind a reverse proxy that is the proxy, unless Apache is given the real client address (`mod_remoteip`). `LOG_IP=truncate` keeps the network only, `none` drops it. There is no `ip` in a cron line.

#### Keys

Every key the code can write. The first block is on every line.

| Key | Meaning |
|-----|---------|
| `ts` | UTC time, ISO-8601 with milliseconds. |
| `lv` | Level: `error`, `warning`, `info`, `debug`. |
| `ev` | Event name, `<category>.<name>` (see below). |
| `rid` | Request id. |
| `src` | `cli` for the cron; absent for a web request. |
| `uid` | Account id (`no_user_account`). |
| `sid` | Session id (`no_auth_token`, never the token itself). |
| `ip` | Client address, as `LOG_IP` allows. |
| `att` | Failed login attempts on the account, with the decay applied (`LOGIN_ATTEMPTS_DECAY_MINUTES`); on a refusal that counts against the account (wrong password, wrong code, locked) this one is included. On a success: the failures it ended. Absent when the address has no account. |
| `totp` | A one-time code was required and given. |
| `err` | The refusal or API error code (`invalid_credentials`, `captcha_invalid`, `unauthorized`...). |
| `why` | The reason: for `auth.token_rejected` `unknown` or `disabled`; for `account.deleted` `user_request` or `inactivity`; for `system.log_sink_failed` `open_basedir`, `permission_denied`, `read_only`, `missing_directory`, `is_directory`, `not_permitted`, `cannot_open` or `write_failed`. |
| `nfp` | The NFP method id (1 to 4) of a new account. |
| `act` | What was refused: `register`, `password_change`, `delete`, `totp_enable`, `totp_disable`. |
| `out` | Number of other sessions closed by a password change. |
| `fld` | Names of the account settings changed (the names, never the values). |
| `ndy` | Days erased with an account. |
| `nds` | Descriptions erased with an account (`account.deleted`) or created by an import (`data.import`). |
| `day` | Day id (`no_day`). |
| `dt` | The `date_obs` of a day. |
| `new` | The row was created by this write. |
| `dsc` | Description id (`no_description`), or the ids of the descriptions a day save created. |
| `dry` | The import was a dry run. |
| `ovr` | The import was allowed to overwrite days (`override`). |
| `rd` | Days the import file holds. |
| `cr` | Days the import created (or would). |
| `ow` | Days the import overwrote (or would). |
| `dt0`, `dt1` | Earliest and latest `date_obs` an import wrote. |
| `day0`, `day1` | Smallest and largest day id an import wrote: bounds, not a list. |
| `fmt` | Export format: `pdf`, `csv`, `nfp`. |
| `anon` | The export was anonymous. |
| `per` | Length of the exported period in days (not the dates). |
| `kind` | Which mail: `welcome`, `new_password`, `cycle`, `reminder`, `deletion_warning`. |
| `to` | Number of recipients of a mail. |
| `fil` | Number of attachments of a mail. |
| `msg` | A scrubbed message: the mailer's error, an exception message, a fatal error. |
| `cls` | Class of an exception. |
| `sql` | SQLSTATE of a database exception. |
| `at` | Where an exception was thrown, `file:line`. |
| `ok` | The cron run reached its end. |
| `ms` | Duration in milliseconds. |
| `sent` | Mails the cron run sent. |
| `ko` | Mails the cron run could not send. |
| `del` | Accounts the cron run deleted. |
| `tok` | Session tokens the cron run purged. |
| `ipa` | Login attempts (IP) the cron run purged. |
| `f` | The log file that could not be used. |
| `m` | HTTP method. |
| `p` | URL path, never the query string. |
| `st` | HTTP status. |
| `n` | Rows a read returned (days, descriptions). |
| `full` | The read was the whole history. |
| `cut` | The line was shortened to fit. |

#### Events

| Event | Level | Keys | When |
|-------|-------|------|------|
| `auth.login_succeeded` | info | `att`, `totp` | A login. |
| `auth.login_failed` | warning | `err`, `att`, `uid` | A refused login: `missing_credentials`, `login_disabled`, `rate_limited`, `captcha_required`, `account_disabled`, `invalid_credentials`, `account_locked`, `totp_required`, `totp_invalid`. `uid` when the address has an account. |
| `auth.logout` | info | | A logout with a valid token. |
| `auth.captcha_issued` | debug | | A captcha image was served. |
| `auth.token_rejected` | debug | `why` | A token was presented and no active account matched. |
| `account.registered` | info | `nfp` | An account was created. |
| `account.refused` | warning | `act`, `err` | A registration refused (`registration_disabled`, `captcha_invalid`, `account_exists`), a wrong password (`invalid_password`) on a password change or an account deletion, a wrong TOTP code (`totp_invalid`). |
| `account.password_reset` | info | | A password reset for a known address. |
| `account.password_reset_unknown` | warning | | A password reset for an address with no account. |
| `account.password_changed` | info | `out` | |
| `account.settings_changed` | info | `fld` | Only when something changed. |
| `account.totp_setup_started` | info | | |
| `account.totp_enabled` | info | | |
| `account.totp_disabled` | info | | |
| `account.reactivated` | info | | A write by the owner of an account flagged inactive. |
| `account.deleted` | info | `why`, `ndy`, `nds` | An account erased, by its owner or for inactivity (RGPD). |
| `data.day_saved` | info | `day`, `dt`, `new`, `dsc` | Written once the transaction has committed. |
| `data.day_cleared` | info | `day`, `dt` | Nothing is logged for a date that had never been written. |
| `data.description_saved` | info | `dsc`, `new` | |
| `data.description_deleted` | info | `dsc` | |
| `data.import` | info | `dry`, `ovr`, `rd`, `cr`, `ow`, `nds`, `dt0`, `dt1`, `day0`, `day1` | One line per import, not per day. A dry run writes nothing and so has no bounds. A refused or rolled-back import logs nothing but its `http.request`. |
| `export.file` | info | `fmt`, `anon`, `per` | A PDF, CSV or NFP export. |
| `export.raw_dump` | info | | The raw data export. |
| `mail.sent` | info | `kind`, `to`, `fil` | |
| `mail.failed` | error | `kind`, `to`, `msg` | |
| `system.exception` | error | `cls`, `sql`, `at`, `msg` | An uncaught exception (the client gets a 500 `unexpected_error`). A database exception has `sql` and no `msg`. |
| `system.cron_started` | info | | |
| `system.cron_ended` | info, **error** if it failed | `ok`, `ms`, `sent`, `ko`, `del`, `tok`, `ipa`, `msg` | `ok:false` when the run died before its end (uncaught exception, fatal error, timeout); the counters are what had been done by then. A mail that could not be sent is `ko` and a `mail.failed` line, and the run is still `ok:true`. |
| `system.log_sink_failed` | error | `f`, `why` | The log file could not be opened or written; the lines go to `stdout`. At most once per request. |
| `http.request` | info | `m`, `p`, `st`, `ms`, `err`, `n`, `full` | The end of every request that reached the app. A read is this line with a `GET`, the `uid`, the path and `n` / `full`: `full` is true for `GET /api/day` with no filter and `GET /api/sync` from the start. |

`account.refused` and `auth.login_failed` are the only lines whose volume an attacker controls (`rate_limited`): the reverse proxy's rate limit (above) bounds it. Docker's own health check calls `/api/health_check` every 30 s; it is not logged unless it fails.

#### To a file

`LOG_OUTPUT=/var/log/moncycle/app.log` appends to a file instead. The file is opened once per request, written with one `write` per line and never locked, so lines stay whole between workers; and a rename-based `logrotate` needs no signal and no `copytruncate`.

- In the Docker image `open_basedir` allows `/var/log/moncycle` and nothing else outside the code: the file must be named inside that directory, and a volume mounted there. The directory belongs to `www-data` (uid 33) in the image, so a **named volume** inherits it; a **bind mount** takes the host directory's owner: `chown 33:33 /host/dir`. Outside Docker, any path the web user can append to works.
- `docker exec` runs as root, and a file first created by root cannot be appended to by the web server: run the cron as `www-data` (`docker exec -u www-data ...`, see [Security](#security)).
- With `read_only: true` the directory is not writable without a volume; the app then logs to `stdout` and says why in `system.log_sink_failed`.
- Cron lines go where the cron's own output goes: with `stdout`, the output of the `docker exec` and not `docker logs`. A file gathers the web and the cron lines in one place.

```yaml
    volumes:
      - moncycle_logs:/var/log/moncycle
    environment:
      LOG_OUTPUT: /var/log/moncycle/app.log
volumes:
  moncycle_logs:
```

```
# /etc/logrotate.d/moncycle: on the host, the file is in the volume (docker volume inspect moncycle_logs)
/var/lib/docker/volumes/moncycle_logs/_data/app.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    create 640 33 33
}
```

The lines hold IP addresses and account ids with the time of their activity: keep them only as long as you need them. A deleted account's old lines stay until they rotate out.

---

### Dev vs prod

The image is **prod by default**: forgetting a variable never turns debugging on.  
Dev is opt-in, with one env file: copy [dev.env.example](dev.env.example) to `dev.env` (git-ignored) and give it to the container, `docker run --env-file dev.env ...` or `env_file: ./dev.env` in compose.  
It shows PHP errors, reports everything, enables assertions, turns off OPcache and every HTTP cache, lets cookies travel over plain HTTP, and logs at `debug` level in the readable `text` format.  
Prod needs none of it: no `env_file`, see [docker-compose.exemple.yml](docker-compose.exemple.yml).  
The code is baked in the image: a deploy is a rebuild (`composer install` honours `composer.lock`; update the dependencies on purpose with `composer update`, and commit the lock).  
