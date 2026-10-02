<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** The human-readable exports of a period: a CSV, and a printable chart -- one line per day for
** Billings (nfp_method 1 and 2), a grid for FertilityCare (3 and 4).
**
** One pipeline feeds all three. doc_export_days() reads the period once and returns one entry
** per calendar day, in the structured day shape of lib/day_format.php (NFP field names), gaps
** included. That data is faithful: nothing is hidden or rewritten there. What a file shows of
** a day is the renderers' business, and the one display rule they share -- what a
** mucusNotObserved day leaves out -- lives in doc_display_day().
**
** The NFP export (lib/nfp_file.php) is a separate pipeline on purpose: it widens the period to
** whole cycles and writes every stored value, because it is a data file, not a chart.
**
** The PDFs only use FPDF's core fonts. They are not embedded, which keeps the files tiny, but
** they only cover windows-1252, so every string drawn goes through doc_pdf_text() first.
*/

use Fpdf\Fpdf;

// DocPdf below extends Fpdf as soon as this file loads, so the autoloader has to be there first
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/account.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/nfp_format.php";
require_once __DIR__ . "/nfp_file.php";

// ===========================================================================
// DATA -- one entry per calendar day of the period
// ===========================================================================

// The days of [$start_date, $end_date], oldest first.
//
// A recorded day is day_to_json()'s output, with the NFP names for the two fields the API
// names differently (dayNotObserved -> mucusNotObserved, comment -> comments, which stays one
// string: day_timeline holds a single comment), and two additions:
//   - freeOther: the names of the day's type-0 descriptions, the free text the v15 migration
//     could not class as freeMucusSensation or freeMucusObservation. day_to_json() leaves
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
			for ($gap = doc_date_next($previous_date); $gap < $date && $gap <= $today; $gap = doc_date_next($gap)) {
				$day = doc_export_day(["date_obs" => $gap, "cycle" => $cycle_start, "pos" => doc_cycle_day($cycle_start, $gap)]);
				$day["isGap"] = true;
				$days[] = $day;
			}
		}

		if (!empty($row["cycle_1st_day"])) $cycle_start = $date;
		$row["cycle"] = $cycle_start;
		$row["pos"] = doc_cycle_day($cycle_start, $date);
		$row["description"] = $descriptions_by_day[intval($row["no_day"])] ?? [];
		$days[] = doc_export_day($row);

		$previous_date = $date;
	}

	return $days;
}

