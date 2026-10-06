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
require_once __DIR__ . "/log.php";

// Sends one mail from the app. $kind says which of the six it is (welcome, new_password, secondary_email,
// cycle, reminder, deletion_warning); it is all the log keeps of it, with how many addresses and files:
// never an address, a subject or a body (two of them hold a password). $to lists the addresses
// (empty ones are skipped), $attachments is [file name => content]. True when the SMTP server took
// it: the callers decide what a failure means, so nothing is thrown.
function mail_send(string $kind, array $to, string $subject, string $html, string $text, array $attachments = []): bool {
	$log = ["kind" => $kind, "to" => count(array_filter($to)), "fil" => count($attachments)];
	try {
		$mail = new PHPMailer();
		$mail->isSMTP();
		$mail->Host       = SMTP_HOST;
		$mail->SMTPAuth   = true;
		$mail->Username   = SMTP_MAIL;
		$mail->Password   = SMTP_PASSWORD;
		$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		$mail->Port       = SMTP_PORT;
		$mail->Timeout    = 10; // seconds; PHPMailer's 300 would hold a worker for minutes on a stalled server
		$mail->CharSet    = 'UTF-8';
		$mail->setFrom(SMTP_MAIL, 'MONCYCLE.APP');

		foreach (array_filter($to) as $address) $mail->addAddress($address, $address);
		$mail->isHTML(true);
		$mail->Subject = $subject;
		$mail->Body = $html;
		$mail->AltBody = $text;
		foreach ($attachments as $file_name => $content) $mail->addStringAttachment($content, $file_name);

		if ($mail->send()) {
			log_event("mail.sent", $log);
			return true;
		}
		log_event("mail.failed", $log + ["msg" => log_scrub($mail->ErrorInfo)]);
		return false;
	} catch (\Throwable $e) {
		log_event("mail.failed", $log + ["msg" => get_class($e)]);
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
	return mail_send('welcome', [$email], 'Bienvenue et mot de passe',
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
	return mail_send('new_password', [$email], 'Nouveau mot de passe',
		mail_html("Bonjour,", $content, mail_why("vous possédez un compte sur MONCYCLE.APP")),
		'Nouveau mot de passe temporaire: ' . $password);
}

// The notice to the account's own address that its secondary address changed ("" = removed): the secondary
// one receives every cycle, so whoever holds the account must be able to see it was changed.
function mail_send_secondary_email_changed(string $email, string $new_email2): bool {
	$new = htmlspecialchars($new_email2);
	$what = $new_email2 === "" ? "La deuxième adresse de votre compte a été <b>supprimée</b>." : "La deuxième adresse de votre compte, qui reçoit vos cycles par e-mail, est maintenant <b>{$new}</b>.";
	$login = mail_link($email, "auth", "connectez-vous");
	$content = <<<HTML
	{$what}<br />
	Si ce n'est pas vous, {$login} et changez votre mot de passe depuis la page "👨‍💻 Mon compte".<br />
	<br />
	HTML;
	return mail_send('secondary_email', [$email], 'La deuxième adresse de votre compte a changé',
		mail_html("Bonjour,", $content, mail_why("vous possédez un compte sur MONCYCLE.APP")),
		$new_email2 === "" ? 'La deuxième adresse de votre compte a été supprimée.' : 'La deuxième adresse de votre compte est maintenant ' . $new_email2 . '.');
}

// ---------------------------------------------------------------------------
// The mail of a finished cycle. It has its own layout (a card on a light background, the cycle
// in figures, one row per attachment) and not the shared mail_html() frame, because its footer
// cannot say that mails are needed to run the app: the user can turn this one off.
// ---------------------------------------------------------------------------

// The sentences the HTML and the text version of the cycle mail share: what each attachment is for
// (the NFP one is exactly two sentences: what the file is, then where else it works), and how to turn
// the mail off, as the account page words it (account.html, "Exporter mes données").
function mail_cycle_words(): array {
	return [
		"pdf" => "Le graphique de votre cycle, à partager avec votre moniteur, votre instructrice ou votre médecin.",
		"csv" => "Vos observations jour par jour, à ouvrir dans LibreOffice ou Microsoft Excel pour une analyse plus détaillée.",
		"nfp" => "Le fichier NFP contient toutes les données de ce cycle dans un format d’échange : conservez-le comme sauvegarde, vous pourrez le réimporter dans moncycle.app depuis « Mon compte », section « Importer mes données ». Il est également compatible avec d’autres applications si vous souhaitez migrer vos données.",
		"opt_out" => "Cet envoi est automatique. Vous pouvez le désactiver à tout moment : ouvrez « Mon compte », section « Exporter mes données », puis décochez « Recevoir automatiquement chaque cycle par e-mail ».",
	];
}

// An escaped sentence with French typography: no line break inside « », nor before a colon.
function mail_cycle_typography(string $text): string {
	return str_replace(["« ", " »", " :"], ["«&nbsp;", "&nbsp;»", "&nbsp;:"], htmlspecialchars($text));
}

// One attachment: a colour badge with its format, and what the file is for ($about is escaped by the caller).
function mail_cycle_file(string $format, string $color, string $tint, string $about): string {
	return <<<HTML
	<tr>
		<td width="52" valign="top" style="padding:0 14px 18px 0;">
			<div style="width:52px;line-height:30px;text-align:center;border-radius:8px;background:{$tint};color:{$color};font-size:12px;font-weight:700;letter-spacing:.6px;">{$format}</div>
		</td>
		<td valign="top" style="padding:0 0 18px 0;font-size:14px;line-height:21px;color:#3d4a42;">{$about}</td>
	</tr>
	HTML;
}

function mail_cycle_html(string $name, string $first_day, string $last_day, int $nb_days): string {
	$name = htmlspecialchars($name);
	$account_url = APP_URL . "account";
	$words = mail_cycle_words();
	$files = mail_cycle_file("PDF", "#b03a2e", "#fbeceb", mail_cycle_typography($words["pdf"]))
		. mail_cycle_file("CSV", "#1e824c", "#e6f4ec", mail_cycle_typography($words["csv"]))
		. mail_cycle_file("NFP", "#3949ab", "#eaecf8", mail_cycle_typography($words["nfp"]));
	$opt_out = mail_cycle_typography($words["opt_out"]);
	$wordmark = "mon<span style='color:#1e824c;'>cycle</span>.app";
	return <<<HTML
	<!DOCTYPE html>
	<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="color-scheme" content="light only" />
		<meta name="supported-color-schemes" content="light only" />
		<title>Votre cycle est terminé</title>
	</head>
	<body style="margin:0;padding:0;background:#f1f5f2;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f1f5f2;font-size:1px;line-height:1px;">Votre cycle de {$nb_days} jours est terminé : PDF, CSV et NFP en pièces jointes.</div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f1f5f2" style="background:#f1f5f2;">
	<tr><td align="center" style="padding:28px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
		<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
			<tr><td style="padding:0 6px 14px 6px;font-size:20px;font-weight:700;letter-spacing:-.2px;color:#1b2a21;">{$wordmark}</td></tr>
			<tr><td bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e1e9e3;border-radius:16px;overflow:hidden;">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
					<tr><td height="6" bgcolor="#1e824c" style="height:6px;line-height:6px;font-size:0;background:#1e824c;">&nbsp;</td></tr>
					<tr><td style="padding:32px 32px 6px 32px;">
						<div style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#1e824c;">Cycle terminé</div>
						<div style="padding-top:8px;font-size:24px;line-height:30px;font-weight:700;color:#1b2a21;">Bonjour {$name},</div>
						<div style="padding-top:10px;font-size:15px;line-height:23px;color:#3d4a42;">Voici l’export complet de votre cycle, dans trois formats, pour le consulter, le partager ou le conserver.</div>
					</td></tr>
					<tr><td style="padding:22px 32px 6px 32px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef7f1" style="background:#eef7f1;border-radius:12px;">
							<tr>
								<td style="padding:18px 22px;">
									<div style="font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#5b7566;">Cycle</div>
									<div style="padding-top:3px;font-size:17px;line-height:23px;font-weight:600;color:#17301f;">du {$first_day} au {$last_day}</div>
								</td>
								<td align="right" style="padding:18px 22px;">
									<div style="font-size:34px;line-height:36px;font-weight:700;color:#1e824c;">{$nb_days}</div>
									<div style="font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#5b7566;">jours</div>
								</td>
							</tr>
						</table>
					</td></tr>
					<tr><td style="padding:26px 32px 0 32px;">
						<div style="padding-bottom:16px;font-size:16px;font-weight:700;color:#1b2a21;">Dans les pièces jointes</div>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
							{$files}
						</table>
					</td></tr>
					<tr><td style="padding:6px 32px 8px 32px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f6f7f6" style="background:#f6f7f6;border-radius:12px;">
							<tr><td style="padding:16px 20px;font-size:13px;line-height:20px;color:#4b574f;">
								{$opt_out}
								<div style="padding-top:12px;"><a href="{$account_url}" style="display:inline-block;padding:9px 16px;border-radius:8px;background:#1e824c;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;">Gérer cet envoi</a></div>
							</td></tr>
						</table>
					</td></tr>
					<tr><td style="padding:22px 32px 30px 32px;font-size:15px;line-height:23px;color:#3d4a42;">À bientôt sur <a href="https://www.moncycle.app" style="color:#1b2a21;font-weight:700;text-decoration:none;">{$wordmark}</a></td></tr>
				</table>
			</td></tr>
			<tr><td style="padding:16px 10px 0 10px;font-size:12px;line-height:18px;color:#7a867f;text-align:center;">
				Ce mail a été envoyé automatiquement par MONCYCLE.APP, merci de ne pas y répondre.<br />
				Vous le recevez car l’envoi automatique des cycles est activé sur votre compte.
			</td></tr>
		</table>
	</td></tr>
	</table>
	</body>
	</html>
	HTML;
}

function mail_cycle_text(string $name, string $first_day, string $last_day, int $nb_days): string {
	$account_url = APP_URL . "account";
	$words = mail_cycle_words();
	return "Bonjour {$name},\n\n"
		. "Votre cycle est terminé : du {$first_day} au {$last_day}, {$nb_days} jours. Voici son export complet, en pièces jointes.\n\n"
		. "- PDF : {$words["pdf"]}\n"
		. "- CSV : {$words["csv"]}\n"
		. "- NFP : {$words["nfp"]}\n\n"
		. "{$words["opt_out"]}\n{$account_url}\n\n"
		. "À bientôt sur moncycle.app\n\n"
		. "Ce mail a été envoyé automatiquement par MONCYCLE.APP, merci de ne pas y répondre. Vous le recevez car l'envoi automatique des cycles est activé sur votre compte.";
}

// The export of a finished cycle (a row of db_select_cycles_finished()), with $files attached:
// [file name => content], the PDF, the CSV and the NFP file.
function mail_send_cycle(array $account, string $first_day, string $last_day, int $nb_days, array $files): bool {
	return mail_send('cycle', [$account["email1"], $account["email2"]], "Votre cycle du $first_day au $last_day ($nb_days jours)",
		mail_cycle_html($account["name"], $first_day, $last_day, $nb_days),
		mail_cycle_text($account["name"], $first_day, $last_day, $nb_days), $files);
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
	return mail_send('reminder', [$email, $account["email2"]], "Comment allez-vous?",
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
	return mail_send('deletion_warning', [$email, $account["email2"]], "Votre compte moncycle.app va être supprimé dans $days jours",
		mail_html("Bonjour {$name},", $content, $why),
		"Faute d'activité, votre compte moncycle.app sera supprimé dans $days jours. Connectez-vous pour le conserver.");
}
