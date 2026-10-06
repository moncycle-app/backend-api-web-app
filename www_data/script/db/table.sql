SET NAMES utf8mb4;

CREATE TABLE `user_account` (
  `no_user_account` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_bin NOT NULL,
  `nfp_method` smallint(5) unsigned NOT NULL DEFAULT 1,
  `age` smallint(5) unsigned NOT NULL,
  `email1` varchar(255) COLLATE utf8mb4_bin NOT NULL,
  `email2` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_bin NOT NULL,
  `language` varchar(2) COLLATE utf8mb4_bin NOT NULL,
  `totp_state` tinyint(1) unsigned DEFAULT NULL,
  `totp_secret` varchar(255) DEFAULT NULL,
  `nb_connection_attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_failed_attempt` timestamp NULL DEFAULT NULL,
  `timeline_asc` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `sponsor` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `research` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `auto_mail_export` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `user_enabled` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `is_inactive` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `last_auth_date` timestamp NULL DEFAULT NULL,
  `inscription_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_password_change` timestamp NULL DEFAULT NULL,
  `register_comment` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `last_write_client_UTC` timestamp NULL DEFAULT NULL,
  `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
  PRIMARY KEY (`no_user_account`),
  UNIQUE KEY `email1` (`email1`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `day_timeline` (
  `no_day` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `no_user_account` mediumint(8) unsigned NOT NULL,
  `date_obs` date NOT NULL DEFAULT '0000-00-00',
  `day_not_observed` tinyint(1) unsigned DEFAULT NULL,
  `fc_score` varchar(32) COLLATE utf8mb4_bin DEFAULT NULL,
  `fc_arrow` varchar(1) COLLATE utf8mb4_bin DEFAULT NULL,
  `stamp` varchar(3) COLLATE utf8mb4_bin NOT NULL,
  `sensation` varchar(256) COLLATE utf8mb4_bin DEFAULT NULL,
  `temperature` decimal(4,2) unsigned DEFAULT NULL,
  `time_temp_taken` time DEFAULT NULL,
  `is_peak` tinyint(1) unsigned DEFAULT NULL,
  `counter_start` tinyint(1) unsigned DEFAULT NULL,
  `union_sex` tinyint(1) unsigned DEFAULT NULL,
  `cycle_1st_day` tinyint(1) unsigned DEFAULT NULL,
  `pregnancy` tinyint(1) unsigned DEFAULT NULL,
  `comment` varchar(256) COLLATE utf8mb4_bin DEFAULT NULL,
  `last_write_client_UTC` timestamp NULL DEFAULT NULL,
  `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
  PRIMARY KEY (`no_day`),
  UNIQUE KEY `unique_user_account_and_date` (`no_user_account`,`date_obs`),
  KEY `no_user_account` (`no_user_account`),
  KEY `date_obs` (`date_obs`),
  CONSTRAINT `day_timeline_ibfk_1` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `auth_token` (
  `no_auth_token` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `no_user_account` mediumint(8) unsigned DEFAULT NULL,
  `name` varchar(256) COLLATE utf8mb4_bin NOT NULL,
  `expire` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `contry_code` varchar(2) COLLATE utf8mb4_bin DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_use` timestamp NULL DEFAULT NULL,
  `auth_token_str` varchar(512) COLLATE utf8mb4_bin NOT NULL,
  `captcha` varchar(16) COLLATE utf8mb4_bin DEFAULT NULL,
  `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
  PRIMARY KEY (`no_auth_token`),
  UNIQUE KEY `auth_token_str` (`auth_token_str`),
  KEY `no_user_account` (`no_user_account`),
  CONSTRAINT `day_timeline_ibfk_2` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `login_attempt_ip` (
  `no_login_attempt_ip` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_bin NOT NULL,
  `date_attempt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`no_login_attempt_ip`),
  KEY `ip_address_date_attempt` (`ip_address`,`date_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `description` (
  `no_description` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `no_user_account` mediumint(8) unsigned DEFAULT NULL,
  `name` varchar(256) COLLATE utf8mb4_bin NOT NULL,
  `type` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `last_write_client_UTC` timestamp NULL DEFAULT NULL,
  `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
  PRIMARY KEY (`no_description`),
  KEY `no_user_account` (`no_user_account`),
  UNIQUE KEY `unique_user_account_and_name` (`no_user_account`,`name`),
  CONSTRAINT `day_timeline_ibfk_3` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `link_day_timeline_description` (
  `no_day` mediumint(8) unsigned NOT NULL,
  `no_description` mediumint(8) unsigned NOT NULL,
  `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
  KEY `no_day` (`no_day`),
  KEY `no_description` (`no_description`),
  UNIQUE KEY `unique_day_timeline_and_description` (`no_day`,`no_description`),
  CONSTRAINT `day_timeline_ibfk_4` FOREIGN KEY (`no_day`) REFERENCES `day_timeline` (`no_day`) ON DELETE CASCADE,
  CONSTRAINT `day_timeline_ibfk_5` FOREIGN KEY (`no_description`) REFERENCES `description` (`no_description`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `key_value` (
  `key` varchar(255) NOT NULL,
  `value` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO `key_value` (`key`, `value`) VALUES
('pub_visit_monthly',	0),
('pub_visit_weekly',	0),
('pub_visit_daily',	0);
