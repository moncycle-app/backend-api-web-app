<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// A day in its three shapes, and the translation between them:
//
//   - the DB row of day_timeline, with the packed columns (stamp, fc_score, fc_arrow);
//   - the structured JSON "day" the API speaks, on the vocabulary of the NFP file format
//     (stampColor, isPeak, codifiedArrow, ...): api/day.php, api/sync.php;
//   - the NFP day (lib/nfp_export.php, lib/nfp_import.php), which is this JSON day with a few fields renamed, and which is
//     read into it before it is stored.
//
// This file is the only place that knows the packed encoding, and the rules a day has to follow
// to be stored: the API and the NFP import both come through them.

require_once __DIR__ . "/http.php";

// ---------------------------------------------------------------------------
// stamp: the DB holds "" | "R" | "G" | "Y" | "BB" | "RBB" | "GBB" | "YBB"; the API speaks
// stampColor (Red|Green|Yellow|White|null) and stampBaby (bool). See DAY_STAMP_COLOR_CODES.
// ---------------------------------------------------------------------------

function day_format_stamp_decode(?string $stamp): array {
	$stamp = $stamp ?? '';
	$color = null;
	foreach (DAY_STAMP_COLOR_CODES as $name => $code) {
		if (str_contains($stamp, $code)) {
			$color = $name;
			break;
		}
	}
	$baby = str_contains($stamp, 'BB');
	return ['color' => $color ?? ($baby ? 'White' : null), 'baby' => $baby];
}

// (White has no letter of its own, and no colour at all is the same: nothing)
function day_format_stamp_encode(?string $color, bool $baby): string {
	return (DAY_STAMP_COLOR_CODES[$color] ?? '') . ($baby ? 'BB' : '');
}

// ---------------------------------------------------------------------------
// fc_arrow: the DB holds the glyph, the API speaks codifiedArrow (Up|Down|Right|null)
// ---------------------------------------------------------------------------

function day_format_arrow_decode(?string $arrow): ?string {
	return array_search($arrow, DAY_ARROW_GLYPHS, true) ?: null;
}

function day_format_arrow_encode(?string $codified): ?string {
	return DAY_ARROW_GLYPHS[$codified] ?? null;
}

// ---------------------------------------------------------------------------
// fc_score: one packed, order-sensitive string covering the five independent FertilityCare
// note groups (DAY_FORMAT_FC_GROUPS); the API speaks one nullable string per group instead.
//
// Encoding joins the five values with a space. That space isn't part of the legacy manual-entry
// notation, but decoding only looks for known codes and ignores the rest, so old values decode
// the same way -- the space just stops two adjacent groups from spelling a third code (a mucus
// sensation ending "...WL" glued to a pain code starting "AP..." would read back as "LAP").
// ---------------------------------------------------------------------------

function day_format_fc_score_decode(?string $fc_score): array {
	$rest = strtoupper(trim((string) $fc_score));

	// a leading "L" (not the start of "LAP") is Lsaignement, the spotting of the bleeding group
	$spotting = str_starts_with($rest, 'L') && !str_starts_with($rest, 'LAP');
	if ($spotting) $rest = substr($rest, 1);

	$found = [];
	foreach (DAY_FORMAT_FC_PARSE_ORDER as $code) {
		if (!str_contains($rest, $code)) continue;
		$found[$code] = true;
		$rest = str_replace($code, '', $rest);
	}

	$decoded = [];
	foreach (DAY_FORMAT_FC_GROUPS as $field => $codes) {
		$value = implode('', array_filter($codes, fn($code) => isset($found[$code])));
		if ($field === 'codifiedBleedingObservation' && $spotting) $value .= 'L';
		$decoded[$field] = $value !== '' ? $value : null;
	}
	return $decoded;
}

