<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/date.php";

// reads and JSON-decodes the raw request body. Sends a 400 error and exits if it's missing,
// empty, or not a JSON object -- endpoints that take no body (GET, DELETE-by-query-string)
// don't call this.
function http_json_body(): array {
	$raw = file_get_contents('php://input');
	$body = json_decode($raw, true);
	if (!is_array($body)) http_error(400, "invalid_json", "Request body must be a valid JSON object.");
	return $body;
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
