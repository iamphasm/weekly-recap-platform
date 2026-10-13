-- For installs created before teachers could change their password. The app also adds this column itself on first use.
ALTER TABLE teachers ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0;