// One day_timeline row, with its "description" rows and its "cycle" / "pos" set -> an export day.
function doc_export_day(array $row): array {
	$day = day_to_json($row);

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

function doc_date_next(string $date): string {
	return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
}

// 1 on the cycle's first day, null when the period holds no cycle start before $date
function doc_cycle_day(?string $cycle_start, string $date): ?int {
	if (is_null($cycle_start)) return null;
	return (new DateTimeImmutable($cycle_start))->diff(new DateTimeImmutable($date))->days + 1;
}

// ===========================================================================
// THE SHARED DISPLAY RULE -- mucusNotObserved days
// ===========================================================================

// mucusNotObserved only says that the mucus was not observed that day. These are the fields it
// empties in the CSV and in both PDFs, even when old values are still stored in the row: the
// NFP format's own incompatibility list, plus the FertilityCare recurrence count, the stamp
// (the charts draw "jour non observé" or ??? in its place) and freeOther, the legacy mucus free
// text. Everything else recorded that day -- union, counter, peak, temperature, pain codes,
// arrow, pregnancy, comment, cycle start -- shows as usual.
//
// The NFP export deliberately writes all of them anyway: it is a data file, not a chart.
// The list is DOC_MUCUS_FIELDS (constants.php).

function doc_is_mucus_field(string $field): bool {
	return in_array($field, DOC_MUCUS_FIELDS, true);
}

// $day as every export shows it: on a mucusNotObserved day, the mucus fields emptied.
function doc_display_day(array $day): array {
	if (empty($day["mucusNotObserved"])) return $day;
	foreach ($day as $field => $value) {
		if (!doc_is_mucus_field($field)) continue;
		$day[$field] = match (true) {
			is_array($value) => [],
			is_bool($value) => false,
			default => null,
		};
	}
	return $day;
}

// ===========================================================================
// TEXT
// ===========================================================================

// The core fonts only speak windows-1252. Everything drawn in a PDF goes through here, read as
// UTF-8 -- the DB and this source file both are, so nothing is guessed. A character cp1252 has
// no glyph for (an emoji, a CJK character) is drawn as "?".
function doc_pdf_text(?string $text): string {
	if (is_null($text) || $text === '') return '';
	$substitute = mb_substitute_character();
	mb_substitute_character(0x3F);
	$converted = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
	mb_substitute_character($substitute);
	return $converted;
}

// Free text on one line: line breaks and runs of blanks become a single space.
function doc_one_line(?string $text): string {
	$text = $text ?? '';
	return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

// "07:00:00" -> "07:00". A TIME column can run past 24h ("838:59:59"); that is kept as it is.
function doc_time_hhmm(?string $time): string {
	if (is_null($time) || !preg_match('/^\s*(\d+):(\d{2})/', $time, $m)) return '';
	return $m[1] . ':' . $m[2];
}

function doc_get_initials(string $name): string {
	$initials = '';
	foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
		$initials .= mb_strtoupper(mb_substr($word, 0, 1));
	}
	return $initials;
}

// ===========================================================================
// CSV
// ===========================================================================

// Opened in text editors, Excel (FR and EN), Google Sheets and LibreOffice, so it sticks to the
// most compatible shape: UTF-8 with a BOM (what makes Excel read it as UTF-8), CSV_SEP between
// fields, RFC 4180 quoting, CRLF line ends, and as many fields on every row as in the header.

// Named after the NFP fields, or after the API's day where NFP has none (date, cycleDay,
// cycleFirstDay).
function doc_csv_columns(array $days, int $nfp_method): array {
	$temperature = account_tracks_temperature($nfp_method) ? ["temperature", "temperatureTime"] : [];

	if (account_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) {
		return ["date", "cycleDay", "cycleFirstDay", "mucusNotObserved", "stampColor", "stampBaby",
			"codifiedBleedingObservation", "codifiedMucusSensation", "codifiedMucusObservation",
			"codifiedNumberObservations", "codifiedPainObservations", "codifiedArrow",
			...$temperature, "isPeak", "sexUnion", "booleanPregnancyDetected", "comments"];
	}

	// only accounts the v15 migration left unclassified text on have any, so the column only
	// appears when an exported day actually shows one
	$other = [];
	foreach ($days as $day) {
		if (!empty(doc_display_day($day)["freeOther"])) {
			$other = ["freeOther"];
			break;
		}
	}

	return ["date", "cycleDay", "cycleFirstDay", "mucusNotObserved", "stampColor", "stampBaby",
		"freeMucusSensation", "freeMucusObservation", ...$other,
		...$temperature, "isPeak", "counterStart", "sexUnion", "booleanPregnancyDetected", "comments"];
}

function doc_cycle_to_csv($out, array $days, int $nfp_method): void {
	$columns = doc_csv_columns($days, $nfp_method);

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
function doc_raw_days($db, int $no_user_account): array {
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
		"freeMucusSensation", "freeMucusObservation", "freeOther" =>
			doc_csv_free_text(implode(DOC_CSV_LIST_JOINER, array_map('nfp_file_clean_text', $value))),
		"comments" => doc_csv_free_text(nfp_file_clean_text($value)),
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

// ===========================================================================
// PDF -- what both charts share
// ===========================================================================

function doc_cycle_to_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	if (account_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) {
		return doc_cycle_fc_to_pdf($days, $nfp_method, $name, $anonymous);
	}
	return doc_cycle_bill_to_pdf($days, $nfp_method, $name, $anonymous);
}

class DocPdf extends Fpdf {

	// $title and $author are UTF-8: the document properties are not limited to windows-1252.
	public function __construct(string $orientation, string $size, string $title, string $author) {
		parent::__construct($orientation, 'mm', $size);
		// Every row is measured before it is drawn, and the breaks are decided from that, so
		// FPDF must never break a page on its own -- in the middle of a MultiCell, say.
		$this->SetAutoPageBreak(false);
		$this->SetTitle($title, true);
		$this->SetAuthor($author, true);
		$this->SetCreator("moncycle.app", true);
	}

	public function UseStyle(string $style, ?array $color = null): void {
		[$family, $font_style, $size, $style_color] = DOC_PDF_STYLES[$style];
		$this->SetFont($family, $font_style, $size);
		$this->SetTextColor(...($color ?? $style_color));
	}

	// the padding Cell() leaves before left-aligned text
	public function GetCellMargin(): float {
		return $this->cMargin;
	}

	// Draws runs of differently styled text side by side, on the baseline Cell($w, $h, $txt)
	// would use at ($x, $y) in the first run's style, but with no cell margin. $runs is
	// [[style, text, space before it in mm], ...], the text already windows-1252.
	public function TextRuns(float $x, float $y, float $h, array $runs): void {
		$baseline = null;
		foreach ($runs as [$style, $text, $gap]) {
			$this->UseStyle($style);
			$baseline ??= $y + .5 * $h + .3 * $this->FontSize;
			$x += $gap;
			$this->Text($x, $baseline, $text);
			$x += $this->GetStringWidth($text);
		}
	}

	// $txt, cut short with an ellipsis if it is wider than Cell($w, ..., $txt) can hold, in the
	// current font.
	public function FitText(string $txt, float $w): string {
		$room = $w - 2 * $this->cMargin;
		if ($this->GetStringWidth($txt) <= $room) return $txt;

		$ellipsis = doc_pdf_text("…");
		while ($txt !== '' && $this->GetStringWidth($txt . $ellipsis) > $room) $txt = substr($txt, 0, -1);
		return rtrim($txt) . $ellipsis;
	}

	// How many lines MultiCell($w, $h, $txt) takes in the current font: MultiCell()'s own line
	// breaking, as in FPDF's "Table with MultiCells" script. It is what lets a row's height be
	// known before anything of it is drawn.
	public function NbLines(float $w, string $txt): int {
		$cw = $this->CurrentFont['cw'];
		$wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
		$s = str_replace("\r", '', $txt);
		$nb = strlen($s);
		if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
		$sep = -1;
		$i = 0;
		$j = 0;
		$l = 0;
		$nl = 1;
		while ($i < $nb) {
			$c = $s[$i];
			if ($c == "\n") {
				$i++;
				$sep = -1;
				$j = $i;
				$l = 0;
				$nl++;
				continue;
			}
			if ($c == ' ') $sep = $i;
			$l += $cw[$c];
			if ($l > $wmax) {
				if ($sep == -1) {
					if ($i == $j) $i++;
				}
				else $i = $sep + 1;
				$sep = -1;
				$j = $i;
				$l = 0;
				$nl++;
			}
			else $i++;
		}
		return $nl;
	}

	// $txt cut short with an ellipsis, if it needs more than $max lines in MultiCell($w, ...) in
	// the current font. Returns [the text to draw, the lines it takes].
	public function FitLines(float $w, string $txt, int $max): array {
		$lines = $this->NbLines($w, $txt);
		if ($lines <= $max) return [$txt, $lines];

		// the longest start of the text that still fits once the ellipsis is on it
		$ellipsis = doc_pdf_text("…");
		$low = 0;
		$high = strlen($txt);
		while ($low < $high) {
			$mid = intdiv($low + $high + 1, 2);
			if ($this->NbLines($w, rtrim(substr($txt, 0, $mid)) . $ellipsis) <= $max) $low = $mid;
			else $high = $mid - 1;
		}

		$txt = rtrim(substr($txt, 0, $low)) . $ellipsis;
		return [$txt, $this->NbLines($w, $txt)];
	}
}

// ===========================================================================
// BILLINGS PDF (nfp_method 1 and 2) -- a table, one line per day, A4 portrait
// ===========================================================================
//
// A day's line, left to right: date | day of the cycle | stamp | [peak] | [counter] | [union] |
// sensations | observations | [other] | comment, and method 1 ends with the temperature and its
// curve. The columns in brackets only exist when the export uses them.
//
// A line is as tall as its tallest text: sensations, observations, other and the comment wrap
// inside their column, and a text that would take more than DOC_BILL_MAX_LINES lines is cut
// short with an ellipsis. Every line is measured before anything is drawn, so that the pages
// are known up front: a cycle starts a new page, and one that does not fit carries on to the
// next, under the same column headers.
//
// PDF_BILLINGS_BORDERS (config file) turns every line of the table off at once. The layout
// (DOC_BILL_*), the text styles (DOC_PDF_STYLES) and the colours are in constants.php.

function doc_cycle_bill_to_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	$first_date = new DateTime($days[0]["date"]);
	$last_date = new DateTime(end($days)["date"]);
	if ($anonymous) $name = doc_get_initials($name) . " (anonyme)";

	$subtitle = sprintf("Tableau de %d %s", count($days), count($days) > 1 ? "jours" : "jour");
	if (!$anonymous) $subtitle .= sprintf(" du %s au %s", date_humain($first_date), date_humain($last_date));

	$pdf = new DocPdf('P', 'A4', 'MONCYCLE.APP tableau du ' . date_humain($first_date), $name);
	$pdf->SetMargins(DOC_PDF_MARGIN, DOC_PDF_MARGIN);

	$rows = doc_bill_rows($days);
	$layout = doc_bill_layout($pdf, $rows, account_tracks_temperature($nfp_method));
	doc_bill_fit_rows($pdf, $rows, $layout);
	$pages = doc_bill_paginate($pdf, $rows);

	foreach ($pages as $page_index => [$first, $count]) {
		$pdf->AddPage();
		doc_bill_draw_title($pdf, doc_pdf_text($name), $subtitle, $page_index + 1, count($pages));
		doc_bill_draw_head($pdf, $layout);

		$previous_point = null;   // the last point drawn on the temperature curve
		$y = DOC_BILL_TABLE_TOP + DOC_BILL_HEAD_H;
		for ($index = $first; $index < $first + $count; $index++) {
			doc_bill_draw_row($pdf, $rows[$index], $layout, $y, $anonymous, ($index - $first) % 2 === 1, $previous_point);
			$y += $rows[$index]["height"];
		}
		doc_bill_draw_edges($pdf, $layout, DOC_BILL_TABLE_TOP, $y);

		// the stamps of this page that need explaining
		$stamps = array_column(array_slice($rows, $first, $count), "stamp");
		$legend = [];
		if (in_array("unknown", $stamps, true)) $legend["unknown"] = "jour non observé ou non renseigné";
		if (in_array("pregnancy", $stamps, true)) $legend["pregnancy"] = "grossesse";
		doc_bill_draw_legend($pdf, $legend);
	}

	return $pdf;
}

// What each day shows. The peak (+1..+3 after it) and counter (+1..+n from its start) run over
// calendar days, gaps included, and start over at every cycle start. Texts are windows-1252.
function doc_bill_rows(array $days): array {
	$rows = [];
	$since_peak = null;    // days since this cycle's last peak
	$counter = null;       // [days counted so far, days to count]

	foreach ($days as $day) {
		$day = doc_display_day($day);

		if ($day["cycleFirstDay"]) {
			$since_peak = null;
			$counter = null;
		}

		$peak = null;
		if ($day["isPeak"]) {
			$since_peak = 0;
			$peak = ['bill.peak', chr(115)];     // a triangle in ZapfDingbats
		}
		elseif (!is_null($since_peak) && $since_peak < 3) $peak = ['bill.peak_offset', "+" . ++$since_peak];
		else $since_peak = null;

		if (!empty($day["counterStart"])) $counter = [0, $day["counterStart"]];
		$count = null;
		if (!is_null($counter) && $counter[0] < $counter[1]) $count = ['bill.counter', "+" . ++$counter[0]];
		else $counter = null;

		// A day that was not observed, and one that was never filled in, show the same stamp. The
		// stamp is cleared for the first by doc_display_day().
		$stamp = null;
		if ($day["booleanPregnancyDetected"]) $stamp = "pregnancy";
		elseif ($day["isGap"] || $day["mucusNotObserved"]) $stamp = "unknown";
		elseif (!is_null($day["stampColor"])) $stamp = $day["stampColor"];

		// each description list on one line, comma joined like the old free text
		$texts = [];
		foreach (["freeMucusSensation", "freeMucusObservation", "freeOther"] as $field) {
			$texts[$field] = doc_pdf_text(doc_one_line(implode(", ", $day[$field])));
		}
		$texts["comments"] = doc_pdf_text(doc_one_line($day["comments"]));

		$temperature_time = doc_time_hhmm($day["temperatureTime"]);

		$rows[] = [
			"day" => $day,
			"stamp" => $stamp,
			"baby" => $day["stampBaby"] && !is_null($day["stampColor"]),
			"peak" => $peak,
			"counter" => $count,
			"union" => $day["sexUnion"] ? ['bill.union', chr(164)] : null,   // a heart in ZapfDingbats
			"texts" => $texts,
			"temperature" => $day["temperature"],
			"temperature_label" => is_null($day["temperature"]) ? '' : doc_pdf_text(number_format($day["temperature"], 2, '.', '') . "°"),
			"temperature_time" => str_replace(':', 'h', $temperature_time),
		];
	}

	return $rows;
}

// The columns shared by every day of the export, computed once so that the days line up: which
// ones there are, their x and width, and the temperature scale. $temperature is whether the
// method tracks it.
//
// The comment takes the room the description columns leave. Those are as wide as their widest
// value, within DOC_BILL_TEXT_MIN_W and DOC_BILL_TEXT_MAX_W, and give way to keep the comment
// DOC_BILL_COMMENT_WANTED_W wide when they would take too much.
function doc_bill_layout(DocPdf $pdf, array $rows, bool $temperature): array {
	$margin = $pdf->GetCellMargin();
	$width = $pdf->GetPageWidth() - 2 * DOC_PDF_MARGIN;

	// method 1 draws the curve only when the export has a reading to put on it
	$readings = array_filter(array_column($rows, "temperature"), fn($t) => !is_null($t));
	$temperature = $temperature && !empty($readings);

	$columns = [];
	foreach (DOC_BILL_FIXED_COLUMNS as $key => [$label, $w]) {
		if (in_array($key, DOC_BILL_MARKERS, true) && empty(array_filter(array_column($rows, $key)))) continue;
		$columns[$key] = ["label" => $label, "w" => $w, "align" => 'C'];
	}

	$right_w = $temperature ? DOC_BILL_TEMPERATURE_W + DOC_BILL_CURVE_W : 0;
	$text_room = $width - array_sum(array_column($columns, "w")) - $right_w;

	$text_w = [];
	$text_min_w = [];
	foreach (DOC_BILL_TEXT_COLUMNS as $field => [$label, $style]) {
		if ($field === "comments") continue;

		// the width most values fit in on one line: a single long list does not get a column
		// wide enough for it, it wraps
		$pdf->UseStyle($style);
		$widths = [];
		foreach ($rows as $row) {
			if ($row["texts"][$field] !== '') $widths[] = $pdf->GetStringWidth($row["texts"][$field]);
		}
		// the legacy column only exists for the accounts that have such text
		if ($field === "freeOther" && empty($widths)) continue;
		sort($widths);
		$typical = empty($widths) ? 0.0 : $widths[intval(ceil(DOC_BILL_TEXT_TYPICAL * count($widths))) - 1];

		// and never narrower than its own header
		$pdf->UseStyle('bill.head');
		$text_min_w[$field] = max(DOC_BILL_TEXT_MIN_W, $pdf->GetStringWidth($label) + 2 * $margin);
		$text_w[$field] = min(DOC_BILL_TEXT_MAX_W, max($text_min_w[$field], $typical + 2 * $margin));
	}

	$budget = $text_room - DOC_BILL_COMMENT_WANTED_W;
	if (array_sum($text_w) > $budget) {
		$scale = $budget / array_sum($text_w);
		foreach ($text_w as $field => $w) $text_w[$field] = max($text_min_w[$field], $w * $scale);
	}
	$text_w["comments"] = $text_room - array_sum($text_w);

	foreach ($text_w as $field => $w) {
		$columns[$field] = ["label" => DOC_BILL_TEXT_COLUMNS[$field][0], "w" => $w, "align" => 'L'];
	}

	$layout = ["columns" => [], "temperature" => $temperature, "width" => $width];

	if ($temperature) {
		$columns["temperature"] = ["label" => "TEMP.", "w" => DOC_BILL_TEMPERATURE_W, "align" => 'L'];
		$columns["curve"] = ["label" => "", "w" => DOC_BILL_CURVE_W, "align" => 'L'];

		$layout["temperature_min"] = min($readings);
		$layout["temperature_max"] = max($readings);
		// one reading, or all the same: a scale around it
		if ($layout["temperature_max"] == $layout["temperature_min"]) {
			$layout["temperature_min"] -= 0.5;
			$layout["temperature_max"] += 0.5;
		}
	}

	$x = DOC_PDF_MARGIN;
	foreach ($columns as $key => $column) {
		$layout["columns"][$key] = $column + ["x" => $x];
		$x += $column["w"];
	}

	return $layout;
}

// Cuts each day's texts to what its column holds (the ones that are too long end with an
// ellipsis) and gives the day its height. Texts are measured in the style they are drawn in, on
// MultiCell()'s own line breaking. $rows is changed in place: a long export has thousands of
// days, and a copy of them is memory it needs.
function doc_bill_fit_rows(DocPdf $pdf, array &$rows, array $layout): void {
	foreach ($rows as &$row) {
		$lines = 1;
		foreach (DOC_BILL_TEXT_COLUMNS as $field => [$label, $style]) {
			if (!isset($layout["columns"][$field])) {
				// the legacy column, when no day has such text
				unset($row["texts"][$field]);
				continue;
			}
			$pdf->UseStyle($style);
			[$row["texts"][$field], $text_lines] = $pdf->FitLines($layout["columns"][$field]["w"], $row["texts"][$field], DOC_BILL_MAX_LINES);
			$lines = max($lines, $text_lines);
		}
		$row["height"] = max(DOC_BILL_MIN_ROW_H, 2 * DOC_BILL_PAD + $lines * DOC_BILL_LINE_H);
	}
}

// The pages, each as the [first day, number of days] it holds. A cycle starts a page, and so does
// a day that would run past the bottom of one. A single day always fits: its height is capped.
function doc_bill_paginate(DocPdf $pdf, array $rows): array {
	$limit = $pdf->GetPageHeight() - DOC_BILL_BOTTOM;
	$pages = [];
	$first = 0;
	$y = DOC_BILL_TABLE_TOP + DOC_BILL_HEAD_H;

	foreach ($rows as $index => $row) {
		if ($index > $first && ($row["day"]["cycleFirstDay"] || $y + $row["height"] > $limit)) {
			$pages[] = [$first, $index - $first];
			$first = $index;
			$y = DOC_BILL_TABLE_TOP + DOC_BILL_HEAD_H;
		}
		$y += $row["height"];
	}

	$pages[] = [$first, count($rows) - $first];
	return $pages;
}

// The name, the link to the app, what the chart covers and the page number. $name is windows-1252.
function doc_bill_draw_title(DocPdf $pdf, string $name, string $subtitle, int $page, int $page_count): void {
	$width = $pdf->GetPageWidth() - 2 * DOC_PDF_MARGIN;
	$side_w = 32;

	$pdf->SetXY(DOC_PDF_MARGIN, DOC_PDF_MARGIN);
	$pdf->UseStyle('bill.title');
	$pdf->Cell($width - $side_w, 6.5, $pdf->FitText($name, $width - $side_w), 0, 0, 'L');
	$pdf->UseStyle('bill.link');
	$pdf->Cell($side_w, 6.5, "MONCYCLE.APP", 0, 1, 'R', false, "https://www.moncycle.app");

	$pdf->UseStyle('bill.subtitle');
	$pdf->Cell($width - $side_w, 4, $pdf->FitText($subtitle, $width - $side_w), 0, 0, 'L');
	$pdf->Cell($side_w, 4, sprintf("page %d sur %d", $page, $page_count), 0, 1, 'R');
}

// The table's lines, when borders are on: a rule across the table at $y, which every band of cells
// (the header, each day) has under it and the first one over it...
function doc_bill_draw_rule(DocPdf $pdf, array $layout, float $y): void {
	if (!PDF_BILLINGS_BORDERS) return;

	$pdf->SetDrawColor(...DOC_BILL_BORDER_COLOR);
	$pdf->SetLineWidth(DOC_BILL_BORDER_W);
	$pdf->Line(DOC_PDF_MARGIN, $y, DOC_PDF_MARGIN + $layout["width"], $y);
}

// ... and the edges of the columns, drawn once for the whole page, from $top to $bottom.
function doc_bill_draw_edges(DocPdf $pdf, array $layout, float $top, float $bottom): void {
	if (!PDF_BILLINGS_BORDERS) return;

	$pdf->SetDrawColor(...DOC_BILL_BORDER_COLOR);
	$pdf->SetLineWidth(DOC_BILL_BORDER_W);
	foreach ($layout["columns"] as $column) $pdf->Line($column["x"], $top, $column["x"], $bottom);
	$pdf->Line(DOC_PDF_MARGIN + $layout["width"], $top, DOC_PDF_MARGIN + $layout["width"], $bottom);
}

// The column headers, on a light band. The curve's header gives its scale.
function doc_bill_draw_head(DocPdf $pdf, array $layout): void {
	$y = DOC_BILL_TABLE_TOP;

	$pdf->SetFillColor(...DOC_BILL_HEAD_FILL);
	$pdf->Rect(DOC_PDF_MARGIN, $y, $layout["width"], DOC_BILL_HEAD_H, 'F');

	foreach ($layout["columns"] as $column) {
		$pdf->UseStyle('bill.head');
		$pdf->SetXY($column["x"], $y);
		$pdf->Cell($column["w"], DOC_BILL_HEAD_H, doc_pdf_text($column["label"]), 0, 0, $column["align"]);
	}

	if ($layout["temperature"]) {
		$curve = $layout["columns"]["curve"];
		$min = doc_pdf_text(number_format($layout["temperature_min"], 2, '.', '') . "°");
		$max = doc_pdf_text(number_format($layout["temperature_max"], 2, '.', '') . "°");
		$pdf->UseStyle('bill.scale');
		$pdf->SetXY($curve["x"], $y);
		$pdf->Cell($curve["w"], DOC_BILL_HEAD_H, $min, 0, 0, 'L');
		$pdf->SetXY($curve["x"], $y);
		$pdf->Cell($curve["w"], DOC_BILL_HEAD_H, $max, 0, 0, 'R');
	}

	doc_bill_draw_rule($pdf, $layout, $y);
	doc_bill_draw_rule($pdf, $layout, $y + DOC_BILL_HEAD_H);
}

function doc_bill_draw_row(DocPdf $pdf, array $row, array $layout, float $y, bool $anonymous, bool $striped, ?array &$previous_point): void {
	$day = $row["day"];
	$columns = $layout["columns"];
	$h = $row["height"];
	$text_y = $y + DOC_BILL_PAD;
	$margin = $pdf->GetCellMargin();

	// without lines, every other day is shaded to keep the eye on its line
	if ($striped && !PDF_BILLINGS_BORDERS) {
		$pdf->SetFillColor(...DOC_BILL_STRIPE_FILL);
		$pdf->Rect(DOC_PDF_MARGIN, $y, $layout["width"], $h, 'F');
	}

	// the date and the day of the cycle, white on black on a cycle's first day
	$date = new DateTime($day["date"]);
	$week_day = intval($date->format('w'));
	$date_label = $anonymous ? DOC_WEEK_DAYS[$week_day] : date_humain_week_day($date, DOC_WEEK_DAYS);
	$date_style = $week_day === 0 ? 'bill.date.sunday' : 'bill.date';
	$text_color = null;
	if ($day["cycleFirstDay"]) {
		$pdf->SetFillColor(0, 0, 0);
		$pdf->Rect($columns["date"]["x"], $y, $columns["date"]["w"] + $columns["day"]["w"], $h, 'F');
		$text_color = [255, 255, 255];
	}
	$pdf->UseStyle($date_style, $text_color);
	$pdf->SetXY($columns["date"]["x"], $text_y);
	$pdf->Cell($columns["date"]["w"], DOC_BILL_LINE_H, $date_label, 0, 0, 'C');
	$pdf->UseStyle('bill.day_number', $text_color);
	$pdf->SetXY($columns["day"]["x"], $text_y);
	$pdf->Cell($columns["day"]["w"], DOC_BILL_LINE_H, (string) ($day["cycleDay"] ?? "?"), 0, 0, 'C');

	doc_bill_draw_stamp($pdf, $row["stamp"], $row["baby"],
		$columns["stamp"]["x"] + ($columns["stamp"]["w"] - DOC_BILL_STAMP_SIZE) / 2,
		$y + (DOC_BILL_MIN_ROW_H - DOC_BILL_STAMP_SIZE) / 2, DOC_BILL_STAMP_SIZE);

	foreach (DOC_BILL_MARKERS as $kind) {
		if (!isset($columns[$kind]) || is_null($row[$kind])) continue;
		[$style, $text] = $row[$kind];
		$pdf->UseStyle($style);
		$pdf->SetXY($columns[$kind]["x"], $text_y);
		$pdf->Cell($columns[$kind]["w"], DOC_BILL_LINE_H, $text, 0, 0, 'C');
	}

	foreach ($row["texts"] as $field => $text) {
		if ($text === '') continue;
		$pdf->UseStyle(DOC_BILL_TEXT_COLUMNS[$field][1]);
		$pdf->SetXY($columns[$field]["x"], $text_y);
		$pdf->MultiCell($columns[$field]["w"], DOC_BILL_LINE_H, $text, 0, 'L');
	}

	// method 1: the reading and the time it was taken, then its point on the curve, joined to
	// the one of the day before. A day without a reading breaks the curve.
	if ($layout["temperature"] && !is_null($row["temperature"])) {
		$runs = [['bill.temperature', $row["temperature_label"], 0]];
		if ($row["temperature_time"] !== '') $runs[] = ['bill.temperature_time', $row["temperature_time"], 1];
		$pdf->TextRuns($columns["temperature"]["x"] + $margin, $y + DOC_BILL_PAD, DOC_BILL_LINE_H, $runs);

		$curve = $columns["curve"];
		$scale = ($row["temperature"] - $layout["temperature_min"]) / ($layout["temperature_max"] - $layout["temperature_min"]);
		$point = [
			$curve["x"] + DOC_BILL_CURVE_PAD + $scale * ($curve["w"] - 2 * DOC_BILL_CURVE_PAD),
			$y + DOC_BILL_MIN_ROW_H / 2,
		];
		if (!is_null($previous_point)) {
			$pdf->SetDrawColor(...DOC_BILL_TEMPERATURE_COLOR);
			$pdf->SetLineWidth(0.25);
			$pdf->Line($previous_point[0], $previous_point[1], $point[0], $point[1]);
		}
		$pdf->SetFillColor(...DOC_BILL_TEMPERATURE_COLOR);
		$pdf->Rect($point[0] - 0.6, $point[1] - 0.6, 1.2, 1.2, 'F');
		$previous_point = $point;
	}
	else $previous_point = null;

	doc_bill_draw_rule($pdf, $layout, $y + $h);
}

// The stamp, a square: the colour of the day, with the baby on it when there is one. A day that
// was not observed or filled in is a light grey "?", a pregnancy a pink "G". A day with no stamp
// at all draws nothing.
function doc_bill_draw_stamp(DocPdf $pdf, ?string $kind, bool $baby, float $x, float $y, float $size): void {
	if (is_null($kind)) return;

	$glyph = '';
	if (isset(DOC_BILL_SPECIAL_STAMPS[$kind])) [$fill, $glyph, $glyph_color] = DOC_BILL_SPECIAL_STAMPS[$kind];
	else $fill = DOC_BILL_STAMP_COLORS[$kind];

	$pdf->SetFillColor(...$fill);
	$pdf->Rect($x, $y, $size, $size, 'F');
	// a baby on white would float without an outline
	if ($kind === "White" && PDF_BILLINGS_BORDERS) {
		$pdf->SetDrawColor(...DOC_BILL_BORDER_COLOR);
		$pdf->SetLineWidth(DOC_BILL_BORDER_W);
		$pdf->Rect($x, $y, $size, $size, 'D');
	}

	if ($glyph !== '') {
		$pdf->UseStyle('bill.stamp', $glyph_color);
		$pdf->SetFontSize($size * 1.45);
		$pdf->SetXY($x, $y);
		$pdf->Cell($size, $size, $glyph, 0, 0, 'C');
	}
	if ($baby) $pdf->Image(DOC_BABY_IMAGE, $x + 0.35, $y + 0.35, $size - 0.7, $size - 0.7);
}

// What the stamps that are not self-explanatory mean, along the bottom of the page.
function doc_bill_draw_legend(DocPdf $pdf, array $legend): void {
	if (empty($legend)) return;

	$size = 3.4;
	$y = $pdf->GetPageHeight() - DOC_PDF_MARGIN - $size;
	$x = DOC_PDF_MARGIN;
	foreach ($legend as $kind => $text) {
		doc_bill_draw_stamp($pdf, $kind, false, $x, $y, $size);
		$text = doc_pdf_text($text);
		$pdf->UseStyle('bill.legend');
		$pdf->SetXY($x + $size + 1, $y);
		$pdf->Cell($pdf->GetStringWidth($text) + 2 * $pdf->GetCellMargin(), $size, $text, 0, 0, 'L');
		$x += $size + 1 + $pdf->GetStringWidth($text) + 2 * $pdf->GetCellMargin() + 4;
	}
}

// ===========================================================================
// FERTILITYCARE PDF (nfp_method 3 and 4) -- a grid, A3 landscape
// ===========================================================================
//
// One row of the grid per cycle, 35 days wide; a longer cycle carries on in the next row. Each
// day is a column of cells: stamp, baby, peak, date, bleeding, mucus, other (arrow, pain codes,
// union), [temperature,] comment. The layout (DOC_FC_*) is in constants.php.

function doc_cycle_fc_to_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	$first_col_width = DOC_FC_FIRST_COL_W;
	$top_margin = DOC_PDF_MARGIN;
	$left_margin = DOC_PDF_MARGIN;
	$grid_gray = DOC_FC_GRID_GRAY;
	$with_temperature = account_tracks_temperature($nfp_method);
	$lines_per_page = $with_temperature ? DOC_FC_LINES_PER_PAGE_TEMPERATURE : DOC_FC_LINES_PER_PAGE;

	if ($anonymous) $name = doc_get_initials($name);

	$h_start_date = date_humain(new DateTime($days[0]["date"]));
	$h_end_date = date_humain(new DateTime(end($days)["date"]));
	$h_current_date = date_humain(new DateTime());

	$pdf = new DocPdf('L', 'A3', 'MONCYCLE.APP tableau du ' . $h_start_date, $anonymous ? "$name (anonyme)" : $name);
	$pdf->SetMargins($left_margin, $top_margin);

	$grid = [
		"cell_w" => ($pdf->GetPageWidth() - $first_col_width - 2 * $left_margin) / DOC_FC_DAYS_PER_ROW,
		"line_h" => DOC_FC_LINE_H,
		"stamp_h" => DOC_FC_STAMP_H,
		"color_coef" => DOC_FC_COLOR_COEF,
		"temperature" => $with_temperature,
		"anonymous" => $anonymous,
	];
	$line_h = $grid["line_h"];
	$content_h = $line_h * ($with_temperature ? 8 : 7) + $grid["stamp_h"];
	$separator_h = DOC_FC_SEPARATOR_H;
	$full_w = $pdf->GetPageWidth() - 2 * $left_margin;

	// the legend column: row => [label, height]
	$legend = [];
	foreach (DOC_FC_ROW_LABELS as $row => $label) {
		if ($row === "temperature" && !$with_temperature) continue;
		$legend[$row] = [$label, $row === "baby" ? $grid["stamp_h"] : $line_h];
	}

	// built once, then paginated: the page count cannot disagree with what is drawn
	$pages = array_chunk(doc_fc_grid_rows($days), $lines_per_page);
	$page_count = count($pages);

	foreach ($pages as $page_index => $page_rows) {
		$pdf->AddPage();
		$pdf->SetDrawColor($grid_gray, $grid_gray, $grid_gray);

		$page_title = $anonymous ? " (anonyme)" : " - observations du $h_start_date au $h_end_date";
		$page_title .= " - document créé le $h_current_date - page " . ($page_index + 1) . " sur $page_count - ";
		$page_title = doc_pdf_text($page_title);
		$pdf->UseStyle('fc.header.name');
		$pdf->Cell($pdf->GetStringWidth(doc_pdf_text($name)), $line_h, doc_pdf_text($name), 0, 0, 'L');
		$pdf->UseStyle('fc.header');
		$pdf->Cell($pdf->GetStringWidth($page_title), $line_h, $page_title, 0, 0, 'L');
		$pdf->UseStyle('fc.header.link');
		$pdf->Cell($pdf->GetStringWidth(" MONCYCLE.APP"), $line_h, "MONCYCLE.APP", 0, 0, 'L', false, "https://www.moncycle.app/");

		// the day numbers across the top
		$pdf->SetXY($left_margin, $top_margin + $line_h + 2);
		$pdf->UseStyle('fc.day_numbers');
		$pdf->SetFillColor(220, 220, 220);
		$pdf->Cell($first_col_width, $line_h, "", "LTR", 0, 'L');
		for ($j = 0; $j < DOC_FC_DAYS_PER_ROW; $j++) $pdf->Cell($grid["cell_w"], $line_h, $j + 1, "TR", 0, 'C', true);
		$y = $pdf->GetY() + $line_h;

		for ($line = 0; $line < $lines_per_page; $line++) {
			$pdf->SetFillColor($grid_gray, $grid_gray, $grid_gray);
			$pdf->SetDrawColor($grid_gray, $grid_gray, $grid_gray);
			$pdf->SetXY($left_margin, $y);
			$pdf->Cell($full_w, $separator_h, '', 'TLBR', 0, '', true);
			$y += $separator_h;

			// the legend column
			$pdf->UseStyle('fc.cell');
			$pdf->SetDrawColor(255 / $grid["color_coef"], 255 / $grid["color_coef"], 255 / $grid["color_coef"]);
			$legend_y = $y;
			foreach ($legend as $row => [$text, $h]) {
				$pdf->SetXY($left_margin, $legend_y);
				$pdf->Cell($first_col_width, $h, $text, $row === "comment" ? "" : "B", 0, 'R');
				$legend_y += $h;
			}

			$x = $left_margin + $first_col_width;
			for ($j = 0; $j < DOC_FC_DAYS_PER_ROW; $j++) {
				doc_fc_draw_cell($pdf, $page_rows[$line][$j] ?? null, $grid, $x + $grid["cell_w"] * $j, $y);
			}

			// the vertical lines between the cells
			$pdf->SetDrawColor($grid_gray, $grid_gray, $grid_gray);
			$pdf->Line($left_margin, $y, $left_margin, $y + $content_h);
			for ($j = 0; $j <= DOC_FC_DAYS_PER_ROW; $j++) {
				$pdf->Line($x + $grid["cell_w"] * $j, $y, $x + $grid["cell_w"] * $j, $y + $content_h);
			}
			$y += $content_h;
		}

		$pdf->SetFillColor($grid_gray, $grid_gray, $grid_gray);
		$pdf->SetXY($left_margin, $y);
		$pdf->Cell($full_w, $separator_h, '', 'TLBR', 0, '', true);
	}

	return $pdf;
}

