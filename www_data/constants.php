<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** The internal constants of the app: DB codes, API vocabularies, limits, export layouts. None of
** them is a deployment setting.
**
** Loaded by config.php (Docker and local) and by config.exemple.php, so there is one copy
** whatever the deployment. What an operator may change (DB, SMTP, toggles, login throttling)
** is in those files. PHP has no typed global constants: each value is written as the type it
** has (int, float, bool, string, array), and the settings read from the environment are cast
** in config.php.
**
** The JSON Schema (NFP_FILE_SCHEMA in lib/nfp_format.php) and the SQL fragment
** (DB_SQL_USER_ACCOUNT_LAST_ACTIVITY in lib/db.php) stay next to the code that runs them.
*/

// ===========================================================================
// ACCOUNT -- two-factor authentication, data retention
// ===========================================================================

// user_account.totp_state: where the account is in the two-factor authentication lifecycle.
const TOTP_STATE_NEVER_USED = 0;    // 2FA was never set up
const TOTP_STATE_DISABLED = 1;      // was set up, then turned off by the user
const TOTP_STATE_INIT = 2;          // secret generated and shown, first code not confirmed yet
const TOTP_STATE_ACTIVE = 3;        // confirmed: login asks for a code

// state => the string the JSON API speaks instead of the raw 0-3 DB int (sec_totp_state_name()).
const TOTP_STATE_NAMES = [
	TOTP_STATE_NEVER_USED => "never_used",
	TOTP_STATE_DISABLED => "disabled",
	TOTP_STATE_INIT => "init",
	TOTP_STATE_ACTIVE => "active",
];

// The cookie that carries the session token (an Authorization: Bearer header carries the same one),
// and the name it had before, which is still read for a while.
const COOKIE_AUTH_TOKEN = "MONCYCLEAPP_TOKEN";
const COOKIE_AUTH_TOKEN_LEGACY = "MONCYCLEAPP_JETTON";

// The values the COOKIE_SAMESITE setting takes (the attribute is these with a capital: Strict, Lax, None).
const COOKIE_SAMESITE_VALUES = ["strict", "lax", "none"];

const PASSWORD_MIN_LENGTH = 8;
// The hash of a password nobody knows (random, then forgotten), at the cost password_hash() uses. A login for an
// address that has no account is checked against it, so that it takes as long as a wrong password does.
const AUTH_DUMMY_PASSWORD_HASH = '$2y$12$fFAyg7vWBJagg2QJOymLY.Ndi.VRQtAJhChoNUW7XiAxek8JDKUju';
const ACCOUNT_DEFAULT_LANGUAGE = "FR";    // user_account.language of a new account, until the user can choose
const ACCOUNT_DEMO_ID = 2;      // the public demo account: never warned, never erased (see lib/db.php)

// What user_account and auth_token can hold: the widths of their columns, and the birth years a person can give.
const ACCOUNT_LIMIT_NAME_CHARS = 255;               // user_account.name varchar(255)
const ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS = 255;   // user_account.register_comment varchar(255)
const ACCOUNT_BIRTH_YEAR_MAX_AGE = 100;             // this year, or up to this many years back (user_account.age is a smallint)
const AUTH_TOKEN_USER_AGENT_BYTES = 200;            // auth_token.name varchar(256): "AUTH | " then the user agent, cut to this

// RGPD retention, re-evaluated on every cron run (so no "warning sent" flag exists): an account
// with no activity (login, or any write tied to it) for this many years is erased...
const ACCOUNT_INACTIVITY_DELETE_YEARS = 4;
// ... and gets a warning email this many days before that deletion.
const ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE = 10;

// ===========================================================================
// NFP METHODS -- what an account follows, and how it is stored and exposed
// ===========================================================================

// The method as the NFP format and the JSON API name it.
const NFP_METHOD_BILLINGS = "billings";
const NFP_METHOD_FERTILITY_CARE = "fertilityCare";
const NFP_METHOD_SYMPTOTHERMIC_FR = "symptothermic_fr";

