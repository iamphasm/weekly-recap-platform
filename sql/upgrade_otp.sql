-- Upgrade for installs created before email verification (one-time codes). Safe to run more than once.
DROP TABLE IF EXISTS login_codes;

CREATE TABLE IF NOT EXISTS login_challenges (
  email VARCHAR(190) NOT NULL PRIMARY KEY,
  code_hash CHAR(64) NULL,
  expires_at DATETIME NULL,
  last_sent DATETIME NULL,
  requests INT UNSIGNED NOT NULL DEFAULT 0,   -- codes sent in the current hour
  attempts INT UNSIGNED NOT NULL DEFAULT 0,   -- wrong codes in the current hour
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_ips (
  ip VARCHAR(45) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  last_used DATETIME NOT NULL,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
