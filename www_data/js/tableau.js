/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/* ===========================================================================
** USER-FACING TEXT
**
** Every string shown to the user lives in this block and nowhere else in this
** file, so it can be translated (or moved to its own file) without touching
** any logic. Strings that need values injected are functions taking raw
** values (Date, number) and doing their own formatting, because both the
** wording and the layout of a date change with the language.
**
** Deliberately NOT here, these are not language:
**  - CSS class names and DOM id fragments (rouge, vert, jaune, baby, b/h/d/g),
**    see moncycle_app.stamp_class and moncycle_app.arrow_id;
**  - FertilityCare / Billings notation codes (VL, H, B, AD, RAP, 10KL, X1...),
**    which belong to the method itself.
** ======================================================================== */
const moncycle_app_text = {

	/* --- calendar ------------------------------------------------------- */
	months : ["jan", "fév", "mars", "avr", "mai", "juin", "juil", "août", "sep", "oct", "nov", "déc"],
	months_long : ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"],
	weekdays : ["dimanche", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi"],
	// "3 mars"
	date_short : function (d) {
		return `${d.getDate()} ${moncycle_app_text.months[d.getMonth()]}`;
	},
	// "lundi 3 mars 2025"
	date_long : function (d) {
		return `${moncycle_app_text.weekdays[d.getDay()]} ${d.getDate()} ${moncycle_app_text.months_long[d.getMonth()]} ${d.getFullYear()}`;
	},
	// heading of a timeline row: weekday initial + short date, "l 3 mars "
	date_row : function (d) {
		return `${moncycle_app_text.weekdays[d.getDay()][0]} ${d.getDate()} ${moncycle_app_text.months[d.getMonth()]} `;
	},
	// position of a day inside its cycle, "J12"
	day_number : function (n) {
		return `J${n}`;
	},

	/* --- page ----------------------------------------------------------- */
	console_banner : "moncycle.app - app de suivi de cycle pour les méthodes naturelles",
	page_title : function (name) {
		return `moncycle.app - ${name}`;
	},
	sponsor_badge : " &#x1F396;&#xFE0F;",
	// label of the view switch: it names the view it switches TO
	but_view_maxi : "🔬 Vue maxi",
	but_view_mini : "🔭 Vue mini",

	/* --- cycle header and exports --------------------------------------- */
	// "Cycle du 3 mars au 29 mars de 27j" (timeline) / "... de 27 jours" (recap)
	cycle_title : function (start, end, nb) {
		return `Cycle du ${moncycle_app_text.date_short(start)} <span class='cycle_fin'>au ${moncycle_app_text.date_short(end)} </span> de <span class='nb_jours'>${nb}</span>j`;
	},
	cycle_title_recap : function (start, end, nb) {
		return `Cycle du ${moncycle_app_text.date_short(start)}. <span class='cycle_fin'>au ${moncycle_app_text.date_short(end)}. </span> de <span class='nb_jours'>${nb}</span> jours`;
	},
	but_export_nfp : "🚀 export NFP",
	but_export_csv : "&#x1F522; export CSV",
	but_export_pdf : "&#x1F4C4; export PDF",
	label_anonymous_export : " anonymiser les exports PDF et NFP",

	/* --- new cycle form ------------------------------------------------- */
	new_cycle_title : "Créer un nouveau cycle",
	new_cycle_ask_1st_day : "Entrer la date du premier jour du cycle à créer.",
	new_cycle_ask_restart : "Entrer la date du jour de reprise du suivi du cycle.",
	new_cycle_submit : "✔️",
	new_cycle_date_error : "Erreur: la date du premier jour du cycle à créer ne doit pas être dans un cycle existant et doit être antérieure à aujourd'hui.",
	all_cycles_shown : "Tous les cycles sont affichées.",
	create_cycle_hint : "Dans la page MAXI vous avez la possibilité de créer un nouveau cycle.",

	/* --- a day, in the timeline and in the recap ------------------------ */
	// glyph drawn in the day cell, keyed by the stamp code stored in the DB
	stamp_glyph : {
		"R"  : "R",
		"G"  : "G",
		"Y"  : "=",
		"BB" : "👶",
	},
	// glyph of the cervical fluid arrow, keyed by the value stored in the DB
	arrow_glyph : {
		"↓" : "⬇️",
		"↑" : "⬆️",
		"→" : "➡️",
		"←" : "⬅️",
		""  : ""
	},
	to_fill_in : "à renseigner",
	to_fill_in_glyph : "👋",
	not_observed : "jour non observé",
	not_observed_glyph : "?",
	pregnancy : "🤰 grossesse",
	pregnancy_glyph : "🤰",
	// stamp drawn in the recap for a pregnancy day
	pregnancy_stamp_glyph : "G",
	union : "❤️",
	peak_bill : "⛰️",
	peak_fc : "PIC",
	// day counted from the peak, "+3"
	peak_offset : function (n) {
		return `+${n}`;
	},
	// time the temperature was taken, " à 7h05"
	temperature_time : function (hh, mm) {
		return ` à ${hh}h${mm}`;
	},
	// stands for a note too long to fit in the recap cell
	fc_note_overflow : "*",
	// not used at the moment
	to_today : "à auj.",

	/* --- day form ------------------------------------------------------- */
	arrow_up : "↑",
	arrow_down : "↓",
	// button moving to the previous / next day, "↑ J13"
	but_day_step : function (arrow, n) {
		return `${arrow} ${moncycle_app_text.day_number(n)}`;
	},
	target_blank : "blanc",
	target_empty : "vide",
	bulk_too_many_days : function (max) {
		return `Le nombre de jours doit être inférieur à ${max}.`;
	},
	confirm_delete_day : function (d) {
		return `Voulez-vous vraiment supprimer définitivement les données de la journée du ${moncycle_app_text.date_long(d)}?`;
	},
	fc_syntax_valid : "syntaxe valide",
	fc_syntax_invalid : "syntaxe invalide",

	/* --- keeping the local copy and the server together ------------------ */
	// the bar at the bottom of the page: only what the user has to know (the routine syncs are silent)
	sync_pending : function (n) {
		return `⏳ ${n} modification${n > 1 ? "s" : ""} en cours d'envoi...`;
	},
	// n changes are kept on this device but the server has not got them
	sync_not_sent : function (n) {
		return `⚠️ ${n} modification${n > 1 ? "s" : ""} enregistrée${n > 1 ? "s" : ""} sur cet appareil, pas encore sur le serveur. Nouvel essai automatique.`;
	},
	sync_offline : "⚠️ Connexion impossible : les données affichées peuvent ne pas être à jour.",
	sync_server_error : "⚠️ Le serveur ne répond pas correctement : nouvel essai automatique.",
	// the server refused a change for good, "message" is its own wording
	sync_rejected : function (message) {
		return `❌ Modification refusée par le serveur, elle n'a pas été enregistrée : ${message}`;
	},
	sync_retry : "Réessayer",
	sync_dismiss : "OK",

	/* --- description picker (sensations / observations / autre) --------- */
	desc_add_saved : "✅",
	desc_add_duplicate : function (name) {
		return `❌ « ${name} » existe déjà`;
	},
	// tooltip of a description with no type yet, struck through in the timeline
	desc_legacy_title : "Sans type : sensation ou observation ? Touchez le jour pour l’ouvrir",
}

moncycle_app = {
	stamp_class : {
		"R"  : "rouge",
		"G"  : "vert",
		"Y"  : "jaune",
		"BB" : "baby",
	},
	arrow_id : {
		"↓" : "b",
		"↑" : "h",
		"→" : "d",
		"←" : "g",
		""  : ""
	},

	/* -----------------------------------------------------------------------
	** API ADAPTERS
	**
	** The backend speaks a structured JSON API (see api/moncycle_app_open_api.yaml):
	** every response is {"data": ...}, and the per-day shape uses NFP-inspired field
	** names (stampColor, isPeak, codifiedArrow, freeMucusSensation, ...) instead of
	** the packed legacy codes (stamp, fc_score, fc_arrow, no_description ids...) the
	** rest of this file is written against. These adapters are the only place that
	** need to know about the new shape -- day_timeline2timeline, day_timeline2recap,
	** open_menu, fc_note2form/fc_form2note/fc_test_note/fc_note2html etc. all keep
	** working against the same legacy flat shape they always have.
	** ====================================================================== */

	fc_note_keys : ['10DL','10SL','10WL','RAP','LAP','X1','X2','X3','AD','AP','VL','VH','2W','10','H','M','L','B','0','2','4','6','8','C','G','K','P','Y','R'],

	// mirrors data_parse_fc_note() in lib/data.php: a leading "L" (other than "LAP...") is
	// the separate "Lsaignement" (spotting) flag, not the standalone "L" mucus-observation
	// code checked for below.
	parse_fc_note : function (str) {
		let note = {Lsaignement: false};
		moncycle_app.fc_note_keys.forEach(k => note[k] = false);
		if (!str) return note;
		str = str.trim();
		if (str.length > 0) {
			str = str.toUpperCase();
			if (str.startsWith('L') && !str.startsWith('LAP')) {
				note.Lsaignement = true;
				str = str.slice(1);
			}
			moncycle_app.fc_note_keys.forEach(k => {
				if (str.includes(k)) note[k] = true;
				str = str.split(k).join('');
			});
		}
		return note;
	},

	fc_groups : {
		codifiedBleedingObservation : ['VH','H','M','VL','B'],
		codifiedMucusSensation : ['0','2','2W','4','6','8','10','10DL','10SL','10WL'],
		codifiedMucusObservation : ['C','G','K','P','Y','L'],
		codifiedNumberObservations : ['X1','X2','X3','AD'],
		codifiedPainObservations : ['AP','RAP','LAP'],
	},

	// fc_score string (from #form_fc) -> the API's 5 codifiedXxx fields
	fc_score_to_codified : function (str) {
		let note = moncycle_app.parse_fc_note(str);
		let bleeding = '';
		moncycle_app.fc_groups.codifiedBleedingObservation.forEach(c => { if (note[c]) bleeding += c; });
		if (note.Lsaignement) bleeding += 'L';
		let out = {codifiedBleedingObservation : bleeding || null};
		for (const field in moncycle_app.fc_groups) {
			if (field == 'codifiedBleedingObservation') continue;
			let v = '';
			moncycle_app.fc_groups[field].forEach(c => { if (note[c]) v += c; });
			out[field] = v || null;
		}
		return out;
	},

	// the API's 5 codifiedXxx fields -> a single legacy-style fc_score string, for the
	// #form_fc textarea and for the calendar-cell rendering that reads j.fc_score. Mirrors
	// lib/day_format.php's day_format_fc_score_encode() on the server, including the same
	// Lsaignement repositioning: decoding puts spotting at the *end* of
	// codifiedBleedingObservation (e.g. "BL"), but the legacy notation only recognises it as
	// a *leading* "L", so it has to move back to the front here or it reads back as the
	// unrelated standalone "L" mucus-observation code instead.
	fc_score_from_api : function (d) {
		let bleeding = (d.codifiedBleedingObservation || '').trim();
		let spotting = false;
		const bleeding_codes = moncycle_app.fc_groups.codifiedBleedingObservation;
		if (bleeding !== '' && !bleeding_codes.includes(bleeding) && bleeding.endsWith('L')) {
			let candidate = bleeding.slice(0, -1);
			if (candidate === '' || bleeding_codes.includes(candidate)) {
				spotting = true;
				bleeding = candidate;
			}
		}
		let parts = [];
		if (bleeding !== '') parts.push(bleeding);
		['codifiedMucusSensation','codifiedMucusObservation','codifiedNumberObservations','codifiedPainObservations'].forEach(f => {
			let v = (d[f] || '').trim();
			if (v !== '') parts.push(v);
		});
		if (parts.length === 0 && !spotting) return '';
		let joined = parts.join(' ');
		if (spotting) return 'L' + (joined !== '' ? ' ' + joined : '');
		if (joined.startsWith('L') && !joined.startsWith('LAP')) joined = ' ' + joined;
		return joined;
	},

	stamp_from_api : function (d) {
		let code = {"Red":"R","Green":"G","Yellow":"Y"}[d.stampColor] || "";
		return code + (d.stampBaby ? "BB" : "");
	},

	arrow_from_api : {"Up":"↑","Down":"↓","Right":"→"},
	arrow_to_api : {"↑":"Up","↓":"Down","→":"Right"},

	desc_type_to_int : {"undefined":0, "observation":1, "sensation":2},
	desc_type_from_int : {0:"undefined", 1:"observation", 2:"sensation"},

	description_from_api : function (d) {
		return {no_description: d.id, name: d.name, type: moncycle_app.desc_type_to_int[d.type] || 0, use_count: d.useCount};
	},

	// full Day object (GET/POST /api/day, or one entry of GET /api/day's keyed-by-date
	// result) -> the legacy flat shape day_timeline2timeline/day_timeline2recap/open_menu
	// expect. Description names are matched against the already-loaded picklist
	// (moncycle_app.description) to recover the numeric ids open_menu checks against.
	day_from_api : function (d) {
		let description = [];
		(d.freeMucusObservation || []).forEach(name => {
			let match = moncycle_app.description.find(sd => sd.name == name && sd.type == 1);
			description.push(match || {no_description: null, name: name, type: 1, use_count: 0});
		});
		(d.freeMucusSensation || []).forEach(name => {
			let match = moncycle_app.description.find(sd => sd.name == name && sd.type == 2);
			description.push(match || {no_description: null, name: name, type: 2, use_count: 0});
		});
		(d.freeOther || []).forEach(name => {
			let match = moncycle_app.description.find(sd => sd.name == name && sd.type == 0);
			description.push(match || {no_description: null, name: name, type: 0, use_count: 0});
		});
		return {
			date_obs : d.date,
			cycle : d.cycleStartDate,
			pos : d.cycleDay,
			cycle_1st_day : d.cycleFirstDay,
			day_not_observed : d.dayNotObserved,
			stamp : moncycle_app.stamp_from_api(d),
			is_peak : d.isPeak,
			counter_start : d.counterStart,
			union_sex : d.sexUnion,
			pregnancy : d.booleanPregnancyDetected,
			temperature : d.temperature,
			time_temp_taken : d.temperatureTime,
			fc_score : moncycle_app.fc_score_from_api(d),
			fc_arrow : moncycle_app.arrow_from_api[d.codifiedArrow] || "",
			comment : d.comment,
			description : description,
		};
	},

	// the "nfp_method" 1-4 integer used throughout this file (and "nfp_method_name", its
	// display-class variant) conflates the NFP method with whether temperature is tracked;
	// the API exposes those as two separate fields instead.
	nfp_method_from_api : function (method, temperatureTracking) {
		const table = {"billings": [2, 1], "fertilityCare": [3, 4]};
		let pair = table[method] || table["billings"];
		return temperatureTracking ? pair[1] : pair[0];
	},

	// GET /api/key_infos's response -> the legacy flat shape moncycle_app.constante has
	// always had.
	constante_from_api : function (d) {
		let nfp_method = moncycle_app.nfp_method_from_api(d.method, d.temperatureTracking);
		return {
			no_user_account : d.userId,
			name : d.name,
			sponsor : d.sponsor,
			nfp_method : nfp_method,
			nfp_method_name : {1:"bill_temp", 2:"bill", 3:"fc", 4:"fc_temp"}[nfp_method],
			all_cycles_1st_day : d.allCyclesFirstDay,
			all_pregnancy_dates : d.allPregnancyDates,
			timeline_asc : d.timelineAscending,
		};
	},

	// the serializeArray() output of #form_data (legacy flat field names: date, stamp, baby,
	// fc_score, fc_arrow, temp, time_temp_taken, counter_start, is_peak, union_sex,
	// cycle_1st_day, day_not_observed, pregnancy, comment, description[], last_write_client_UTC
	// -- everything else in that form, the individual fc_* checkboxes, is client-side scratch
	// state for building fc_score and was never read server-side either way) -> the JSON body
	// POST /api/day expects.
	day_to_api : function (fields) {
		let get = (n) => fields.filter(f => f.name == n).map(f => f.value);
		let get1 = (n) => { let v = get(n); return v.length ? v[0] : undefined; };
		let codified = moncycle_app.fc_score_to_codified(get1('fc_score') || '');
		let payload = Object.assign({
			date : get1('date'),
			stampColor : {"R":"Red","G":"Green","Y":"Yellow","":null}[get1('stamp') || ''],
			stampBaby : get1('baby') == 'BB',
			codifiedArrow : moncycle_app.arrow_to_api[get1('fc_arrow')] || null,
			temperature : get1('temp') ? parseFloat(get1('temp')) : null,
			temperatureTime : get1('time_temp_taken') || null,
			counterStart : parseInt(get1('counter_start') || '0'),
			isPeak : get1('is_peak') == '1',
			sexUnion : get1('union_sex') == '1',
			cycleFirstDay : get1('cycle_1st_day') == '1',
			dayNotObserved : get1('day_not_observed') == '1',
			booleanPregnancyDetected : get1('pregnancy') == '1',
			comment : get1('comment') || '',
			lastWriteClientUtc : get1('last_write_client_UTC') || null,
		}, codified);
		payload.freeMucusObservation = [];
		payload.freeMucusSensation = [];
		// a day is posted whole: the labels with no type yet are sent back, or they would be unlinked
		payload.freeOther = [];
		// a chip stands for a description by its name, which is what a day carries it by: a description made here has no id until the server has created it
		get('description[]').forEach(name => {
			let sdesc = moncycle_app.description.find(d => d.name == name);
			if (!sdesc) return;
			if (sdesc.type == 1) payload.freeMucusObservation.push(sdesc.name);
			else if (sdesc.type == 2) payload.freeMucusSensation.push(sdesc.name);
			else if (sdesc.type == 0) payload.freeOther.push(sdesc.name);
		});
		return payload;
	},

	sommets : [],
	counter_starts : {},
	page_a_recharger: false,
	graph_data : {},
	graphs : {},
	cycle_curseur : 0,
	date_loaded: null,
	constante : {},
	description : [],
	day_timeline : {},
	timeline_asc : true,
	// what the cycles drawn were made from (see structure_of): when the copy says otherwise, they are drawn again
	structure : null,

	/* -----------------------------------------------------------------------
	** START
	**
	** The page is drawn from the local copy (js/store.js) at once, when there is one, and the sync
	** that follows brings what changed elsewhere. A change of the user is applied to the copy first,
	** then sent: the page shows an error when the server could not take it.
	** ====================================================================== */
	letsgo : function() {
		console.log(moncycle_app_text.console_banner);
		if (!localStorage.auth) {
			window.location.replace('/auth');
			return;
		}
		moncycle_app.date_loaded = moncycle_app.date.str(moncycle_app.date.now());
		moncycle_store.init();
		moncycle_store.on("status", moncycle_app.show_sync_status);
		moncycle_store.on("changed", moncycle_app.store_changed);
		moncycle_store.on("sent", moncycle_app.day_sent);
		moncycle_store.on("failed", moncycle_app.day_failed);
		$("#charger_cycle").click(moncycle_app.charger_cycle);
		$("#jour_form_close").click(moncycle_app.close_menu);
		$("#jour_form_submit").click(moncycle_app.submit_menu);
		$("#jour_form_next").click(moncycle_app.open_menu);
		$("#jour_form_prev").click(moncycle_app.open_menu);
		$("#bulk_but_submit").click(moncycle_app.bulk_submit_menu);
		$("#jour_form_bulk_but").click(moncycle_app.bulk_show_hide);
		$("#jour_form #form_data input:not(.desc_add_input), #jour_form textarea").on("change", moncycle_app.submit_menu);
		$("#form_fc").on("keyup", moncycle_app.fc_note2form);
		$("#jour_form_suppr").click(moncycle_app.suppr_day_timeline);
		$("#sync_status").on("click", "#sync_retry", function () { moncycle_store.sync().catch(function () { }); });
		$("#sync_status").on("click", "#sync_dismiss", moncycle_store.dismiss_error);
		moncycle_app.bind_desc_add_forms();
		$("#but_mini_maxi").click(moncycle_app.mini_maxi_switch);
		$("#go_baby").click(moncycle_app.go_blank_or_empty);
		if (localStorage.mini_maxi == "mini") moncycle_app.mini_maxi = "maxi";
		else moncycle_app.mini_maxi = "mini";
		moncycle_app.mini_maxi_switch();
		$("#form_time_temp_taken").focus(function () {
			if($("#form_time_temp_taken").val().trim().length==0) {
				let d  = new Date();
				let h = d.getHours();
				let m = d.getMinutes();
				$("#form_time_temp_taken").val((h<10 ? "0"+h : h) + ":" + (m<10 ? "0"+m : m));
				moncycle_app.submit_menu();
			}
		});
		$(window).scroll(function() {
			if(moncycle_app.timeline_asc && $(window).scrollTop() + $(window).height() +50 >= $(document).height()){
				moncycle_app.charger_cycle();
			}
		});
		$(window).focus(function() {
			if (moncycle_app.date.str(moncycle_app.date.now()) != moncycle_app.date_loaded) location.reload(false);
			return false;
		})
		// another tab wrote its copy: nothing to do. It logged out, or logged in as someone else: leave.
		window.addEventListener("storage", function (event) {
			if (event.key !== null && event.key != "auth") return;
			if (localStorage.auth != moncycle_app.constante.no_user_account) window.location.href = window.location.href;
		}, false);
		if (moncycle_store.account) moncycle_app.show_account();
		moncycle_store.sync().catch(function () { });
	},
	// what decides how the cycles are laid out: when it changes in the copy, they have to be drawn again
	structure_of : function (account) {
		return JSON.stringify([account.allCyclesFirstDay, account.allPregnancyDates, account.timelineAscending, account.method, account.temperatureTracking]);
	},
	// The copy holds the account: draw the page header and the cycles from it.
	show_account : function () {
		moncycle_app.show_header();
		moncycle_app.structure = moncycle_app.structure_of(moncycle_store.account);
		if (moncycle_app.cycle_curseur == 0) moncycle_app.remplir_page_de_cycle();
	},
	show_header : function () {
		moncycle_app.constante = moncycle_app.constante_from_api(moncycle_store.account);
		moncycle_app.timeline_asc = moncycle_app.constante.timeline_asc;
		moncycle_app.description = moncycle_store.all_descriptions().map(moncycle_app.description_from_api);
		document.title = moncycle_app_text.page_title(moncycle_app.constante.name);
		// the name is free text: text first, then the badge (markup of ours)
		$("#name").text(moncycle_app.constante.name);
		if (moncycle_app.constante.sponsor) $("#name").append(moncycle_app_text.sponsor_badge);
		moncycle_app.charger_actu();
		$(".main_button").css("display","inline-block");
		if (moncycle_app.timeline_asc) $("#charger_cycle").hide();
		else $("#charger_cycle").show();
	},
	// Draws every cycle again from the copy (the cycles changed, or the way they are laid out).
	rebuild : function () {
		moncycle_app.page_a_recharger = false;
		$.each(moncycle_app.graphs, function (id, graph) { graph.destroy(); });
		moncycle_app.graphs = {};
		moncycle_app.graph_data = {};
		moncycle_app.sommets = [];
		moncycle_app.counter_starts = {};
		moncycle_app.day_timeline = {};
		moncycle_app.cycle_curseur = 0;
		moncycle_app.form_nouveau_cycle_active = false;
		moncycle_app.cycle_title_opened = null;
		$("#timeline").empty();
		$("#recap").empty();
		$("#charger_cycle").prop("disabled", false);
		moncycle_app.show_account();
	},
	// What happened to the copy: the days that changed are drawn again, and the cycles when their
	// structure moved (at once, or when the day form is closed if it is open).
	store_changed : function (change) {
		if (change.descriptions) moncycle_app.description = moncycle_store.all_descriptions().map(moncycle_app.description_from_api);
		if (!moncycle_store.account) return;
		if (moncycle_app.structure === null) {
			moncycle_app.show_account();
			return;
		}
		if (change.account || change.source == "sync") {
			moncycle_app.page_a_recharger = moncycle_app.structure_of(moncycle_store.account) !== moncycle_app.structure;
			if (moncycle_app.page_a_recharger && moncycle_app.menu_opened_date === null) {
				moncycle_app.rebuild();
				return;
			}
			// the cycles stay as drawn: only the header (name, sponsor) can have changed
			if (!moncycle_app.page_a_recharger) moncycle_app.show_header();
		}
		change.days.forEach(moncycle_app.refresh_day);
	},
	// The write of a day was taken by the server.
	day_sent : function (detail) {
		if (detail.date != moncycle_app.menu_opened_date || moncycle_store.pending[detail.date]) return;
		$("#jour_form_saving").hide();
		$("#jour_form_unsent").hide();
		$("#jour_form_saved").show();
	},
	day_failed : function (detail) {
		if (detail.date != moncycle_app.menu_opened_date) return;
		$("#jour_form_saving").hide();
		$("#jour_form_unsent").hide();
	},
	// The bar at the bottom of the page, and the indicator of the day form. Silent unless something needs the user.
	show_sync_status : function (state) {
		let text = "";
		let css = "sync_error";
		if (state.error && state.error.kind == "rejected") text = moncycle_app_text.sync_rejected(state.error.message);
		else if (state.pending > 0 && state.error) text = moncycle_app_text.sync_not_sent(state.pending);
		else if (state.error) {
			text = state.error.kind == "network" ? moncycle_app_text.sync_offline : moncycle_app_text.sync_server_error;
			css = "sync_warning";
		}
		else if (state.pending > 0) {
			text = moncycle_app_text.sync_pending(state.pending);
			css = "sync_busy";
		}
		$("#sync_status").empty().attr("class", "sync_status " + css).toggle(text != "");
		if (text != "") {
			$("#sync_status").append($("<span>").text(text));
			if (state.error && state.error.kind == "rejected") $("#sync_status").append(" ").append($("<button>", {type: "button", id: "sync_dismiss"}).text(moncycle_app_text.sync_dismiss));
			else if (css != "sync_busy") $("#sync_status").append(" ").append($("<button>", {type: "button", id: "sync_retry"}).text(moncycle_app_text.sync_retry));
		}
		let unsent = state.pending > 0 && state.error && state.error.kind != "rejected";
		if (unsent) $("#jour_form_saving").hide();
		$("#jour_form_unsent").toggle(!!(unsent && moncycle_app.menu_opened_date !== null && moncycle_store.pending[moncycle_app.menu_opened_date]));
	},

	mini_maxi : "mini",
	mini_maxi_switch : function () {
		if (moncycle_app.mini_maxi=="maxi"){
			$("#timeline").hide();
			$("#recap").show();
			$("#but_mini_maxi").text(moncycle_app_text.but_view_maxi);
			moncycle_app.mini_maxi="mini";
			localStorage.mini_maxi="mini";
		}
		else {
			$("#timeline").show();
			$("#recap").hide();
			$("#but_mini_maxi").text(moncycle_app_text.but_view_mini);
			moncycle_app.mini_maxi="maxi";
			localStorage.mini_maxi="maxi";
		}
		if (moncycle_app.cycle_curseur == 0) moncycle_app.remplir_page_de_cycle();
		if (moncycle_app.timeline_asc && $(document).height()<=$(window).height()) moncycle_app.remplir_page_de_cycle();
	},
	bulk_show_hide : function () {
		if ($("#bulk_form").is(":hidden")) $("#bulk_form").show();
		else $("#bulk_form").hide();
	},
	remplir_page_de_cycle : function() {
		if (!moncycle_app.constante || !moncycle_app.constante.all_cycles_1st_day) return;
		if (moncycle_app.timeline_asc) {
			while ($(window).height() == $(document).height() && moncycle_app.cycle_curseur < moncycle_app.constante.all_cycles_1st_day.length) {
				moncycle_app.charger_cycle();
			}
			moncycle_app.charger_cycle();
			if ($(window).height() == $(document).height()) moncycle_app.form_nouveau_cycle();
		}
		else moncycle_app.charger_cycle();
	},
	// The news banner: the HTML of the instance's NEWS_URL (the server says it in the account, null when it
	// is off), once per page. What comes back is another site's markup: it is parsed in an inert document and
	// rebuilt from the tags below, no attribute copied but an https href.
	news_requested : false,
	news_tags : ["h4", "p", "b", "i", "br", "ul", "li", "a", "time"],
	news_node : function (node) {
		if (node.nodeType == Node.TEXT_NODE) return document.createTextNode(node.textContent);
		if (node.nodeType != Node.ELEMENT_NODE) return null;
		let tag = node.tagName.toLowerCase();
		if (["script", "style", "template"].includes(tag)) return null;
		// a tag outside the list is dropped but its text stays
		let copy = moncycle_app.news_tags.includes(tag) ? document.createElement(tag) : document.createDocumentFragment();
		if (tag == "a") {
			let href = node.getAttribute("href") || "";
			if (/^https:\/\//i.test(href)) {
				copy.setAttribute("href", href);
				copy.setAttribute("target", "_blank");
				copy.setAttribute("rel", "noopener noreferrer");
			}
		}
		node.childNodes.forEach(function (child) {
			let child_copy = moncycle_app.news_node(child);
			if (child_copy) copy.appendChild(child_copy);
		});
		return copy;
	},
	charger_actu : function() {
		let url = moncycle_store.account ? moncycle_store.account.newsUrl : null;
		if (!url || moncycle_app.news_requested) return;
		moncycle_app.news_requested = true;
		$.get(url, function(data) {
			let news = new DOMParser().parseFromString(String(data), "text/html");
			$("#actu_contenu").empty();
			news.body.childNodes.forEach(function (node) {
				let copy = moncycle_app.news_node(node);
				if (copy) $("#actu_contenu")[0].appendChild(copy);
			});
			let titre = $("#actu_contenu").find("h4").text();
			if (titre && localStorage.actu_lu != titre) $("#actu").show();
			$("#fermer_actu").click(function () {
				localStorage.actu_lu = $("#actu_contenu").find("h4").text();
				$("#actu").hide();
			});
		});
	},
	charger_cycle : function() {
		if (moncycle_app.cycle_curseur >= moncycle_app.constante.all_cycles_1st_day.length) {
			moncycle_app.form_nouveau_cycle();
			return;
		}
		let c = moncycle_app.cycle_curseur;
		moncycle_app.cycle_curseur += 1;
		let date_cycle_str = moncycle_app.constante.all_cycles_1st_day[c];
		let date_fin = moncycle_app.date.now();
		let fin_auj = true;
		if (c>0) {
			date_fin = new Date(moncycle_app.date.parse(moncycle_app.constante.all_cycles_1st_day[c-1]) - (1000*60*60*24));
			date_fin.setHours(9);
			fin_auj = false;
		}
		let date_cycle = moncycle_app.date.parse(date_cycle_str);
		date_cycle.setHours(9);
		let form_nouv_cycle = false;
		for (let i = 0; i < moncycle_app.constante.all_pregnancy_dates.length; i++) {
			let gross = moncycle_app.date.parse(moncycle_app.constante.all_pregnancy_dates[i]);
			gross.setHours(9);
			if (gross>=date_cycle && gross<=date_fin) {
				date_fin = gross;
				if (fin_auj) form_nouv_cycle = true;
			}
		}
		if (form_nouv_cycle && moncycle_app.timeline_asc) moncycle_app.form_nouveau_cycle(false);
		let nb_jours = parseInt(Math.round((date_fin-date_cycle)/(1000*60*60*24)+1));
		if (moncycle_app.timeline_asc) {
			$("#timeline").append(moncycle_app.cycle2timeline(date_cycle_str, nb_jours, date_fin));
			$("#recap").append(moncycle_app.cycle2recap(date_cycle_str, nb_jours, date_fin));
		}
		else {
			$("#timeline").prepend(moncycle_app.cycle2timeline(date_cycle_str, nb_jours, date_fin));
			$("#recap").prepend(moncycle_app.cycle2recap(date_cycle_str, nb_jours, date_fin));
		}
		let dates_data_holder = {};
		for (let pas = 0; pas < nb_jours; pas++) {
			let date_obs = new Date(date_cycle);
			date_obs.setDate(date_obs.getDate()+pas);
			let date_obs_str = moncycle_app.date.str(date_obs);
			let data = moncycle_app.view_day(date_obs_str, date_cycle_str, pas+1);
			dates_data_holder[date_obs_str] = data;
			moncycle_app.day_timeline[date_obs_str] = data;
			if (moncycle_app.timeline_asc) $(`#c-${date_cycle_str} .contenu`).prepend(moncycle_app.day_timeline2timeline(data));
			else $(`#c-${date_cycle_str} .contenu`).append(moncycle_app.day_timeline2timeline(data));
			$(`#rc-${date_cycle_str} .contenu`).append(moncycle_app.day_timeline2recap(data));
			moncycle_app.track_day(date_obs_str, data);
		}
		$(`.pas_${moncycle_app.constante.nfp_method_name}`).css("display", "none");
		moncycle_app.trois_jours();
		moncycle_app.graph_preparation_data(dates_data_holder);
		if (moncycle_app.constante.nfp_method == 1 || moncycle_app.constante.nfp_method == 4) {
			moncycle_app.cycle2graph(date_cycle_str);
			moncycle_app.graph_update(date_cycle_str);
		}
		if (form_nouv_cycle && !moncycle_app.timeline_asc) moncycle_app.form_nouveau_cycle(false);
	},
	// A day of the local copy, in the flat shape the drawing functions read. A date that holds nothing is
	// an empty day; $cycle and $pos say where it stands when the caller knows.
	view_day : function (date, cycle, pos) {
		let day = moncycle_store.days[date];
		if (!day) {
			day = moncycle_store.blank_day(date);
			Object.assign(day, cycle ? {cycleStartDate : cycle, cycleDay : pos} : moncycle_store.cycle_of(date));
		}
		return moncycle_app.day_from_api(day);
	},
	// the peak days and the counters the "+1 +2 +3" marks of the other days come from
	track_day : function (date, data) {
		if (data.is_peak && $.inArray(date, moncycle_app.sommets)<0) moncycle_app.sommets.push(date);
		else if (!data.is_peak && $.inArray(date, moncycle_app.sommets)>=0) moncycle_app.sommets.splice($.inArray(date, moncycle_app.sommets), 1);
		if (data.counter_start) moncycle_app.counter_starts[date] = data.counter_start;
		else if (date in moncycle_app.counter_starts) delete moncycle_app.counter_starts[date];
	},
	// A day changed in the copy: draw it again, if it is on the page.
	refresh_day : function (date) {
		let shown = moncycle_app.day_timeline[date];
		if (!shown) return;
		let data = moncycle_app.view_day(date, shown.cycle, shown.pos);
		moncycle_app.day_timeline[date] = data;
		$(`#o-${date}`).replaceWith(moncycle_app.day_timeline2timeline(data));
		$(`#ro-${date}`).replaceWith(moncycle_app.day_timeline2recap(data));
		moncycle_app.track_day(date, data);
		$(`.pas_${moncycle_app.constante.nfp_method_name}`).css("display", "none");
		moncycle_app.trois_jours();
		moncycle_app.graph_preparation_data({[date]: data});
	},
	form_nouveau_cycle_active: false,
	form_nouveau_cycle: function (prepend=true) {
		if (moncycle_app.form_nouveau_cycle_active) return;
		moncycle_app.form_nouveau_cycle_active = true;
		let max_date = moncycle_app.date.str(moncycle_app.date.now());
		let min_date = "";
		if (prepend && moncycle_app.cycle_curseur>0) {
			max_date = moncycle_app.constante.all_cycles_1st_day[moncycle_app.cycle_curseur-1];
			max_date = moncycle_app.date.str(new Date(moncycle_app.date.parse(max_date) - (1000*60*60*24)));
		}
		else if (!prepend) {
			let min_calc = moncycle_app.date.parse(moncycle_app.constante.all_pregnancy_dates[0]);
			min_calc.setDate(min_calc.getDate()+1);
			min_date = moncycle_app.date.str(min_calc);
		}
		let instruction = moncycle_app_text.new_cycle_ask_1st_day;
		if (!prepend) instruction = moncycle_app_text.new_cycle_ask_restart;
		let html = `<div class="cycle" id="nouveau_cycle"><h2 class="title">${moncycle_app_text.new_cycle_title}</h2><div class="nouveau_cycle_form">${instruction}<br><input id="nouveau_cycle_date" type="date" value="${max_date}" max="${max_date}" min="${min_date}" /> <input type="button" id="but_creer_cycle" value="${moncycle_app_text.new_cycle_submit}" /></div></div>`;
		let nocycle = `<div id="nocycle">${moncycle_app_text.all_cycles_shown}</div>`;
		if (moncycle_app.cycle_curseur == 0) nocycle = `<div id="nocycle">${moncycle_app_text.create_cycle_hint}</div>`;
		if (prepend && !moncycle_app.timeline_asc) {
			$("#charger_cycle").prop("disabled", true);
			$("#timeline").prepend(html);
			$("#recap").prepend(nocycle);
		}
		else {
			$("#timeline").append(html);
			if (moncycle_app.cycle_curseur == 0) $("#recap").append(nocycle);
		}
		$("#but_creer_cycle").click(function () {
			let nouveau_cycle_date = $("#nouveau_cycle_date").val();
			let max = moncycle_app.date.parse($("#nouveau_cycle_date").attr("max"));
			let min = moncycle_app.date.parse($("#nouveau_cycle_date").attr("min"));
			if (moncycle_app.date.parse(nouveau_cycle_date) > max || (!isNaN(min) && moncycle_app.date.parse(nouveau_cycle_date) < min)) {
				alert(moncycle_app_text.new_cycle_date_error);
				return;
			}
			moncycle_store.queue_day({date: nouveau_cycle_date, cycleFirstDay: true, lastWriteClientUtc: moncycle_app.date.nowInUTC()});
		});
	},
	trois_jours : function() {
		$(".day .s").empty();
		$(".obs .s").empty();
		$(".obs .s").show();
		$(".day .s").removeClass("j_pic");
		let txt_sommet = moncycle_app_text.peak_bill;
		if (moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) txt_sommet = moncycle_app_text.peak_fc;
		moncycle_app.sommets.forEach(s => {
			let last = 3;
			if (!moncycle_app.timeline_asc) last = $(`#o-${s}`).parent()[0].children.length - $(`#o-${s}`).index() - 1;
			else last = $(`#o-${s}`).index();
			[1, 2, 3, last].forEach(n => {
				let s_date = moncycle_app.date.parse(s);
				s_date.setDate(s_date.getDate()+n);
				let s_id = moncycle_app.date.str(s_date);
				$(`#o-${s_id} .s`).html(moncycle_app_text.peak_offset(n));
				$(`#ro-${s_id} .s`).html(n);
			});
			$(`#o-${s} .s`).html(txt_sommet);
			$(`#ro-${s} .s`).html(txt_sommet);
			$(`#o-${s} .s`).addClass("j_pic");
			$(`#ro-${s} .s`).addClass("j_pic");
		});
		if (moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) return;
		$(".day .n").empty();
		$(".obs .n").empty();
		$(".obs .n").hide();
		$.each(moncycle_app.counter_starts,function(d,c){
			for (let i = 0; i < c; i++) {
				let s_date = moncycle_app.date.parse(d);
				s_date.setDate(s_date.getDate()+i);
				let s_id = moncycle_app.date.str(s_date);
				$(`#o-${s_id} .n`).html(moncycle_app_text.peak_offset(i+1));
				if (($(`#ro-${s_id} .s`).text()).length==0) {
					$(`#ro-${s_id} .n`).html(i+1);
					$(`#ro-${s_id} .n`).show();
					$(`#ro-${s_id} .s`).hide();
				}
			}
		});
	},
	cycle_option : function (c_date_str, c_date_fin_str, discri) {
		let id_buts = c_date_str.replace("-", "_").replace("-", "_");
		let c_action = $(`<div class='cycle_options c_options_${c_date_str}' style='display:none'></div>`);
		c_action.append(`<a id='nfp_but_${id_buts}_${discri}' href='api/export?start_date=${c_date_str}&end_date=${c_date_fin_str}&type=nfp&anonymous=0'><button>${moncycle_app_text.but_export_nfp}</button></a> `);
		c_action.append(`<a href='api/export?start_date=${c_date_str}&end_date=${c_date_fin_str}&type=csv'><button>${moncycle_app_text.but_export_csv}</button></a> `);
		c_action.append(`<a id='pdf_but_${id_buts}_${discri}' href='api/export?start_date=${c_date_str}&end_date=${c_date_fin_str}&type=pdf&anonymous=0'><button>${moncycle_app_text.but_export_pdf}</button></a> `);
		// the checkbox applies to every export that can carry the user's identity: the PDF
		// (name on the chart) and the NFP file (userInformation block). The CSV never does.
		let anonymous_checkbox = $(`<input type='checkbox' value='1' id='anonymous_${id_buts}_${discri}' name="privacy" />`);
		anonymous_checkbox.change(function () {
			let anonymous = $(this).is(':checked') ? 1 : 0;
			c_action.find(`#nfp_but_${id_buts}_${discri}, #pdf_but_${id_buts}_${discri}`).each(function () {
				let url = $(this).attr("href").split('?');
				let params = new URLSearchParams(url[1]);
				params.set("anonymous", anonymous);
				url[1] = params.toString();
				$(this).attr("href", url.join("?"));
			});
		});
		c_action.append("<br />");
		c_action.append(anonymous_checkbox);
		c_action.append(`<label for='anonymous_${id_buts}_${discri}' class='label_anonymous_export'>${moncycle_app_text.label_anonymous_export}</label>`);
		return c_action;
	},
	cycle_title_opened : null,
	cycle_title_click : function () {
		let c = $(this).attr("for");
		$(".cycle_options").hide();
		$(".mini_ruler").hide();
		if (moncycle_app.cycle_title_opened == null || moncycle_app.cycle_title_opened != c) {
			$("#ruler_" + c).show();
			$(`.c_options_${c}`).show();
			moncycle_app.cycle_title_opened = c;
		}
		else moncycle_app.cycle_title_opened = null;
	},
	cycle2timeline : function (c, nb, fin) {
		let c_id = "c-" + c;
		let cycle = $("<div>", {id: c_id, class: "cycle"});
		let c_date = moncycle_app.date.parse(c);
		let c_fin = new Date(fin);
		let c_title = $(`<h2 class='title title_${c}' for='${c}'>${moncycle_app_text.cycle_title(c_date, c_fin, nb)}</h2>`);
		let c_graph = $(`<div class='graph pas_bill pas_fc' id='graph-${c_id}' style='display:none' ><canvas id='canvas-${c_id}'></canvas></div>`);
		let c_content = $(`<div class='contenu' id='contenu-${c_id}'></div>`);
		c_title.click(moncycle_app.cycle_title_click);
		cycle.append(c_title);
		cycle.append(moncycle_app.cycle_option(c, moncycle_app.date.str(fin), "timeline"));
		cycle.append(c_graph);
		cycle.append(c_content);
		return cycle;
	},
	cycle2recap : function (c, nb, fin) {
		let c_id = "rc-" + c;
		let cycle = $("<div>", {id: c_id, class: "cycle_recap"});
		let c_date = moncycle_app.date.parse(c);
		let c_fin = new Date(fin);
		let c_title = $(`<h5 class='title title_${c}' for='${c}'>${moncycle_app_text.cycle_title_recap(c_date, c_fin, nb)}</h5>`);
		c_title.click(moncycle_app.cycle_title_click);
		cycle.append(c_title);
		cycle.append(moncycle_app.cycle_option(c, moncycle_app.date.str(fin), "recap"));
		let c_ruler = $("<div>", {id: "ruler_" + c, class: "mini_ruler", style: "display:none"});
		let odd = true;
		for (let n=1; n<=35; n++) {
			let ruler_num = $(`<span>${n}</span>`);
			if (odd) ruler_num.addClass("odd");
			c_ruler.append(ruler_num);
			odd = !odd;
		}
		cycle.append(c_ruler);
		cycle.append($("<div>", {id: "rc_contenu_" + c, class: "contenu"}));
		return cycle;
	},
	cycle2graph : function (id) {
		const temp_chart = new Chart($(`#canvas-c-${id}`), {
			type: 'line',
			data: {
				datasets: [{
					data: moncycle_app.graph_data[id],
					fill: false,
					borderColor: '#1e824c',
					tension: 0.1,
				}]
			},
			options: {
				responsive:true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: false
					}
				}
			}
		});
		moncycle_app.graphs[id] = temp_chart;
	},
	graph_update : function(id) {
		let vide = true;
		for (let k in moncycle_app.graph_data[id]) if (vide && !isNaN(moncycle_app.graph_data[id][k])) vide = false;
		$("#graph-c-" + id).attr("vide", vide ? 1 : 0);
		if (vide) $("#graph-c-" + id).hide();
		else {
			if (!$("#contenu-c-" + id).is(":hidden")) $("#graph-c-" + id).show();
			moncycle_app.graphs[id].data.datasets[0].data = moncycle_app.graph_data[id];
			moncycle_app.graphs[id].update();
		}
		if (!vide && (moncycle_app.constante.nfp_method==1 || moncycle_app.constante.nfp_method==4)) {
			moncycle_app.graphs[id].data.datasets = moncycle_app.graphs[id].data.datasets.slice(0,1);
			moncycle_app.graphs[id].update();
		}
	},
	day_timeline2recap : function(j) {
		let o_date = moncycle_app.date.parse(j.date_obs);
		let o_id = "ro-" + moncycle_app.date.str(o_date);
		let o_class = "obs";
		if (j.pregnancy) o_class += " o_gross";
		let day_timeline = $("<div>", {id: o_id, class: o_class, date: moncycle_app.date.str(o_date)});
		day_timeline.click(moncycle_app.open_menu);
		let color = "vide";
		let index_couleur = j.stamp;
		let baby = (j.stamp == "BB");
		if (j.stamp && j.stamp.includes("BB") && j.stamp.length>2) {
			color = moncycle_app.stamp_class["BB"];
			index_couleur = index_couleur.replace("BB", "");
			baby = true;
		}
		if (moncycle_app.stamp_class[index_couleur]) color = moncycle_app.stamp_class[index_couleur];
		let car_du_milieu = baby ? moncycle_app_text.stamp_glyph["BB"] : "";
		let car_du_bas = j.union_sex ? moncycle_app_text.union : "";
		if (j.err && j.err.includes("no data")) car_du_milieu = moncycle_app_text.to_fill_in_glyph;
		let recap_note = j.fc_score;
		if ((moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) && j.fc_score) {
			recap_note = recap_note.toUpperCase();
			recap_note = recap_note.replace('RAP','').replace('LAP','').replace('AD','').replace('AP','').replace('B','');
		}
		else recap_note = "";
		if ((moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) && car_du_milieu == "" && j.fc_score) {
			const should_be_red = ['VL', 'L', 'VH', 'H', 'M'];
			should_be_red.forEach(c => {
				recap_note = recap_note.trim();
				if (recap_note.indexOf(c) == 0) {
					car_du_milieu += c;
					recap_note = recap_note.replace(c,'');
				}
			});
		}
		if (j.pregnancy) {
			color = "pink";
			car_du_milieu = moncycle_app_text.pregnancy_stamp_glyph;
			car_du_bas = moncycle_app_text.pregnancy_glyph;
		}
		if (j.day_not_observed) {
			car_du_milieu = moncycle_app_text.not_observed_glyph;
			color = "jcpas";
		}
		if (car_du_milieu=="" && j.stamp=="") car_du_milieu = moncycle_app_text.to_fill_in_glyph;
		day_timeline.append(`<span class='s'>${j.is_peak ? moncycle_app_text.peak_bill : ""}</span>`);
		if (moncycle_app.constante.nfp_method==1 || moncycle_app.constante.nfp_method==2) day_timeline.append(`<span class='n'></span>`);
		day_timeline.append(`<span class='g ${color}'>${car_du_milieu}</span>`);
		if ((moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) && !j.pregnancy && !j.day_not_observed && j.fc_score){
			recap_note = recap_note.replace('X1','').replace('X2','').replace('X3','');
			let fc_glaire = recap_note.match(/\d+/);
			if (fc_glaire) recap_note = recap_note.replace(fc_glaire[0], '');
			day_timeline.append($("<span>", {class: "fc"}).text(fc_glaire? fc_glaire[0] : ""));
			recap_note = recap_note.trim().replace(/\s+/g, '')
			if (recap_note.length>2) recap_note = moncycle_app_text.fc_note_overflow;
			day_timeline.append($("<span>", {class: "fc"}).text(recap_note));
		}
		else if (moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) {
			day_timeline.append(`<span class='fc'></span>`);
			day_timeline.append(`<span class='fc'></span>`);
		}
		day_timeline.append(`<span class='c'>${car_du_bas}</span>`);
		return day_timeline;
	},
	// The "o" cell of a Billings day: observations as they are, sensations in bold, then the
	// descriptions with no type yet, struck through (css/tableau.css). Built with the DOM, never as
	// HTML: a name is free text. <b> and <s> and text nodes on purpose, `.day span` styles every span
	// as a cell and `.day span:empty` hides it, so a day with no description keeps an empty cell.
	description_cell : function (descriptions) {
		let names_of = (type) => descriptions.filter(d => d.type == type).map(d => d.name);
		let parts = [
			...names_of(1).map(name => document.createTextNode(name)),
			...names_of(2).map(name => $("<b>", {class: "o_sens"}).text(name)[0]),
			...names_of(0).map(name => $("<s>", {class: "o_other", title: moncycle_app_text.desc_legacy_title}).text(name)[0]),
		];
		let cell = $("<span>", {class: "o pas_fc pas_fc_temp"});
		parts.forEach((part, i) => {
			if (i > 0) cell.append(document.createTextNode(", "));
			cell.append(part);
		});
		return cell;
	},
	day_timeline2timeline : function(j) {
		let o_date = moncycle_app.date.parse(j.date_obs);
		let o_id = "o-" + moncycle_app.date.str(o_date);
		let o_class = "day";
		if (moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) o_class += " o_fc";
		else o_class += " o_bill";
		if (j.pregnancy) o_class += " o_gross";
		let day_timeline = $("<div>", {id: o_id, class: o_class, date : moncycle_app.date.str(o_date)});
		let d_bold = o_date.getDay()==0 ? "bold" : "";
		day_timeline.append(`<span class='d ${d_bold}'>${moncycle_app_text.date_row(o_date)}</span>`);
		let pos = $(`<span class='j'>${j.pos}</span>`);
		day_timeline.append(pos);
		day_timeline.click(moncycle_app.open_menu);
		let tbd = true;
		if (j.pregnancy) {
			day_timeline.append(`<span class='e'>${moncycle_app_text.pregnancy}</span>`);
			day_timeline.append(`<span class='s'></span>`);
			day_timeline.append(`<span class='n'></span>`);
			tbd = false;
		}
		else {
			if (j.day_not_observed) {
				day_timeline.append(`<span class='g jcpas'>${moncycle_app_text.not_observed_glyph}</span>`);
				pos.addClass("j_jcpas");
				tbd = false;
			}
			else {
				if (j.stamp) {
					let contenu = "o";
					let color = j.stamp;
					if (j.stamp.includes("BB") && j.stamp.length>2){
						contenu = moncycle_app_text.stamp_glyph["BB"];
						color = j.stamp.replace("BB", "");
					}
					else {
						contenu = moncycle_app_text.stamp_glyph[j.stamp];
					}
					day_timeline.append(`<span class='g ${moncycle_app.stamp_class[color]}'>${contenu}</span>`);
					pos.addClass("j_" + moncycle_app.stamp_class[color]);
					tbd = false;
				}
				let html_fc_score = moncycle_app.fc_note2html(j.fc_score || "");
				day_timeline.append(`<span class='fc pas_bill pas_bill_temp'>${html_fc_score}</span>`);
				if ((moncycle_app.constante.nfp_method==1 || moncycle_app.constante.nfp_method==4) && j.temperature) {
					let temp = parseFloat(j.temperature);
					let color = "#4169e1";
					if (temp > 37.5) color = "#b469e1";
					else if (temp <= 37.5 && temp >= 36.5) {
						let r = parseInt((1-(37.5-temp))*115)+65;
						color = `rgb(${r}, 105, 225)`;
					}
					day_timeline.append(`<span class='t pas_bill pas_fc' style='background-color: ${color}'>${temp}</span>`);
					if (j.time_temp_taken) {
						let hm = j.time_temp_taken.substring(0,5).split(':');
						day_timeline.append(`<span class='th bill pas_fc pas_bill' style='color: ${color}'>${moncycle_app_text.temperature_time(hm[0], hm[1])}</span>`);
					}
					tbd = false;
				}
				if ((moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4) && j.fc_score) tbd = false;
			}
			if (tbd) {
				day_timeline.append(`<span class='g ar'>${moncycle_app_text.to_fill_in_glyph}</span>`);
				day_timeline.append(`<span class='s'></span>`);
				day_timeline.append(`<span class='r'>${moncycle_app_text.to_fill_in}</span>`);
				pos.addClass("j_ar");
				return day_timeline;
			}
			day_timeline.append(`<span class='s'>${j.is_peak ? moncycle_app_text.peak_bill : ""}</span>`);
			day_timeline.append(`<span class='n'></span>`);
			if (!j.day_not_observed) {
				day_timeline.append(moncycle_app.description_cell(j.description));
				if (moncycle_app.arrow_id[j.fc_arrow]) day_timeline.append(`<span class='fle pas_bill pas_bill_temp'>${moncycle_app_text.arrow_glyph[j.fc_arrow] || ""}</span>`);
			}
			else day_timeline.append(`<span class='p'>${moncycle_app_text.not_observed}</span>`);
			day_timeline.append(`<span class='u'>${j.union_sex ? moncycle_app_text.union : ""}</span>`);
		}
		if (j.comment) {
			// free text: one text node per line, a <br> between, never HTML
			let cell = $("<span>", {class: "c"});
			j.comment.trim().split("\n").forEach((line, i) => {
				if (i > 0) cell.append($("<br>"));
				cell.append(document.createTextNode(line));
			});
			day_timeline.append(cell);
		}
		return day_timeline;
	},
	go_blank_or_empty : function () {
		if ($("#go_baby")[0].checked) $("#blank_or_empty").text(moncycle_app_text.target_blank);
		else $("#blank_or_empty").text(moncycle_app_text.target_empty);
	},
	// container holding the chips for each description type: 2=sensation, 1=observation, 0=autre (legacy)
	desc_container_id : {2 : "#menu_sensation_container", 1 : "#menu_observation_container", 0 : "#menu_autre_container"},
	desc_chip_count : 0,
	// A chip is the description's name (see day_to_api); its id only ties the label to the box.
	render_desc_chip : function (sdesc, active) {
		let id = "i_desc_" + moncycle_app.desc_chip_count++;
		return $("<span>", {"class": "desc_chip"})
			.append($("<input>", {type: "checkbox", name: "description[]", value: sdesc.name, id: id, "class": "i_desc"}).prop("checked", !!active))
			.append($("<label>", {"for": id}).text(sdesc.name));
	},
	// wires the "+ nouvelle sensation/observation" affordances in jour_form; there is no
	// equivalent for "autre" since that legacy type can't be created or converted to (see open_menu)
	bind_desc_add_forms : function () {
		$(".desc_add_toggle").on("click", function () {
			$(this).hide();
			$(this).siblings(".desc_add_form").show().find(".desc_add_input").val("").focus();
		});
		$(".desc_add_cancel").on("click", function () {
			let form = $(this).closest(".desc_add_form");
			form.hide().find(".desc_add_status").empty();
			form.siblings(".desc_add_toggle").show();
		});
		$(".desc_add_submit").on("click", function () {
			moncycle_app.desc_add_submit($(this).closest(".desc_add_form"));
		});
		$(".desc_add_input").on("keydown", function (event) {
			if (event.key !== "Enter") return;
			event.preventDefault();
			moncycle_app.desc_add_submit($(this).closest(".desc_add_form"));
		});
	},
	desc_add_submit : function (form) {
		let desc_type = parseInt(form.data("desc-type"));
		let name = form.find(".desc_add_input").val().trim();
		let status = form.find(".desc_add_status");
		if (name == "") return;
		let duplicate = moncycle_app.description.some(d => d.name.toLowerCase() == name.toLowerCase());
		if (duplicate) {
			status.text(moncycle_app_text.desc_add_duplicate(name));
			return;
		}
		// kept here and sent when the server can be reached (js/store.js): the chip is there at once, offline too
		moncycle_store.queue_description(name, moncycle_app.desc_type_from_int[desc_type], moncycle_app.date.nowInUTC());
		let desc = moncycle_app.description.find(d => d.name == name) || {no_description : null, name : name, type : desc_type, use_count : 0};
		let chip = moncycle_app.render_desc_chip(desc, false);
		$(moncycle_app.desc_container_id[desc_type]).append(chip);
		chip.find(".i_desc").on("change", moncycle_app.submit_menu);
		form.find(".desc_add_input").val("").focus();
		status.text(moncycle_app_text.desc_add_saved);
	},
	menu_opened_date : null,
	open_menu : function(e, date = null) {
		let o_date;
		if (date) o_date = moncycle_app.date.parse(date);
		else o_date = moncycle_app.date.parse($(this).attr('date'));
		date = moncycle_app.date.str(o_date);
		moncycle_app.menu_opened_date = date;
		let j = moncycle_app.day_timeline[moncycle_app.menu_opened_date];
		let stamp = j.stamp? j.stamp : "";
		$("#jour_form_titre").html(`${moncycle_app_text.date_long(o_date)} <span>${moncycle_app_text.day_number(j.pos)}</span>`);
		let arrow = {"next" : moncycle_app_text.arrow_up, "prev" : moncycle_app_text.arrow_down};
		if (!moncycle_app.timeline_asc) arrow = {"next" : moncycle_app_text.arrow_down, "prev" : moncycle_app_text.arrow_up};
		$("#jour_form_prev").text(moncycle_app_text.but_day_step(arrow["prev"], j.pos-1));
		$("#jour_form_next").text(moncycle_app_text.but_day_step(arrow["next"], j.pos+1));
		let date_cursor = new Date(j.date_obs);
		date_cursor.setDate(date_cursor.getDate()+1);
		let str_date_cursor = moncycle_app.date.str(date_cursor);
		if (moncycle_app.day_timeline[str_date_cursor] && !moncycle_app.day_timeline[str_date_cursor].cycle_1st_day) {
			$("#jour_form_next").attr("date", str_date_cursor);
			$("#jour_form_next").show();
		}
		else $("#jour_form_next").hide();
		date_cursor.setDate(date_cursor.getDate()-2);
		str_date_cursor = moncycle_app.date.str(date_cursor);
		if (j.pos-1 > 0 && moncycle_app.day_timeline[str_date_cursor]) {
			$("#jour_form_prev").attr("date", str_date_cursor);
			$("#jour_form_prev").show();
		}
		else $("#jour_form_prev").hide();
		$("#jour_form #form_data")[0].reset();
		$("#fc_msg").empty();
		$("#jour_form_saving").hide();
		$("#jour_form_saved").hide();
		$("#jour_form_unsent").toggle(!!moncycle_store.pending[date]);
		$("#form_date").val(j.date_obs);
		if (j.fc_score && (moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4)) {
			$("#form_fc").val(j.fc_score);
			moncycle_app.fc_note2form();
			moncycle_app.fc_test_note();
		}
		if (j.fc_arrow && (moncycle_app.constante.nfp_method==3 || moncycle_app.constante.nfp_method==4)) $("#fc_f" + moncycle_app.arrow_id[j.fc_arrow]).prop('checked', true);
		if (stamp.includes("BB") && stamp.length>2) {
			$("#go_" + moncycle_app.stamp_class["BB"]).prop('checked', true);
			stamp = stamp.replace("BB", "");
		}
		if (moncycle_app.stamp_class[stamp]) $("#go_" + moncycle_app.stamp_class[stamp]).prop('checked', true);
		moncycle_app.go_blank_or_empty();
		$("#form_temp").val(j.temperature);
		$("#form_time_temp_taken").val(j.time_temp_taken);
		$("#menu_observation_container").empty();
		$("#menu_sensation_container").empty();
		$("#menu_autre_container").empty();
		let active_desc = [];
		for (const adesc of j.description) active_desc.push(adesc.name);
		let has_autre = false;
		for (const sdesc of moncycle_app.description) {
			let active = active_desc.includes(sdesc.name);
			let container_id = moncycle_app.desc_container_id[sdesc.type];
			if (!container_id) continue;
			if (sdesc.type == 0) has_autre = true;
			$(container_id).append(moncycle_app.render_desc_chip(sdesc, active));
		}
		// "autre" is a legacy type new descriptions can't be created as or converted to;
		// only show the category when a legacy description of that type still exists
		$("#desc_category_other").toggle(has_autre);
		$(".i_desc").on("change", moncycle_app.submit_menu);
		$(".desc_add_form").hide().find(".desc_add_status").empty();
		$(".desc_add_toggle").show();
		if (j.cycle_1st_day) $("#ev_cycle_1st_day").prop('checked', true);
		if (j.union_sex) $("#ev_union").prop('checked', true);
		if (j.is_peak) $("#ev_is_peak").prop('checked', true);
		if (j.counter_start && j.counter_start > 0) {
			$("#ev_counter_start_actif").prop('checked', true);
			$("#ev_counter_start_nb").val(j.counter_start);
			$("#ev_hidden_counter_start").val(j.counter_start)
		}
		if (j.day_not_observed) $("#ev_jesaispas").prop('checked', true);
		if (j.pregnancy) $("#ev_pregnancy").prop('checked', true);
		$("#from_com").val(j.comment);
		$("html, body").css({
			"overflow": "hidden",
			"touch-action": "none"
		});
		$("#jour_form").show();
		$("#jour_form").scrollTop(0);
		$("#timeline").addClass("flou");
		$("#recap").addClass("flou");
	},
	close_menu : function (e) {
		$("html, body").css({
			"overflow": "visible",
			"touch-action": "auto"
		});
		$("#timeline").removeClass("flou");
		$("#recap").removeClass("flou");
		$("#bulk_form").hide();
		$("#jour_form").hide();
		moncycle_app.menu_opened_date = null;
		// the cycles moved while the form was open: draw them again from the copy
		if (moncycle_app.page_a_recharger) moncycle_app.rebuild();
	},
	// The form is the full state of the day: it goes into the local copy at once and the page draws it
	// (store_changed), then the store sends it; day_sent / day_failed / show_sync_status say how it went.
	submit_menu : function () {
		$("#jour_form_saving").show();
		$("#jour_form_saved").hide();
		$("#jour_form_unsent").hide();
		$("#form_save_time").val(moncycle_app.date.nowInUTC());
		if (this.id == "form_fc") moncycle_app.fc_note2form();
		else moncycle_app.fc_form2note();
		moncycle_app.fc_test_note();
		if ($("#ev_counter_start_actif")[0].checked) $("#ev_hidden_counter_start").val($("#ev_counter_start_nb").val());
		else $("#ev_hidden_counter_start").val(0);
		let d = $("#jour_form #form_data").serializeArray();
		if (moncycle_app.menu_opened_date != null) {
			let j = 0;
			while (j<d.length && d[j]["name"]!="date") j += 1;
			if (j == d.length) d.push({"date" : moncycle_app.menu_opened_date});
			else d[j]["value"] = moncycle_app.menu_opened_date;
		}
		moncycle_store.queue_day(moncycle_app.day_to_api(d));
	},
	bulk_submit_menu : function () {
		let nb_of_days = $("#i_bulk_count").val();
		if (nb_of_days > 365) {
			alert(moncycle_app_text.bulk_too_many_days(365));
			return;
		}
		let menu_current_date = moncycle_app.menu_opened_date;
		let menu_current_1st_day = $("#ev_cycle_1st_day").is(':checked');
		let date_cursor = moncycle_app.date.parse(menu_current_date);
		let j = 1;
		while (j <= nb_of_days && moncycle_app.menu_opened_date!=moncycle_app.day_timeline[menu_current_date]["cycle"]) {
			date_cursor.setDate(date_cursor.getDate()-1);
			moncycle_app.menu_opened_date = moncycle_app.date.str(date_cursor);
			if (moncycle_app.menu_opened_date==moncycle_app.day_timeline[menu_current_date]["cycle"]) $("#ev_cycle_1st_day").prop('checked', true);
			else $("#ev_cycle_1st_day").prop('checked', false);
			moncycle_app.submit_menu();
			j += 1;
		}
		moncycle_app.menu_opened_date = menu_current_date;
		if (menu_current_1st_day) $("#ev_cycle_1st_day").prop('checked', true);
	},
	suppr_day_timeline : function () {
		let date = moncycle_app.date.parse($("#form_date").val());
		date.setHours(9);
		if (confirm(moncycle_app_text.confirm_delete_day(date))) {
			moncycle_store.queue_delete(moncycle_app.date.str(date), moncycle_app.date.nowInUTC());
			moncycle_app.close_menu();
		}
	},
	graph_preparation_data : function (data) {
		$.each(data, function(o_date, o_data) {
			if (moncycle_app.graph_data[o_data.cycle] == undefined) moncycle_app.graph_data[o_data.cycle] = {};
			let date = moncycle_app.date.parse(o_date);
			let label = moncycle_app_text.date_short(date);
			moncycle_app.graph_data[o_data.cycle][label] = parseFloat(o_data.temperature);
			if (moncycle_app.graphs[o_data.cycle]) moncycle_app.graph_update(o_data.cycle);
		});
	},
	fc_note_regex : /^((h|m|l|vl|H|M|L|VL|VH)\s*(b|B)?\s*)?(2W|10KL|10SL|10DL|10WL|2w|10kl|10sl|10dl|10wl|[024]|(([68]|10)\s*[BCGKLPYRbcgklpyr]{1,8}))?\s*([xX][123]|AD|ad)?(\s*[RrLl]?(ap|AP))?$/,
	fc_test_note : function() {
		if (!$("#form_fc").val()) {
			$("#fc_msg").empty();
		}
		else if (moncycle_app.fc_note_regex.test($("#form_fc").val().toUpperCase())) {
			$("#fc_msg").html(moncycle_app_text.fc_syntax_valid);
			$("#fc_msg").addClass("vert");
			$("#fc_msg").removeClass("rouge");
		}
		else {
			$("#fc_msg").html(moncycle_app_text.fc_syntax_invalid);
			$("#fc_msg").addClass("rouge");
			$("#fc_msg").removeClass("vert");
		}
	},
	fc_form2note : function() {
		let note = $('input[name="fc_regles"]:checked').map(function(){ return this.value }).get().join("");
		if (note.length && !note.endsWith(' ')) note += " " ;
		note += $('.fc_sens:checked').map(function(){ return this.value }).get().join("");
		note += $(".fc_obs:checked").map(function(){ return this.value }).get().join("");
		note += $('.fc_sens_L:checked').val() ? $('.fc_sens_L:checked').val() : "";
		if (note.length && !note.endsWith(' ')) note += " ";
		note += $('input[name="fc_rec"]:checked').map(function(){ return this.value }).get().join("");
		if (note.length && !note.endsWith(' ')) note += " ";
		note += $('input[name="fc_dou"]:checked').map(function(){ return this.value }).get().join("");
		$("#form_fc").val(note.trim());
		return note;
	},
	// A text as HTML, for the one place that still builds markup out of a stored value: the server only
	// stores FertilityCare notes made of the notation's codes, so this is a second wall, not the first.
	escape_html (text) {
		return $("<span>").text(text).html();
	},
	fc_note2html (note) {
		const should_be_red = ['VL', 'VH', 'H', 'M', 'B'];
		const less_important = ['RAP', 'LAP', 'AP', 'X1', 'X2', 'X3', 'AD',];
		// escaped after the upper-casing: the entities are lower case, so no code below can match inside one
		note = moncycle_app.escape_html(note.toUpperCase());
		less_important.forEach(c => {
			note = note.replace(c,`<span class='note_not_imp'>${c}</span>`);
		});
		should_be_red.forEach(c => {
			note = note.replace(c,`<span class='note_rouge'>${c}</span>`);
		});
		if (note.startsWith('L')) {
			note = note.slice(1);
			note = `<span class='note_rouge'>L</span>` + note;
		}
		return note;
	},
	fc_note2form : function() {
		let note = $("#form_fc").val().trim().toUpperCase();
		if (note.startsWith('L') && !note.startsWith('LAP')) {
			$("#fc_rl").prop("checked", true);
			note = note.slice(1);
		}
		else {
			$("#fc_rl").prop("checked", false);
		}
		['10DL', '10SL', '10WL', 'RAP', 'LAP', 'X1', 'X2', 'X3', 'AD', 'AP', 'VL', 'VH', '2W', '10', 'H', 'M', 'L', 'B', '0', '2', '4', '6', '8', 'C', 'G', 'K', 'P', 'Y', 'R'].forEach(c => {
			if (note.includes(c)) {
				$("#fc_" + c.toLowerCase()).prop("checked", true);
				note = note.replace(c,'');
			}
			else $("#fc_" + c.toLowerCase()).prop("checked", false);
		});
		note = $("#form_fc").val().trim().toUpperCase();
		['10DL', '10SL', '10WL', '10', '2W'].forEach(c => {
			note = note.replace(c,'');
		});
		['RAP', 'LAP', 'AP'].forEach(c => {
			note = note.replace(c,'');
		});
		['X1', 'X2', 'X3', 'AD'].forEach(c => {
			note = note.replace(c,'');
		});
		['VL', 'VH', 'H', 'M', 'L'].forEach(c => {
			note = note.replace(c,'');
		});
		['0', '2', '4', '6', '8'].forEach(c => {
			note = note.replace(c,'');
		});
		return note;
	},
	date : {
		now : function () {
			let d =  new Date();
			d.setHours(9,0,0,0);
			return d;
		},
		parse : function (str) {
			let d = moncycle_app.date.now();
			let b = str.split(/\D/);
			d.setFullYear(b[0], b[1]-1, b[2]);
			return d;
		},
		str : function (d) {
			let m = d.getMonth()+1;
			let j = d.getDate();
			return [d.getFullYear(), m<10 ? "0"+m : m, j<10 ? "0"+j : j].join("-");
		},
		nowInUTC : function () {
			const now = new Date();
			year = now.getUTCFullYear();
			month = String(now.getUTCMonth() + 1).padStart(2, '0');
			day = String(now.getUTCDate()).padStart(2, '0');
			hours = String(now.getUTCHours()).padStart(2, '0');
			minutes = String(now.getUTCMinutes()).padStart(2, '0');
			seconds = String(now.getUTCSeconds()).padStart(2, '0');
			return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
		}
	}
}

// start once the page is loaded (no inline script: the CSP is script-src 'self')
window.addEventListener("load", moncycle_app.letsgo);