// The rows of the grid: a new row at every cycle start, and when a row is full. Each cell is
// ["day" => display day, "peak" => text]. The peak count runs to the end of its cycle, so on
// into the next row when a long cycle wraps, and gaps are counted as the calendar days they are.
function doc_fc_grid_rows(array $days): array {
	$rows = [];
	$row = [];
	$since_peak = null;

	foreach ($days as $day) {
		$day = doc_display_day($day);

		if (!empty($row) && ($day["cycleFirstDay"] || count($row) >= DOC_FC_DAYS_PER_ROW)) {
			$rows[] = $row;
			$row = [];
		}
		if ($day["cycleFirstDay"]) $since_peak = null;

		$peak = '';
		if ($day["isPeak"]) {
			$since_peak = 0;
			$peak = "PIC";
		}
		elseif (!is_null($since_peak)) $peak = (string) ++$since_peak;

		$row[] = ["day" => $day, "peak" => $peak];
	}

	if (!empty($row)) $rows[] = $row;
	return $rows;
}

function doc_fc_stamp_key(?array $day): string {
	if (is_null($day)) return "";          // padding after a cycle's last day
	// not "G": that is the green stamp since stamps took their English codes (d75cf3e), which
	// had silently turned every pregnancy cell into a green "V"
	if ($day["booleanPregnancyDetected"]) return "Gi";
	if ($day["isGap"] || $day["mucusNotObserved"]) return "?";
	return day_format_stamp_encode($day["stampColor"], $day["stampBaby"]);
}

