<?php
/* MONCYCLE.APP
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/


use PHPMailer\PHPMailer\PHPMailer;

function mail_init(){
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
	return $mail;
}

function mail_body_creation_user_account ($name, $mdp, $mail1) {
	$app_url_var = APP_URL;
	return <<<HTML
	<div style='font-family: sans-serif;'>Bonjour {$name},<br />
	<br />
	Bienvenue sur MONCYCLE.APP!<br />
	<br />
	Voici votre mot de passe temporaire: <b style='font-family: monospace;'>{$mdp}</b><br />
	Ce mot de passe est à changer dans la page "👨‍💻 Mon compte". Pour protéger vos données, pensez à activer l'authentification multifacteur.<br />
	<br />
	<a style='color: #1e824c' href='{$app_url_var}auth?email1={$mail1}'>connectez-vous</a><br />
	<br />
	À bientôt,<br />
	<br />
	<a href='{$app_url_var}' style='color: unset; text-decoration:none'>mon<span style='color: #1e824c;font-weight:bold'>cycle</span>.app</a><br />
	<br />
	<p style="color:gray;font-size:.85em;font-style: italic;">Merci d'utiliser MONCYCLE.APP! Ce mail a été envoyé automatiquement, merci de ne pas y répondre. Vous le recevez car vous avez créé un compte sur MONCYCLE.APP. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, merci de ne plus utiliser MONCYCLE.APP.</p><br />
	</div>
	HTML;
}

function mail_body_nouveau_mdp($mdp, $mail1) {
	$app_url_var = APP_URL;
	return <<<HTML
	<div style='font-family: sans-serif;'>Bonjour,<br />
	<br />
	Voici un nouveau mot de passe temporaire: <b style='font-family: monospace;'>{$mdp}</b><br />
	Ce mot de passe est à changer dans la page "👨‍💻 Mon compte".<br />
	<br />
	<a style='color: #1e824c' href='{$app_url_var}auth?email1={$mail1}'>connectez-vous</a><br />
	<br />
	A bientôt,<br />
	<br />
	<a href='https://www.moncycle.app' style='color: unset; text-decoration:none'>MON<span style='color: #1e824c;font-weight:bold'>CYCLE</span>.APP</a><br />
	<br />
	<p style="color:gray;font-size:.85em;font-style: italic;">Merci d'utiliser MONCYCLE.APP! Ce mail a été envoyé automatiquement, merci de ne pas y répondre. Vous le recevez car vous possédez un compte sur MONCYCLE.APP. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, merci de ne plus utiliser MONCYCLE.APP.</p><br />
	</div>
	HTML;
}

function mail_body_cycle($name, $dh, $fh, $nb_j) {
	$app_url_var = APP_URL;
	return <<<HTML
	<div style='font-family: sans-serif;'>Bonjour {$name},<br />
	<br />
	Vous trouverez en PJ un export au format PDF et CSV de votre cycle du $dh au $fh d'une durée de $nb_j jours.<br />
	<br />
	À bientôt,<br />
	<br />
	<a href='https://www.moncycle.app' style='color: unset; text-decoration:none'>mon<span style='color: #1e824c;font-weight:bold'>cycle</span>.app</a><br />
	<br />
	<p style="color:gray;font-size:.85em;font-style: italic;">Merci d'utiliser MONCYCLE.APP! Ce mail a été envoyé automatiquement, merci de ne pas y répondre. Vous le recevez car vous possédez un compte sur MONCYCLE.APP. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, merci de ne plus utiliser MONCYCLE.APP.</p><br />
	</div>
	HTML;
}

function mail_body_relance ($name, $mail1) {
	$app_url_var = APP_URL;
	return <<<HTML
	<div style='font-family: sans-serif;'>Bonjour {$name},<br />
	<br />
	Cela fait un moment qu'il n'y a pas eu d'activité dans votre tableau.<br />
	<br />
	Tout va bien? Comment pouvons-nous vous aider?<br />
	<ol type='a'>
		<li>Vous avez perdu votre mot de passe?<br /><a style='color: #1e824c' href='{$app_url_var}register?email1={$mail1}'>Réinitialisez votre mot de passe</a></li>
		<li style="margin-top: 10px">L'application ne vous plaît pas?<br /><a style='color: #1e824c' href='https://forms.gle/aA3GrFHAAx8SFdd47'>Dîtes-nous tout</a></li>
		<li style="margin-top: 10px">Vous souhaitez simplement vous connecter?<br /><a style='color: #1e824c' href='{$app_url_var}auth?email1={$mail1}'>C'est par ici</a></li>
		<li style="margin-top: 10px">Un problème? Besoin d'aide?<br />Envoyez-nous un mail à <a style='color: #1e824c' href='mailto:bonjour@moncycle.app'>bonjour@moncycle.app</a></li>
		<li style="margin-top: 10px">Vous ne souhaitez plus utiliser MONCYCLE.APP?<br />Ignorez ce mail, vous n'en recevrez plus d'autre.</li>
	</ol>
	À bientôt,<br />
	<br />
	<a href='https://www.moncycle.app' style='color: unset; text-decoration:none'>mon<span style='color: #1e824c;font-weight:bold'>cycle</span>.app</a><br />
	<br />
	<p style="color:gray;font-size:.85em;font-style: italic;">Merci d'utiliser MONCYCLE.APP! Ce mail a été envoyé automatiquement, merci de ne pas y répondre. Vous le recevez car vous possédez un compte sur MONCYCLE.APP. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, ignorez ce mail, vous n'en recevrez plus d'autre.</p><br />
	</div>
	HTML;
}

function mail_body_account_deletion_warning ($name, $mail1, $jours) {
	$app_url_var = APP_URL;
	return <<<HTML
	<div style='font-family: sans-serif;'>Bonjour {$name},<br />
	<br />
	Nous n'avons constaté aucune activité sur votre compte MONCYCLE.APP depuis longtemps.<br />
	<br />
	Conformément à notre politique de conservation des données (RGPD), votre compte et toutes les données associées seront <b>définitivement supprimés dans {$jours} jours</b> si aucune activité n'est détectée d'ici là.<br />
	<br />
	Pour conserver votre compte, il vous suffit de vous connecter:<br />
	<a style='color: #1e824c' href='{$app_url_var}auth?email1={$mail1}'>connectez-vous</a><br />
	<br />
	Mot de passe oublié?<br /><a style='color: #1e824c' href='{$app_url_var}register?email1={$mail1}'>Réinitialisez-le</a><br />
	<br />
	Un problème? Besoin d'aide? Envoyez-nous un mail à <a style='color: #1e824c' href='mailto:bonjour@moncycle.app'>bonjour@moncycle.app</a><br />
	<br />
	À bientôt,<br />
	<br />
	<a href='https://www.moncycle.app' style='color: unset; text-decoration:none'>mon<span style='color: #1e824c;font-weight:bold'>cycle</span>.app</a><br />
	<br />
	<p style="color:gray;font-size:.85em;font-style: italic;">Merci d'utiliser MONCYCLE.APP! Ce mail a été envoyé automatiquement, merci de ne pas y répondre. Vous le recevez car vous possédez un compte inactif sur MONCYCLE.APP; ce mail est nécessaire pour vous informer, conformément au RGPD, de la suppression prochaine de vos données en l'absence d'activité.</p><br />
	</div>
	HTML;
}

