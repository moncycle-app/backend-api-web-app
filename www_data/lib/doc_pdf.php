<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// What the two PDF charts are drawn with: DocPdf, an FPDF with the helpers both charts use, and the
// conversion of text to what FPDF's core fonts can show. The PDFs only use those fonts: they are
// not embedded, which keeps the files tiny, but they only cover windows-1252, so every string drawn
// goes through doc_pdf_text() first.

use Fpdf\Fpdf;

// DocPdf extends Fpdf as soon as this file loads, so the autoloader has to be there first
require_once __DIR__ . "/../vendor/autoload.php";
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
