-- UniTrace v2.1 -> v3 migration. Run ONCE in phpMyAdmin (SQL tab) on your existing `unitrace` database.
-- Needs the v2.1 schema first (migration.sql + migration_images.sql). Running it twice fails with
-- "duplicate column" (harmless, but stop there). Starting fresh? Import database.sql instead.

USE unitrace;

-- Users: CMS ID + email verification. Existing accounts count as verified.
ALTER TABLE users
    ADD COLUMN cms_id VARCHAR(20) NULL UNIQUE AFTER email,
    ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN verify_token CHAR(64) NULL;
ALTER TABLE users ALTER COLUMN is_verified SET DEFAULT 0;   -- new sign-ups start unverified

-- Items: category-prefixed ID + soft delete
ALTER TABLE items
    ADD COLUMN item_code VARCHAR(20) NULL UNIQUE AFTER id,
    ADD COLUMN deleted_at DATETIME NULL,
    ADD INDEX idx_items_deleted (deleted_at);

UPDATE items SET item_code = CONCAT(
    CASE category WHEN 'Electronics' THEN 'ELEC' WHEN 'Documents' THEN 'DOC'
                  WHEN 'Clothing' THEN 'CLO' WHEN 'Accessories' THEN 'ACC' ELSE 'OTH' END,
    '-', LPAD(CAST(id AS CHAR), GREATEST(3, CHAR_LENGTH(CAST(id AS CHAR))), '0'));

CREATE TABLE IF NOT EXISTS item_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    changed_by INT NULL,
    action VARCHAR(30) NOT NULL,
    old_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_item (item_id),
    CONSTRAINT fk_history_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO item_history (item_id, changed_by, action, new_status, note)
    SELECT id, user_id, 'created', status, 'Imported from v2.1' FROM items;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(100) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    attempts TINYINT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    failed TINYINT(1) NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_due (sent_at, failed, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
