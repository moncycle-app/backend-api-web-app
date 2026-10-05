<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// What the app does with its data, on top of the queries of lib/db.php: building a day, saving one,
// the descriptions (the free-text labels of a day). The shape of a day itself is lib/day_format.php.

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";

// A day as the array lib/day_format.php translates: the DB row (or just its date when the day is
// empty), with the first day of its cycle, its position in it, and its descriptions. What the
// caller already has -- the row, the cycle, the position -- is not read again.
function data_construct_day($db, $date, $no_user_account, $raw_day = null, $cycle = null, $pos = null) {
	$day = ["cycle" => $cycle ?? db_select_cycle($db, $date, $no_user_account)];

	if ($day["cycle"] && is_null($pos)) $pos = data_cycle_day($day["cycle"], $date);
	if (!is_null($pos)) $day["pos"] = $pos;

	$raw_day ??= db_select_day_timeline($db, $date, $no_user_account);
	if (empty($raw_day)) return $day + ["date_obs" => $date];

	return array_merge($day, $raw_day, ["description" => db_select_all_description_for_day_timeline($db, $no_user_account, $raw_day["no_day"])]);
}

// The position of a date in the cycle that began on $cycle: 1 for its first day.
function data_cycle_day(string $cycle, string $date): int {
	return intval(date_diff(date_create($cycle), date_create($date))->format('%a')) + 1;
}

// a description row (db_select_description_with_count) as the JSON API shows it, shared by
// api/description.php and api/sync.php so the two never drift apart.
function data_description_to_json(array $row): array {
	return [
		"id" => intval($row["no_description"]),
		"name" => $row["name"],
		"type" => DESCRIPTION_TYPE_NAMES[intval($row["type"])] ?? DESCRIPTION_TYPE_NAMES[DESCRIPTION_TYPE_UNDEFINED],
		"useCount" => intval($row["use_count"] ?? 0),
	];
}

// ---------------------------------------------------------------------------
// Saving a day: the API (one day) and the NFP import (many) both write through these.
// ---------------------------------------------------------------------------

// The description of a name: found among the account's, or created with $type the first time.
// $known keeps the answers from one call to the next (name => id and type), for a file that repeats
// the same few names on every day. A name is one row whatever type it is asked as: "type" in the
// answer is the one the description has, "created" whether this very call made it.
function data_resolve_description($db, int $no_user_account, string $name, int $type, string $last_write_client_UTC, array &$known = []): array {
	$name = trim($name);
	if (isset($known[$name])) return $known[$name] + ["created" => false];

	$existing = db_select_description_exact_name($db, $no_user_account, $name);
	$created = is_null($existing);
	$known[$name] = [
		"id" => $created ? intval(db_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC)) : intval($existing["no_description"]),
		"type" => $created ? $type : intval($existing["type"]),
	];
	return $known[$name] + ["created" => $created];
}

// Writes a day: its row (made when $existing, the row the caller looked up, is null), its fields,
// and the set of descriptions it carries ($no_descriptions, their ids). Not a transaction of its
// own: the caller wraps what has to be all or nothing. Returns the no_day.
function data_write_day($db, int $no_user_account, string $date, ?array $existing, array $fields, array $no_descriptions, string $last_write_client_UTC): int {
	$no_day = is_null($existing) ? intval(db_insert_day_timeline($db, $date, $no_user_account)) : intval($existing["no_day"]);
	db_update_day_timeline($db, $date, $no_user_account, $last_write_client_UTC, $fields);

	$linked = array_map('intval', array_column(db_select_all_description_for_day_timeline($db, $no_user_account, $no_day), "no_description"));
	$wanted = array_values(array_unique($no_descriptions));
	foreach (array_diff($linked, $wanted) as $no_description) db_delete_linked_descriptions($db, $no_day, $no_description);
	foreach (array_diff($wanted, $linked) as $no_description) db_insert_link_description_day_timeline($db, $no_day, $no_description);

	return $no_day;
}

// Saves a day the API received, checked and cleaned by day_format_validate(): in one transaction, its
// fields and its free-text descriptions (created when a name is new). True when the day is new.
function data_save_day($db, int $no_user_account, string $date, array $day, string $last_write_client_UTC): bool {
	return db_transaction($db, function () use ($db, $no_user_account, $date, $day, $last_write_client_UTC) {
		$existing = db_select_day_timeline($db, $date, $no_user_account);

		$known = [];
		$no_descriptions = [];
		foreach ([DESCRIPTION_TYPE_OBSERVATION => 'freeMucusObservation', DESCRIPTION_TYPE_SENSATION => 'freeMucusSensation', DESCRIPTION_TYPE_UNDEFINED => 'freeOther'] as $type => $field) {
			foreach ($day[$field] ?? [] as $name) {
				$no_descriptions[] = data_resolve_description($db, $no_user_account, $name, $type, $last_write_client_UTC, $known)["id"];
			}
		}

		data_write_day($db, $no_user_account, $date, $existing, day_format_from_json($day), $no_descriptions, $last_write_client_UTC);
		return is_null($existing);
	});
}

// A write by the owner counts as activity: an account flagged inactive (the "are you still there"
// mail) is not any more.
function data_reactivate_account($db, array &$user_account): void {
	if (empty($user_account["is_inactive"])) return;
	db_update_is_inactive($db, $user_account["no_user_account"], 0);
	$user_account["is_inactive"] = 0;
}