// The methods this app records natively: a cycle declaring another one still imports, and what
// cannot be stored is reported field by field. KNOWN adds the ones a file may legitimately name.
const NFP_METHODS_NATIVE = [NFP_METHOD_BILLINGS, NFP_METHOD_FERTILITY_CARE];
const NFP_METHODS_KNOWN = [NFP_METHOD_BILLINGS, NFP_METHOD_FERTILITY_CARE, NFP_METHOD_SYMPTOTHERMIC_FR];

// user_account.nfp_method packs the method and whether temperature is tracked in one 1-4 int:
// id => [the method as the NFP format and the JSON API name it, temperature tracked].
// lib/account.php is the one place that reads this table; js/account.js carries a copy.
const NFP_METHOD_BY_ID = [
	1 => [NFP_METHOD_BILLINGS, true],
	2 => [NFP_METHOD_BILLINGS, false],
	3 => [NFP_METHOD_FERTILITY_CARE, false],
	4 => [NFP_METHOD_FERTILITY_CARE, true],
];

// ===========================================================================
// DESCRIPTIONS AND EXPORT TYPES
// ===========================================================================

// description.type: what a free-text label of a day is.
const DESCRIPTION_TYPE_UNDEFINED = 0;     // text the v15 migration could not class
const DESCRIPTION_TYPE_OBSERVATION = 1;   // a mucus observation
const DESCRIPTION_TYPE_SENSATION = 2;     // a mucus sensation

// type <-> the string the JSON API speaks (api/description.php, api/sync.php).
const DESCRIPTION_TYPE_NAMES = [
	DESCRIPTION_TYPE_UNDEFINED => "undefined",
	DESCRIPTION_TYPE_OBSERVATION => "observation",
	DESCRIPTION_TYPE_SENSATION => "sensation",
];
const DESCRIPTION_TYPE_BY_NAME = [
	"undefined" => DESCRIPTION_TYPE_UNDEFINED,
	"observation" => DESCRIPTION_TYPE_OBSERVATION,
	"sensation" => DESCRIPTION_TYPE_SENSATION,
];

// The formats api/export.php can produce, as its "type" parameter names them.
const EXPORT_TYPE_PDF = "pdf";
const EXPORT_TYPE_CSV = "csv";
const EXPORT_TYPE_NFP = "nfp";
const EXPORT_TYPES = [EXPORT_TYPE_PDF, EXPORT_TYPE_CSV, EXPORT_TYPE_NFP];

// ===========================================================================
// PUBLIC STATISTICS -- GET /api/pub_stat (api/pub_stat.php, lib/data.php)
// ===========================================================================

// The three numbers it answers: JSON name => [the key_value row the cron keeps it in, the digits it is rounded to].
// Counting them walks day_timeline, which a public endpoint must not do on every visit.
const PUB_STAT_KEYS = [
	"moncycle_app_nb_user_account" => ["pub_stat_nb_user_account", -1],
	"moncycle_app_nb_cycle" => ["pub_stat_nb_cycle", -1],
	"moncycle_app_nb_total_observation" => ["pub_stat_nb_total_observation", -2],
];

// ===========================================================================
// SYNC -- GET /api/sync (api/sync.php, lib/data.php)
// ===========================================================================

// How far before the cursor a client sends the sync reads. A row is stamped (last_write_db) when its
// statement runs but only seen once its transaction commits, so a read made in between would skip it
// for good. A client replaces what it holds by what it receives, so reading a row twice is harmless.
const SYNC_OVERLAP_SECONDS = 120;

// What a sync with no fromTimestamp reads from: the start of everything, as a client's first sync does.
const SYNC_FROM_START = "1970-01-01 00:00:00";

// ===========================================================================
// LOGS -- the app's own log (lib/log.php; the policy is README.md, "Logs")
// ===========================================================================

