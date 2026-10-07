<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// Every SQL statement of the app. A query is one function: its SQL and its parameters, nothing
// else (the rules of what the data means are in lib/data.php and the others). The DB is MariaDB,
// utf8mb4_bin everywhere, so "=" is exact and case-sensitive.
//
// Reads answer what the caller needs and no more: a list of rows (name => value), one row or null,
// a column, a single value. Writes answer how many rows they changed, inserts the new id.
//
// RGPD data retention (ACCOUNT_INACTIVITY_DELETE_YEARS, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE,
// constants.php) is re-evaluated fresh on every cron run, so there's no "warning already
// sent" flag to maintain: any real activity bumps last_activity (db_select_user_account_to_warn_before_deletion()
// / _to_delete()) and drops the account out of both queries.

// The DSN of the database. The charset is in it: PDO then knows it, and the server hears it in the handshake
// (no SET NAMES round trip).
function db_dsn(): string {
	return "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
}

// Opens the connection. In a web request it is persistent: the Apache worker keeps it after the answer and the
// next request it serves takes it back, which saves the connect and the login (about 250 us of 650 a request,
// measured by script/db_perf.php, which also checks what follows). The CLI (cron, scripts, tools) opens its own, as
// it has nothing to save. $persistent forces one or the other (script/db_perf.php).
//
// Why it is safe: nothing in the app leaves a state on a connection (no SET, no GET_LOCK, no temporary table, no
// autocommit change: db_perf.php scans the code for them); PDO checks that a kept connection is alive and opens
// a new one if not (a MariaDB restart, wait_timeout); and a request that died inside db_transaction() leaves a
// transaction open, whose locks the next request would inherit: it is rolled back here, before anything runs.
// Each worker holds one connection: max_connections must cover the workers of every app container plus the cron.
function db_open(?bool $persistent = null) {
	$db = new PDO(db_dsn(), DB_ID, DB_PASSWORD, [PDO::ATTR_PERSISTENT => $persistent ?? PHP_SAPI !== "cli"]);
	if ($db->inTransaction()) $db->exec("ROLLBACK");
	return $db;
}

// ---------------------------------------------------------------------------
// Running a statement
// ---------------------------------------------------------------------------

// Runs $sql with its named parameters (":name" in the SQL, "name" => value here) and returns the
// statement. It is prepared once per connection and SQL text. A PHP int or bool binds as an
// integer, null as NULL, anything else (strings, floats) as a string.
function db_run($db, string $sql, array $params = []): PDOStatement {
	static $statements = [];
	$statement = $statements[spl_object_id($db)][$sql] ??= $db->prepare($sql);

	foreach ($params as $name => $value) {
		$type = match (true) {
			is_null($value) => PDO::PARAM_NULL,
			is_int($value), is_bool($value) => PDO::PARAM_INT,
			default => PDO::PARAM_STR,
		};
		$statement->bindValue(":$name", is_bool($value) ? intval($value) : $value, $type);
	}
	$statement->execute();
	return $statement;
}

