<?php
/* MONCYCLE.APP
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The NFP export: the days of an account as an NFP file, a data file made to move to another app.
// What the format is (vocabulary, schema) is lib/nfp_format.php; the day itself is the JSON day of
// lib/day_format.php, with the few fields the format names differently. The way back is lib/nfp_import.php.

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";

// ===========================================================================
// EXPORT
// ===========================================================================

// One stored day -> one NFP day. Takes the JSON day day_format_to_json() produces, and keeps only
// what the format defines, dropping anything empty so a quiet day stays small. An empty array is a
// day with nothing recorded, which the format writes as "{}".
function nfp_export_day(array $day): array {
	$nfp = [];

	$comment = day_format_clean_text((string) ($day['comment'] ?? ''));
	if ($comment !== '') $nfp['comments'] = [$comment];

	if (!empty($day['dayNotObserved'])) $nfp['mucusNotObserved'] = true;
	if (day_format_filled($day, 'stampColor')) $nfp['stampColor'] = $day['stampColor'];
	if (!empty($day['stampBaby'])) $nfp['stampBaby'] = true;

	// The method is not consulted here on purpose. A stored value is exported whatever the
	// account's current method says, because dropping recorded data from a portability file is
	// worse than a receiving app meeting a field it does not expect -- and the format marks these
	// fields _customValuesAllowed anyway. In practice each method only ever fills its own.
	// ("0", dryness, is a real code: day_format_filled() keeps it where empty() would not.)
	foreach ([...array_keys(DAY_FORMAT_FC_GROUPS), 'codifiedArrow'] as $field) {
		if (day_format_filled($day, $field)) $nfp[$field] = (string) $day[$field];
	}

	foreach (['freeMucusSensation', 'freeMucusObservation'] as $field) {
		if (!empty($day[$field]) && is_array($day[$field])) $nfp[$field] = array_values($day[$field]);
	}

	// a temperature of 0 is "none recorded", not a reading
	if (!empty($day['temperature'])) {
		$nfp['temperature'] = round(floatval($day['temperature']), 2);
		if (day_format_filled($day, 'temperatureTime')) $nfp['temperatureTime'] = $day['temperatureTime'];
	}

	if (!empty($day['isPeak'])) $nfp['isPeak'] = true;
	if (!empty($day['booleanPregnancyDetected'])) $nfp['booleanPregnancyDetected'] = true;
	// day_timeline records only whether there was a union, not which kind
	if (!empty($day['sexUnion'])) $nfp['sexUnion'] = 'Union';
	if (!empty($day['counterStart'])) $nfp['counterStart'] = intval($day['counterStart']);

	return $nfp;
}

// The cycles of a period, split at each cycle first day.
//
// The period is widened backwards to the first day of the cycle containing $start_date, so a
// cycle is never exported cut in half, and it never runs past today. Three queries total: the
// cycle lookup, the days, and the descriptions of those days in one go.
function nfp_export_cycles($db, string $start_date, string $end_date, array $user_account): array {
	$no_user_account = intval($user_account["no_user_account"]);
	$method = account_method_name(intval($user_account["nfp_method"]));

	$cycle_start_date = db_select_cycle($db, $start_date, $no_user_account) ?? $start_date;

	$raw_days = db_select_day_timelines_frame($db, $cycle_start_date, $end_date, $no_user_account);
	$raw_days = array_column($raw_days, null, 'date_obs');

	$description_rows = db_select_descriptions_for_day_timeline_frame($db, $cycle_start_date, $end_date, $no_user_account);
	$descriptions_by_day = [];
	foreach ($description_rows as $row) {
		$descriptions_by_day[intval($row["no_day"])][] = $row;
	}

	$cycles = [];
	$current = ["method" => $method, "cycleStartDate" => $cycle_start_date, "days" => []];

	$cursor = new DateTime($cycle_start_date);
	$last_date = new DateTime($end_date);
	$today = new DateTime('today');
	if ($last_date > $today) $last_date = $today;

	while ($cursor <= $last_date) {
		$date = $cursor->format('Y-m-d');

		if (!isset($raw_days[$date])) {
			$current["days"][] = new stdClass();      // a gap: "{}" in the file
			$cursor->modify('+1 day');
			continue;
		}

		$row = $raw_days[$date];
		$row["cycle"] = $current["cycleStartDate"];
		$row["description"] = $descriptions_by_day[intval($row["no_day"])] ?? [];
		$row["pos"] = null;                            // positional, and the format has no field for it
		$nfp_day = nfp_export_day(day_format_to_json($row));

		if (!empty($row["cycle_1st_day"]) && $date !== $current["cycleStartDate"]) {
			$cycles[] = $current;
			$current = ["method" => $method, "cycleStartDate" => $date, "days" => []];
		}

		$current["days"][] = empty($nfp_day) ? new stdClass() : $nfp_day;
		$cursor->modify('+1 day');
	}

	$cycles[] = $current;
	return $cycles;
}

// The whole file. $anonymous leaves out everything identifying, for sharing with a
// practitioner; the data itself is unchanged.
function nfp_export_file($db, string $start_date, string $end_date, array $user_account, bool $anonymous, string $app_version): array {
	$file = [
		"schemaVersion" => NFP_SCHEMA_VERSION,
		"fileInformation" => [
			"sourceApp" => "moncycle.app",
			"sourceAppVersion" => $app_version,
			"fileCreationTimestamp" => date('c'),
		],
	];

	if (!$anonymous) {
		$user = ["identifier" => strval($user_account["no_user_account"])];
		if (!empty($user_account["name_user_account"])) $user["firstName"] = $user_account["name_user_account"];
		if (!empty($user_account["email1"])) $user["email"] = $user_account["email1"];
		// birthDate is deliberately absent: user_account.age holds a birth *year*, and the
		// format's birthDate is a full YYYY-MM-DD, which would mean inventing a day and month.
		$file["userInformation"] = $user;
	}

	$file["userMethodPreferences"] = [
		"preferredMethod" => account_method_name(intval($user_account["nfp_method"])),
	];
	$file["cycles"] = nfp_export_cycles($db, $start_date, $end_date, $user_account);

	return $file;
}

// The file as the text a download or a mail attachment carries. $pretty is for reading it in the browser.
// JSON_UNESCAPED_UNICODE / _SLASHES keep accented comments and the ISO-8601 timestamp readable in the
// file; JSON_PRESERVE_ZERO_FRACTION keeps a round 37.0 a number.
function nfp_export_json($db, string $start_date, string $end_date, array $user_account, bool $anonymous, bool $pretty = false): string {
	$app_version = json_decode(file_get_contents(__DIR__ . "/../api/version.json"), true)["version"] ?? "";
	$file = nfp_export_file($db, $start_date, $end_date, $user_account, $anonymous, $app_version);

	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
	return json_encode($file, $pretty ? ($flags | JSON_PRETTY_PRINT) : $flags);
}
