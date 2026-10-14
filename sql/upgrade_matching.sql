-- For installs created before matching questions. The app also does this itself on first use.
ALTER TABLE questions MODIFY type ENUM('single','multi','tf','text','match') NOT NULL;
ALTER TABLE options ADD COLUMN pair_label VARCHAR(500) NULL AFTER label;
ALTER TABLE questions ADD COLUMN left_title VARCHAR(80) NULL, ADD COLUMN right_title VARCHAR(80) NULL;
