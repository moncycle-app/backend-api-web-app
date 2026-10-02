<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** The FertilityCare chart (nfp_method 3 and 4): a grid, A3 landscape. Sizes are in mm.
**
** One row of the grid per cycle, 35 days wide; a longer cycle carries on in the next row. Each
** day is a column of cells: stamp, baby, peak, date, bleeding, mucus, other (arrow, pain codes,
** union), [temperature,] comment. The layout (DOC_FC_*) is in constants.php.
*/

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/doc.php";
require_once __DIR__ . "/doc_pdf.php";

function doc_fc_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	$with_temperature = account_tracks_temperature($nfp_method);
	if ($anonymous) $name = doc_get_initials($name);

	$h_start_date = date_human(new DateTime($days[0]["date"]));
	$h_end_date = date_human(new DateTime(end($days)["date"]));
	$h_current_date = date_human(new DateTime());

	$pdf = new DocPdf('L', 'A3', 'MONCYCLE.APP tableau du ' . $h_start_date, $anonymous ? "$name (anonyme)" : $name);
	$pdf->SetMargins(DOC_PDF_MARGIN, DOC_PDF_MARGIN);
	$grid = doc_fc_layout($pdf, $with_temperature, $anonymous);

	// built once, then paginated: the page count cannot disagree with what is drawn
	$lines_per_page = $with_temperature ? DOC_FC_LINES_PER_PAGE_TEMPERATURE : DOC_FC_LINES_PER_PAGE;
	$pages = array_chunk(doc_fc_grid_rows($days), $lines_per_page);

	foreach ($pages as $page_index => $page_rows) {
		$page_title = $anonymous ? " (anonyme)" : " - observations du $h_start_date au $h_end_date";
		$page_title .= " - document créé le $h_current_date - page " . ($page_index + 1) . " sur " . count($pages) . " - ";

		$pdf->AddPage();
		$pdf->SetDrawColor($grid["gray"], $grid["gray"], $grid["gray"]);
		doc_fc_draw_title($pdf, $name, doc_pdf_text($page_title), $grid);

		$y = doc_fc_draw_day_numbers($pdf, $grid);
		for ($line = 0; $line < $lines_per_page; $line++) {
			$y = doc_fc_draw_row($pdf, $page_rows[$line] ?? [], $grid, $y);
		}
		doc_fc_draw_bar($pdf, $grid, $y, false);
	}

	return $pdf;
}

// The measures of the grid, all in one place: the width of a day, the height of a line of text, of
// the stamp cell and of one row of the grid, the margins, the grey of the lines, and the legend
// column (row => [label, height]).
function doc_fc_layout(DocPdf $pdf, bool $with_temperature, bool $anonymous): array {
	$legend = [];
	foreach (DOC_FC_ROW_LABELS as $row => $label) {
		if ($row === "temperature" && !$with_temperature) continue;
		$legend[$row] = [$label, $row === "baby" ? DOC_FC_STAMP_H : DOC_FC_LINE_H];
	}

	return [
		"cell_w" => ($pdf->GetPageWidth() - DOC_FC_FIRST_COL_W - 2 * DOC_PDF_MARGIN) / DOC_FC_DAYS_PER_ROW,
		"line_h" => DOC_FC_LINE_H,
		"stamp_h" => DOC_FC_STAMP_H,
		"color_coef" => DOC_FC_COLOR_COEF,
		"temperature" => $with_temperature,
		"anonymous" => $anonymous,
		"left" => DOC_PDF_MARGIN,
		"top" => DOC_PDF_MARGIN,
		"first_col_w" => DOC_FC_FIRST_COL_W,
		"gray" => DOC_FC_GRID_GRAY,
		"full_w" => $pdf->GetPageWidth() - 2 * DOC_PDF_MARGIN,
		"separator_h" => DOC_FC_SEPARATOR_H,
		"row_h" => DOC_FC_LINE_H * ($with_temperature ? 8 : 7) + DOC_FC_STAMP_H,
		"legend" => $legend,
	];
}

