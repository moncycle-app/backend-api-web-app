<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/date.php";
require_once "../lib/data.php";
require_once "../lib/sec.php";

header('Content-Type: application/json');

$result = [];

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') parse_str(file_get_contents('php://input'), $_DELETE);

$db = db_open();
$err = "";

$user_account = sec_auth_token($db);
sec_exit_si_non_connecte($user_account);

// LECTURE D'UNE OBSERVATION
if ($_SERVER['REQUEST_METHOD'] == "GET") {

	$dates_req = [];
	$start_date = null;
	$end_date = null;

	if (isset($_GET["date"]) && preg_match("/^\s*\d{4}-\d{2}-\d{2}(\s*,\s*\d{4}-\d{2}-\d{2})*\s*$/", $_GET["date"])) {
		$dates_req = explode(",", $_GET["date"]);
	}
	elseif (isset($_GET["date"])) $err .= "'date' in the wrong format. Correct format : YYYY-MM-DD,YYYY-MM-DD,YYYY-MM-DD... ";

	if (isset($_GET["start_date"]) && preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $_GET["start_date"])) {
		$start_date = trim($_GET["start_date"]);
	}
	elseif (isset($_GET["start_date"])) $err .= "'start_date' in the wrong format. Correct format : YYYY-MM-DD ! ";

	if (isset($_GET["end_date"]) && preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $_GET["end_date"])) {
		$end_date = trim($_GET["end_date"]);
	}
	elseif (isset($_GET["end_date"])) $err .= "'end_date' in the wrong format. Correct format : YYYY-MM-DD ! ";

	if (($start_date || $end_date) && !($start_date && $end_date)) {
		$err .= "'start_date' and 'end_date' should be used together";
	}

	if (empty($err)) {

		foreach ($dates_req as $date) {
			$date = trim($date);
			$result[$date] = data_construnct_day($db, $date, $user_account["no_user_account"]);
		}

		$all_days = array();
		if ($start_date && $end_date) $all_days = db_select_day_timelines_frame ($db, $start_date, $end_date, $user_account["no_user_account"]);
		elseif (empty($result)) $all_days = db_select_all_day_timeline($db, $user_account["no_user_account"]);
			
		$cycle_date = null;
		for ($i = 0; $i < count($all_days); $i+=1) {
			if ($all_days[$i]["cycle_1st_day"]) $cycle_date = $all_days[$i]["date_obs"];
			$all_days[$i] = data_construnct_day($db, $all_days[$i]["date_obs"], $user_account["no_user_account"], $all_days[$i], $cycle_date);
			$result[$all_days[$i]["date_obs"]] = $all_days[$i];
		}



	}

}

