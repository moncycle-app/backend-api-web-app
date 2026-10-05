<?php

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/log.php";
require_once "../lib/sec.php";

log_start(sec_client_ip());
header('Content-Type: application/json');

$db = db_open();


$stats = [];

$stats["moncycle_app_nb_user_account"] = round(db_count_user_accounts($db), -1);

$stats["moncycle_app_nb_cycle"] = round(db_count_cycles($db), -1);

$stats["moncycle_app_nb_total_observation"] = round(db_count_days($db), -2);

echo json_encode($stats, JSON_PRETTY_PRINT);

db_update_increment_key_value($db, "pub_visite_mensuel");
db_update_increment_key_value($db, "pub_visite_hebdo");
db_update_increment_key_value($db, "pub_visite_jour");
