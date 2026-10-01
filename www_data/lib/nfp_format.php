<?php
/* MONCYCLE.APP
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** The NFP interchange format: its vocabulary, its hard limits, its JSON Schemas, and the
** translation of older variants of the format into the one shape the rest of the code reads.
**
** This file knows the *format*. lib/nfp_file.php knows how the format maps onto this app's
** storage. Nothing here touches the DB or the request.
**
** The reference for schema version 1.0 is the format's own structure document: a per-field
** description carrying _type, _required, _customValuesAllowed and _methodValidation (which
** fields make sense for which planning method). Two deliberate notes on reading it:
**
**  - fields marked "_customValuesAllowed": true accept values outside their listed enum, so
**    the enums below are only validated where the spec leaves no room for custom values
**    (stampColor, sexUnion, the Yes/No/Undecided triples, ...).
**  - "_methodValidation": null for a method means "this field is not part of that method",
**    which is advisory. It is reported, never used to reject a file -- see
**    nfp_file_day_from_nfp() in lib/nfp_file.php.
*/

const NFP_SCHEMA_VERSION = "1.0";
const NFP_SUPPORTED_SCHEMA_VERSIONS = ["1.0"];

// ---------------------------------------------------------------------------
// Hard limits. Everything that reaches the DB is bounded here first: these are
// the sizes day_timeline / description actually accept, plus caps that keep a
// hostile or broken file from exhausting memory or the request timeout.
// ---------------------------------------------------------------------------

const NFP_LIMIT_BODY_BYTES = 262144;        // 256K -- matches post_max_size in server_conf
const NFP_LIMIT_JSON_DEPTH = 32;            // the format nests 4 deep; 32 is already generous
const NFP_LIMIT_CYCLES = 120;               // ~10 years of cycles in one file
const NFP_LIMIT_DAYS_PER_CYCLE = 400;       // a pregnancy-length cycle still fits
const NFP_LIMIT_DAYS_TOTAL = 4000;
const NFP_LIMIT_COMMENT_CHARS = 256;        // day_timeline.comment    varchar(256)
const NFP_LIMIT_DESCRIPTION_CHARS = 256;    // description.name        varchar(256)
const NFP_LIMIT_DESCRIPTIONS_PER_DAY = 20;
const NFP_LIMIT_FC_SCORE_CHARS = 32;        // day_timeline.fc_score   varchar(32)
const NFP_LIMIT_COUNTER_START = 255;        // day_timeline.counter_start tinyint unsigned
const NFP_LIMIT_SOURCE_APP_CHARS = 255;
const NFP_TEMPERATURE_MIN = 30.0;           // day_timeline.temperature decimal(4,2) unsigned,
const NFP_TEMPERATURE_MAX = 45.0;           // narrowed to a body temperature a human can have
const NFP_DATE_FLOOR = "1900-01-01";
const NFP_FUTURE_GRACE_DAYS = 1;            // a client a timezone ahead of the server is fine

// ---------------------------------------------------------------------------
// Vocabularies. Only the closed ones (no _customValuesAllowed in the spec) are
// enforced as enums.
// ---------------------------------------------------------------------------

const NFP_METHOD_BILLINGS = "billings";
const NFP_METHOD_FERTILITY_CARE = "fertilityCare";
const NFP_METHOD_SYMPTOTHERMIC_FR = "symptothermic_fr";

// the methods this app records natively. A cycle declaring anything else still imports --
// what cannot be stored is reported field by field rather than silently dropped.
const NFP_METHODS_NATIVE = [NFP_METHOD_BILLINGS, NFP_METHOD_FERTILITY_CARE];
const NFP_METHODS_KNOWN = [NFP_METHOD_BILLINGS, NFP_METHOD_FERTILITY_CARE, NFP_METHOD_SYMPTOTHERMIC_FR];

