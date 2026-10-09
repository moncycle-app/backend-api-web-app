<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/data.php";
require_once "../lib/doc_csv.php";
require_once "../lib/doc_export.php";
require_once "../lib/log.php";
require_once "../lib/mail.php";
require_once "../lib/nfp_export.php";

// php cron.php [--dry-run]. --dry-run reads and says what a run would do, and sends, deletes and writes nothing.
// Only the command line takes it, and anything else on it is refused: a typo must not turn a dry run into a real one.
$arguments = PHP_SAPI === "cli" ? array_slice($_SERVER["argv"], 1) : [];
$dry_run = in_array("--dry-run", $arguments, true);
if (array_diff($arguments, ["--dry-run"])) {
	fwrite(STDERR, "usage: php cron.php [--dry-run]" . PHP_EOL);
	exit(2);
}

log_cron_start($dry_run);
header("Content-Type: text/plain");

echo "............................................................................." . PHP_EOL;
echo "moncycle.app cron worker" . ($dry_run ? " (DRY RUN: nothing is sent, deleted or written)" : "") . PHP_EOL;

// MAINTENANCE: the run fails before it touches the database or sends anything (a dry run too: the database may be
// in the middle of a migration or a restore). A failed run, for the cron of the host: exit status 1, and 503 over HTTP.
if (MAINTENANCE_MODE) {
	echo "MAINTENANCE_MODE is on: the cron did nothing." . PHP_EOL;
	log_cron_finish(false, "maintenance");
	if (PHP_SAPI !== "cli") http_response_code(503);
	exit(1);
}

$db = db_open();

// THE EXPORT OF A CYCLE THAT ENDED, by mail: PDF, CSV and NFP (cycles of at least 5 days, accounts that
// have not turned auto_mail_export off)

$handled = $failed = 0;
foreach (db_select_cycles_finished($db) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);

	$cycle_start = db_select_cycle($db, $account["cycle_complet"], $account["no_user_account"]);
	if (is_null($cycle_start)) continue;

	$days = doc_export_days($db, $cycle_start, $account["cycle_complet"], $account);
	if (count($days) < 5) continue;

	$handled++;
	if ($dry_run) {
		echo "[dry run] would send the cycle of " . count($days) . " days to {$account["email1"]} (and {$account["email2"]})." . PHP_EOL;
		continue;
	}

	$nfp_method = intval($account["nfp_method"]);
	$csv = fopen('php://memory', 'rw');
	doc_csv_cycle($csv, $days, $nfp_method);
	rewind($csv);

	// the NFP export reads the account the way a logged-in request gives it: the name is "name_user_account"
	$nfp = nfp_export_json($db, $cycle_start, $account["cycle_complet"], $account + ["name_user_account" => $account["name"]], false);

	$file_name = 'moncycle_app_' . date_human(new DateTime($cycle_start), '_');
	$sent = mail_send_cycle(
		$account, date_human(new DateTime($days[0]["date"])), date_human(new DateTime(end($days)["date"])), count($days),
		["$file_name.pdf" => doc_export_pdf($days, $nfp_method, $account["name"])->Output('S'), "$file_name.csv" => stream_get_contents($csv), "$file_name.nfp" => $nfp]
	);
	fclose($csv);
	log_cron_count($sent ? "sent" : "ko");
	if (!$sent) $failed++;

	echo ($sent ? "cycle of " . count($days) . " days sent to " : "COULD NOT send the cycle of " . count($days) . " days to ") . "{$account["email1"]} (and {$account["email2"]})." . PHP_EOL;
}
log_cron_step("cycle_mails", $handled, $failed);

// A REMINDER TO THE ACCOUNTS THAT HAVE GONE QUIET

