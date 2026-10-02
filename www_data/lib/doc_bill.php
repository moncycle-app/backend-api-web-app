<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** The Billings chart (nfp_method 1 and 2): a table, one line per day, A4 portrait. Sizes are in mm.
**
** A day's line, left to right: date | day of the cycle | stamp | [peak] | [counter] | [union] |
** sensations | observations | [other] | comment, and method 1 ends with the temperature and its
** curve. The columns in brackets only exist when the export uses them.
**
** A line is as tall as its tallest text: sensations, observations, other and the comment wrap
** inside their column, and a text that would take more than DOC_BILL_MAX_LINES lines is cut
** short with an ellipsis. Every line is measured before anything is drawn, so that the pages
** are known up front: a cycle starts a new page, and one that does not fit carries on to the
** next, under the same column headers.
**
** PDF_BILLINGS_BORDERS (config file) turns every line of the table off at once. The layout
** (DOC_BILL_*), the text styles (DOC_PDF_STYLES) and the colours are in constants.php.
*/

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/doc.php";
require_once __DIR__ . "/doc_pdf.php";

function doc_bill_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	$first_date = new DateTime($days[0]["date"]);
	$last_date = new DateTime(end($days)["date"]);
	if ($anonymous) $name = doc_get_initials($name) . " (anonyme)";

	$subtitle = sprintf("Tableau de %d %s", count($days), count($days) > 1 ? "jours" : "jour");
	if (!$anonymous) $subtitle .= sprintf(" du %s au %s", date_human($first_date), date_human($last_date));

	$pdf = new DocPdf('P', 'A4', 'MONCYCLE.APP tableau du ' . date_human($first_date), $name);
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
	$date_label = $anonymous ? DOC_WEEK_DAYS[$week_day] : date_human_week_day($date, DOC_WEEK_DAYS);
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