const NFP_STAMP_COLORS = ["Green", "Red", "Yellow", "White"];
const NFP_SEX_UNIONS = ["Union", "ReservedUnion", "LastReportedUnion"];
const NFP_ARROWS = ["Up", "Down", "Right"];
const NFP_TRIPLE_CHOICE = ["Yes", "No", "Undecided"];
const NFP_END_OF_CYCLE_FOLLOW_UP = ["Pregnancy", "Menopause", "Disease", "Other"];
const NFP_CYCLE_PARTICULAR_CASES = ["FirstEyedCycle", "PostPill", "Pregnancy", "PostPartum"];

// mucusNotObserved means "the user recorded nothing for this day", so it cannot be combined
// with an observation. Straight from the spec's _incompatibleWith list, with one correction:
// it names "codifiedBloodObservation", which is not a field the format defines anywhere else
// -- "codifiedBleedingObservation" is plainly what was meant.
const NFP_MUCUS_NOT_OBSERVED_INCOMPATIBLE = [
	"codifiedMucusObservation",
	"codifiedMucusSensation",
	"codifiedBleedingObservation",
	"codifiedCervixHeight",
	"codifiedCervixConsistency",
	"freeMucusObservation",
	"freeMucusSensation",
	"booleanCervixMucus",
	"booleanCervixAperture",
];

// ---------------------------------------------------------------------------
// FertilityCare note codes.
//
// The app packs the five FertilityCare note groups into one day_timeline.fc_score
// string; DAY_FORMAT_FC_GROUPS in lib/day_format.php is the list of codes that
// round-trip through data_parse_fc_note(). The spec spells three of them
// differently, so those are aliased on the way in. Anything outside this
// vocabulary is reported as ignored rather than written: fc_score is re-parsed by
// substring matching, so an unknown code there would corrupt the day it lands on.
// ---------------------------------------------------------------------------

const NFP_FC_CODE_ALIASES = [
	"BR" => "B",      // spec writes brown bleeding "Br", the app stores "B"
	"X1" => "X1",     // spec writes "x1".."x3" lowercase; matching is case-insensitive
	"X2" => "X2",
	"X3" => "X3",
	"C/K" => "CK",    // spec's "between C and K"; the app stores the two codes together
];

function nfp_format_fc_vocabulary(): array {
	$vocabulary = DAY_FORMAT_FC_GROUPS;
	// the Lsaignement prefix is a bleeding value of its own ("L" = light/bleeding) that
	// day_format_fc_score_encode() recognises as a trailing "L"; see its comment.
	$vocabulary['codifiedBleedingObservation'][] = 'L';
	return $vocabulary;
}

// ---------------------------------------------------------------------------
// JSON Schemas, validated against the *canonical* shape -- so run
// nfp_format_normalize() first. They cover structure and types only; range,
// calendar, length and cross-field consistency checks live in lib/nfp_file.php,
// which can report the cycle and day a problem belongs to.
// ---------------------------------------------------------------------------