function day_format_fc_score_encode(array $codified): string {
	$bleeding_codes = DAY_FORMAT_FC_GROUPS['codifiedBleedingObservation'];
	$bleeding = trim((string) ($codified['codifiedBleedingObservation'] ?? ''));

	// Decoding shows the spotting as a trailing "L" on the bleeding ("LB" reads "BL"), but the
	// legacy notation only knows it as a *leading* "L" of the whole string, so it is moved back
	// to the front. A trailing "L" only means spotting when what is left without it is empty or
	// a real bleeding code: "VL" (very light) is a code of its own that happens to end in "L".
	$spotting = false;
	if ($bleeding !== '' && !in_array($bleeding, $bleeding_codes, true) && str_ends_with($bleeding, 'L')) {
		$without = substr($bleeding, 0, -1);
		if ($without === '' || in_array($without, $bleeding_codes, true)) {
			$spotting = true;
			$bleeding = $without;
		}
	}

	$parts = $bleeding !== '' ? [$bleeding] : [];
	foreach (array_slice(array_keys(DAY_FORMAT_FC_GROUPS), 1) as $field) {
		$value = trim((string) ($codified[$field] ?? ''));
		if ($value !== '') $parts[] = $value;
	}

	$joined = implode(' ', $parts);
	if ($spotting) return 'L' . ($joined !== '' ? ' ' . $joined : '');

	// no spotting asked, but another group's value starts with "L" and lands first: the leading
	// space keeps it from being read as Lsaignement
	if (str_starts_with($joined, 'L') && !str_starts_with($joined, 'LAP')) $joined = ' ' . $joined;
	return $joined;
}

// A note value onto the codes decoding can read back, or null when it is outside the vocabulary of its
// group: fc_score is read back by looking for codes, so an unknown one stored there would corrupt the
// day it lands on. The NFP spelling of three codes is accepted (NFP_FC_CODE_ALIASES), and a value
// can be several codes of its group in a row ("CK"), longest code first so "10DL" is not "10" + junk.
function day_format_fc_canonical(string $field, string $value): ?string {
	$codes = DAY_FORMAT_FC_GROUPS[$field] ?? [];
	if ($field === 'codifiedBleedingObservation') $codes[] = 'L';   // the spotting, as decoding shows it
	usort($codes, fn($a, $b) => strlen($b) <=> strlen($a));

	$rest = strtoupper(trim($value));
	if ($rest === '') return null;
	$rest = NFP_FC_CODE_ALIASES[$rest] ?? $rest;

	$canonical = '';
	while ($rest !== '') {
		$code = current(array_filter($codes, fn($code) => str_starts_with($rest, $code)));
		if ($code === false) return null;
		$canonical .= $code;
		$rest = substr($rest, strlen($code));
	}
	return $canonical;
}

// ---------------------------------------------------------------------------
// Small things a stored value has to follow
// ---------------------------------------------------------------------------

// Free text from a client or a file: control characters out (they serve no purpose and make the CSV
// unreadable), line breaks and tabs kept because a comment legitimately has them.
function day_format_clean_text(string $text): string {
	$text = str_replace(["\r\n", "\r"], "\n", $text);
	// C0 and C1 controls, and the Unicode line/paragraph separators, except \n and \t
	$text = preg_replace('/[^\P{C}\n\t]+/u', '', $text);
	return is_null($text) ? '' : trim($text);   // (invalid UTF-8 makes preg_replace fail)
}

// A time of day as the DB takes it ("hh:mm" or "hh:mm:ss"), as "hh:mm:ss"; null if it is not one.
function day_format_time(string $time): ?string {
	if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', trim($time), $m)) return null;
	$seconds = intval($m[3] ?? 0);
	return intval($m[1]) <= 23 && intval($m[2]) <= 59 && $seconds <= 59 ? sprintf("%s:%s:%02d", $m[1], $m[2], $seconds) : null;
}

// Free text a client sends for a varchar($max_chars) column (a label, a name): [the text cleaned as
// day_format_clean_text() does, null], or [null, why it cannot be stored]. $field names it for the client.
function day_format_text(mixed $value, string $field, int $max_chars): array {
	if (!is_string($value)) return [null, "'$field' must be a string."];

	$text = day_format_clean_text($value);
	$length = mb_strlen($text);
	if ($length > $max_chars) return [null, "'$field' is $length characters; the limit is $max_chars."];
	return [$text, null];
}

