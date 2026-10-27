-- SMS Gateway schema (MySQL 5.7+ / MariaDB 10.3+)
CREATE DATABASE IF NOT EXISTS sms_gateway CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sms_gateway;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_keys (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  label       VARCHAR(100) NOT NULL,
  key_prefix  VARCHAR(12)  NOT NULL,
  key_hash    CHAR(64)     NOT NULL UNIQUE,   -- sha256 of the full key; key itself is never stored
  created_at  DATETIME NOT NULL,
  revoked_at  DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS devices (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(100) NOT NULL,
  model      VARCHAR(100) NULL,
  last_seen  DATETIME NULL,                    -- device is "online" if within the last 30 seconds
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  device_id     INT UNSIGNED NOT NULL,
  recipient     VARCHAR(20)  NOT NULL,
  body          TEXT NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- pending|processing|sent|delivered|failed
  error         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL,
  processing_at DATETIME NULL,
  sent_at       DATETIME NULL,
  delivered_at  DATETIME NULL,
  INDEX idx_dev_status (device_id, status),
  INDEX idx_user_created (user_id, created_at),
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
  api_key_id INT UNSIGNED NOT NULL,
  window_min BIGINT UNSIGNED NOT NULL,         -- unix time / 60
  hits       INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (api_key_id, window_min)
) ENGINE=InnoDB;