const NFP_FILE_SCHEMA = <<<'JSON'
{
	"type": "object",
	"required": ["schemaVersion", "fileInformation", "cycles"],
	"properties": {
		"schemaVersion": {
			"description": "Version of the schema this file claims to follow.",
			"type": "string",
			"pattern": "^[0-9]+\\.[0-9]+$"
		},
		"fileInformation": {
			"type": "object",
			"required": ["sourceApp", "sourceAppVersion", "fileCreationTimestamp"],
			"properties": {
				"sourceApp": {"type": "string", "minLength": 1, "maxLength": 255},
				"sourceAppVersion": {"type": "string", "minLength": 1, "maxLength": 64},
				"fileCreationTimestamp": {
					"description": "ISO 8601 with timezone; a bare 'YYYY-MM-DD hh:mm:ss' is also accepted.",
					"type": "string",
					"minLength": 1,
					"maxLength": 64
				}
			}
		},
		"userInformation": {
			"type": "object",
			"properties": {
				"identifier": {"type": "string", "maxLength": 255},
				"email": {"type": "string", "maxLength": 255},
				"firstName": {"type": "string", "maxLength": 255},
				"lastName": {"type": "string", "maxLength": 255},
				"birthDate": {"type": "string", "maxLength": 32},
				"comment": {"type": "string", "maxLength": 1024}
			}
		},
		"userMethodPreferences": {
			"type": "object",
			"properties": {
				"preferredMethod": {"type": "string", "maxLength": 64},
				"twoTenthShift": {"type": "boolean"},
				"oestrogenicalHollow": {"type": "boolean"},
				"irregularitiesSensibility": {"type": "boolean"}
			}
		},
		"cycles": {
			"type": "array",
			"minItems": 1,
			"maxItems": 120,
			"items": {
				"type": "object",
				"required": ["method", "cycleStartDate", "days"],
				"properties": {
					"method": {"type": "string", "minLength": 1, "maxLength": 64},
					"cycleStartDate": {"type": "string", "pattern": "^\\d{4}-\\d{2}-\\d{2}$"},
					"comment": {"type": "string", "maxLength": 1024},
					"cycleParticularCases": {
						"type": "array",
						"maxItems": 8,
						"items": {"type": "string", "enum": ["FirstEyedCycle", "PostPill", "Pregnancy", "PostPartum"]}
					},
					"currentCyclePlannedPregnancy": {"type": "string", "enum": ["Yes", "No", "Undecided"]},
					"nextMonthsPlannedPregnancy": {"type": "string", "enum": ["Yes", "No", "Undecided"]},
					"endOfCycleFollowUp": {"type": "string", "enum": ["Pregnancy", "Menopause", "Disease", "Other"]},
					"temperatureUsualTime": {"type": "string", "pattern": "^\\d{2}:\\d{2}:\\d{2}$"},
					"temperatureCaptureType": {"type": "string", "maxLength": 64},
					"temperatureThermometerType": {"type": "string", "maxLength": 64},
					"days": {
						"type": "array",
						"maxItems": 400,
						"items": {
							"type": "object",
							"properties": {
								"comments": {"type": "array", "maxItems": 32, "items": {"type": "string", "maxLength": 256}},
								"codifiedBleedingObservation": {"type": "string", "maxLength": 32},
								"nonUsualBleeding": {"type": "boolean"},
								"codifiedMucusSensation": {"type": "string", "maxLength": 32},
								"freeMucusSensation": {"type": "array", "maxItems": 20, "items": {"type": "string", "maxLength": 256}},
								"codifiedMucusObservation": {"type": "string", "maxLength": 32},
								"mucusNotObserved": {"type": "boolean"},
								"freeMucusObservation": {"type": "array", "maxItems": 20, "items": {"type": "string", "maxLength": 256}},
								"codifiedArrow": {"type": "string", "maxLength": 16},
								"codifiedNumberObservations": {"type": "string", "maxLength": 16},
								"codifiedPainObservations": {"type": "string", "maxLength": 16},
								"booleanCervixMucus": {"type": "boolean"},
								"booleanCervixAperture": {"type": "boolean"},
								"codifiedCervixConsistency": {"type": "string", "maxLength": 32},
								"codifiedCervixHeight": {"type": "string", "maxLength": 32},
								"temperature": {"type": "number"},
								"codifiedTemperatureParasitic": {"type": "string", "maxLength": 64},
								"temperatureTime": {"type": "string", "pattern": "^\\d{2}:\\d{2}:\\d{2}$"},
								"temperatureCaptureOffset": {"type": "integer", "minimum": -12, "maximum": 12},
								"booleanPregnancyDetected": {"type": "boolean"},
								"isPeak": {"type": "boolean"},
								"stampColor": {"type": "string", "enum": ["Green", "Red", "Yellow", "White"]},
								"stampBaby": {"type": "boolean"},
								"codifiedEvents": {"type": "array", "maxItems": 32, "items": {"type": "string", "maxLength": 128}},
								"babyFeedCount": {"type": "integer", "minimum": 0, "maximum": 99},
								"codifiedManualFertility": {"type": "string", "maxLength": 64},
								"counterStart": {"type": "integer", "minimum": 0, "maximum": 255},
								"sexUnion": {"type": "string", "enum": ["Union", "ReservedUnion", "LastReportedUnion"]}
							}
						}
					}
				}
			}
		}
	}
}
JSON;

