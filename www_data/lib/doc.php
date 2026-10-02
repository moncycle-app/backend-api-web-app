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
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/nfp_format.php";
require_once __DIR__ . "/nfp_file.php";

const DOC_WEEK_DAYS = ["D", "L", "M", "M", "J", "V", "S"];
const DOC_BABY_IMAGE = __DIR__ . "/../img/baby.png";

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
		if (intval($description["type"]) === 0) $day["freeOther"][] = $description["name"];
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
const DOC_MUCUS_FIELDS = [
	...NFP_MUCUS_NOT_OBSERVED_INCOMPATIBLE,
	"codifiedNumberObservations",
	"stampColor",
	"stampBaby",
	"freeOther",
];

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

const DOC_CSV_LIST_JOINER = " | ";   // the same as api/all_of_my_data_plz.php

// Named after the NFP fields, or after the API's day where NFP has none (date, cycleDay,
// cycleFirstDay).
function doc_csv_columns(array $days, int $nfp_method): array {
	$temperature = nfp_file_method_tracks_temperature($nfp_method) ? ["temperature", "temperatureTime"] : [];

	if (nfp_file_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) {
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
	if (nfp_file_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) {
		return doc_cycle_fc_to_pdf($days, $nfp_method, $name, $anonymous);
	}
	return doc_cycle_bill_to_pdf($days, $nfp_method, $name, $anonymous);
}

class DocPdf extends Fpdf {

	// The text styles the charts draw with: [font family, style, size in pt, [r, g, b]].
	const STYLES = [
		'bill.title' => ['Courier', 'B', 12, [0, 0, 0]],
		'bill.subtitle' => ['Courier', '', 10, [0, 0, 0]],
		'bill.link' => ['Courier', '', 10, [30, 130, 76]],
		'bill.date' => ['Courier', '', 6, [200, 200, 200]],
		'bill.date.sunday' => ['Courier', 'B', 6, [145, 145, 145]],
		'bill.day_number' => ['Courier', '', 8, [0, 0, 0]],
		'bill.not_observed' => ['Arial', 'I', 8, [100, 100, 100]],
		'bill.pregnancy' => ['Courier', '', 12, [130, 21, 33]],
		'bill.peak' => ['ZapfDingbats', '', 10, [139, 69, 19]],
		'bill.peak_offset' => ['Courier', '', 10, [139, 69, 19]],
		'bill.counter' => ['Courier', '', 10, [30, 130, 76]],
		'bill.union' => ['ZapfDingbats', '', 10, [172, 36, 51]],
		'bill.freeMucusSensation' => ['Arial', '', 8.5, [0, 0, 0]],
		'bill.freeMucusObservation' => ['Arial', '', 8.5, [90, 90, 90]],
		'bill.freeOther' => ['Arial', 'I', 8.5, [90, 90, 90]],
		'bill.temperature' => ['Courier', '', 9, [135, 67, 176]],
		'bill.comment' => ['Arial', 'I', 7, [0, 0, 0]],
		'fc.header' => ['Courier', '', 10, [0, 0, 0]],
		'fc.header.name' => ['Courier', 'B', 10, [0, 0, 0]],
		'fc.header.link' => ['Courier', 'B', 10, [30, 130, 76]],
		'fc.day_numbers' => ['Courier', '', 8, [0, 0, 0]],
		'fc.cell' => ['Courier', '', 6, [0, 0, 0]],
		'fc.cell.sunday' => ['Courier', 'B', 6, [0, 0, 0]],
		'fc.baby' => ['Courier', '', 5, [0, 0, 0]],
		'fc.arrow' => ['Symbol', '', 6, [0, 0, 0]],
		'fc.temperature' => ['Courier', '', 3.8, [0, 0, 0]],
		'fc.comment.small' => ['Courier', '', 3, [0, 0, 0]],
	];

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
		[$family, $font_style, $size, $style_color] = self::STYLES[$style];
		$this->SetFont($family, $font_style, $size);
		$this->SetTextColor(...($color ?? $style_color));
	}

	// the padding Cell() leaves before left-aligned text
	public function GetCellMargin(): float {
		return $this->cMargin;
	}

	public function GetLeftMargin(): float {
		return $this->lMargin;
	}

	// Draws $txt on the baseline Cell($w, $h, $txt) would use at ($x, $y), but with no cell
	// margin, so runs of differently styled text can sit side by side on one line.
	public function TextInLine(float $x, float $y, float $h, string $txt): void {
		$this->Text($x, $y + .5 * $h + .3 * $this->FontSize, $txt);
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

	// Lays runs of differently styled text out in lines at most $w wide. $runs is
	// [[style, text], ...], the text already windows-1252. Breaks at spaces, and inside a word
	// only when the word alone is wider than a line. Returns the lines, each a list of
	// [style, text] runs to draw one after the other.
	public function FlowLines(array $runs, float $w): array {
		$lines = [];
		$line = [];
		$line_w = 0.0;

		foreach ($runs as [$style, $text]) {
			$this->UseStyle($style);
			$space_w = $this->GetStringWidth(' ');

			foreach (explode(' ', $text) as $word) {
				if ($word === '') continue;
				$word_w = $this->GetStringWidth($word);

				if (!empty($line) && $line_w + $space_w + $word_w > $w) {
					$lines[] = $line;
					$line = [];
					$line_w = 0.0;
				}

				// a word wider than a whole line is cut where it overflows
				while (empty($line) && $word_w > $w && strlen($word) > 1) {
					$cut = 0;
					$cut_w = 0.0;
					while ($cut < strlen($word) && $cut_w + $this->GetStringWidth($word[$cut]) <= $w) {
						$cut_w += $this->GetStringWidth($word[$cut]);
						$cut++;
					}
					$cut = max(1, $cut);
					$lines[] = [[$style, substr($word, 0, $cut)]];
					$word = substr($word, $cut);
					$word_w = $this->GetStringWidth($word);
				}

				$piece = empty($line) ? $word : ' ' . $word;
				$line_w += (empty($line) ? 0.0 : $space_w) + $word_w;
				$last = count($line) - 1;
				if ($last >= 0 && $line[$last][0] === $style) $line[$last][1] .= $piece;
				else $line[] = [$style, $piece];
			}
		}

		if (!empty($line)) $lines[] = $line;
		return $lines;
	}

	public function FlowLineWidth(array $line): float {
		$w = 0.0;
		foreach ($line as [$style, $text]) {
			$this->UseStyle($style);
			$w += $this->GetStringWidth($text);
		}
		return $w;
	}
}

// ===========================================================================
// BILLINGS PDF (nfp_method 1 and 2) -- one line per day, A4 portrait
// ===========================================================================
//
// A day's line, left to right: date | day of the cycle | stamp | marker slot (peak, counter,
// union) | freeMucusSensation | freeMucusObservation | [freeOther] | comment. Method 1 adds the
// temperature, right aligned on the middle of the page, and its point on the curve beyond.
// Method 2 has no curve, and fits two columns of days on a page instead. A new cycle always
// starts a new column.
//
// All of the geometry is relative to the left edge of the column of days.

const DOC_BILL_LINE_H = 5;             // a day's line
const DOC_BILL_ROW_GAP = 0.5;          // the white space under every day, between two stamps
const DOC_BILL_FLOW_LINE_H = 3.5;      // a line of descriptions laid out as flowing text
const DOC_BILL_COMMENT_LINE_H = 3;     // a comment line wrapped under the day's line
const DOC_BILL_BOTTOM_MARGIN = 20;     // FPDF's own default page break margin
const DOC_BILL_DATE_W = 11;
const DOC_BILL_DAY_NUMBER_W = 8;
const DOC_BILL_STAMP_X = 19.5;         // half a millimetre after the day of the cycle
const DOC_BILL_STAMP_W = 5;
const DOC_BILL_MIN_COMMENT_W = 15;     // what the description columns always leave to a comment
const DOC_BILL_CURVE_W = 70;
const DOC_BILL_TEMPERATURE_MIN_W = 12; // the temperature area, left of the middle of the page

// the narrowest each part of the marker slot gets, when the export uses it at all
const DOC_BILL_MARKER_MIN_W = ["peak" => 5, "counter" => 5, "union" => 4];

// The description columns, in order: day field => [fallback prefix, style]. The prefixes end in
// a windows-1252 no-break space, so a wrapped line never leaves one apart from its value.
const DOC_BILL_DESCRIPTION_COLUMNS = [
	"freeMucusSensation" => ["S:\xA0", 'bill.freeMucusSensation'],
	"freeMucusObservation" => ["O:\xA0", 'bill.freeMucusObservation'],
	"freeOther" => ["A:\xA0", 'bill.freeOther'],
];

function doc_cycle_bill_to_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	$first_date = new DateTime($days[0]["date"]);
	$last_date = new DateTime(end($days)["date"]);
	if ($anonymous) $name = doc_get_initials($name) . " (anonyme)";

	$pdf = new DocPdf('P', 'A4', 'MONCYCLE.APP tableau du ' . date_humain($first_date), $name);
	$pdf->AddPage();

	$title_w = $pdf->GetPageWidth() - 35;
	$pdf->UseStyle('bill.title');
	$pdf->Cell($title_w, 10, doc_pdf_text($name), 0, 1, 'C');
	$pdf->UseStyle('bill.subtitle');
	$subtitle = sprintf("Tableau de %d jours", count($days));
	if (!$anonymous) $subtitle .= sprintf(" du %s au %s", date_humain($first_date), date_humain($last_date));
	$pdf->Cell($title_w, 5, $subtitle, 0, 1, 'C');
	$pdf->UseStyle('bill.link');
	$pdf->Link($pdf->GetX(), $pdf->GetY(), $pdf->GetPageWidth() - 25, 6, "https://www.moncycle.app");
	$pdf->Cell($title_w, 5, "MONCYCLE.APP", 0, 1, 'C');
	$pdf->Ln(5);

	$rows = doc_bill_rows($days);
	$layout = doc_bill_layout($pdf, $rows, $nfp_method, $anonymous);

	$top_y = $pdf->GetY();
	$x0 = $pdf->GetLeftMargin();
	$y = $top_y;
	$second_column = false;
	$previous_point = null;   // the last point drawn on the temperature curve

	foreach ($rows as $row) {
		$fit = doc_bill_fit_row($pdf, $row, $layout);

		$overflows = $y + $fit["height"] - DOC_BILL_ROW_GAP > $pdf->GetPageHeight() - DOC_BILL_BOTTOM_MARGIN;
		if ($y > $top_y && ($row["day"]["cycleFirstDay"] || $overflows)) {
			$previous_point = null;
			// without a temperature curve, the right half of the page takes a second column of days
			if (!$layout["temperature"] && !$second_column) {
				$second_column = true;
				$x0 = $pdf->GetPageWidth() / 2;
			}
			else {
				$pdf->AddPage();
				$second_column = false;
				$x0 = $pdf->GetLeftMargin();
				$top_y = $pdf->GetY();
			}
			$y = $top_y;
		}

		doc_bill_draw_row($pdf, $row, $fit, $layout, $x0, $y, $previous_point);
		$y += $fit["height"];
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

		// a label stands in for the stamp and the description columns
		$label = null;
		if ($day["booleanPregnancyDetected"]) $label = "pregnancy";
		elseif ($day["isGap"] || $day["mucusNotObserved"]) $label = "not_observed";

		// each description list on one line, comma joined like the old free text
		$descriptions = [];
		foreach (array_keys(DOC_BILL_DESCRIPTION_COLUMNS) as $field) {
			$descriptions[$field] = doc_pdf_text(doc_one_line(implode(", ", $day[$field])));
		}

		$temperature_label = '';
		if (!is_null($day["temperature"])) {
			$temperature_label = $day["temperature"] . "°";
			$time = doc_time_hhmm($day["temperatureTime"]);
			if ($time !== '') $temperature_label .= " à " . str_replace(':', 'h', $time);
		}

		$rows[] = [
			"day" => $day,
			"label" => $label,
			"markers" => [
				"peak" => $peak,
				"counter" => $count,
				"union" => $day["sexUnion"] ? ['bill.union', chr(164)] : null,   // a heart in ZapfDingbats
			],
			"descriptions" => $descriptions,
			"temperature" => $day["temperature"],
			"temperature_label" => doc_pdf_text($temperature_label),
			"comment" => doc_pdf_text(doc_one_line($day["comments"])),
		];
	}

	return $rows;
}

