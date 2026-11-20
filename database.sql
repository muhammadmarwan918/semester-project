-- UniTrace v3 — fresh install.
-- Already have a v2.1 database with data you want to keep? Run migration_v3.sql instead.

CREATE DATABASE IF NOT EXISTS unitrace
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE unitrace;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    cms_id VARCHAR(20) NULL UNIQUE,                 -- university CMS ID (e.g. 72196), optional
    password VARCHAR(255) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',   -- 'user' = Student / Faculty
    is_verified TINYINT(1) NOT NULL DEFAULT 0,      -- 1 once the university email is confirmed (or an admin verified it)
    verify_token CHAR(64) NULL,                     -- SHA-256 of the emailed activation token
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(20) NULL UNIQUE,              -- FR-04: category-prefixed ID, e.g. ELEC-042
    user_id INT NULL,                               -- who reported it (NULL if that account was deleted)
    item_name VARCHAR(100) NOT NULL,
    type ENUM('Lost','Found') NOT NULL,
    category VARCHAR(50) NOT NULL,
    location VARCHAR(100) NOT NULL,
    item_date DATE NOT NULL,
    description TEXT,
    image VARCHAR(255) NULL,                        -- generated file name inside /uploads (NULL = no photo)
    status ENUM('Open','Claimed','Returned') NOT NULL DEFAULT 'Open',
    deleted_at DATETIME NULL,                       -- soft delete: the record stays for history (FR-10)
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_items_status (status),
    INDEX idx_items_category (category),
    INDEX idx_items_type (type),
    INDEX idx_items_deleted (deleted_at),
    CONSTRAINT fk_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claims_user (user_id),
    INDEX idx_claims_item_status (item_id, status),
    CONSTRAINT fk_claims_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    CONSTRAINT fk_claims_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FR-10: every change to an item is logged (who, what, when)
CREATE TABLE IF NOT EXISTS item_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    changed_by INT NULL,
    action VARCHAR(30) NOT NULL,                    -- created / edited / status / claim / deleted
    old_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_item (item_id),
    CONSTRAINT fk_history_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FR-08a / FR-09a: in-app alerts
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

-- NFR-08: outgoing emails wait here and are retried if sending fails
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(100) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    attempts TINYINT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    failed TINYINT(1) NOT NULL DEFAULT 0,           -- 1 = gave up after the last retry
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_due (sent_at, failed, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
