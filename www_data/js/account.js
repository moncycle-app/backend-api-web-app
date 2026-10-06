/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

var moncycle_app_usr = {};
var description_list = [];

/* ===========================================================================
** USER-FACING STRINGS
**
** Same convention as js/tableau.js: the text the user reads lives here rather than in the
** logic below, so translating a page is one object to go through and not a hunt. It covers
** the NFP import for now -- the older sections of this file still have their strings inline,
** and new ones belong here.
** ======================================================================== */

const moncycle_app_text = {

	/* --- counts --------------------------------------------------------- */
	// "1 jour" / "6 jours": French agrees the noun from 2 on, so 0 and 1 stay singular
	count : function (n, one, many) {
		return `${n} ${n > 1 ? many : one}`;
	},

	/* --- NFP import: the form ------------------------------------------- */
	import_pick_file : "\u274C&nbsp;choisissez un fichier.",
	import_unreadable : "\u274C&nbsp;fichier illisible.",
	import_checking : "\u23F3&nbsp;vérification\u2026",
	import_writing : "\u23F3&nbsp;import\u2026",
	import_but_check : "\uD83D\uDD0D Vérifier le fichier",
	import_but_check_busy : "\uD83D\uDD0D Vérification\u2026",
	import_but_submit : "\uD83D\uDCE5 Importer",
	import_but_submit_busy : "\uD83D\uDCE5 Import\u2026",
	// asked before a real import that is allowed to replace what is already there, since that
	// is the only thing this form can destroy
	import_confirm_override : function (filename) {
		return `Importer « ${filename} » en écrasant les jours déjà renseignés ?\n\nLes journées de votre tableau dont la date figure dans le fichier seront entièrement remplacées, et leur contenu actuel sera définitivement perdu.\n\nPour savoir lesquelles sont concernées avant de vous décider, annulez et utilisez « Vérifier le fichier » : la simulation n\u2019écrit rien.`;
	},

	/* --- NFP import: the report ----------------------------------------- */
	import_head_check : "\uD83D\uDD0D Simulation : rien n\u2019a été écrit dans votre tableau.",
	import_head_done : "\u2705 Import terminé.",
	import_head_refused : "\u274C Fichier refusé : rien n\u2019a été écrit dans votre tableau.",
	import_source : function (version, app) {
		return app ? `Fichier NFP ${version}, écrit par ${app}.` : `Fichier NFP ${version}.`;
	},
	import_read : function (cycles, days) {
		return `${moncycle_app_text.count(cycles, "cycle lu", "cycles lus")}, ${moncycle_app_text.count(days, "jour lu", "jours lus")}.`;
	},
	import_nothing_to_write : "Aucune journée renseignée dans ce fichier : il n\u2019y a rien à importer.",
	import_back_to_timeline : "\uD83D\uDC48 Revenez aux cycles pour voir vos données.",

	// the per-day outcomes. Each heading is the summary line of a foldable list of dates.
	import_days_created : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "jour sera créé", "jours seront créés")} dans votre tableau.`
			: `${moncycle_app_text.count(n, "jour créé", "jours créés")}.`;
	},
	import_days_overwritten : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "jour déjà renseigné sera entièrement remplacé", "jours déjà renseignés seront entièrement remplacés")}.`
			: `${moncycle_app_text.count(n, "jour remplacé", "jours remplacés")}.`;
	},
	import_days_skipped : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "jour déjà renseigné sera ignoré", "jours déjà renseignés seront ignorés")} : cochez « Écraser les jours déjà renseignés » pour les remplacer.`
			: `${moncycle_app_text.count(n, "jour ignoré", "jours ignorés")} car déjà renseignés : cochez « Écraser les jours déjà renseignés » et relancez l\u2019import pour les remplacer.`;
	},
	import_descriptions : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "nouvelle sensation/observation sera créée", "nouvelles sensations/observations seront créées")}.`
			: `${moncycle_app_text.count(n, "sensation/observation créée", "sensations/observations créées")}.`;
	},

	// the three "nothing is dropped silently" lists the API answers with
	import_warnings : function (n) {
		return `\u26A0\uFE0F ${moncycle_app_text.count(n, "avertissement", "avertissements")} : les données sont importées, mais lisez ceci.`;
	},
	import_mapped : function (n) {
		return `\u2139\uFE0F ${moncycle_app_text.count(n, "information importée de façon simplifiée", "informations importées de façon simplifiée")}.`;
	},
	import_ignored : function (n) {
		return `\u2139\uFE0F ${moncycle_app_text.count(n, "information du fichier que cette application ne sait pas conserver", "informations du fichier que cette application ne sait pas conserver")}.`;
	},

	// a refused file
	import_issues : function (shown, total) {
		return shown < total
			? `\u274C ${total} problèmes, dont les ${shown} premiers :`
			: `\u274C ${moncycle_app_text.count(total, "problème", "problèmes")} :`;
	},
	import_duplicates : function (n) {
		return `\u274C ${moncycle_app_text.count(n, "date est présente deux fois dans le fichier", "dates sont présentes deux fois dans le fichier")} : deux de ses cycles se chevauchent, et la journée appartiendrait aux deux. Le fichier doit être corrigé avant d\u2019être importé.`;
	},
	import_duplicates_list : function (n) {
		return moncycle_app_text.count(n, "date en double", "dates en double");
	},
	import_list_more : function (n) {
		return `\u2026 et ${moncycle_app_text.count(n, "autre", "autres")}.`;
	},
};

