/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/* ===========================================================================
** THE LOCAL COPY OF THE ACCOUNT
**
** What a page draws comes from localStorage, and localStorage follows the server:
**
**  - it holds what the API says, in the API's own JSON (the Day, Description and KeyInfos objects of
**    api/moncycle_app_open_api.yaml), one key per thing: data_day_YYYY-MM-DD, data_descriptions,
**    data_account;
**  - a change of a day is applied to the copy first, then sent. Until the server has taken it, it
**    waits in a queue (data_pending, in localStorage too, so closing the page loses nothing) and the
**    page is told whenever it could not be sent;
**  - GET /api/sync brings what was changed elsewhere, and the cursor it gives is kept
**    (data_last_sync);
**  - when the session ends, all of localStorage goes. When the app is updated (api/version changes),
**    the copy goes and is downloaded again; the unsent changes stay.
**
** This file holds no text for the user and draws nothing: a page listens to the events (see on()).
** ======================================================================== */
const moncycle_store = {

	// Bump it when what is kept in localStorage changes shape: the copy of the versions before is dropped.
	schema : 1,
	keys : {version : "data_version", last_sync : "data_last_sync", account : "data_account", descriptions : "data_descriptions", pending : "data_pending", stale : "data_stale", day : "data_day_"},
	// what the versions before this one kept in localStorage, and nobody reads any more
	legacy_keys : ["description", "constante", "day_timeline", "timeline_asc"],

	// the copy, in memory (the same as in localStorage)
	days : {},          // date -> Day: only the days that hold something, a cleared day is not kept
	descriptions : [],  // [Description]
	account : null,     // KeyInfos
	last_sync : null,   // the syncTimestamp of the last merged answer
	pending : {},       // date -> {op: "post", body: the POST /api/day body} | {op: "delete", lastWriteClientUtc}
	stale : [],         // dates of days whose copy is wrong (a write of them was refused): to download again

	// short-lived state
	touched : {},       // date -> true: written here since the running sync started (its answer is older)
	syncing : null,     // the promise of the running sync
	flushing : null,    // the promise of the running flush
	checked_version : false,
	expired : false,
	storage_failed : false,
	retry_timer : null,
	retry_count : 0,
	last_pull : 0,      // when the last sync ended well (Date.now())
	error : null,       // {kind: "network" | "server" | "rejected", message, date?} of the last failure; null when none
	listeners : {},

	// the sync is not run again for a page that comes back into view within this delay (ms)
	wake_min_interval : 20000,
	request_timeout : 30000,
	retry_delays : [5000, 15000, 60000, 300000],
	// the content a Day holds, as opposed to where it stands in its cycle and when it was written
	day_meta : ["date", "cycleStartDate", "cycleDay", "lastWriteClientUtc", "lastWriteDb"],
	codified_fields : ["codifiedBleedingObservation", "codifiedMucusSensation", "codifiedMucusObservation", "codifiedNumberObservations", "codifiedPainObservations"],

	/* -----------------------------------------------------------------------
	** EVENTS
	**   status  {syncing, pending, error}   the state to show: syncing, waiting changes, the last failure
	**   changed {days, descriptions, account, source}   the copy was changed ("sync": by an answer of the
	**           server, "server": by the answer to one of our writes, "local": by a write of the user)
	**   sent    {date}                      the server has taken the write of that day
	**   failed  {date, message, issues}     the server refused a write for good: the change is dropped
	** ====================================================================== */
	on : function (name, fn) {
		(moncycle_store.listeners[name] = moncycle_store.listeners[name] || []).push(fn);
	},
	emit : function (name, detail) {
		(moncycle_store.listeners[name] || []).forEach(function (fn) {
			try { fn(detail); }
			catch (e) { console.error(e); }
		});
	},
	emit_status : function () {
		moncycle_store.emit("status", {syncing : !!(moncycle_store.syncing || moncycle_store.flushing), pending : moncycle_store.pending_count(), error : moncycle_store.error});
	},
	pending_count : function () {
		return Object.keys(moncycle_store.pending).length;
	},

	/* -----------------------------------------------------------------------
	** LOCALSTORAGE
	** ====================================================================== */
	read : function (key) {
		try {
			let raw = localStorage.getItem(key);
			return raw === null ? null : JSON.parse(raw);
		}
		catch (e) { return null; }
	},
	// A write that fails (storage full, or refused) is not fatal: the page goes on with the copy in
	// memory, and the cursor is dropped so the next visit downloads everything again.
	write : function (key, value) {
		if (moncycle_store.expired) return;
		try { localStorage.setItem(key, JSON.stringify(value)); }
		catch (e) {
			console.error(e);
			moncycle_store.storage_failed = true;
			try { localStorage.removeItem(moncycle_store.keys.last_sync); } catch (e2) { }
		}
	},
	erase : function (key) {
		if (moncycle_store.expired) return;
		try { localStorage.removeItem(key); } catch (e) { }
	},
	// every key of the copy (not the other things localStorage holds: the user id, the view chosen...)
	data_keys : function () {
		let found = [];
		for (let i = 0; i < localStorage.length; i++) {
			let key = localStorage.key(i);
			if (key.startsWith("data_")) found.push(key);
		}
		return found;
	},
	// Forgets the copy. The queue of unsent changes stays when asked: an update of the app is no
	// reason to lose what the user did offline.
	wipe_data : function (keep_pending) {
		let queue = keep_pending ? moncycle_store.read(moncycle_store.keys.pending) : null;
		moncycle_store.data_keys().forEach(function (key) { localStorage.removeItem(key); });
		moncycle_store.days = {};
		moncycle_store.descriptions = [];
		moncycle_store.account = null;
		moncycle_store.last_sync = null;
		moncycle_store.pending = queue || {};
		moncycle_store.stale = [];
		if (queue) moncycle_store.write(moncycle_store.keys.pending, queue);
	},
	// The session is over: the copy is personal health data, so nothing of it stays in the browser.
	expire_session : function () {
		if (moncycle_store.expired) return;
		moncycle_store.expired = true;
		clearTimeout(moncycle_store.retry_timer);
		try { localStorage.clear(); } catch (e) { }
		window.location.replace("/auth");
	},
	// 401 "unauthorized" is a session that is over. Another 401 is an answer of an endpoint to a
	// wrong password or code (POST /api/password_change, DELETE /api/account): the session is fine.
	is_unauthorized : function (jqXHR) {
		if (jqXHR.status != 401) return false;
		let code = ((jqXHR.responseJSON || {}).error || {}).code;
		return code === undefined || code === "unauthorized";
	},

	/* -----------------------------------------------------------------------
	** START: read the copy
	** ====================================================================== */
	init : function () {
		moncycle_store.legacy_keys.forEach(function (key) { localStorage.removeItem(key); });

		let stamp = localStorage.getItem(moncycle_store.keys.version);
		if (stamp === null || stamp.split("/")[0] != String(moncycle_store.schema)) {
			moncycle_store.wipe_data(false);
			stamp = null;
		}

		moncycle_store.days = {};
		moncycle_store.data_keys().forEach(function (key) {
			if (!key.startsWith(moncycle_store.keys.day)) return;
			let day = moncycle_store.read(key);
			if (day && day.date) moncycle_store.days[day.date] = day;
		});
		moncycle_store.descriptions = moncycle_store.read(moncycle_store.keys.descriptions) || [];
		moncycle_store.account = moncycle_store.read(moncycle_store.keys.account);
		moncycle_store.last_sync = moncycle_store.read(moncycle_store.keys.last_sync);
		moncycle_store.pending = moncycle_store.read(moncycle_store.keys.pending) || {};
		moncycle_store.stale = moncycle_store.read(moncycle_store.keys.stale) || [];
		// a copy without a cursor, or without the account, is not one to trust
		if (!moncycle_store.account || !moncycle_store.last_sync) {
			moncycle_store.wipe_data(true);
			if (stamp !== null) localStorage.setItem(moncycle_store.keys.version, stamp);
		}

		$(document).ajaxError(function (event, jqXHR) {
			if (moncycle_store.is_unauthorized(jqXHR)) moncycle_store.expire_session();
		});
		window.addEventListener("online", function () { moncycle_store.wake(true); });
		window.addEventListener("focus", function () { moncycle_store.wake(false); });
		document.addEventListener("visibilitychange", function () { if (!document.hidden) moncycle_store.wake(false); });
		// unsent changes are kept in localStorage and sent at the next visit, but a session that ends before
		// then drops them with the rest: leaving with some waiting asks first
		window.addEventListener("beforeunload", function (event) {
			if (moncycle_store.pending_count() == 0) return;
			event.preventDefault();
			event.returnValue = "";
		});
	},

	// A page that comes back into view: sync, unless it just did.
	wake : function (force) {
		if (moncycle_store.expired) return;
		if (!force && moncycle_store.pending_count() == 0 && Date.now() - moncycle_store.last_pull < moncycle_store.wake_min_interval) return;
		moncycle_store.sync().catch(function () { });
	},

	/* -----------------------------------------------------------------------
	** THE API
	** ====================================================================== */
	request : function (method, url, body) {
		return new Promise(function (resolve, reject) {
			$.ajax({
				type : method,
				url : url,
				contentType : "application/json",
				data : body === undefined ? undefined : JSON.stringify(body),
				dataType : "json",
				cache : false,
				timeout : moncycle_store.request_timeout,
			}).done(function (ret) { resolve(ret); }).fail(function (jqXHR) { reject(jqXHR); });
		});
	},
	// A failure that a later try can mend: no answer, a server in trouble, or an answer that is not the
	// API's. Only a refusal of the request itself (4xx) is final. Sending a day twice is harmless.
	is_transient : function (jqXHR) {
		return !(jqXHR.status >= 400 && jqXHR.status < 500 && jqXHR.status != 408 && jqXHR.status != 429);
	},
	error_of : function (jqXHR) {
		let error = (jqXHR.responseJSON || {}).error || {};
		return {
			kind : jqXHR.status === 0 ? "network" : (moncycle_store.is_transient(jqXHR) ? "server" : "rejected"),
			code : error.code || null,
			message : error.message || jqXHR.statusText || "",
			issues : (error.details || {}).issues || [],
		};
	},

	/* -----------------------------------------------------------------------
	** A DAY IN THE COPY
	** ====================================================================== */
	// the empty day the API answers for a date that holds nothing
	blank_day : function (date) {
		let day = {
			date : date, cycleStartDate : null, cycleDay : null, cycleFirstDay : false, dayNotObserved : false,
			stampColor : null, stampBaby : false, isPeak : false, counterStart : null, sexUnion : false, booleanPregnancyDetected : false,
			freeMucusSensation : [], freeMucusObservation : [], temperature : null, temperatureTime : null, codifiedArrow : null,
			comment : "", lastWriteClientUtc : null, lastWriteDb : null,
		};
		moncycle_store.codified_fields.forEach(function (field) { day[field] = null; });
		return day;
	},
	is_blank : function (day) {
		let blank = moncycle_store.blank_day(day.date);
		return Object.keys(blank).every(function (key) {
			return moncycle_store.day_meta.includes(key) || JSON.stringify(day[key]) === JSON.stringify(blank[key]);
		});
	},
	// the day of a date, an empty one when it holds nothing; with its place in its cycle
	get_day : function (date) {
		return moncycle_store.days[date] || Object.assign(moncycle_store.blank_day(date), moncycle_store.cycle_of(date));
	},
	// A day goes in the copy, in memory and in localStorage; an empty one is the same as none.
	set_day : function (day) {
		if (moncycle_store.is_blank(day)) {
			delete moncycle_store.days[day.date];
			moncycle_store.erase(moncycle_store.keys.day + day.date);
		}
		else {
			moncycle_store.days[day.date] = day;
			moncycle_store.write(moncycle_store.keys.day + day.date, day);
		}
	},

	// the number of days from a cycle's first day to a date, plus one
	day_number : function (cycle, date) {
		return Math.round((Date.parse(date + "T00:00:00Z") - Date.parse(cycle + "T00:00:00Z")) / 86400000) + 1;
	},
	// where a date stands in the cycles of the account: the closest first day at or before it
	cycle_of : function (date) {
		let cycle = null;
		((moncycle_store.account || {}).allCyclesFirstDay || []).forEach(function (start) {
			if (start <= date && (cycle === null || start > cycle)) cycle = start;
		});
		return {cycleStartDate : cycle, cycleDay : cycle ? moncycle_store.day_number(cycle, date) : null};
	},
	// A list of dates, newest first, with a date in it or out of it.
	set_member : function (list, date, member) {
		let at = list.indexOf(date);
		if (member && at < 0) {
			list.push(date);
			list.sort().reverse();
		}
		else if (!member && at >= 0) list.splice(at, 1);
	},
	// The account lists the first days and the pregnancies, and every day shows its place in its cycle:
	// after a day changed, make both agree with it. Returns the dates of the days that changed (the day
	// itself first) and whether the account's lists did.
	follow_day : function (date) {
		let day = moncycle_store.days[date];
		if (!moncycle_store.account) return {days : [date], account : false};
		let lists = JSON.stringify([moncycle_store.account.allCyclesFirstDay, moncycle_store.account.allPregnancyDates]);
		moncycle_store.set_member(moncycle_store.account.allCyclesFirstDay, date, !!(day && day.cycleFirstDay));
		moncycle_store.set_member(moncycle_store.account.allPregnancyDates, date, !!(day && day.booleanPregnancyDetected));
		moncycle_store.write(moncycle_store.keys.account, moncycle_store.account);
		let moved = moncycle_store.recompute_cycles().filter(function (other) { return other != date; });
		return {days : [date].concat(moved), account : lists !== JSON.stringify([moncycle_store.account.allCyclesFirstDay, moncycle_store.account.allPregnancyDates])};
	},
	recompute_cycles : function () {
		let starts = moncycle_store.account.allCyclesFirstDay.slice().sort();
		let moved = [];
		let next = 0;
		let cycle = null;
		Object.keys(moncycle_store.days).sort().forEach(function (date) {
			while (next < starts.length && starts[next] <= date) cycle = starts[next++];
			let day = moncycle_store.days[date];
			let place = cycle ? moncycle_store.day_number(cycle, date) : null;
			if (day.cycleStartDate === cycle && day.cycleDay === place) return;
			day.cycleStartDate = cycle;
			day.cycleDay = place;
			moncycle_store.write(moncycle_store.keys.day + date, day);
			moved.push(date);
		});
		return moved;
	},

	// The day the server will hold once it has taken a POST /api/day body, as far as the page can tell.
	// The server's own answer replaces it as soon as there is one.
	day_from_body : function (body, previous) {
		let baby = !!body.stampBaby;
		let temperature = body.temperature > 0;
		let day = Object.assign(moncycle_store.blank_day(body.date), {
			cycleFirstDay : !!body.cycleFirstDay,
			dayNotObserved : !!body.dayNotObserved,
			stampColor : body.stampColor || (baby ? "White" : null),
			stampBaby : baby,
			isPeak : !!body.isPeak,
			counterStart : body.counterStart > 0 ? body.counterStart : null,
			sexUnion : !!body.sexUnion,
			booleanPregnancyDetected : !!body.booleanPregnancyDetected,
			freeMucusSensation : (body.freeMucusSensation || []).slice(),
			freeMucusObservation : (body.freeMucusObservation || []).slice(),
			temperature : temperature ? body.temperature : null,
			temperatureTime : temperature && body.temperatureTime ? body.temperatureTime : null,
			codifiedArrow : body.codifiedArrow || null,
			comment : body.comment || "",
			lastWriteClientUtc : body.lastWriteClientUtc ? body.lastWriteClientUtc.replace(" ", "T").replace(/Z?$/, "Z") : null,
			lastWriteDb : previous ? previous.lastWriteDb : null,
		});
		moncycle_store.codified_fields.forEach(function (field) { day[field] = body[field] || null; });
		return day;
	},

	/* -----------------------------------------------------------------------
	** WRITING: the copy first, then the server
	** ====================================================================== */
	// A POST /api/day body (the full state of the day). Returns the dates whose day changed in the copy.
	queue_day : function (body) {
		let date = body.date;
		moncycle_store.days[date] = moncycle_store.day_from_body(body, moncycle_store.days[date]);
		moncycle_store.set_day(moncycle_store.days[date]);
		return moncycle_store.queued(date, {op : "post", body : body});
	},
	// DELETE /api/day: the day is cleared, with its descriptions
	queue_delete : function (date, last_write_client_utc) {
		moncycle_store.set_day(moncycle_store.blank_day(date));
		return moncycle_store.queued(date, {op : "delete", lastWriteClientUtc : last_write_client_utc || null});
	},
	queued : function (date, entry) {
		moncycle_store.pending[date] = entry;
		moncycle_store.touched[date] = true;
		moncycle_store.write(moncycle_store.keys.pending, moncycle_store.pending);
		let followed = moncycle_store.follow_day(date);
		moncycle_store.emit("changed", {days : followed.days, descriptions : false, account : followed.account, source : "local"});
		moncycle_store.flush().catch(function () { });
		return followed.days;
	},
	// A description the server has just created.
	add_description : function (description) {
		moncycle_store.descriptions.push({id : description.id, name : description.name, type : description.type, useCount : 0});
		moncycle_store.write(moncycle_store.keys.descriptions, moncycle_store.descriptions);
	},

	// Sends the queue, oldest first, one request at a time. Resolves when it is empty. Rejects when something
	// is left that a later try can mend, and a retry is planned: no answer at all stops the pass (the network
	// is down), but a server that fails on one day (an error page, a 5xx) does not hold back the others.
	flush : function () {
		if (moncycle_store.expired) return Promise.reject();
		if (!moncycle_store.flushing) {
			moncycle_store.flushing = moncycle_store.flush_queue().finally(function () {
				moncycle_store.flushing = null;
				moncycle_store.emit_status();
			});
			moncycle_store.emit_status();
		}
		return moncycle_store.flushing;
	},
	flush_queue : async function () {
		let failed = {};   // dates the server could not take in this pass
		let failure = null;
		while (true) {
			let date = Object.keys(moncycle_store.pending).find(function (queued) { return !(queued in failed); });
			if (date === undefined) break;
			let entry = moncycle_store.pending[date];
			try {
				let answer = await moncycle_store.send(date, entry);
				moncycle_store.sent(date, entry, answer);
			}
			catch (jqXHR) {
				if (moncycle_store.expired || moncycle_store.is_unauthorized(jqXHR)) throw jqXHR;
				let error = moncycle_store.error_of(jqXHR);
				if (error.kind == "rejected") {
					moncycle_store.rejected(date, entry, error);
					await moncycle_store.reload_stale().catch(function () { });
					continue;
				}
				moncycle_store.error = error;
				failure = jqXHR;
				failed[date] = true;
				if (error.kind == "network") break;
			}
		}
		if (failure) {
			// (a day sent after the failure cleared the error: something is still waiting, so it is shown again)
			moncycle_store.error = moncycle_store.error_of(failure);
			moncycle_store.plan_retry();
			throw failure;
		}
	},
	send : function (date, entry) {
		if (entry.op == "delete") {
			let query = "api/day?date=" + encodeURIComponent(date) + (entry.lastWriteClientUtc ? "&lastWriteClientUtc=" + encodeURIComponent(entry.lastWriteClientUtc) : "");
			return moncycle_store.request("DELETE", query);
		}
		return moncycle_store.request("POST", "api/day", entry.body);
	},
	// The server took a write. Unless the user wrote that day again meanwhile (then the queue holds a
	// newer entry, and the copy is newer than this answer), the copy gets the day as the server holds it.
	sent : function (date, entry, answer) {
		moncycle_store.retry_count = 0;
		moncycle_store.error = null;
		if (moncycle_store.pending[date] !== entry) return;
		delete moncycle_store.pending[date];
		moncycle_store.write(moncycle_store.keys.pending, moncycle_store.pending);
		moncycle_store.touched[date] = true;
		if (entry.op == "post" && answer && answer.data) {
			let before = JSON.stringify(moncycle_store.days[date]);
			moncycle_store.set_day(answer.data);
			if (before !== JSON.stringify(moncycle_store.days[date])) moncycle_store.emit("changed", {days : [date], descriptions : false, account : false, source : "server"});
		}
		moncycle_store.emit("sent", {date : date});
		moncycle_store.emit_status();
	},
	// The server refused a write for good (the day it was given cannot be stored): the change is dropped,
	// the page is told why, and the day is downloaded again: the server holds something else than the copy.
	rejected : function (date, entry, error) {
		if (moncycle_store.pending[date] === entry) {
			delete moncycle_store.pending[date];
			moncycle_store.write(moncycle_store.keys.pending, moncycle_store.pending);
			if (!moncycle_store.stale.includes(date)) moncycle_store.stale.push(date);
			moncycle_store.write(moncycle_store.keys.stale, moncycle_store.stale);
		}
		let message = error.issues.length ? error.issues.join(" ") : error.message;
		moncycle_store.error = {kind : "rejected", code : error.code, message : message, date : date};
		moncycle_store.emit("failed", {date : date, message : message, issues : error.issues});
		moncycle_store.emit_status();
	},
	// Downloads again the days whose copy is wrong (GET /api/day); one written here again meanwhile is newer.
	reload_stale : async function () {
		while (moncycle_store.stale.length) {
			let date = moncycle_store.stale[0];
			if (!moncycle_store.pending[date]) {
				let answer = await moncycle_store.request("GET", "api/day?date=" + encodeURIComponent(date));
				let before = JSON.stringify(moncycle_store.days[date]);
				moncycle_store.set_day((answer.data || {})[date] || moncycle_store.blank_day(date));
				let followed = moncycle_store.follow_day(date);
				if (before !== JSON.stringify(moncycle_store.days[date]) || followed.account) {
					moncycle_store.emit("changed", {days : followed.days, descriptions : false, account : followed.account, source : "server"});
				}
			}
			moncycle_store.stale.shift();
			moncycle_store.write(moncycle_store.keys.stale, moncycle_store.stale);
		}
	},
	// The user has read the failure shown: it is not shown any more (the next failure is).
	dismiss_error : function () {
		moncycle_store.error = null;
		moncycle_store.emit_status();
	},
	plan_retry : function () {
		clearTimeout(moncycle_store.retry_timer);
		let delays = moncycle_store.retry_delays;
		let delay = delays[Math.min(moncycle_store.retry_count++, delays.length - 1)];
		moncycle_store.retry_timer = setTimeout(function () { moncycle_store.wake(true); }, delay);
	},

	/* -----------------------------------------------------------------------
	** SYNC
	** ====================================================================== */
	// Sends what waits, then fetches what changed. Resolves with what the answer changed in the copy.
	// A sync already running is the one returned.
	sync : function () {
		if (moncycle_store.expired) return Promise.reject();
		if (!moncycle_store.syncing) {
			moncycle_store.syncing = moncycle_store.flush()
				.then(moncycle_store.reload_stale)
				.then(moncycle_store.check_version)
				.then(moncycle_store.pull)
				.catch(function (jqXHR) {
					if (jqXHR && jqXHR.status !== undefined && !moncycle_store.is_unauthorized(jqXHR)) moncycle_store.error = moncycle_store.error_of(jqXHR);
					throw jqXHR;
				})
				.finally(function () {
					moncycle_store.syncing = null;
					moncycle_store.emit_status();
				});
			moncycle_store.emit_status();
		}
		return moncycle_store.syncing;
	},

	// The copy was downloaded by an app that is not this one any more? Then it goes, and the page
	// is loaded again, so that the new code reads a new copy. The first time there is nothing to compare.
	check_version : function () {
		if (moncycle_store.checked_version) return Promise.resolve();
		return moncycle_store.request("GET", "api/version").then(function (ret) {
			moncycle_store.checked_version = true;
			let stamp = [moncycle_store.schema, ret.version, ret.build, ret.commit].join("/");
			let known = localStorage.getItem(moncycle_store.keys.version);
			if (known === stamp) return;
			if (known !== null) moncycle_store.wipe_data(true);
			try { localStorage.setItem(moncycle_store.keys.version, stamp); } catch (e) { }
			if (known === null) return;
			window.location.reload();
			return new Promise(function () { });
		}, function (jqXHR) {
			// no answer: the version is checked at the next sync
			if (moncycle_store.is_unauthorized(jqXHR)) throw jqXHR;
		});
	},

	pull : function () {
		moncycle_store.touched = {};
		// the first sync has no cursor: the server then reads from the start
		let query = moncycle_store.last_sync ? "?fromTimestamp=" + encodeURIComponent(moncycle_store.last_sync) : "";
		return moncycle_store.request("GET", "api/sync" + query).then(function (ret) {
			let changes = moncycle_store.merge(ret.data);
			moncycle_store.error = null;
			moncycle_store.last_pull = Date.now();
			moncycle_store.last_sync = ret.data.syncTimestamp;
			if (!moncycle_store.storage_failed) moncycle_store.write(moncycle_store.keys.last_sync, moncycle_store.last_sync);
			if (changes.days.length || changes.descriptions || changes.account) moncycle_store.emit("changed", Object.assign(changes, {source : "sync"}));
			return changes;
		});
	},

	// Puts an answer of GET /api/sync in the copy, by replacing: an answer merged twice, or a day received
	// again, changes nothing. A day written here since the request left, or still waiting to be sent, is
	// kept as it is: it is newer than the answer, and about to reach the server.
	merge : function (data) {
		let changes = {days : [], descriptions : false, account : false};

		(data.days || []).forEach(function (day) {
			if (moncycle_store.pending[day.date] || moncycle_store.touched[day.date]) return;
			let before = JSON.stringify(moncycle_store.days[day.date]);
			moncycle_store.set_day(day);
			if (before !== JSON.stringify(moncycle_store.days[day.date])) changes.days.push(day.date);
		});

		if (data.descriptions && JSON.stringify(data.descriptions) !== JSON.stringify(moncycle_store.descriptions)) {
			moncycle_store.descriptions = data.descriptions;
			moncycle_store.write(moncycle_store.keys.descriptions, data.descriptions);
			changes.descriptions = true;
		}

		if (data.keyInfos) {
			let before = JSON.stringify(moncycle_store.account);
			moncycle_store.account = data.keyInfos;
			// the answer does not know the days written here since: the lists agree with them
			Object.keys(moncycle_store.pending).concat(Object.keys(moncycle_store.touched)).forEach(function (date) {
				moncycle_store.set_member(moncycle_store.account.allCyclesFirstDay, date, !!(moncycle_store.days[date] && moncycle_store.days[date].cycleFirstDay));
				moncycle_store.set_member(moncycle_store.account.allPregnancyDates, date, !!(moncycle_store.days[date] && moncycle_store.days[date].booleanPregnancyDetected));
			});
			if (before !== JSON.stringify(moncycle_store.account)) changes.account = true;
			moncycle_store.write(moncycle_store.keys.account, moncycle_store.account);
		}

		// and the days of the answer, which do not know about those, are put back in their cycle
		if (moncycle_store.account && (moncycle_store.pending_count() || Object.keys(moncycle_store.touched).length)) {
			moncycle_store.recompute_cycles().forEach(function (date) {
				if (!changes.days.includes(date)) changes.days.push(date);
			});
		}

		return changes;
	},
};