// The geometry shared by every day of the export, computed once so that the days line up: the
// marker slot, the width of each description column, and the temperature scale.
function doc_bill_layout(DocPdf $pdf, array $rows, int $nfp_method, bool $anonymous): array {
	$margin = $pdf->GetCellMargin();
	$half = $pdf->GetPageWidth() / 2 - $pdf->GetLeftMargin();
	$temperature = nfp_file_method_tracks_temperature($nfp_method);

	$layout = [
		"anonymous" => $anonymous,
		"temperature" => $temperature,
		// a comment that does not fit on its line wraps under it, this wide
		"wrap_w" => $pdf->GetPageWidth() / 2 - 11,
		// where a comment has to stop: the middle of the page for a column of method 2, the
		// right margin for method 1 (that day's temperature permitting, see doc_bill_fit_row())
		"right" => $temperature ? $pdf->GetPageWidth() - 2 * $pdf->GetLeftMargin() : $half,
		// what the descriptions can never cross
		"columns_end" => $half,
	];

	// only the markers this export uses get room in the slot, each part as wide as its widest
	$layout["slot"] = ["peak" => 0.0, "counter" => 0.0, "union" => 0.0];
	foreach ($rows as $row) {
		foreach ($row["markers"] as $kind => $marker) {
			if (is_null($marker)) continue;
			$pdf->UseStyle($marker[0]);
			$layout["slot"][$kind] = max($layout["slot"][$kind], DOC_BILL_MARKER_MIN_W[$kind], $pdf->GetStringWidth($marker[1]) + $margin);
		}
	}
	$layout["slot_w"] = array_sum($layout["slot"]);
	$layout["columns_x"] = DOC_BILL_STAMP_X + DOC_BILL_STAMP_W + $layout["slot_w"];

	// The room for the columns, keeping DOC_BILL_MIN_COMMENT_W for a comment. Method 1 stops
	// before the temperature area, which starts DOC_BILL_TEMPERATURE_MIN_W left of the middle of
	// the page -- further left when a reading's label is wider than that (a time makes it so),
	// as the columns must never run into one. The comment room can overlap the temperature area:
	// a comment stops before that day's reading anyway.
	$columns_room = $half - DOC_BILL_MIN_COMMENT_W;
	if ($temperature) {
		$pdf->UseStyle('bill.temperature');
		$widest = DOC_BILL_TEMPERATURE_MIN_W;
		$readings = [];
		foreach ($rows as $row) {
			if (is_null($row["temperature"])) continue;
			$widest = max($widest, $pdf->GetStringWidth($row["temperature_label"]) + $margin);
			$readings[] = $row["temperature"];
		}
		$layout["columns_end"] = $half - $widest;
		$columns_room = min($layout["columns_end"], $half - DOC_BILL_TEMPERATURE_MIN_W - DOC_BILL_MIN_COMMENT_W);

		$layout["temperature_min"] = empty($readings) ? 36 : min($readings);
		$layout["temperature_max"] = empty($readings) ? 38 : max($readings);
		if ($layout["temperature_max"] == $layout["temperature_min"]) {
			$layout["temperature_min"] = 36;
			$layout["temperature_max"] = 38;
		}
	}

	// what each day with a stamp needs per column -- a label day never draws the columns
	$needs = [];
	foreach ($rows as $row) {
		if (!is_null($row["label"])) continue;
		$need = [];
		foreach (DOC_BILL_DESCRIPTION_COLUMNS as $column => [$prefix, $style]) {
			$pdf->UseStyle($style);
			$text = $row["descriptions"][$column];
			$need[$column] = $text === '' ? 0.0 : $pdf->GetStringWidth($text) + 2 * $margin;
		}
		$needs[] = $need;
	}
	$layout["column_w"] = doc_bill_column_widths($needs, max(0.0, $columns_room - $layout["columns_x"]));
	$layout["comment_x"] = $layout["columns_x"] + array_sum($layout["column_w"]);

	return $layout;
}