// The values the LOG_* settings take. LOG_LEVEL is the least severe level written: the order of
// LOG_LEVEL_VALUES is the severity, "off" first.
const LOG_LEVEL_VALUES = ["off", "error", "warning", "info", "debug"];
const LOG_CATEGORY_VALUES = ["auth", "account", "data", "export", "mail", "system", "http"];
const LOG_FORMAT_VALUES = ["json", "text"];
const LOG_IP_VALUES = ["full", "truncate", "none"];

// What lib/log.php falls back on when a setting is missing from a hand-made config.php (written
// before the logs) or holds a value that is not one of the above. Same defaults as the config files.
const LOG_DEFAULTS = [
	"LOG_LEVEL" => "info",
	"LOG_CATEGORIES" => [],
	"LOG_OUTPUT" => "stdout",
	"LOG_FORMAT" => "json",
	"LOG_IP" => "full",
	"LOG_REQUEST_ID_HEADER" => "X-Request-Id",
];

// A request id from a client is only kept when it is this plain: it is never trusted beyond correlation.
const LOG_REQUEST_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/D';

// How a line is encoded: readable text, and a value that cannot be encoded is replaced, never fatal.
const LOG_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

// A string is clipped to LOG_LIMIT_FIELD_CHARS and a list to LOG_LIMIT_LIST_ITEMS. A line stays under
// LOG_LIMIT_LINE_BYTES, below PIPE_BUF (4096 on Linux): one write of that size is atomic, so the
// workers of a prefork server never interleave their lines in a shared stream or file.
const LOG_LIMIT_FIELD_CHARS = 200;
const LOG_LIMIT_LIST_ITEMS = 20;
const LOG_LIMIT_LINE_BYTES = 4000;

// Every event of the log => its default level. The category is the prefix before the dot.
const LOG_EVENTS = [
	"auth.login_succeeded" => "info",
	"auth.login_failed" => "warning",
	"auth.logout" => "info",
	"auth.captcha_issued" => "debug",
	"auth.token_rejected" => "debug",
	"account.registered" => "info",
	"account.refused" => "warning",
	"account.password_reset" => "info",
	"account.password_reset_unknown" => "warning",
	"account.password_changed" => "info",
	"account.settings_changed" => "info",
	"account.totp_setup_started" => "info",
	"account.totp_enabled" => "info",
	"account.totp_disabled" => "info",
	"account.reactivated" => "info",
	"account.deleted" => "info",
	"data.day_saved" => "info",
	"data.day_cleared" => "info",
	"data.description_saved" => "info",
	"data.description_deleted" => "info",
	"data.import" => "info",
	"export.file" => "info",
	"export.raw_dump" => "info",
	"mail.sent" => "info",
	"mail.failed" => "error",
	"system.exception" => "error",
	"system.cron_started" => "info",
	"system.cron_ended" => "info",     // "error" when the run did not reach its end
	"system.log_sink_failed" => "error",
	"http.request" => "info",
];

// ===========================================================================
// DAY FORMAT -- the codes day_timeline stores (lib/day_format.php)
// ===========================================================================

// The stamp is stored as one letter for the colour, then "BB" when the baby is on it. White has no
// letter: a white stamp is a stamp with the baby and no colour. Colour => letter, in the order the
// colour is looked for.
const DAY_STAMP_COLOR_CODES = ['Red' => 'R', 'Green' => 'G', 'Yellow' => 'Y'];

// The FertilityCare arrow: the glyph day_timeline.fc_arrow holds => the name the JSON API and the NFP format use.
const DAY_ARROW_GLYPHS = ['Up' => "\u{2191}", 'Down' => "\u{2193}", 'Right' => "\u{2192}"];

