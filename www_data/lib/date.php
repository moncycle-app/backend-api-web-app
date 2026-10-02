<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

function date_sql($date) {
	return date_format($date, 'Y-m-d');
}

function date_humain($date, $sep='/') {
	return date_format($date, 'd' . $sep . 'm' . $sep . 'y');
}

function date_humain_week_day($date, $week_days, $sep='/') {
	return $week_days[intval(date_format($date, 'w'))] . date_format($date, ' d' . $sep . 'm');
}

// "YYYY-MM-DD" (spaces around are allowed) -> the date itself, or null when it is not a real calendar date.
function date_parse_ymd(mixed $value): ?string {
	if (!is_string($value) || !preg_match('/^\s*(\d{4})-(\d{2})-(\d{2})\s*$/', $value, $m)) return null;
	return checkdate(intval($m[2]), intval($m[3]), intval($m[1])) ? trim($value) : null;
}

function date_validate_timestamp($timestamp) {
    $pattern = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    if (!preg_match($pattern, $timestamp)) return false;
    $dateParts = explode(' ', $timestamp);
    [$year, $month, $day] = explode('-', $dateParts[0]);
    [$hour, $minute, $second] = explode(':', $dateParts[1]);
	if (!checkdate((int)$month, (int)$day, (int)$year)) return false;
	if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) return false;
	return true;
}

