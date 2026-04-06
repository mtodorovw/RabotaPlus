-- Run this in phpMyAdmin to add new tables to your existing 'fl' database
USE fl;

-- NOTIFICATIONS
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(500) DEFAULT '',
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read)
);

-- Add stripe_payment_id to transactions if not exists
ALTER TABLE transactions 
    ADD COLUMN IF NOT EXISTS stripe_payment_id VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(50) DEFAULT NULL;

-- Update existing transactions to have invoice numbers
UPDATE transactions SET invoice_number = CONCAT('INV-', LPAD(id, 6, '0')) WHERE invoice_number IS NULL;
