<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/date.php";
require_once "../lib/db.php";
require_once "../lib/doc.php";
require_once "../lib/sec.php";
require_once "../lib/data.php";
require_once "../lib/http.php";
require_once "../lib/day_format.php";
require_once "../lib/nfp_format.php";
require_once "../lib/nfp_file.php";

require_once "../vendor/autoload.php";


$db = db_open();

$result = [];
$user_account = sec_auth_token($db);
sec_redirect_non_connecte($user_account);


// START DATE
if (isset($_GET['start_date']) && preg_match("/^\s*\d{4}-\d{2}-\d{2}$/", $_GET["start_date"])) {
	$result["start_date"] = trim($_GET['start_date']);
}
else {
	http_response_code(400);
	print("ERREUR: date de démarrage non indiquée ou au mauvais format.");
	exit;
}

// END DATE
if (isset($_GET['end_date']) && preg_match("/^\s*\d{4}-\d{2}-\d{2}$/", $_GET["end_date"])) {
	$result["end_date"] = trim($_GET['end_date']);
}
else {
	http_response_code(400);
	print("ERREUR: date de fin non indiquée ou au mauvais format.");
	exit;
}

// THE START DATE MUST COME FIRST
if (new DateTime($result["start_date"]) >= new DateTime($result["end_date"])) {
	http_response_code(400);
	print("ERREUR: la 'start_date' doit être antérieure à la 'end_date'.");
	exit;
}

// EXPORT FORMAT
if (!isset($_GET['type']) || !in_array($_GET['type'], EXPORT_TYPES)) {
	http_response_code(400);
	print("ERREUR: le format de l'export doit être: ");
	print(implode(", ", EXPORT_TYPES));
	exit;
}

// CHECK ANONYMOUS MODE OR NOT -- applies to pdf (no name on the chart) and to nfp (no
// userInformation block in the file)
if (isset($_GET['anonymous']) && !in_array($_GET['anonymous'], ["1", "0"])) {
	http_response_code(400);
	print("ERREUR: 'anonymous' doit être 1 ou 0");
	exit;
}
$anonymous = boolval($_GET['anonymous'] ?? "0");

// VERIFY JSON_IN_PAGE PARAM
if ($_GET['type'] == EXPORT_TYPE_NFP && isset($_GET['json_in_page']) && !in_array($_GET['json_in_page'], ["1", "0"])) {
	http_response_code(400);
	print("ERREUR: 'json_in_page' doit être 1 ou 0");
	exit;
}
$json_in_page = boolval($_GET['json_in_page'] ?? "0");

$nfp_method = intval($user_account["nfp_method"]);

// THE DAYS OF THE PERIOD -- csv and pdf read exactly the period asked for; the nfp export reads
// its own, widened back to the cycle start, so here it only needs to know there is something
if ($_GET['type'] == EXPORT_TYPE_NFP) {
	$days = [];
	$has_data = !empty(db_select_day_timeline_dates_frame($db, $result["start_date"], $result["end_date"], $user_account["no_user_account"]));
}
else {
	$days = doc_export_days($db, $result["start_date"], $result["end_date"], $user_account);
	$has_data = !empty($days);
}

if (!$has_data) {
	http_response_code(400);
	print("ERREUR: il n'y a pas d'observation pour la période demandée.");
	exit;
}

$filename = 'moncycle_app_' . date_humain(new DateTime($result["start_date"]), '_');

if ($_GET['type'] == EXPORT_TYPE_CSV) {
	header("Content-Type: text/csv; charset=utf-8");
	header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
	$out = fopen('php://output', 'w');
	doc_cycle_to_csv($out, $days, $nfp_method);
	fclose($out);
}

elseif ($_GET['type'] == EXPORT_TYPE_PDF) {
	$pdf = doc_cycle_to_pdf($days, $nfp_method, $user_account["name_user_account"], $anonymous);
	// 'D' sends the Content-Type and an attachment Content-Disposition itself
	$pdf->Output('D', $filename . '.pdf', true);
}

elseif ($_GET['type'] == EXPORT_TYPE_NFP) {

	$json_version = json_decode(file_get_contents("version.json"), true);

	$nfp_data = nfp_file_export(
		$db, $result["start_date"], $result["end_date"], $user_account,
		$anonymous, $json_version["version"] ?? ""
	);

	header('Content-Type: application/json');
	if (!$json_in_page) header('Content-Disposition: attachment; filename="' . $filename . '.nfp"');

	// JSON_UNESCAPED_UNICODE / _SLASHES keep accented comments and the ISO-8601 timestamp
	// readable in the file; JSON_PRESERVE_ZERO_FRACTION keeps a round 37.0 a number.
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
	print(json_encode($nfp_data, $json_in_page ? ($flags | JSON_PRETTY_PRINT) : $flags));

}