/* ===========================================================================
** API ADAPTERS
**
** The backend speaks a structured JSON API (see api/moncycle_app_open_api.yaml):
** every response is {"data": ...} on success or {"error": {"code","message"}} on
** failure, and a few fields that used to be a single overloaded integer are now
** split into more explicit ones. These small helpers are the only place in this
** file that need to know about that -- everything else below still works with
** the same flat field names (no_description, type as 0/1/2, ...) it always has.
** ======================================================================== */

function moncycle_app_redirect_if_unauthenticated(jqXHR) {
	if (jqXHR.status == 401) {
		moncycle_store.clear_storage();
		window.location.replace('/auth');
	}
}

// Something changed on the server that the local copy (js/store.js) shows: the copy is brought up to
// date once the changes stop coming (a name typed letter by letter is one change per letter).
let moncycle_app_sync_timer = null;
function moncycle_app_sync_later() {
	clearTimeout(moncycle_app_sync_timer);
	moncycle_app_sync_timer = setTimeout(function () { moncycle_store.sync().catch(function () { }); }, 1500);
}

function moncycle_app_error_message(jqXHR) {
	return ((jqXHR.responseJSON || {}).error || {}).message || "erreur inconnue";
}

// "<prefix><b>label</b> message<suffix>" in the element. The label is ours; the message is the server's and
// can quote what the user typed (duplicate_name echoes the label), so it goes in as a text node. Never
// through append(string): jQuery reads a string with a "<" or an entity in it as HTML.
function moncycle_app_show_error(target, label, message, prefix = "", suffix = "") {
	target.empty().append(document.createTextNode(prefix), $("<b>").text(label), document.createTextNode(" " + message + suffix));
}

// Report lines from /api/import quote what was in the file -- a sensation name, the app that
// wrote it -- so they are never dropped into HTML as they come.
function moncycle_app_escape_html(text) {
	return $("<span>").text(text === null || text === undefined ? "" : text).html();
}

