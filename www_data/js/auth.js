/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The sign-in page (auth.html), as a file of its own: the CSP is script-src 'self', so no inline script.

window.addEventListener("storage", function () {
	if (parseInt(localStorage.auth)>0) {
		window.location.replace('..');
	}
}, false);
if (parseInt(localStorage.auth)>0) {
	window.location.replace('..');
}
else {
	moncycle_store.clear_storage();
	$(document).ready(function(){
		$("#connexion_form").on("submit", function(event) {
			event.preventDefault();
			$("#connexion_but").prop("disabled", true);
			$("#connexion_but").val("Connexion ⏳");
			var payload = {
				email: $("#i_email1").val(),
				password: $("#i_password").val(),
			};
			if ($("#i_code").val()) payload.code = parseInt($("#i_code").val());
			if ($("#captcha_wrapper").is(":visible")) payload.captcha = $("#i_captcha").val();
			$.ajax({
				type: "POST",
				url: "api/login",
				contentType: "application/json",
				data: JSON.stringify(payload),
			}).done(function(ret){
				console.log(ret);
				localStorage.auth = ret.data.userId;
				window.location.replace('..');
			}).fail(function(jqXHR){
				$("#connexion_but").prop("disabled", false);
				$("#connexion_but").val("Connexion 🔑");
				var err = (jqXHR.responseJSON || {}).error || {};
				const return_codes = {
					login_disabled : "La connexion est désactivée.",
					missing_credentials : "E-mail et/ou mot de passe manquant.",
					account_disabled : "Compte désactivé.",
					totp_required : "Bon mot de passe, mais un code TOTP est requis.",
					totp_invalid : "Bon mot de passe, mais le code TOTP est incorrect.",
					invalid_credentials : "Mauvais mot de passe ou compte inexistant.",
					captcha_required : "Captcha manquant ou incorrect.",
					account_locked : "Compte temporairement bloqué après trop de tentatives échouées, réessayez plus tard.",
					rate_limited : "Trop de tentatives depuis ce réseau, réessayez plus tard."
				};
				if ((err.details || {}).captchaRequired) $("#captcha_wrapper").show();
				$("#message_err").empty().append(document.createTextNode(return_codes[err.code] || err.message || "Erreur inconnue."), $("<br>"));

				// the captcha is single-use server-side, refresh it whenever it was shown for this attempt
				if ($("#captcha_wrapper").is(":visible")) {
					$("#l_captcha").attr("src", "api/captcha.php?v=" + new Date().getTime());
					$("#i_captcha").val('');
				}
			});
		});
		$("#reset_form").on("submit", function(event) {
			event.preventDefault();
			$("#reset_but").prop("disabled", true);
			$("#reset_but").val("Rénitialiser ⏳");
			var email = $("#r_email1").val();
			$.ajax({
				type: "POST",
				url: "api/recover_password",
				contentType: "application/json",
				data: JSON.stringify({email: email}),
			}).done(function(ret){
				console.log(ret);
				$("#reset_but").val("Rénitialiser 🤐");
				$("#reset_but").prop("disabled", false);
				if (ret.data && ret.data.sent) {
					$("#reset_form").hide();
					// the address is what was typed: a text node, never HTML
						$("#password_reseted_msg").empty().append(document.createTextNode("📧 Nouveau mot de passe envoyé par mail à : "), $("<br>"), $("<b>").text(email), $("<br>"), $("<br>"), document.createTextNode("(si ce compte existe)"));
				}
			}).fail(function(jqXHR){
				$("#reset_but").val("Rénitialiser 🤐");
				$("#reset_but").prop("disabled", false);
				console.error(jqXHR);
			});
		});
		const email1 = (new URLSearchParams(window.location.search)).get("email1");
		$("#i_email1").val(email1);
		$("#but_demo_bill").on("click", function(event) {
			event.preventDefault();
			$("#i_email1").val("demo.bill@moncycle.app");
			$("#i_password").val("demo");
			$("#connexion_form").submit();
		});
		$("#but_demo_fc").on("click", function(event) {
			event.preventDefault();
			$("#i_email1").val("demo.fc@moncycle.app");
			$("#i_password").val("demo");
			$("#connexion_form").submit();
		});
	});
}
