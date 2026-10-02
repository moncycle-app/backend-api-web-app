<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . "/../vendor/autoload.php";

// Sends one mail from the app. $to lists the addresses (empty ones are skipped), $attachments is
// [file name => content]. True when the SMTP server took it: the callers decide what a failure
// means, so nothing is thrown.
function mail_send(array $to, string $subject, string $html, string $text, array $attachments = []): bool {
	try {
		$mail = new PHPMailer();
		$mail->isSMTP();
		$mail->Host       = SMTP_HOST;
		$mail->SMTPAuth   = true;
		$mail->Username   = SMTP_MAIL;
		$mail->Password   = SMTP_PASSWORD;
		$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		$mail->Port       = SMTP_PORT;
		$mail->CharSet    = 'UTF-8';
		$mail->setFrom(SMTP_MAIL, 'MONCYCLE.APP');

		foreach (array_filter($to) as $address) $mail->addAddress($address, $address);
		$mail->isHTML(true);
		$mail->Subject = $subject;
		$mail->Body = $html;
		$mail->AltBody = $text;
		foreach ($attachments as $file_name => $content) $mail->addStringAttachment($content, $file_name);

		return $mail->send();
	} catch (\Throwable $e) {
		return false;
	}
}

// ---------------------------------------------------------------------------
// The mails. A name or an address comes from a user, so it is escaped before it reaches the HTML.
// ---------------------------------------------------------------------------

// the frame every mail shares: greeting, the content, the sign-off, and the line saying why it was sent
function mail_html(string $greeting, string $content, string $why): string {
	return <<<HTML
	<div style='font-family: sans-serif;'>{$greeting}<br />
	<br />
	{$content}
	À bientôt,<br />
	<br />
	<a href='https://www.moncycle.app' style='color: unset; text-decoration:none'>mon<span style='color: #1e824c;font-weight:bold'>cycle</span>.app</a><br />
	<br />
	<p style="color:gray;font-size:.85em;font-style: italic;">Merci d'utiliser MONCYCLE.APP! Ce mail a été envoyé automatiquement, merci de ne pas y répondre. {$why}</p><br />
	</div>
	HTML;
}

// the usual "why you get this": $because completes "Vous le recevez car ..."
function mail_why(string $because): string {
	return "Vous le recevez car $because. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, merci de ne plus utiliser MONCYCLE.APP.";
}

// a link to a page of the app with the address filled in
function mail_link(string $email, string $page, string $text): string {
	return "<a style='color: #1e824c' href='" . APP_URL . $page . "?email1=" . urlencode($email) . "'>" . $text . "</a>";
}

function mail_send_welcome(string $name, string $email, string $password): bool {
	$name = htmlspecialchars($name);
	$login = mail_link($email, "auth", "connectez-vous");
	$content = <<<HTML
	Bienvenue sur MONCYCLE.APP!<br />
	<br />
	Voici votre mot de passe temporaire: <b style='font-family: monospace;'>{$password}</b><br />
	Ce mot de passe est à changer dans la page "👨‍💻 Mon compte". Pour protéger vos données, pensez à activer l'authentification multifacteur.<br />
	<br />
	{$login}<br />
	<br />
	HTML;
	return mail_send([$email], 'Bienvenue et mot de passe',
		mail_html("Bonjour {$name},", $content, mail_why("vous avez créé un compte sur MONCYCLE.APP")),
		'Bienvenue sur MONCYCLE.APP! Votre mot de passe: ' . $password);
}

function mail_send_new_password(string $email, string $password): bool {
	$login = mail_link($email, "auth", "connectez-vous");
	$content = <<<HTML
	Voici un nouveau mot de passe temporaire: <b style='font-family: monospace;'>{$password}</b><br />
	Ce mot de passe est à changer dans la page "👨‍💻 Mon compte".<br />
	<br />
	{$login}<br />
	<br />
	HTML;
	return mail_send([$email], 'Nouveau mot de passe',
		mail_html("Bonjour,", $content, mail_why("vous possédez un compte sur MONCYCLE.APP")),
		'Nouveau mot de passe temporaire: ' . $password);
}

