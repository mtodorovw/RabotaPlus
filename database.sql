-- ============================================================
-- Freelance Platform Database
-- Import via phpMyAdmin or: mysql -u root < database.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS fl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fl;

-- USERS
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    neighborhood VARCHAR(100) DEFAULT NULL,
    balance DECIMAL(10,2) DEFAULT 0.00,
    role ENUM('user','admin') DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- LISTINGS
CREATE TABLE listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employer_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    budget DECIMAL(10,2) NOT NULL,
    city VARCHAR(100) DEFAULT NULL,
    neighborhood VARCHAR(100) DEFAULT NULL,
    status ENUM('open','closed','cancelled') DEFAULT 'open',
    selected_applicant_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (selected_applicant_id) REFERENCES users(id) ON DELETE SET NULL
);

-- APPLICATIONS
CREATE TABLE applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    applicant_id INT NOT NULL,
    cover_message TEXT DEFAULT NULL,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    UNIQUE KEY unique_application (listing_id, applicant_id),
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE CASCADE
);

-- CHATS (created automatically on application)
CREATE TABLE chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    application_id INT NOT NULL,
    employer_id INT NOT NULL,
    applicant_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    UNIQUE KEY unique_chat (application_id),
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE CASCADE
);

-- MESSAGES
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id INT NOT NULL,
    sender_id INT NOT NULL,
    body TEXT DEFAULT NULL,
    attachment VARCHAR(500) DEFAULT NULL,
    attachment_type ENUM('image','video','file') DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACTS
CREATE TABLE contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    employer_id INT NOT NULL,
    contractor_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    escrow_held TINYINT(1) DEFAULT 1,
    employer_confirmed TINYINT(1) DEFAULT 0,
    contractor_confirmed TINYINT(1) DEFAULT 0,
    status ENUM('active','completed','disputed','cancelled') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (contractor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- DISPUTES
CREATE TABLE disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    opened_by INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('open','resolved') DEFAULT 'open',
    resolution ENUM('employer','contractor') DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    admin_note TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    assigned_at DATETIME DEFAULT NULL,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

-- TRANSACTIONS LOG
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('deposit','escrow_lock','escrow_release','refund','withdrawal','commission') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    contract_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL
);

-- DEMO ADMIN USER (password: admin123)
INSERT INTO users (name, email, password, role, balance) VALUES
('Администратор', 'admin@platform.bg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 10000.00);

-- DEMO USERS (password: password)
INSERT INTO users (name, email, password, balance, city, neighborhood) VALUES
('Иван Петров', 'ivan@example.bg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 500.00, 'София', 'Лозенец'),
('Мария Георгиева', 'maria@example.bg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 300.00, 'Пловдив', 'Кършияка'),
('Петър Димитров', 'petar@example.bg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 750.00, 'Варна', 'Чайка');

-- DEMO LISTINGS
INSERT INTO listings (employer_id, title, description, budget, city, neighborhood, status) VALUES
(2, 'Нужен бояджия за стая', 'Търся опитен бояджия за боядисване на 20кв.м. стая. Необходими материали се осигуряват от мен. Работата трябва да завърши за 1 ден.', 180.00, 'София', 'Лозенец', 'open'),
(2, 'Ремонт на кран в баня', 'Спукан кран под мивката трябва да бъде сменен. Носете собствени инструменти.', 60.00, 'София', 'Лозенец', 'open'),
(3, 'Превод на документ от английски', 'Имам 3-странично юридическо споразумение на английски, нуждая се от точен превод на български.', 120.00, 'Пловдив', NULL, 'open'),
(4, 'Уебсайт за малък бизнес', 'Нужен е прост уебсайт с 5 страници за моя магазин. WordPress е предпочитан.', 800.00, 'Варна', 'Чайка', 'open');


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

-- NOTIFICATIONS
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(500) DEFAULT '',
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    invoice_number VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read)
);

-- Add Stripe + invoice columns to transactions
ALTER TABLE transactions
    ADD COLUMN IF NOT EXISTS stripe_payment_id VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(50) DEFAULT NULL;
