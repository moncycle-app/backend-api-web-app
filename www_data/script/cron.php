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

log_cron_start();
header("Content-Type: text/plain");

echo "............................................................................." . PHP_EOL;
echo "moncycle.app cron worker" . PHP_EOL;

$db = db_open();

// THE EXPORT OF A CYCLE THAT ENDED, by mail: PDF, CSV and NFP (cycles of at least 5 days, accounts that
// have not turned auto_mail_export off)

foreach (db_select_cycles_finished($db) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);

	$cycle_start = db_select_cycle($db, $account["cycle_complet"], $account["no_user_account"]);
	if (is_null($cycle_start)) continue;

	$days = doc_export_days($db, $cycle_start, $account["cycle_complet"], $account);
	if (count($days) < 5) continue;

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

	echo ($sent ? "cycle of " . count($days) . " days sent to " : "COULD NOT send the cycle of " . count($days) . " days to ") . "{$account["email1"]} (and {$account["email2"]})." . PHP_EOL;
}

// A REMINDER TO THE ACCOUNTS THAT HAVE GONE QUIET

foreach (db_select_user_account_inactive($db) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);
	$sent = mail_send_reminder($account);
	log_cron_count($sent ? "sent" : "ko");
	if ($sent) db_update_is_inactive($db, $account["no_user_account"], 1);
	echo ($sent ? "reminder sent to " : "COULD NOT send a reminder to ") . "{$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
}

// RGPD: WARN, THEN DELETE, THE ACCOUNTS INACTIVE FOR ACCOUNT_INACTIVITY_DELETE_YEARS

foreach (db_select_user_account_to_warn_before_deletion($db, ACCOUNT_INACTIVITY_DELETE_YEARS, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);
	$sent = mail_send_deletion_warning($account, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE);
	log_cron_count($sent ? "sent" : "ko");
	echo ($sent ? "deletion warning sent to " : "COULD NOT send a deletion warning to ") . "{$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
}

foreach (db_select_user_account_to_delete($db, ACCOUNT_INACTIVITY_DELETE_YEARS) as $account) {
	log_context(["uid" => intval($account["no_user_account"])]);
	data_delete_account($db, $account, "inactivity");
	log_cron_count("del");
	echo "account {$account["email1"]} deleted (" . ACCOUNT_INACTIVITY_DELETE_YEARS . " years without activity, RGPD)" . PHP_EOL;
}

// EXPIRED TOKENS

log_context(["uid" => null]);
$deleted = db_delete_old_auth_token($db);
log_cron_count("tok", $deleted);
echo $deleted . " old tokens deleted" . PHP_EOL;
$deleted = db_delete_old_login_attempt_ip($db);
log_cron_count("ipa", $deleted);
echo $deleted . " old login attempts (IP) deleted" . PHP_EOL;

// THE VISIT COUNTERS: every day, every Sunday, the first of the month

db_update_reset_key_value($db, "pub_visit_daily");
echo "daily stats reset";

$today = getdate();

if ($today["wday"] == 0) {
	db_update_reset_key_value($db, "pub_visit_weekly");
	echo ", weekly stats reset";
}

if ($today["mday"] == 1) {
	db_update_reset_key_value($db, "pub_visit_monthly");
	echo ", monthly stats reset";
}

echo PHP_EOL;

log_cron_end();
