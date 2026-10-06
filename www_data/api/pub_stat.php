<?php

require_once "../config.php";
require_once "../lib/data.php";
require_once "../lib/db.php";
require_once "../lib/log.php";
require_once "../lib/sec.php";

log_start(sec_client_ip());
http_exit_if_maintenance();
header('Content-Type: application/json');

$db = db_open();


// the numbers the cron keeps (counted now when it has not run yet)
echo json_encode(data_public_stats($db), JSON_PRETTY_PRINT);

db_update_increment_key_value($db, "pub_visit_monthly");
db_update_increment_key_value($db, "pub_visit_weekly");
db_update_increment_key_value($db, "pub_visit_daily");