// ---------------------------------------------------------------------------
// Field inventories.
//
// Every day and cycle field schema 1.0 defines, split by what this app can do
// with it. The "not stored" lists are what makes the import report honest: a
// field named here is reported back to the caller rather than silently dropped.
// A day or cycle key in neither list is reported as unknown to schema 1.0.
// ---------------------------------------------------------------------------

const NFP_DAY_FIELDS_NOT_STORED = [
	"nonUsualBleeding" => "this app has no spotting flag of its own",
	"booleanCervixMucus" => "cervix observations are not recorded here",
	"booleanCervixAperture" => "cervix observations are not recorded here",
	"codifiedCervixConsistency" => "cervix observations are not recorded here",
	"codifiedCervixHeight" => "cervix observations are not recorded here",
	"codifiedTemperatureParasitic" => "no temperature-disturbance field is recorded here",
	"temperatureCaptureOffset" => "only an absolute temperatureTime is recorded here",
	"codifiedEvents" => "no pre-defined event list is recorded here",
	"babyFeedCount" => "no feed count is recorded here",
	"codifiedManualFertility" => "fertility is computed for display, not stored",
];

const NFP_DAY_FIELDS_STORED = [
	"comments",
	"codifiedBleedingObservation",
	"codifiedMucusSensation",
	"codifiedMucusObservation",
	"codifiedNumberObservations",
	"codifiedPainObservations",
	"codifiedArrow",
	"freeMucusSensation",
	"freeMucusObservation",
	"mucusNotObserved",
	"temperature",
	"temperatureTime",
	"booleanPregnancyDetected",
	"isPeak",
	"stampColor",
	"stampBaby",
	"counterStart",
	"sexUnion",
];

const NFP_CYCLE_FIELDS_NOT_STORED = [
	"cycleParticularCases" => "no per-cycle case list is recorded here",
	"comment" => "only per-day comments are recorded here",
	"currentCyclePlannedPregnancy" => "pregnancy intention is not recorded here",
	"nextMonthsPlannedPregnancy" => "pregnancy intention is not recorded here",
	"endOfCycleFollowUp" => "no end-of-cycle reason is recorded here",
	"temperatureUsualTime" => "no usual capture time is recorded here",
	"temperatureCaptureType" => "no capture type is recorded here",
	"temperatureThermometerType" => "no thermometer type is recorded here",
];

const NFP_CYCLE_FIELDS_STRUCTURAL = ["method", "cycleStartDate", "days"];

function nfp_format_day_fields_known(): array {
	return array_merge(NFP_DAY_FIELDS_STORED, array_keys(NFP_DAY_FIELDS_NOT_STORED));
}

function nfp_format_cycle_fields_known(): array {
	return array_merge(NFP_CYCLE_FIELDS_STRUCTURAL, array_keys(NFP_CYCLE_FIELDS_NOT_STORED));
}

// ---------------------------------------------------------------------------
// Reading helpers. An explicit null is treated as an absent field throughout:
// the format marks almost everything optional, and "key present, value null" is
// what most serialisers produce for "not set".
// ---------------------------------------------------------------------------

function nfp_format_get(mixed $holder, string $key): mixed {
	if (!is_object($holder) || !property_exists($holder, $key)) return null;
	return $holder->$key;
}

function nfp_format_has(mixed $holder, string $key): bool {
	return !is_null(nfp_format_get($holder, $key));
}

// The keys actually present on a decoded object, used to report fields that are not part of
// the schema at all. Returns nothing for anything that is not an object.
function nfp_format_object_keys(mixed $holder): array {
	if (!is_object($holder)) return [];
	return array_keys(get_object_vars($holder));
}