// The export of a finished cycle (a row of db_select_cycles_finished()), with $files attached:
// [file name => content].
function mail_send_cycle(array $account, string $first_day, string $last_day, int $nb_days, array $files): bool {
	$name = htmlspecialchars($account["name"]);
	$content = <<<HTML
	Vous trouverez en PJ un export au format PDF et CSV de votre cycle du $first_day au $last_day d'une durée de $nb_days jours.<br />
	<br />
	HTML;
	return mail_send([$account["email1"], $account["email2"]], "Cycle de $nb_days jours du $first_day",
		mail_html("Bonjour {$name},", $content, mail_why("vous possédez un compte sur MONCYCLE.APP")),
		"Export de votre cycle du $first_day au $last_day de $nb_days jours.\n\nmoncycle.app", $files);
}

function mail_send_reminder(array $account): bool {
	$name = htmlspecialchars($account["name"]);
	$email = $account["email1"];
	$reset = mail_link($email, "register", "Réinitialisez votre mot de passe");
	$login = mail_link($email, "auth", "C'est par ici");
	$content = <<<HTML
	Cela fait un moment qu'il n'y a pas eu d'activité dans votre tableau.<br />
	<br />
	Tout va bien? Comment pouvons-nous vous aider?<br />
	<ol type='a'>
		<li>Vous avez perdu votre mot de passe?<br />{$reset}</li>
		<li style="margin-top: 10px">L'application ne vous plaît pas?<br /><a style='color: #1e824c' href='https://forms.gle/aA3GrFHAAx8SFdd47'>Dîtes-nous tout</a></li>
		<li style="margin-top: 10px">Vous souhaitez simplement vous connecter?<br />{$login}</li>
		<li style="margin-top: 10px">Un problème? Besoin d'aide?<br />Envoyez-nous un mail à <a style='color: #1e824c' href='mailto:bonjour@moncycle.app'>bonjour@moncycle.app</a></li>
		<li style="margin-top: 10px">Vous ne souhaitez plus utiliser MONCYCLE.APP?<br />Ignorez ce mail, vous n'en recevrez plus d'autre.</li>
	</ol>
	HTML;
	$why = "Vous le recevez car vous possédez un compte sur MONCYCLE.APP. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, ignorez ce mail, vous n'en recevrez plus d'autre.";
	return mail_send([$email, $account["email2"]], "Comment allez-vous?",
		mail_html("Bonjour {$name},", $content, $why),
		"Cela fait longtemps que l'on ne vous a pas vu sur moncycle.app, tout va bien?");
}

function mail_send_deletion_warning(array $account, int $days): bool {
	$name = htmlspecialchars($account["name"]);
	$email = $account["email1"];
	$login = mail_link($email, "auth", "connectez-vous");
	$reset = mail_link($email, "register", "Réinitialisez-le");
	$content = <<<HTML
	Nous n'avons constaté aucune activité sur votre compte MONCYCLE.APP depuis longtemps.<br />
	<br />
	Conformément à notre politique de conservation des données (RGPD), votre compte et toutes les données associées seront <b>définitivement supprimés dans {$days} jours</b> si aucune activité n'est détectée d'ici là.<br />
	<br />
	Pour conserver votre compte, il vous suffit de vous connecter:<br />
	{$login}<br />
	<br />
	Mot de passe oublié?<br />{$reset}<br />
	<br />
	Un problème? Besoin d'aide? Envoyez-nous un mail à <a style='color: #1e824c' href='mailto:bonjour@moncycle.app'>bonjour@moncycle.app</a><br />
	<br />
	HTML;
	$why = "Vous le recevez car vous possédez un compte inactif sur MONCYCLE.APP; ce mail est nécessaire pour vous informer, conformément au RGPD, de la suppression prochaine de vos données en l'absence d'activité.";
	return mail_send([$email, $account["email2"]], "Votre compte moncycle.app va être supprimé dans $days jours",
		mail_html("Bonjour {$name},", $content, $why),
		"Faute d'activité, votre compte moncycle.app sera supprimé dans $days jours. Connectez-vous pour le conserver.");
}
