-- Run this in phpMyAdmin on your existing 'fl' database
USE fl;

-- 1. Fix the ENUM to include 'withdrawal'
ALTER TABLE transactions 
    MODIFY COLUMN type ENUM('deposit','escrow_lock','escrow_release','refund','withdrawal') NOT NULL;

-- 2. Add withdrawal requests table for admin management
CREATE TABLE IF NOT EXISTS withdrawal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    method ENUM('iban','card') NOT NULL DEFAULT 'iban',
    iban VARCHAR(34) DEFAULT NULL,
    account_name VARCHAR(255) DEFAULT NULL,
    stripe_payment_method VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(500) DEFAULT NULL,
    processed_by INT DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status)
);

-- 3. Ensure stripe_payment_id and invoice_number exist in transactions
ALTER TABLE transactions
    ADD COLUMN IF NOT EXISTS stripe_payment_id VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(50) DEFAULT NULL;

-- 4. Add 'deposit' back as valid type (already there) — rejected withdrawals now use 'deposit' type
-- No schema change needed for this, just informational.

-- 5. Add attachment support to messages
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS attachment VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS attachment_type ENUM('image','video','file') DEFAULT NULL;

-- Allow body to be empty (for attachment-only messages)
ALTER TABLE messages MODIFY COLUMN body TEXT DEFAULT NULL;

-- 6. Fix emoji encoding: convert messages table to utf8mb4
ALTER TABLE messages
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Also fix body column explicitly
ALTER TABLE messages
    MODIFY COLUMN body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Also run this to ensure the connection charset is set correctly
SET NAMES utf8mb4;

-- 7. DEFINITIVE emoji fix: convert ALL tables to utf8mb4
-- Run this if emojis still show as "?" after previous migrations
ALTER DATABASE fl CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE messages     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE users        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE listings     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE notifications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE transactions  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE contracts     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE disputes      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE chats         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Ensure body column is explicitly utf8mb4
ALTER TABLE messages MODIFY COLUMN body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- 8. Fix "Illegal mix of collations" error in contracts page
-- Standardize ALL tables to utf8mb4_unicode_ci (run this if you see collation errors)
ALTER DATABASE fl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE contracts    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE listings     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE users        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE messages     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE transactions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE disputes     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE chats        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE applications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE notifications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE withdrawal_requests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 9. Add assigned_to for dispute ownership
ALTER TABLE disputes ADD COLUMN IF NOT EXISTS assigned_to INT DEFAULT NULL;
ALTER TABLE disputes ADD COLUMN IF NOT EXISTS assigned_at DATETIME DEFAULT NULL;
ALTER TABLE disputes ADD FOREIGN KEY IF NOT EXISTS fk_disputes_assigned (assigned_to) REFERENCES users(id) ON DELETE SET NULL;

-- 10. Add 'commission' to transactions type ENUM
ALTER TABLE transactions MODIFY COLUMN type ENUM('deposit','escrow_lock','escrow_release','refund','withdrawal','commission') NOT NULL;

-- 11. Per-user message read tracking (fixes shared is_read bug)
-- Tracks the last message ID each user has read in each chat
CREATE TABLE IF NOT EXISTS chat_reads (
    chat_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_id INT NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id, user_id),
    FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 12. Link withdrawal_requests to their transaction for reliable invoice check
ALTER TABLE withdrawal_requests ADD COLUMN IF NOT EXISTS tx_id INT DEFAULT NULL;
ALTER TABLE withdrawal_requests ADD FOREIGN KEY IF NOT EXISTS fk_wr_tx (tx_id) REFERENCES transactions(id) ON DELETE SET NULL;