// the "nfp_method" 1-4 integer (still used by account.html's radio buttons) conflates the
// NFP method with whether temperature is also tracked; the API exposes those as two
// separate fields (method: "billings"|"fertilityCare", temperatureTracking: bool) instead.
const moncycle_app_nfp_method_table = {1: ["billings", true], 2: ["billings", false], 3: ["fertilityCare", false], 4: ["fertilityCare", true]};
function moncycle_app_nfp_method_to_api(nfp_method) {
	let m = moncycle_app_nfp_method_table[nfp_method] || ["billings", false];
	return {method: m[0], temperatureTracking: m[1]};
}
function moncycle_app_nfp_method_from_api(method, temperatureTracking) {
	for (const k in moncycle_app_nfp_method_table) {
		let m = moncycle_app_nfp_method_table[k];
		if (m[0] == method && m[1] == !!temperatureTracking) return parseInt(k);
	}
	return 2;
}

const moncycle_app_desc_type_to_int = {"undefined": 0, "observation": 1, "sensation": 2};
const moncycle_app_desc_type_from_int = {0: "undefined", 1: "observation", 2: "sensation"};
function moncycle_app_description_from_api(d) {
	return {no_description: d.id, name: d.name, type: moncycle_app_desc_type_to_int[d.type] || 0, use_count: d.useCount};
}

