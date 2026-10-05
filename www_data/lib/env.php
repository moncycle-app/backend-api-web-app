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