// The FertilityCare note codes: day field => its codes, in the order they are written back into
// day_timeline.fc_score (the five groups, space-separated, in this order).
const DAY_FORMAT_FC_GROUPS = [
	'codifiedBleedingObservation' => ['VH', 'H', 'M', 'VL', 'B'], // the 'L' (Lsaignement) *prefix* is handled separately
	'codifiedMucusSensation' => ['0', '2', '2W', '4', '6', '8', '10', '10DL', '10SL', '10WL'],
	'codifiedMucusObservation' => ['C', 'G', 'K', 'P', 'Y', 'L'], // this 'L' is a standalone mucus-observation code, distinct from the Lsaignement *prefix*
	'codifiedNumberObservations' => ['X1', 'X2', 'X3', 'AD'],
	'codifiedPainObservations' => ['AP', 'RAP', 'LAP'],
];

// The order the codes are looked for in an fc_score, each taken out of the text once found: a
// longer code goes first, so "10DL" is not read as "10" and a lone "L", and where two overlap
// ("X1" and "10" in "X10") the order decides. 'R' belongs to no group: it is taken out and ignored.
const DAY_FORMAT_FC_PARSE_ORDER = ['10DL', '10SL', '10WL', 'RAP', 'LAP', 'X1', 'X2', 'X3', 'AD', 'AP', 'VL', 'VH', '2W', '10', 'H', 'M', 'L', 'B', '0', '2', '4', '6', '8', 'C', 'G', 'K', 'P', 'Y', 'R'];

// How many dates GET /api/day?date=a,b,c reads (one query each): a year of days.
const DAY_LIMIT_QUERY_DATES = 366;

// What day_timeline and description can hold: the widths of their columns.
const DAY_LIMIT_COMMENT_CHARS = 256;        // day_timeline.comment    varchar(256)
const DAY_LIMIT_DESCRIPTION_CHARS = 256;    // description.name        varchar(256)
const DAY_LIMIT_FC_SCORE_CHARS = 32;        // day_timeline.fc_score   varchar(32), the five groups packed
const DAY_LIMIT_COUNTER_START = 255;        // day_timeline.counter_start tinyint unsigned
const DAY_TEMPERATURE_STORABLE_MIN = 0.0;   // day_timeline.temperature decimal(4,2) unsigned
const DAY_TEMPERATURE_STORABLE_MAX = 99.99;
// the range of a TIMESTAMP column (last_write_client_UTC, in UTC): a client clock outside it cannot be stored
const TIMESTAMP_STORABLE_MIN = "1970-01-01 00:00:01";
const TIMESTAMP_STORABLE_MAX = "2038-01-19 03:14:07";

// ===========================================================================
// NFP FILE FORMAT -- schema, limits and vocabularies (lib/nfp_format.php)
// ===========================================================================

const NFP_SCHEMA_VERSION = "1.0";                  // the schema version the export writes
const NFP_SUPPORTED_SCHEMA_VERSIONS = ["1.0"];     // the ones the import accepts

// Hard limits refuse a file: the body size, and the DAY_LIMIT_* widths of the columns a value must
// land in (above). The ones marked "advisory" only raise a warning and the data is imported anyway, because this
// app's own /export can write more than they allow (years of gap days, hundreds of cycles).
const NFP_LIMIT_BODY_BYTES = 262144;        // 256K -- matches post_max_size in server_conf, and bounds the work
const NFP_LIMIT_JSON_DEPTH = 32;            // the format nests 4 deep; 32 is already generous
const NFP_LIMIT_CYCLES = 120;               // advisory -- ~10 years of cycles in one file
const NFP_LIMIT_DAYS_PER_CYCLE = 400;       // advisory -- a pregnancy-length cycle still fits
const NFP_LIMIT_DAYS_TOTAL = 4000;          // advisory
const NFP_LIMIT_DESCRIPTIONS_PER_DAY = 20;  // advisory -- nothing caps the links of a day

// Outside DAY_TEMPERATURE_STORABLE_* a file is refused; outside the narrower band a human body
// reaches (these two, advisory) it only draws a warning.
const NFP_TEMPERATURE_MIN = 30.0;
const NFP_TEMPERATURE_MAX = 45.0;
const NFP_DATE_FLOOR = "1900-01-01";        // no day of a file may be older than this
const NFP_FUTURE_GRACE_DAYS = 1;            // days past today still accepted: a client a timezone ahead of the server is fine

