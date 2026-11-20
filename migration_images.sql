-- UniTrace v2.1 photo upload: run ONCE in phpMyAdmin (unitrace database > SQL tab).
-- Existing database: run migration.sql first (if you haven't already), then this file.
-- Fresh install via database.sql already includes this column — skip this file.
USE unitrace;
ALTER TABLE items ADD COLUMN image VARCHAR(255) NULL AFTER description;
