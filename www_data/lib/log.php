<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The app's own log: what happened, to which account, to which row, from where -- never the
// content. The app holds health data, so a line says "account 21 wrote day 4412", not what the day
// holds; what is never logged is in README.md ("Logs"), and log_scrub() is how a message that may
// carry an address or an SQL fragment is made fit for it.
//
// One line per event: a flat JSON object (or "key=value" text), to stdout, stderr or a file
// (LOG_OUTPUT). The events are LOG_EVENTS (constants.php), the keys their glossary in the README.
//
// Logging never breaks a request: nothing here throws, writes to the response or queries the
// database. An unwritable file, an unencodable value or a bad setting is swallowed.
//
// A web request is started by log_start() (api_start() does it), which also writes its `http.request`
// line when the request ends. log_context() holds what every line of the request shares (the
// account and the session), log_note() what only the request's own line carries.

// The state of the running request or run. Returned by reference so that the callers and the
// shutdown functions share it (a PHP static does not outlive a request under mod_php).
function &log_state(): array {
	static $state = [
		"rid" => null, "ip" => null, "started" => null, "context" => [], "note" => [],
		"settings" => null, "sink" => null, "handle" => null, "why" => "", "cron" => null,
	];
	return $state;
}

// The LOG_* settings, each one checked: a missing one (a config.php written before the logs) or a
// value that is not allowed is the default, never "off".
function log_settings(): array {
	$state = &log_state();
	if ($state["settings"] !== null) return $state["settings"];

	$read = fn(string $name) => defined($name) ? constant($name) : LOG_DEFAULTS[$name];
	$choice = fn(string $name, array $allowed) => in_array($read($name), $allowed, true) ? $read($name) : LOG_DEFAULTS[$name];
	$text = fn(string $name) => is_string($read($name)) ? trim($read($name)) : LOG_DEFAULTS[$name];
	$categories = $read("LOG_CATEGORIES");

	return $state["settings"] = [
		"level" => $choice("LOG_LEVEL", LOG_LEVEL_VALUES),
		"categories" => is_array($categories) ? array_values(array_intersect($categories, LOG_CATEGORY_VALUES)) : [],
		"output" => $text("LOG_OUTPUT"),
		"format" => $choice("LOG_FORMAT", LOG_FORMAT_VALUES),
		"ip" => $choice("LOG_IP", LOG_IP_VALUES),
		"header" => $text("LOG_REQUEST_ID_HEADER"),
	];
}

// ---------------------------------------------------------------------------
// A web request
// ---------------------------------------------------------------------------

// Starts the log of a web request, first thing: gives it its id (sent back as X-Request-Id) and
// registers the `http.request` line. $client_ip is sec_client_ip(); it is passed in so that this
// file knows nothing of sec.php. Safe to call twice.
function log_start(string $client_ip = ""): void {
	try {
		$state = &log_state();
		if ($state["started"] !== null) return;
		$state["started"] = microtime(true);
		$state["ip"] = log_ip($client_ip);
		if (!headers_sent()) header("X-Request-Id: " . log_rid());
		register_shutdown_function('log_request_end');
	} catch (\Throwable $e) {
	}
}

// The `http.request` line: method, path (never the query string), status, duration, and what the
// endpoint noted (the API error code, the number of rows a read returned).
function log_request_end(): void {
	try {
		$state = &log_state();
		$path = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH);
		$fields = [
			"m" => log_scrub((string) ($_SERVER["REQUEST_METHOD"] ?? "")),
			"p" => is_string($path) ? log_scrub($path) : null,
			"st" => http_response_code() ?: null,
			"ms" => (int) round((microtime(true) - $state["started"]) * 1000),
		];
		log_event("http.request", $fields + array_merge(["err" => null, "n" => null, "full" => null], $state["note"]));
	} catch (\Throwable $e) {
	}
}

// What the lines of the request share. Only "uid" (the account) and "sid" (the session, never the
// token) are read; a null value removes the key.
function log_context(array $fields): void {
	$state = &log_state();
	foreach ($fields as $key => $value) {
		if ($value === null) unset($state["context"][$key]);
		else $state["context"][$key] = $value;
	}
}

// Fields for the request's own `http.request` line, and for no other (err, n, full).
function log_note(array $fields): void {
	$state = &log_state();
	$state["note"] = array_merge($state["note"], $fields);
}

