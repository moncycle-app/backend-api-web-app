<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// RGPD data retention: an account gets erased after this many years with no sign of
// activity (login, or any write tied to it); ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE sets how
// long before that deletion the warning email goes out. Both are re-evaluated fresh on every
// cron run, so there's no "warning already sent" flag to maintain: any real activity bumps
// last_activity in db_select_user_account_to_warn_before_deletion() / _to_delete() and drops
// the account out of both queries.
define("ACCOUNT_INACTIVITY_DELETE_YEARS", 4);
define("ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE", 10);

function db_open() {
	$db = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_ID, DB_PASSWORD);

	$sql = "SET NAMES utf8mb4;";
	$statement = $db->prepare($sql);
	$statement->execute();

	return $db;
}

function db_select_cycles($db, $no_user_account) {
	static $sql = "SELECT date_obs AS cycles FROM day_timeline WHERE no_user_account = :no_user_account AND cycle_1st_day=1 ORDER BY cycles DESC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function db_select_pregnancys($db, $no_user_account) {
	static $sql = "SELECT date_obs AS cycles FROM day_timeline WHERE no_user_account = :no_user_account AND pregnancy=1 ORDER BY cycles DESC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function db_select_description_no_exist($db, $desc_no, $no_user_account) {
	static $sql = "SELECT count(no_description)>0 AS description_existe FROM description WHERE no_description = :desc_no AND no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":desc_no", $desc_no, PDO::PARAM_INT);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchColumn();
}

function db_select_description_name_exist($db, $desc_name, $no_user_account, $desc_no) {
	static $sql = "SELECT count(no_description)>0 AS description_existe FROM description WHERE name LIKE :desc_name AND no_user_account = :no_user_account AND no_description != :desc_no";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":desc_name", $desc_name, PDO::PARAM_STR);
	$statement->bindValue(":desc_no", $desc_no, PDO::PARAM_INT);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchColumn();
}

function db_select_description_with_count($db, $no_user_account) {
	static $sql = "SELECT d.no_description, d.name, COUNT(od.no_day) AS use_count, d.no_user_account, d.name, d.type, d.last_write_client_UTC, d.last_write_db FROM description AS d LEFT JOIN link_day_timeline_description AS od ON od.no_description = d.no_description WHERE d.no_user_account = :no_user_account GROUP BY d.no_description ORDER BY use_count DESC, d.name DESC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_description_with_count_modified ($db, $modified_since, $no_user_account) {
	static $sql = "SELECT d.no_description, d.name, COUNT(od.no_day) AS use_count, d.no_user_account, d.name, d.type, d.last_write_client_UTC, d.last_write_db FROM description AS d LEFT JOIN link_day_timeline_description AS od ON od.no_description = d.no_description WHERE d.no_user_account = :no_user_account AND d.last_write_client_UTC >= :modified_since GROUP BY d.no_description ORDER BY use_count DESC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":modified_since", $modified_since, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_all_description_for_day_timeline($db, $no_user_account, $no_day) {
	static $sql = "SELECT ld.no_description, ld.no_description, d.no_user_account, d.name, d.type FROM link_day_timeline_description AS ld LEFT JOIN description AS d ON ld.no_description = d.no_description WHERE ld.no_day = :no_day AND d.no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_day", $no_day, PDO::PARAM_INT);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

