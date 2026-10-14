-- For installs created before teachers could name students. The app also adds this column itself on first use.
ALTER TABLE students ADD COLUMN name VARCHAR(120) NULL AFTER email;
