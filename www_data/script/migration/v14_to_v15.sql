CREATE TABLE `description` (
  `no_description` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `no_compte` mediumint(8) unsigned DEFAULT NULL,
  `name` varchar(256) NOT NULL,
  `type` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `last_write_client_UTC` timestamp NULL DEFAULT NULL,
  `last_write_db` TIMESTAMP  NOT NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`no_description`),
  UNIQUE KEY `unique_user_account_and_name` (`no_compte`,`name`),
  KEY `no_user_account` (`no_compte`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `observation`
CHANGE `dernier_modif` `last_write_db` timestamp NULL ON UPDATE CURRENT_TIMESTAMP AFTER `commentaire`;

ALTER TABLE `observation`
ADD `last_write_client_UTC` timestamp NULL AFTER `commentaire`;

ALTER TABLE `compte`
CHANGE `recherche` `research` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `donateur`;
ALTER TABLE `compte`
CHANGE `nom` `name` varchar(255) COLLATE 'utf8mb4_bin' NOT NULL AFTER `no_compte`;
ALTER TABLE `jetton`
CHANGE `nom` `name` varchar(256) COLLATE 'utf8mb4_bin' NOT NULL AFTER `no_compte`;
ALTER TABLE `compte`
CHANGE `donateur` `sponsor` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `timeline_asc`;
ALTER TABLE `observation`
CHANGE `premier_jour` `cycle_1st_day` tinyint(1) unsigned NULL AFTER `union_sex`;
ALTER TABLE `observation`
CHANGE `note_fc` `fc_score` varchar(32) COLLATE 'utf8mb4_bin' NULL AFTER `jenesaispas`;
ALTER TABLE `observation`
CHANGE `fleche_fc` `fc_arrow` varchar(1) COLLATE 'utf8mb4_bin' NULL AFTER `fc_score`;
ALTER TABLE `observation`
CHANGE `heure_temp` `time_temp_taken` time NULL AFTER `temperature`;
ALTER TABLE `observation`
CHANGE `jenesaispas` `day_not_observed` tinyint(1) unsigned NULL AFTER `date_obs`;
ALTER TABLE `observation`
CHANGE `jour_sommet` `is_peak` tinyint(1) unsigned NULL AFTER `time_temp_taken`;
ALTER TABLE `observation`
CHANGE `compteur` `counter_start` tinyint(1) unsigned NULL AFTER `is_peak`;
ALTER TABLE `observation`
CHANGE `grossesse` `pregnancy` tinyint(1) unsigned NULL AFTER `cycle_1st_day`;
ALTER TABLE `observation`
CHANGE `commentaire` `comment` varchar(256) COLLATE 'utf8mb4_bin' NULL AFTER `pregnancy`;


ALTER TABLE `compte`
CHANGE `motdepasse` `password` varchar(255) COLLATE 'utf8mb4_bin' NOT NULL AFTER `email2`;
ALTER TABLE `compte`
CHANGE `totp_etat` `totp_state` tinyint(1) unsigned DEFAULT NULL AFTER `password`;
ALTER TABLE `compte`
CHANGE `nb_co_echoue` `nb_connection_attempts` smallint(5) unsigned NOT NULL DEFAULT '0' AFTER `totp_secret`;
ALTER TABLE `compte`
CHANGE `actif` `user_enabled` tinyint(1) unsigned NOT NULL DEFAULT '1' AFTER `research`;
ALTER TABLE `compte`
CHANGE `derniere_co_date` `last_auth_date` timestamp NULL AFTER `relance`;
ALTER TABLE `compte`
CHANGE `mdp_change_date` `last_password_change` timestamp NULL AFTER `inscription_date`;
ALTER TABLE `compte`
CHANGE `relance` `is_inactive` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `user_enabled`;
ALTER TABLE `compte`
CHANGE `methode` `nfp_method` smallint(5) unsigned NOT NULL DEFAULT '1' AFTER `name`;


ALTER TABLE `jetton`
CHANGE `no_jetton` `no_auth_token` mediumint(8) unsigned NOT NULL AUTO_INCREMENT FIRST,
CHANGE `jetton_str` `auth_token_str` varchar(512) COLLATE 'utf8mb4_bin' NOT NULL AFTER `date_use`,
RENAME TO `auth_token`;
ALTER TABLE `auth_token`
CHANGE `pays` `contry_code` varchar(2) COLLATE 'utf8mb4_bin' NULL AFTER `expire`;

ALTER TABLE `compte`
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NOT NULL AUTO_INCREMENT FIRST,
RENAME TO `user_account`;

ALTER TABLE auth_token
DROP FOREIGN KEY `observation_ibfk_2`,
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NULL AFTER `no_auth_token`,
ADD CONSTRAINT `fk_auth_token_no_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE;

ALTER TABLE `description`
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NULL AFTER `no_description`,
ADD CONSTRAINT `fk_description_no_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE;

ALTER TABLE `observation`
DROP FOREIGN KEY `observation_ibfk_1`,
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NULL AFTER `no_observation`,
ADD CONSTRAINT `fk_no_observation_no_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE;

ALTER TABLE `observation`
CHANGE `no_observation` `no_day` mediumint(8) unsigned NOT NULL AUTO_INCREMENT FIRST,
RENAME TO `day_timeline`;

CREATE TABLE `link_day_timeline_description` (
  `no_day` mediumint(8) unsigned NOT NULL,
  `no_description` mediumint(8) unsigned NOT NULL,
  `last_write_db` TIMESTAMP  NOT NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP,
  KEY `no_day` (`no_day`),
  KEY `no_description` (`no_description`),
  UNIQUE KEY `unique_day_timeline_and_description` (`no_day`,`no_description`),
  CONSTRAINT `day_timeline_ibfk_4` FOREIGN KEY (`no_day`) REFERENCES `day_timeline` (`no_day`) ON DELETE CASCADE,
  CONSTRAINT `day_timeline_ibfk_5` FOREIGN KEY (`no_description`) REFERENCES `description` (`no_description`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `cle_valeur`
CHANGE `cle` `key` varchar(255) COLLATE 'utf8mb4_bin' NOT NULL FIRST,
CHANGE `valeur` `value` bigint(20) unsigned NULL AFTER `key`,
RENAME TO `key_value`;

ALTER TABLE `user_account` CHANGE `decouvert` `register_comment` VARCHAR(255)  CHARACTER SET utf8mb4  BINARY  NULL  DEFAULT NULL;
ALTER TABLE `day_timeline` CHANGE `gommette` `stamp` VARCHAR(3)  CHARACTER SET utf8mb4  BINARY  NOT NULL;
ALTER TABLE `day_timeline` CHANGE `last_write_db` `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `description` CHANGE `last_write_db` `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `user_account` ADD `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `auth_token` ADD `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `user_account` ADD `last_write_client_UTC` timestamp NULL AFTER `register_comment`;

ALTER TABLE `user_account`
ADD `last_failed_attempt` timestamp NULL DEFAULT NULL AFTER `nb_connection_attempts`;

CREATE TABLE `login_attempt_ip` (
  `no_login_attempt_ip` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_bin NOT NULL,
  `date_attempt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`no_login_attempt_ip`),
  KEY `ip_address_date_attempt` (`ip_address`,`date_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `user_account`
ADD `language` varchar(2) COLLATE 'utf8mb4_bin' NOT NULL AFTER `password`;
UPDATE `user_account` SET `language` = 'FR';
