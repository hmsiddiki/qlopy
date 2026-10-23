<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/mail-schema.php
// The mail tables (log, queue, debug transcripts) in one place, so the installer (install.php) and the runtime check
// (qp_mail_install_tables() in includes/qp-mail.php) create exactly the same thing. No dependency on the rest of the core.

/** The CREATE TABLE bodies, keyed log | queue | debug. */
function qp_mail_schema_definitions(): array {
    return [
        'log' => "(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'queue' => "(
            id INT AUTO_INCREMENT PRIMARY KEY,
            payload LONGTEXT NOT NULL,
            attempts INT DEFAULT 0,
            next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'debug' => "(
            id INT AUTO_INCREMENT PRIMARY KEY,
            log_id INT NULL,
            transcript LONGTEXT NOT NULL,
            transport VARCHAR(32) DEFAULT 'smtp',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (log_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/**
 * Create the three mail tables with the given prefix (safe to repeat). Sites from an older core have them as qp_mail_* whatever
 * their prefix was; those are renamed to the prefixed names so their queue and log are kept. Throws on a database error.
 */
function qp_mail_create_tables(PDO $pdo, string $prefix): void {
    foreach (qp_mail_schema_definitions() as $key => $def) {
        $name = $prefix . 'mail_' . $key;
        $legacy = 'qp_mail_' . $key;
        if ($name !== $legacy) {
            $have = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $have->execute([$name]); $hasNew = (int)$have->fetchColumn() > 0;
            $have->execute([$legacy]); $hasOld = (int)$have->fetchColumn() > 0;
            if (!$hasNew && $hasOld) { $pdo->exec("RENAME TABLE `$legacy` TO `$name`"); continue; }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$name` " . $def);
    }
}
