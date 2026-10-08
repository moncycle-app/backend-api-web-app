<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/db.php";

// php db_perf.php [--n=200] [--socket=/run/mysqld/mysqld.sock] [--account=ID | --largest]
//
// Diagnoses the link between the app and MariaDB. It only reads (SELECT, SHOW): no row is written, and the
// connections it opens are the only load. Five parts:
//   1. the server: version, the settings that matter, what the counters say since it started, the size of
//      the data against the buffer pool;
//   2. a connection per request, in each way: the old one (SET NAMES), db_open(false) (charset in the DSN),
//      db_open(true) (persistent: what a web request gets, see db_open()), and a Unix socket when --socket is
//      given. "connect" = open + one SELECT 1; "request" = connect + the three reads a typical request makes
//      (session token, account row, a month of days);
//   3. what a query costs once the connection is open: the round trip alone (SELECT 1), then the app's own
//      reads through db_*(), on the demo account (ACCOUNT_DEMO_ID_BILLINGS) or, with --account=ID, on that account
//      or, with --largest, on each of the 10 accounts that hold the most days (a read of all the days of an account
//      grows with it, and the demo account holds 50). Section 1 always says how the days are spread over the
//      accounts. Nothing an account holds is printed, nor its id: only its rank and its number of days. The reads
//      do load the rows of those accounts in memory, as the app does when it serves them. Section 2's
//      "request" reads are on the first of these accounts;
//   4. emulated prepares (PDO's default, what db_open() uses) against native ones;
//   5. whether a persistent connection is safe: it is reused, a dead one is replaced, a request that died inside
//      a transaction leaves neither the transaction nor its locks to the next one, and the code holds no
//      session state (SET, GET_LOCK, temporary tables...) that a kept connection would carry over.
//      Any FAIL makes the exit status 1. It kills only connections it opened itself.
// Every number is printed with its unit (µs, ms, MB, rows, days...). The first three runs of each series are not
// counted (DNS, caches).
// A socket is reachable from this container only if the socket's directory is mounted in it (a volume shared with
// the MariaDB container); without it the socket series is skipped.
//
// Command line only: it opens hundreds of connections.

if (PHP_SAPI !== "cli") {
	http_response_code(403);
	exit;
}

$options = getopt("", ["n:", "socket:", "account:", "largest"]);
$n = max(10, intval($options["n"] ?? 200));
$socket = is_string($options["socket"] ?? null) ? $options["socket"] : null;
$largest = isset($options["largest"]);
$account_option = is_string($options["account"] ?? null) && ctype_digit($options["account"]) && intval($options["account"]) > 0 ? intval($options["account"]) : null;
if ($largest && isset($options["account"]) || isset($options["account"]) && $account_option === null) {
	fwrite(STDERR, "usage: php db_perf.php [--n=200] [--socket=/path/to/mysqld.sock] [--account=ID | --largest]" . PHP_EOL);
	exit(2);
}
$warmup = 3;

$charset = ";charset=utf8mb4";
$tcp_dsn = str_replace($charset, "", db_dsn());   // without the charset: the old way, SET NAMES after the connect
$results = [];   // label => list of microseconds

// ---------------------------------------------------------------------------
// 1. The server
// ---------------------------------------------------------------------------

$db = db_open();

echo "== 1. server ==" . PHP_EOL;
echo "client: PHP " . PHP_VERSION . " (" . PHP_SAPI . "), pdo_mysql, host " . DB_HOST . ":" . DB_PORT . ", db " . DB_NAME . PHP_EOL;
echo "server: " . db_value($db, "SELECT VERSION()") . PHP_EOL;

$variables = array_column(db_rows($db,
	"SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size', 'innodb_log_file_size', 'innodb_flush_log_at_trx_commit',
	'innodb_flush_method', 'innodb_io_capacity', 'max_connections', 'skip_name_resolve', 'skip_networking', 'thread_handling',
	'thread_cache_size', 'query_cache_type', 'log_bin', 'performance_schema', 'socket', 'port', 'slow_query_log', 'long_query_time')"
), "Value", "Variable_name");
ksort($variables);
$variable_units = ["innodb_buffer_pool_size" => "bytes", "innodb_log_file_size" => "bytes", "innodb_io_capacity" => "IO/s",
	"max_connections" => "connections", "thread_cache_size" => "threads", "long_query_time" => "s"];
