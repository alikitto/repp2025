-- Tutor CRM schema (runtime). Fresh install: import this file, then start the app.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `login` varchar(64) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `role` enum('admin','teacher') NOT NULL DEFAULT 'teacher',
  `tg_chat_id` varchar(32) DEFAULT NULL,
  `fkey` text DEFAULT NULL,
  `fkey_rev` int NOT NULL DEFAULT 0,
  `session_rev` int NOT NULL DEFAULT 0,
  `totp_secret` varchar(255) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_last_counter` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stud` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `lastname` varchar(100) NOT NULL DEFAULT '',
  `klass` varchar(32) DEFAULT NULL,
  `money` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pay_mode` enum('prepaid','postpaid') NOT NULL DEFAULT 'prepaid',
  `teacher_id` int NOT NULL,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  KEY `idx_stud_teacher` (`teacher_id`),
  KEY `idx_stud_teacher_archived` (`teacher_id`,`archived`),
  CONSTRAINT `fk_stud_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sched_blocks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `teacher_id` int NOT NULL,
  `weekday` tinyint NOT NULL,
  `start` time NOT NULL,
  `end` time NOT NULL,
  `prelude` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_blocks_teacher_wd` (`teacher_id`,`weekday`,`start`),
  CONSTRAINT `fk_blocks_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `weekday` tinyint NOT NULL,
  `time` time NOT NULL,
  `time_end` time DEFAULT NULL,
  `block_id` int DEFAULT NULL,
  `partial` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_schedule_slot` (`user_id`,`weekday`,`time`),
  KEY `idx_schedule_user_day_time` (`user_id`,`weekday`,`time`),
  KEY `idx_schedule_wd_time` (`weekday`,`time`),
  KEY `idx_schedule_block` (`block_id`),
  CONSTRAINT `fk_schedule_user` FOREIGN KEY (`user_id`) REFERENCES `stud` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_schedule_block` FOREIGN KEY (`block_id`) REFERENCES `sched_blocks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dates` (
  `dates_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `dates` date NOT NULL,
  `time` time NOT NULL DEFAULT '00:00:00',
  `visited` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`dates_id`),
  UNIQUE KEY `uniq_user_date_time` (`user_id`,`dates`,`time`),
  KEY `idx_dates_user_date` (`user_id`,`dates`),
  KEY `idx_dates_date` (`dates`),
  CONSTRAINT `fk_dates_user` FOREIGN KEY (`user_id`) REFERENCES `stud` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pays` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `date` date NOT NULL,
  `lessons` int DEFAULT 8,
  `amount` decimal(10,2) DEFAULT NULL,
  `voice` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pays_user_date` (`user_id`,`date`),
  CONSTRAINT `fk_pays_user` FOREIGN KEY (`user_id`) REFERENCES `stud` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_settings` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `teacher_settings` (
  `teacher_id` int NOT NULL,
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`teacher_id`,`k`),
  CONSTRAINT `fk_teacher_settings_user` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `selector` char(32) NOT NULL,
  `hashed` varchar(255) NOT NULL,
  `expires` datetime NOT NULL,
  `user_id` int NOT NULL,
  PRIMARY KEY (`selector`),
  KEY `idx_remember_user` (`user_id`),
  KEY `idx_remember_expires` (`expires`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` varchar(32) NOT NULL,
  `text` varchar(255) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `teacher_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_teacher_id` (`teacher_id`,`id`),
  CONSTRAINT `fk_activity_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `login` varchar(64) NOT NULL,
  `ts` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_la_ip_ts` (`ip`,`ts`),
  KEY `idx_la_login_ts` (`login`,`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tg_notifications` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `lesson_at` datetime NOT NULL,
  `type` enum('debt_reminder') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_lesson_type` (`user_id`,`lesson_at`,`type`),
  CONSTRAINT `fk_tg_user` FOREIGN KEY (`user_id`) REFERENCES `stud` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