// The closed vocabularies of the spec, enforced as enums (the open ones, _customValuesAllowed, are not).
const NFP_STAMP_COLORS = ["Green", "Red", "Yellow", "White"];
const NFP_SEX_UNIONS = ["Union", "ReservedUnion", "LastReportedUnion"];
const NFP_ARROWS = ["Up", "Down", "Right"];

// The spec's _incompatibleWith list for mucusNotObserved (a day with nothing recorded cannot hold
// an observation). "codifiedBloodObservation" in the spec is corrected to "codifiedBleedingObservation".
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

// The spec's FertilityCare codes the app stores under another spelling, aliased on the way in;
// a code outside DAY_FORMAT_FC_GROUPS and these is reported as ignored, never written.
const NFP_FC_CODE_ALIASES = [
	"BR" => "B",      // spec writes brown bleeding "Br", the app stores "B"
	"X1" => "X1",     // spec writes "x1".."x3" lowercase; matching is case-insensitive
	"X2" => "X2",
	"X3" => "X3",
	"C/K" => "CK",    // spec's "between C and K"; the app stores the two codes together
];

// The day and cycle fields schema 1.0 defines, split by what this app can do with them. A "not
// stored" field is reported back to the caller with its reason; a key in neither list is reported as unknown.
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

const NFP_CYCLE_FIELDS_STRUCTURAL = ["method", "cycleStartDate", "days"];   // the keys a cycle cannot do without

// ===========================================================================
// CSV AND PDF EXPORTS -- what both charts and the CSV share (lib/doc.php)
// ===========================================================================

const DOC_WEEK_DAYS = ["D", "L", "M", "M", "J", "V", "S"];   // initials of the days, Sunday first, on the charts
const DOC_BABY_IMAGE = __DIR__ . "/img/baby.png";            // drawn on a stamp that has the baby
const DOC_CSV_LIST_JOINER = " | ";                           // joins the descriptions of a day in one CSV cell, in both CSV exports
const DOC_COMMENT_OTHER_JOINER = " | ";                      // between a day's comment and its descriptions with no type yet, which follow it in the CSV and in both PDFs

// The fields a mucusNotObserved day leaves empty in the CSV and in both PDFs, even when old values
// are stored: the NFP incompatibility list, plus the FertilityCare recurrence count, the stamp and
// the legacy free text. The NFP export still writes them: it is a data file, not a chart.
const DOC_MUCUS_FIELDS = [
	...NFP_MUCUS_NOT_OBSERVED_INCOMPATIBLE,
	"codifiedNumberObservations",
	"stampColor",
	"stampBaby",
	"freeOther",
];

const DOC_PDF_MARGIN = 10;                                  // mm kept free on every side of the page, in both charts
const DOC_PREGNANCY_COLOR = [130, 21, 33];                  // r, g, b of the pregnancy "G" and "GROSSESSE" text, in both charts
const DOC_BILL_TEMPERATURE_COLOR = [135, 67, 176];          // r, g, b of the temperature reading, its curve and its points

