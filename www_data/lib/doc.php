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
** One pipeline feeds all three: doc_export_days() (lib/doc_export.php) reads the period once and
** returns one entry per calendar day, in the structured day shape of lib/day_format.php (NFP
** field names), gaps included. That data is faithful: nothing is hidden or rewritten there. What
** a file shows of a day is the renderers' business, and the display rule they share lives here.
**
**   doc.php           what the renderers share (this file)
**   doc_export.php    the front door: reading the period, and choosing the chart of a method
**   doc_csv.php       the CSV, and the raw data export
**   doc_pdf.php       the PDF base: DocPdf, text for the core fonts
**   doc_bill.php      the Billings chart      doc_fc.php   the FertilityCare chart
**
** The NFP export (lib/nfp_export.php) is a separate pipeline on purpose: it widens the period to
** whole cycles and writes every stored value, because it is a data file, not a chart.
*/

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

// $day as every export shows it: on a mucusNotObserved day, the mucus fields emptied; and the
// descriptions with no type yet (freeOther, the free text the v15 migration could not class) moved
// to the end of the comment, which they follow and never replace. They have no column or cell of
// their own, so freeOther is empty in what comes out. A day that was not observed shows none.
function doc_display_day(array $day): array {
	if (!empty($day["mucusNotObserved"])) {
		foreach ($day as $field => $value) {
			if (!doc_is_mucus_field($field)) continue;
			$day[$field] = match (true) {
				is_array($value) => [],
				is_bool($value) => false,
				default => null,
			};
		}
	}

	$other = implode(", ", $day["freeOther"] ?? []);
	if ($other !== '') {
		$comment = (string) ($day["comments"] ?? '');
		$day["comments"] = $comment === '' ? $other : $comment . DOC_COMMENT_OTHER_JOINER . $other;
	}
	$day["freeOther"] = [];
	return $day;
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