// true when a field carries a value: "0" is a real FertilityCare code (dryness) and falsy in PHP,
// so empty() cannot tell. A list is a value too (a wrong one: the validation says so), and is not
// cast to a string.
function day_format_filled(array $day, string $field): bool {
	return isset($day[$field]) && (!is_scalar($day[$field]) || (string) $day[$field] !== '');
}

// ---------------------------------------------------------------------------
// Checking what a client sends
// ---------------------------------------------------------------------------

// A day posted in the JSON shape, checked against what can be stored and cleaned for
// day_format_from_json(): [the day, the problems]. The problems are messages for the client; with
// any, the day is not to be stored. A field left out is not a problem: a day is the full state of
// the day, and what is absent is empty.
function day_format_validate(array $day): array {
	$problems = [];

	foreach (['stampColor' => NFP_STAMP_COLORS, 'codifiedArrow' => NFP_ARROWS] as $field => $vocabulary) {
		if (isset($day[$field]) && !in_array($day[$field], $vocabulary, true)) {
			$problems[] = "'$field' must be one of " . implode(", ", $vocabulary) . ", or null.";
		}
	}

	foreach (array_keys(DAY_FORMAT_FC_GROUPS) as $field) {
		if (!day_format_filled($day, $field)) continue;
		$canonical = is_string($day[$field]) ? day_format_fc_canonical($field, $day[$field]) : null;
		if (is_null($canonical)) $problems[] = "'$field' holds a code outside the FertilityCare notation.";
		else $day[$field] = $canonical;
	}
	if (empty($problems)) {
		$packed = mb_strlen(day_format_fc_score_encode($day));
		if ($packed > DAY_LIMIT_FC_SCORE_CHARS) $problems[] = "The FertilityCare notes take $packed characters packed; the limit is " . DAY_LIMIT_FC_SCORE_CHARS . ".";
	}

	if (isset($day['comment'])) {
		if (!is_string($day['comment'])) $problems[] = "'comment' must be a string.";
		else {
			$day['comment'] = day_format_clean_text($day['comment']);
			if (mb_strlen($day['comment']) > DAY_LIMIT_COMMENT_CHARS) $problems[] = "'comment' is " . mb_strlen($day['comment']) . " characters; the limit is " . DAY_LIMIT_COMMENT_CHARS . ".";
		}
	}

	foreach (['freeMucusSensation', 'freeMucusObservation', 'freeOther'] as $field) {
		if (!isset($day[$field])) continue;
		if (!is_array($day[$field]) || count(array_filter($day[$field], 'is_string')) !== count($day[$field])) {
			$problems[] = "'$field' must be a list of strings.";
			continue;
		}
		$names = array_filter(array_map('day_format_clean_text', $day[$field]), fn($name) => $name !== '');
		foreach ($names as $name) {
			if (mb_strlen($name) > DAY_LIMIT_DESCRIPTION_CHARS) $problems[] = "A '$field' entry is " . mb_strlen($name) . " characters; the limit is " . DAY_LIMIT_DESCRIPTION_CHARS . ".";
		}
		$day[$field] = array_values(array_unique($names));
	}

	if (!empty($day['temperature'])) {
		if (!is_numeric($day['temperature']) || $day['temperature'] < DAY_TEMPERATURE_STORABLE_MIN || $day['temperature'] > DAY_TEMPERATURE_STORABLE_MAX) {
			$problems[] = "'temperature' must be a number from " . DAY_TEMPERATURE_STORABLE_MIN . " to " . DAY_TEMPERATURE_STORABLE_MAX . ".";
		}
		elseif (!empty($day['temperatureTime'])) {
			$time = is_string($day['temperatureTime']) ? day_format_time($day['temperatureTime']) : null;
			if (is_null($time)) $problems[] = "'temperatureTime' must be a time of day, hh:mm or hh:mm:ss.";
			else $day['temperatureTime'] = $time;
		}
	}

	if (!empty($day['counterStart']) && (!is_numeric($day['counterStart']) || $day['counterStart'] < 0 || $day['counterStart'] > DAY_LIMIT_COUNTER_START)) {
		$problems[] = "'counterStart' must be a whole number from 0 to " . DAY_LIMIT_COUNTER_START . ".";
	}

	return [$day, $problems];
}