foreach ($variables as $name => $value) {
	$unit = $variable_units[$name] ?? null;
	if ($unit === null || !is_numeric($value)) $shown = $value;   // ON / OFF, a name, a port, a mode
	elseif ($unit === "bytes") $shown = number_format($value / 1048576, 0, ".", " ") . " MB";
	else $shown = number_format(floatval($value), 0, ".", " ") . " $unit";
	echo sprintf("  %-34s %s", $name, $shown) . PHP_EOL;
}

$status = array_column(db_rows($db,
	"SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Connections', 'Threads_created', 'Threads_connected', 'Max_used_connections',
	'Aborted_connects', 'Aborted_clients', 'Slow_queries', 'Created_tmp_tables', 'Created_tmp_disk_tables', 'Innodb_buffer_pool_reads',
	'Innodb_buffer_pool_read_requests', 'Innodb_os_log_fsyncs', 'Questions')"
), "Value", "Variable_name");
echo "since the server started (" . round(intval($status["Uptime"] ?? 0) / 3600, 1) . " h):" . PHP_EOL;
ksort($status);
$status_units = ["Uptime" => "s", "Connections" => "connections", "Threads_created" => "threads", "Threads_connected" => "threads",
	"Max_used_connections" => "connections", "Aborted_connects" => "connections", "Aborted_clients" => "connections",
	"Slow_queries" => "queries", "Questions" => "queries", "Created_tmp_tables" => "tables", "Created_tmp_disk_tables" => "tables",
	"Innodb_buffer_pool_reads" => "page reads from disk", "Innodb_buffer_pool_read_requests" => "page reads in all", "Innodb_os_log_fsyncs" => "fsyncs"];
foreach ($status as $name => $value) {
	$shown = number_format(floatval($value), 0, ".", " ") . " " . ($status_units[$name] ?? "");
	if ($name === "Uptime") $shown .= " (" . round(floatval($value) / 86400, 1) . " days)";
	echo sprintf("  %-34s %s", $name, rtrim($shown)) . PHP_EOL;
}

$tables = db_rows($db,
	"SELECT t.table_name AS name, t.table_rows AS nb_rows, t.data_length AS data_bytes, t.index_length AS index_bytes
	FROM information_schema.tables AS t
	WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
	ORDER BY t.data_length + t.index_length DESC, t.table_name"
);
$data_bytes = 0;
echo "tables of " . DB_NAME . " (rows are the engine's estimate):" . PHP_EOL;
foreach ($tables as $table) {
	$data_bytes += intval($table["data_bytes"]) + intval($table["index_bytes"]);
	echo sprintf("  %-28s %9s rows   data %8.2f MB   index %8.2f MB", $table["name"], number_format(intval($table["nb_rows"]), 0, ".", " "), $table["data_bytes"] / 1048576, $table["index_bytes"] / 1048576) . PHP_EOL;
}
$pool_bytes = intval($variables["innodb_buffer_pool_size"] ?? 0);
echo sprintf("data + indexes %.2f MB, buffer pool %.0f MB", $data_bytes / 1048576, $pool_bytes / 1048576) . PHP_EOL;

// how the days are spread over the accounts (the demo accounts are in it)
$day_counts = db_select_day_counts_by_account($db);   // the biggest first
$sizes = array_map("intval", array_column($day_counts, "nb_days"));
sort($sizes);
if ($sizes) {
	$over_a_year = $over_1000_days = 0;
	foreach ($sizes as $size) {
		if ($size > 365) $over_a_year++;
		if ($size > 1000) $over_1000_days++;
	}
	echo sprintf("days per account: %d accounts hold %d days in all; smallest %d days, median %d days, 90th percentile %d days, biggest %d days; %d accounts over 365 days, %d over 1000 days",
		count($sizes), array_sum($sizes), $sizes[0], $sizes[intdiv(count($sizes), 2)], $sizes[intval(floor(0.9 * (count($sizes) - 1)))], end($sizes),
		$over_a_year, $over_1000_days) . PHP_EOL;
}

