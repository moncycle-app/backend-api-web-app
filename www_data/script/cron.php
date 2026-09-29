<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/doc.php";
require_once "../lib/date.php";
require_once "../lib/mail.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../vendor/phpmailer/phpmailer/src/Exception.php';
require_once '../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once '../vendor/phpmailer/phpmailer/src/SMTP.php';
require_once "../vendor/fpdf/fpdf/src/Fpdf/Fpdf.php";



header("Content-Type: text/plain");

echo ".............................................................................";
echo PHP_EOL;

echo "moncycle.app cron worker";
echo PHP_EOL;


$db = db_open();

// ENVOIE DES MAILS CYCLES TERMINE

$cycles = db_select_cycles_recent($db);

foreach($cycles as $cyc) {
	
	$debut_cycle = db_select_cycle($db, $cyc["cycle_complet"], $cyc["no_user_account"]);
	
	if(!empty($debut_cycle)) {

		$debut_cycle = $debut_cycle[0]["cycle"];
		$cycle_complet = db_select_cycle_complet($db, $debut_cycle,  $cyc["cycle_complet"], $cyc["no_user_account"]);
		$cycle_complet = doc_preparation_jours_pour_affichage($cycle_complet, $cyc["nfp_method"]);

		$nb_j = count($cycle_complet);
		
		if ($nb_j>=5) {

			$pdf = null;
			if ($cyc["nfp_method"] == 3 || $cyc["nfp_method"] == 4) $pdf = doc_cycle_fc_vers_pdf($cycle_complet, $cyc["nfp_method"], $cyc["name"]);
			else $pdf = doc_cycle_bill_vers_pdf ($cycle_complet, $cyc["nfp_method"], $cyc["name"]);

			$csv = fopen('php://memory','rw');
			doc_cycle_vers_csv ($csv, $cycle_complet, $cyc["nfp_method"]);
			rewind($csv);

			$mail = mail_init();

			$mail->addAddress($cyc["email1"], $cyc["email1"]);
			if (!empty($cyc["email2"])) $mail->addAddress($cyc["email2"], $cyc["email2"]);

			$dh = date_humain(new Datetime($cycle_complet[0]["date_obs"]));
			$fh = date_humain(new Datetime(end($cycle_complet)["date_obs"]));

			$mail->isHTML(true);
			$mail->Subject = "Cycle de $nb_j jours du $dh";
			$mail->Body = mail_body_cycle($cyc['name'], $dh, $fh, $nb_j);
			$mail->AltBody = "Export de votre cycle du $dh au $fh de $nb_j jours.\n\nmoncycle.app";

			$filename_start_date = date_humain(new DateTime($debut_cycle), '_');

			$mail->addStringAttachment($pdf->Output('', 'S'), 'moncycle_app_'. $filename_start_date . '.pdf');
			$mail->addStringAttachment(stream_get_contents($csv), 'moncycle_app_'. $filename_start_date . '.csv');

			$mail->send();

			fclose($csv);

			echo "cycle of $nb_j days sent to {$cyc["email1"]} (and {$cyc["email2"]}).";
			echo PHP_EOL;
		}
	}
}

// RELANCE COMPTES INACTIF

$user_account = db_select_user_account_inactif($db);

foreach($user_account as $com) {

	$mail = mail_init();

	$mail->addAddress($com["email1"], $com["email1"]);
	if (!empty($com["email2"])) $mail->addAddress($com["email2"], $com["email2"]);

	$mail->isHTML(true);
	$mail->Subject = "Comment allez-vous?";
	$mail->Body = mail_body_relance($com["name"], $com["email1"]);
	$mail->AltBody = "Cela fait longtemps que l'on ne vous a pas vu sur moncycle.app, tout va bien?";

	$mail->send();

	db_update_is_inactive($db, $com["no_user_account"], 1);

	echo "reminder sent to {$com["email1"]} (and {$com["email2"]})";
	echo PHP_EOL;
}

// RGPD: WARN THEN DELETE ACCOUNTS INACTIVE FOR ACCOUNT_INACTIVITY_DELETE_YEARS

$user_account_to_warn = db_select_user_account_to_warn_before_deletion($db, ACCOUNT_INACTIVITY_DELETE_YEARS, ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE);

foreach($user_account_to_warn as $account) {

	$mail = mail_init();

	$mail->addAddress($account["email1"], $account["email1"]);
	if (!empty($account["email2"])) $mail->addAddress($account["email2"], $account["email2"]);

	$mail->isHTML(true);
	$mail->Subject = "Votre compte moncycle.app va être supprimé dans " . ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE . " jours";
	$mail->Body = mail_body_account_deletion_warning($account["name"], $account["email1"], ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE);
	$mail->AltBody = "Faute d'activité, votre compte moncycle.app sera supprimé dans " . ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE . " jours. Connectez-vous pour le conserver.";

	$mail->send();

	echo "deletion warning sent to {$account["email1"]} (and {$account["email2"]})";
	echo PHP_EOL;
}

$user_account_to_delete = db_select_user_account_to_delete($db, ACCOUNT_INACTIVITY_DELETE_YEARS);

foreach($user_account_to_delete as $account) {

	db_delete_user_account($db, $account["no_user_account"]);

	echo "account {$account["email1"]} deleted (" . ACCOUNT_INACTIVITY_DELETE_YEARS . " years without activity, RGPD)";
	echo PHP_EOL;
}

// SUPPR DES TOKENS EXPIRES

$ret = db_delete_vieux_auth_token($db);
echo $ret . " old tokens deleted";
echo PHP_EOL;

$ret = db_delete_vieux_login_attempt_ip($db);
echo $ret . " old login attempts (IP) deleted";
echo PHP_EOL;

// RESET DES COMPTEURS DE STAT

db_update_reset_key_value($db, "pub_visite_jour");
echo "daily stats reset";

$auj = getdate();

if ($auj["wday"]==0) {
	db_update_reset_key_value($db, "pub_visite_hebdo");
	echo ", weekly stats reset";
}

if ($auj["mday"]==1) {
	db_update_reset_key_value($db, "pub_visite_mensuel");
	echo ", monthly stats reset";
}

echo PHP_EOL;