// ---------------------------------------------------------------------------
// The whole day: the array data_construct_day() (lib/data.php) builds <-> the JSON day
// ---------------------------------------------------------------------------

function day_format_to_json(array $day): array {
	$stamp = day_format_stamp_decode($day['stamp'] ?? null);

	$descriptions = [DESCRIPTION_TYPE_SENSATION => [], DESCRIPTION_TYPE_OBSERVATION => [], DESCRIPTION_TYPE_UNDEFINED => []];
	foreach ($day['description'] ?? [] as $description) {
		$descriptions[intval($description['type'])][] = $description['name'];
	}

	$json = [
		'date' => $day['date_obs'],
		'cycleStartDate' => $day['cycle'] ?? null,
		'cycleDay' => isset($day['pos']) ? intval($day['pos']) : null,
		'cycleFirstDay' => boolval($day['cycle_1st_day'] ?? false),
		'dayNotObserved' => boolval($day['day_not_observed'] ?? false),
		'stampColor' => $stamp['color'],
		'stampBaby' => $stamp['baby'],
		'isPeak' => boolval($day['is_peak'] ?? false),
		'counterStart' => !empty($day['counter_start']) ? intval($day['counter_start']) : null,
		'sexUnion' => boolval($day['union_sex'] ?? false),
		'booleanPregnancyDetected' => boolval($day['pregnancy'] ?? false),
		'freeMucusSensation' => $descriptions[DESCRIPTION_TYPE_SENSATION],
		'freeMucusObservation' => $descriptions[DESCRIPTION_TYPE_OBSERVATION],
		'freeOther' => $descriptions[DESCRIPTION_TYPE_UNDEFINED],
		'temperature' => !empty($day['temperature']) ? floatval($day['temperature']) : null,
		'temperatureTime' => $day['time_temp_taken'] ?? null,
		'codifiedArrow' => day_format_arrow_decode($day['fc_arrow'] ?? null),
		'comment' => $day['comment'] ?? '',
		'lastWriteClientUtc' => http_iso8601($day['last_write_client_UTC'] ?? null),
		'lastWriteDb' => http_iso8601($day['last_write_db'] ?? null),
	];

	return array_merge($json, day_format_fc_score_decode($day['fc_score'] ?? null));
}

// The fields db_update_day_timeline() takes, from the JSON day. A POST carries the full state of the
// day, not a patch: a field left out is written empty. Only the encoding is translated here; the
// descriptions are names, resolved to ids against the DB by lib/data.php.
function day_format_from_json(array $json): array {
	$fc_score = day_format_fc_score_encode($json);
	$has_temperature = isset($json['temperature']) && floatval($json['temperature']) > 0;

	return [
		'stamp' => day_format_stamp_encode($json['stampColor'] ?? null, boolval($json['stampBaby'] ?? false)),
		'fc_score' => $fc_score !== '' ? $fc_score : null,
		'fc_arrow' => day_format_arrow_encode($json['codifiedArrow'] ?? null),
		'temp' => $has_temperature ? floatval($json['temperature']) : null,
		'htemp' => $has_temperature && !empty($json['temperatureTime']) ? trim($json['temperatureTime']) : null,
		'is_peak' => boolval($json['isPeak'] ?? false),
		'union_sex' => boolval($json['sexUnion'] ?? false),
		'cycle_1st_day' => boolval($json['cycleFirstDay'] ?? false),
		'day_not_observed' => boolval($json['dayNotObserved'] ?? false),
		'pregnancy' => boolval($json['booleanPregnancyDetected'] ?? false),
		'comment' => $json['comment'] ?? null,
		'counter_start' => isset($json['counterStart']) && intval($json['counterStart']) > 0 ? intval($json['counterStart']) : null,
	];
}