echo "to look at:" . PHP_EOL;
$notes = [];
$pool_requests = intval($status["Innodb_buffer_pool_read_requests"] ?? 0);
if ($pool_requests > 0) {
	$miss = intval($status["Innodb_buffer_pool_reads"] ?? 0) / $pool_requests * 100;
	$notes[] = sprintf("buffer pool: %.3f%% of the page reads went to disk%s", $miss, $miss > 1 ? " (more than 1%: the pool may be too small)" : " (fine)");
}
if ($pool_bytes > 0 && $data_bytes > $pool_bytes) $notes[] = "the data is larger than the buffer pool: raise innodb_buffer_pool_size";
if (intval($status["Connections"] ?? 0) > 0) {
	$created = intval($status["Threads_created"] ?? 0) / intval($status["Connections"]) * 100;
	$notes[] = sprintf("thread cache: %.1f%% of the connections had to create a thread%s", $created, $created > 10 ? " (raise thread_cache_size)" : " (fine)");
}
if (intval($status["Max_used_connections"] ?? 0) > 0.8 * intval($variables["max_connections"] ?? 0)) $notes[] = "Max_used_connections (" . $status["Max_used_connections"] . " connections) is above 80% of max_connections (" . $variables["max_connections"] . " connections)";
if (intval($status["Aborted_connects"] ?? 0) > 0) $notes[] = "Aborted_connects is " . $status["Aborted_connects"] . " connections: failed logins or a network problem";
if (intval($status["Created_tmp_disk_tables"] ?? 0) > 0) $notes[] = "Created_tmp_disk_tables is " . number_format(intval($status["Created_tmp_disk_tables"]), 0, ".", " ") . " tables (" . round(intval($status["Created_tmp_disk_tables"]) / max(1, intval($status["Created_tmp_tables"] ?? 0)) * 100, 1) . "% of the temporary tables): a query sorts or groups on disk";
if (($variables["skip_name_resolve"] ?? "OFF") === "OFF") $notes[] = "skip_name_resolve is OFF: every TCP connect may pay a reverse DNS lookup (grants must then name addresses, not hosts)";
if (($variables["log_bin"] ?? "OFF") === "ON") $notes[] = "log_bin is ON: every commit also writes the binary log (keep it only for replication or point-in-time recovery)";
if (($variables["innodb_flush_log_at_trx_commit"] ?? "1") !== "1") $notes[] = "innodb_flush_log_at_trx_commit is " . $variables["innodb_flush_log_at_trx_commit"] . ": faster commits, but a crash can lose the last second";
if (($variables["slow_query_log"] ?? "OFF") === "OFF") $notes[] = "slow_query_log is OFF: set it with long_query_time=0.1 to find the slow queries";
foreach ($notes as $note) echo "  - $note" . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// The accounts the reads are made on: the demo one, --account, or the 10 biggest (--largest).
// A month of days is the last one the account has a day in.
// ---------------------------------------------------------------------------

$picked = [];   // [name, no_user_account]
if ($largest) {
	foreach (array_slice($day_counts, 0, 10) as $rank => $row) $picked[] = ["#" . ($rank + 1), intval($row["no_user_account"])];
}
elseif ($account_option !== null) {
	if (db_select_user_account($db, $account_option) === null) {
		fwrite(STDERR, "--account: no such account" . PHP_EOL);
		exit(2);
	}
	$picked[] = ["account", $account_option];
}
else $picked[] = ["demo", ACCOUNT_DEMO_ID_BILLINGS];
if (!$picked) {
	fwrite(STDERR, "--largest: no account holds a day" . PHP_EOL);
	exit(2);
}

$targets = [];   // each: label, id, days, start and end of the month
foreach ($picked as [$name, $no_user_account]) {
	$all_days = db_select_all_day_timeline($db, $no_user_account);
	$start = substr($all_days ? end($all_days)["date_obs"] : date("Y-m-d"), 0, 8) . "01";
	$targets[] = [
		"label" => "$name, " . count($all_days) . " days",
		"id" => $no_user_account,
		"days" => count($all_days),
		"start" => $start,
		"end" => (new DateTimeImmutable($start))->modify("+1 month")->format("Y-m-d"),
	];
}
$all_days = null;
echo "reads are made on: " . implode("; ", array_column($targets, "label")) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// 2. One connection per request, in each way
// ---------------------------------------------------------------------------