// true for a stdClass, and for the empty array json_decode() hands back for "{}" when a
// caller decoded associatively. A populated list is not an object.
function nfp_format_is_object(mixed $value): bool {
	return is_object($value) || (is_array($value) && empty($value));
}

// ---------------------------------------------------------------------------
// Legacy -> canonical.
//
// Three shapes of the format are in circulation, all stamped schemaVersion 1.0:
//
//   - the current one, described by the structure document: nested
//     fileInformation / userInformation objects, "preferredMethod", a per-day
//     "comments" array.
//   - an early draft: flat "source_app" / "source_app_version" /
//     "file_creation_timestamp", "userInformation" as a positional array,
//     "preferedMethod" (one r), a per-day "comment" string, "dayNotObserved".
//   - what moncycle.app 14 and early 15dev actually exported: the early draft,
//     plus freeMucusSensation / freeMucusObservation as comma-joined strings
//     rather than arrays, and sexUnion as a boolean rather than its enum.
//
// Users hold files of all three, so all three import. This function only moves
// and re-types values; it never validates. Anything it cannot make sense of is
// left where it is for the schema to reject with a clear message.
// ---------------------------------------------------------------------------

function nfp_format_normalize(object $raw): object {
	$file = clone $raw;

	// flat source_app/* -> fileInformation{}
	$information = nfp_format_get($file, "fileInformation");
	if (!nfp_format_is_object($information)) $information = new stdClass();
	else $information = clone $information;

	$legacy_file_keys = [
		"source_app" => "sourceApp",
		"source_app_version" => "sourceAppVersion",
		"file_creation_timestamp" => "fileCreationTimestamp",
	];
	foreach ($legacy_file_keys as $legacy => $canonical) {
		if (!nfp_format_has($information, $canonical) && nfp_format_has($file, $legacy)) {
			$information->$canonical = $file->$legacy;
		}
		unset($file->$legacy);
	}
	// the early draft's bare "YYYY-MM-DD hh:mm:ss" -> ISO 8601
	$timestamp = nfp_format_get($information, "fileCreationTimestamp");
	if (is_string($timestamp) && preg_match('/^\s*(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})\s*$/', $timestamp, $m)) {
		$information->fileCreationTimestamp = $m[1] . 'T' . $m[2] . date('P');
	}
	$file->fileInformation = $information;

	// userInformation as [identifier, email, firstName, lastName, birthDate] -> object
	$user = nfp_format_get($file, "userInformation");
	if (is_array($user)) {
		$positional = ["identifier", "email", "firstName", "lastName", "birthDate"];
		$rebuilt = new stdClass();
		foreach (array_values($user) as $index => $value) {
			if (!isset($positional[$index]) || is_null($value)) continue;
			if (is_scalar($value)) $rebuilt->{$positional[$index]} = (string) $value;
		}
		$file->userInformation = $rebuilt;
	}

	// "preferedMethod" (one r) -> "preferredMethod"
	$preferences = nfp_format_get($file, "userMethodPreferences");
	if (is_object($preferences)) {
		$preferences = clone $preferences;
		if (!nfp_format_has($preferences, "preferredMethod") && nfp_format_has($preferences, "preferedMethod")) {
			$preferences->preferredMethod = $preferences->preferedMethod;
		}
		unset($preferences->preferedMethod);
		$file->userMethodPreferences = $preferences;
	}

	$cycles = nfp_format_get($file, "cycles");
	if (is_array($cycles)) {
		$file->cycles = array_map('nfp_format_normalize_cycle', $cycles);
	}

	return $file;
}

function nfp_format_normalize_cycle(mixed $raw_cycle): mixed {
	if (!is_object($raw_cycle)) return $raw_cycle;
	$cycle = clone $raw_cycle;

	// the method name is matched case-insensitively everywhere else, so settle its casing once
	$method = nfp_format_get($cycle, "method");
	if (is_string($method)) {
		$trimmed = trim($method);
		foreach (NFP_METHODS_KNOWN as $known) {
			if (strcasecmp($trimmed, $known) === 0) $trimmed = $known;
		}
		$cycle->method = $trimmed;
	}

	$start_date = nfp_format_get($cycle, "cycleStartDate");
	if (is_string($start_date)) $cycle->cycleStartDate = trim($start_date);

	$days = nfp_format_get($cycle, "days");
	if (is_array($days)) $cycle->days = array_map('nfp_format_normalize_day', $days);

	return $cycle;
}