// One day's column of cells, or an empty one when $cell is null (padding).
function doc_fc_draw_cell(DocPdf $pdf, ?array $cell, array $grid, float $x, float $y): void {
	$day = $cell["day"] ?? null;
	$w = $grid["cell_w"];
	$line_h = $grid["line_h"];
	$stamp_h = $grid["stamp_h"];
	$coef = $grid["color_coef"];
	$pregnancy = !empty($day["booleanPregnancyDetected"]);
	$text_color = $pregnancy ? DOC_PREGNANCY_COLOR : [0, 0, 0];

	// the stamp, the baby and the peak, in the stamp's colour
	[$symbol, $r, $g, $b] = DOC_FC_STAMPS[doc_fc_stamp_key($day)];
	$pdf->SetDrawColor($r / $coef, $g / $coef, $b / $coef);
	$pdf->SetFillColor($r, $g, $b);
	$pdf->UseStyle('fc.cell', $text_color);
	$pdf->SetXY($x, $y);
	$pdf->Cell($w, $line_h, $symbol, 'B', 0, 'C', true);

	$pdf->UseStyle('fc.baby', $text_color);
	$pdf->SetXY($x, $y + $line_h);
	$pdf->Cell($w, $stamp_h, $pregnancy ? "GROSSESSE" : "", 'B', 0, 'C', true);
	$image_size = min($w, $stamp_h) - 2;
	if (str_contains($symbol, 'BB')) $pdf->Image(DOC_BABY_IMAGE, $x + $image_size / 2, $y + $line_h + 1, $image_size, $image_size);

	$pdf->UseStyle('fc.cell', $text_color);
	$pdf->SetXY($x, $y + $line_h + $stamp_h);
	$pdf->Cell($w, $line_h, $cell["peak"] ?? '', 'B', 0, 'C', true);

	$pdf->SetDrawColor(255 / $coef, 255 / $coef, 255 / $coef);
	$row_y = fn(int $index) => $y + $line_h * $index + $stamp_h;

	// the date
	$date_text = '';
	$date_style = 'fc.cell';
	if (!is_null($day)) {
		$date = new DateTime($day["date"]);
		$week_day = intval($date->format('w'));
		$date_text = $grid["anonymous"] ? DOC_WEEK_DAYS[$week_day] : date_humain_week_day($date, DOC_WEEK_DAYS);
		if ($week_day === 0) $date_style = 'fc.cell.sunday';
	}
	$pdf->UseStyle($date_style, $text_color);
	$pdf->SetXY($x, $row_y(2));
	$pdf->Cell($w, $line_h, $date_text, 'B', 0, 'C');

	// bleeding, then mucus: codifiedMucusSensation, codifiedMucusObservation and the recurrence
	// count, "X" written "*"
	$pdf->UseStyle('fc.cell', $text_color);
	$pdf->SetXY($x, $row_y(3));
	$pdf->Cell($w, $line_h, $day["codifiedBleedingObservation"] ?? '', 'B', 0, 'C');
	$mucus = ($day["codifiedMucusSensation"] ?? '') . ($day["codifiedMucusObservation"] ?? '')
		. str_ireplace('X', '*', $day["codifiedNumberObservations"] ?? '');
	$pdf->SetXY($x, $row_y(4));
	$pdf->Cell($w, $line_h, $mucus, 'B', 0, 'C');

	// other info: the arrow, the pain codes and the union ("G")
	$pain = $day["codifiedPainObservations"] ?? '';
	$other = $pain . ($pain !== '' ? ' ' : '') . (!empty($day["sexUnion"]) ? "G" : '');
	$arrow = DOC_FC_ARROWS[$day["codifiedArrow"] ?? ''] ?? '';
	$pdf->SetXY($x, $row_y(5));
	if ($other !== '' && $arrow !== '') {
		$pdf->UseStyle('fc.arrow', $text_color);
		$pdf->Cell($w / 4, $line_h, $arrow, 'B', 0, 'R');
		$pdf->UseStyle('fc.cell', $text_color);
		$pdf->Cell($w / 4 * 3, $line_h, $other, 'B', 0, 'L');
	}
	elseif ($arrow !== '') {
		$pdf->UseStyle('fc.arrow', $text_color);
		$pdf->Cell($w, $line_h, $arrow, 'B', 0, 'C');
	}
	else $pdf->Cell($w, $line_h, $other, 'B', 0, 'C');

	if ($grid["temperature"]) {
		$temperature = '';
		if (!is_null($day["temperature"] ?? null)) {
			$temperature = number_format($day["temperature"], 2, '.', '');
			$time = doc_time_hhmm($day["temperatureTime"]);
			if ($time !== '') $temperature .= doc_pdf_text(" à ") . $time;
		}
		$pdf->UseStyle('fc.temperature', $text_color);
		$pdf->SetXY($x, $row_y(6));
		$pdf->Cell($w, $line_h, $temperature, 'B', 0, 'C');
	}

	if (!is_null($day)) {
		$comment = doc_pdf_text(doc_one_line($day["comments"]));
		doc_fc_draw_comment($pdf, $comment, $x, $row_y($grid["temperature"] ? 7 : 6), $w, $line_h, $text_color);
	}
}