// Every description linked to any day in a date range, in one query. The per-day
// db_select_all_description_for_day_timeline() above is an N+1 when a whole period is read
// at once (the NFP export walks a cycle day by day), so the file pipeline uses this and
// groups the rows by no_day in PHP.
function db_select_descriptions_for_day_timeline_frame ($db, $start_date, $end_date, $no_user_account) {
	static $sql = "SELECT ld.no_day, d.no_description, d.name, d.type FROM day_timeline AS dt JOIN link_day_timeline_description AS ld ON ld.no_day = dt.no_day JOIN description AS d ON d.no_description = ld.no_description WHERE dt.no_user_account = :no_user_account AND dt.date_obs >= :start_date AND dt.date_obs <= :end_date AND d.no_user_account = :no_user_account ORDER BY ld.no_day ASC, d.type ASC, d.no_description ASC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":start_date", $start_date, PDO::PARAM_STR);
	$statement->bindValue(":end_date", $end_date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_delete_linked_descriptions ($db, $no_day, $no_description) {
	static $sql = "DELETE FROM link_day_timeline_description WHERE no_description = :no_description AND no_day = :no_day";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_description", $no_description, PDO::PARAM_INT);
	$statement->bindValue(":no_day", $no_day, PDO::PARAM_INT);
	$statement->execute();

	return $statement->rowCount();
}

function db_delete_descriptions ($db, $no_description, $no_user_account) {
	static $sql = "DELETE FROM description WHERE no_description = :no_description AND no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_description", $no_description, PDO::PARAM_INT);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->rowCount();
}

function db_select_description_from_name($db, $no_user_account, $name) {
	static $sql = "SELECT * FROM description WHERE name LIKE :name AND no_user_account= :no_user_account LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":name", $name, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

// Exact-match sibling of db_select_description_from_name() above, which matches with
// "name LIKE :name": unescaped "%" and "_" in the bound value act as wildcards there, and its
// LIMIT 1 has no ORDER BY, so a name carrying either character can resolve to an arbitrary
// different description. That is harmless for the account UI (names come from a picklist) but
// not for the NFP import, where the names come out of a file. utf8mb4_bin makes "=" exact and
// case-sensitive, and it matches the unique key, so this can never collide on insert.
function db_select_description_exact_name($db, $no_user_account, $name) {
	static $sql = "SELECT d.no_description, d.name, d.type FROM description AS d WHERE d.no_user_account = :no_user_account AND d.name = :name LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":name", $name, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

// Every label of the account, as name + type and nothing more. The read-only sibling of
// db_select_description_exact_name() above, for when the question is asked about many names
// at once: the NFP import's dry run has to say which of a file's labels are new without
// creating any, and an account holds few enough descriptions that one query beats one
// exact-name lookup per distinct name in the file.
function db_select_description_name_type($db, $no_user_account) {
	static $sql = "SELECT d.name, d.type FROM description AS d WHERE d.no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_insert_description($db, $no_user_account, $name, $desc_type, $last_write_client_UTC) {
	static $sql = "INSERT INTO `description` (`no_user_account`, `name`, `type`, `last_write_client_UTC`) VALUES (:no_user_account, :name, :desc_type, :last_write_client_UTC)";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":desc_type", $desc_type, PDO::PARAM_INT);
	$statement->bindValue(":name", $name, PDO::PARAM_STR);
	$statement->bindValue(":last_write_client_UTC", $last_write_client_UTC, PDO::PARAM_STR);
	$statement->execute();

	return $db->lastInsertId();
}

function db_update_description_name_type ($db, $no_user_account, $no_description, $name, $type, $last_write_client_UTC) {
	static $sql ="UPDATE description SET name = :name, type = :type, last_write_client_UTC = :last_write_client_UTC WHERE no_description = :no_description AND no_user_account = :no_user_account";

	$statement = $db->prepare($sql);
	$statement->bindValue(":no_description", $no_description, PDO::PARAM_INT);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":name", $name, PDO::PARAM_STR);
	$statement->bindValue(":type", $type, PDO::PARAM_INT);
	$statement->bindValue(":last_write_client_UTC", $last_write_client_UTC, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_insert_link_description_day_timeline($db, $day_timeline_no, $description_no) {
	static $sql = "INSERT INTO `link_day_timeline_description` (`no_day`, `no_description`) VALUES (:day_timeline_no, :description_no)";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":day_timeline_no", $day_timeline_no, PDO::PARAM_INT);
	$statement->bindValue(":description_no", $description_no, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_user_account_par_nouser_account($db, $no_user_account) {
	static $sql = "select * from user_account where no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_user_account_par_mail($db, $mail) {
	static $sql = "select * from user_account where email1 like :email1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":email1", $mail, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_user_account_connecte($db, $no_user_account){
	static $sql ="update user_account set last_auth_date = now(), nb_connection_attempts = 0, is_inactive = 0 where no_user_account = :no_user_account";

	$statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

// increments the failed-attempt counter, but restarts it at 1 instead of compounding when the
// last failure is old (60 MINUTE, must match LOGIN_ATTEMPTS_DECAY_MINUTES in lib/sec.php)
function db_update_co_echoue($db, $mail){
	static $sql ="update user_account set nb_connection_attempts = IF(last_failed_attempt IS NULL OR last_failed_attempt < NOW() - INTERVAL 60 MINUTE, 1, nb_connection_attempts + 1), last_failed_attempt = now() where email1 like :email1";

	$statement = $db->prepare($sql);
	$statement->bindValue(":email1", $mail, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_insert_login_attempt_ip($db, $ip_address){
	static $sql = "insert into login_attempt_ip (ip_address) values (:ip_address)";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":ip_address", $ip_address, PDO::PARAM_STR);
	$statement->execute();

	return $statement->rowCount();
}

// number of failed login attempts recorded from this IP in the last 15 minutes, across all accounts
function db_count_login_attempt_ip($db, $ip_address){
	static $sql = "select count(*) from login_attempt_ip where ip_address = :ip_address and date_attempt > NOW() - INTERVAL 15 MINUTE";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":ip_address", $ip_address, PDO::PARAM_STR);
	$statement->execute();

	return intval($statement->fetchColumn());
}

function db_delete_vieux_login_attempt_ip($db) {
	static $sql = "DELETE FROM login_attempt_ip WHERE date_attempt < NOW() - INTERVAL 1 DAY";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->rowCount();
}

function db_select_user_account_existe($db, $mail) {
	static $sql = "select count(no_user_account)>0 as user_account_existe from user_account where email1 like :email1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":email1", $mail, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_insert_user_account($db, $name, $nfp_method, $age, $mail, $mdp, $register_comment, $research) {
	static $sql = "INSERT INTO user_account (name, nfp_method, age, email1, password, register_comment, research) VALUES (:name, :nfp_method, :age, :email1, :password, :register_comment, :research)";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":name", $name, PDO::PARAM_STR);
	$statement->bindValue(":nfp_method", $nfp_method, PDO::PARAM_INT);
	$statement->bindValue(":age", $age, PDO::PARAM_INT);
	$statement->bindValue(":email1", $mail, PDO::PARAM_STR);
	$statement->bindValue(":password", $mdp, PDO::PARAM_STR);
	$statement->bindValue(":register_comment", $register_comment, PDO::PARAM_STR);
	$statement->bindValue(":research", $research, PDO::PARAM_INT);
	$statement->execute();

	return $db->lastInsertId();
}

function db_update_user_account_param($db, $name, $email2, $nfp_method, $age, $sponsor, $timeline_asc, $research, $last_write_client_UTC, $no_user_account) {
	static $sql = "UPDATE user_account SET `name` = :name, email2 = :email2, nfp_method = :nfp_method, age = :age, sponsor = :sponsor, timeline_asc = :timeline_asc, research = :research, last_write_client_UTC = :last_write_client_UTC WHERE no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":name", $name, PDO::PARAM_STR);
	$statement->bindValue(":email2", $email2, PDO::PARAM_STR);
	$statement->bindValue(":last_write_client_UTC", $last_write_client_UTC, PDO::PARAM_STR);
	$statement->bindValue(":nfp_method", $nfp_method, PDO::PARAM_INT);
	$statement->bindValue(":age", $age, PDO::PARAM_INT);
	$statement->bindValue(":sponsor", $sponsor, PDO::PARAM_INT);
	$statement->bindValue(":timeline_asc", $timeline_asc, PDO::PARAM_INT);
	$statement->bindValue(":research", $research, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_password_par_mail ($db, $mdp, $mail) {
	static $sql = "UPDATE user_account SET password = :password, last_password_change = NULL WHERE email1 = :email1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":email1", $mail, PDO::PARAM_STR);
	$statement->bindValue(":password", $mdp, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_udpate_password_par_nouser_account($db, $mdp, $no_user_account) {
	static $sql = "UPDATE user_account SET password = :password, last_password_change = now() WHERE no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":password", $mdp, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_delete_user_account($db, $no_user_account){
	static $sql = "DELETE FROM user_account WHERE no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->rowCount();
}

// last sign of life for an account: the latest of its registration date, its last login, any
// day_timeline/description write, or any authenticated request (auth_token.date_use). Each of
// those three tables is aggregated to one MAX(...) row per no_user_account in its own derived
// table (dt/de/at below) and LEFT JOINed once, instead of a correlated subquery re-scanning the
// table for every user_account row. no_user_account 2 is the demo account, excluded here as it
// is from the stats/relance queries above.
const DB_SQL_USER_ACCOUNT_LAST_ACTIVITY = "SELECT u.no_user_account, u.name, u.email1, u.email2, GREATEST(u.inscription_date, COALESCE(u.last_auth_date, u.inscription_date), COALESCE(dt.last_activity, u.inscription_date), COALESCE(de.last_activity, u.inscription_date), COALESCE(at.last_activity, u.inscription_date)) AS last_activity FROM user_account u LEFT JOIN (SELECT no_user_account, MAX(last_write_db) AS last_activity FROM day_timeline GROUP BY no_user_account) dt ON dt.no_user_account = u.no_user_account LEFT JOIN (SELECT no_user_account, MAX(last_write_db) AS last_activity FROM description GROUP BY no_user_account) de ON de.no_user_account = u.no_user_account LEFT JOIN (SELECT no_user_account, MAX(date_use) AS last_activity FROM auth_token GROUP BY no_user_account) at ON at.no_user_account = u.no_user_account WHERE u.no_user_account != 2";

function db_select_user_account_to_warn_before_deletion($db, $years, $warning_days_before) {
	static $sql = "SELECT * FROM (" . DB_SQL_USER_ACCOUNT_LAST_ACTIVITY . ") AS activity WHERE last_activity < NOW() - INTERVAL :years1 YEAR + INTERVAL :warning_days1 DAY AND last_activity >= NOW() - INTERVAL :years2 YEAR + INTERVAL :warning_days2 DAY - INTERVAL 1 DAY";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":years1", $years, PDO::PARAM_INT);
	$statement->bindValue(":years2", $years, PDO::PARAM_INT);
	$statement->bindValue(":warning_days1", $warning_days_before, PDO::PARAM_INT);
	$statement->bindValue(":warning_days2", $warning_days_before, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_user_account_to_delete($db, $years) {
	static $sql = "SELECT * FROM (" . DB_SQL_USER_ACCOUNT_LAST_ACTIVITY . ") AS activity WHERE last_activity < NOW() - INTERVAL :years YEAR";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":years", $years, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_delete_auth_token($db, $no_auth_token, $no_user_account){
	static $sql = "DELETE FROM `auth_token` WHERE `no_auth_token` = :no_auth_token AND `no_user_account` = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_auth_token", $no_auth_token, PDO::PARAM_INT);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->rowCount();
}

function db_delete_vieux_auth_token($db) {
	static $sql = "DELETE FROM auth_token WHERE (date_creation < (CURDATE() + INTERVAL - 365 DAY) OR date_use < (CURDATE() + INTERVAL - 40 DAY)) AND expire>0";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->rowCount();
}

function db_select_all_day_timeline($db, $no_user_account) {
	static $sql = "SELECT * FROM day_timeline WHERE no_user_account = :no_user_account ORDER BY date_obs ASC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_day_timeline ($db, $date, $no_user_account) {
	static $sql = "SELECT * FROM day_timeline WHERE date_obs = :date AND no_user_account = :no_user_account LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":date", $date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_day_timelines_modified ($db, $modified_since, $no_user_account) {
	static $sql = "SELECT * FROM day_timeline WHERE last_write_client_UTC >= :modified_since AND no_user_account = :no_user_account ORDER BY last_write_client_UTC DESC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":modified_since", $modified_since, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_day_timelines_frame ($db, $start_date, $end_date, $no_user_account) {
	static $sql = "SELECT * FROM day_timeline WHERE date_obs >= :start_date AND date_obs <= :end_date AND no_user_account = :no_user_account ORDER BY date_obs ASC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":start_date", $start_date, PDO::PARAM_STR);
	$statement->bindValue(":end_date", $end_date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

// The dates in the range this account already has a day on, and nothing else.
//
// date_obs is a DATE column, so the inclusive bounds are exact. Equality on no_user_account
// followed by a range on date_obs is exactly unique_user_account_and_date, and both columns
// live in that index, so this reads the whole span from the index alone -- which is what makes
// it usable as the import dry run's collision check: one query for a file covering years,
// instead of one db_select_day_timeline() per day.
function db_select_day_timeline_dates_frame ($db, $start_date, $end_date, $no_user_account) {
	static $sql = "SELECT date_obs FROM day_timeline WHERE no_user_account = :no_user_account AND date_obs >= :start_date AND date_obs <= :end_date ORDER BY date_obs ASC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":start_date", $start_date, PDO::PARAM_STR);
	$statement->bindValue(":end_date", $end_date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function db_insert_day_timeline ($db, $date, $no_user_account) {
	static $sql = "INSERT INTO day_timeline (no_user_account, date_obs, stamp) VALUES (:no_user_account, :date, '')";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":date", $date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $db->lastInsertId();
}

function db_update_day_timeline ($db, $date, $no_user_account, $last_write_client_UTC, $stamp='', $fc_score=null, $fc_arrow=null, $temp=null, $htemp=null, $is_peak=null, $union_sex=null, $cycle_1st_day=null, $day_not_observed=null, $pregnancy=null, $comment=null, $counter_start=null) {
	static $sql = "UPDATE day_timeline SET stamp = :stamp, fc_score = :fc_score, fc_arrow = :fc_arrow, temperature = :temp, time_temp_taken = :htemp, is_peak = :is_peak, union_sex = :union_sex, cycle_1st_day = :cycle_1st_day, day_not_observed = :day_not_observed, pregnancy = :pregnancy, comment = :comment, counter_start = :counter_start, last_write_client_UTC = :last_write_client_UTC WHERE date_obs = :date AND no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":stamp", $stamp, PDO::PARAM_STR);
	$statement->bindValue(":fc_score", $fc_score, PDO::PARAM_STR);
	$statement->bindValue(":fc_arrow", $fc_arrow, PDO::PARAM_STR);
	$statement->bindValue(":temp", $temp, PDO::PARAM_STR);
	$statement->bindValue(":htemp", $htemp, PDO::PARAM_STR);
	$statement->bindValue(":is_peak", $is_peak, PDO::PARAM_INT);
	$statement->bindValue(":union_sex", $union_sex, PDO::PARAM_INT);
	$statement->bindValue(":cycle_1st_day", $cycle_1st_day, PDO::PARAM_INT);
	$statement->bindValue(":comment", $comment, PDO::PARAM_STR);
	$statement->bindValue(":day_not_observed", $day_not_observed, PDO::PARAM_INT);
	$statement->bindValue(":pregnancy", $pregnancy, PDO::PARAM_INT);
	$statement->bindValue(":date", $date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":counter_start", $counter_start, PDO::PARAM_INT);
	$statement->bindValue(":last_write_client_UTC", $last_write_client_UTC, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_cycle($db, $date, $no_user_account) {
	static $sql = "SELECT date_obs AS cycle FROM day_timeline WHERE cycle_1st_day=1 AND date_obs<=:date AND no_user_account = :no_user_account ORDER BY date_obs DESC LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":date", $date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_cycle_end($db, $date, $no_user_account) {
	static $sql = "SELECT date_obs AS cycle_end FROM day_timeline WHERE cycle_1st_day=1 and date_obs>:date AND no_user_account = :no_user_account ORDER BY date_obs ASC LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":date", $date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_cycle_pregnancy($db, $date, $no_user_account) {
	static $sql = "SELECT date_obs AS pregnancy FROM day_timeline WHERE pregnancy=1 and date_obs>:date AND no_user_account = :no_user_account ORDER BY date_obs ASC LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":date", $date, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_cycle_complet($db, $date_start, $date_end, $no_user_account) {
	static $sql = "SELECT date_obs, COALESCE(day_not_observed,'') as '?', COALESCE(fc_score,'') as fc_score, COALESCE(fc_arrow,'') as fc_arrow, stamp, COALESCE(temperature,'') as temperature, COALESCE(time_temp_taken,'') as time_temp_taken, COALESCE(is_peak, '') as sommet, COALESCE(counter_start, '') as counter_start, COALESCE(union_sex, '') as 'unions', COALESCE(pregnancy, '') as 'pregnancy', comment, COALESCE(cycle_1st_day, 0) as 'cycle_1st_day' FROM day_timeline WHERE date_obs>=:date_start AND date_obs<=:date_end AND no_user_account = :no_user_account ORDER BY date_obs ASC";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":date_start", $date_start, PDO::PARAM_STR);
	$statement->bindValue(":date_end", $date_end, PDO::PARAM_STR);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_nb_user_account($db) {
	static $sql = "select count(no_user_account) as MONCYCLE_APP_NB_COMPTE from user_account";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_nb_user_account_actif($db) {
	static $sql = "select count(distinct no_user_account) as MONCYCLE_APP_NB_COMPTE_ACTIF from day_timeline where date_obs >= DATE(NOW()) - INTERVAL 35 DAY";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_nb_user_account_actif_par_nfp_method($db, $nfp_method) {
	static $sql = "select count(distinct obs.no_user_account) as MONCYCLE_APP_NB_COMPTE_ACTIF_METHODE from day_timeline as obs left join user_account as com on obs.no_user_account = com.no_user_account where date_obs >= DATE(NOW()) - INTERVAL 35 DAY and com.nfp_method = :nfp_method";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":nfp_method", $nfp_method, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_nb_user_account_recent($db) {
	static $sql = "select count(no_user_account) as MONCYCLE_APP_NB_COMPTE_RECENT from user_account where inscription_date >= DATE(NOW()) - INTERVAL 15 DAY and last_auth_date is not null";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_nb_cycle($db) {
	static $sql = "select count(no_day) as MONCYCLE_APP_NB_CYCLE from day_timeline where cycle_1st_day=1 and no_user_account!=2";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_nb_cycle_recent($db) {
	static $sql = "select count(no_day) as MONCYCLE_APP_NB_CYCLE_RECENT from day_timeline where cycle_1st_day=1 and date_obs>= DATE(NOW()) - INTERVAL 30 DAY and no_user_account!=2";

	static $statement = $db->prepare($sql);
	$statement->execute();
	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_age_moyen($db) {
	static $sql = "select year(now())-avg(age)+2.5 as MONCYCLE_APP_NB_AGE_MOYEN from user_account";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_age_moyen_recent($db) {
	static $sql = "select year(now())-avg(age)+2.5 as MONCYCLE_APP_NB_AGE_MOYEN_RECENT from user_account where inscription_date >= DATE(NOW()) - INTERVAL 15 DAY and last_auth_date is not null and no_user_account!=2";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_total_day_timeline_count($db) {
	static $sql = "select count(no_day) as MONCYCLE_APP_NB_OBSERVATION from day_timeline where no_user_account!=2;";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_day_timeline_aujourdhui($db) {
	static $sql = "select count(no_day) as MONCYCLE_APP_NB_OBSERVATION_AUJOURDHUI from day_timeline where date_obs like DATE(NOW()) and no_user_account!=2";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_day_timeline_count($db, $nbj) {
	static $sql = "select count(no_day) as MONCYCLE_APP_NB_OBSERVATION from day_timeline where date_obs>= DATE(NOW()) - INTERVAL :nbj DAY and no_user_account!=2";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":nbj", $nbj, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_auth_token_user_account($db) {
	static $sql = "select count(no_auth_token) as MONCYCLE_APP_NB_TOKEN from auth_token";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_select_cycles_recent($db) {
	static $sql = "select subdate(obs.date_obs, 1) as cycle_complet, obs.no_user_account as no_user_account, c.name as name, c.nfp_method as nfp_method, c.email1 as email1, c.email2 as email2 from day_timeline as obs, user_account as c where obs.no_user_account=c.no_user_account and date_obs= DATE(NOW()) - INTERVAL 2 DAY and (cycle_1st_day=1 or pregnancy=1)";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_user_account_inactif($db) {
	static $sql = "select `c`.`no_user_account` as `no_user_account`,`c`.`name` as `name`,max(`o`.`last_write_db`) as `derniere_obs_modif`,`c`.`email1` as `email1`,`c`.`email2` as `email2`,`c`.`inscription_date` as `inscription_date` from `user_account` as     `c` left join `day_timeline` as `o` on `c`.`no_user_account` = `o`.`no_user_account` where `c`.`no_user_account` != 2 and `c`.`is_inactive`=0 group by `c`.`no_user_account`  having (date(`derniere_obs_modif`) < date(now()) - interval 35 DAY or `derniere_obs_modif` is null) and `inscription_date` < date(now()) - interval 35 DAY order by `derniere_obs_modif` desc limit 20";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_is_inactive ($db, $no_user_account, $is_inactive) {
	static $sql = "UPDATE user_account SET is_inactive = :is_inactive WHERE no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":is_inactive", $is_inactive, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_insert_auth_token($db, $no_user_account, $name, $contry_code, $auth_token_str, $expire=2) {
	static $sql = "INSERT INTO `auth_token` (`no_user_account`, `name`, `contry_code`, `auth_token_str`, `expire`) VALUES (:no_user_account, :name, :contry_code, :auth_token_str, :expire)";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":expire", $expire, PDO::PARAM_INT);
	$statement->bindValue(":name", $name, PDO::PARAM_STR);
	$statement->bindValue(":contry_code", $contry_code, PDO::PARAM_STR);
	$statement->bindValue(":auth_token_str", $auth_token_str, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_user_account_auth_token($db, $auth_token_str) {
	static $sql = "SELECT J.no_user_account, J.no_auth_token, C.name AS name_user_account, J.contry_code, J.name AS name_auth_token, J.date_creation AS d_creation_auth_token, J.date_use AS d_use_auth_token, C.nfp_method, C.age, C.email1, C.email2, C.nb_connection_attempts, C.sponsor, C.user_enabled, C.is_inactive, C.last_auth_date, C.inscription_date, C.last_password_change, C.register_comment, C.totp_secret, C.totp_state, C.research, C.timeline_asc, C.last_write_client_UTC FROM `auth_token` AS J INNER JOIN `user_account` AS C ON J.no_user_account=C.no_user_account WHERE `auth_token_str` = :auth_token_str LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":auth_token_str", $auth_token_str, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_auth_token_captcha($db, $auth_token_str) {
	static $sql = "SELECT captcha, no_auth_token FROM `auth_token` WHERE `auth_token_str` = :auth_token_str LIMIT 1";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":auth_token_str", $auth_token_str, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_auth_token_use($db, $no_auth_token){
	static $sql = "UPDATE `auth_token` SET `date_use` = now() WHERE `no_auth_token` = :no_auth_token";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_auth_token", $no_auth_token, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_auth_token_captcha($db, $auth_token_str, $captcha){
	static $sql = "UPDATE `auth_token` SET `date_use` = now(), `captcha` = :captcha WHERE `auth_token_str` = :auth_token_str";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":auth_token_str", $auth_token_str, PDO::PARAM_STR);
	$statement->bindValue(":captcha", $captcha, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_tous_les_auth_token($db, $no_user_account) {
	static $sql = "SELECT * FROM auth_token where no_user_account= :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_increment_key_value($db, $key){
	static $sql = "update key_value set `value` = `value`+1 where `key` like :key";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":key", $key, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_key_value($db, $key) {
	static $sql = "select `value` from key_value where `key` like :key";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":key", $key, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

function db_update_reset_key_value($db, $key){
	static $sql = "update key_value set `value` = 0 where `key` like :key";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":key", $key, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_user_account_totp_secret($db, $totp_secret, $no_user_account) {
	static $sql = "UPDATE user_account SET totp_secret = :cvalue WHERE no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":cvalue", $totp_secret, PDO::PARAM_STR);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_update_user_account_totp_state($db, $totp_state, $no_user_account) {
	static $sql = "UPDATE user_account SET totp_state = :cvalue WHERE no_user_account = :no_user_account";

	static $statement = $db->prepare($sql);
	$statement->bindValue(":no_user_account", $no_user_account, PDO::PARAM_INT);
	$statement->bindValue(":cvalue", $totp_state, PDO::PARAM_INT);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_select_user_account_avec_totp($db) {
	static $sql = "select count(no_user_account) as MONCYCLE_APP_NB_COMPTE_AVEC_TOTP from user_account where totp_state=3 and no_user_account!=2";

	static $statement = $db->prepare($sql);
	$statement->execute();

	return $statement->fetchAll(PDO::FETCH_NUM);
}

