<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../../config.php";
require_once "../../lib/db.php";
require_once "../../lib/sec.php";

header("Content-Type: text/plain");

print("MONCYCLE.APP");
print(PHP_EOL);

print("DB migration script from v14 to v15.");
print(PHP_EOL);
print("IMPORTANT : this script runs the file v14_to_v15.sql, DO NOT RUN IT ASIDE!");
print(PHP_EOL);

print("-----");
print(PHP_EOL);
print(PHP_EOL);

$db = db_open();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// NOTE: MariaDB commits at every ALTER TABLE / CREATE TABLE / RENAME, so the transaction below protects the
// data steps only, not the schema ones. A run that stops half way leaves a half migrated database: restore the
// dump taken before (README, "Upgrading"), do not run the script again on it. The accounts deleted below are
// final from the first ALTER on: the dump is the only way back.
try {

    $db->exec("START TRANSACTION");

    print("reading v14_to_v15.sql file ...");
    print(PHP_EOL);

    $v14_to_v15_shema_migration = file_get_contents("./v14_to_v15.sql");

    if ($v14_to_v15_shema_migration === false) {
        throw new RuntimeException("cannot read ./v14_to_v15.sql: run the script from its own directory, nothing has been changed");
    }

    print("looking for addresses held by several accounts ...");
    print(PHP_EOL);

    // Addresses are stored in lower case from v15 on (the file lowers the stored ones). v14 compared them exactly
    // (utf8mb4_bin), so `Alice@X.test` and `alice@x.test` could be two accounts: they would become one address and
    // the unique key would refuse. For each such address one account is kept and the others are deleted (their days
    // and tokens go with them, ON DELETE CASCADE): the one most likely to be the right one, and the report says why.
    // This reads the v14 tables, before anything is changed.

    // What tells two accounts apart, strongest first: the column of the query below, and what the report says when
    // the kept account wins on it (%1$s: its value, %2$s: the deleted account's). The query ranks on these columns,
    // in this order, and the oldest account wins what is left: so the first column that differs is the reason.
    $criteria = [
        "enabled"       => 'the kept account is enabled, the other one is disabled and cannot log in',
        "authenticated" => 'the kept account has authenticated, the other one never did (no login date, no token)',
        "day_count"     => 'the kept account holds more days (%1$s against %2$s)',
        "last_activity" => 'the kept account was used more recently (%1$s against %2$s)',
    ];

    // last_activity: the latest of the last login, the last use of a token and the last change of a day. GREATEST
    // answers NULL when one of its arguments is NULL, hence the oldest possible timestamp for "never".
    $accounts = $db->query(
        "SELECT d.email, c.no_compte, c.email1, c.actif AS enabled, c.inscription_date, c.derniere_co_date,
                (c.derniere_co_date IS NOT NULL OR COALESCE(j.tokens, 0) > 0) AS authenticated,
                COALESCE(j.tokens, 0) AS tokens, COALESCE(o.day_count, 0) AS day_count, o.first_day, o.last_day,
                NULLIF(GREATEST(COALESCE(c.derniere_co_date, '1970-01-01 00:00:01'),
                                COALESCE(j.last_use, '1970-01-01 00:00:01'),
                                COALESCE(o.last_modified, '1970-01-01 00:00:01')), '1970-01-01 00:00:01') AS last_activity
         FROM compte AS c
         INNER JOIN (SELECT LOWER(email1) AS email FROM compte GROUP BY email HAVING COUNT(*) > 1) AS d ON d.email = LOWER(c.email1)
         LEFT JOIN (SELECT no_compte, COUNT(*) AS day_count, MIN(date_obs) AS first_day, MAX(date_obs) AS last_day, MAX(dernier_modif) AS last_modified
                    FROM observation GROUP BY no_compte) AS o ON o.no_compte = c.no_compte
         LEFT JOIN (SELECT no_compte, COUNT(*) AS tokens, MAX(date_use) AS last_use
                    FROM jetton WHERE no_compte IS NOT NULL GROUP BY no_compte) AS j ON j.no_compte = c.no_compte
         ORDER BY d.email, " . implode(" DESC, ", array_keys($criteria)) . " DESC, c.no_compte"
    )->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($accounts as $account) {
        $groups[$account["email"]][] = $account;
    }

    $statement_delete_account = $db->prepare("DELETE FROM compte WHERE no_compte = :no_compte");

    $deleted_accounts = 0;
    $deleted_days = 0;
    $deleted_tokens = 0;
    $to_review = 0;

    foreach ($groups as $email => $members) {

        print("> " . $email . " is held by " . count($members) . " accounts:");
        print(PHP_EOL);

        foreach ($members as $member) {
            print(sprintf(
                "    account %s \"%s\": %s, registered %s, last login %s, last activity %s, tokens %s, days %s%s",
                $member["no_compte"], $member["email1"], $member["enabled"] ? "enabled" : "DISABLED", $member["inscription_date"],
                $member["derniere_co_date"] ?? "never", $member["last_activity"] ?? "never", $member["tokens"], $member["day_count"],
                $member["day_count"] > 0 ? " (" . $member["first_day"] . " to " . $member["last_day"] . ")" : ""
            ));
            print(PHP_EOL);
        }

        // the query put the account to keep first
        $keeper = array_shift($members);

        foreach ($members as $loser) {

            $reason = "nothing tells the two apart (same state, same data, same last use), the oldest account is kept";
            foreach ($criteria as $column => $message) {
                if ((string) $keeper[$column] !== (string) $loser[$column]) {
                    $reason = sprintf($message, $keeper[$column] ?? "never", $loser[$column] ?? "never");
                    break;
                }
            }

            print("  keeping account " . $keeper["no_compte"] . ", deleting account " . $loser["no_compte"] . ": " . $reason);
            print(PHP_EOL);

            // a choice to look at again: what goes held days, or was used more recently than what stays
            $used_more_recently = ($loser["last_activity"] ?? "") > ($keeper["last_activity"] ?? "");
            if ($loser["day_count"] > 0 || $used_more_recently) {
                print("  REVIEW account " . $loser["no_compte"] . " goes with its data (days " . $loser["day_count"] . ", tokens " . $loser["tokens"] . ")");
                print($used_more_recently ? " and was used more recently than the kept account" : "");
                print(PHP_EOL);
                $to_review++;
            }

            $statement_delete_account->bindValue(":no_compte", $loser["no_compte"], PDO::PARAM_INT);
            $statement_delete_account->execute();

            if ($statement_delete_account->rowCount() != 1) {
                throw new RuntimeException("account " . $loser["no_compte"] . " was not deleted");
            }

            $deleted_accounts++;
            $deleted_days += $loser["day_count"];
            $deleted_tokens += $loser["tokens"];
        }
    }

    // nothing may be left for the unique key to refuse in the file below
    if ($db->query("SELECT LOWER(email1) AS email FROM compte GROUP BY email HAVING COUNT(*) > 1")->fetch() !== false) {
        throw new RuntimeException("some addresses are still held by several accounts");
    }

    print(count($groups) . " addresses held by several accounts, " . $deleted_accounts . " accounts deleted with " . $deleted_days . " days and " . $deleted_tokens . " tokens");
    print(PHP_EOL);
    if ($to_review > 0) {
        print("!! " . $to_review . " deleted accounts to review (lines REVIEW above): if a choice is wrong, restore the dump taken before and decide by hand");
        print(PHP_EOL);
    }

    print("DONE !");
    print(PHP_EOL);
    print("-----");
    print(PHP_EOL);
    print(PHP_EOL);

    print("migrating shema (executing v14_to_v15.sql) ...");
    print(PHP_EOL);

    $db->exec($v14_to_v15_shema_migration);

    print("DONE !");
    print(PHP_EOL);
    print("-----");
    print(PHP_EOL);
    print(PHP_EOL);
    print("migrating data ...");
    print(PHP_EOL);

	$statement_select_obs  = $db->prepare("SELECT no_day, no_user_account, date_obs, sensation, stamp FROM day_timeline");
    $statement_select_desc = $db->prepare("SELECT no_description FROM description WHERE name = :desc_name AND no_user_account=:account_no LIMIT 1");
    $statement_insert_desc = $db->prepare("INSERT INTO `description` (`no_user_account`, `name`, `type`) VALUES (:no_user_account, :name, 0)");
    $statement_insert_link = $db->prepare("INSERT INTO `link_day_timeline_description` (`no_day`, `no_description`) VALUES (:observation_no, :description_no)");
    $statement_remove_old_desc = $db->prepare("UPDATE `day_timeline` SET `sensation` = NULL WHERE `no_day` = :no_day");
    $statement_migrate_stamp = $db->prepare("UPDATE `day_timeline` SET `stamp` = :new_stamp WHERE `no_day` = :no_day");

	$statement_select_obs->execute();

    $cached_descriptions = [];

    // ITERATE ON ALL OBSERVATIONS
    while ($obs = $statement_select_obs->fetch(PDO::FETCH_ASSOC)) {

        print("> account " . $obs["no_user_account"]);
        print("; obs " . $obs["no_day"]);
        print(" " . $obs["date_obs"]);
        
        if (!isset($cached_descriptions[$obs["no_user_account"]])) {
            $cached_descriptions[$obs["no_user_account"]] = [];
        }

        if (isset($obs["stamp"]) && !empty($obs["stamp"])) {
            print(PHP_EOL);
            print("      % stamp ");

            $new_stamp = str_ireplace(":)", "BB", $obs["stamp"]);
            $new_stamp = str_ireplace(".", "R", $new_stamp);
            $new_stamp = str_ireplace("=", "Y", $new_stamp);
            $new_stamp = str_ireplace("I", "G", $new_stamp);

            print($obs["stamp"]);

            if ($obs["stamp"] == $new_stamp) {
                print(" !! ingnored");
            }
            else {
                print(" -> ");
                print($new_stamp);

                try {
                    $statement_migrate_stamp->bindValue(":no_day", $obs["no_day"], PDO::PARAM_INT);
                    $statement_migrate_stamp->bindValue(":new_stamp", $new_stamp, PDO::PARAM_STR);
                    $statement_migrate_stamp->execute();

                    $statement_remove_old_desc->bindValue(":no_day", $obs["no_day"], PDO::PARAM_INT);
                    $statement_remove_old_desc->execute();
                } catch (PDOException $th) {
                    print($th->getMessage());
                }
            }
        }

        if (isset($obs["sensation"]) && $obs["sensation"]!=null && !empty($obs["sensation"])) {

            // THERE IS SENSATION TO MIGRATE
            $sensations = explode(',', $obs["sensation"]);

            foreach ($sensations as $sens) {
                $sens = trim($sens);
                print(PHP_EOL);
                print("      # desc  ");
                print($sens);
                
                $statement_select_desc->bindValue(":desc_name", $sens, PDO::PARAM_STR);
                $statement_select_desc->bindValue(":account_no", $obs["no_user_account"], PDO::PARAM_INT);

	            $statement_select_desc->execute();
                $description = $statement_select_desc->fetch(PDO::FETCH_ASSOC);

                print(" ->");

                try {
                    $no_desc = 0;

                    if (isset($cached_descriptions[$obs["no_user_account"]][$sens])) {
                        print(" cached "); // DESC NO PRESENT IN CACHE
                        $no_desc = $cached_descriptions[$obs["no_user_account"]][$sens];
                    }
                    elseif (!isset($description["no_description"])) {
                        print(" inserting "); // INSERTING A NEW DESC FOR THIS ACCOUNT
                        $statement_insert_desc->bindValue(":no_user_account", $obs["no_user_account"], PDO::PARAM_INT);
                        $statement_insert_desc->bindValue(":name", $sens, PDO::PARAM_STR);
                        $statement_insert_desc->execute();
                        $no_desc = $db->lastInsertId();
                    }
                    else {
                        print(" existing "); // THIS DESC IS EXISTING FOR THIS ACCOUNT
                        $no_desc = intval($description["no_description"]);
                    }
    
                    print("obs id ");
                    print($no_desc); // ID OF DESCRIPTION
    
                    // CACHING DESCRIPTION NUMBER
                    if (!isset($cached_descriptions[$obs["no_user_account"]][$sens])) {
                        $cached_descriptions[$obs["no_user_account"]][$sens] = $no_desc;
                    }

                    $statement_insert_link->bindValue(":observation_no", $obs["no_day"], PDO::PARAM_INT);
                    $statement_insert_link->bindValue(":description_no", $no_desc, PDO::PARAM_INT);
                    $statement_insert_link->execute();
                } catch (PDOException $th) {
                    print($th->getMessage());
                }

            }

        }

        print(PHP_EOL);
    }

    print("DONE !");
    print(PHP_EOL);
    print("-----");
    print(PHP_EOL);
    print(PHP_EOL);
    print("migrating auth tokens to hashed storage ...");
    print(PHP_EOL);

    // auth_token_str used to be stored in plaintext. Sessions belong to a user account
    // (no_user_account IS NOT NULL); captcha challenges reuse the same column but have
    // no_user_account NULL and are left alone, since their lookup (db_select_auth_token_captcha)
    // still matches on the plain value. Re-running this script is safe: a row already
    // hashed has strlen 64 (sha256 hex), not 256 (sec_random_password(256) output), so
    // it gets skipped on a second pass.
    $statement_select_tokens = $db->prepare("SELECT no_auth_token, auth_token_str FROM `auth_token` WHERE no_user_account IS NOT NULL");
    $statement_hash_token = $db->prepare("UPDATE `auth_token` SET `auth_token_str` = :hashed_token WHERE `no_auth_token` = :no_auth_token");

    $statement_select_tokens->execute();
    $tokens_to_migrate = $statement_select_tokens->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tokens_to_migrate as $token_row) {
        if (strlen($token_row["auth_token_str"]) != 256) {
            print("> auth_token " . $token_row["no_auth_token"] . " already migrated, skipping");
            print(PHP_EOL);
            continue;
        }

        print("> hashing auth_token " . $token_row["no_auth_token"]);
        print(PHP_EOL);

        $statement_hash_token->bindValue(":hashed_token", sec_hash_token($token_row["auth_token_str"]), PDO::PARAM_STR);
        $statement_hash_token->bindValue(":no_auth_token", $token_row["no_auth_token"], PDO::PARAM_INT);
        $statement_hash_token->execute();
    }

    print("DONE !");
    print(PHP_EOL);
    print("-----");
    print(PHP_EOL);
    print(PHP_EOL);
    print("deleting old shema ...");
    print(PHP_EOL);

    $db->exec("ALTER TABLE `day_timeline` DROP `sensation`");

    print("commiting DB changes ...");
    print(PHP_EOL);  

    $db->exec("COMMIT");

    print("DONE !");
    print(PHP_EOL);

} catch (\Throwable $th) {
    $db->exec("ROLLBACK");
    $db = null;

    throw $th;
}

$db = null;