// The widths of the description columns, from what each day needs in each of them.
//
// When every column can be as wide as its widest value, that is it. When they cannot all fit,
// the widths are the ones that keep the most days in the aligned columns, the narrowest such
// set winning a tie; the other days fall back to flowing text. A column no day uses -- or that
// only days which fall back anyway would use -- takes no room at all.
function doc_bill_column_widths(array $needs, float $available): array {
	$columns = array_keys(DOC_BILL_DESCRIPTION_COLUMNS);
	$widest = array_fill_keys($columns, 0.0);
	foreach ($needs as $need) {
		foreach ($columns as $column) $widest[$column] = max($widest[$column], $need[$column]);
	}
	if (array_sum($widest) <= $available) return $widest;

	// the days, grouped by what they need
	$shapes = [];
	foreach ($needs as $need) {
		$key = implode('|', array_map(fn($w) => round($w, 2), $need));
		$shapes[$key] ??= ["need" => $need, "days" => 0];
		$shapes[$key]["days"]++;
	}

	// Every combination of candidate widths -- the needs actually met in each column -- for all
	// the used columns but the last, which takes what is left. Each column's candidates are
	// thinned out to a few dozen, so a long export cannot make this explode.
	$used = array_values(array_filter($columns, fn($column) => $widest[$column] > 0));
	$last = array_pop($used);
	$combinations = [[]];
	foreach ($used as $column) {
		$candidates = array_values(array_unique(array_merge([0.0], array_column(array_column($shapes, "need"), $column))));
		sort($candidates);
		if (count($candidates) > 40) {
			$candidates = array_map(fn($i) => $candidates[intval(round($i * (count($candidates) - 1) / 39))], range(0, 39));
		}
		$next = [];
		foreach ($combinations as $combination) {
			foreach ($candidates as $w) {
				if (array_sum($combination) + $w <= $available) $next[] = $combination + [$column => $w];
			}
		}
		$combinations = $next;
	}

	$best = ["days" => -1, "total" => INF, "widths" => array_fill_keys($columns, 0.0)];
	foreach ($combinations as $combination) {
		$limits = $combination + [$last => $available - array_sum($combination)];
		$days = 0;
		$widths = array_fill_keys($columns, 0.0);
		foreach ($shapes as $shape) {
			foreach ($limits as $column => $limit) {
				if ($shape["need"][$column] > $limit) continue 2;
			}
			$days += $shape["days"];
			foreach ($limits as $column => $limit) $widths[$column] = max($widths[$column], $shape["need"][$column]);
		}
		$total = array_sum($widths);
		if ($days > $best["days"] || ($days === $best["days"] && $total < $best["total"])) {
			$best = ["days" => $days, "total" => $total, "widths" => $widths];
		}
	}
	return $best["widths"];
}

