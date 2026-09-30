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
		window.localStorage.clear();
		window.location.replace('/auth');
	}
}

function moncycle_app_error_message(jqXHR) {
	return ((jqXHR.responseJSON || {}).error || {}).message || "erreur inconnue";
}

// the "nfp_method" 1-4 integer (still used by account.html's radio buttons) conflates the
// NFP method with whether temperature is also tracked; the API exposes those as two
// separate fields (method: "billings"|"fertilityCare", temperatureTracking: bool) instead.
const moncycle_app_nfp_method_table = {1: ["billings", true], 2: ["billings", false], 3: ["fertilityCare", true], 4: ["fertilityCare", false]};
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
			let input_form = $(`<form
				class="f_edit_description" id="f_edit_description_${description.no_description}">
				<input type="hidden" name="no_description" value="${description.no_description}" />
				<input class="i_desc_name" type="text" name="name" value="${description.name}" />
				<select class="i_desc_type" name="type">
					<option ${description.type==2 ? 'selected' : '' } value="2">🧠 Sensations</option>
					<option ${description.type==1 ? 'selected' : '' } value="1">👀 Observation</option>
					<option ${description.type==0 ? 'selected' : '' } value="0" disabled>❓ à définir</option>
				</select>
				<span class="i_desc_count" title="Nombre de jours associés à cette description">${description.use_count}</span></form>`);
			let input_del = $(`<form
				class="f_delete_description" id="f_delete_description_${description.no_description}">
				<input type="hidden" name="no_description" value="${description.no_description}" />
				<input type="hidden" class="del_data_name" value="${description.name}" />
				<input type="hidden" class="del_data_count" value="${description.use_count}" />
				<input class="i_desc_del" type="submit" value="❌" /></form>`);
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
	const moncycle_app_account_field_map = {name: "name", email2: "secondaryEmail", age: "birthYear", timeline_asc: "timelineAscending", research: "research"};
	$(".auto_save").on("keyup change", function() {
		$("#net_stat").text('⏳');
		localStorage.timeline_asc = $("#i_timeline_asc").prop('checked');
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
			$("#net_stat").html(' ✅&nbsp;enregistré');
			$("#net_stat").addClass('vert');
			$("#net_stat").removeClass('rouge');
		}).fail(function(jqXHR){
			console.error(jqXHR);
			$("#net_stat").html(' ❌&nbsp;erreur');
			$("#net_stat").addClass('rouge');
			$("#net_stat").removeClass('vert');
		});
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
		var payload = {oldPassword: $("#i_old_pw").val(), newPassword: $("#i_pw1").val()};
		$.ajax({type: "POST", url: "../api/password_change", contentType: "application/json", data: JSON.stringify(payload)}).done(function(ret){
			$("#but_mdp_change").prop("disabled", false);
			$("#form_mdp_change input[type=password]").val('');
			$("#mdp_change_ok").text('✅ enregistré');
		}).fail(function(jqXHR){
			console.error(jqXHR);
			$("#but_mdp_change").prop("disabled", false);
			$("#mdp_ret_msg").html(`❌ <b>erreur:</b> ${moncycle_app_error_message(jqXHR)}.`);
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
			$("#totp_err_msg").html("<b>❌&nbsp;erreur:</b> " + moncycle_app_error_message(jqXHR));
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
			$("#totp_err_msg").html("<b>❌&nbsp;erreur:</b> " + moncycle_app_error_message(jqXHR));
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
			$("#totp_err_msg").html("<b>❌&nbsp;erreur:</b> " + moncycle_app_error_message(jqXHR));
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

	// SUPPRESSION DU COMPTE
	$("#f_suppr_user_account").on("submit", function(event) {
		event.preventDefault();
		var password = $('#f_suppr_user_account input[name="pw_before_deletion"]').val();
		if (!confirm(moncycle_app_usr.name + ', êtes-vous sur de vouloir supprimer votre compte ainsi que toutes vos données? Cette action est irréversible. 😟')) return;
		$.ajax({type : 'DELETE', "url" : "../api/account", contentType: "application/json", data: JSON.stringify({password: password})}).done(function(){
			window.localStorage.clear();
			alert(moncycle_app_usr.name + ", votre compte a bien été supprimé. 😢💔");
			window.location.replace('auth');
		}).fail(function(jqXHR) {
			alert(moncycle_app_usr.name + ", votre compte n'a pas été supprimé: " + moncycle_app_error_message(jqXHR));
		});
	});

});