// A comment in a cell a few millimetres wide, as large as it fits: one line at 6pt, one line at
// 3pt, three lines at 3pt, and past that five lines at 3pt, cut short with an ellipsis.
function doc_fc_draw_comment(DocPdf $pdf, string $comment, float $x, float $y, float $w, float $h, array $color): void {
	if ($comment === '') return;
	$room = $w - 2 * $pdf->GetCellMargin();
	$pdf->SetXY($x, $y);

	$pdf->UseStyle('fc.cell', $color);
	if ($pdf->GetStringWidth($comment) <= $room) {
		$pdf->Cell($w, $h, $comment, 0, 0, 'L');
		return;
	}

	$pdf->UseStyle('fc.comment.small', $color);
	if ($pdf->GetStringWidth($comment) <= $room) $pdf->Cell($w, $h, $comment, 0, 0, 'L');
	elseif ($pdf->NbLines($w, $comment) <= 3) $pdf->MultiCell($w, $h / 3, $comment, 0, 'L');
	else {
		$ellipsis = doc_pdf_text("…");
		while (strlen($comment) > 1 && $pdf->NbLines($w, $comment . $ellipsis) > 5) $comment = substr($comment, 0, -1);
		$pdf->MultiCell($w, $h / 5, rtrim($comment) . $ellipsis, 0, 'L');
	}
}
