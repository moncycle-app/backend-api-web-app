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

try {

    $db->exec("START TRANSACTION");

    print("reading v14_to_v15.sql file ...");
    print(PHP_EOL);

    $v14_to_v15_shema_migration = file_get_contents("./v14_to_v15.sql");

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
    $statement_select_desc = $db->prepare("SELECT * FROM description WHERE name LIKE :desc_name AND no_user_account=:account_no LIMIT 1");
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
                    elseif (!isset($description["no_description"]) || isset($description["no_description"])<0) {
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
    // hashed has strlen 64 (sha256 hex), not 256 (sec_password_aleatoire(256) output), so
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