$handled = $failed = 0;
foreach (db_select_user_account_inactive($db) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);
	$handled++;
	if ($dry_run) {
		echo "[dry run] would send a reminder to {$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
		continue;
	}
	$sent = mail_send_reminder($account);
	log_cron_count($sent ? "sent" : "ko");
	if (!$sent) $failed++;
	if ($sent) db_update_is_inactive($db, $account["no_user_account"], 1);
	echo ($sent ? "reminder sent to " : "COULD NOT send a reminder to ") . "{$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
}
log_cron_step("reminders", $handled, $failed);

// RGPD: WARN, THEN DELETE, THE ACCOUNTS INACTIVE FOR ACCOUNT_INACTIVITY_DELETE_YEARS

$handled = $failed = 0;
foreach (db_select_user_account_to_warn_before_deletion($db, ACCOUNT_INACTIVITY_DELETE_YEARS, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);
	$handled++;
	if ($dry_run) {
		echo "[dry run] would send a deletion warning to {$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
		continue;
	}
	$sent = mail_send_deletion_warning($account, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE);
	log_cron_count($sent ? "sent" : "ko");
	if (!$sent) $failed++;
	echo ($sent ? "deletion warning sent to " : "COULD NOT send a deletion warning to ") . "{$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
}
log_cron_step("deletion_warnings", $handled, $failed);

$handled = 0;
foreach (db_select_user_account_to_delete($db, ACCOUNT_INACTIVITY_DELETE_YEARS) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);
	$handled++;
	if ($dry_run) {
		echo "[dry run] would delete account {$account["email1"]} (" . ACCOUNT_INACTIVITY_DELETE_YEARS . " years without activity, RGPD)" . PHP_EOL;
		continue;
	}
	data_delete_account($db, $account, "inactivity");
	log_cron_count("del");
	echo "account {$account["email1"]} deleted (" . ACCOUNT_INACTIVITY_DELETE_YEARS . " years without activity, RGPD)" . PHP_EOL;
}
log_cron_step("account_deletions", $handled);

// EXPIRED TOKENS

log_context(["uid" => null]);
if ($dry_run) {
	$count = db_count_old_auth_token($db);
	echo "[dry run] $count old tokens would be deleted" . PHP_EOL;
	log_cron_step("tokens", $count);
	$count = db_count_old_login_attempt_ip($db);
	echo "[dry run] $count old login attempts (IP) would be deleted" . PHP_EOL;
	log_cron_step("login_attempts", $count);
}
else {
	$deleted = db_delete_old_auth_token($db);
	log_cron_count("tok", $deleted);
	echo $deleted . " old tokens deleted" . PHP_EOL;
	log_cron_step("tokens", $deleted);
	$deleted = db_delete_old_login_attempt_ip($db);
	log_cron_count("ipa", $deleted);
	echo $deleted . " old login attempts (IP) deleted" . PHP_EOL;
	log_cron_step("login_attempts", $deleted);
}

// THE PUBLIC NUMBERS OF /api/pub_stat: counted here, once a day, and not on every visit

if (!$dry_run) data_public_stats_store($db);
echo ($dry_run ? "[dry run] public stats would be stored" : "public stats stored") . PHP_EOL;
log_cron_step("public_stats");

// THE VISIT COUNTERS: every day, every Sunday, the first of the month

$reset = $dry_run ? "would be reset" : "reset";
$today = getdate();
$counters = 1;

if (!$dry_run) db_update_reset_key_value($db, "pub_visit_daily");
echo ($dry_run ? "[dry run] " : "") . "daily stats " . $reset;

if ($today["wday"] == 0) {
	if (!$dry_run) db_update_reset_key_value($db, "pub_visit_weekly");
	echo ", weekly stats " . $reset;
	$counters++;
}

if ($today["mday"] == 1) {
	if (!$dry_run) db_update_reset_key_value($db, "pub_visit_monthly");
	echo ", monthly stats " . $reset;
	$counters++;
}

echo PHP_EOL;
log_cron_step("visit_counters", $counters);

log_cron_end();