// [text, style, x, cell width] of the label that stands in for the stamp and the columns
function doc_bill_label(DocPdf $pdf, string $label): array {
	if ($label === "pregnancy") {
		$pdf->UseStyle('bill.pregnancy');
		return ["GROSSESSE", 'bill.pregnancy', DOC_BILL_STAMP_X - 0.5, $pdf->GetStringWidth("GROSSESSE") + 5];
	}
	$pdf->UseStyle('bill.not_observed');
	$text = doc_pdf_text("jour non observé ");
	return [$text, 'bill.not_observed', DOC_BILL_STAMP_X, $pdf->GetStringWidth($text) + 0.25];
}

// Where everything of one day goes and how tall that makes it, worked out before anything is
// drawn so that the page and column breaks can be decided up front.
//
// The descriptions go in the export's aligned columns when each value fits its column. When
// one does not, that day alone lays them out as prefixed flowing text instead ("S: ... O: ...
// A: ..."), wrapping onto more lines if it has to. A comment goes on the day's line when it
// fits there -- lined up with the other comments after the columns if it can, right after the
// day's own content otherwise -- and under it when it does not.
function doc_bill_fit_row(DocPdf $pdf, array $row, array $layout): array {
	$margin = $pdf->GetCellMargin();
	$fit = ["label" => null, "columns" => false, "flow" => [], "comment" => null];

	if (is_null($row["label"])) $fit["slot_x"] = DOC_BILL_STAMP_X + DOC_BILL_STAMP_W;
	else {
		$fit["label"] = doc_bill_label($pdf, $row["label"]);
		$fit["slot_x"] = $fit["label"][2] + $fit["label"][3];
	}
	$fit["content_x"] = $fit["slot_x"] + $layout["slot_w"];

	// where the day's own content ends on its line, so far: the stamp or the label, then the
	// markers it has
	$content_end = $fit["slot_x"];
	$x = $fit["slot_x"];
	foreach ($layout["slot"] as $kind => $w) {
		$x += $w;
		if (!is_null($row["markers"][$kind])) $content_end = $x;
	}

	$descriptions = array_filter($row["descriptions"], fn($text) => $text !== '');
	if (!empty($descriptions)) {
		$fit["columns"] = is_null($row["label"]);
		foreach ($descriptions as $column => $text) {
			$pdf->UseStyle(DOC_BILL_DESCRIPTION_COLUMNS[$column][1]);
			if ($pdf->GetStringWidth($text) + 2 * $margin > $layout["column_w"][$column]) $fit["columns"] = false;
		}

		if ($fit["columns"]) {
			$x = $layout["columns_x"];
			foreach ($layout["column_w"] as $column => $w) {
				$x += $w;
				if (isset($descriptions[$column])) $content_end = $x;
			}
		}
		else {
			$runs = [];
			foreach ($descriptions as $column => $text) {
				[$prefix, $style] = DOC_BILL_DESCRIPTION_COLUMNS[$column];
				$runs[] = [$style, $prefix . $text];
			}
			$fit["flow"] = $pdf->FlowLines($runs, max(1.0, $layout["columns_end"] - $fit["content_x"] - 2 * $margin));
			// once they wrap, the descriptions take the whole line
			$content_end = count($fit["flow"]) > 1 ? null : $fit["content_x"] + 2 * $margin + $pdf->FlowLineWidth($fit["flow"][0]);
		}
	}

	$bottom = DOC_BILL_LINE_H;
	if (count($fit["flow"]) > 1) $bottom = doc_bill_flow_line_y(count($fit["flow"]));

	if ($row["comment"] !== '') {
		$comment_end = $layout["right"];
		if ($layout["temperature"] && !is_null($row["temperature"])) {
			$pdf->UseStyle('bill.temperature');
			$comment_end = $pdf->GetPageWidth() / 2 - $pdf->GetLeftMargin() - $pdf->GetStringWidth($row["temperature_label"]) - $margin;
		}

		$starts = [];
		if (!is_null($content_end)) {
			if (is_null($row["label"]) && $layout["comment_x"] >= $content_end) $starts[] = $layout["comment_x"];
			$starts[] = $content_end;
		}

		$pdf->UseStyle('bill.comment');
		// the cell's own margin before the text, and as much air after it
		$comment_w = $pdf->GetStringWidth($row["comment"]) + 2 * $margin;
		foreach ($starts as $start) {
			if ($comment_w <= $comment_end - $start) {
				$fit["comment"] = "inline";
				$fit["comment_x"] = $start;
				break;
			}
		}

		if (is_null($fit["comment"])) {
			$fit["comment"] = "wrapped";
			$fit["comment_y"] = $bottom + DOC_BILL_ROW_GAP;
			$bottom = $fit["comment_y"] + DOC_BILL_COMMENT_LINE_H * $pdf->NbLines($layout["wrap_w"], $row["comment"]);
		}
	}

	$fit["height"] = $bottom + DOC_BILL_ROW_GAP;
	return $fit;
}