// The id of the request: the one the client or the proxy sent in the header LOG_REQUEST_ID_HEADER
// when it is plain (it is client-controlled, so it only serves correlation), else a new one.
function log_rid(): string {
	$state = &log_state();
	if ($state["rid"] !== null) return $state["rid"];

	$header = log_settings()["header"];
	$sent = PHP_SAPI !== "cli" && $header !== "" ? ($_SERVER["HTTP_" . strtoupper(str_replace("-", "_", $header))] ?? "") : "";
	if (is_string($sent) && preg_match(LOG_REQUEST_ID_PATTERN, $sent)) return $state["rid"] = $sent;

	try {
		return $state["rid"] = bin2hex(random_bytes(6));
	} catch (\Throwable $e) {
		return $state["rid"] = substr(sha1(uniqid("", true)), 0, 12);
	}
}

// The client address as LOG_IP allows it: whole, cut to its network (a.b.c.0, or the first 3 groups
// of an IPv6 address), or not at all. Null when there is none to write.
function log_ip(string $ip): ?string {
	$mode = log_settings()["ip"];
	if ($ip === "" || $mode === "none") return null;
	if ($mode === "full") return mb_substr($ip, 0, 45);

	$packed = @inet_pton($ip);
	if ($packed === false) return null;
	if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) $packed = substr($packed, 12); // ::ffff:a.b.c.d
	if (strlen($packed) === 4) return implode(".", array_map('ord', str_split(substr($packed, 0, 3)))) . ".0";

	$groups = str_split(bin2hex(substr($packed, 0, 6)), 4);
	return implode(":", array_map(fn($group) => ltrim($group, "0") ?: "0", $groups)) . "::";
}

// ---------------------------------------------------------------------------
// Writing an event
// ---------------------------------------------------------------------------

// Logs an event of LOG_EVENTS. $fields are its own keys (see the README glossary); a "uid" or "sid"
// among them wins over the request's. $level overrides the event's, for the one whose outcome
// decides it. Nothing is written when the level or the category is not kept.
function log_event(string $event, array $fields = [], ?string $level = null): void {
	try {
		$level ??= LOG_EVENTS[$event] ?? "info";
		$settings = log_settings();
		$rank = array_search($level, LOG_LEVEL_VALUES, true);
		if ($rank === false || $rank === 0 || $rank > array_search($settings["level"], LOG_LEVEL_VALUES, true)) return;
		if ($settings["categories"] && !in_array(strstr($event, ".", true), $settings["categories"], true)) return;

		$state = &log_state();
		$uid = $fields["uid"] ?? $state["context"]["uid"] ?? null;
		$sid = $fields["sid"] ?? $state["context"]["sid"] ?? null;
		$common = [
			"ts" => log_timestamp(), "lv" => $level, "ev" => $event, "rid" => log_rid(),
			"src" => PHP_SAPI === "cli" ? "cli" : null,
			"uid" => is_numeric($uid) ? intval($uid) : null,
			"sid" => is_numeric($sid) ? intval($sid) : null,
			"ip" => $state["ip"],
		];
		unset($fields["uid"], $fields["sid"]);
		log_write(log_line($common, $fields));
	} catch (\Throwable $e) {
	}
}

// Logs an exception: its class, where it was thrown, its message scrubbed. A PDOException gives its
// SQLSTATE and no message: it can hold the SQL, or the value that broke a unique key.
function log_exception(\Throwable $e): void {
	try {
		$sql = $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : null;
		log_event("system.exception", [
			"cls" => get_class($e),
			"sql" => $sql,
			"at" => str_replace(dirname(__DIR__) . "/", "", $e->getFile()) . ":" . $e->getLine(),
			"msg" => is_null($sql) ? log_scrub($e->getMessage()) : null,
		]);
	} catch (\Throwable $e) {
	}
}

// A text made fit for a line: addresses masked, control characters gone, clipped. For what the
// code does not write itself -- an exception message, PHPMailer's ErrorInfo -- which can embed an
// address or an SQL fragment.
function log_scrub(string $text): string {
	$text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_substr(mb_scrub($text), 0, 4 * LOG_LIMIT_FIELD_CHARS)) ?? "";
	$text = preg_replace('/[^\s@\'"<>(),;:\[\]]+@[^\s@\'"<>(),;:\[\]]+/u', '[email]', $text) ?? "";
	return mb_substr($text, 0, LOG_LIMIT_FIELD_CHARS);
}

