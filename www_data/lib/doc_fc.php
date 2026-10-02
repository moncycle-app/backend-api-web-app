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
	$first_col_width = DOC_FC_FIRST_COL_W;
	$top_margin = DOC_PDF_MARGIN;
	$left_margin = DOC_PDF_MARGIN;
	$grid_gray = DOC_FC_GRID_GRAY;
	$with_temperature = account_tracks_temperature($nfp_method);
	$lines_per_page = $with_temperature ? DOC_FC_LINES_PER_PAGE_TEMPERATURE : DOC_FC_LINES_PER_PAGE;

	if ($anonymous) $name = doc_get_initials($name);

	$h_start_date = date_human(new DateTime($days[0]["date"]));
	$h_end_date = date_human(new DateTime(end($days)["date"]));
	$h_current_date = date_human(new DateTime());

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
