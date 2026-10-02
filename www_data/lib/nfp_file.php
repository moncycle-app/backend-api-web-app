<?php
/* MONCYCLE.APP
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** The NFP file pipeline: day_timeline <-> the NFP interchange format.
**
** lib/nfp_format.php owns the format (vocabulary, limits, schema, legacy variants). This file
** owns the mapping onto this app's storage, in four stages the endpoints call in order:
**
**   nfp_file_parse()       raw request body -> canonical object, or a transport-level refusal
**   nfp_file_schema_errors()  structure and types, via the JSON Schema
**   nfp_file_build_plan()  semantics, consistency, and the per-day write plan
**   nfp_file_write_plan()  the only stage that writes, and only inside a transaction
**
** Nothing is written before all three checking stages have passed, so a file is either
** imported whole or not at all.
**
** The per-day encoding is NOT duplicated here. day_to_json() / day_from_json() in
** lib/day_format.php already translate between the packed DB columns (stamp, fc_score,
** fc_arrow) and a structured day shape built on the NFP vocabulary, and they are what
** api/day.php writes through. This file reuses them so an imported day and a day posted to
** /api/day go through exactly the same encoder, and the packed-code knowledge stays in one
** place.
*/

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/data.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/nfp_format.php";

// ---------------------------------------------------------------------------
// Shared text hygiene.
//
// Free text out of a file reaches day_timeline.comment and description.name, and
// from there the CSV and PDF exports. Control characters serve no purpose in
// either and make the CSV unreadable, so they are stripped here; line breaks and
// tabs survive because a comment legitimately carries them.
// ---------------------------------------------------------------------------

function nfp_file_clean_text(string $text): string {
	$text = str_replace(["\r\n", "\r"], "\n", $text);
	// C0 and C1 controls, and the Unicode line/paragraph separators, except \n and \t
	$text = preg_replace('/[^\P{C}\n\t]+/u', '', $text);
	if (is_null($text)) return '';            // invalid UTF-8 would make preg_replace fail
	return trim($text);
}

// True when a day field carries an actual value. Distinct from empty(), which also rejects
// "0" -- a FertilityCare code -- and so cannot be used on these fields.
function nfp_file_filled(array $day, string $field): bool {
	return isset($day[$field]) && (string) $day[$field] !== '';
}

function nfp_file_valid_time(string $time): bool {
	if (!preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $time, $m)) return false;
	return intval($m[1]) <= 23 && intval($m[2]) <= 59 && intval($m[3]) <= 59;
}

// A FertilityCare note value, canonicalised onto the codes data_parse_fc_note() can read back.
// Returns null when the value is outside that vocabulary -- fc_score is re-parsed by substring
// matching, so an unrecognised code stored there would corrupt the day it lands on.
function nfp_file_fc_code_canonical(string $field, string $value): ?string {
	$vocabulary = nfp_format_fc_vocabulary()[$field] ?? [];
	if (empty($vocabulary)) return null;

	$normalized = strtoupper(trim($value));
	if ($normalized === '') return null;
	if (isset(NFP_FC_CODE_ALIASES[$normalized])) $normalized = NFP_FC_CODE_ALIASES[$normalized];

	// Longest code first, so "10DL" is not read as "10" followed by junk. Values are matched
	// as a sequence because day_format_fc_score_decode() concatenates the codes of a group
	// (two mucus observations come back as "CK"), and such a value has to import again.
	$codes = $vocabulary;
	usort($codes, fn($a, $b) => strlen($b) <=> strlen($a));

	$rest = $normalized;
	$matched = '';
	while ($rest !== '') {
		$found = null;
		foreach ($codes as $code) {
			if (str_starts_with($rest, $code)) { $found = $code; break; }
		}
		if (is_null($found)) return null;
		$matched .= $found;
		$rest = substr($rest, strlen($found));
	}
	return $matched;
}

// ===========================================================================
// EXPORT
// ===========================================================================