// The text styles the charts draw with: style => [font family, font style, size in pt, [r, g, b]].
// Names are "bill.*" for the Billings table and "fc.*" for the FertilityCare grid.
const DOC_PDF_STYLES = [
	'bill.title' => ['Helvetica', 'B', 14, [0, 0, 0]],
	'bill.subtitle' => ['Helvetica', '', 8.5, [110, 110, 110]],
	'bill.link' => ['Helvetica', 'B', 8, [30, 130, 76]],
	'bill.head' => ['Helvetica', 'B', 6, [110, 110, 110]],
	'bill.scale' => ['Helvetica', '', 5.5, [140, 140, 140]],
	'bill.date' => ['Helvetica', '', 7, [110, 110, 110]],
	'bill.date.sunday' => ['Helvetica', 'B', 7, [50, 50, 50]],
	'bill.day_number' => ['Helvetica', '', 8.5, [0, 0, 0]],
	'bill.stamp' => ['Helvetica', 'B', 8, [0, 0, 0]],
	'bill.peak' => ['ZapfDingbats', '', 8, [139, 69, 19]],
	'bill.peak_offset' => ['Helvetica', 'B', 7.5, [139, 69, 19]],
	'bill.counter' => ['Helvetica', 'B', 7.5, [30, 130, 76]],
	'bill.union' => ['ZapfDingbats', '', 8, [172, 36, 51]],
	'bill.text' => ['Helvetica', '', 8, [30, 30, 30]],
	'bill.comment' => ['Helvetica', 'I', 7.5, [70, 70, 70]],
	'bill.temperature' => ['Helvetica', '', 8, DOC_BILL_TEMPERATURE_COLOR],
	'bill.temperature_time' => ['Helvetica', '', 6, [140, 140, 140]],
	'bill.legend' => ['Helvetica', '', 6.5, [110, 110, 110]],
	'fc.header' => ['Courier', '', 10, [0, 0, 0]],
	'fc.header.name' => ['Courier', 'B', 10, [0, 0, 0]],
	'fc.header.link' => ['Courier', 'B', 10, [30, 130, 76]],
	'fc.day_numbers' => ['Courier', '', 8, [0, 0, 0]],
	'fc.cell' => ['Courier', '', 6, [0, 0, 0]],
	'fc.cell.sunday' => ['Courier', 'B', 6, [0, 0, 0]],
	'fc.baby' => ['Courier', '', 5, [0, 0, 0]],
	'fc.arrow' => ['Symbol', '', 6, [0, 0, 0]],
	'fc.temperature' => ['Courier', '', 3.8, [0, 0, 0]],
	'fc.comment.small' => ['Courier', '', 3, [0, 0, 0]],
];

// ===========================================================================
// BILLINGS PDF (nfp_method 1 and 2) -- a table, one line per day, A4 portrait. Sizes are in mm.
// ===========================================================================

const DOC_BILL_TABLE_TOP = 24;          // where the table starts, under the page title
const DOC_BILL_BOTTOM = 14;             // what the page keeps free under the table, for the legend
const DOC_BILL_HEAD_H = 6;              // height of the column headers band
const DOC_BILL_LINE_H = 3.6;            // a line of text in a cell
const DOC_BILL_MIN_ROW_H = 6.5;         // a day's line, whatever it holds
const DOC_BILL_PAD = (DOC_BILL_MIN_ROW_H - DOC_BILL_LINE_H) / 2;   // above the first line of text
const DOC_BILL_MAX_LINES = 10;          // a text taking more lines than this is cut short with an ellipsis
const DOC_BILL_STAMP_SIZE = 5.5;        // side of the stamp square
const DOC_BILL_TEMPERATURE_W = 17;      // the temperature column: the reading and the time it was taken at
const DOC_BILL_CURVE_W = 24;            // the curve column
const DOC_BILL_CURVE_PAD = 2.5;         // kept free at each end of the curve's width
const DOC_BILL_TEXT_MIN_W = 14;         // a description column is never narrower than this...
const DOC_BILL_TEXT_MAX_W = 40;         // ... nor wider, as the comment is the one to take the room
const DOC_BILL_TEXT_TYPICAL = 0.85;     // share of a column's values that must fit it on one line
const DOC_BILL_COMMENT_WANTED_W = 40;   // the width the description columns give way to keep for the comment

const DOC_BILL_HEAD_FILL = [240, 240, 240];     // r, g, b of the column headers band
const DOC_BILL_STRIPE_FILL = [248, 248, 248];   // r, g, b of every other day, when borders are off
const DOC_BILL_BORDER_COLOR = [210, 210, 210];  // r, g, b of the table's lines and of a white stamp's outline
const DOC_BILL_BORDER_W = 0.1;                  // thickness of those lines

