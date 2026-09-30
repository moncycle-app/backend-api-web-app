<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

require_once "../config.php";
require_once "../lib/db.php";
require_once "../lib/sec.php";
require_once "../lib/mail.php";
require_once "../lib/http.php";
require_once '../vendor/phpmailer/phpmailer/src/Exception.php';
require_once '../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once '../vendor/phpmailer/phpmailer/src/SMTP.php';

$db = db_open();

$body = http_json_body();

$reset_email = isset($body["email"]) ? trim((string) $body["email"]) : null;

if (empty($reset_email)) {
	http_error(400, "missing_email", "'email' is required.");
}

if (!filter_var($reset_email, FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}

// CHECK IF ACCOUNT IS EXISTING, IF YES SEND A NEW PASSWORD
if (boolval(db_select_user_account_existe($db, $reset_email)[0]["user_account_existe"])) {
	$pass_text = sec_password_aleatoire();
	$pass_hash = sec_hash($pass_text);

	db_update_password_par_mail($db, $pass_hash, $reset_email);

	try {
		$mail = mail_init();
		$mail->addAddress($reset_email, $reset_email);

		$mail->isHTML(false);
		$mail->Subject = 'Nouveau mot de passe';
		$mail->Body = mail_body_nouveau_mdp($pass_text, $reset_email);
		$mail->AltBody = 'Nouveau mot de passe temporaire: ' . $pass_text;

		$mail->send();
	} catch (\Throwable $e) {
		// swallowed deliberately: the response must stay identical whether or not the
		// account existed / the email went through, to avoid account enumeration (see below)
	}

	sleep(rand(1, 4));
}
// RETURN SUCCESS REGARDLESS, TO AVOID ACCOUNT ENUMERATION
else {
	sleep(rand(1, 5));
}

http_data(200, ["sent" => true]);
