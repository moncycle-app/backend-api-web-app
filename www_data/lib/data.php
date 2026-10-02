<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/db.php";

function data_construnct_day($db, $date, $no_user_account, $raw_day=null, $cycle=null, $pos=null) {
	$ob_data = array();

	if (is_null($cycle)) {
		$cycle = db_select_cycle($db, $date, $no_user_account);
		$ob_data["cycle"] = $cycle;
	}
	elseif (!is_null($cycle)) $ob_data["cycle"] = $cycle;

	if($cycle && is_null($pos)) {
		$interval = date_diff(date_create($cycle), date_create($date));
		$ob_data["pos"] = intval($interval->format('%a'))+1;
	}
	elseif (!is_null($pos)) $ob_data["pos"] = $pos;

	if(is_null($raw_day)) $raw_day = db_select_day_timeline($db, $date, $no_user_account) ?? array();
	if(!empty($raw_day)) {
		$ob_data = array_merge($ob_data, $raw_day);
		$ob_data["description"] = db_select_all_description_for_day_timeline($db, $no_user_account, $ob_data["no_day"]);
	}
	else {
		$ob_data["date_obs"] = $date;
	}

	return $ob_data;
}

// resolves a free-text description name to its id, creating it (with the given type) the
// first time it's used -- mirrors the dedupe-by-name already used by the account settings
// picklist (db_select_description_exact_name).
function data_resolve_description_id($db, $no_user_account, $name, $type, $last_write_client_UTC) {
	$name = trim($name);
	$existing = db_select_description_exact_name($db, $no_user_account, $name);
	if (isset($existing["no_description"])) return intval($existing["no_description"]);
	return intval(db_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC));
}

// a description row (db_select_description_with_count*) as the JSON API shows it, shared by
// api/description.php and api/sync.php so the two never drift apart.
function data_description_to_json(array $row): array {
	return [
		"id" => intval($row["no_description"]),
		"name" => $row["name"],
		"type" => DESCRIPTION_TYPE_NAMES[intval($row["type"])] ?? DESCRIPTION_TYPE_NAMES[DESCRIPTION_TYPE_UNDEFINED],
		"useCount" => intval($row["use_count"] ?? 0),
	];
}

function data_parse_fc_note ($str_fc_note) {
	$fc_note = [
		'10DL' => false,
		'10SL' => false,
		'10WL' => false,
		'RAP' => false,
		'LAP' => false, 
		'X1' => false,
		'X2' => false,
		'X3' => false,
		'AD' => false,
		'AP' => false,
		'VL' => false,
		'VH' => false,
		'2W' => false,
		'10' => false,
		'H' => false,
		'M' => false,
		'L' => false,
		'Lsaignement' => false,
		'B' => false,
		'0' => false,
		'2' => false,
		'4' => false,
		'6' => false,
		'8' => false,
		'C' => false,
		'G' => false,
		'K' => false,
		'P' => false,
		'Y' => false,
		'R' => false
	];
	// "=== ''" and not empty(): "0" is a real FertilityCare code (dryness) and is falsy in PHP,
	// so empty() read a day noted just "0" as having no note at all.
	if (is_null($str_fc_note) || $str_fc_note === '') return $fc_note;
	$str_fc_note = trim($str_fc_note);
	if (strlen($str_fc_note)>0) {
		$str_fc_note = strtoupper($str_fc_note);
		if (str_starts_with($str_fc_note, 'L') && !str_starts_with($str_fc_note, 'LAP')) {
			$fc_note['Lsaignement'] = true;
			$str_fc_note = substr($str_fc_note, 1);
		}
		foreach ($fc_note as $note => $is_present) {
			if (str_contains($str_fc_note, $note)) $fc_note[$note] = true;
			$str_fc_note = str_ireplace($note, '', $str_fc_note);
		}
		$str_fc_note = trim($str_fc_note);
	}
	$fc_note['extra'] = strlen($str_fc_note)>0;
	$fc_note['extra_str'] = $str_fc_note;
	return $fc_note;
}