// all the rows
function db_rows($db, string $sql, array $params = []): array {
	return db_run($db, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

// the first row, or null
function db_row($db, string $sql, array $params = []): ?array {
	$statement = db_run($db, $sql, $params);
	$row = $statement->fetch(PDO::FETCH_ASSOC);
	$statement->closeCursor();
	return $row === false ? null : $row;
}

// the first column of every row
function db_column($db, string $sql, array $params = []): array {
	return db_run($db, $sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

// the first column of the first row
function db_value($db, string $sql, array $params = []) {
	$statement = db_run($db, $sql, $params);
	$value = $statement->fetchColumn();
	$statement->closeCursor();
	return $value;
}

// rows changed
function db_exec($db, string $sql, array $params = []): int {
	return db_run($db, $sql, $params)->rowCount();
}

// id of the new row
function db_insert($db, string $sql, array $params = []): string {
	db_run($db, $sql, $params);
	return $db->lastInsertId();
}

// Runs $work in one transaction: committed, and its result returned, or rolled back when it throws.
function db_transaction($db, callable $work) {
	$db->exec("START TRANSACTION");
	try {
		$result = $work();
		$db->exec("COMMIT");
		return $result;
	} catch (\Throwable $error) {
		$db->exec("ROLLBACK");
		throw $error;
	}
}

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------

function db_select_user_account($db, $no_user_account): ?array {
	return db_row($db, "SELECT * FROM user_account WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

// Takes the row of the account until the end of the transaction, so that the writes of one account go
// one after the other. They read what exists and insert what is missing (a day, a label, a link):
// two at once both find it missing, and the unique key refuses the second with an exception.
// Answers whether the account exists.
function db_select_user_account_for_update($db, $no_user_account): bool {
	return db_value($db, "SELECT no_user_account FROM user_account WHERE no_user_account = :no_user_account FOR UPDATE", ["no_user_account" => $no_user_account]) !== false;
}

function db_select_user_account_by_email($db, $email): ?array {
	return db_row($db, "SELECT * FROM user_account WHERE email1 = :email1", ["email1" => $email]);
}

function db_select_user_account_exists($db, $email): bool {
	return boolval(db_value($db, "SELECT COUNT(no_user_account) > 0 FROM user_account WHERE email1 = :email1", ["email1" => $email]));
}

// The new id, or null when the email already has an account: the unique key on email1 is what
// decides, so of two registrations racing for one address only one gets an id.
function db_insert_user_account($db, $name, $nfp_method, $age, $email, $password_hash, $register_comment, $research): ?string {
	try {
		return db_insert($db,
			"INSERT INTO user_account (name, nfp_method, age, email1, password, language, register_comment, research)
			VALUES (:name, :nfp_method, :age, :email1, :password, :language, :register_comment, :research)",
			["name" => $name, "nfp_method" => $nfp_method, "age" => $age, "email1" => $email, "password" => $password_hash, "language" => ACCOUNT_DEFAULT_LANGUAGE, "register_comment" => $register_comment, "research" => $research]
		);
	}
	catch (\PDOException $error) {
		if (intval($error->errorInfo[1] ?? 0) === 1062) return null;   // MariaDB 1062: duplicate entry for a unique key
		throw $error;
	}
}

// $fields: name, email2, nfp_method, age, sponsor, timeline_asc, research, auto_mail_export (see account_apply_json())
function db_update_user_account_settings($db, $no_user_account, array $fields, $last_write_client_UTC) {
	return db_exec($db,
		"UPDATE user_account SET `name` = :name, email2 = :email2, nfp_method = :nfp_method, age = :age, sponsor = :sponsor,
		timeline_asc = :timeline_asc, research = :research, auto_mail_export = :auto_mail_export, last_write_client_UTC = :last_write_client_UTC
		WHERE no_user_account = :no_user_account",
		$fields + ["last_write_client_UTC" => $last_write_client_UTC, "no_user_account" => $no_user_account]
	);
}

function db_update_password_by_email($db, $password_hash, $email) {
	return db_exec($db, "UPDATE user_account SET password = :password, last_password_change = NULL WHERE email1 = :email1", ["password" => $password_hash, "email1" => $email]);
}

function db_update_password($db, $password_hash, $no_user_account) {
	return db_exec($db, "UPDATE user_account SET password = :password, last_password_change = NOW() WHERE no_user_account = :no_user_account", ["password" => $password_hash, "no_user_account" => $no_user_account]);
}

// How many days an account holds (a cleared day is a row too): the unique (account, date) index answers alone.
function db_count_days_of_account($db, $no_user_account): int {
	return intval(db_value($db, "SELECT COUNT(dt.no_day) FROM day_timeline AS dt WHERE dt.no_user_account = :no_user_account", ["no_user_account" => $no_user_account]));
}

// How many descriptions an account holds, whether a day carries them or not.
function db_count_descriptions_of_account($db, $no_user_account): int {
	return intval(db_value($db, "SELECT COUNT(d.no_description) FROM description AS d WHERE d.no_user_account = :no_user_account", ["no_user_account" => $no_user_account]));
}

function db_delete_user_account($db, $no_user_account) {
	return db_exec($db, "DELETE FROM user_account WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

function db_update_is_inactive($db, $no_user_account, $is_inactive) {
	return db_exec($db, "UPDATE user_account SET is_inactive = :is_inactive WHERE no_user_account = :no_user_account", ["is_inactive" => $is_inactive, "no_user_account" => $no_user_account]);
}

function db_update_user_account_totp_secret($db, $totp_secret, $no_user_account) {
	return db_exec($db, "UPDATE user_account SET totp_secret = :totp_secret WHERE no_user_account = :no_user_account", ["totp_secret" => $totp_secret, "no_user_account" => $no_user_account]);
}

function db_update_user_account_totp_state($db, $totp_state, $no_user_account) {
	return db_exec($db, "UPDATE user_account SET totp_state = :totp_state WHERE no_user_account = :no_user_account", ["totp_state" => $totp_state, "no_user_account" => $no_user_account]);
}

// ---------------------------------------------------------------------------
// Sessions, captcha, login attempts
// ---------------------------------------------------------------------------

// The account behind a token (the SHA-256 of what the client holds), with the session's own columns.
function db_select_user_account_auth_token($db, $auth_token_str): ?array {
	return db_row($db,
		"SELECT J.no_user_account, J.no_auth_token, C.name AS name_user_account, J.contry_code, J.name AS name_auth_token,
		J.date_creation AS d_creation_auth_token, J.date_use AS d_use_auth_token, C.nfp_method, C.age, C.email1, C.email2,
		C.nb_connection_attempts, C.sponsor, C.user_enabled, C.is_inactive, C.last_auth_date, C.inscription_date,
		C.last_password_change, C.register_comment, C.totp_secret, C.totp_state, C.research, C.timeline_asc, C.auto_mail_export, C.last_write_client_UTC
		FROM auth_token AS J INNER JOIN user_account AS C ON J.no_user_account = C.no_user_account
		WHERE auth_token_str = :auth_token_str LIMIT 1",
		["auth_token_str" => $auth_token_str]
	);
}

function db_select_auth_tokens($db, $no_user_account) {
	return db_rows($db, "SELECT * FROM auth_token WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

function db_insert_auth_token($db, $no_user_account, $name, $contry_code, $auth_token_str, $expire = 2) {
	return db_insert($db,
		"INSERT INTO auth_token (no_user_account, name, contry_code, auth_token_str, expire)
		VALUES (:no_user_account, :name, :contry_code, :auth_token_str, :expire)",
		["no_user_account" => $no_user_account, "name" => $name, "contry_code" => $contry_code, "auth_token_str" => $auth_token_str, "expire" => $expire]
	);
}

// Stamps the session as used. Every authenticated request calls it, and what reads date_use counts in days
// (the purge below, the cron's 40 days of inactivity), so a session is written at most every 5 minutes.
function db_update_auth_token_use($db, $no_auth_token) {
	return db_exec($db,
		"UPDATE auth_token SET date_use = NOW() WHERE no_auth_token = :no_auth_token AND (date_use IS NULL OR date_use < NOW() - INTERVAL 5 MINUTE)",
		["no_auth_token" => $no_auth_token]
	);
}

function db_delete_auth_token($db, $no_auth_token, $no_user_account) {
	return db_exec($db, "DELETE FROM auth_token WHERE no_auth_token = :no_auth_token AND no_user_account = :no_user_account", ["no_auth_token" => $no_auth_token, "no_user_account" => $no_user_account]);
}

// Every session of the account but one (the caller's own): answers how many were closed.
function db_delete_auth_tokens_other($db, $no_user_account, $no_auth_token_kept) {
	return db_exec($db, "DELETE FROM auth_token WHERE no_user_account = :no_user_account AND no_auth_token <> :no_auth_token", ["no_user_account" => $no_user_account, "no_auth_token" => $no_auth_token_kept]);
}

// sessions not used for 40 days, or older than a year (those that expire: a captcha's does), and the
// captchas of visitors who never logged in (no account), whose cookie lasts 2 days. One condition for
// the purge and for the count of the cron's dry run, which must agree.
function db_sql_old_auth_token(): string {
	return "((date_creation < CURDATE() - INTERVAL 365 DAY OR date_use < CURDATE() - INTERVAL 40 DAY) AND expire > 0)
		OR (no_user_account IS NULL AND date_creation < NOW() - INTERVAL 2 DAY)";
}

function db_delete_old_auth_token($db) {
	return db_exec($db, "DELETE FROM auth_token WHERE " . db_sql_old_auth_token());
}

function db_count_old_auth_token($db): int {
	return intval(db_value($db, "SELECT COUNT(*) FROM auth_token WHERE " . db_sql_old_auth_token()));
}

function db_select_auth_token_captcha($db, $auth_token_str): ?array {
	return db_row($db, "SELECT captcha, no_auth_token FROM auth_token WHERE auth_token_str = :auth_token_str LIMIT 1", ["auth_token_str" => $auth_token_str]);
}

function db_update_auth_token_captcha($db, $auth_token_str, $captcha) {
	return db_exec($db, "UPDATE auth_token SET date_use = NOW(), captcha = :captcha WHERE auth_token_str = :auth_token_str", ["captcha" => $captcha, "auth_token_str" => $auth_token_str]);
}

// Burns the captcha answer of a token. True for one caller only: requests racing for the same answer
// all read it, and the row lock makes every one but the first find it already gone.
function db_update_auth_token_captcha_burn($db, $auth_token_str): bool {
	return db_exec($db, "UPDATE auth_token SET captcha = NULL WHERE auth_token_str = :auth_token_str AND captcha IS NOT NULL", ["auth_token_str" => $auth_token_str]) > 0;
}

function db_update_user_account_logged_in($db, $no_user_account) {
	return db_exec($db, "UPDATE user_account SET last_auth_date = NOW(), nb_connection_attempts = 0, is_inactive = 0 WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

// increments the failed-attempt counter, but restarts it at 1 instead of compounding when the
// last failure is old (LOGIN_ATTEMPTS_DECAY_MINUTES, the same value lib/sec.php decays by)
function db_update_login_failure($db, $email) {
	return db_exec($db,
		"UPDATE user_account SET
		nb_connection_attempts = IF(last_failed_attempt IS NULL OR last_failed_attempt < NOW() - INTERVAL :decay_minutes MINUTE, 1, nb_connection_attempts + 1),
		last_failed_attempt = NOW() WHERE email1 = :email1",
		["decay_minutes" => LOGIN_ATTEMPTS_DECAY_MINUTES, "email1" => $email]
	);
}

function db_insert_login_attempt_ip($db, $ip_address) {
	return db_exec($db, "INSERT INTO login_attempt_ip (ip_address) VALUES (:ip_address)", ["ip_address" => $ip_address]);
}

// number of failed login attempts recorded from this IP in the last 15 minutes, across all accounts
function db_count_login_attempt_ip($db, $ip_address): int {
	return intval(db_value($db, "SELECT COUNT(*) FROM login_attempt_ip WHERE ip_address = :ip_address AND date_attempt > NOW() - INTERVAL 15 MINUTE", ["ip_address" => $ip_address]));
}

// the attempts the cron purges, and the count of its dry run
function db_sql_old_login_attempt_ip(): string {
	return "date_attempt < NOW() - INTERVAL 1 DAY";
}

function db_delete_old_login_attempt_ip($db) {
	return db_exec($db, "DELETE FROM login_attempt_ip WHERE " . db_sql_old_login_attempt_ip());
}

function db_count_old_login_attempt_ip($db): int {
	return intval(db_value($db, "SELECT COUNT(*) FROM login_attempt_ip WHERE " . db_sql_old_login_attempt_ip()));
}

// ---------------------------------------------------------------------------
// Descriptions: the free-text labels of a day, and their links to the days
// ---------------------------------------------------------------------------

function db_select_description_exists($db, $no_description, $no_user_account): bool {
	return boolval(db_value($db, "SELECT COUNT(no_description) > 0 FROM description WHERE no_description = :no_description AND no_user_account = :no_user_account",
		["no_description" => $no_description, "no_user_account" => $no_user_account]));
}

// is the name taken by another description of the account?
function db_select_description_name_exists($db, $name, $no_user_account, $no_description): bool {
	return boolval(db_value($db, "SELECT COUNT(no_description) > 0 FROM description WHERE name = :name AND no_user_account = :no_user_account AND no_description != :no_description",
		["name" => $name, "no_user_account" => $no_user_account, "no_description" => $no_description]));
}

// The labels with the number of days each is on.
function db_select_description_with_count($db, $no_user_account) {
	return db_rows($db,
		"SELECT d.no_description, d.name, COUNT(od.no_day) AS use_count, d.no_user_account, d.type, d.last_write_client_UTC, d.last_write_db
		FROM description AS d LEFT JOIN link_day_timeline_description AS od ON od.no_description = d.no_description
		WHERE d.no_user_account = :no_user_account
		GROUP BY d.no_description, d.name, d.no_user_account, d.type, d.last_write_client_UTC, d.last_write_db ORDER BY use_count DESC, d.name DESC",
		["no_user_account" => $no_user_account]
	);
}

// The label of a name, exact (so "%" and "_" in it mean nothing special) and case-sensitive.
function db_select_description_exact_name($db, $no_user_account, $name): ?array {
	return db_row($db, "SELECT no_description, name, type FROM description WHERE no_user_account = :no_user_account AND name = :name LIMIT 1",
		["no_user_account" => $no_user_account, "name" => $name]);
}

// Every label of the account, as name + type: one query for a file with many names (the dry run of
// the NFP import has to say which are new without creating any).
function db_select_description_name_type($db, $no_user_account) {
	return db_rows($db, "SELECT name, type FROM description WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

function db_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC) {
	return db_insert($db,
		"INSERT INTO description (no_user_account, name, type, last_write_client_UTC) VALUES (:no_user_account, :name, :type, :last_write_client_UTC)",
		["no_user_account" => $no_user_account, "name" => $name, "type" => $type, "last_write_client_UTC" => $last_write_client_UTC]
	);
}

function db_update_description_name_type($db, $no_user_account, $no_description, $name, $type, $last_write_client_UTC) {
	return db_exec($db,
		"UPDATE description SET name = :name, type = :type, last_write_client_UTC = :last_write_client_UTC
		WHERE no_description = :no_description AND no_user_account = :no_user_account",
		["name" => $name, "type" => $type, "last_write_client_UTC" => $last_write_client_UTC, "no_description" => $no_description, "no_user_account" => $no_user_account]
	);
}

function db_delete_descriptions($db, $no_description, $no_user_account) {
	return db_exec($db, "DELETE FROM description WHERE no_description = :no_description AND no_user_account = :no_user_account",
		["no_description" => $no_description, "no_user_account" => $no_user_account]);
}

function db_select_all_description_for_day_timeline($db, $no_user_account, $no_day) {
	return db_rows($db,
		"SELECT ld.no_description, d.no_user_account, d.name, d.type
		FROM link_day_timeline_description AS ld LEFT JOIN description AS d ON ld.no_description = d.no_description
		WHERE ld.no_day = :no_day AND d.no_user_account = :no_user_account",
		["no_day" => $no_day, "no_user_account" => $no_user_account]
	);
}

// Every label linked to any day of a date range, in one query: the per-day query above is an N+1
// when a whole period is read at once (the exports walk a cycle day by day), so they use this
// and group the rows by no_day in PHP.
function db_select_descriptions_for_day_timeline_frame($db, $start_date, $end_date, $no_user_account) {
	return db_rows($db,
		"SELECT ld.no_day, d.no_description, d.name, d.type
		FROM day_timeline AS dt
		JOIN link_day_timeline_description AS ld ON ld.no_day = dt.no_day
		JOIN description AS d ON d.no_description = ld.no_description
		WHERE dt.no_user_account = :no_user_account AND dt.date_obs >= :start_date AND dt.date_obs <= :end_date AND d.no_user_account = :no_user_account
		ORDER BY ld.no_day ASC, d.type ASC, d.no_description ASC",
		["no_user_account" => $no_user_account, "start_date" => $start_date, "end_date" => $end_date]
	);
}

function db_insert_link_description_day_timeline($db, $no_day, $no_description) {
	return db_exec($db, "INSERT INTO link_day_timeline_description (no_day, no_description) VALUES (:no_day, :no_description)",
		["no_day" => $no_day, "no_description" => $no_description]);
}

function db_delete_linked_descriptions($db, $no_day, $no_description) {
	return db_exec($db, "DELETE FROM link_day_timeline_description WHERE no_description = :no_description AND no_day = :no_day",
		["no_description" => $no_description, "no_day" => $no_day]);
}

// Stamps the days a label is on as written now. A day shows its labels by name and type, so renaming,
// retyping or deleting the label changes those days for the sync, though their rows do not change.
function db_update_day_timeline_touch_description($db, $no_user_account, $no_description) {
	return db_exec($db,
		"UPDATE day_timeline AS dt JOIN link_day_timeline_description AS ld ON ld.no_day = dt.no_day
		SET dt.last_write_db = CURRENT_TIMESTAMP
		WHERE ld.no_description = :no_description AND dt.no_user_account = :no_user_account",
		["no_description" => $no_description, "no_user_account" => $no_user_account]
	);
}

// ---------------------------------------------------------------------------
// Days. date_obs is a DATE and unique per account (unique_user_account_and_date): equality on the
// account then a range on the date is a range scan of that index, which also gives the ORDER BY.
// ---------------------------------------------------------------------------

function db_select_all_day_timeline($db, $no_user_account) {
	return db_rows($db, "SELECT * FROM day_timeline WHERE no_user_account = :no_user_account ORDER BY date_obs ASC", ["no_user_account" => $no_user_account]);
}

function db_select_day_timeline($db, $date, $no_user_account): ?array {
	return db_row($db, "SELECT * FROM day_timeline WHERE date_obs = :date AND no_user_account = :no_user_account LIMIT 1", ["date" => $date, "no_user_account" => $no_user_account]);
}

// The database's own clock, the one that stamps last_write_db: the sync cursor is read from it, so a
// client's clock never decides what it receives.
function db_select_now($db): string {
	return db_value($db, "SELECT NOW()");
}

// The dates of the days written since a moment, less $overlap_seconds (last_write_db, the database's
// clock). A day never stamped counts as written, so a first sync cannot miss it.
function db_select_day_timeline_dates_written_since($db, $since, $overlap_seconds, $no_user_account) {
	return db_column($db,
		"SELECT date_obs FROM day_timeline
		WHERE no_user_account = :no_user_account AND (last_write_db IS NULL OR last_write_db >= :since - INTERVAL :overlap_seconds SECOND)
		ORDER BY date_obs ASC",
		["no_user_account" => $no_user_account, "since" => $since, "overlap_seconds" => $overlap_seconds]
	);
}

// The days from a date up to, not including, another (null: no end), with the columns the JSON day
// shows -- not the deprecated sensation column.
function db_select_day_timelines_range($db, $start_date, $end_date, $no_user_account) {
	return db_rows($db,
		"SELECT no_day, date_obs, day_not_observed, fc_score, fc_arrow, stamp, temperature, time_temp_taken, is_peak, counter_start,
		union_sex, cycle_1st_day, pregnancy, comment, last_write_client_UTC, last_write_db
		FROM day_timeline
		WHERE no_user_account = :no_user_account AND date_obs >= :start_date AND (:end_date IS NULL OR date_obs < :end_date)
		ORDER BY date_obs ASC",
		["no_user_account" => $no_user_account, "start_date" => $start_date, "end_date" => $end_date]
	);
}

function db_select_day_timelines_frame($db, $start_date, $end_date, $no_user_account) {
	return db_rows($db,
		"SELECT * FROM day_timeline WHERE date_obs >= :start_date AND date_obs <= :end_date AND no_user_account = :no_user_account ORDER BY date_obs ASC",
		["start_date" => $start_date, "end_date" => $end_date, "no_user_account" => $no_user_account]
	);
}

// The days of a range with only the columns the CSV and PDF exports read: not the one above, which
// is SELECT *, as these exports must never see the deprecated day_timeline.sensation column
// (sensations live in `description`, type 2).
function db_select_day_timelines_export($db, $start_date, $end_date, $no_user_account) {
	return db_rows($db,
		"SELECT dt.no_day, dt.date_obs, dt.day_not_observed, dt.fc_score, dt.fc_arrow, dt.stamp, dt.temperature, dt.time_temp_taken,
		dt.is_peak, dt.counter_start, dt.union_sex, dt.cycle_1st_day, dt.pregnancy, dt.comment
		FROM day_timeline AS dt
		WHERE dt.no_user_account = :no_user_account AND dt.date_obs >= :start_date AND dt.date_obs <= :end_date ORDER BY dt.date_obs ASC",
		["no_user_account" => $no_user_account, "start_date" => $start_date, "end_date" => $end_date]
	);
}

// The dates of a range the account already has a day on, read from the index alone: one query
// for a file covering years, as the import dry run's collision check.
function db_select_day_timeline_dates_frame($db, $start_date, $end_date, $no_user_account) {
	return db_column($db,
		"SELECT date_obs FROM day_timeline WHERE no_user_account = :no_user_account AND date_obs >= :start_date AND date_obs <= :end_date ORDER BY date_obs ASC",
		["no_user_account" => $no_user_account, "start_date" => $start_date, "end_date" => $end_date]
	);
}

function db_insert_day_timeline($db, $date, $no_user_account) {
	return db_insert($db, "INSERT INTO day_timeline (no_user_account, date_obs, stamp) VALUES (:no_user_account, :date, '')", ["no_user_account" => $no_user_account, "date" => $date]);
}

// Writes what a day holds. $fields (see day_format_from_json()): stamp, fc_score, fc_arrow, temp, htemp, is_peak,
// union_sex, cycle_1st_day, day_not_observed, pregnancy, comment, counter_start; one left out is written empty.
// last_write_db is set here rather than left to ON UPDATE: a write that changes no column of the row
// (only the labels it carries) must still show up in the sync.
function db_update_day_timeline($db, $date, $no_user_account, $last_write_client_UTC, array $fields = []) {
	$fields += [
		"stamp" => '', "fc_score" => null, "fc_arrow" => null, "temp" => null, "htemp" => null, "is_peak" => null, "union_sex" => null,
		"cycle_1st_day" => null, "day_not_observed" => null, "pregnancy" => null, "comment" => null, "counter_start" => null,
	];
	return db_exec($db,
		"UPDATE day_timeline SET stamp = :stamp, fc_score = :fc_score, fc_arrow = :fc_arrow, temperature = :temp, time_temp_taken = :htemp,
		is_peak = :is_peak, union_sex = :union_sex, cycle_1st_day = :cycle_1st_day, day_not_observed = :day_not_observed,
		pregnancy = :pregnancy, comment = :comment, counter_start = :counter_start, last_write_client_UTC = :last_write_client_UTC,
		last_write_db = CURRENT_TIMESTAMP
		WHERE date_obs = :date AND no_user_account = :no_user_account",
		$fields + ["last_write_client_UTC" => $last_write_client_UTC, "date" => $date, "no_user_account" => $no_user_account]
	);
}

// ---------------------------------------------------------------------------
// Cycles: a cycle runs from a day marked cycle_1st_day to the day before the next one
// ---------------------------------------------------------------------------

// the first days of the cycles of the account, latest first
function db_select_cycles($db, $no_user_account) {
	return db_column($db, "SELECT date_obs FROM day_timeline WHERE no_user_account = :no_user_account AND cycle_1st_day = 1 ORDER BY date_obs DESC", ["no_user_account" => $no_user_account]);
}

function db_select_pregnancies($db, $no_user_account) {
	return db_column($db, "SELECT date_obs FROM day_timeline WHERE no_user_account = :no_user_account AND pregnancy = 1 ORDER BY date_obs DESC", ["no_user_account" => $no_user_account]);
}

// the first day of the cycle a date is in, or null before the first cycle
function db_select_cycle($db, $date, $no_user_account): ?string {
	return db_value($db, "SELECT date_obs FROM day_timeline WHERE cycle_1st_day = 1 AND date_obs <= :date AND no_user_account = :no_user_account ORDER BY date_obs DESC LIMIT 1",
		["date" => $date, "no_user_account" => $no_user_account]) ?: null;
}

// ---------------------------------------------------------------------------
// What the cron works on
// ---------------------------------------------------------------------------

// the accounts whose cycle began two days ago, so that the cycle before it is finished, and that
// have not turned the automatic mail of a cycle off (auto_mail_export)
function db_select_cycles_finished($db) {
	return db_rows($db,
		"SELECT SUBDATE(obs.date_obs, 1) AS cycle_complet, obs.no_user_account, c.name, c.nfp_method, c.email1, c.email2
		FROM day_timeline AS obs JOIN user_account AS c ON obs.no_user_account = c.no_user_account
		WHERE obs.date_obs = CURDATE() - INTERVAL 2 DAY AND (obs.cycle_1st_day = 1 OR obs.pregnancy = 1) AND c.auto_mail_export = 1"
	);
}

// the accounts with no day written for 35 days, registered for more than that, not yet reminded
function db_select_user_account_inactive($db) {
	return db_rows($db,
		"SELECT c.no_user_account, c.name, MAX(o.last_write_db) AS last_day_written, c.email1, c.email2, c.inscription_date
		FROM user_account AS c LEFT JOIN day_timeline AS o ON c.no_user_account = o.no_user_account
		WHERE c.no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ") AND c.is_inactive = 0
		GROUP BY c.no_user_account, c.name, c.email1, c.email2, c.inscription_date
		HAVING (DATE(last_day_written) < DATE(NOW()) - INTERVAL 35 DAY OR last_day_written IS NULL) AND c.inscription_date < DATE(NOW()) - INTERVAL 35 DAY
		ORDER BY last_day_written DESC LIMIT 20"
	);
}

// last sign of life for an account: the latest of its registration date, its last login, any
// day_timeline/description write, or any authenticated request (auth_token.date_use). Each of
// those three tables is aggregated to one MAX(...) row per no_user_account in its own derived
// table (dt/de/at below) and LEFT JOINed once, instead of a correlated subquery re-scanning the
// table for every user_account row. The demo accounts are left out, as they are from the stats.
const DB_SQL_USER_ACCOUNT_LAST_ACTIVITY =
	"SELECT u.no_user_account, u.name, u.email1, u.email2,
	GREATEST(u.inscription_date, COALESCE(u.last_auth_date, u.inscription_date), COALESCE(dt.last_activity, u.inscription_date),
		COALESCE(de.last_activity, u.inscription_date), COALESCE(at.last_activity, u.inscription_date)) AS last_activity
	FROM user_account u
	LEFT JOIN (SELECT no_user_account, MAX(last_write_db) AS last_activity FROM day_timeline GROUP BY no_user_account) dt ON dt.no_user_account = u.no_user_account
	LEFT JOIN (SELECT no_user_account, MAX(last_write_db) AS last_activity FROM description GROUP BY no_user_account) de ON de.no_user_account = u.no_user_account
	LEFT JOIN (SELECT no_user_account, MAX(date_use) AS last_activity FROM auth_token GROUP BY no_user_account) at ON at.no_user_account = u.no_user_account
	WHERE u.no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")";

// the accounts that will be erased in $warning_days_before days: the ones to warn today
function db_select_user_account_to_warn_before_deletion($db, $years, $warning_days_before) {
	return db_rows($db,
		"SELECT * FROM (" . DB_SQL_USER_ACCOUNT_LAST_ACTIVITY . ") AS activity
		WHERE last_activity < NOW() - INTERVAL :years1 YEAR + INTERVAL :warning_days1 DAY
		AND last_activity >= NOW() - INTERVAL :years2 YEAR + INTERVAL :warning_days2 DAY - INTERVAL 1 DAY",
		["years1" => $years, "years2" => $years, "warning_days1" => $warning_days_before, "warning_days2" => $warning_days_before]
	);
}

function db_select_user_account_to_delete($db, $years) {
	return db_rows($db, "SELECT * FROM (" . DB_SQL_USER_ACCOUNT_LAST_ACTIVITY . ") AS activity WHERE last_activity < NOW() - INTERVAL :years YEAR", ["years" => $years]);
}

// ---------------------------------------------------------------------------
// Statistics (the demo accounts are left out of them), and the visit counters
// ---------------------------------------------------------------------------

function db_count_user_accounts($db) {
	return db_value($db, "SELECT COUNT(no_user_account) FROM user_account");
}

// accounts with a day in the last 35 days
function db_count_user_accounts_active($db) {
	return db_value($db, "SELECT COUNT(DISTINCT no_user_account) FROM day_timeline WHERE date_obs >= DATE(NOW()) - INTERVAL 35 DAY");
}

function db_count_user_accounts_active_by_method($db, $nfp_method) {
	return db_value($db,
		"SELECT COUNT(DISTINCT obs.no_user_account) FROM day_timeline AS obs JOIN user_account AS com ON obs.no_user_account = com.no_user_account
		WHERE obs.date_obs >= DATE(NOW()) - INTERVAL 35 DAY AND com.nfp_method = :nfp_method",
		["nfp_method" => $nfp_method]
	);
}

// accounts registered in the last 15 days that have logged in
function db_count_user_accounts_recent($db) {
	return db_value($db, "SELECT COUNT(no_user_account) FROM user_account WHERE inscription_date >= DATE(NOW()) - INTERVAL 15 DAY AND last_auth_date IS NOT NULL");
}

function db_count_user_accounts_with_totp($db) {
	return db_value($db, "SELECT COUNT(no_user_account) FROM user_account WHERE totp_state = " . TOTP_STATE_ACTIVE . " AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")");
}

function db_count_auth_tokens($db) {
	return db_value($db, "SELECT COUNT(no_auth_token) FROM auth_token");
}

function db_count_cycles($db) {
	return db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE cycle_1st_day = 1 AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")");
}

function db_count_cycles_recent($db) {
	return db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE cycle_1st_day = 1 AND date_obs >= DATE(NOW()) - INTERVAL 30 DAY AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")");
}

// the average age: the DB holds a birth year, and 2.5 is half the 5 years the form groups
function db_select_average_age($db) {
	return db_value($db, "SELECT YEAR(NOW()) - AVG(age) + 2.5 FROM user_account");
}

function db_select_average_age_recent($db) {
	return db_value($db, "SELECT YEAR(NOW()) - AVG(age) + 2.5 FROM user_account WHERE inscription_date >= DATE(NOW()) - INTERVAL 15 DAY AND last_auth_date IS NOT NULL AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")");
}

function db_count_days($db) {
	return db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")");
}

// How many days each account holds, the biggest first (script/db_perf.php): list of rows no_user_account, nb_days.
function db_select_day_counts_by_account($db): array {
	return db_rows($db, "SELECT dt.no_user_account, COUNT(dt.no_day) AS nb_days FROM day_timeline AS dt GROUP BY dt.no_user_account ORDER BY nb_days DESC, dt.no_user_account");
}

function db_count_days_today($db) {
	return db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE date_obs = CURDATE() AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")");
}

function db_count_days_since($db, $nb_days) {
	return db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE date_obs >= DATE(NOW()) - INTERVAL :nb_days DAY AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")", ["nb_days" => $nb_days]);
}

function db_select_key_value($db, $key) {
	return db_value($db, "SELECT `value` FROM key_value WHERE `key` = :key", ["key" => $key]);
}

function db_insert_key_value($db, $key, $value) {
	return db_exec($db, "INSERT INTO key_value (`key`, `value`) VALUES (:key, :value)", ["key" => $key, "value" => $value]);
}

function db_update_key_value($db, $key, $value) {
	return db_exec($db, "UPDATE key_value SET `value` = :value WHERE `key` = :key", ["key" => $key, "value" => $value]);
}

function db_update_increment_key_value($db, $key) {
	return db_exec($db, "UPDATE key_value SET `value` = `value` + 1 WHERE `key` = :key", ["key" => $key]);
}

function db_update_reset_key_value($db, $key) {
	return db_exec($db, "UPDATE key_value SET `value` = 0 WHERE `key` = :key", ["key" => $key]);
}
