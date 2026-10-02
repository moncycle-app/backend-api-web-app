<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// Translates between the day_timeline DB row (packed stamp/fc_score/fc_arrow codes -- see
// data_parse_fc_note() in lib/data.php) and the structured JSON "day" shape the API now
// speaks, inspired by the NFP file format's per-day vocabulary (stampColor, isPeak,
// codifiedArrow, ...). This file is the only place that should know about the packed
// encoding; api/day.php and api/sync.php only ever see the JSON shape.
//
// Deliberately independent from lib/nfp_file.php / lib/nfp_format.php (the NFP file
// import/export pipeline): that pipeline is out of scope for this pass and is left untouched.

require_once __DIR__ . "/data.php";

// ---------------------------------------------------------------------------
// stamp: DB stores "" | "R" | "G" | "Y" | "BB" | "RBB" | "GBB" | "YBB".
// API speaks stampColor (Red|Green|Yellow|White|null) + stampBaby (bool).
// ---------------------------------------------------------------------------

function day_format_stamp_decode(?string $stamp): array {
	$stamp = $stamp ?? '';
	$color = null;
	if (str_contains($stamp, 'R')) $color = 'Red';
	elseif (str_contains($stamp, 'G')) $color = 'Green';
	elseif (str_contains($stamp, 'Y')) $color = 'Yellow';
	$baby = str_contains($stamp, 'BB');
	if ($baby && is_null($color)) $color = 'White';
	return ['color' => $color, 'baby' => $baby];
}

function day_format_stamp_encode(?string $color, bool $baby): string {
	$code = match ($color) {
		'Red' => 'R',
		'Green' => 'G',
		'Yellow' => 'Y',
		default => '', // null or 'White' carry no color code of their own
	};
	return $code . ($baby ? 'BB' : '');
}

// ---------------------------------------------------------------------------
// fc_arrow: DB stores the raw Unicode glyph. API speaks codifiedArrow
// (Up|Down|Right|null), matching structure.json's FertilityCare vocabulary.
// ---------------------------------------------------------------------------

function day_format_arrow_decode(?string $arrow): ?string {
	return match ($arrow) {
		"\u{2191}" => 'Up',
		"\u{2193}" => 'Down',
		"\u{2192}" => 'Right',
		default => null,
	};
}

function day_format_arrow_encode(?string $codified): ?string {
	return match ($codified) {
		'Up' => "\u{2191}",
		'Down' => "\u{2193}",
		'Right' => "\u{2192}",
		default => null,
	};
}

// ---------------------------------------------------------------------------
// fc_score: DB stores one packed, order-sensitive string covering five
// independent FertilityCare note groups (see data_parse_fc_note()). The API
// speaks one nullable string per group instead.
//
// Encoding joins the five group values with a space. That space isn't part
// of the legacy manual-entry notation, but data_parse_fc_note() only ever
// looks for known substrings and ignores anything else, so old values already
// in the DB still decode the same way -- the space just stops two adjacent
// groups from accidentally spelling a third code (a mucus sensation ending
// "...WL" glued directly to a pain code starting "AP..." would otherwise
// read back as the unrelated code "LAP").
//
// The codes of each group are DAY_FORMAT_FC_GROUPS (constants.php).
// ---------------------------------------------------------------------------

function day_format_fc_score_decode(?string $fc_score): array {
	$note = data_parse_fc_note($fc_score);

	$bleeding = '';
	foreach (DAY_FORMAT_FC_GROUPS['codifiedBleedingObservation'] as $code) if ($note[$code]) $bleeding .= $code;
	if ($note['Lsaignement']) $bleeding .= 'L';

	$decoded = ['codifiedBleedingObservation' => $bleeding !== '' ? $bleeding : null];
	foreach (DAY_FORMAT_FC_GROUPS as $field => $codes) {
		if ($field === 'codifiedBleedingObservation') continue;
		$value = '';
		foreach ($codes as $code) if ($note[$code]) $value .= $code;
		$decoded[$field] = $value !== '' ? $value : null;
	}
	return $decoded;
}