// UTC, ISO-8601, milliseconds
function log_timestamp(): string {
	$now = microtime(true);
	return gmdate("Y-m-d\TH:i:s", (int) $now) . sprintf(".%03dZ", (int) (($now - floor($now)) * 1000));
}

// A value as the log keeps it: a string clipped, a list cut to its first items, a number or a
// boolean as is. Null, objects and nested lists are dropped (null).
function log_value(mixed $value, bool $in_list = false): mixed {
	if (is_string($value)) return mb_substr($value, 0, LOG_LIMIT_FIELD_CHARS);
	if (is_int($value) || is_float($value) || is_bool($value)) return $value;
	if (!is_array($value) || $in_list) return null;
	$items = array_map(fn($item) => log_value($item, true), array_slice($value, 0, LOG_LIMIT_LIST_ITEMS));
	return array_values(array_filter($items, fn($item) => $item !== null));
}

// array with the values of log_value() and the keys whose value is null left out
function log_clean(array $fields): array {
	$clean = [];
	foreach ($fields as $key => $value) {
		$value = log_value($value);
		if ($value !== null) $clean[$key] = $value;
	}
	return $clean;
}

// The line of an event: the common keys, then the event's. Too long for one atomic write, it loses
// its last keys one by one and says so with "cut".
function log_line(array $common, array $fields): string {
	$common = log_clean($common);
	$fields = log_clean($fields);
	$line = log_render($common + $fields);
	while (strlen($line) >= LOG_LIMIT_LINE_BYTES && $fields) {
		array_pop($fields);
		$line = log_render($common + $fields + ["cut" => true]);
	}
	return $line;
}

// A line, in the format of LOG_FORMAT. No newline in it, in either: JSON escapes it, and a text
// value that has a control character is quoted as JSON.
function log_render(array $pairs): string {
	if (log_settings()["format"] !== "text") return (string) json_encode($pairs, LOG_JSON_FLAGS);

	$parts = array_values(array_slice($pairs, 0, 3));     // ts, lv, ev
	foreach (array_slice($pairs, 3) as $key => $value) {
		if (is_bool($value)) $value = $value ? "true" : "false";
		elseif (is_array($value)) $value = json_encode($value, LOG_JSON_FLAGS);
		elseif (is_string($value) && ($value === "" || preg_match('/[\s"\x00-\x1F\x7F]|\xE2\x80[\xA8\xA9]|\xC2\x85/', $value))) $value = json_encode($value, LOG_JSON_FLAGS);
		$parts[] = $key . "=" . $value;
	}
	return implode(" ", $parts);
}

// ---------------------------------------------------------------------------
// The sink: stdout, stderr or a file
// ---------------------------------------------------------------------------

// Where the lines go: [kind, path]. stdout and stderr in any case, a value that starts with "/" is
// a file, anything else (empty, a relative path, a typo) is stdout: the log is never turned off by mistake.
function log_sink(): array {
	$output = log_settings()["output"];
	if (strtolower($output) === "stderr") return ["stderr", ""];
	if (str_starts_with($output, "/")) return ["file", $output];
	return ["stdout", ""];
}

// Opens the sink once per request (a rename-based logrotate then needs no signal). A file is
// appended to, never locked: O_APPEND and a line under LOG_LIMIT_LINE_BYTES keep lines whole
// between workers. False when it cannot be opened; the reason is in the state.
function log_open(array $sink) {
	[$kind, $path] = $sink;
	error_clear_last();
	$handle = @fopen(match ($kind) { "stderr" => "php://stderr", "stdout" => "php://stdout", default => $path }, $kind === "file" ? "ab" : "wb");
	if ($handle !== false) return $handle;

	$state = &log_state();
	$state["why"] = log_open_failure($path, error_get_last()["message"] ?? "");
	return false;
}

// Why a file could not be opened, in a word. PHP 8.4 words an open_basedir refusal "Operation not
// permitted", so that one is told from the setting: a path under none of its directories.
function log_open_failure(string $path, string $error): string {
	$allowed = array_filter(explode(PATH_SEPARATOR, (string) ini_get("open_basedir")));
	if ($allowed && !array_filter($allowed, fn($dir) => $path === rtrim($dir, "/") || str_starts_with($path, rtrim($dir, "/") . "/"))) return "open_basedir";

	return match (true) {
		str_contains($error, "Permission denied") => "permission_denied",
		str_contains($error, "Read-only file system") => "read_only",
		str_contains($error, "No such file") => "missing_directory",
		str_contains($error, "Is a directory") => "is_directory",
		str_contains($error, "Operation not permitted") => "not_permitted",
		default => "cannot_open",
	};
}

