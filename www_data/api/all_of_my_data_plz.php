<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/doc_csv.php";
require_once "../lib/log.php";
require_once "../lib/sec.php";

log_start(sec_client_ip());
$db = db_open();

$user_account = sec_auth_token($db);
sec_redirect_if_logged_out($user_account);
$no_user_account = $user_account["no_user_account"];

log_event("export.raw_dump");
header("content-type:application/csv;charset=UTF-8");
header('Content-Disposition: attachment; filename="export_moncycle_app.csv"');

$out = fopen('php://output', 'w');
fputs($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputs($out, "Export des données MONCYCLE.APP de " . $user_account["name_user_account"] . PHP_EOL . PHP_EOL);

// the account, its sessions and its days, as they are stored (secrets masked)
doc_csv_dump($out, [sec_obfuscate_columns(db_select_user_account($db, $no_user_account), ["password", "totp_secret"])]);
doc_csv_dump($out, array_map(fn($row) => sec_obfuscate_columns($row, ["auth_token_str"]), db_select_auth_tokens($db, $no_user_account)));
doc_csv_dump($out, doc_csv_raw_days($db, $no_user_account));

fclose($out);
