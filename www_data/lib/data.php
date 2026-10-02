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

require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";

// A day as the array lib/day_format.php translates: the DB row (or just its date when the day is
// empty), with the first day of its cycle, its position in it, and its descriptions. What the
// caller already has -- the row, the cycle, the position -- is not read again.
function data_construct_day($db, $date, $no_user_account, $raw_day = null, $cycle = null, $pos = null) {
	$day = ["cycle" => $cycle ?? db_select_cycle($db, $date, $no_user_account)];

	if ($day["cycle"] && is_null($pos)) {
		$pos = intval(date_diff(date_create($day["cycle"]), date_create($date))->format('%a')) + 1;
	}
	if (!is_null($pos)) $day["pos"] = $pos;

	$raw_day ??= db_select_day_timeline($db, $date, $no_user_account);
	if (empty($raw_day)) return $day + ["date_obs" => $date];

	return array_merge($day, $raw_day, ["description" => db_select_all_description_for_day_timeline($db, $no_user_account, $raw_day["no_day"])]);
}

// a description row (db_select_description_with_count*) as the JSON API shows it, shared by
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
		foreach ([DESCRIPTION_TYPE_OBSERVATION => 'freeMucusObservation', DESCRIPTION_TYPE_SENSATION => 'freeMucusSensation'] as $type => $field) {
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