// The narrow columns, in order: key => [header, width]. The three markers only get a column
// when a day of the export has one.
const DOC_BILL_FIXED_COLUMNS = [
	"date" => ["DATE", 13],
	"day" => ["JOUR", 9],
	"stamp" => ["TAMPON", 11],
	"peak" => ["PIC", 9],
	"counter" => ["COMPTEUR", 15],
	"union" => ["UNION", 10],
];
const DOC_BILL_MARKERS = ["peak", "counter", "union"];   // the optional columns of those, also the keys of a day's row

// The text columns, in order: day field => [header, style]. The comment takes what the others leave.
const DOC_BILL_TEXT_COLUMNS = [
	"freeMucusSensation" => ["SENSATIONS", 'bill.text'],
	"freeMucusObservation" => ["OBSERVATIONS", 'bill.text'],
	"comments" => ["COMMENTAIRE", 'bill.comment'],
];

// The stamps that are not a colour of the app: kind => [fill, glyph, glyph colour].
const DOC_BILL_SPECIAL_STAMPS = [
	"unknown" => [[226, 226, 226], "?", [130, 130, 130]],
	"pregnancy" => [[255, 226, 230], "G", DOC_PREGNANCY_COLOR],
];
const DOC_BILL_STAMP_COLORS = [          // stampColor => r, g, b of the stamp
	"Red" => [172, 36, 51],
	"Green" => [30, 130, 76],
	"Yellow" => [251, 202, 11],
	"White" => [255, 255, 255],
];

// ===========================================================================
// FERTILITYCARE PDF (nfp_method 3 and 4) -- a grid, A3 landscape. Sizes are in mm.
// ===========================================================================

const DOC_FC_DAYS_PER_ROW = 35;                 // days across the grid: a longer cycle carries on in the next row
const DOC_FC_FIRST_COL_W = 16;                  // width of the legend column
const DOC_FC_LINE_H = 3.5;                      // height of a line of text in a cell
const DOC_FC_STAMP_H = 7.3;                     // height of the stamp / baby cell
const DOC_FC_SEPARATOR_H = 0.25;                // thickness of the bar between two rows of the grid
const DOC_FC_GRID_GRAY = 60;                    // grey level (0-255) of the grid's lines
const DOC_FC_COLOR_COEF = 1.1;                  // a cell's border is its stamp colour divided by this, a slightly darker shade
const DOC_FC_LINES_PER_PAGE = 8;                // rows of the grid on a page...
const DOC_FC_LINES_PER_PAGE_TEMPERATURE = 7;    // ... and when the temperature row takes room

// The legend column, top to bottom: row => its label. The baby row has none, and the temperature
// row only exists for the methods that track it (account_tracks_temperature(), lib/account.php).
const DOC_FC_ROW_LABELS = [
	"stamp" => "TAMPON",
	"baby" => "",
	"peak" => "PEAK",
	"date" => "DATE",
	"bleeding" => "SAIGNEMENT",
	"mucus" => "GLAIRE",
	"other" => "AUTRE INFO",
	"temperature" => "TEMPERATURE",
	"comment" => "COMMENTAIRE",
];

// stamp code (see doc_fc_stamp_key()) => [text drawn, r, g, b]
const DOC_FC_STAMPS = [
	"" => ["", 255, 255, 255],
	"?" => ["???", 210, 210, 210],
	"R" => ['R', 190, 0, 4],
	"G" => ['V', 45, 102, 23],
	"Y" => ['J', 255, 255, 9],
	"BB" => ['BBB', 255, 255, 255],
	'RBB' => ['BBR', 190, 0, 0],
	'GBB' => ['BBV', 130, 187, 106],
	'YBB' => ['BBJ', 255, 255, 9],
	'Gi' => ['G', 255, 236, 238], // pregnancy ("Grossesse") -- TODO: another letter, G reads like Green
];

// codifiedArrow => its glyph in the Symbol font
const DOC_FC_ARROWS = ["Up" => "\xAD", "Down" => "\xAF", "Right" => "\xAE"];
