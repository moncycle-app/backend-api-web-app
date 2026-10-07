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

> **Note for Docker users:** The database must be installed manually. The SQL file is [www_data/script/db/table.sql](www_data/script/db/table.sql).  

---

### Quick start

A running instance, with Docker, in five steps:

1. **Database.** Create a MariaDB 11.1 database and a user for it, then load the schema: `mariadb -u <user> -p <database> < www_data/script/db/table.sql`.
2. **Compose file.** Copy [docker-compose.exemple.yml](docker-compose.exemple.yml) and set `APP_URL` (with a trailing `/`), the `DB_*` and `SMTP_*` variables and the two secret files (`db_password`, `smtp_password`). SMTP is required: the password of a new account is sent by mail. All the settings are in [Docker Environment Variables](#docker-environment-variables).
3. **Start it.** `docker compose up -d`, then `curl -fsS http://127.0.0.1:8080/api/health_check` must answer `{"data":{"status":"ok"}}`.
4. **Reverse proxy.** The container serves plain HTTP on `127.0.0.1:8080`: put TLS in front, and set `TRUSTED_PROXIES` to the proxy's address, or every visitor is one address to the login throttling. See [Security](#security) and [Deployments](#deployments).
5. **Cron, once a day:** `docker exec -u www-data <container> php /var/www/html/script/cron.php`. See [Security](#security) for what it does.

Then open `APP_URL`, create an account (a captcha, then the password arrives by mail) and write a first day. Upgrading from an older version: [Upgrading](#upgrading).

---

### System Requirements

Tested with:  
- **PHP 8.4**  
- **MariaDB 11.1.3**  

---

### API documentation

The HTTP API is described in OpenAPI 3.0: [www_data/api/moncycle_app_open_api.yaml](www_data/api/moncycle_app_open_api.yaml). **The spec is the contract:** a change to an endpoint and its YAML go in the same commit.

- **Interactive:** Swagger UI, served by the API itself at `<API origin>/api/`. That is `<APP_URL>api/` when the web app and the API share an origin, which is the default; for the official instance, [https://tableau.moncycle.app/api/](https://tableau.moncycle.app/api/). "Try it out" uses your session: log in on the same instance first.
- **Raw spec:** `<API origin>/api/moncycle_app_open_api.yaml`, for code generators, Postman or Insomnia.

Quick start, in three lines:

1. `POST /api/login` with `{"email": "...", "password": "..."}` answers `{"data": {"token": "...", ...}}`.
2. Send that token on every other call as `Authorization: Bearer <token>`.
3. Answers are `{"data": ...}` or `{"error": {"code": "...", "message": "..."}}`. **Bodies must be `Content-Type: application/json`** (any other type is a 415).

```
TOKEN=$(curl -s -H 'Content-Type: application/json' -d '{"email":"you@example.org","password":"..."}' https://YOUR.HOST/api/login | jq -r .data.token)
curl -s -H "Authorization: Bearer $TOKEN" https://YOUR.HOST/api/key_infos
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"date":"2026-09-20","stampColor":"Red"}' https://YOUR.HOST/api/day
```

---

### Security

- `script/` (cron, stats, migrations) answers only to the container itself: `APP_SCRIPT_ALLOW` (default `127.0.0.1 ::1`) is the list of IPs allowed in, everyone else gets a 403. Behind a reverse proxy Apache sees the proxy's address, not the caller's, so do not open it to an IP unless Apache gets the real client address.  
- Run `script/cron.php` **once per day**, from the host, through the container, **as the web user**: `docker exec -u www-data <container> php /var/www/html/script/cron.php`. It purges expired session tokens, the captchas nobody used and old login attempts, stores the numbers `/api/pub_stat` answers, sends the cycle mails, the reminders to accounts that went quiet and the deletion warnings, and **deletes the accounts that have been inactive for `ACCOUNT_INACTIVITY_DELETE_YEARS` years** (4, in [constants.php](www_data/constants.php); RGPD retention). Do not run it on a database you have not backed up (**`php /var/www/html/script/cron.php --dry-run`** reads and prints what a run would do, who would be mailed, which accounts and how many tokens would be deleted, and sends, deletes and writes nothing; any other argument is refused with exit status 2; it is off over HTTP). It fails in [maintenance mode](#maintenance-mode). As `www-data`, because `docker exec` runs as root otherwise, and a log file that root created first cannot be appended to by the web server (see [Logs](#logs)):  
  - More than once per day → may send duplicate emails.  
  - Less than once per day → expired tokens may not be deleted on time, causing missed emails.  
  - Through the CLI it has no time limit (`max_execution_time` is 30 s over HTTP, which can cut it short with many accounts).  
- Apache refuses (403) what is not public: `config*.php`, `constants.php`, `lib/`, `vendor/` (except the three libraries the pages load), `composer.*`, `*.sql`, `*.md`.  
- Logs: the app writes its own (see [Logs](#logs)), to `docker logs` by default, next to Apache's and PHP's errors. There is no Apache access log in the image: it recorded query strings (a TOTP code, the dates of a read). The logs hold IP addresses and account ids: rotate them (see `docker-compose.exemple.yml`).  
- **What stops a page of another site from writing with your session.** The session is an `HttpOnly` cookie, `SameSite=Strict` (`COOKIE_SAMESITE`). On top of it every write (`POST`, `DELETE`) that a browser sends is checked against its `Origin` header (or `Sec-Fetch-Site` when there is none): the server's own host, `APP_URL` and `WEB_APP_ORIGINS` pass, any other page gets `403 cross_origin_refused`, login and password recovery included. Bodies must be `Content-Type: application/json` (a 415 otherwise), which a form of another site cannot send. A request with none of these headers (curl, a script, a mobile app) is not a browser's and is not refused.  
- **What stops a stored text from running in the page.** What a user can store (the account name, a comment, a label, whether typed or imported from a file) is written into the page as text, never as HTML, and the pages of the image are served with a Content-Security-Policy whose `script-src` is `'self'`: no inline script runs. The news banner is the only HTML from outside, and it is rebuilt from an allow-list of tags (`NEWS_URL`).  
- **Two-factor authentication and the lockout.** A wrong TOTP code after a right password counts like a wrong password. Past `LOGIN_LOCKOUT_THRESHOLD` an account with a TOTP code refuses even the right password and code until the failures decay, so that the code cannot be guessed. Whoever knows the password can keep such an account locked while they keep trying.  
- **`POST /api/recover_password` has no limit of its own.** It replaces the password of an address with a new one at once and mails it: whoever knows an address can lock its owner out until the mail is read, and each call holds one of the 30 workers for 1 to 5 seconds on purpose. Rate-limit it at the edge (below). A one-time link sent by mail, which changes nothing until it is clicked, is the planned replacement.  

**Limits** (`server_conf/`):  
- Apache: request body 256 KB (a request to `/api/` whose `Content-Length` is larger gets a 413 `file_too_large` in the API's JSON envelope before any handler runs, so PHP never starts and logs nothing; `LimitRequestBody` is the same wall for a body sent in chunks, which has no `Content-Length`), 30 s `Timeout`, 30 workers of ~21 MB each. Without that early refusal PHP starts, logs "POST Content-Length of N bytes exceeds the limit" and reads a JSON body into memory: measured, a 20 MB body is read in full and one of 33 MB ends in a `memory_limit` fatal error (500).  
- PHP: `memory_limit` 32M (do not lower it without measuring the peak memory of a multi-year export), `max_execution_time` 30, `file_uploads` Off.  
- `post_max_size` (256K) is coupled with `NFP_LIMIT_BODY_BYTES` in [constants.php](www_data/constants.php) and with Apache's `LimitRequestBody` and `Content-Length` test in [zz-moncycleapp.conf](server_conf/zz-moncycleapp.conf): change the three or none. It does **not** cap a raw JSON body (read from `php://input`): the cap is Apache's 256K and, for `/api/import`, the app's own check of the same size.  

**What the reverse proxy in front must do** (the image serves plain HTTP and is made to sit behind one):  
- TLS and an http → https redirect. The image sends `Strict-Transport-Security` itself when the request carries `X-Forwarded-Proto: https`: make the proxy set that header. If the proxy already sends HSTS, remove that line from `server_conf/zz-moncycleapp.conf` to avoid a duplicate.  
- Rate-limit `/api/login`, `/api/register`, `/api/recover_password` and `/api/captcha` (each captcha is a row in the database, purged after two days). `/api/recover_password` sleeps 1 to 5 s on purpose and holds one of the 30 workers meanwhile.  
- Tell the app the proxy's address: set `TRUSTED_PROXIES` to it (an address or a CIDR range, comma separated) and have the proxy send the real client address in `X-Forwarded-For` (`proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` with nginx). Without it every visitor has the proxy's address: the login throttling (`LOGIN_IP_MAX_ATTEMPTS`, 30 failures in 15 minutes per address) becomes one limit for everyone, so 30 failed logins from anywhere answer 429 to all, and the `ip` of the log is the proxy. The header is believed only when the peer is in `TRUSTED_PROXIES`, read from its right end, skipping trusted addresses: what the client itself wrote on the left is ignored. It is never believed from anyone else, whatever the header says: a visitor who could choose their own address would dodge the throttling with a new one at every attempt, or put the failures on somebody else's. The value is the address the **container** sees the proxy from, which is not always the one you know it by: a proxy on the host reaches a published port from the gateway of the Docker network (`172.x.0.1`), a proxy in another container from that container's address. If the `ip` of the log lines (or `login_attempt_ip.ip_address`) shows an address of your Docker network instead of visitors', that address is the one to put in `TRUSTED_PROXIES` (its network's range, e.g. `172.18.0.0/16`, if it can change). A CDN in front of the proxy adds its own address to the chain: list its ranges too, or the first one the app meets from the right, the CDN's, is taken for the visitor. `APP_SCRIPT_ALLOW` is unchanged, it is Apache's own `Require ip` and still sees the proxy's address.  
- Do not log query strings: `DELETE /api/totp?code=` carries a TOTP code, and the image's own Apache log was removed for that reason.  
- A body limit of 256 KB (`client_max_body_size 256k` with nginx): the same as Apache's, so a request that is too large is refused at the edge before it costs a worker.  
- Block `/script/` too (defence in depth).  

---

### Deployments

**The default: the web app and the API on one origin.** The container serves the pages and `/api/` from the same host, `APP_URL`. A reverse proxy that routes `/api` to another backend still shows the browser one origin, so it needs nothing here but `TRUSTED_PROXIES`. The session cookie is `SameSite=Strict`, which costs nothing in this setup.

**What the image does not do: serve the web app from another origin than its API.** Every URL in the pages is relative (`api/...`) and the API sends no CORS header, so a browser page on another origin cannot call it. What is there is the groundwork, so that nothing assumes one origin:

- `APP_URL` and `WEB_APP_ORIGINS` name the browser origins that may *write* (the `Origin` check, see [Security](#security)): the origin of `APP_URL`, plus the ones you list, plus the API's own host (the `Host` header of the request: if your proxy rewrites it, `APP_URL` is what lets your own pages through).
- `COOKIE_SAMESITE` sets the cookie's `SameSite`. `strict` (the default) is right for the pages and the API on the same site, sibling subdomains of one domain included, for requests a script makes as well as for links. A web app on **another site** needs `lax` (page navigations only) or `none` (requests from scripts, which needs `PHP_SECURE_COOKIES=true`), and relies on the browser not blocking third-party cookies: treat that as fragile.
- Clients that are not browsers (a mobile app, a script) send no `Origin`, use `Authorization: Bearer <token>` and work from anywhere.

**The Content-Security-Policy** is sent by the image for the pages it serves (`server_conf/zz-moncycleapp.conf`): `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' <NEWS_URL>; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'`. Pages served by anything else need the same policy from their own server, and a front end that calls an API on another origin adds that origin to its `connect-src`. Swagger UI is one of the image's pages and is covered by the same policy.

---

### Upgrading

Migrations are `www_data/script/migration/vN-1_to_vN.sql`, with a `.php` runner when the data has to move too; run them in order, one version at a time.

1. **Dump the database first** (`mariadb-dump`), and try the migration on a copy of it: time it, and read its output. MariaDB commits at every `ALTER TABLE`, `CREATE TABLE` and `RENAME`, so the transaction of the script protects the data steps only, not the schema ones. A run that fails half way is undone by restoring the dump, never by running the script again.
2. Stop the old container, or point the proxy away from it, or start the new one with `MAINTENANCE_MODE=true` (see [Maintenance mode](#maintenance-mode)).
3. Run the migration **inside a container of the new image** (it already has the database settings): `docker exec -w /var/www/html/script/migration <container> php v14_to_v15.php`. The script reads `./v14_to_v15.sql` from its working directory, hence `-w`; `script/` answers only to `127.0.0.1` over HTTP, so run it through the CLI as above.
4. Outside Docker, a `config.php` written by hand needs the settings the new version adds: copy them from `config.exemple.php` (v15: `MAINTENANCE_MODE`, `COOKIE_SAMESITE`, `WEB_APP_ORIGINS`, `TRUSTED_PROXIES`, `NEWS_URL`; the login ones before them). A missing one is a fatal "Undefined constant". The Docker image reads them from its environment and needs nothing.
5. Start the new image, log in, write a day, export it, log out, and read `docker logs` for `system.exception`.

**v14 to v15** is a large one: it renames the tables and columns to English, moves the `sensation` text of each day into `description` rows linked to it (and drops the `sensation` column at the end of the script), hashes the session tokens, and stores login addresses in lower case (`account_email_normalise`: `Alice@X.test` and `alice@x.test` become one account). v14 compared addresses exactly, so two accounts could already differ only by the case of theirs. For each such address the script keeps one account, preferring an enabled account, then one that has authenticated, then the one holding more days, then the one used more recently, then the oldest; and for each of the others: **if the two hold no day on the same date, it is merged** (its days move to the kept account, which keeps its own settings, password and sessions; the other's go), **otherwise it is deleted** with its days and tokens. Its output gives the reason of every choice. A line `REVIEW` marks a merge that drops the settings of an account used more recently than the one kept (or of another method), and a deletion that takes days with it or an account used more recently than the one kept: read those on the copy of step 1, and decide by hand (from the dump) if one is wrong. It also gives `key_value` a primary key.

### Maintenance mode

`MAINTENANCE_MODE=true` (an environment variable in Docker, `define("MAINTENANCE_MODE", true);` in a hand-written `config.php`; restart the container to change it) puts the back end in maintenance:

- **The API.** Every endpoint answers `503` and `{"error": {"code": "maintenance", "message": ...}}` before it opens the database: nothing can be read or written, whoever is logged in, login, registration and the downloads (`/export`, `/all_of_my_data_plz`) included, and the visit counters do not move. The answer is the JSON envelope on every endpoint, a file or a redirect included. Two things stay up: `GET /api/health_check`, which still answers `200` when the database is reachable, so that a container in maintenance is not restarted as dead, and the static files (the pages, `GET /api/version`).
- **The cron.** `script/cron.php` refuses to run, dry run included: it does not open the database and sends nothing, writes `system.cron_ended` with `ok:false` and `msg:"maintenance"` (level `error`), exits with status 1 (503 when called over HTTP) and prints `MAINTENANCE_MODE is on`.
- **Not affected:** the migrations (`script/migration/`), which are what you run in maintenance, and `script/stat.php`.

The web app does not know the code yet: its calls fail like any 5xx.

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
| `SMTP_HOST` | **required** | SMTP server hostname, used for the welcome, password-recovery, secondary-address notice, cycle, reminder and deletion-warning mails. |
| `SMTP_PORT` | `465` | SMTP server port (implicit TLS only, no STARTTLS). |
| `SMTP_MAIL` | **required** | Sender address, also used as the SMTP login. |
| `SMTP_PASSWORD_FILE` | – | Path of a file holding the SMTP password, typically a Docker secret. Preferred over `SMTP_PASSWORD`, which it overrides when the file is readable. |
| `SMTP_PASSWORD` | – | SMTP password in clear; set it or `SMTP_PASSWORD_FILE`. |
| `APP_URL` | `https://tableau.moncycle.app/` | Public URL of your instance, **with a trailing `/`**: it builds the links in mails and the TOTP QR code. Set it, or the links point to the official instance. |
| `REGISTRATION_ENABLED` | `true` | Allow account creation. Formerly `CREATION_COMPTE`, still read. |
| `LOGIN_ENABLED` | `true` | Allow login. Formerly `CONNEXION_COMPTE`, still read. |
| `MAINTENANCE_MODE` | `false` | Maintenance: every endpoint of the API answers `503` with the error code `maintenance`, before it reads or writes anything (the health check excepted), and `script/cron.php` fails. See [Maintenance mode](#maintenance-mode). |
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
| `COOKIE_SAMESITE` | `strict` | `SameSite` of the session cookie: `strict`, `lax` or `none` (any case). `none` is honoured only with `PHP_SECURE_COOKIES=true`, otherwise it is `lax`. See [Deployments](#deployments). |
| `WEB_APP_ORIGINS` | – | Browser origins (`https://app.example.org`, comma separated) allowed to write to the API besides its own host and `APP_URL`'s: a staging site, a dev server, another front end. A write from any other origin is a 403. |
| `TRUSTED_PROXIES` | – | Reverse proxies in front of the app (addresses or CIDR ranges, comma separated) whose `X-Forwarded-For` is believed. Empty: the client is the peer of the connection. See [Security](#security). |
| `NEWS_URL` | `https://www.moncycle.app/actu.html` | The https page the web app shows as its news banner (`h4`, `p`, `b`, `i`, `br`, `ul`, `li`, `a` and `time` only). **Set it empty for no banner and no request to anybody.** It goes in the CSP's `connect-src` too, so give a URL with no query or fragment. |
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

**The address** (`ip`) is the peer of the connection (`REMOTE_ADDR`), or, when that peer is one of `TRUSTED_PROXIES`, the client address it forwarded (`X-Forwarded-For`): behind a reverse proxy set `TRUSTED_PROXIES`, or it is the proxy. `LOG_IP=truncate` keeps the network only, `none` drops it. There is no `ip` in a cron line.

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
| `act` | What was refused: `register`, `password_change`, `delete`, `secondary_email`, `totp_enable`, `totp_disable`. |
| `out` | Number of other sessions closed by a password change. |
| `fld` | Names of the account settings changed (the names, never the values). |
| `ndy` | Days erased with an account. |
| `nds` | Descriptions erased with an account (`account.deleted`) or created by an import (`data.import`). |
| `day` | Day id (`no_day`). |
| `dt` | The `date_obs` of a day. |
| `new` | The row was created by this write. |
| `dsc` | Description id (`no_description`), or the ids of the descriptions a day save created. |
| `dry` | The import, or the cron run, was a dry run (the cron's lines carry it only when it is). |
| `ovr` | The import was allowed to overwrite days (`override`). |
| `rd` | Days the import file holds. |
| `cr` | Days the import created (or would). |
| `ow` | Days the import overwrote (or would). |
| `dt0`, `dt1` | Earliest and latest `date_obs` an import wrote. |
| `day0`, `day1` | Smallest and largest day id an import wrote: bounds, not a list. |
| `fmt` | Export format: `pdf`, `csv`, `nfp`. |
| `anon` | The export was anonymous. |
| `per` | Length of the exported period in days (not the dates). |
| `kind` | Which mail: `welcome`, `new_password`, `secondary_email`, `cycle`, `reminder`, `deletion_warning`. |
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
| `system.cron_started` | info | `dry` | |
| `system.cron_ended` | info, **error** if it failed | `ok`, `dry`, `ms`, `sent`, `ko`, `del`, `tok`, `ipa`, `msg` | `ok:false` when the run died before its end (uncaught exception, fatal error, timeout), or was refused: `msg` is `maintenance` when `MAINTENANCE_MODE` is on and nothing was done. The counters are what had been done by then, so all 0 for a dry run. A mail that could not be sent is `ko` and a `mail.failed` line, and the run is still `ok:true`. |
| `system.log_sink_failed` | error | `f`, `why` | The log file could not be opened or written; the lines go to `stdout`. At most once per request. |
| `http.request` | info | `m`, `p`, `st`, `ms`, `err`, `n`, `full` | The end of every request that reached the app. A read is this line with a `GET`, the `uid`, the path and `n` / `full`: `full` is true for `GET /api/day` with no filter and `GET /api/sync` from the start. |

`account.refused` and `auth.login_failed` are the only lines whose volume an attacker controls (`rate_limited`): the reverse proxy's rate limit (above) bounds it. The health check of [docker-compose.exemple.yml](docker-compose.exemple.yml) calls `/api/health_check` every 30 s; it is not logged unless it fails.

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
