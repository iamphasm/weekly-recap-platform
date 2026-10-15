-- For installs created before student profile pages. The app also adds these columns itself on first use.
ALTER TABLE students ADD COLUMN bio VARCHAR(500) NULL AFTER name, ADD COLUMN photo VARCHAR(60) NULL AFTER bio;
