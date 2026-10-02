<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/doc_csv.php";
require_once "../lib/doc_export.php";
require_once "../lib/nfp_export.php";
require_once "../lib/sec.php";

$db = db_open();
$user_account = sec_auth_token($db);
sec_redirect_if_logged_out($user_account);

// THE PERIOD AND THE FORMAT
$start_date = date_parse_ymd($_GET['start_date'] ?? null) ?? http_text_error("date de démarrage non indiquée ou au mauvais format.");
$end_date = date_parse_ymd($_GET['end_date'] ?? null) ?? http_text_error("date de fin non indiquée ou au mauvais format.");

if (new DateTime($start_date) >= new DateTime($end_date)) {
	http_text_error("la 'start_date' doit être antérieure à la 'end_date'.");
}

$type = $_GET['type'] ?? null;
if (!in_array($type, EXPORT_TYPES, true)) {
	http_text_error("le format de l'export doit être: " . implode(", ", EXPORT_TYPES));
}

// 'anonymous' applies to pdf (no name on the chart) and to nfp (no userInformation block in the
// file); 'json_in_page' only to nfp (shown in the browser instead of downloaded)
$anonymous = $_GET['anonymous'] ?? "0";
if (!in_array($anonymous, ["0", "1"], true)) http_text_error("'anonymous' doit être 1 ou 0");

$json_in_page = $type === EXPORT_TYPE_NFP ? ($_GET['json_in_page'] ?? "0") : "0";
if (!in_array($json_in_page, ["0", "1"], true)) http_text_error("'json_in_page' doit être 1 ou 0");

$anonymous = $anonymous === "1";
$json_in_page = $json_in_page === "1";
$nfp_method = intval($user_account["nfp_method"]);

// THE DAYS OF THE PERIOD -- csv and pdf read exactly the period asked for; the nfp export reads
// its own, widened back to the cycle start, so here it only needs to know there is something
if ($type === EXPORT_TYPE_NFP) {
	$days = [];
	$has_data = !empty(db_select_day_timeline_dates_frame($db, $start_date, $end_date, $user_account["no_user_account"]));
}
else {
	$days = doc_export_days($db, $start_date, $end_date, $user_account);
	$has_data = !empty($days);
}

if (!$has_data) {
	http_text_error("il n'y a pas d'observation pour la période demandée.");
}

$filename = 'moncycle_app_' . date_human(new DateTime($start_date), '_');

if ($type === EXPORT_TYPE_CSV) {
	header("Content-Type: text/csv; charset=utf-8");
	header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
	$out = fopen('php://output', 'w');
	doc_csv_cycle($out, $days, $nfp_method);
	fclose($out);
}

elseif ($type === EXPORT_TYPE_PDF) {
	$pdf = doc_export_pdf($days, $nfp_method, $user_account["name_user_account"], $anonymous);
	// 'D' sends the Content-Type and an attachment Content-Disposition itself
	$pdf->Output('D', $filename . '.pdf', true);
}

else {
	$app_version = json_decode(file_get_contents("version.json"), true)["version"] ?? "";
	$nfp_data = nfp_export_file($db, $start_date, $end_date, $user_account, $anonymous, $app_version);

	header('Content-Type: application/json');
	if (!$json_in_page) header('Content-Disposition: attachment; filename="' . $filename . '.nfp"');

	// JSON_UNESCAPED_UNICODE / _SLASHES keep accented comments and the ISO-8601 timestamp
	// readable in the file; JSON_PRESERVE_ZERO_FRACTION keeps a round 37.0 a number.
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
	print(json_encode($nfp_data, $json_in_page ? ($flags | JSON_PRETTY_PRINT) : $flags));
}
