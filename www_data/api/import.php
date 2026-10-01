<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** Imports an NFP file into the logged-in account.
**
** The four stages of lib/nfp_file.php run in order and nothing is written until all the
** checking ones have passed, so the account is either updated with the whole file or not
** touched at all:
**
**   1. nfp_file_parse()        size, encoding, JSON, schema version
**   2. nfp_file_schema_errors() structure and types
**   3. nfp_file_build_plan()   calendar, ranges, lengths, cycle overlap -> the write plan
**   4. nfp_file_write_plan()   inside one transaction
**
** ?dryRun=1 stops after stage 3, which is the "check my file first" behaviour this endpoint
** used to be limited to.
**
** ?overide=<0|1> decides what happens to a date the account already has data on: 0 (the
** default) leaves it alone and reports it, 1 replaces it.
*/

require_once "../vendor/autoload.php";

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";
require_once "../lib/date.php";
require_once "../lib/data.php";
require_once "../lib/http.php";
require_once "../lib/day_format.php";
require_once "../lib/nfp_format.php";
require_once "../lib/nfp_file.php";

header('Content-Type: application/json');

$db = db_open();

$user_account = sec_auth_token($db);
sec_exit_si_non_connecte($user_account);

if ($_SERVER['REQUEST_METHOD'] !== "POST") {
	http_error(405, "method_not_allowed", "Supported method: POST.");
}

// ---------------------------------------------------------------------------
// Parameters
// ---------------------------------------------------------------------------

// "overide" keeps its original spelling: it is already published in the API.
if (isset($_GET['overide']) && !in_array($_GET['overide'], ["0", "1"], true)) {
	http_error(400, "invalid_parameter", "'overide' must be 0 or 1.");
}
$overide = isset($_GET['overide']) && $_GET['overide'] === "1";

if (isset($_GET['dryRun']) && !in_array($_GET['dryRun'], ["0", "1"], true)) {
	http_error(400, "invalid_parameter", "'dryRun' must be 0 or 1.");
}
$dry_run = isset($_GET['dryRun']) && $_GET['dryRun'] === "1";

$last_write_client_UTC = http_from_iso8601($_GET['lastWriteClientUtc'] ?? null);
if (!$last_write_client_UTC || !date_validate_timestamp($last_write_client_UTC)) {
	$last_write_client_UTC = date('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Stage 1 -- the body
// ---------------------------------------------------------------------------

$content_length = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : null;
$parsed = nfp_file_parse(file_get_contents('php://input'), $content_length);

if (!$parsed["ok"]) {
	$status = $parsed["code"] === "file_too_large" ? 413 : 400;
	http_error($status, $parsed["code"], $parsed["message"], $parsed["details"]);
}

$nfp_file = $parsed["file"];

// ---------------------------------------------------------------------------
// Stage 2 -- structure and types
// ---------------------------------------------------------------------------

$schema_errors = nfp_file_schema_errors($nfp_file);
if (!empty($schema_errors)) {
	http_error(400, "invalid_nfp_structure", "The file does not match the NFP schema.", ["issues" => $schema_errors]);
}

// ---------------------------------------------------------------------------
// Stage 3 -- semantics and consistency
// ---------------------------------------------------------------------------

$checked = nfp_file_build_plan($nfp_file, $user_account);

if (!empty($checked["issues"])) {
	http_error(400, "inconsistent_nfp_data", "The file is valid JSON but its contents are inconsistent.", [
		"issues" => array_slice($checked["issues"], 0, 50),
		"issueCount" => count($checked["issues"]),
	]);
}

$report = [
	"dryRun" => $dry_run,
	"overide" => $overide,
	"schemaVersion" => nfp_format_get($nfp_file, "schemaVersion"),
	"sourceApp" => nfp_format_get(nfp_format_get($nfp_file, "fileInformation"), "sourceApp"),
	"cyclesRead" => $checked["cyclesRead"],
	"daysRead" => $checked["daysRead"],
	"daysToWrite" => count($checked["plan"]),
	"warnings" => $checked["warnings"],
	"mappedFields" => $checked["mapped"],
	"ignoredFields" => $checked["ignored"],
];

if ($dry_run) {
	$report["daysCreated"] = [];
	$report["daysOverwritten"] = [];
	$report["daysSkipped"] = [];
	$report["descriptionsCreated"] = 0;
	http_data(200, $report);
}

// ---------------------------------------------------------------------------
// Stage 4 -- the write
// ---------------------------------------------------------------------------

// an import counts as activity, same as a POST to /api/day
if (isset($user_account["is_inactive"]) && boolval($user_account["is_inactive"])) {
	db_update_is_inactive($db, $user_account["no_user_account"], 0);
}

try {
	$db->exec("START TRANSACTION");
	$written = nfp_file_write_plan(
		$db, intval($user_account["no_user_account"]), $checked["plan"], $overide, $last_write_client_UTC
	);
	$db->exec("COMMIT");
} catch (\Throwable $th) {
	$db->exec("ROLLBACK");
	throw $th;
}

// the writer finds one kind of narrowing of its own (a label already recorded under the other
// type), so its list joins the ones found while checking
$report["mappedFields"] = array_merge($report["mappedFields"], $written["narrowed"]);
unset($written["narrowed"]);

$report = array_merge($report, $written);
$report["daysWritten"] = count($written["daysCreated"]) + count($written["daysOverwritten"]);

http_data(200, $report);
