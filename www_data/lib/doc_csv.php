<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** The CSV of a period, and the raw data export of an account (api/all_of_my_data_plz.php).
**
** Opened in text editors, Excel (FR and EN), Google Sheets and LibreOffice, so it sticks to the
** most compatible shape: UTF-8 with a BOM (what makes Excel read it as UTF-8), CSV_SEP between
** fields, RFC 4180 quoting, CRLF line ends, and as many fields on every row as in the header.
*/

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/doc.php";

// Named after the NFP fields, or after the API's day where NFP has none (date, cycleDay,
// cycleFirstDay).
function doc_csv_columns(int $nfp_method): array {
	$temperature = account_tracks_temperature($nfp_method) ? ["temperature", "temperatureTime"] : [];

	if (account_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) {
		return ["date", "cycleDay", "cycleFirstDay", "mucusNotObserved", "stampColor", "stampBaby",
			"codifiedBleedingObservation", "codifiedMucusSensation", "codifiedMucusObservation",
			"codifiedNumberObservations", "codifiedPainObservations", "codifiedArrow",
			...$temperature, "isPeak", "sexUnion", "booleanPregnancyDetected", "comments"];
	}

	return ["date", "cycleDay", "cycleFirstDay", "mucusNotObserved", "stampColor", "stampBaby",
		"freeMucusSensation", "freeMucusObservation",
		...$temperature, "isPeak", "counterStart", "sexUnion", "booleanPregnancyDetected", "comments"];
}

function doc_csv_cycle($out, array $days, int $nfp_method): void {
	$columns = doc_csv_columns($nfp_method);

	fwrite($out, "\xEF\xBB\xBF");
	doc_csv_row($out, $columns);
	foreach ($days as $day) {
		$day = doc_display_day($day);
		doc_csv_row($out, array_map(fn($column) => doc_csv_value($day, $column), $columns));
	}
}

// The empty escape character is RFC 4180 (a quote is escaped by doubling it, and nothing else
// escapes), and PHP 8.4 deprecates relying on the default one.
function doc_csv_row($out, array $fields): void {
	fputcsv($out, $fields, CSV_SEP, '"', '', "\r\n");
}

// A stored table for the raw data export (api/all_of_my_data_plz.php): the column names, each
// followed by the separator, then the rows, then a blank line. Nothing at all for no rows.
function doc_csv_dump($out, array $rows): void {
	if (empty($rows)) return;
	foreach (array_keys($rows[0]) as $column) fputs($out, $column . CSV_SEP);
	fputs($out, PHP_EOL);
	foreach ($rows as $row) fputcsv($out, $row, CSV_SEP, '"', '\\');
	fputs($out, PHP_EOL);
}

// Every day of the account as stored, for the raw data export. The legacy "sensation" column is
// unused: its place is taken by the sensation and observation descriptions of the day.
function doc_csv_raw_days($db, int $no_user_account): array {
	$names_by_day = [];
	foreach (db_select_descriptions_for_day_timeline_frame($db, "0000-00-00", "9999-12-31", $no_user_account) as $description) {
		$names_by_day[$description["no_day"]][intval($description["type"])][] = $description["name"];
	}

	$rows = [];
	foreach (db_select_all_day_timeline($db, $no_user_account) as $day) {
		$row = [];
		foreach ($day as $column => $value) {
			if ($column !== "sensation") {
				$row[$column] = $value;
				continue;
			}
			$row["sensation"] = implode(DOC_CSV_LIST_JOINER, $names_by_day[$day["no_day"]][DESCRIPTION_TYPE_SENSATION] ?? []);
			$row["observation"] = implode(DOC_CSV_LIST_JOINER, $names_by_day[$day["no_day"]][DESCRIPTION_TYPE_OBSERVATION] ?? []);
		}
		$rows[] = $row;
	}
	return $rows;
}

// Booleans are "1" or empty, lists are joined with DOC_CSV_LIST_JOINER, dates are ISO, times
// HH:MM, and the temperature takes a decimal comma when the separator is ";" -- the French
// spreadsheet convention, where "," cannot be the separator. A gap only has its date.
function doc_csv_value(array $day, string $column): string {
	if ($day["isGap"] && $column !== "date" && $column !== "cycleDay") return '';

	$value = $day[$column] ?? null;
	// is_null() and not empty(): "0" is a FertilityCare code (dryness)
	if (is_null($value)) return '';

	return match ($column) {
		"freeMucusSensation", "freeMucusObservation" =>
			doc_csv_free_text(implode(DOC_CSV_LIST_JOINER, array_map('day_format_clean_text', $value))),
		"comments" => doc_csv_free_text(day_format_clean_text($value)),
		"temperature" => number_format($value, 2, CSV_SEP === ';' ? ',' : '.', ''),
		"temperatureTime" => doc_time_hhmm($value),
		default => is_bool($value) ? ($value ? '1' : '') : (string) $value,
	};
}

// A spreadsheet runs a cell starting with one of these as a formula, so free text that does
// gets a leading apostrophe (OWASP's advice against CSV injection): "=1+1" is then read as
// text, at the cost of the apostrophe showing in some readers.
function doc_csv_free_text(string $text): string {
	if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) return "'" . $text;
	return $text;
}