// the line at the top of a page: the name, what the page holds, and the link to the site
function doc_fc_draw_title(DocPdf $pdf, string $name, string $page_title, array $grid): void {
	$pdf->UseStyle('fc.header.name');
	$pdf->Cell($pdf->GetStringWidth(doc_pdf_text($name)), $grid["line_h"], doc_pdf_text($name), 0, 0, 'L');
	$pdf->UseStyle('fc.header');
	$pdf->Cell($pdf->GetStringWidth($page_title), $grid["line_h"], $page_title, 0, 0, 'L');
	$pdf->UseStyle('fc.header.link');
	$pdf->Cell($pdf->GetStringWidth(" MONCYCLE.APP"), $grid["line_h"], "MONCYCLE.APP", 0, 0, 'L', false, "https://www.moncycle.app/");
}

// the day numbers across the top; returns where the first row of the grid starts
function doc_fc_draw_day_numbers(DocPdf $pdf, array $grid): float {
	$pdf->SetXY($grid["left"], $grid["top"] + $grid["line_h"] + 2);
	$pdf->UseStyle('fc.day_numbers');
	$pdf->SetFillColor(220, 220, 220);
	$pdf->Cell($grid["first_col_w"], $grid["line_h"], "", "LTR", 0, 'L');
	for ($day = 1; $day <= DOC_FC_DAYS_PER_ROW; $day++) $pdf->Cell($grid["cell_w"], $grid["line_h"], $day, "TR", 0, 'C', true);
	return $pdf->GetY() + $grid["line_h"];
}

// the bar between two rows of the grid (which also sets the colour of the lines), and under the last
function doc_fc_draw_bar(DocPdf $pdf, array $grid, float $y, bool $set_line_color = true): void {
	$pdf->SetFillColor($grid["gray"], $grid["gray"], $grid["gray"]);
	if ($set_line_color) $pdf->SetDrawColor($grid["gray"], $grid["gray"], $grid["gray"]);
	$pdf->SetXY($grid["left"], $y);
	$pdf->Cell($grid["full_w"], $grid["separator_h"], '', 'TLBR', 0, '', true);
}

// One row of the grid: the bar above it, the legend column, the cells of its days, and the vertical
// lines between them. $cells are the days of the row (see doc_fc_grid_rows()), none for an empty
// row. Returns the y under the row.
function doc_fc_draw_row(DocPdf $pdf, array $cells, array $grid, float $y): float {
	doc_fc_draw_bar($pdf, $grid, $y);
	$y += $grid["separator_h"];

	// the legend column
	$pdf->UseStyle('fc.cell');
	$pdf->SetDrawColor(255 / $grid["color_coef"], 255 / $grid["color_coef"], 255 / $grid["color_coef"]);
	$legend_y = $y;
	foreach ($grid["legend"] as $row => [$text, $height]) {
		$pdf->SetXY($grid["left"], $legend_y);
		$pdf->Cell($grid["first_col_w"], $height, $text, $row === "comment" ? "" : "B", 0, 'R');
		$legend_y += $height;
	}

	$x = $grid["left"] + $grid["first_col_w"];
	for ($day = 0; $day < DOC_FC_DAYS_PER_ROW; $day++) {
		doc_fc_draw_cell($pdf, $cells[$day] ?? null, $grid, $x + $grid["cell_w"] * $day, $y);
	}

	// the vertical lines between the cells
	$pdf->SetDrawColor($grid["gray"], $grid["gray"], $grid["gray"]);
	$pdf->Line($grid["left"], $y, $grid["left"], $y + $grid["row_h"]);
	for ($day = 0; $day <= DOC_FC_DAYS_PER_ROW; $day++) {
		$pdf->Line($x + $grid["cell_w"] * $day, $y, $x + $grid["cell_w"] * $day, $y + $grid["row_h"]);
	}
	return $y + $grid["row_h"];
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
		$date_text = $grid["anonymous"] ? DOC_WEEK_DAYS[$week_day] : date_human_week_day($date, DOC_WEEK_DAYS);
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
