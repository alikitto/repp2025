-- 03_schema.sql — чистая схема для Railway MySQL
SET NAMES utf8mb4;
SET SESSION sql_require_primary_key=0;

CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(64) NOT NULL,
  password VARCHAR(255) NULL,
  password_hash VARCHAR(255) NULL,
  name VARCHAR(100) NULL,
  familiya VARCHAR(100) NULL,
  prof VARCHAR(100) NULL,
  UNIQUE KEY uq_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS stud (
  user_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  lastname VARCHAR(100) NOT NULL,
  klass VARCHAR(32) NULL,
  phone VARCHAR(20) NULL,
  parentname VARCHAR(100) NULL,
  parent VARCHAR(100) NULL,
  school VARCHAR(100) NULL,
  note TEXT NULL,
  money DECIMAL(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  weekday TINYINT NOT NULL,
  time TIME NOT NULL,
  CONSTRAINT fk_schedule_user FOREIGN KEY (user_id) REFERENCES stud(user_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  KEY idx_schedule_user_day_time (user_id, weekday, time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS dates (
  dates_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  dates DATE NOT NULL,
  visited TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_dates_user FOREIGN KEY (user_id) REFERENCES stud(user_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  KEY idx_dates_user_date (user_id, dates)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pays (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  date DATE NOT NULL,
  CONSTRAINT fk_pays_user FOREIGN KEY (user_id) REFERENCES stud(user_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  KEY idx_pays_user_date (user_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tg_notifications (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  lesson_at DATETIME NOT NULL,
  type ENUM('debt_reminder') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_lesson_type (user_id, lesson_at, type),
  CONSTRAINT fk_tg_user FOREIGN KEY (user_id) REFERENCES stud(user_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
