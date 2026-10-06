<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/date.php";
require_once __DIR__ . "/log.php";

// Whether the request says its body is JSON: "application/json" in any case, parameters
// ("; charset=utf-8") allowed. Anything else, a form or "text/plain", is what a page of another site
// can send without asking the browser's leave: the writes of this API do not take it.
function http_content_type_is_json(): bool {
	$type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
	return is_string($type) && strtolower(trim(explode(';', $type, 2)[0])) === 'application/json';
}

// Sends a 415 and exits unless the body is declared JSON. The endpoints that read the body
// themselves (api/import.php) call it; http_json_body() does.
function http_require_json_content_type(): void {
	if (!http_content_type_is_json()) http_error(415, "unsupported_media_type", "The body must be sent as 'Content-Type: application/json'.");
}

// reads and JSON-decodes the raw request body. Sends a 415 if it is not declared JSON and a 400
// error and exits if it's missing, empty, or not a JSON object -- endpoints that take no body (GET,
// DELETE-by-query-string) don't call this.
function http_json_body(): array {
	http_require_json_content_type();
	$raw = file_get_contents('php://input');
	$body = json_decode($raw, true);
	if (!is_array($body)) http_error(400, "invalid_json", "Request body must be a valid JSON object.");
	return $body;
}

// Ends the request with a 503 "maintenance" when MAINTENANCE_MODE is on, whoever calls and whatever the method:
// every endpoint calls it first, before it opens the database (api_start() does for the JSON ones), so nothing
// is read or written. The health check is the one that does not, or a container in maintenance would be
// restarted as dead. The answer is the JSON envelope even for the endpoints that answer a file, an image or a redirect.
function http_exit_if_maintenance(): void {
	if (!MAINTENANCE_MODE) return;
	header('Content-Type: application/json');
	http_error(503, "maintenance", "The server is under maintenance. Try again later.");
}

function http_respond(int $status, array $body): never {
	http_response_code($status);
	echo json_encode($body);
	exit;
}

function http_data(int $status, array $data): never {
	http_respond($status, ["data" => $data]);
}

function http_no_content(): never {
	http_response_code(204);
	exit;
}

function http_error(int $status, string $code, string $message, array $details = []): never {
	$error = ["code" => $code, "message" => $message];
	if (!empty($details)) $error["details"] = $details;
	log_note(["err" => $code]);
	http_respond($status, ["error" => $error]);
}

// this codebase stores/produces UTC timestamps as bare "YYYY-MM-DD HH:MM:SS" throughout
// (see lib/date.php's date_validate_timestamp) -- these two helpers are the only place that
// knows about the ISO-8601 shape the JSON API exposes instead.
function http_iso8601(?string $utc_timestamp): ?string {
	if (empty($utc_timestamp)) return null;
	return str_replace(' ', 'T', trim($utc_timestamp)) . 'Z';
}

function http_from_iso8601(?string $timestamp): ?string {
	if (empty($timestamp)) return null;
	return preg_replace('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2}).*$/', '$1 $2', trim($timestamp));
}

// The client's own timestamp of a write ("lastWriteClientUtc") as a UTC "Y-m-d H:i:s", or the
// server's clock when it is absent, not a valid timestamp (a body can hold any JSON value), or outside
// what the column can store: a client whose clock is wrong must still be able to write.
function http_client_timestamp(mixed $iso8601): string {
	$utc = is_string($iso8601) ? http_from_iso8601($iso8601) : null;
	$storable = $utc && date_validate_timestamp($utc) && $utc >= TIMESTAMP_STORABLE_MIN && $utc <= TIMESTAMP_STORABLE_MAX;
	return $storable ? $utc : date('Y-m-d H:i:s');
}

// The answer of the endpoints a browser calls straight (downloads, pages): a plain-text 400.
function http_text_error(string $message): never {
	http_response_code(400);
	print("ERREUR: " . $message);
	exit;
}
