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


// LECTURE D'UNE DATE DE DEBUT DE CYCLE
if (isset($_GET['start_date']) && preg_match("/^\s*\d{4}-\d{2}-\d{2}$/", $_GET["start_date"])) {
	$result["start_date"] = trim($_GET['start_date']);
}
else {
	http_response_code(400);
	print("ERREUR: date de démarrage non indiquée ou au mauvais format.");
	exit;
}

// LECTURE D'UNE DATE DE FIN DE CYCLE
if (isset($_GET['end_date']) && preg_match("/^\s*\d{4}-\d{2}-\d{2}$/", $_GET["end_date"])) {
	$result["end_date"] = trim($_GET['end_date']);
}
else {
	http_response_code(400);
	print("ERREUR: date de fin non indiquée ou au mauvais format.");
	exit;
}

// VERIFICATION D'ANTERIORITE
if (new DateTime($result["start_date"]) >= new DateTime($result["end_date"])) {
	http_response_code(400);
	print("ERREUR: la 'start_date' doit être antérieure à la 'end_date'.");
	exit;
}

// VERIFICATION DU FORMAT DE L'EXPORT
$available_type = ["pdf", "csv", "nfp"];
if (!isset($_GET['type']) || !in_array($_GET['type'], $available_type)) {
	http_response_code(400);
	print("ERREUR: le format de l'export doit être: ");
	print(implode(", ", $available_type));
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
if ($_GET['type'] == "nfp" && isset($_GET['json_in_page']) && !in_array($_GET['json_in_page'], ["1", "0"])) {
	http_response_code(400);
	print("ERREUR: 'json_in_page' doit être 1 ou 0");
	exit;
}
$json_in_page = boolval($_GET['json_in_page'] ?? "0");

// RECUPERATION DU CYCLE
$data = db_select_cycle_complet($db, $result["start_date"],$result["end_date"], $user_account["no_user_account"]);

// VERIFICATION SI IL Y A DE LA DONNEE
if (!isset($data[0])) {
	http_response_code(400);
	print("ERREUR: il n'y a pas d'observation pour la période demandée.");
	exit;
}

// AJOUT DES JOURS MANQUANTS DU CYCLE
$cycle = doc_preparation_jours_pour_affichage($data, $user_account["nfp_method"]);

$filename_start_date = date_humain(new DateTime($result["start_date"]), '_');

try {

	if ($_GET['type'] == "csv") {
		header("content-type:application/csv;charset=UTF-8");
		header('Content-Disposition: attachment; filename="moncycle_app_'. $filename_start_date .'.csv"');
		$out = fopen('php://output', 'w');
		doc_cycle_vers_csv ($out, $cycle, $user_account["nfp_method"]);
		fclose($out);
	}

	elseif ($_GET['type'] == "pdf") {
		$pdf = null;
		if ($user_account["nfp_method"] == 3 || $user_account["nfp_method"] == 4) $pdf = doc_cycle_fc_vers_pdf($cycle, $user_account["nfp_method"], $user_account["name_user_account"], $anonymous);
		else $pdf = doc_cycle_bill_vers_pdf($cycle, $user_account["nfp_method"], $user_account["name_user_account"], $anonymous);
		header("content-type:application/pdf");
		header('Content-Disposition: attachment; filename="moncycle_app_'. $filename_start_date .'.pdf"');
		$pdf->Output('I', 'moncycle_app_'. $filename_start_date . '.pdf');
	}

	elseif ($_GET['type'] == "nfp") {

		$json_version = json_decode(file_get_contents("version.json"), true);

		$nfp_data = nfp_file_export(
			$db, $result["start_date"], $result["end_date"], $user_account,
			$anonymous, $json_version["version"] ?? ""
		);

		header('Content-Type: application/json');
		if (!$json_in_page) header('Content-Disposition: attachment; filename="moncycle_app_'. $filename_start_date .'.nfp"');

		// JSON_UNESCAPED_UNICODE / _SLASHES keep accented comments and the ISO-8601 timestamp
		// readable in the file; JSON_PRESERVE_ZERO_FRACTION keeps a round 37.0 a number.
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
		print(json_encode($nfp_data, $json_in_page ? ($flags | JSON_PRETTY_PRINT) : $flags));

	}

} catch (\Throwable $th) {
	throw $th;
}