// label => [dsn, PDO options, SET NAMES after the connect, db_open()'s $persistent (null: not through db_open())]
$series = [
	"tcp, SET NAMES (the old db_open)" => [$tcp_dsn, [], true, null],
	"tcp, charset in DSN: db_open(false)" => [db_dsn(), [], false, false],
	"tcp, persistent: db_open(true)" => [db_dsn(), [], false, true],
];
if ($socket !== null) {
	$socket_dsn = "mysql:unix_socket=" . $socket . ";dbname=" . DB_NAME . $charset;
	$series["socket, charset in DSN"] = [$socket_dsn, [], false, null];
	$series["socket, charset in DSN, persistent"] = [$socket_dsn, [PDO::ATTR_PERSISTENT => true], false, null];
}

echo "== 2. a connection per request ($n runs each; the three reads are on " . $targets[0]["label"] . ") ==" . PHP_EOL;
foreach ($series as $label => [$dsn, $pdo_options, $set_names, $through_db_open]) {
	try {
		for ($i = -$warmup; $i < $n; $i++) {
			$t0 = hrtime(true);
			$c = $through_db_open === null ? new PDO($dsn, DB_ID, DB_PASSWORD, $pdo_options) : db_open($through_db_open);
			if ($set_names) $c->exec("SET NAMES utf8mb4;");
			$c->query("SELECT 1")->fetchColumn();
			$t1 = hrtime(true);

			$st = $c->prepare("SELECT J.no_user_account, C.name FROM auth_token AS J INNER JOIN user_account AS C ON J.no_user_account = C.no_user_account WHERE J.auth_token_str = :token LIMIT 1");
			$st->execute(["token" => str_repeat("0", 64)]);
			$st->fetch();
			$st->closeCursor();
			$st = $c->prepare("SELECT C.no_user_account, C.name, C.nfp_method FROM user_account AS C WHERE C.no_user_account = :id");
			$st->execute(["id" => $targets[0]["id"]]);
			$st->fetch();
			$st->closeCursor();
			$st = $c->prepare("SELECT D.no_day, D.date_obs, D.stamp, D.comment FROM day_timeline AS D WHERE D.no_user_account = :id AND D.date_obs >= :start AND D.date_obs < :end ORDER BY D.date_obs, D.no_day");
			$st->execute(["id" => $targets[0]["id"], "start" => $targets[0]["start"], "end" => $targets[0]["end"]]);
			$st->fetchAll();
			$st = null;
			$t2 = hrtime(true);

			$c = null;
			if ($i >= 0) {
				$results["connect: $label"][] = ($t1 - $t0) / 1000;
				$results["request: $label"][] = ($t2 - $t0) / 1000;
			}
		}
	}
	catch (\PDOException $error) {
		echo "  skipped '$label': " . $error->getMessage() . PHP_EOL;
	}
}

// ---------------------------------------------------------------------------
// 3. What a query costs on an open connection
// ---------------------------------------------------------------------------

echo "== 3. queries on an open connection ($n runs each) ==" . PHP_EOL;
$reads = ["round trip alone: SELECT 1", "db_select_user_account_auth_token (miss)"];
$account_reads = ["db_select_user_account", "db_select_day_timelines_range (the last month)", "db_select_all_day_timeline (all days)"];
$db = db_open();
foreach ($reads as $label) {
	for ($i = -$warmup; $i < $n; $i++) {
		$t0 = hrtime(true);
		match ($label) {
			$reads[0] => db_value($db, "SELECT 1"),
			$reads[1] => db_select_user_account_auth_token($db, str_repeat("0", 64)),
		};
		if ($i >= 0) $results["query: $label"][] = (hrtime(true) - $t0) / 1000;
	}
}
$all_days_series = [];   // result key => number of days, for the cost per day
foreach ($targets as $target) {
	foreach ($account_reads as $label) {
		$key = "query: $label | " . $target["label"];
		if ($label === $account_reads[2]) $all_days_series[$key] = $target["days"];
		for ($i = -$warmup; $i < $n; $i++) {
			$t0 = hrtime(true);
			match ($label) {
				$account_reads[0] => db_select_user_account($db, $target["id"]),
				$account_reads[1] => db_select_day_timelines_range($db, $target["start"], $target["end"], $target["id"]),
				$account_reads[2] => db_select_all_day_timeline($db, $target["id"]),
			};
			if ($i >= 0) $results[$key][] = (hrtime(true) - $t0) / 1000;
		}
	}
}
$db = null;

// ---------------------------------------------------------------------------
// 4. Emulated and native prepares
// ---------------------------------------------------------------------------

