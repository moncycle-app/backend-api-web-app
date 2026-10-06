<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// Environment variables read as the type of the setting, for config.docker.php -- the one lib
// file loaded before the config is, so it uses nothing the config defines. getenv() answers
// false when a variable is unset and a string otherwise, so an unset or empty one takes the
// default. A boolean is true/false, on/off, yes/no or 1/0, in any case.

function env_string(string $name, string $default = ""): string {
	$value = getenv($name);
	return $value === false || $value === "" ? $default : $value;
}

function env_int(string $name, int $default): int {
	$value = filter_var(getenv($name), FILTER_VALIDATE_INT);
	return $value === false ? $default : $value;
}

function env_bool(string $name, bool $default): bool {
	$value = getenv($name);
	if ($value === false || $value === "") return $default;
	return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

// One of $allowed (any case), else the default: a typo never turns a feature off.
function env_choice(string $name, array $allowed, string $default): string {
	$value = strtolower(trim((string) getenv($name)));
	return in_array($value, $allowed, true) ? $value : $default;
}

// The items of a comma list that are in $allowed (any case, each once). Unset, empty or nothing
// valid gives [], which the setting reads as "all".
function env_list(string $name, array $allowed): array {
	$items = array_map(fn($item) => strtolower(trim($item)), explode(",", (string) getenv($name)));
	return array_values(array_unique(array_intersect($items, $allowed)));
}

// The origins of a comma list, each as "scheme://host[:port]" in lower case (a trailing "/" is
// tolerated). An item that is anything else, a path or a query included, is dropped.
function env_origins(string $name): array {
	$origins = [];
	foreach (explode(",", (string) getenv($name)) as $item) {
		$origin = strtolower(rtrim(trim($item), "/"));
		if (preg_match('#^https?://([a-z0-9]([a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:.]+\])(:[0-9]{1,5})?$#', $origin)) $origins[] = $origin;
	}
	return array_values(array_unique($origins));
}

// The addresses and CIDR ranges ("10.0.0.0/8", "fd00::/8") of a comma list; an item that is not
// one is dropped.
function env_ip_ranges(string $name): array {
	$ranges = [];
	foreach (explode(",", (string) getenv($name)) as $item) {
		$parts = explode("/", trim($item));
		if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) continue;
		$max_bits = str_contains($parts[0], ":") ? 128 : 32;
		if (isset($parts[1]) && !(ctype_digit($parts[1]) && intval($parts[1]) <= $max_bits)) continue;
		$ranges[] = implode("/", $parts);
	}
	return array_values(array_unique($ranges));
}
