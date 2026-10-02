<?php

require_once "../config.php";
require_once "../lib/db.php";

header('Content-Type: application/json');

$db = db_open();


$stats = [];

$stats["moncycle_app_nb_user_account"] = round(db_select_nb_user_account($db), -1);

$stats["moncycle_app_nb_cycle"] = round(db_select_nb_cycle($db), -1);

$stats["moncycle_app_nb_total_observation"] = round(db_select_total_day_timeline_count($db), -2);

echo json_encode($stats, JSON_PRETTY_PRINT);

db_update_increment_key_value($db, "pub_visite_mensuel");
db_update_increment_key_value($db, "pub_visite_hebdo");
db_update_increment_key_value($db, "pub_visite_jour");