echo "== 4. prepares ($n runs each, SELECT of one account row) ==" . PHP_EOL;
$prepare_sql = "SELECT C.no_user_account, C.name, C.nfp_method FROM user_account AS C WHERE C.no_user_account = :id";
$modes = ["prepare: emulated (PDO default, db_open)", "prepare: native, prepared every time", "prepare: native, prepared once"];
foreach ($modes as $mode) {
	$c = new PDO(db_dsn(), DB_ID, DB_PASSWORD, [PDO::ATTR_EMULATE_PREPARES => $mode === $modes[0]]);
	$once = $c->prepare($prepare_sql);
	for ($i = -$warmup; $i < $n; $i++) {
		$t0 = hrtime(true);
		$st = $mode === $modes[2] ? $once : $c->prepare($prepare_sql);
		$st->execute(["id" => ACCOUNT_DEMO_ID_BILLINGS]);
		$st->fetch();
		$st->closeCursor();
		if ($i >= 0) $results[$mode][] = (hrtime(true) - $t0) / 1000;
	}
	$st = $once = $c = null;
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// The numbers
// ---------------------------------------------------------------------------

echo sprintf("%-78s %11s %11s %11s %11s %11s", "time of one run (µs = microsecond)", "min", "median", "mean", "p95", "max") . PHP_EOL;
foreach ($results as $label => $samples) {
	sort($samples);
	$count = count($samples);
	echo sprintf("%-78s %8.0f µs %8.0f µs %8.0f µs %8.0f µs %8.0f µs", $label, $samples[0], $samples[intdiv($count, 2)], array_sum($samples) / $count, $samples[intval(floor(0.95 * ($count - 1)))], $samples[$count - 1]) . PHP_EOL;
}

if ($all_days_series) {
	echo PHP_EOL . "a read of all the days of an account, by its size (median of the runs):" . PHP_EOL;
	foreach ($all_days_series as $key => $days) {
		$samples = $results[$key];
		sort($samples);
		$median = $samples[intdiv(count($samples), 2)];
		echo sprintf("  %-26s %6d days %9.0f µs %7.1f µs per day", substr($key, strrpos($key, "| ") + 2), $days, $median, $days > 0 ? $median / $days : 0) . PHP_EOL;
	}
}

echo PHP_EOL . "Reading it: 'connect' is what opening the link costs every request; 'request' adds the three reads, so" . PHP_EOL;
echo "'request' minus 'connect' is the same in every series. A persistent series is the cost once the connection is reused" . PHP_EOL;
echo "(the CLI keeps the pool for the life of this process, as one Apache worker does for its own)." . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// 5. Is a persistent connection safe?
// ---------------------------------------------------------------------------

echo "== 5. persistent connection: safety ==" . PHP_EOL;
$failed = 0;
$check = [];   // label => [ok, detail]; an ok of null is information, not a verdict

$admin = db_open(false);   // a plain connection: it kills, it looks, it is never the one under test

// reused: the same server connection comes back, and a plain one never does
$handle = db_open(true);
$id = intval(db_value($handle, "SELECT CONNECTION_ID()"));
$handle = null;
$handle = db_open(true);
$check["a released connection is reused"] = [intval(db_value($handle, "SELECT CONNECTION_ID()")) === $id, "connection $id"];
$handle = null;
$plain = db_open(false);
$plain_id = intval(db_value($plain, "SELECT CONNECTION_ID()"));
$plain = null;
$plain = db_open(false);
$check["db_open(false) opens a new one each time"] = [intval(db_value($plain, "SELECT CONNECTION_ID()")) !== $plain_id, "connections $plain_id then another"];
$plain = null;

// dead: the server closed it (KILL stands for a MariaDB restart or wait_timeout)
$handle = db_open(true);
$id = intval(db_value($handle, "SELECT CONNECTION_ID()"));
$handle = null;
db_exec($admin, "KILL $id");
usleep(200000);
try {
	$handle = db_open(true);
	$new_id = intval(db_value($handle, "SELECT CONNECTION_ID()"));
	$check["a connection killed while kept is replaced"] = [$new_id !== $id, "connection $id killed, got $new_id"];
}
catch (\Throwable $error) {
	$check["a connection killed while kept is replaced"] = [false, $error->getMessage()];
}
$handle = null;

// a request that died inside db_transaction() (a fatal error, a timeout): START TRANSACTION and a row lock, never committed
$handle = db_open(true);
$id = intval(db_value($handle, "SELECT CONNECTION_ID()"));
$handle->exec("START TRANSACTION");
db_select_user_account_for_update($handle, ACCOUNT_DEMO_ID_BILLINGS);
$handle = null;   // the request ends here, without COMMIT or ROLLBACK
$handle = db_open(true);
$same = intval(db_value($handle, "SELECT CONNECTION_ID()")) === $id;
$check["a request that died in a transaction: same connection, no transaction left"] = [$same && !$handle->inTransaction() && intval(db_value($handle, "SELECT @@in_transaction")) === 0, ($same ? "same" : "another") . " connection, inTransaction " . var_export($handle->inTransaction(), true)];
try {
	$locker = db_open(false);
	$locker->exec("SET SESSION innodb_lock_wait_timeout = 2");
	$locker->exec("START TRANSACTION");
	$locker->query("SELECT U.no_user_account FROM user_account AS U WHERE U.no_user_account = " . intval(ACCOUNT_DEMO_ID_BILLINGS) . " FOR UPDATE NOWAIT")->fetchAll();
	$locker->exec("ROLLBACK");
	$check["... and its row lock is released"] = [true, "another connection took the row at once"];
}
catch (\Throwable $error) {
	$check["... and its row lock is released"] = [false, $error->getMessage()];
}
$locker = $handle = null;

// what PDO does by itself, without db_open()'s guard: information, to know whether the guard is the only defence
$handle = new PDO(db_dsn(), DB_ID, DB_PASSWORD, [PDO::ATTR_PERSISTENT => true]);
$handle->exec("START TRANSACTION");
$handle = null;
$handle = new PDO(db_dsn(), DB_ID, DB_PASSWORD, [PDO::ATTR_PERSISTENT => true]);
$by_itself = !$handle->inTransaction();
$handle->exec("ROLLBACK");
$check["information: PDO alone also rolls back an open transaction"] = [null, $by_itself ? "yes: db_open()'s check is a second line" : "NO: db_open()'s check is the only defence"];

// information: what a kept connection does carry over (this is why the code must not set any)
$handle = db_open(true);
$handle->exec("SET @moncycle_probe = 1");
$handle = null;
$handle = db_open(true);
$leaks = db_value($handle, "SELECT @moncycle_probe") !== null;
$handle->exec("SET @moncycle_probe = NULL");
$check["information: a user variable survives on a kept connection"] = [null, $leaks ? "yes" : "no"];
$handle = null;

// the code holds no session state (comment lines are not code: db_open() names what it avoids)
$patterns = '/\b(GET_LOCK|RELEASE_LOCK|LOCK\s+TABLES|CREATE\s+TEMPORARY|SET\s+(SESSION|GLOBAL|@|autocommit|NAMES|CHARACTER|time_zone|sql_mode|transaction)|autocommit\s*=)/i';
$found = [];
foreach (array_merge(glob(__DIR__ . "/../lib/*.php"), glob(__DIR__ . "/../api/*.php"), glob(__DIR__ . "/*.php")) as $file) {
	if (in_array(basename($file), ["db_perf.php", "test.php"], true)) continue;
	if (preg_match_all($patterns, preg_replace("~^\\s*(//|\\*|/\\*).*$~m", "", file_get_contents($file)), $matches)) $found[] = basename($file) . ": " . implode(", ", array_unique($matches[0]));
}
$check["the code sets no session state (SET, GET_LOCK, temporary table, autocommit...)"] = [!$found, $found ? implode("; ", $found) : "lib/, api/ and script/ scanned"];

$check["information: connections now"] = [null, db_value($admin, "SELECT COUNT(*) FROM information_schema.processlist AS P WHERE P.user = :user", ["user" => DB_ID]) . " connections open for this user, max_connections is " . ($variables["max_connections"] ?? "?") . " connections (each Apache worker keeps one)"];

foreach ($check as $label => [$ok, $detail]) {
	if ($ok === false) $failed++;
	echo sprintf("  %-5s %s (%s)", $ok === null ? "info" : ($ok ? "PASS" : "FAIL"), $label, $detail) . PHP_EOL;
}
echo $failed ? "$failed FAILED" . PHP_EOL : "all checks passed" . PHP_EOL;
exit($failed ? 1 : 0);
