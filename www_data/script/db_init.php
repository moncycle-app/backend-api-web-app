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

// php db_init.php [--wait=SECONDS]. The first launch of the Docker image (server_conf/docker-entrypoint.sh): a
// database with no table gets the schema (script/db/table.sql) and, when DEMO_ENABLED, the demo accounts
// (script/db/demo.sql); one that has tables is not touched.
// --wait: how long to keep trying while the database does not answer, as it may still be starting (default 0).
// Exit status: 0 the schema is there (made now, or before) or nothing was asked (maintenance), 1 the database
// does not answer or the schema failed, 2 usage. Command line only.
if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit;
}

$wait = 0;
foreach (array_slice($_SERVER["argv"], 1) as $argument) {
	if (!preg_match('/^--wait=([0-9]{1,3})$/', $argument, $found)) {
		fwrite(STDERR, "usage: php db_init.php [--wait=SECONDS]" . PHP_EOL);
		exit(2);
	}
	$wait = intval($found[1]);
}

// MAINTENANCE: the database may be down or in the middle of a restore, and the app does not read it
if (MAINTENANCE_MODE) {
	echo "db_init: MAINTENANCE_MODE is on, the database is not looked at." . PHP_EOL;
	exit(0);
}

$deadline = time() + $wait;
$db = null;
while (is_null($db)) {
	try {
		$db = db_open();
	}
	catch (\PDOException $e) {
		if (time() >= $deadline) {
			fwrite(STDERR, "db_init: the database does not answer: " . $e->getMessage() . PHP_EOL);
			exit(1);
		}
		echo "db_init: waiting for the database..." . PHP_EOL;
		sleep(2);
	}
}

try {
	echo data_init_database($db)
		? "db_init: the database was empty, the schema is made (table.sql)" . (DEMO_ENABLED ? " and the demo accounts (demo.sql)." : ".") . PHP_EOL
		: "db_init: the database has tables, left as it is." . PHP_EOL;
}
catch (\Throwable $e) {
	fwrite(STDERR, "db_init: the schema or the demo accounts could not be made: " . $e->getMessage() . PHP_EOL);
	exit(1);
}
