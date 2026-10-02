<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/doc.php";
require_once "../lib/mail.php";

header("Content-Type: text/plain");

echo "............................................................................." . PHP_EOL;
echo "moncycle.app cron worker" . PHP_EOL;

$db = db_open();

// THE EXPORT OF A CYCLE THAT ENDED, by mail (cycles of at least 5 days)

foreach (db_select_cycles_recent($db) as $account) {

	$cycle_start = db_select_cycle($db, $account["cycle_complet"], $account["no_user_account"]);
	if (is_null($cycle_start)) continue;

	$days = doc_export_days($db, $cycle_start, $account["cycle_complet"], $account);
	if (count($days) < 5) continue;

	$nfp_method = intval($account["nfp_method"]);
	$csv = fopen('php://memory', 'rw');
	doc_cycle_to_csv($csv, $days, $nfp_method);
	rewind($csv);

	$file_name = 'moncycle_app_' . date_humain(new DateTime($cycle_start), '_');
	$sent = mail_send_cycle(
		$account, date_humain(new DateTime($days[0]["date"])), date_humain(new DateTime(end($days)["date"])), count($days),
		["$file_name.pdf" => doc_cycle_to_pdf($days, $nfp_method, $account["name"])->Output('S'), "$file_name.csv" => stream_get_contents($csv)]
	);
	fclose($csv);

	echo ($sent ? "cycle of " . count($days) . " days sent to " : "COULD NOT send the cycle of " . count($days) . " days to ") . "{$account["email1"]} (and {$account["email2"]})." . PHP_EOL;
}

// A REMINDER TO THE ACCOUNTS THAT HAVE GONE QUIET

foreach (db_select_user_account_inactif($db) as $account) {
	$sent = mail_send_reminder($account);
	if ($sent) db_update_is_inactive($db, $account["no_user_account"], 1);
	echo ($sent ? "reminder sent to " : "COULD NOT send a reminder to ") . "{$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
}

// RGPD: WARN, THEN DELETE, THE ACCOUNTS INACTIVE FOR ACCOUNT_INACTIVITY_DELETE_YEARS

foreach (db_select_user_account_to_warn_before_deletion($db, ACCOUNT_INACTIVITY_DELETE_YEARS, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE) as $account) {
	$sent = mail_send_deletion_warning($account, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE);
	echo ($sent ? "deletion warning sent to " : "COULD NOT send a deletion warning to ") . "{$account["email1"]} (and {$account["email2"]})" . PHP_EOL;
}

foreach (db_select_user_account_to_delete($db, ACCOUNT_INACTIVITY_DELETE_YEARS) as $account) {
	db_delete_user_account($db, $account["no_user_account"]);
	echo "account {$account["email1"]} deleted (" . ACCOUNT_INACTIVITY_DELETE_YEARS . " years without activity, RGPD)" . PHP_EOL;
}

// EXPIRED TOKENS

echo db_delete_vieux_auth_token($db) . " old tokens deleted" . PHP_EOL;
echo db_delete_vieux_login_attempt_ip($db) . " old login attempts (IP) deleted" . PHP_EOL;

// THE VISIT COUNTERS: every day, every Sunday, the first of the month

db_update_reset_key_value($db, "pub_visite_jour");
echo "daily stats reset";

$today = getdate();

if ($today["wday"] == 0) {
	db_update_reset_key_value($db, "pub_visite_hebdo");
	echo ", weekly stats reset";
}

if ($today["mday"] == 1) {
	db_update_reset_key_value($db, "pub_visite_mensuel");
	echo ", monthly stats reset";
}

echo PHP_EOL;
