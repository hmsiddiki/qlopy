-- includes/db-migrations/qp_mail_tables.sql
-- Migration to create qp_mail tables: qp_mail_log, qp_mail_queue, qp_mail_debug

CREATE TABLE IF NOT EXISTS qp_mail_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipients TEXT NOT NULL,
    subject VARCHAR(255) DEFAULT '',
    headers TEXT,
    body LONGTEXT,
    transport VARCHAR(32) DEFAULT 'mail',
    status VARCHAR(32) DEFAULT 'queued',
    error TEXT,
    attempts INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    meta JSON DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qp_mail_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payload LONGTEXT NOT NULL,
    attempts INT DEFAULT 0,
    next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qp_mail_debug (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_id INT NULL,
    transcript LONGTEXT NOT NULL,
    transport VARCHAR(32) DEFAULT 'smtp',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
