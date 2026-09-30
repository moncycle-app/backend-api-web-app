/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

var moncycle_app_usr = {};
var description_list = [];

const TOTP_STATE_NEVER_USED = 0;
const TOTP_STATE_DISABLED = 1;
const TOTP_STATE_INIT = 2;
const TOTP_STATE_ACTIVE = 3;

$(document).ready(function(){

	// TELECHARGEMENT DES DONNES DES UTILISATEUR
	$.get("api/key_infos", {}).done(function(data) {
		moncycle_app_usr = data;
		$("#f_info_pref")[0].reset();
		$("#name").text(moncycle_app_usr.name);
		document.title = "moncycle.app - compte " + moncycle_app_usr.name;
		$("#i_name").val(moncycle_app_usr.name);
		$("#tech_info_no").text(moncycle_app_usr.no_user_account);
		if(moncycle_app_usr.sponsor) $("#merci_don").show();
		if(moncycle_app_usr.no_user_account == 2 || moncycle_app_usr.no_user_account == 3) $("#warning_demo").show();
		if(moncycle_app_usr.nfp_method == 3 || moncycle_app_usr.nfp_method == 4) $("#description_section").hide();
		$("#tech_info_id").text(moncycle_app_usr.email1);
		$("#i_email1").val(moncycle_app_usr.email1);
		$("#i_email2").val(moncycle_app_usr.email2);
		$(`#m_${moncycle_app_usr.nfp_method}`).attr("checked", "");
		if (moncycle_app_usr.research) $("#i_research").prop('checked', true);
		if (moncycle_app_usr.timeline_asc) $("#i_timeline_asc").prop('checked', true);
		let d = new Date(moncycle_app_usr.date_inscription);
		let m = d.getMonth()+1;
		let j = d.getDate();
		$("#tech_info_insc").text([j<10 ? "0"+j : j, m<10 ? "0"+m : m, d.getFullYear()].join("/"));
		const cette_annee = (new Date()).getFullYear();
		for (let y = (cette_annee - cette_annee%5)-75; y < cette_annee-5; y += 5) {
			var selected = ""
			if (y==moncycle_app_usr.age) selected = 'selected';
			$("#i_anaissance").append(`<option ${selected} value="${y}">entre ${y} et ${y+4}</option>`);
		}
		if (moncycle_app_usr.totp_state < 3) $("#totp_explications").show();
		else $("#totp_state").show();
	}).fail(function (err) {
		if (err.status == 401 || err.status == 403 || err.status == 407) {	
			window.localStorage.clear();
			window.location.replace('/auth');
		}
	});


	// TELECHARGEMENT/MODIFICATION/CRATION/SUPPRESSION DES DESCRIPTIONS BILLINGS
	let check_if_desc_exist = function(desc, type) {
		for (let i = 0; i < description_list.length; i+=1) {
			if (description_list[i].name == desc && description_list[i].type == type) return true;
		}
		return false;
	}
	let load_description = function(data) {
		description_list = data;
		$("#desc_froms_container").empty();
		if (data.length == 0) {
			$("#desc_froms_container").append("<i class='tech_info'>Vous n’avez aucune sensation ou observation renseignée dans l’application.</i>");
			return
		}
		for (const description of data) {
			let input_form = $(`<form 
				class="f_edit_description" method="get" action="api/description" id="f_edit_description_${description.no_description}">
				<input type="hidden" name="no_description" value="${description.no_description}" />
				<input class="i_desc_name" type="text" name="name" value="${description.name}" />
				<select class="i_desc_type" name="type">
					<option ${description.type==2 ? 'selected' : '' } value="2">🧠 Sensations</option>
					<option ${description.type==1 ? 'selected' : '' } value="1">👀 Observation</option>
					<option ${description.type==0 ? 'selected' : '' } value="0" disabled>❓ à définir</option>
				</select>
				<span class="i_desc_count" title="Nombre de jours associés à cette description">${description.use_count}</span></form>`);
			let input_del = $(`<form 
				class="f_delete_description" method="delete" action="api/description" id="f_delete_description_${description.no_description}">
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
			let name = $(this).closest('form').find(".i_desc_name").val();
			let type = $(this).closest('form').find(".i_desc_type").val();
			if (check_if_desc_exist(name, type)) {
				$("#desc_net_stat").html(' ❌&nbsp;description doublon');
				return;
			}
			let form_data = $(this).closest('form').serializeArray();
			$.post("api/description", $.param(form_data)).done(function(ret){
				if (ret.err) {
					console.error(ret.err);
					$("#desc_net_stat").html('');
				}
				else $("#desc_net_stat").html(' ✅&nbsp;enregistré');
			}).fail(function(ret){
				$("#desc_net_stat").html('');
				console.error(ret);
			});
		}
		$(".f_edit_description .i_desc_type").on("change", update_desc);
		$(".f_edit_description .i_desc_name").on("keyup", update_desc);
		$(".f_delete_description").on("submit", function(event){
			event.preventDefault();
			let html_form = $(this).closest('form');
			let name = html_form.find(".del_data_name").val();
			let count = parseInt(html_form.find(".del_data_count").val());
			if (count>1 && !confirm(`Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associé à ${count} dates différentes.`)) return;
			else if (count>0 && !confirm(`Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associé à une date.`)) return;
			$("#desc_net_stat").html('⏳');
			$.ajax({type : 'DELETE', "url" : "api/description", "data" : $.param(html_form.serializeArray())}).done(function(ret){
				$("#desc_net_stat").html('');
				if (ret.nb_deleted) {
					$(`#f_edit_description_${ret.no_description}`).remove();
					$(`#f_delete_description_${ret.no_description}`).remove();
					$("#desc_net_stat").html(' ✅&nbsp;supprimé');
				}
				if (ret.err) console.error(ret.err);
			});
		});
		
	};
	$.get("api/description", {}).done(load_description).fail(function (err) {
		if (err.status == 401 || err.status == 403 || err.status == 407) {	
			window.localStorage.clear();
			window.location.replace('/auth');
		}
	});
	$("#f_new_description").on("submit", function(event){
		event.preventDefault();
		if (check_if_desc_exist($("#i_desc_name_new").val(), $("#i_desc_type_new").val())) {
			$("#desc_net_stat").html(' ❌&nbsp;description doublon');
			return;
		}
		$("#desc_net_stat").html('⏳');
		let form_obj = $(this).closest('form');
		$.post("api/description", $.param(form_obj.serializeArray())).done(function(ret){
			if (ret.err) {
				console.error(ret.err);
				$("#desc_net_stat").html('');
			}
			else {
				$("#desc_net_stat").html(' ✅&nbsp;enregistré');
				form_obj[0].reset();
				$.get("api/description", {}).done(load_description).fail(function (err) {
					if (err.status == 401 || err.status == 403 || err.status == 407) {	
						window.localStorage.clear();
						window.location.replace('/auth');
					}
				});
			}
		}).fail(function(ret){
			$("#desc_net_stat").html('');
			console.error(ret);
		});
	});


	// AFFICHAGE VERSION
	$.get("api/version", {}).done(function(data) {
		$("#tech_info_ver").text(data.version ?? "?");
	}).fail(function (err) {
		if (err.status == 401 || err.status == 403 || err.status == 407) {	
			window.localStorage.clear();
			window.location.replace('/auth');
		}
	});


	// MISE A JOURS DES PARAMETTRE DU COMPTE
	$(".auto_save").on("keyup change", function() {
		$("#net_stat").text('⏳');
		localStorage.timeline_asc = $("#i_timeline_asc").prop('checked');
		let val = this.value;
		if ($(this)[0].type=="checkbox") val = $(this)[0].checked ? 1 : 0;
		$.post("../api/account", `${$(this).attr('name')}=${val}`).fail(function(data){
			console.error(data);
			$("#net_stat").html(' ❌&nbsp;erreur');
			$("#net_stat").addClass('rouge');
			$("#net_stat").removeClass('vert');
		}).done(function(data){
			if(data.hasOwnProperty("name")) {
				$("#name").text(data.name);
			}
			$("#net_stat").html(' ✅&nbsp;enregistré');
			$("#net_stat").addClass('vert');
			$("#net_stat").removeClass('rouge');
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
		var form_data = $("#form_mdp_change").serializeArray();
		$.post("../api/password_change", $.param(form_data)).done(function(ret){
			$("#but_mdp_change").prop("disabled", false);
			if (ret.change_ok) {
				$("#form_mdp_change input[type=password]").val('');
				$("#mdp_change_ok").text('✅ enregistré');
			}
			else {
				$("#mdp_ret_msg").html(`❌ <b>erreur:</b> ${ret.message}.`);
			}
		}).fail(function(ret){
			console.error(ret);
			$("#but_mdp_change").prop("disabled", false);
		});
	});

	// TOTP AUTH MULTI FACTEUR
	$("#i_activate_otp").on("click", function(event) {
		$("#i_activate_otp").prop("disabled", true);
		$("#totp_err_msg").html("");
		$.get("api/totp", {}).done(function(data) {
			$("#i_activate_otp").prop("disabled", false);
			$("#totp_explications").hide();
			$("#totp_miseenpalce").show();
			$("#totp_auto_conf").attr("href", data.otpauth);
			$("#totp_qrcode").html(data.qrcode);
			$("#totp_copy_secret").on("click", function(event) {
				navigator.clipboard.writeText(data.init_secret).then(function () {
					$("#totp_copy_secret_ok").text("✅ copié");
				}, function () {
					alert('Failure to copy. Check permissions for clipboard');
				});				
			});
		});
	});

	$("#f_totp_validation").on("submit", function(event) {
		event.preventDefault();
		$("#totp_err_msg").html("");
		var form_data = $("#f_totp_validation").serializeArray();
		$("#f_totp_validation").trigger("reset");
		$.post("../api/totp", $.param(form_data)).done(function(ret){
			if (ret.totp_state == TOTP_STATE_ACTIVE) {
				$("#totp_miseenpalce").hide();
				$("#totp_state").show();
			}
			else {
				$("#totp_err_msg").html("<b>❌&nbsp;erreur:</b> " + ret.message);
			}
		}).fail(function(err) {
			console.error(err);
		});
	});

	$("#f_totp_desac").on("submit", function(event) {
		event.preventDefault();
		$("#totp_err_msg").html("");
		var form_data = $("#f_totp_desac").serializeArray();
		$("#f_totp_desac").trigger("reset");
		$.ajax({type : 'DELETE', "url" : "../api/totp?desactivation", "data" : $.param(form_data)}).done(function(ret){
			if (ret.totp_state == TOTP_STATE_DISABLED) {
				$("#totp_explications").show();
				$("#totp_state").hide();
			}
			else {
				$("#totp_err_msg").html("<b>❌&nbsp;erreur:</b> " + ret.message);
			}
		}).fail(function(err) {
			console.error(err);
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
		var form_data = $("#f_suppr_user_account").serializeArray();
		if (!confirm(moncycle_app_usr.name + ', êtes-vous sur de vouloir supprimer votre compte ainsi que toutes vos données? Cette action est irréversible. 😟')) return;
		$.ajax({type : 'DELETE', "url" : "../api/account", "data" : $.param(form_data)}).done(function(ret){
			if (ret.suppr) {
				window.localStorage.clear();
				alert(moncycle_app_usr.name + ", votre compte a bien été supprimé. 😢💔");
				window.location.replace('auth');
			}
			else alert(moncycle_app_usr.name + ", votre compte n'a pas été supprimé: " + ret.msg);
		}).fail(function(err) {
			console.error(err);
		});
	});

});