// Writes one line, in one fwrite. A file that cannot be opened or written does not lose it: this
// line and the rest of the request go to stdout, and `system.log_sink_failed` says so (once).
function log_write(string $line): void {
	if ($line === "") return;
	$state = &log_state();
	$line .= "\n";

	for ($attempt = 0; $attempt < 2; $attempt++) {
		$state["sink"] ??= log_sink();
		$state["handle"] ??= log_open($state["sink"]);
		if ($state["handle"] && @fwrite($state["handle"], $line) === strlen($line)) return;
		if ($state["sink"][0] !== "file") return;     // stdout or stderr unwritable: nowhere else to go

		$file = $state["sink"][1];
		$why = $state["handle"] ? "write_failed" : $state["why"];
		if ($state["handle"]) @fclose($state["handle"]);
		$state["sink"] = ["stdout", ""];
		$state["handle"] = null;
		log_event("system.log_sink_failed", ["f" => $file, "why" => $why]);
	}
}

// ---------------------------------------------------------------------------
// script/cron.php
// ---------------------------------------------------------------------------

// Starts the log of a cron run, first thing: one request id for the run, `system.cron_started`, and
// a shutdown function that writes `system.cron_ended` with ok:false if the run dies before
// log_cron_end() (an uncaught exception, a fatal error, a timeout). A dry run says so (`dry`) on both
// lines; a normal run has no such key.
function log_cron_start(bool $dry_run = false): void {
	$state = &log_state();
	$state["cron"] = ["started" => microtime(true), "done" => false, "dry" => $dry_run ?: null, "count" => array_fill_keys(["sent", "ko", "del", "tok", "ipa"], 0)];
	register_shutdown_function('log_cron_shutdown');
	log_event("system.cron_started", ["dry" => $state["cron"]["dry"]]);
}

// Counts what the run did: sent and ko (mails), del (accounts deleted), tok (tokens purged), ipa
// (login attempts purged).
function log_cron_count(string $counter, int $by = 1): void {
	$state = &log_state();
	if (isset($state["cron"]["count"][$counter])) $state["cron"]["count"][$counter] += $by;
}

// `system.cron_step`: a step of the run is done, written even when it had nothing to do ($handled 0), so
// that the lines between `system.cron_started` and `system.cron_ended` tell how far a run that died got.
// $handled is what the step took care of (accounts, rows purged, counters reset), $failed the mails among
// them that could not be sent; a dry run says what it would have done. The line belongs to no account.
function log_cron_step(string $step, ?int $handled = null, ?int $failed = null): void {
	$state = &log_state();
	log_context(["uid" => null]);
	log_event("system.cron_step", ["step" => $step, "dry" => $state["cron"]["dry"] ?? null, "n" => $handled, "ko" => $failed]);
}

// The run reached its end.
function log_cron_end(): void {
	log_cron_finish(true, null);
}

function log_cron_shutdown(): void {
	$error = error_get_last();
	$fatal = $error !== null && in_array($error["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
	log_cron_finish(false, $fatal ? log_fatal_message($error["message"]) : null);
}

// `system.cron_ended`, once: the counters are what had been done up to then.
function log_cron_finish(bool $ok, ?string $message): void {
	$state = &log_state();
	if (($state["cron"]["done"] ?? true) === true) return;
	$state["cron"]["done"] = true;
	$fields = ["ok" => $ok, "dry" => $state["cron"]["dry"], "ms" => (int) round((microtime(true) - $state["cron"]["started"]) * 1000)] + $state["cron"]["count"] + ["msg" => $message];
	log_event("system.cron_ended", $fields, $ok ? null : "error");
}

// The message of a fatal error for a line: its first line, scrubbed, and of an uncaught
// PDOException only the SQLSTATE (the rest may hold SQL).
function log_fatal_message(string $message): string {
	if (preg_match('/^Uncaught PDOException: SQLSTATE\[(\w+)\]/', $message, $found)) return "Uncaught PDOException SQLSTATE[" . $found[1] . "]";
	return log_scrub(strtok($message, "\n") ?: "");
}
