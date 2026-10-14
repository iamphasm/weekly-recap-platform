-- For installs created before timed tests. The app also does this itself on first use.
ALTER TABLE tests ADD COLUMN time_limit_min SMALLINT UNSIGNED NULL AFTER published;

CREATE TABLE IF NOT EXISTS test_starts (
  test_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  PRIMARY KEY (test_id, student_id),
  FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