// One stored day -> one NFP day. Takes the structured day shape day_to_json() produces, and
// keeps only what the format defines, dropping anything empty so a quiet day stays small.
// An empty array is a day with nothing recorded, which the format writes as "{}".
function nfp_file_day_to_nfp(array $day): array {
	$nfp = [];

	$comment = nfp_file_clean_text((string) ($day['comment'] ?? ''));
	if ($comment !== '') $nfp['comments'] = [$comment];

	if (!empty($day['dayNotObserved'])) $nfp['mucusNotObserved'] = true;

	// Note the explicit "!== ''" tests rather than empty() on every string field below: "0" is
	// a real FertilityCare mucus-sensation code (dryness) and is falsy in PHP, so empty() would
	// drop it from the file and lose the observation.
	if (nfp_file_filled($day, 'stampColor')) $nfp['stampColor'] = $day['stampColor'];
	if (!empty($day['stampBaby'])) $nfp['stampBaby'] = true;

	// The method is not consulted here on purpose. A stored value is exported whatever the
	// account's current method says, because dropping recorded data from a portability file
	// is worse than a receiving app meeting a field it does not expect -- and the format
	// marks these fields _customValuesAllowed anyway. In practice each method only ever fills
	// its own: the FertilityCare notes below stay null for a Billings account, and vice versa.
	foreach (array_keys(DAY_FORMAT_FC_GROUPS) as $field) {
		if (nfp_file_filled($day, $field)) $nfp[$field] = (string) $day[$field];
	}
	if (nfp_file_filled($day, 'codifiedArrow')) $nfp['codifiedArrow'] = $day['codifiedArrow'];

	foreach (['freeMucusSensation', 'freeMucusObservation'] as $field) {
		if (!empty($day[$field]) && is_array($day[$field])) $nfp[$field] = array_values($day[$field]);
	}

	// a temperature of 0 is "none recorded", not a reading, so empty() is right here
	if (!empty($day['temperature'])) {
		$nfp['temperature'] = round(floatval($day['temperature']), 2);
		if (nfp_file_filled($day, 'temperatureTime')) $nfp['temperatureTime'] = $day['temperatureTime'];
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
function nfp_file_export_cycles($db, string $start_date, string $end_date, array $user_account): array {
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
		$nfp_day = nfp_file_day_to_nfp(day_to_json($row));

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
function nfp_file_export($db, string $start_date, string $end_date, array $user_account, bool $anonymous, string $app_version): array {
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
	$file["cycles"] = nfp_file_export_cycles($db, $start_date, $end_date, $user_account);

	return $file;
}

// ===========================================================================
// IMPORT -- stage 1: the request body
// ===========================================================================

// Returns ["ok" => true, "file" => object] or ["ok" => false, "code" =>, "message" =>,
// "details" => []]. Everything here is a transport-level refusal: too big, not UTF-8, not
// JSON, or a schema version this build does not implement.
function nfp_file_parse(string $raw, ?int $content_length = null): array {
	$refuse = fn(string $code, string $message, array $details = []) =>
		["ok" => false, "code" => $code, "message" => $message, "details" => $details];

	if ($raw === '') {
		// PHP discards a body larger than post_max_size and leaves php://input empty, so an
		// empty body with a Content-Length is almost always a file over the limit.
		if (!is_null($content_length) && $content_length > 0) {
			return $refuse("file_too_large", sprintf(
				"The request body was dropped before it reached the application: %d bytes against a server limit of %s (post_max_size). Split the file into fewer cycles.",
				$content_length, ini_get('post_max_size')
			));
		}
		return $refuse("empty_body", "The request body is empty. Send the content of an .nfp file.");
	}

	if (strlen($raw) > NFP_LIMIT_BODY_BYTES) {
		return $refuse("file_too_large", sprintf(
			"The file is %d bytes; the limit is %d. Split it into fewer cycles.",
			strlen($raw), NFP_LIMIT_BODY_BYTES
		));
	}

	if (!mb_check_encoding($raw, 'UTF-8')) {
		return $refuse("invalid_encoding", "The file is not valid UTF-8.");
	}

	try {
		$decoded = json_decode($raw, false, NFP_LIMIT_JSON_DEPTH, JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		return $refuse("invalid_json", "The file is not valid JSON.", ["reason" => $e->getMessage()]);
	}

	if (!is_object($decoded)) {
		return $refuse("invalid_json", "The top level of the file must be a JSON object.");
	}

	// The version gate runs before normalization: a file claiming a version this build does
	// not implement must say so plainly, not fail later on individual fields.
	$version = nfp_format_get($decoded, "schemaVersion");
	if (!is_string($version) || trim($version) === '') {
		return $refuse("missing_schema_version", "'schemaVersion' is required and must not be empty.");
	}
	$version = trim($version);
	if (!preg_match('/^\d+\.\d+$/', $version)) {
		return $refuse("invalid_schema_version", sprintf("'schemaVersion' must look like 1.0; got '%s'.", $version));
	}
	if (!in_array($version, NFP_SUPPORTED_SCHEMA_VERSIONS, true)) {
		return $refuse("unsupported_schema_version", sprintf(
			"Schema version %s is not supported. Supported: %s.",
			$version, implode(", ", NFP_SUPPORTED_SCHEMA_VERSIONS)
		));
	}

	return ["ok" => true, "file" => nfp_format_normalize($decoded)];
}

// ===========================================================================
// IMPORT -- stage 2: structure and types
// ===========================================================================

// Each error reads "[where] what is wrong", with "where" the path inside the file
// (cycles[0].days[7].temperature). Expects the canonical shape, so run nfp_file_parse() first.
function nfp_file_schema_errors(object $file): array {
	$schema = json_decode(NFP_FILE_SCHEMA);

	$storage = new \JsonSchema\SchemaStorage();
	$storage->addSchema('internal://nfp-file', $schema);
	$validator = new \JsonSchema\Validator(new \JsonSchema\Constraints\Factory($storage));
	$validator->validate($file, $schema);

	if ($validator->isValid()) return [];

	$errors = [];
	foreach ($validator->getErrors() as $error) {
		$where = $error['property'] !== '' ? $error['property'] : '(file)';
		$errors[] = sprintf("[%s] %s", $where, $error['message']);
	}
	// a file wrong in one structural way is wrong on every day; keep the answer readable
	if (count($errors) > 50) {
		$remaining = count($errors) - 50;
		$errors = array_slice($errors, 0, 50);
		$errors[] = sprintf("... and %d more.", $remaining);
	}
	return $errors;
}

// ===========================================================================
// IMPORT -- stage 3: semantics, consistency, and the write plan
// ===========================================================================

/*
** Returns:
**   issues[]   hard inconsistencies -- any one of them rejects the file
**   warnings[] accepted, but the caller should know (method mismatch, ...)
**   ignored[]  a field the file carries and this app has nowhere to store
**   mapped[]   a field stored as something narrower than the file said
**   duplicates[] dates the file itself carries twice -- also in issues[], where they do the
**              rejecting; here as bare dates, because a dry run has to show them as a list
**   plan[]     one entry per day to write, already encoded for db_update_day_timeline()
**   read       cycles and days counted in the file
**
** Nothing here touches the DB.
*/
function nfp_file_build_plan(object $file, array $user_account): array {
	$issues = [];
	$warnings = [];
	$ignored = [];
	$mapped = [];
	$duplicates = [];
	$plan = [];
	$dates_seen = [];

	$account_method = account_method_name(intval($user_account["nfp_method"]));

	$floor = new DateTime(NFP_DATE_FLOOR);
	$ceiling = new DateTime('today');
	$ceiling->modify('+' . NFP_FUTURE_GRACE_DAYS . ' day');

	// An imported file never rewrites the account itself. Letting it would mean a file could
	// change the address and the method of the account importing it.
	if (nfp_format_has($file, "userInformation")) {
		$ignored[] = "[file] userInformation: the account's own identity is never changed by an import.";
	}
	$preferences = nfp_format_get($file, "userMethodPreferences");
	if (!is_null($preferences)) {
		$preferred = nfp_format_get($preferences, "preferredMethod");
		$note = "[file] userMethodPreferences: the account's method is only changed from the account settings";
		if (is_string($preferred) && $preferred !== '' && strcasecmp($preferred, $account_method) !== 0) {
			$note .= sprintf(" (the file prefers '%s', this account is set to '%s')", $preferred, $account_method);
		}
		$ignored[] = $note . ".";
	}

	$cycles = nfp_format_get($file, "cycles");
	if (!is_array($cycles)) return nfp_file_plan_result(["[file] 'cycles' must be an array."], [], [], [], [], [], 0, 0);

	// The three count limits below are advisory (see the NFP_LIMIT_ block in nfp_format.php):
	// /export is bounded by the date range asked for, not by them, so a long enough export
	// breaks all three. They are reported and the file is read in full. The body size, already
	// enforced in stage 1, is what bounds the work -- stages 1 and 2 have decoded and validated
	// the whole file before any of these is looked at.
	if (count($cycles) > NFP_LIMIT_CYCLES) {
		$warnings[] = sprintf(
			"[file] %d cycles, more than the %d a file usually carries. All of them were read.",
			count($cycles), NFP_LIMIT_CYCLES
		);
	}

	$days_read = 0;

	foreach ($cycles as $cycle_index => $cycle) {
		$where = sprintf("cycles[%d]", $cycle_index);

		$method = nfp_format_get($cycle, "method");
		$method = is_string($method) ? trim($method) : '';
		$start_date = nfp_format_get($cycle, "cycleStartDate");
		$start_date = is_string($start_date) ? trim($start_date) : '';

		// the schema has already checked the YYYY-MM-DD shape; this is the calendar check
		$parts = explode('-', $start_date);
		if (count($parts) !== 3 || !checkdate(intval($parts[1]), intval($parts[2]), intval($parts[0]))) {
			$issues[] = sprintf("[%s] cycleStartDate '%s' is not a real calendar date.", $where, $start_date);
			continue;
		}

		$cycle_start = new DateTime($start_date);
		if ($cycle_start < $floor) {
			$issues[] = sprintf("[%s] cycleStartDate %s is before %s.", $where, $start_date, NFP_DATE_FLOOR);
			continue;
		}
		if ($cycle_start > $ceiling) {
			$issues[] = sprintf("[%s] cycleStartDate %s is in the future.", $where, $start_date);
			continue;
		}

		if ($method === '') {
			$issues[] = sprintf("[%s] 'method' is required and must not be empty.", $where);
			continue;
		}
		if (!in_array($method, NFP_METHODS_KNOWN, true)) {
			$warnings[] = sprintf(
				"[%s] method '%s' is not one this app knows; its days were read for the fields every method shares.",
				$where, $method
			);
		}
		elseif (!in_array($method, NFP_METHODS_NATIVE, true)) {
			$warnings[] = sprintf(
				"[%s] method '%s' is not recorded by this app; only the fields it shares with %s were kept.",
				$where, $method, $account_method
			);
		}
		elseif ($method !== $account_method) {
			$warnings[] = sprintf(
				"[%s] the cycle uses '%s' while this account is set to '%s'. The data was imported; change the method in the account settings to see it.",
				$where, $method, $account_method
			);
		}

		foreach (NFP_CYCLE_FIELDS_NOT_STORED as $field => $why) {
			if (nfp_format_has($cycle, $field)) $ignored[] = sprintf("[%s] %s: %s.", $where, $field, $why);
		}
		foreach (nfp_format_object_keys($cycle) as $key) {
			if (!in_array($key, nfp_format_cycle_fields_known(), true)) {
				$ignored[] = sprintf("[%s] %s: not a cycle field of schema %s.", $where, $key, NFP_SCHEMA_VERSION);
			}
		}

		$days = nfp_format_get($cycle, "days");
		if (!is_array($days)) {
			$issues[] = sprintf("[%s] 'days' must be an array.", $where);
			continue;
		}
		// a cycle runs until the next first day is marked, so an account left alone for years
		// exports one cycle padded with gaps all the way to today
		if (count($days) > NFP_LIMIT_DAYS_PER_CYCLE) {
			$warnings[] = sprintf(
				"[%s] %d days, more than the %d a cycle usually runs. The cycle was read in full.",
				$where, count($days), NFP_LIMIT_DAYS_PER_CYCLE
			);
		}

		$was_under_total = $days_read <= NFP_LIMIT_DAYS_TOTAL;
		$days_read += count($days);
		if ($was_under_total && $days_read > NFP_LIMIT_DAYS_TOTAL) {
			$warnings[] = sprintf(
				"[file] more than %d days in one file. All of them were read.",
				NFP_LIMIT_DAYS_TOTAL
			);
		}

		$cursor = new DateTime($start_date);
		foreach ($days as $day_index => $nfp_day) {
			$day_date = clone $cursor;
			$date = $day_date->format('Y-m-d');
			$cursor->modify('+1 day');

			if (!nfp_format_is_object($nfp_day)) {
				$issues[] = sprintf("[%s] day %d (%s) must be an object.", $where, $day_index, $date);
				continue;
			}
			if (is_array($nfp_day)) $nfp_day = new stdClass();

			// Overlapping cycles leave a date belonging to two of them; the file is ambiguous
			// and is refused rather than guessed at. Gaps count, because the overlap is
			// structural whether or not the day carries anything.
			if (isset($dates_seen[$date])) {
				$issues[] = sprintf(
					"[%s] %s appears twice in the file (also in %s): the cycles overlap.",
					$where, $date, $dates_seen[$date]
				);
				$duplicates[] = $date;
				continue;
			}
			$dates_seen[$date] = $where;

			$is_cycle_first_day = ($day_index === 0);
			$mapping = nfp_file_day_from_nfp($nfp_day, $method, $date);

			$issues = array_merge($issues, $mapping["issues"]);
			$warnings = array_merge($warnings, $mapping["warnings"]);
			$ignored = array_merge($ignored, $mapping["ignored"]);
			$mapped = array_merge($mapped, $mapping["mapped"]);

			// A day past today is only a problem when it carries something. Our own export
			// pads a cycle with gaps up to today, and a client a timezone ahead would
			// otherwise make its own file unimportable around midnight.
			if ($day_date > $ceiling && ($mapping["content"] || $is_cycle_first_day)) {
				$issues[] = sprintf("[%s] day %d falls on %s, which is in the future.", $where, $day_index, $date);
				continue;
			}

			// The first day of a cycle is always written, even with nothing recorded on it:
			// it is what marks the cycle boundary (day_timeline.cycle_1st_day), and dropping
			// it would lose the cycle itself.
			if (!$mapping["content"] && !$is_cycle_first_day) continue;

			$day_json = $mapping["day"];
			$day_json["cycleFirstDay"] = $is_cycle_first_day;

			$fields = day_from_json($day_json);

			// fc_score is varchar(32); day_format_fc_score_encode() joins the five note
			// groups, so this is the only place the packed length is knowable.
			if (!is_null($fields["fc_score"]) && mb_strlen($fields["fc_score"]) > NFP_LIMIT_FC_SCORE_CHARS) {
				$issues[] = sprintf(
					"[%s] the FertilityCare notes pack into %d characters; the limit is %d.",
					$date, mb_strlen($fields["fc_score"]), NFP_LIMIT_FC_SCORE_CHARS
				);
				continue;
			}

			$plan[] = [
				"date" => $date,
				"cycleStartDate" => $start_date,
				"fields" => $fields,
				"sensations" => $mapping["sensations"],
				"observations" => $mapping["observations"],
			];
		}
	}

	return nfp_file_plan_result($issues, $warnings, $ignored, $mapped, $duplicates, $plan, count($cycles), $days_read);
}

function nfp_file_plan_result(array $issues, array $warnings, array $ignored, array $mapped, array $duplicates, array $plan, int $cycles_read, int $days_read): array {
	return [
		"issues" => $issues,
		"warnings" => $warnings,
		"ignored" => array_values(array_unique($ignored)),
		"mapped" => $mapped,
		"duplicates" => array_values(array_unique($duplicates)),
		"plan" => $plan,
		"cyclesRead" => $cycles_read,
		"daysRead" => $days_read,
	];
}

/*
** One NFP day -> the structured day shape day_from_json() consumes.
**
** Returns ["day" =>, "sensations" =>, "observations" =>, "content" =>, "issues" =>,
** "warnings" =>, "ignored" =>, "mapped" =>]. "content" is false for a day the file leaves
** empty, which is a gap in the timeline and is not written at all.
**
** issues[] refuse the file; warnings[] do not. The line between them is whether the value can
** be stored at all: a temperature the column cannot hold is an issue, a storable one outside
** the plausible band is a warning. Anything this app's own /export can write has to end up on
** the warning side, or the app would be emitting files it refuses to read back.
**
** How a field is treated depends on the cycle's method, because the format reuses the same
** key for different vocabularies: codifiedMucusSensation is "0".."10WL" under FertilityCare
** but "Dry|Humid|Wet|Lubricated" under symptothermic_fr. Only the FertilityCare reading can
** be stored, so under any other method those keys are reported rather than written.
*/
function nfp_file_day_from_nfp(object $nfp_day, string $method, string $date): array {
	$day = [];
	$issues = [];
	$warnings = [];
	$ignored = [];
	$mapped = [];
	$sensations = [];
	$observations = [];
	$content = false;

	// --- comments -------------------------------------------------------
	$comments = nfp_format_get($nfp_day, "comments");
	if (is_array($comments)) {
		$parts = [];
		foreach ($comments as $comment) {
			if (!is_string($comment)) continue;
			$comment = nfp_file_clean_text($comment);
			if ($comment !== '') $parts[] = $comment;
		}
		if (!empty($parts)) {
			$joined = implode("\n", $parts);
			if (mb_strlen($joined) > NFP_LIMIT_COMMENT_CHARS) {
				$issues[] = sprintf(
					"[%s] the comments come to %d characters together; the limit is %d.",
					$date, mb_strlen($joined), NFP_LIMIT_COMMENT_CHARS
				);
			}
			else {
				$day["comment"] = $joined;
				$content = true;
			}
		}
	}

	// --- mucusNotObserved ------------------------------------------------
	// The format calls mucusNotObserved incompatible with any observation, but day_timeline
	// has no such rule: day_not_observed is just another column, and a day can carry it
	// alongside a stamp or a label -- so /export writes that combination, and refusing it
	// here would make this app's own files unimportable. Both are kept, exactly as the
	// account had them, and the contradiction is only reported.
	if (nfp_format_get($nfp_day, "mucusNotObserved") === true) {
		$clash = [];
		foreach (NFP_MUCUS_NOT_OBSERVED_INCOMPATIBLE as $field) {
			if (nfp_format_has($nfp_day, $field)) $clash[] = $field;
		}
		if (!empty($clash)) {
			$warnings[] = sprintf(
				"[%s] mucusNotObserved says nothing was recorded, but the day also carries %s. Both were imported as they are.",
				$date, implode(", ", $clash)
			);
		}
		$day["dayNotObserved"] = true;
		$content = true;
	}

	// --- stamp ------------------------------------------------------------
	$colour = nfp_format_get($nfp_day, "stampColor");
	$baby = nfp_format_get($nfp_day, "stampBaby") === true;
	if (is_string($colour) && $colour !== '') {
		$day["stampColor"] = $colour;          // the schema has checked the enum
		$content = true;
		// stamp is ''|R|G|Y with an optional BB: a white stamp is the absence of a colour,
		// so on its own it cannot be told from a day with no stamp at all.
		if ($colour === 'White' && !$baby) {
			$mapped[] = sprintf("[%s] stampColor 'White' without stampBaby is stored as no stamp.", $date);
		}
	}
	if ($baby) {
		$day["stampBaby"] = true;
		$content = true;
	}

	// --- the codified note groups ----------------------------------------
	if ($method === NFP_METHOD_FERTILITY_CARE) {
		foreach (array_keys(DAY_FORMAT_FC_GROUPS) as $field) {
			$value = nfp_format_get($nfp_day, $field);
			if (!is_string($value) || trim($value) === '') continue;
			$canonical = nfp_file_fc_code_canonical($field, $value);
			if (is_null($canonical)) {
				$ignored[] = sprintf("[%s] %s '%s': outside the FertilityCare notation this app stores.", $date, $field, trim($value));
				continue;
			}
			$day[$field] = $canonical;
			$content = true;
		}
		$arrow = nfp_format_get($nfp_day, "codifiedArrow");
		if (is_string($arrow) && trim($arrow) !== '') {
			if (in_array($arrow, NFP_ARROWS, true)) {
				$day["codifiedArrow"] = $arrow;
				$content = true;
			}
			else $ignored[] = sprintf("[%s] codifiedArrow '%s': not one of %s.", $date, trim($arrow), implode("|", NFP_ARROWS));
		}
	}
	else {
		// Bleeding is the one codified field with a meaning outside FertilityCare: a bleeding
		// day is a red stamp here. The rest of the codified vocabulary has no equivalent.
		$bleeding = nfp_format_get($nfp_day, "codifiedBleedingObservation");
		if (is_string($bleeding) && trim($bleeding) !== '') {
			if (!isset($day["stampColor"])) {
				$day["stampColor"] = 'Red';
				$content = true;
				$mapped[] = sprintf(
					"[%s] codifiedBleedingObservation '%s' stored as a Red stamp (%s records bleeding as a stamp).",
					$date, trim($bleeding), $method
				);
			}
			else {
				$ignored[] = sprintf(
					"[%s] codifiedBleedingObservation '%s': the day already carries a %s stamp.",
					$date, trim($bleeding), $day["stampColor"]
				);
			}
		}
		foreach (["codifiedMucusSensation", "codifiedMucusObservation", "codifiedNumberObservations",
		          "codifiedPainObservations", "codifiedArrow"] as $field) {
			if (nfp_format_has($nfp_day, $field)) {
				$ignored[] = sprintf(
					"[%s] %s: only the FertilityCare reading of this field can be stored, and this cycle is '%s'.",
					$date, $field, $method
				);
			}
		}
	}

	// --- free-text observations and sensations ----------------------------
	foreach (["freeMucusSensation", "freeMucusObservation"] as $field) {
		$values = nfp_format_get($nfp_day, $field);
		if (!is_array($values)) continue;
		$names = [];
		foreach ($values as $name) {
			if (!is_string($name)) continue;
			$name = nfp_file_clean_text($name);
			if ($name === '') continue;
			if (mb_strlen($name) > NFP_LIMIT_DESCRIPTION_CHARS) {
				$issues[] = sprintf(
					"[%s] the %s entry '%s...' is %d characters; the limit is %d.",
					$date, $field, mb_substr($name, 0, 30), mb_strlen($name), NFP_LIMIT_DESCRIPTION_CHARS
				);
				continue;
			}
			$names[] = $name;
		}
		$names = array_values(array_unique($names));
		// Nothing caps how many labels a day is linked to -- link_day_timeline_description
		// has no such key -- so /export can write more than the format's advisory count. They
		// are all imported; dropping the overflow would lose data the account already had.
		if (count($names) > NFP_LIMIT_DESCRIPTIONS_PER_DAY) {
			$warnings[] = sprintf(
				"[%s] %d %s entries, more than the %d a day usually carries. All of them were imported.",
				$date, count($names), $field, NFP_LIMIT_DESCRIPTIONS_PER_DAY
			);
		}
		if (!empty($names)) {
			if ($field === "freeMucusSensation") $sensations = $names;
			else $observations = $names;
			$content = true;
		}
	}

	// --- flags and scalars -------------------------------------------------
	if (nfp_format_get($nfp_day, "isPeak") === true) {
		$day["isPeak"] = true;
		$content = true;
	}
	if (nfp_format_get($nfp_day, "booleanPregnancyDetected") === true) {
		$day["booleanPregnancyDetected"] = true;
		$content = true;
	}

	$union = nfp_format_get($nfp_day, "sexUnion");
	if (is_string($union) && trim($union) !== '') {
		$day["sexUnion"] = true;
		$content = true;
		if ($union !== 'Union') {
			$mapped[] = sprintf(
				"[%s] sexUnion '%s' stored as a plain union: day_timeline records only whether there was one.",
				$date, $union
			);
		}
	}

	$counter = nfp_format_get($nfp_day, "counterStart");
	if (is_int($counter) && $counter > 0) {
		$day["counterStart"] = $counter;       // the schema has bounded it to 0-255
		$content = true;
	}

	// --- temperature --------------------------------------------------------
	$temperature = nfp_format_get($nfp_day, "temperature");
	if (is_int($temperature) || is_float($temperature)) {
		$value = floatval($temperature);
		$printed = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

		// Outside what decimal(4,2) unsigned holds there is nothing to write, so the file is
		// refused. Inside the column but outside the band a body reaches, the reading is kept:
		// nothing stopped it being recorded in the first place, and /export writes it back.
		if ($value < NFP_TEMPERATURE_STORABLE_MIN || $value > NFP_TEMPERATURE_STORABLE_MAX) {
			$issues[] = sprintf(
				"[%s] temperature %s cannot be stored: the column holds %.1f-%.2f C.",
				$date, $printed, NFP_TEMPERATURE_STORABLE_MIN, NFP_TEMPERATURE_STORABLE_MAX
			);
		}
		else {
			if ($value < NFP_TEMPERATURE_MIN || $value > NFP_TEMPERATURE_MAX) {
				$warnings[] = sprintf(
					"[%s] temperature %s is outside the plausible range %.1f-%.1f C. It was imported as it is.",
					$date, $printed, NFP_TEMPERATURE_MIN, NFP_TEMPERATURE_MAX
				);
			}
			// decimal(4,2): round here rather than letting MariaDB do it silently
			$day["temperature"] = round($value, 2);
			$content = true;
			$time = nfp_format_get($nfp_day, "temperatureTime");
			if (is_string($time) && trim($time) !== '') {
				// a TIME column can hold values outside a clock day (MariaDB goes to 838:59:59),
				// so this is reachable from stored data: keep the reading, drop the hour
				if (nfp_file_valid_time(trim($time))) $day["temperatureTime"] = trim($time);
				else $warnings[] = sprintf(
					"[%s] temperatureTime '%s' is not an hh:mm:ss time of day; the temperature was imported without it.",
					$date, trim($time)
				);
			}
		}
	}
	elseif (nfp_format_has($nfp_day, "temperatureTime")) {
		$ignored[] = sprintf("[%s] temperatureTime: there is no temperature on this day to time.", $date);
	}

	// --- everything this app cannot hold -------------------------------------
	foreach (NFP_DAY_FIELDS_NOT_STORED as $field => $why) {
		if (nfp_format_has($nfp_day, $field)) $ignored[] = sprintf("[%s] %s: %s.", $date, $field, $why);
	}
	foreach (nfp_format_object_keys($nfp_day) as $key) {
		if (!in_array($key, nfp_format_day_fields_known(), true)) {
			$ignored[] = sprintf("[%s] %s: not a day field of schema %s.", $date, $key, NFP_SCHEMA_VERSION);
		}
	}

	return [
		"day" => $day,
		"sensations" => $sensations,
		"observations" => $observations,
		"content" => $content,
		"issues" => $issues,
		"warnings" => $warnings,
		"ignored" => $ignored,
		"mapped" => $mapped,
	];
}

// ===========================================================================
// IMPORT -- stage 3b: what stage 4 would do, read instead of written
// ===========================================================================

/*
** The dry run's answer, in the same shape nfp_file_write_plan() returns, so one report
** describes a checked file and an imported one alike.
**
** Re-running the checks is not enough on its own: the question a user has before importing is
** "what does this file touch that I already have?", and the answer only exists in the account.
** So this resolves, by reading, the two things stage 4 would otherwise discover as it writes:
**
**   - the dates the account already holds a day on. A real run skips them (overide=0) or
**     replaces them whole (overide=1); both lists are filled the way the passed $overide
**     would have it, and daysAlreadyInAccount carries them regardless of it, since that is the
**     set the user needs in order to choose.
**   - the free-text labels the file uses: the ones the account does not have yet would be
**     created, and the ones it records under the other type keep the type they have, which is
**     the single narrowing nfp_file_resolve_description() reports.
**
** Read-only: two SELECTs, no transaction, nothing here can write.
*/
function nfp_file_preview_plan($db, int $no_user_account, array $plan, bool $overide): array {
	$already = [];
	$created = [];
	$overwritten = [];
	$skipped = [];

	if (!empty($plan)) {
		// the plan holds no duplicate date -- a file carrying one is rejected in stage 3 --
		// so one range query over its span answers every date in it
		$dates = array_column($plan, "date");
		$existing = array_flip(db_select_day_timeline_dates_frame($db, min($dates), max($dates), $no_user_account));

		foreach ($dates as $date) {
			if (!isset($existing[$date])) {
				$created[] = $date;
				continue;
			}
			$already[] = $date;
			if ($overide) $overwritten[] = $date;
			else $skipped[] = $date;
		}
	}

	// Same walk as nfp_file_write_plan(): over the days it would write (a skipped day writes no
	// labels either), sensations before observations, each name resolved once with its first
	// use deciding the type. $recorded plays the part its $cache does, holding only the type.
	$recorded = [];
	foreach (db_select_description_name_type($db, $no_user_account) as $description) {
		$recorded[$description["name"]] = intval($description["type"]);
	}

	$descriptions_created = 0;
	$narrowed = [];
	$is_skipped = array_flip($skipped);

	foreach ($plan as $entry) {
		if (isset($is_skipped[$entry["date"]])) continue;
		foreach ([DESCRIPTION_TYPE_SENSATION => $entry["sensations"], DESCRIPTION_TYPE_OBSERVATION => $entry["observations"]] as $type => $names) {
			foreach ($names as $name) {
				if (!isset($recorded[$name])) {
					$recorded[$name] = $type;
					$descriptions_created += 1;
				}
				elseif ($recorded[$name] !== $type) {
					$narrowed[] = nfp_file_narrowed_description_note($entry["date"], $name, $recorded[$name]);
				}
			}
		}
	}

	return [
		"daysCreated" => $created,
		"daysOverwritten" => $overwritten,
		"daysSkipped" => $skipped,
		"daysAlreadyInAccount" => $already,
		"descriptionsCreated" => $descriptions_created,
		"narrowed" => $narrowed,
	];
}

// ===========================================================================
// IMPORT -- stage 4: the write
// ===========================================================================

// The one narrowing a label can go through: the file uses a name under one type and the
// account already records it under the other. The label keeps the type it has, so this is a
// report and not a change. nfp_file_preview_plan() has to predict exactly what
// nfp_file_resolve_description() says, hence the shared wording.
function nfp_file_narrowed_description_note(string $date, string $name, int $recorded_type): string {
	// a label of undefined type is just called a label
	$type_name = $recorded_type === DESCRIPTION_TYPE_UNDEFINED ? "label" : (DESCRIPTION_TYPE_NAMES[$recorded_type] ?? "label");
	return sprintf(
		"[%s] '%s' is recorded as a %s on this account and stays one: a label carries a single type here.",
		$date, $name, $type_name
	);
}

// Resolves a description name to its id, creating it on first use. Same dedupe-by-name as
// api/day.php's day_resolve_description_id(), with two differences that matter when the names
// come out of a file: the lookup is an exact match (see db_select_description_exact_name), and
// results are cached, because one file commonly repeats the same handful of names on every day.
function nfp_file_resolve_description($db, int $no_user_account, string $name, int $type, string $last_write_client_UTC, array &$cache, int &$created, array &$narrowed, string $date): int {
	// The cache carries the resolved type as well as the id, so a name the file uses under
	// both types is reported on the second use even when this very import created it.
	if (!isset($cache[$name])) {
		$existing = db_select_description_exact_name($db, $no_user_account, $name);
		if (isset($existing["no_description"])) {
			// Reused whatever its recorded type: description has a unique key on
			// (no_user_account, name), so one name is one row. api/day.php does the same.
			$cache[$name] = ["id" => intval($existing["no_description"]), "type" => intval($existing["type"])];
		}
		else {
			$no_description = intval(db_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC));
			$created += 1;
			$cache[$name] = ["id" => $no_description, "type" => $type];
		}
	}

	// asking for the other type is a narrowing: the label keeps the one it has
	if ($cache[$name]["type"] !== $type) {
		$narrowed[] = nfp_file_narrowed_description_note($date, $name, $cache[$name]["type"]);
	}

	return $cache[$name]["id"];
}

/*
** Writes the plan. The caller owns the transaction, so a failure anywhere leaves the account
** untouched.
**
** $overide decides what happens on a date that already has data: false skips it and reports
** it, true replaces it. A replaced day is replaced whole, including its linked descriptions --
** same semantics as a POST to /api/day, which also carries the full state of a day.
*/
function nfp_file_write_plan($db, int $no_user_account, array $plan, bool $overide, string $last_write_client_UTC): array {
	$created = [];
	$overwritten = [];
	$skipped = [];
	$already = [];
	$descriptions_created = 0;
	$description_cache = [];
	$narrowed = [];

	foreach ($plan as $entry) {
		$date = $entry["date"];

		$existing = db_select_day_timeline($db, $date, $no_user_account);
		$exists = isset($existing["no_day"]);

		if ($exists) $already[] = $date;

		if ($exists && !$overide) {
			$skipped[] = $date;
			continue;
		}

		$no_day = $exists
			? intval($existing["no_day"])
			: intval(db_insert_day_timeline($db, $date, $no_user_account));

		db_update_day_timeline($db, $date, $no_user_account, $last_write_client_UTC, $entry["fields"]);

		$wanted = [];
		foreach ($entry["sensations"] as $name) {
			$wanted[] = nfp_file_resolve_description($db, $no_user_account, $name, DESCRIPTION_TYPE_SENSATION, $last_write_client_UTC, $description_cache, $descriptions_created, $narrowed, $date);
		}
		foreach ($entry["observations"] as $name) {
			$wanted[] = nfp_file_resolve_description($db, $no_user_account, $name, DESCRIPTION_TYPE_OBSERVATION, $last_write_client_UTC, $description_cache, $descriptions_created, $narrowed, $date);
		}
		$wanted = array_values(array_unique($wanted));

		$linked = array_map('intval', array_column(
			db_select_all_description_for_day_timeline($db, $no_user_account, $no_day), "no_description"
		));

		foreach (array_diff($linked, $wanted) as $no_description) db_delete_linked_descriptions($db, $no_day, $no_description);
		foreach (array_diff($wanted, $linked) as $no_description) db_insert_link_description_day_timeline($db, $no_day, $no_description);

		if ($exists) $overwritten[] = $date;
		else $created[] = $date;
	}

	// daysAlreadyInAccount is the union of the two lists above, and it is reported on its own
	// because it is the one that does not depend on $overide: it says which days of the file
	// the account already had, whichever way they were treated. nfp_file_preview_plan() answers
	// with the same keys, so a dry run and a real one report the same shape.
	return [
		"daysCreated" => $created,
		"daysOverwritten" => $overwritten,
		"daysSkipped" => $skipped,
		"daysAlreadyInAccount" => $already,
		"descriptionsCreated" => $descriptions_created,
		"narrowed" => $narrowed,
	];
}