function day_format_fc_score_encode(array $codified): string {
	// day_format_fc_score_decode() represents Lsaignement (spotting) as a trailing "L" on
	// codifiedBleedingObservation (e.g. decoding "LB" produces bleeding "BL"). But "VL"
	// ("Very Light") is itself a whole, legitimate bleeding code that also happens to end
	// in "L" -- so a trailing "L" only means spotting when what's left after removing it
	// is empty or one of the other real codes, never when the value already *is* one of
	// them. data_parse_fc_note() only ever recognises Lsaignement as a *leading* "L" on
	// the whole fc_score string, so it has to be moved back to the front here, or it
	// silently turns into the unrelated standalone "L" mucus-observation flag instead.
	$bleeding_codes = DAY_FORMAT_FC_GROUPS['codifiedBleedingObservation'];
	$bleeding = trim((string) ($codified['codifiedBleedingObservation'] ?? ''));
	$spotting = false;
	if ($bleeding !== '' && !in_array($bleeding, $bleeding_codes, true) && str_ends_with($bleeding, 'L')) {
		$candidate = substr($bleeding, 0, -1);
		if ($candidate === '' || in_array($candidate, $bleeding_codes, true)) {
			$spotting = true;
			$bleeding = $candidate;
		}
	}

	$parts = [];
	if ($bleeding !== '') $parts[] = $bleeding;
	foreach (array_keys(DAY_FORMAT_FC_GROUPS) as $field) {
		if ($field === 'codifiedBleedingObservation') continue;
		$value = trim((string) ($codified[$field] ?? ''));
		if ($value !== '') $parts[] = $value;
	}

	if (empty($parts) && !$spotting) return '';

	$joined = implode(' ', $parts);

	if ($spotting) return 'L' . ($joined !== '' ? ' ' . $joined : '');

	// guard: if bleeding didn't ask for Lsaignement but a *different* group's value
	// happens to start with "L" (the standalone mucus-observation code, or a mucus
	// sensation code composed alone) and lands first, a leading space keeps it from being
	// misread as Lsaignement instead.
	if (str_starts_with($joined, 'L') && !str_starts_with($joined, 'LAP')) $joined = ' ' . $joined;

	return $joined;
}

// ---------------------------------------------------------------------------
// Whole-day object: the combined array produced by data_construnct_day()
// (lib/data.php) <-> the API's JSON day shape.
// ---------------------------------------------------------------------------

function day_to_json(array $day): array {
	$stamp = day_format_stamp_decode($day['stamp'] ?? null);

	$free_sensation = [];
	$free_observation = [];
	foreach ($day['description'] ?? [] as $desc) {
		if (intval($desc['type']) === DESCRIPTION_TYPE_SENSATION) $free_sensation[] = $desc['name'];
		elseif (intval($desc['type']) === DESCRIPTION_TYPE_OBSERVATION) $free_observation[] = $desc['name'];
	}

	$temperature = $day['temperature'] ?? null;

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
		'freeMucusSensation' => $free_sensation,
		'freeMucusObservation' => $free_observation,
		'temperature' => !empty($temperature) ? floatval($temperature) : null,
		'temperatureTime' => $day['time_temp_taken'] ?? null,
		'codifiedArrow' => day_format_arrow_decode($day['fc_arrow'] ?? null),
		'comment' => $day['comment'] ?? '',
		'lastWriteClientUtc' => http_iso8601($day['last_write_client_UTC'] ?? null),
		'lastWriteDb' => http_iso8601($day['last_write_db'] ?? null),
	];

	return array_merge($json, day_format_fc_score_decode($day['fc_score'] ?? null));
}

// Builds the positional values db_update_day_timeline() expects from the JSON day shape.
// Matches the existing upsert semantics of api/day.php: a POST is expected to carry the full
// current state of the day, not a sparse patch, so an omitted field is written as empty/false,
// same as before this change. This only translates encoding -- it doesn't touch the DB and
// doesn't resolve freeMucusSensation/freeMucusObservation names to description ids (that needs
// $db/$no_user_account, so it stays in api/day.php).
function day_from_json(array $json): array {
	$stamp = day_format_stamp_encode($json['stampColor'] ?? null, boolval($json['stampBaby'] ?? false));
	$fc_score = day_format_fc_score_encode($json);
	$fc_arrow = day_format_arrow_encode($json['codifiedArrow'] ?? null);

	$temp = null;
	$htemp = null;
	if (isset($json['temperature']) && floatval($json['temperature']) > 0) {
		$temp = floatval($json['temperature']);
		if (!empty($json['temperatureTime'])) $htemp = trim($json['temperatureTime']);
	}

	$counter_start = null;
	if (isset($json['counterStart']) && intval($json['counterStart']) > 0) $counter_start = intval($json['counterStart']);

	return [
		'stamp' => $stamp,
		'fc_score' => $fc_score !== '' ? $fc_score : null,
		'fc_arrow' => $fc_arrow,
		'temp' => $temp,
		'htemp' => $htemp,
		'is_peak' => boolval($json['isPeak'] ?? false),
		'union_sex' => boolval($json['sexUnion'] ?? false),
		'cycle_1st_day' => boolval($json['cycleFirstDay'] ?? false),
		'day_not_observed' => boolval($json['dayNotObserved'] ?? false),
		'pregnancy' => boolval($json['booleanPregnancyDetected'] ?? false),
		'comment' => $json['comment'] ?? null,
		'counter_start' => $counter_start,
	];
}