// The top of flowing description line $index: the first one is centred on the day's line.
function doc_bill_flow_line_y(int $index): float {
	return (DOC_BILL_LINE_H - DOC_BILL_FLOW_LINE_H) / 2 + $index * DOC_BILL_FLOW_LINE_H;
}

function doc_bill_draw_row(DocPdf $pdf, array $row, array $fit, array $layout, float $x0, float $y, ?array &$previous_point): void {
	$day = $row["day"];
	$margin = $pdf->GetCellMargin();
	$date = new DateTime($day["date"]);
	$week_day = intval($date->format('w'));
	$date_label = $layout["anonymous"] ? DOC_WEEK_DAYS[$week_day] : date_humain_week_day($date, DOC_WEEK_DAYS);
	$date_style = $week_day === 0 ? 'bill.date.sunday' : 'bill.date';

	// the date and the day of the cycle, white on black on a cycle's first day
	$pdf->SetXY($x0, $y);
	if ($day["cycleFirstDay"]) {
		$pdf->SetFillColor(0, 0, 0);
		$pdf->SetDrawColor(0, 0, 0);
		$pdf->UseStyle($date_style, [255, 255, 255]);
		$pdf->Cell(DOC_BILL_DATE_W, DOC_BILL_LINE_H, $date_label, 1, 0, 'R', true);
		$pdf->UseStyle('bill.day_number', [255, 255, 255]);
		$pdf->Cell(DOC_BILL_DAY_NUMBER_W, DOC_BILL_LINE_H, "1erJ", 1, 0, 'C', true);
	}
	else {
		$pdf->UseStyle($date_style);
		$pdf->Cell(DOC_BILL_DATE_W, DOC_BILL_LINE_H, $date_label, 0, 0, 'R');
		$pdf->UseStyle('bill.day_number');
		$pdf->Cell(DOC_BILL_DAY_NUMBER_W, DOC_BILL_LINE_H, (string) ($day["cycleDay"] ?? "?"), 0, 0, 'C');
	}

	if (is_null($fit["label"])) doc_bill_draw_stamp($pdf, $day, $x0 + DOC_BILL_STAMP_X, $y);
	else {
		[$text, $style, $x, $w] = $fit["label"];
		$pdf->UseStyle($style);
		$pdf->SetXY($x0 + $x, $y);
		if ($row["label"] === "pregnancy") {
			$pdf->SetFillColor(255, 236, 238);
			$pdf->SetDrawColor(255, 236, 238);
			$pdf->Cell($w, DOC_BILL_LINE_H, $text, 1, 0, 'C', true);
		}
		else $pdf->Cell($w, DOC_BILL_LINE_H, $text);
	}

	$x = $x0 + $fit["slot_x"];
	foreach ($layout["slot"] as $kind => $w) {
		$marker = $row["markers"][$kind];
		if (!is_null($marker)) {
			$pdf->UseStyle($marker[0]);
			$pdf->SetXY($x, $y);
			$pdf->Cell($w, DOC_BILL_LINE_H, $marker[1]);
		}
		$x += $w;
	}

	if ($fit["columns"]) {
		$x = $x0 + $layout["columns_x"];
		foreach ($layout["column_w"] as $column => $w) {
			$text = $row["descriptions"][$column];
			if ($text !== '') {
				$pdf->UseStyle(DOC_BILL_DESCRIPTION_COLUMNS[$column][1]);
				$pdf->SetXY($x, $y);
				$pdf->Cell($w, DOC_BILL_LINE_H, $text);
			}
			$x += $w;
		}
	}
	foreach ($fit["flow"] as $index => $line) {
		$x = $x0 + $fit["content_x"] + $margin;
		foreach ($line as [$style, $text]) {
			$pdf->UseStyle($style);
			$pdf->TextInLine($x, $y + doc_bill_flow_line_y($index), DOC_BILL_FLOW_LINE_H, $text);
			$x += $pdf->GetStringWidth($text);
		}
	}

	// method 1: the reading right aligned on the middle of the page, then the curve -- a grey
	// baseline, the reading's point, and the segment from the day before's
	if ($layout["temperature"] && !is_null($row["temperature"])) {
		$middle = $pdf->GetPageWidth() / 2;
		$pdf->UseStyle('bill.temperature');
		$w = $pdf->GetStringWidth($row["temperature_label"]);
		$pdf->SetXY($middle - $w, $y);
		$pdf->Cell($w, DOC_BILL_LINE_H, $row["temperature_label"], 0, 0, 'R');

		$pdf->SetDrawColor(200, 200, 200);
		$pdf->Line($middle, $y + 2.5, $middle + DOC_BILL_CURVE_W, $y + 2.5);
		$scale = ($row["temperature"] - $layout["temperature_min"]) / ($layout["temperature_max"] - $layout["temperature_min"]);
		$point_x = $middle + $scale * DOC_BILL_CURVE_W;
		$pdf->SetFillColor(135, 67, 176);
		$pdf->Rect($point_x, $y + 2, 1, 1, 'F');
		if (!is_null($previous_point)) {
			$pdf->SetDrawColor(135, 67, 176);
			$pdf->Line($previous_point[0], $previous_point[1], $point_x + 0.5, $y + 2.5);
		}
		$previous_point = [$point_x + 0.5, $y + 2.5];
	}
	else $previous_point = null;

	if ($fit["comment"] === "inline") {
		$pdf->UseStyle('bill.comment');
		$pdf->SetXY($x0 + $fit["comment_x"], $y + DOC_BILL_ROW_GAP);
		$pdf->Cell($pdf->GetStringWidth($row["comment"]) + $margin, DOC_BILL_LINE_H, $row["comment"]);
	}
	elseif ($fit["comment"] === "wrapped") {
		$pdf->UseStyle('bill.comment');
		$pdf->SetXY($x0, $y + $fit["comment_y"]);
		$pdf->MultiCell($layout["wrap_w"], DOC_BILL_COMMENT_LINE_H, $row["comment"]);
	}
}

