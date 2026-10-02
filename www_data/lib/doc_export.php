<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The front door of the period exports (api/export.php, script/cron.php): the days of a period,
// and the PDF chart that suits an account's method. See lib/doc.php.

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/doc_bill.php";
require_once __DIR__ . "/doc_fc.php";

// The days of [$start_date, $end_date], oldest first.
//
// A recorded day is day_format_to_json()'s output, with the NFP names for the two fields the API
// names differently (dayNotObserved -> mucusNotObserved, comment -> comments, which stays one
// string: day_timeline holds a single comment), and two additions:
//   - freeOther: the names of the day's type-0 descriptions, the free text the v15 migration
//     could not class as freeMucusSensation or freeMucusObservation. day_format_to_json() leaves
//     them out.
//   - isGap: false.
// A date with no record at all between two recorded days is a gap entry: the same keys, all
// empty, and isGap true. A gap is not a mucusNotObserved day, which is a record.
//
// Gaps are only filled between the first and the last recorded day, and never past today. The
// period is exactly the one asked for -- unlike the NFP export it is not widened back to a
// cycle start -- so cycleDay counts from the latest cycleFirstDay inside the period, and is
// null before the first one.
//
// Two queries, whatever the length of the period: the days, then all of their descriptions.
function doc_export_days($db, string $start_date, string $end_date, array $user_account): array {
	$no_user_account = intval($user_account["no_user_account"]);

	$rows = db_select_day_timelines_export($db, $start_date, $end_date, $no_user_account);
	if (empty($rows)) return [];

	// ordered by day, type, then no_description, so a day's descriptions keep a stable order
	$descriptions_by_day = [];
	foreach (db_select_descriptions_for_day_timeline_frame($db, $start_date, $end_date, $no_user_account) as $description) {
		$descriptions_by_day[intval($description["no_day"])][] = $description;
	}

	$today = date('Y-m-d');
	$cycle_start = null;
	$previous_date = null;
	$days = [];

	foreach ($rows as $row) {
		$date = $row["date_obs"];

		if (!is_null($previous_date)) {
			for ($gap = doc_export_next_date($previous_date); $gap < $date && $gap <= $today; $gap = doc_export_next_date($gap)) {
				$day = doc_export_day(["date_obs" => $gap, "cycle" => $cycle_start, "pos" => doc_export_cycle_day($cycle_start, $gap)]);
				$day["isGap"] = true;
				$days[] = $day;
			}
		}

		if (!empty($row["cycle_1st_day"])) $cycle_start = $date;
		$row["cycle"] = $cycle_start;
		$row["pos"] = doc_export_cycle_day($cycle_start, $date);
		$row["description"] = $descriptions_by_day[intval($row["no_day"])] ?? [];
		$days[] = doc_export_day($row);

		$previous_date = $date;
	}

	return $days;
}

// One day_timeline row, with its "description" rows and its "cycle" / "pos" set -> an export day.
function doc_export_day(array $row): array {
	$day = day_format_to_json($row);

	$day["mucusNotObserved"] = $day["dayNotObserved"];
	$day["comments"] = $day["comment"];
	// the last two are sync metadata, not the day's content, and the export query skips them
	unset($day["dayNotObserved"], $day["comment"], $day["lastWriteClientUtc"], $day["lastWriteDb"]);

	$day["freeOther"] = [];
	foreach ($row["description"] ?? [] as $description) {
		if (intval($description["type"]) === DESCRIPTION_TYPE_UNDEFINED) $day["freeOther"][] = $description["name"];
	}

	$day["isGap"] = false;
	return $day;
}

function doc_export_next_date(string $date): string {
	return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
}

// 1 on the cycle's first day, null when the period holds no cycle start before $date
function doc_export_cycle_day(?string $cycle_start, string $date): ?int {
	if (is_null($cycle_start)) return null;
	return (new DateTimeImmutable($cycle_start))->diff(new DateTimeImmutable($date))->days + 1;
}

function doc_export_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	if (account_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) {
		return doc_fc_pdf($days, $nfp_method, $name, $anonymous);
	}
	return doc_bill_pdf($days, $nfp_method, $name, $anonymous);
}
