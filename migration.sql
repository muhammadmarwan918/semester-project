-- UniTrace v2 -> v2.1 migration. Run ONCE in phpMyAdmin (SQL tab) on your existing `unitrace` database.
-- It keeps all your data. Running it twice will fail with "duplicate column" (harmless, but stop there).
-- If you have no data worth keeping, it is simpler to drop the database and import database.sql.

USE unitrace;

ALTER DATABASE unitrace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- InnoDB is required for foreign keys and transactions; utf8mb4 for all text
ALTER TABLE users  ENGINE=InnoDB;
ALTER TABLE items  ENGINE=InnoDB;
ALTER TABLE claims ENGINE=InnoDB;
ALTER TABLE users  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE items  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE claims CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Remove claims whose item or user no longer exists (they would block the foreign keys)
DELETE FROM claims WHERE item_id NOT IN (SELECT id FROM items);
DELETE FROM claims WHERE user_id NOT IN (SELECT id FROM users);

-- Make sure every value is valid before converting to ENUM
UPDATE users  SET role   = 'user'    WHERE role   NOT IN ('user','admin');
UPDATE items  SET status = 'Open'    WHERE status NOT IN ('Open','Claimed','Returned');
UPDATE claims SET status = 'Pending' WHERE status NOT IN ('Pending','Approved','Rejected');

-- Check this result before continuing. Every returned row must be changed to
-- either 'Lost' or 'Found' before the ENUM conversion below.
SELECT id, type FROM items
WHERE type IS NULL OR type NOT IN ('Lost','Found');

ALTER TABLE users
    MODIFY role ENUM('user','admin') NOT NULL DEFAULT 'user',
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE items
    ADD COLUMN user_id INT NULL AFTER id,
    MODIFY type   ENUM('Lost','Found') NOT NULL,
    MODIFY status ENUM('Open','Claimed','Returned') NOT NULL DEFAULT 'Open',
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE claims
    MODIFY status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD INDEX idx_claims_item_status (item_id, status);

ALTER TABLE items
    ADD CONSTRAINT fk_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE claims
    ADD CONSTRAINT fk_claims_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_claims_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