// The stamp: a coloured square, with the baby on it when there is one.
function doc_bill_draw_stamp(DocPdf $pdf, array $day, float $x, float $y): void {
	$color = match ($day["stampColor"]) {
		"Red" => [172, 36, 51],
		"Green" => [30, 130, 76],
		"Yellow" => [251, 202, 11],
		default => [255, 255, 255],
	};
	$pdf->SetFillColor(...$color);
	// a baby on white gets a grey outline, or it would float
	if ($day["stampColor"] === "White") $pdf->SetDrawColor(220, 220, 220);
	else $pdf->SetDrawColor(...$color);

	$pdf->SetXY($x, $y);
	$pdf->Cell(DOC_BILL_STAMP_W, DOC_BILL_LINE_H, '', 1, 0, 'C', true);
	if ($day["stampBaby"]) $pdf->Image(DOC_BABY_IMAGE, $x + 0.25, $y + 0.25, 4.5, 4.5);
}

// ===========================================================================
// FERTILITYCARE PDF (nfp_method 3 and 4) -- a grid, A3 landscape
// ===========================================================================
//
// One row of the grid per cycle, 35 days wide; a longer cycle carries on in the next row. Each
// day is a column of cells: stamp, baby, peak, date, bleeding, mucus, other (arrow, pain codes,
// union), [temperature,] comment.

