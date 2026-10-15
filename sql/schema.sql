-- Weekly Recap Platform schema (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE teachers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 0,   -- bumped on password change to end other sessions
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(120) NULL,                            -- optional display name (the student or a teacher can set it)
  bio VARCHAR(500) NULL,                             -- short text the student writes on their profile page
  photo VARCHAR(60) NULL,                            -- profile picture file name in uploads/avatars/
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  opens_at DATETIME NOT NULL,
  closes_at DATETIME NOT NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  time_limit_min SMALLINT UNSIGNED NULL,             -- minutes allowed once a student starts (empty = untimed)
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_window (published, opens_at, closes_at),
  FOREIGN KEY (created_by) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- type: single | multi | tf | text
CREATE TABLE questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  test_id INT UNSIGNED NOT NULL,
  position INT NOT NULL DEFAULT 0,
  type ENUM('single','multi','tf','text','match') NOT NULL,
  prompt TEXT NOT NULL,
  explanation TEXT NULL,
  left_title VARCHAR(80) NULL,            -- 'match' questions: heading above the items (default Categories)
  right_title VARCHAR(80) NULL,           -- 'match' questions: heading above the descriptions (default Options)
  KEY idx_test (test_id, position),
  FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- For text questions every row is an accepted answer (is_correct=1).
CREATE TABLE options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  position INT NOT NULL DEFAULT 0,
  label VARCHAR(500) NOT NULL,            -- for 'match' questions: the item on the left
  pair_label VARCHAR(500) NULL,           -- for 'match' questions: its description on the right
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_q (question_id, position),
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  test_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  submitted_at DATETIME NOT NULL,
  score INT NOT NULL,
  max_score INT NOT NULL,
  email_sent_at DATETIME NULL,
  UNIQUE KEY uq_attempt (test_id, student_id),
  KEY idx_student (student_id, submitted_at),
  FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE answers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  response TEXT NULL,            -- JSON: option id, list of option ids, or string
  is_correct TINYINT(1) NOT NULL,
  UNIQUE KEY uq_answer (attempt_id, question_id),
  KEY idx_question (question_id),
  FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- When a student pressed Start on a timed test (the deadline is started_at + tests.time_limit_min)
CREATE TABLE test_starts (
  test_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  PRIMARY KEY (test_id, student_id),
  FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE monthly_log (
  student_id INT UNSIGNED NOT NULL,
  month CHAR(7) NOT NULL,        -- YYYY-MM
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (student_id, month),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-time login codes: one row per email, locked (FOR UPDATE) while verifying so guesses cannot race.
CREATE TABLE login_challenges (
  email VARCHAR(190) NOT NULL PRIMARY KEY,
  code_hash CHAR(64) NULL,
  expires_at DATETIME NULL,
  last_sent DATETIME NULL,
  requests INT UNSIGNED NOT NULL DEFAULT 0,   -- codes sent in the current hour
  attempts INT UNSIGNED NOT NULL DEFAULT 0,   -- wrong codes in the current hour
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_ips (
  ip VARCHAR(45) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trusted browsers: the secret token lives in an HttpOnly cookie, only its hash is stored here
CREATE TABLE device_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  last_used DATETIME NOT NULL,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