function nfp_format_normalize_day(mixed $raw_day): mixed {
	if (is_array($raw_day) && empty($raw_day)) return new stdClass();   // "{}" decoded associatively
	if (!is_object($raw_day)) return $raw_day;
	$day = clone $raw_day;

	// "dayNotObserved" -> "mucusNotObserved"
	if (!nfp_format_has($day, "mucusNotObserved") && nfp_format_has($day, "dayNotObserved")) {
		$day->mucusNotObserved = $day->dayNotObserved;
	}
	unset($day->dayNotObserved);

	// a single "comment" string -> the "comments" array
	if (!nfp_format_has($day, "comments") && nfp_format_has($day, "comment")) {
		$comment = $day->comment;
		if (is_scalar($comment) && trim((string) $comment) !== '') $day->comments = [(string) $comment];
	}
	unset($day->comment);

	// moncycle.app 14 wrote these two as comma-joined strings, against its own schema
	foreach (["freeMucusSensation", "freeMucusObservation"] as $field) {
		$value = nfp_format_get($day, $field);
		if (is_string($value)) {
			$parts = array_filter(array_map('trim', explode(",", $value)), fn($part) => $part !== '');
			$day->$field = array_values($parts);
		}
	}

	// moncycle.app 14 wrote sexUnion as a boolean; the format wants its enum
	$union = nfp_format_get($day, "sexUnion");
	if (is_bool($union)) {
		if ($union) $day->sexUnion = "Union";
		else unset($day->sexUnion);
	}
	elseif (is_string($union)) {
		$trimmed = trim($union);
		foreach (NFP_SEX_UNIONS as $known) {
			if (strcasecmp($trimmed, $known) === 0) $trimmed = $known;
		}
		$day->sexUnion = $trimmed;
	}

	// the early day schema typed these as the strings "true"/"false"
	foreach (["mucusNotObserved", "stampBaby", "isPeak", "booleanPregnancyDetected",
	          "nonUsualBleeding", "booleanCervixMucus", "booleanCervixAperture"] as $field) {
		$value = nfp_format_get($day, $field);
		if (!is_string($value)) continue;
		$trimmed = strtolower(trim($value));
		if ($trimmed === "true") $day->$field = true;
		elseif ($trimmed === "false") $day->$field = false;
	}

	// ... and counterStart as a string
	$counter = nfp_format_get($day, "counterStart");
	if (is_string($counter) && preg_match('/^\s*\d{1,3}\s*$/', $counter)) $day->counterStart = intval($counter);

	// a temperature sent as "36.45"
	$temperature = nfp_format_get($day, "temperature");
	if (is_string($temperature) && is_numeric(trim($temperature))) $day->temperature = floatval(trim($temperature));

	// stampColor / codifiedArrow / enum-ish strings: settle casing, trim
	$colour = nfp_format_get($day, "stampColor");
	if (is_string($colour)) {
		$trimmed = trim($colour);
		foreach (NFP_STAMP_COLORS as $known) {
			if (strcasecmp($trimmed, $known) === 0) $trimmed = $known;
		}
		$day->stampColor = $trimmed;
	}
	$arrow = nfp_format_get($day, "codifiedArrow");
	if (is_string($arrow)) {
		$trimmed = trim($arrow);
		foreach (NFP_ARROWS as $known) {
			if (strcasecmp($trimmed, $known) === 0) $trimmed = $known;
		}
		$day->codifiedArrow = $trimmed;
	}

	$time = nfp_format_get($day, "temperatureTime");
	if (is_string($time)) $day->temperatureTime = trim($time);

	return $day;
}