const DOC_FC_DAYS_PER_ROW = 35;

// stamp => [text, r, g, b]
const DOC_FC_STAMPS = [
	"" => ["", 255, 255, 255],
	"?" => ["???", 210, 210, 210],
	"R" => ['R', 190, 0, 4],
	"G" => ['V', 45, 102, 23],
	"Y" => ['J', 255, 255, 9],
	"BB" => ['BBB', 255, 255, 255],
	'RBB' => ['BBR', 190, 0, 0],
	'GBB' => ['BBV', 130, 187, 106],
	'YBB' => ['BBJ', 255, 255, 9],
	'Gi' => ['G', 255, 236, 238], // pregnancy ("Grossesse") -- TODO: another letter, G reads like Green
];

// codifiedArrow => its glyph in the Symbol font
const DOC_FC_ARROWS = ["Up" => "\xAD", "Down" => "\xAF", "Right" => "\xAE"];

function doc_cycle_fc_to_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	$first_col_width = 16;
	$top_margin = 10;
	$left_margin = 10;
	$grid_gray = 60;
	$with_temperature = nfp_file_method_tracks_temperature($nfp_method);
	$lines_per_page = $with_temperature ? 7 : 8;

	if ($anonymous) $name = doc_get_initials($name);

	$h_start_date = date_humain(new DateTime($days[0]["date"]));
	$h_end_date = date_humain(new DateTime(end($days)["date"]));
	$h_current_date = date_humain(new DateTime());

	$pdf = new DocPdf('L', 'A3', 'MONCYCLE.APP tableau du ' . $h_start_date, $anonymous ? "$name (anonyme)" : $name);
	$pdf->SetMargins($left_margin, $top_margin);

	$grid = [
		"cell_w" => ($pdf->GetPageWidth() - $first_col_width - 2 * $left_margin) / DOC_FC_DAYS_PER_ROW,
		"line_h" => 3.5,
		"stamp_h" => 7.3,
		"color_coef" => 1.1,
		"temperature" => $with_temperature,
		"anonymous" => $anonymous,
	];
	$line_h = $grid["line_h"];
	$content_h = $line_h * ($with_temperature ? 8 : 7) + $grid["stamp_h"];
	$separator_h = 0.25;
	$full_w = $pdf->GetPageWidth() - 2 * $left_margin;
	$legend = ["TAMPON" => $line_h, "" => $grid["stamp_h"], "PIC" => $line_h, "DATE" => $line_h,
		"SAIGNEMENT" => $line_h, "GLAIRE" => $line_h, "AUTRE INFO" => $line_h];
	if ($with_temperature) $legend["TEMPERATURE"] = $line_h;
	$legend["COMMENTAIRE"] = $line_h;

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
			foreach ($legend as $text => $h) {
				$pdf->SetXY($left_margin, $legend_y);
				$pdf->Cell($first_col_width, $h, $text, $text === "COMMENTAIRE" ? "" : "B", 0, 'R');
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
	$text_color = $pregnancy ? [130, 21, 33] : [0, 0, 0];

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
