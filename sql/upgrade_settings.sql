-- For installs created before branding settings existed.
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