$(document).ready(function(){

	moncycle_store.init();

	// the local copy goes with the session (the link itself is the request that ends it)
	$("#but_logout").on("click", function () { moncycle_store.clear_storage(); });

	// TELECHARGEMENT DES DONNES DES UTILISATEUR
	$.get("api/key_infos", {}).done(function(ret) {
		moncycle_app_usr = ret.data;
		$("#f_info_pref")[0].reset();
		$("#name").text(moncycle_app_usr.name);
		document.title = "moncycle.app - compte " + moncycle_app_usr.name;
		$("#i_name").val(moncycle_app_usr.name);
		$("#tech_info_no").text(moncycle_app_usr.userId);
		if(moncycle_app_usr.sponsor) $("#merci_don").show();
		if(moncycle_app_usr.userId == 2 || moncycle_app_usr.userId == 3) $("#warning_demo").show();
		if(moncycle_app_usr.method == "fertilityCare") $("#description_section").hide();
		$("#tech_info_id").text(moncycle_app_usr.email);
		$("#i_email1").val(moncycle_app_usr.email);
		$("#i_email2").val(moncycle_app_usr.secondaryEmail);
		let nfp_method = moncycle_app_nfp_method_from_api(moncycle_app_usr.method, moncycle_app_usr.temperatureTracking);
		$(`#m_${nfp_method}`).attr("checked", "");
		if (moncycle_app_usr.research) $("#i_research").prop('checked', true);
		if (moncycle_app_usr.timelineAscending) $("#i_timeline_asc").prop('checked', true);
		$("#i_auto_mail_export").prop('checked', !!moncycle_app_usr.autoMailExport);
		let d = new Date(moncycle_app_usr.inscriptionDate);
		let m = d.getMonth()+1;
		let j = d.getDate();
		$("#tech_info_insc").text([j<10 ? "0"+j : j, m<10 ? "0"+m : m, d.getFullYear()].join("/"));
		const cette_annee = (new Date()).getFullYear();
		for (let y = (cette_annee - cette_annee%5)-75; y < cette_annee-5; y += 5) {
			var selected = ""
			if (y==moncycle_app_usr.birthYear) selected = 'selected';
			$("#i_anaissance").append(`<option ${selected} value="${y}">entre ${y} et ${y+4}</option>`);
		}
		if (moncycle_app_usr.totpState != "active") $("#totp_explications").show();
		else $("#totp_state").show();
	}).fail(moncycle_app_redirect_if_unauthenticated);


	// TELECHARGEMENT/MODIFICATION/CRATION/SUPPRESSION DES DESCRIPTIONS BILLINGS
	let check_if_desc_exist = function(desc, type) {
		for (let i = 0; i < description_list.length; i+=1) {
			if (description_list[i].name == desc && description_list[i].type == type) return true;
		}
		return false;
	}
	let load_description = function(ret) {
		let data = ret.data.map(moncycle_app_description_from_api);
		description_list = data;
		$("#desc_froms_container").empty();
		if (data.length == 0) {
			$("#desc_froms_container").append("<i class='tech_info'>Vous n’avez aucune sensation ou observation renseignée dans l’application.</i>");
			return
		}
		for (const description of data) {
			// built with the DOM, never as HTML: a name is free text and would break out of value="..."
			let input_form = $("<form>", {class: "f_edit_description", id: "f_edit_description_" + description.no_description}).append(
				$("<input>", {type: "hidden", name: "no_description"}).val(description.no_description),
				$("<input>", {class: "i_desc_name", type: "text", name: "name", maxlength: 256}).val(description.name),
				$("<select>", {class: "i_desc_type", name: "type"}).append(
					$("<option>", {value: "2", selected: description.type == 2}).text("🧠 Sensations"),
					$("<option>", {value: "1", selected: description.type == 1}).text("👀 Observation"),
					$("<option>", {value: "0", selected: description.type == 0, disabled: true}).text("❓ à définir")),
				$("<span>", {class: "i_desc_count", title: "Nombre de jours associés à cette description"}).text(description.use_count));
			let input_del = $("<form>", {class: "f_delete_description", id: "f_delete_description_" + description.no_description}).append(
				$("<input>", {type: "hidden", name: "no_description"}).val(description.no_description),
				$("<input>", {type: "hidden", class: "del_data_name"}).val(description.name),
				$("<input>", {type: "hidden", class: "del_data_count"}).val(description.use_count),
				$("<input>", {class: "i_desc_del", type: "submit"}).val("❌"));
			$("#desc_froms_container").append(input_form);
			$("#desc_froms_container").append(input_del);
		}
		let update_desc = function (e) {
			e.stopPropagation();
			$("#desc_net_stat").html('⏳');
			let form = $(this).closest('form');
			let name = form.find(".i_desc_name").val();
			let type_int = parseInt(form.find(".i_desc_type").val());
			if (check_if_desc_exist(name, type_int)) {
				$("#desc_net_stat").html(' ❌&nbsp;description doublon');
				return;
			}
			let id = parseInt(form.find('input[name="no_description"]').val());
			let payload = {name: name, type: moncycle_app_desc_type_from_int[type_int], id: id};
			$.ajax({type: "POST", url: "api/description", contentType: "application/json", data: JSON.stringify(payload)}).done(function(ret){
				$("#desc_net_stat").html(' ✅&nbsp;enregistré');
				moncycle_app_sync_later();
			}).fail(function(jqXHR){
				$("#desc_net_stat").html('');
				console.error(jqXHR);
			});
		}
		$(".f_edit_description .i_desc_type").on("change", update_desc);
		$(".f_edit_description .i_desc_name").on("keyup", update_desc);
		$(".f_delete_description").on("submit", function(event){
			event.preventDefault();
			let html_form = $(this).closest('form');
			let name = html_form.find(".del_data_name").val();
			let count = parseInt(html_form.find(".del_data_count").val());
			let id = html_form.find('input[name="no_description"]').val();
			if (count>1 && !confirm(`Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associé à ${count} dates différentes.`)) return;
			else if (count>0 && !confirm(`Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associé à une date.`)) return;
			$("#desc_net_stat").html('⏳');
			$.ajax({type : 'DELETE', "url" : "api/description?id=" + encodeURIComponent(id)}).done(function(){
				$("#desc_net_stat").html('');
				$(`#f_edit_description_${id}`).remove();
				$(`#f_delete_description_${id}`).remove();
				$("#desc_net_stat").html(' ✅&nbsp;supprimé');
				moncycle_app_sync_later();
			}).fail(function(jqXHR){
				$("#desc_net_stat").html('');
				console.error(jqXHR);
			});
		});

	};
	$.get("api/description", {}).done(load_description).fail(moncycle_app_redirect_if_unauthenticated);
	$("#f_new_description").on("submit", function(event){
		event.preventDefault();
		let name = $("#i_desc_name_new").val();
		let type_int = parseInt($("#i_desc_type_new").val());
		if (check_if_desc_exist(name, type_int)) {
			$("#desc_net_stat").html(' ❌&nbsp;description doublon');
			return;
		}
		$("#desc_net_stat").html('⏳');
		$.ajax({type: "POST", url: "api/description", contentType: "application/json", data: JSON.stringify({name: name, type: moncycle_app_desc_type_from_int[type_int]})}).done(function(ret){
			$("#desc_net_stat").html(' ✅&nbsp;enregistré');
			$("#f_new_description")[0].reset();
			$.get("api/description", {}).done(load_description).fail(moncycle_app_redirect_if_unauthenticated);
			moncycle_app_sync_later();
		}).fail(function(jqXHR){
			$("#desc_net_stat").html('');
			console.error(jqXHR);
		});
	});


	// AFFICHAGE VERSION
	$.get("api/version", {}).done(function(data) {
		$("#tech_info_ver").text(data.version ?? "?");
	}).fail(moncycle_app_redirect_if_unauthenticated);


	// MISE A JOURS DES PARAMETTRE DU COMPTE
	const moncycle_app_account_field_map = {name: "name", age: "birthYear", timeline_asc: "timelineAscending", research: "research", auto_mail_export: "autoMailExport"};
	$(".auto_save").on("keyup change", function() {
		// the status goes next to the section the field is in (data-net-stat), the settings one by default
		let net_stat = $("#" + ($(this).data("net-stat") || "net_stat"));
		net_stat.text('⏳');
		let field_name = $(this).attr('name');
		let payload = {};
		if (field_name == "nfp_method") {
			Object.assign(payload, moncycle_app_nfp_method_to_api(parseInt(this.value)));
		}
		else {
			let val = this.value;
			if (this.type == "checkbox") val = this.checked;
			else if (field_name == "age") val = parseInt(val);
			payload[moncycle_app_account_field_map[field_name] || field_name] = val;
		}
		$.ajax({
			type: "POST",
			url: "../api/account",
			contentType: "application/json",
			data: JSON.stringify(payload),
		}).done(function(ret){
			if (ret.data && ret.data.name) $("#name").text(ret.data.name);
			net_stat.html(' ✅&nbsp;enregistré');
			net_stat.addClass('vert');
			net_stat.removeClass('rouge');
			moncycle_app_sync_later();
		}).fail(function(jqXHR){
			console.error(jqXHR);
			net_stat.html(' ❌&nbsp;erreur');
			net_stat.addClass('rouge');
			net_stat.removeClass('vert');
		});
	});


	// THE SECOND ADDRESS: it receives every cycle by mail, so the password confirms a change
	$("#but_email2_save").on("click", function() {
		let net_stat = $("#net_stat_email2");
		net_stat.removeClass("vert rouge").text('⏳');
		$.ajax({
			type: "POST",
			url: "../api/account",
			contentType: "application/json",
			data: JSON.stringify({secondaryEmail: $("#i_email2").val(), password: $("#i_email2_password").val()}),
		}).done(function() {
			$("#i_email2_password").val('');
			net_stat.addClass('vert').text(' ✅ enregistré');
			moncycle_app_sync_later();
		}).fail(function(jqXHR) {
			console.error(jqXHR);
			net_stat.addClass('rouge').text(" ❌ " + moncycle_app_error_message(jqXHR));
		});
	});
	$("#i_email2_password").on("keydown", function(event) {
		if (event.key !== "Enter") return;
		event.preventDefault();
		$("#but_email2_save").click();
	});

	// CHANGEMENT DU MOT DE PASSE
	$("#form_mdp_change").on("submit", function(event) {
		event.preventDefault();
		$("#mdp_change_ok").text('');
		$("#mdp_ret_msg").text('');
		if ($("#i_pw1").val() != $("#i_pw2").val()) {
			$("#mdp_ret_msg").html("❌ <b>erreur:</b> le nouveau mot de passe et sa confirmation ne sont pas identiques.");
			return;
		}
		$("#but_mdp_change").prop("disabled", true);
		var payload = {oldPassword: $("#i_old_pw").val(), newPassword: $("#i_pw1").val(), logoutOtherDevices: $("#i_logout_other_devices").is(":checked")};
		$.ajax({type: "POST", url: "../api/password_change", contentType: "application/json", data: JSON.stringify(payload)}).done(function(ret){
			$("#but_mdp_change").prop("disabled", false);
			$("#form_mdp_change input[type=password]").val('');
			$("#i_logout_other_devices").prop("checked", false);
			$("#mdp_change_ok").text('✅ enregistré');
		}).fail(function(jqXHR){
			console.error(jqXHR);
			$("#but_mdp_change").prop("disabled", false);
			moncycle_app_show_error($("#mdp_ret_msg"), "erreur:", moncycle_app_error_message(jqXHR), "❌ ", ".");
		});
	});

	// TOTP AUTH MULTI FACTEUR
	$("#i_activate_otp").on("click", function(event) {
		$("#i_activate_otp").prop("disabled", true);
		$("#totp_err_msg").html("");
		$.get("api/totp", {}).done(function(ret) {
			$("#i_activate_otp").prop("disabled", false);
			$("#totp_explications").hide();
			$("#totp_miseenpalce").show();
			$("#totp_auto_conf").attr("href", ret.data.otpauth);
			$("#totp_qrcode").html(ret.data.qrcode);
			$("#totp_copy_secret").on("click", function(event) {
				navigator.clipboard.writeText(ret.data.initSecret).then(function () {
					$("#totp_copy_secret_ok").text("✅ copié");
				}, function () {
					alert('Failure to copy. Check permissions for clipboard');
				});
			});
		}).fail(function(jqXHR) {
			$("#i_activate_otp").prop("disabled", false);
			moncycle_app_show_error($("#totp_err_msg"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
		});
	});

	$("#f_totp_validation").on("submit", function(event) {
		event.preventDefault();
		$("#totp_err_msg").html("");
		var code = $('#f_totp_validation input[name="tmp_code"]').val();
		$("#f_totp_validation").trigger("reset");
		$.ajax({type: "POST", url: "../api/totp", contentType: "application/json", data: JSON.stringify({code: parseInt(code)})}).done(function(ret){
			if (ret.data.totpState == "active") {
				$("#totp_miseenpalce").hide();
				$("#totp_state").show();
			}
		}).fail(function(jqXHR) {
			moncycle_app_show_error($("#totp_err_msg"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
		});
	});

	$("#f_totp_desac").on("submit", function(event) {
		event.preventDefault();
		$("#totp_err_msg").html("");
		var code = $('#f_totp_desac input[name="tmp_code"]').val();
		$("#f_totp_desac").trigger("reset");
		$.ajax({type : 'DELETE', "url" : "../api/totp?code=" + encodeURIComponent(code)}).done(function(ret){
			if (ret.data.totpState == "disabled") {
				$("#totp_explications").show();
				$("#totp_state").hide();
			}
		}).fail(function(jqXHR) {
			moncycle_app_show_error($("#totp_err_msg"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
		});
	});

	// EXPORT PDF
	$("#i_start_date").attr("max", new Date().toISOString().substr(0, 10));
	$("#i_end_date").attr("max", new Date().toISOString().substr(0, 10));
	$("#f_export_complexe").on("submit", function(event) {
		event.preventDefault();
		var start_date = $("#i_start_date").val();
		var end_date = $("#i_end_date").val();
		var file_format = $("#i_file_format").val();
		if (start_date == "" || end_date == "") $("#export_err").html("<br /><b>❌&nbsp;erreur:</b> dates vides.");
		else if (new Date(start_date) >= new Date(end_date)) $("#export_err").html("<br /><b>❌&nbsp;erreur:</b> la 1ère date doit être antérieur à la 2ème.");
		else window.location.replace(`api/export?type=${file_format}&start_date=${start_date}&end_date=${end_date}`);
	});

	// IMPORT NFP
	//
	// One form, two buttons. "Vérifier le fichier" asks api/import for a dry run: it runs every
	// check, reads the account to see which of the file's days are already there, and reports
	// what the write would do -- without writing. "Importer" does it for real. Both answer the
	// same report, and on a dry run a refused file answers an error whose details carry the same
	// lists, so one renderer draws all three outcomes.
	let import_running = false;

	// A foldable list, because these can run to thousands of dates. The limit keeps the page
	// from drawing them all; the count in the heading is always the true one.
	let import_list = function (heading, items, limit) {
		items = items || [];
		if (items.length == 0) return "";
		let shown = items.slice(0, limit);
		let lines = shown.map(function (item) { return `<li>${moncycle_app_escape_html(item)}</li>`; }).join("");
		if (items.length > shown.length) {
			lines += `<li class="label_info">${moncycle_app_text.import_list_more(items.length - shown.length)}</li>`;
		}
		return `<details class="import_list"><summary>${heading}</summary><ul>${lines}</ul></details>`;
	};

	let import_line = function (text, css) {
		return `<p class="import_line ${css || ''}">${text}</p>`;
	};

	// An accepted file, dry run or real: the same report either way, with the verbs put in the
	// future tense on a dry run.
	let import_render_report = function (report) {
		let dry = !!report.dryRun;
		let html = import_line(dry ? moncycle_app_text.import_head_check : moncycle_app_text.import_head_done, dry ? "import_head" : "import_head vert");

		html += import_line(moncycle_app_escape_html(moncycle_app_text.import_source(report.schemaVersion, report.sourceApp)), "label_info");
		html += import_line(moncycle_app_text.import_read(report.cyclesRead, report.daysRead), "label_info");

		if (report.daysToWrite == 0) html += import_line(moncycle_app_text.import_nothing_to_write);

		// the three per-day outcomes, each a date list
		html += import_list(moncycle_app_text.import_days_created((report.daysCreated || []).length, dry), report.daysCreated, 40);
		html += import_list(moncycle_app_text.import_days_overwritten((report.daysOverwritten || []).length, dry), report.daysOverwritten, 40);
		html += import_list(moncycle_app_text.import_days_skipped((report.daysSkipped || []).length, dry), report.daysSkipped, 40);

		if (report.descriptionsCreated > 0) html += import_line(moncycle_app_text.import_descriptions(report.descriptionsCreated, dry), "label_info");

		html += import_render_notes(report);

		if (!dry && report.daysWritten > 0) html += `<p class="import_line"><a href="/">${moncycle_app_text.import_back_to_timeline}</a></p>`;
		return html;
	};

	// warnings / mappedFields / ignoredFields: what the file carries that is not simply stored
	// as it was. The API fills them on an accepted file and, on a dry run, on a refused one too.
	let import_render_notes = function (source) {
		let html = "";
		html += import_list(moncycle_app_text.import_warnings((source.warnings || []).length), source.warnings, 50);
		html += import_list(moncycle_app_text.import_mapped((source.mappedFields || []).length), source.mappedFields, 50);
		html += import_list(moncycle_app_text.import_ignored((source.ignoredFields || []).length), source.ignoredFields, 50);
		return html;
	};

	let import_render_error = function (jqXHR) {
		let error = (jqXHR.responseJSON || {}).error || {};
		let details = error.details || {};
		let html = import_line(moncycle_app_text.import_head_refused, "import_head rouge");
		// the API's own wording for the refusal, shown as it comes
		html += import_line(moncycle_app_escape_html(error.message || moncycle_app_error_message(jqXHR)), "label_info");

		// Dates the file itself carries twice. They are among the issues below as well, but
		// a list of its own is what makes the file fixable.
		if ((details.duplicatedDaysInFile || []).length > 0) {
			html += import_line(moncycle_app_text.import_duplicates(details.duplicatedDaysInFile.length), "rouge");
			html += import_list(moncycle_app_text.import_duplicates_list(details.duplicatedDaysInFile.length), details.duplicatedDaysInFile, 40);
		}

		let issues = details.issues || [];
		if (issues.length > 0) html += import_list(moncycle_app_text.import_issues(issues.length, details.issueCount || issues.length), issues, 50);

		html += import_render_notes(details);
		return html;
	};

	let import_run = function (dry_run) {
		if (import_running) return;

		let file = $("#i_import_file")[0].files[0];
		if (!file) {
			$("#import_net_stat").html(moncycle_app_text.import_pick_file).addClass("rouge").removeClass("vert");
			return;
		}

		let override = $("#i_import_override").prop("checked");
		if (!dry_run && override && !confirm(moncycle_app_text.import_confirm_override(file.name))) return;

		import_running = true;
		$("#import_report").empty();
		$("#import_net_stat").html(dry_run ? moncycle_app_text.import_checking : moncycle_app_text.import_writing).removeClass("rouge vert");
		$("#i_import_check").prop("disabled", true).val(dry_run ? moncycle_app_text.import_but_check_busy : moncycle_app_text.import_but_check);
		$("#i_import_submit").prop("disabled", true).val(dry_run ? moncycle_app_text.import_but_submit : moncycle_app_text.import_but_submit_busy);

		let import_done = function () {
			import_running = false;
			$("#import_net_stat").html("");
			$("#i_import_check").prop("disabled", false).val(moncycle_app_text.import_but_check);
			$("#i_import_submit").prop("disabled", false).val(moncycle_app_text.import_but_submit);
		};

		// api/import reads the file as the request body, so it goes up as it is -- no multipart
		// form, and processData off so jQuery does not try to encode the string.
		file.text().then(function (body) {
			let query = $.param({
				dryRun: dry_run ? 1 : 0,
				override: override ? 1 : 0,
				lastWriteClientUtc: (new Date()).toISOString(),
			});
			$.ajax({
				type: "POST",
				url: `api/import?${query}`,
				contentType: "application/json",
				data: body,
				processData: false,
			}).done(function (ret) {
				import_done();
				$("#import_report").html(import_render_report(ret.data || {}));
				// A real import adds days and cycles: the local copy gets them with a sync.
				if (!dry_run) moncycle_app_sync_later();
			}).fail(function (jqXHR) {
				import_done();
				moncycle_app_redirect_if_unauthenticated(jqXHR);
				console.error(jqXHR);
				$("#import_report").html(import_render_error(jqXHR));
			});
		}, function (err) {
			import_done();
			console.error(err);
			$("#import_net_stat").html(moncycle_app_text.import_unreadable).addClass("rouge");
		});
	};

	$("#i_import_check").on("click", function () { import_run(true); });
	$("#f_import").on("submit", function (event) {
		event.preventDefault();
		import_run(false);
	});
	$("#i_import_file").on("change", function () {
		$("#import_report").empty();
		$("#import_net_stat").html("").removeClass("rouge vert");
	});


	// SUPPRESSION DU COMPTE
	$("#f_suppr_user_account").on("submit", function(event) {
		event.preventDefault();
		var password = $('#f_suppr_user_account input[name="pw_before_deletion"]').val();
		if (!confirm(moncycle_app_usr.name + ', êtes-vous sur de vouloir supprimer votre compte ainsi que toutes vos données? Cette action est irréversible. 😟')) return;
		$.ajax({type : 'DELETE', "url" : "../api/account", contentType: "application/json", data: JSON.stringify({password: password})}).done(function(){
			moncycle_store.clear_storage();
			alert(moncycle_app_usr.name + ", votre compte a bien été supprimé. 😢💔");
			window.location.replace('auth');
		}).fail(function(jqXHR) {
			alert(moncycle_app_usr.name + ", votre compte n'a pas été supprimé: " + moncycle_app_error_message(jqXHR));
		});
	});

});