// CREATION ET MISE A JOUR D'UNE OBSERVATION
elseif($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['date']) && preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $_POST['date'])) {

	$date = trim($_POST['date']);
	$result["date"] = $date;

	$date_exploded = explode('-', $date);
	if (checkdate($date_exploded[1], $date_exploded[2], $date_exploded[0])) {

		if (isset($user_account["is_inactive"]) && boolval($user_account["is_inactive"])) {
			db_update_is_inactive($db, $user_account["no_user_account"], 0);
			$user_account["is_inactive"] = 0;
		}

		$date = trim($_POST['date']);
		$result["date"] = $date;

		try {

			$db->exec("START TRANSACTION");
	
			$output = db_select_day_timeline($db, $date, $user_account["no_user_account"]);
	
			$no_day = null;
			if(!isset($output[0])) $no_day = db_insert_day_timeline($db, $date, $user_account["no_user_account"]);
			else $no_day = $output[0]["no_day"];
	
			$temp = null;
			$htemp = null;
			if (isset($_POST["temp"]) && !empty(trim($_POST["temp"]))) {
				$temp = floatval($_POST["temp"]);
				if ($temp <= 0) $temp = null;
				elseif (!empty($_POST["time_temp_taken"])) $htemp = trim($_POST["time_temp_taken"]);
			}
	
			$go  = $_POST["stamp"] ?? '';
			$go .= $_POST["baby"] ?? '';
	
			$counter_start = null;
			if (isset($_POST["counter_start"]) && intval($_POST["counter_start"])>0) $counter_start = intval($_POST["counter_start"]);

			$last_write_client_UTC = "";
			if (isset($_POST["last_write_client_UTC"]) && date_validate_timestamp(trim($_POST['last_write_client_UTC']))) $last_write_client_UTC = trim($_POST['last_write_client_UTC']);
			else $last_write_client_UTC = date('Y-m-d H:i:s');
	
			db_update_day_timeline($db, $date, $user_account["no_user_account"], $last_write_client_UTC, $go, $_POST["fc_score"] ?? null, $_POST["fc_arrow"] ?? null, $temp, $htemp, $_POST["is_peak"] ?? null, $_POST["union_sex"] ?? null, $_POST["cycle_1st_day"] ?? null, $_POST["day_not_observed"] ?? null, $_POST["pregnancy"] ?? null, $_POST["comment"] ?? null, $counter_start);
			
			$all_raw_description = db_select_description_with_count($db, $user_account["no_user_account"]);
			$all_description_no = array();
			foreach ($all_raw_description as $rdesc) array_push($all_description_no, $rdesc["no_description"]);

			$old_description = db_select_all_description_for_day_timeline($db, $user_account["no_user_account"], $no_day);
			$old_description_no = array();
			foreach ($old_description as $odesc) array_push($old_description_no, $odesc["no_description"]);
			$to_delete_description_no = $old_description_no;

			$new_description_no = array();
			if (isset($_POST["no_description"]) && is_array($_POST["no_description"])) $new_description_no = $_POST["no_description"];
		
			for ($i=0; $i < count($new_description_no); $i+=1) { 
				$int_ndesc = intval($new_description_no[$i]);
				if (array_search($int_ndesc, $all_description_no) === false) unset($new_description_no[$i]);
				else {
					$to_delete_index = array_search($int_ndesc, $to_delete_description_no);
					if ($to_delete_index !== false) unset($to_delete_description_no[$to_delete_index]);
					$to_add_index = array_search($int_ndesc, $old_description_no);
					if ($to_add_index !== false) unset($new_description_no[$to_add_index]);
				}
			}

			foreach ($to_delete_description_no as $no_desc) db_delete_linked_descriptions ($db, $no_day, $no_desc);
			foreach ($new_description_no as $no_desc) db_insert_link_description_day_timeline ($db, $no_day, $no_desc);

			$db->exec("COMMIT");

		} catch (\Throwable $th) {
			$db->exec("ROLLBACK");
			$result["outcome"] = "ko";
			throw $th;
		}

		$result["outcome"] = "ok";

	}
	else {
		$err = "date non valide";
	}
}

// SUPPRESSION D'UNE OBSERVATION
elseif($_SERVER['REQUEST_METHOD'] == "DELETE" && isset($_DELETE['date']) && preg_match("/^\s*\d{4}-\d{2}-\d{2}\s*$/", $_DELETE['date'])) {
	$date = trim($_DELETE['date']);
	$result["date"] = $date;

	$last_write_client_UTC = "";
	if (isset($_POST["last_write_client_UTC"]) && date_validate_timestamp(trim($_POST['last_write_client_UTC']))) $last_write_client_UTC = trim($_POST['last_write_client_UTC']);
	else $last_write_client_UTC = date('Y-m-d H:i:s');
	
	db_update_day_timeline($db, $date, $user_account["no_user_account"], $last_write_client_UTC, '', null, null, null, null, null, null, null, null, null, null, null);

	$result["outcome"] = "ok";
}

else {
	$err = "date and/or action missing";
}

$db = null;

if ($err) print(json_encode(array("err" => $err)));
else print(json_encode($result));

