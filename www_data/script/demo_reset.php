<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/data.php";

// php demo_reset.php [--dry-run]. The two public demo accounts back as script/db/demo.sql makes them, their days
// dated from today: what a visitor wrote in them is gone, and so are their sessions. Run every hour by the Docker
// image (server_conf/moncycle-demo.crontab, when DEMO_ENABLED), where script/cron.php runs once a day: that one sends
// mail and erases accounts, so it must not run more often. Does nothing when DEMO_ENABLED is off or in maintenance.
// --dry-run says what it would do and writes nothing. Any other argument is refused with exit status 2.
// Command line only.
if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit;
}

$arguments = array_slice($_SERVER["argv"], 1);
if (array_diff($arguments, ["--dry-run"])) {
	fwrite(STDERR, "usage: php demo_reset.php [--dry-run]" . PHP_EOL);
	exit(2);
}
$dry_run = in_array("--dry-run", $arguments, true);

if (MAINTENANCE_MODE) {
	echo "demo_reset: MAINTENANCE_MODE is on, the database is not touched." . PHP_EOL;
	exit(0);
}
if (!DEMO_ENABLED) {
	echo "demo_reset: DEMO_ENABLED is off, the demo accounts are left alone." . PHP_EOL;
	exit(0);
}

try {
	if (!$dry_run) data_reload_demo_accounts(db_open());
	echo ($dry_run ? "[dry run] demo accounts would be reloaded" : "demo accounts reloaded") . PHP_EOL;
}
catch (\Throwable $e) {
	fwrite(STDERR, "demo_reset: the demo accounts could not be reloaded: " . $e->getMessage() . PHP_EOL);
	exit(1);
}