// ---------------------------------------------------------------------------
// Clearing a day, and changing a description a day carries
// ---------------------------------------------------------------------------

// Clears a day: its fields, and its descriptions with them. The row stays, empty, stamped with when
// it was cleared: that is how GET /api/sync tells the other clients the day is gone. A date that was
// never written has nothing to clear.
function data_clear_day($db, int $no_user_account, string $date, string $last_write_client_UTC): void {
	db_transaction($db, function () use ($db, $no_user_account, $date, $last_write_client_UTC) {
		$existing = db_select_day_timeline($db, $date, $no_user_account);
		if (!is_null($existing)) data_write_day($db, $no_user_account, $date, $existing, [], [], $last_write_client_UTC);
	});
}

// Creates a description ($no_description null), or renames and retypes one. A day shows its
// descriptions by name and type, so the days carrying it are stamped written: the sync reports them.
// Returns the id.
function data_save_description($db, int $no_user_account, ?int $no_description, string $name, int $type, string $last_write_client_UTC): int {
	if (is_null($no_description)) return intval(db_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC));

	db_transaction($db, function () use ($db, $no_user_account, $no_description, $name, $type, $last_write_client_UTC) {
		db_update_description_name_type($db, $no_user_account, $no_description, $name, $type, $last_write_client_UTC);
		db_update_day_timeline_touch_description($db, $no_user_account, $no_description);
	});
	return $no_description;
}

// Deletes a description, and with it its links to the days. Those days are stamped first (the links
// go with the description): the sync reports them without it.
function data_delete_description($db, int $no_user_account, int $no_description): void {
	db_transaction($db, function () use ($db, $no_user_account, $no_description) {
		db_update_day_timeline_touch_description($db, $no_user_account, $no_description);
		db_delete_descriptions($db, $no_description, $no_user_account);
	});
}

// ---------------------------------------------------------------------------
// GET /api/sync: what a client needs to catch up with the account
// ---------------------------------------------------------------------------

// The date ranges a set of written days changes. A day's cycleStartDate and cycleDay come from the
// first day of its cycle, which a write may have set or cleared: so a written day changes every day
// from it to the next first day. $dates and $starts (the cycles' first days) are YYYY-MM-DD, $starts
// oldest first. A range is [first date, end not included], the end null when the cycle is the last
// one; touching ranges are one.
function data_cycle_tails(array $dates, array $starts): array {
	sort($dates);
	$ranges = [];
	foreach ($dates as $date) {
		$end = null;
		foreach ($starts as $start) {
			if ($start > $date) {
				$end = $start;
				break;
			}
		}
		$last = count($ranges) - 1;
		if ($last >= 0 && ($ranges[$last][1] === null || $date <= $ranges[$last][1])) $ranges[$last][1] = $end;
		else $ranges[] = [$date, $end];
	}
	return $ranges;
}

// The days written since $from_timestamp (the database's clock, less SYNC_OVERLAP_SECONDS) and the
// days that follow them in their cycles, in date order, as data_construct_day() builds them. A
// cleared day is among them: it is a row that is empty. The queries are one per range of days, and
// one for the descriptions of the range, whatever the number of days.
function data_sync_days($db, int $no_user_account, string $from_timestamp): array {
	$written = db_select_day_timeline_dates_written_since($db, $from_timestamp, SYNC_OVERLAP_SECONDS, $no_user_account);
	if (empty($written)) return [];

	$starts = array_reverse(db_select_cycles($db, $no_user_account));
	$days = [];
	foreach (data_cycle_tails($written, $starts) as [$range_start, $range_end]) {
		$rows = db_select_day_timelines_range($db, $range_start, $range_end, $no_user_account);
		if (empty($rows)) continue;

		$descriptions = [];
		foreach (db_select_descriptions_for_day_timeline_frame($db, $range_start, end($rows)["date_obs"], $no_user_account) as $link) {
			$descriptions[$link["no_day"]][] = $link;
		}

		$cycle = null;
		$next_start = 0;
		foreach ($rows as $row) {
			while ($next_start < count($starts) && $starts[$next_start] <= $row["date_obs"]) $cycle = $starts[$next_start++];
			$day = ["cycle" => $cycle];
			if ($cycle) $day["pos"] = data_cycle_day($cycle, $row["date_obs"]);
			$days[] = array_merge($day, $row, ["description" => $descriptions[$row["no_day"]] ?? []]);
		}
	}
	return $days;
}

// The answer of GET /api/sync: the cursor to send next time (the database's clock, read before
// anything else so what is written while this runs is in the next one), the days to merge, every
// description, and the account summary. The descriptions come whole every time: there are few of
// them, and that is how a deletion, a rename and the count of days each is on reach the client.
function data_sync($db, array $user_account, string $from_timestamp): array {
	$no_user_account = intval($user_account["no_user_account"]);
	$now = db_select_now($db);

	return [
		"syncTimestamp" => http_iso8601($now),
		"days" => array_map('day_format_to_json', data_sync_days($db, $no_user_account, $from_timestamp)),
		"descriptions" => array_map('data_description_to_json', db_select_description_with_count($db, $no_user_account)),
		"keyInfos" => account_key_infos($db, $user_account),
	];
}
