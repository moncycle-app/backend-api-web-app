/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The sign-up page (register.html), as a file of its own: the CSP is script-src 'self', so no inline script.

if (parseInt(localStorage.auth)>0) {
	window.location.replace('..');
}
else {
	moncycle_store.clear_storage();
	$(document).ready(function(){
		let year = new Date().getFullYear();
		yera = year - (year%5);
		for (let yy = year-100; yy < year; yy+=5) {
			$("#i_anaissance").append(`<option value="${yy}">entre ${yy} et ${yy+4}</option>`);
		}
		$("#f_registration").on("submit", function(event) {
			event.preventDefault();
			$("#register_error_msg").text("");
			if ($("#i_email1").val() != $("#i_email1_conf").val()) {
				$("#register_error_msg").text("L'addresse email et sa confirmation ne sont pas identique.");
				return;
			}
			$("#but_register").prop("disabled", true);
			$("#but_register").val("Créer mon compte ⏳");
			let reactivate_form = function() {
				$("#but_register").prop("disabled", false);
				$("#but_register").val("Créer mon compte 🥳");
				$("#r_captcha").attr("src", $("#r_captcha").attr("src") + "?v=" + new Date().getTime());
				$("#i_captcha").val('');
			}
			var method = $('input[name="method"]:checked').val() == "3" ? "fertilityCare" : "billings";
			var payload = {
				firstName: $("#i_firstname").val(),
				email: $("#i_email1").val(),
				birthYear: parseInt($("#i_anaissance").val()),
				captcha: $("#i_captcha").val(),
				method: method,
				temperatureTracking: $("#m_temp").is(':checked'),
				discoveredComment: $("#i_comment").val(),
				okForResearch: $("#jc_research").is(':checked'),
			};
			$.ajax({type : 'POST', "url" : "api/register", contentType: "application/json", "data" : JSON.stringify(payload)}).done(function(ret){
				console.log(ret);
				$("#success_name").text(ret.data.name);
				$("#success_email").text(ret.data.email);
				$("#register_form").hide();
				$("#register_ok").show();
				if (!ret.data.welcomeEmailSent) {
					$("#register_error_msg").text("Le compte a bien été créé, mais le mail avec le mot de passe n'a pas été envoyé. Utilisez « mot de passe perdu » sur la page de connexion.");
				}
			}).fail(function(jqXHR) {
				console.error(jqXHR);
				var err = (jqXHR.responseJSON || {}).error || {};
				const return_codes = {
					already_authenticated: "Compte déjà connecté.",
					registration_disabled: "La création de compte est désactivée.",
					missing_fields: "Données manquantes pour créer le compte.",
					invalid_email: "L'adresse email renseignée n'est pas valide.",
					captcha_invalid: "Erreur dans la saisie du captcha.",
					account_exists: "Un compte existe déjà pour cette adresse email.",
					invalid_birth_year: "La date de naissance n'est pas réaliste.",
					invalid_first_name: "Le prénom est invalide ou trop long.",
					invalid_discovered_comment: "Le commentaire est invalide ou trop long."
				};
				$("#register_error_msg").text(return_codes[err.code] || err.message || "Erreur inconnue.");
				reactivate_form();
			});
		});
	});
}
