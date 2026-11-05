<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/qp-mail.php
// Core qp_mail API: settings, encryption helpers, DB installers and basic queue/log helpers.

if (!defined('QLP_INCLUDED_QP_MAIL')) define('QLP_INCLUDED_QP_MAIL', true);

// Path to bundled PHPMailer (app may include later when sending)
define('QP_MAIL_PHPM_HEADER_PATH', __DIR__ . '/helper_library/PHPMailer/src/');

define('QP_MAIL_SETTINGS_KEY', 'qp_mail_settings');

// Compute a sensible default From email when none is configured.
// Derives no-reply@<current-site-domain> using site_url, config, or HTTP_HOST.
function qp_mail_default_from(array $settings = []): string {
    $fe = trim((string)($settings['from_email'] ?? ''));
    if ($fe !== '') return $fe;
    // Prefer an existing SMTP username if it looks like an email
    $user = trim((string)($settings['smtp_username'] ?? ''));
    if ($user !== '' && strpos($user, '@') !== false) return $user;
    // derive domain from site_url option or config
    $domain = '';
    if (function_exists('get_option_meta')) {
        $site_url = get_option_meta('site_url');
        if (is_string($site_url) && $site_url !== '') {
            $host = parse_url($site_url, PHP_URL_HOST);
            if ($host) $domain = $host;
        }
    }
    if ($domain === '') {
        // try config.php
        try {
            $cfg = @require __DIR__ . '/../config.php';
            if (is_array($cfg) && !empty($cfg['site_url'])) {
                $host = parse_url($cfg['site_url'], PHP_URL_HOST);
                if ($host) $domain = $host;
            }
        } catch (Throwable $_e) { /* ignore */ }
    }
    if ($domain === '' && !empty($_SERVER['HTTP_HOST'])) {
        $domain = $_SERVER['HTTP_HOST'];
    }
    // sanitize domain
    $domain = trim(strtolower($domain));
    $domain = preg_replace('/^www\./i', '', $domain);
    if ($domain === '') $domain = 'localhost';
    return 'no-reply@' . $domain;
}

// Encryption helpers: prefer libsodium, else OpenSSL, fallback to base64 (not secure)
function qp_mail_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';
    if (function_exists('sodium_crypto_secretbox') && defined('AUTH_KEY')) {
        $key = substr(hash('sha256', AUTH_KEY, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }
    if (function_exists('openssl_encrypt') && defined('AUTH_KEY')) {
        $method = 'AES-256-CBC';
        $key = substr(hash('sha256', AUTH_KEY, true), 0, 32);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }
    return base64_encode($plaintext);
}

function qp_mail_decrypt(string $ciphertext): string {
    if ($ciphertext === '') return '';
    $raw = base64_decode($ciphertext);
    if (function_exists('sodium_crypto_secretbox') && defined('AUTH_KEY')) {
        $key = substr(hash('sha256', AUTH_KEY, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = @sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return $plain === false ? '' : $plain;
    }
    if (function_exists('openssl_decrypt') && defined('AUTH_KEY')) {
        $method = 'AES-256-CBC';
        $key = substr(hash('sha256', AUTH_KEY, true), 0, 32);
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, $method, $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }
    return base64_decode($ciphertext);
}

// Settings storage
// Filter `qp_mail_settings` ($settings) can adjust them per request (tests point mail at a local server with it).
function qp_mail_get_settings(): array {
    $s = [];
    if (function_exists('get_option_meta')) {
        $s = @get_option_meta(QP_MAIL_SETTINGS_KEY);
    }
    if (!is_array($s) && function_exists('get_option')) {
        $s = @get_option(QP_MAIL_SETTINGS_KEY, []);
    }
    $s = is_array($s) ? $s : [];
    if (function_exists('apply_filters')) {
        $f = apply_filters('qp_mail_settings', $s);
        if (is_array($f)) $s = $f;
    }
    return $s;
}

function qp_mail_update_settings(array $settings): bool {
    if (function_exists('update_option_meta')) return update_option_meta(QP_MAIL_SETTINGS_KEY, $settings);
    if (function_exists('update_option')) return update_option(QP_MAIL_SETTINGS_KEY, $settings);
    return false;
}

// Table names follow the site's table prefix (config.php db_table_prefix), like every other core table.
function qp_mail_table(string $name): string {
    return function_exists('table_name') ? table_name('mail_' . $name) : 'qp_mail_' . $name;
}

// Installer: create tables (safe if already exists). The definitions live in includes/mail-schema.php, shared with install.php.
function qp_mail_install_tables(): bool {
    if (!function_exists('db')) return false;
    try {
        require_once __DIR__ . '/mail-schema.php';
        qp_mail_create_tables(db(), defined('DB_TABLE_PREFIX') ? DB_TABLE_PREFIX : 'qp_');
        return true;
    } catch (Throwable $e) {
        $GLOBALS['qp_mail_install_error'] = $e->getMessage();
        return false;
    }
}

/**
 * Make sure the mail tables exist, once per request and (after a success) once per site: the option qp_mail_schema remembers it,
 * so a normal request pays nothing. The old include-time call ran before the database helper existed on a fresh install, so a
 * new site never got its tables and the Mail screen showed "tables appear to be missing".
 */
function qp_mail_ensure_tables(): bool {
    static $done = null;
    if ($done !== null) return $done;
    if (function_exists('qp_option_raw') && qp_option_raw('qp_mail_schema') !== null && qp_option_raw('qp_mail_schema') !== false && qp_option_raw('qp_mail_schema') !== '') return $done = true;
    $ok = qp_mail_install_tables();
    if ($ok && function_exists('update_option_meta')) update_option_meta('qp_mail_schema', 1);
    return $done = $ok;
}
// Basic logging function (writes to DB if available, else options fallback)
function qp_mail_log(array $row): bool {
    global $pdo;
    if (!empty($pdo)) qp_mail_ensure_tables();
    $now = date('Y-m-d H:i:s');
    $meta = $row['meta'] ?? [];
    if (!is_array($meta)) {
        $meta = json_decode((string)$meta, true) ?: [];
    }
    $data = [
        'recipients' => json_encode($row['to'] ?? $row['recipients'] ?? []),
        'subject' => $row['subject'] ?? '',
        'headers' => json_encode($row['headers'] ?? []),
        'body' => $row['message'] ?? $row['body'] ?? '',
        'transport' => $row['transport'] ?? 'mail',
        'status' => $row['status'] ?? 'unknown',
        'error' => $row['error'] ?? null,
        'attempts' => $row['attempts'] ?? 0,
        'created_at' => $now,
        'sent_at' => ($row['status'] === 'sent') ? $now : null,
        'meta' => json_encode($meta)
    ];

    if (!empty($pdo)) {
        try {
            $sql = "INSERT INTO " . qp_mail_table('log') . " (recipients,subject,headers,body,transport,status,error,attempts,created_at,sent_at,meta) VALUES (:recipients,:subject,:headers,:body,:transport,:status,:error,:attempts,:created_at,:sent_at,:meta)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
            $last = (int)$pdo->lastInsertId();
            if (!empty($meta['debug'])) {
                try {
                    $dsql = "INSERT INTO " . qp_mail_table('debug') . " (log_id, transcript, transport) VALUES (:log_id, :transcript, :transport)";
                    $dstmt = $pdo->prepare($dsql);
                    $dstmt->execute(['log_id' => $last, 'transcript' => $meta['debug'], 'transport' => $data['transport']]);
                } catch (Throwable $_e) { }
            }
            return true;
        } catch (Throwable $e) {
            // fallback
        }
    }

    // Option fallback
    $logs = function_exists('get_option_meta') ? @get_option_meta('qp_mail_logs') : @get_option('qp_mail_logs', []);
    if (!is_array($logs)) $logs = [];
    $logs[] = $data;
    if (function_exists('update_option_meta')) update_option_meta('qp_mail_logs', $logs);
    elseif (function_exists('update_option')) update_option('qp_mail_logs', $logs);
    return true;
}

// Enqueue payload (writes to DB queue or option fallback)
function qp_mail_enqueue(array $payload): bool {
    global $pdo;
    if (!empty($pdo)) qp_mail_ensure_tables();
    $now = gmdate('Y-m-d H:i:s');
    if (!empty($pdo)) {
        try {
            $sql = "INSERT INTO " . qp_mail_table('queue') . " (payload,attempts,next_attempt_at,created_at) VALUES (:payload,0,:next_attempt_at,:created_at)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['payload' => json_encode($payload), 'next_attempt_at' => $now, 'created_at' => $now]);
            if (function_exists('qp_cron_note_work')) qp_cron_note_work(time());
            return true;
        } catch (Throwable $e) { }
    }
    $q = function_exists('get_option_meta') ? @get_option_meta('qp_mail_queue') : @get_option('qp_mail_queue', []);
    if (!$q) $q = [];
    $q[] = ['payload' => $payload, 'attempts' => 0, 'next_attempt_at' => $now, 'created_at' => $now];
    if (function_exists('update_option_meta')) update_option_meta('qp_mail_queue', $q);
    else if (function_exists('update_option')) update_option('qp_mail_queue', $q);
    return true;
}

// Normalise caller headers (string, numeric array of "Name: value" lines, or associative array) into
// [lines, map-with-lowercase-names]. Internal `qp_` keys (qp_queue, qp_text_body) are never emitted as headers.
function qp_mail_normalize_headers($headers): array {
    $header_lines = [];
    $header_map = [];
    $add_line = function (string $line) use (&$header_lines, &$header_map) {
        $line = trim($line);
        if ($line === '') return;
        $header_lines[] = $line;
        if (strpos($line, ':') !== false) {
            [$hn, $hv] = explode(':', $line, 2);
            $header_map[strtolower(trim($hn))] = trim($hv);
        }
    };
    if (is_string($headers) && trim($headers) !== '') {
        foreach (preg_split('/\r\n|\n|\r/', $headers) as $line) $add_line($line);
    } elseif (is_array($headers)) {
        $is_assoc = false;
        foreach ($headers as $k => $_v) { if (!is_int($k)) { $is_assoc = true; break; } }
        if ($is_assoc) {
            foreach ($headers as $k => $v) {
                if (is_string($k) && strpos($k, 'qp_') === 0) continue;
                if (is_array($v) || is_object($v)) continue;
                $hn = trim((string)$k);
                $hv = trim((string)$v);
                if ($hn === '' || $hv === '') continue;
                $header_lines[] = $hn . ': ' . $hv;
                $header_map[strtolower($hn)] = $hv;
            }
        } else {
            foreach ($headers as $line) if (is_scalar($line)) $add_line((string)$line);
        }
    }
    return [$header_lines, $header_map];
}

// A sender's own plain-text version, passed as headers['qp_text_body'] (associative headers only). Null when none.
function qp_mail_given_text($headers): ?string {
    if (is_array($headers) && isset($headers['qp_text_body']) && is_string($headers['qp_text_body']) && trim($headers['qp_text_body']) !== '') {
        return $headers['qp_text_body'];
    }
    return null;
}

// Is this message HTML? An explicit Content-Type wins; otherwise $default when given, else a look at the body.
function qp_mail_message_is_html(string $message, array $header_map, ?bool $default = null): bool {
    $ct = strtolower((string)($header_map['content-type'] ?? ''));
    if (strpos($ct, 'text/plain') !== false) return false;
    if (strpos($ct, 'text/html') !== false) return true;
    if ($default !== null) return $default;
    return (bool)preg_match('/<(?:!doctype|html|body|p|br|div|a|table|h[1-6])\b/i', $message);
}

// Plain-text version of an HTML email. Core's own converter (no PHPMailer code is used, so a PHPMailer update cannot
// change it): keeps link targets ("Label (https://...)"), turns blocks, line breaks and list items into lines, drops
// head/style/script, decodes entities. Filter `qp_mail_text_version` ($text, $html) can replace the result.
function qp_mail_text_version(string $html): string {
    $s = preg_replace('/<!--.*?-->/s', '', $html);
    $s = preg_replace('/<(head|title|style|script)\b[^>]*>.*?<\/\1\s*>/is', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s); // HTML whitespace is not significant; tags below decide the line breaks
    $s = preg_replace_callback('/<img\b[^>]*\balt\s*=\s*("([^"]*)"|\'([^\']*)\')[^>]*>/i', function ($m) {
        return ' ' . ($m[2] !== '' ? $m[2] : ($m[3] ?? '')) . ' ';
    }, $s);
    $s = preg_replace_callback('/<a\b[^>]*\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)<\/a\s*>/is', function ($m) {
        $raw = ($m[2] ?? '') !== '' ? $m[2] : ((($m[3] ?? '') !== '') ? $m[3] : ($m[4] ?? ''));
        $href = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $inner = $m[5] ?? '';
        $label = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0) return $inner;
        $bare = preg_replace('/^mailto:/i', '', $href);
        // re-escaped so strip_tags/entity decoding below leave the URL exactly as it was (a "&copy=" query stays)
        $shown = htmlspecialchars($bare, ENT_QUOTES, 'UTF-8');
        if ($label === '' || $label === $href || $label === $bare || rtrim($label, '/') === rtrim($href, '/')) return $shown;
        return $inner . ' (' . $shown . ')';
    }, $s);
    $s = preg_replace('/<br\b[^>]*>/i', "\n", $s);
    $s = preg_replace('/<li\b[^>]*>/i', "\n- ", $s);
    $s = preg_replace('/<\/?(?:p|div|h[1-6]|table|ul|ol|blockquote|pre|section|article|header|footer|hr)\b[^>]*>/i', "\n\n", $s);
    $s = preg_replace('/<\/tr\s*>/i', "\n", $s);
    $s = preg_replace('/<\/t[dh]\s*>/i', '  ', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\xC2\xA0", ' ', $s);
    $lines = array_map(function ($l) { return trim(preg_replace('/[ \t]+/', ' ', $l)); }, explode("\n", $s));
    $s = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    return function_exists('apply_filters') ? (string)apply_filters('qp_mail_text_version', $s, $html) : $s;
}

// Give a PHPMailer HTML message its text part (sender's own text when given, else generated). Plain messages untouched.
function qp_mail_set_alt_body($mail, ?string $text = null): void {
    if ($mail->ContentType !== 'text/html') return;
    $alt = $text !== null ? $text : qp_mail_text_version((string)$mail->Body);
    if (trim($alt) !== '') $mail->AltBody = $alt;
}

// multipart/alternative headers and body for PHP mail(): the caller's headers minus their content headers, then a
// base64 text/plain part followed by the text/html part (base64 keeps every line short). Returns [headers, body].
function qp_mail_alternative_mime(string $html, ?string $text, array $header_lines, string $charset = 'UTF-8'): array {
    $text = $text !== null ? $text : qp_mail_text_version($html);
    $charset = preg_match('/^[A-Za-z0-9\-_]+$/', $charset) ? $charset : 'UTF-8';
    $boundary = 'qp_alt_' . bin2hex(random_bytes(12));
    $keep = [];
    foreach ($header_lines as $line) {
        $name = strtolower(trim((string)strstr($line, ':', true)));
        if (in_array($name, ['content-type', 'content-transfer-encoding', 'mime-version'], true)) continue;
        $keep[] = $line;
    }
    $keep[] = 'MIME-Version: 1.0';
    $keep[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $part = function (string $type, string $content) use ($boundary, $charset) {
        return '--' . $boundary . "\r\nContent-Type: " . $type . '; charset=' . $charset . "\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($content), 76, "\r\n");
    };
    $body = "This is a multi-part message in MIME format.\r\n\r\n"
        . ($text !== '' ? $part('text/plain', $text) : '')
        . $part('text/html', $html)
        . '--' . $boundary . "--\r\n";
    return [implode("\r\n", $keep), $body];
}

// Public wrapper - minimal: supports queue flag via headers['qp_queue'] or settings.use_queue
function qp_mail($to, $subject, $message, $headers = [], $attachments = []) {
    $settings = qp_mail_get_settings();
    $use_queue = !empty($settings['use_queue']) || (!empty($headers['qp_queue']) && $headers['qp_queue']);
    if ($use_queue) {
        qp_mail_enqueue(['to'=>$to,'subject'=>$subject,'message'=>$message,'headers'=>$headers,'attachments'=>$attachments,'transport'=>$settings['transport'] ?? 'mail']);
        return true;
    }
    // Immediate send: try using PHPMailer if available; otherwise fallback to mail()
    // Headers are normalised before PHPMailer is touched, so the mail() fallback keeps them even when PHPMailer is missing.
    [$header_lines, $header_map] = qp_mail_normalize_headers($headers);
    $headers_string = !empty($header_lines) ? implode("\r\n", $header_lines) . "\r\n" : null;
    $text = qp_mail_given_text($headers);
    $debugLines = [];
    try {
        $mail = qp_mail_prepare_phpmailer($settings);
        qp_mail_capture_debug($mail, $debugLines);

        // Apply common headers to PHPMailer
        if (!empty($header_map['from'])) {
            $from = $header_map['from'];
            if (preg_match('/(.*)<\s*([^>]+)\s*>/', $from, $m)) {
                $name = trim($m[1], "\"' ");
                $email_addr = trim($m[2]);
                try { $mail->setFrom($email_addr, $name); } catch (Throwable $_) { }
            } else {
                try { $mail->setFrom(trim($from)); } catch (Throwable $_) { }
            }
        }
        if (!empty($header_map['reply-to'])) {
            try { $mail->addReplyTo(trim($header_map['reply-to'])); } catch (Throwable $_) { }
        }
        if (!empty($header_map['cc'])) {
            $ccs = explode(',', $header_map['cc']);
            foreach ($ccs as $c) { $c = trim($c); if ($c !== '') try { $mail->addCC($c); } catch (Throwable $_) { } }
        }
        if (!empty($header_map['bcc'])) {
            $bccs = explode(',', $header_map['bcc']);
            foreach ($bccs as $c) { $c = trim($c); if ($c !== '') try { $mail->addBCC($c); } catch (Throwable $_) { } }
        }
        if (!empty($header_map['content-type'])) {
            $ct = strtolower((string)$header_map['content-type']);
            if (strpos($ct, 'text/plain') !== false) $mail->isHTML(false);
            elseif (strpos($ct, 'text/html') !== false) $mail->isHTML(true);
            if (preg_match('/charset=([A-Za-z0-9\-]+)/i', $ct, $m)) $mail->CharSet = $m[1];
        }
        // Add other headers as custom headers (skip common ones)
        $skip = ['mime-version','content-type','from','cc','bcc','reply-to'];
        foreach ($header_map as $hn => $hv) {
            if (in_array($hn, $skip)) continue;
            try { $mail->addCustomHeader(ucwords($hn, '-') . ': ' . $hv); } catch (Throwable $_) { }
        }

        // Now add recipients
        if (is_array($to)) foreach ($to as $r) $mail->addAddress($r); else $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $message;
        qp_mail_set_alt_body($mail, $text);
        if (!empty($attachments) && is_array($attachments)) foreach ($attachments as $a) if (is_string($a) && file_exists($a)) $mail->addAttachment($a);
        $sent = $mail->send();
        qp_mail_log(['to'=>$to,'subject'=>$subject,'message'=>$message,'headers'=>$headers,'attachments'=>$attachments,'transport'=>$settings['transport'] ?? 'mail','status'=>$sent ? 'sent' : 'failed','error' => $sent ? '' : $mail->ErrorInfo,'meta'=>['debug'=>implode("\n", $debugLines)]]);
        return (bool)$sent;
    } catch (Throwable $e) {
        // Fallback to PHP mail() — include headers string if available; HTML goes out as multipart/alternative
        $to_str = is_array($to) ? implode(',', $to) : $to;
        if (qp_mail_message_is_html((string)$message, $header_map)) {
            [$alt_headers, $alt_body] = qp_mail_alternative_mime((string)$message, $text, $header_lines, $settings['charset'] ?? 'UTF-8');
            $ok = @mail($to_str, $subject, $alt_body, $alt_headers);
        } elseif ($headers_string !== null) {
            $ok = @mail($to_str, $subject, $message, $headers_string);
        } else {
            $ok = @mail($to_str, $subject, $message);
        }
        qp_mail_log(['to'=>$to,'subject'=>$subject,'message'=>$message,'headers'=>$headers,'attachments'=>$attachments,'transport'=>'mail','status'=>$ok ? 'sent' : 'failed','error'=>$ok? '': $e->getMessage(),'meta'=>['debug'=>implode("\n", $debugLines)]]);
        return (bool)$ok;
    }
}

// Simple accessor functions (queue/log retrieval) to be expanded later
function qp_mail_get_queue(int $limit = 200) {
    global $pdo;
    if (!empty($pdo)) qp_mail_ensure_tables();
    if (!empty($pdo)) {
        try { $stmt = $pdo->prepare('SELECT * FROM ' . qp_mail_table('queue') . ' ORDER BY next_attempt_at ASC LIMIT ?'); $stmt->bindValue(1,(int)$limit,PDO::PARAM_INT); $stmt->execute(); return $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { }
    }
    $q = function_exists('get_option_meta') ? @get_option_meta('qp_mail_queue') : @get_option('qp_mail_queue', []);
    return is_array($q) ? array_slice($q,0,$limit) : [];
}

function qp_mail_get_logs(int $limit = 200) {
    global $pdo;
    if (!empty($pdo)) qp_mail_ensure_tables();
    if (!empty($pdo)) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM ' . qp_mail_table('log') . ' ORDER BY created_at DESC LIMIT ?');
            $stmt->bindValue(1,(int)$limit,PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['meta'] = json_decode($r['meta'] ?? '[]', true) ?: [];
                $r_created = $r['created_at'] ?? null;
                $r['created_at_formatted'] = function_exists('format_site_datetime') ? format_site_datetime($r_created) : $r_created;
            }
            return $rows;
        } catch (Throwable $e) { }
    }
    $logs = function_exists('get_option_meta') ? @get_option_meta('qp_mail_logs') : @get_option('qp_mail_logs', []);
    return is_array($logs) ? array_slice(array_reverse($logs),0,$limit) : [];
}

// Record the SMTP conversation into $lines (shown by the Transcript button in the mail log). PHPMailer prints nothing
// unless SMTPDebug is on. Credentials sent during AUTH are masked so a transcript is safe to look at and keep.
function qp_mail_capture_debug($mail, array &$lines): void {
    $mail->SMTPDebug = 2; // client and server lines
    $inAuth = false;
    $mail->Debugoutput = function ($str, $level) use (&$lines, &$inAuth) {
        $line = trim((string)$str);
        if ($line === '') return;
        if (preg_match('/CLIENT -> SERVER: AUTH\b/i', $line)) {
            $inAuth = true;
            $line = preg_replace('/(AUTH\s+(?:PLAIN|XOAUTH2|LOGIN))\s+\S+/i', '$1 ********', $line);
        } elseif ($inAuth && preg_match('/^CLIENT -> SERVER: \S+$/', $line)) {
            $line = 'CLIENT -> SERVER: ********';
        }
        if ($inAuth && preg_match('/SERVER -> CLIENT: (?:235|[45]\d\d)\b/', $line)) $inAuth = false; // 334 prompts keep it open
        $lines[] = $line;
    };
}

// Prepare and return a configured PHPMailer instance based on settings.
function qp_mail_prepare_phpmailer(array $settings = [], ?string $transport = null) {
    if (!file_exists(QP_MAIL_PHPM_HEADER_PATH . 'PHPMailer.php')) {
        throw new Exception('PHPMailer library not found at ' . QP_MAIL_PHPM_HEADER_PATH);
    }
    require_once QP_MAIL_PHPM_HEADER_PATH . 'PHPMailer.php';
    require_once QP_MAIL_PHPM_HEADER_PATH . 'SMTP.php';
    require_once QP_MAIL_PHPM_HEADER_PATH . 'Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = $settings['charset'] ?? 'UTF-8';
    $mail->isHTML(true);

    $useTransport = $transport ?? ($settings['transport'] ?? 'mail');
    if ($useTransport === 'smtp') {
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'] ?? 'localhost';
        $mail->Port = intval($settings['smtp_port'] ?? 25);
        $enc = strtolower($settings['smtp_encryption'] ?? '');
        if ($enc === 'ssl') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        elseif ($enc === 'tls') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = !empty($settings['smtp_auth']);
        if ($mail->SMTPAuth) {
            $mail->Username = $settings['smtp_username'] ?? '';
            $mail->Password = qp_mail_decrypt($settings['smtp_password'] ?? '');
        }
        // provider-specific defaults
        $provider = strtolower($settings['smtp_provider'] ?? ($settings['provider'] ?? ''));
        if ($provider === 'sendgrid') {
            // SendGrid SMTP defaults
            $mail->Host = $settings['smtp_host'] ?? 'smtp.sendgrid.net';
            $mail->Port = intval($settings['smtp_port'] ?? 587);
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = true;
            // SendGrid expects username 'apikey' and password equal to API key
            if (empty($mail->Username)) $mail->Username = 'apikey';
            if (empty($mail->Password)) $mail->Password = qp_mail_decrypt($settings['sendgrid_api_key'] ?? '');
        } elseif ($provider === 'ses' || $provider === 'aws_ses') {
            // AWS SES SMTP interface defaults (region-dependent host)
            $mail->Host = $settings['smtp_host'] ?? ($settings['ses_smtp_host'] ?? 'email-smtp.us-east-1.amazonaws.com');
            $mail->Port = intval($settings['smtp_port'] ?? 587);
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = true;
            // Username/password are SMTP credentials generated in AWS SES console
        }
        // OAuth2: if refresh token present, enable XOAUTH2 and set a token provider
        if (!empty($settings['oauth_refresh_token'])) {
            try {
                require_once __DIR__ . '/qp-mail-oauth.php';
                $provider_type = strtolower($settings['oauth_provider'] ?? 'google');
                $tenant = $settings['oauth_tenant'] ?? ($settings['oauth_tenant_id'] ?? 'common');
                $oauthOpts = [
                    'clientId' => $settings['oauth_client_id'] ?? '',
                    'clientSecret' => qp_mail_decrypt($settings['oauth_client_secret'] ?? ''),
                    'refreshToken' => qp_mail_decrypt($settings['oauth_refresh_token'] ?? ''),
                    'userEmail' => $settings['smtp_username'] ?? ($settings['from_email'] ?? ''),
                    'provider' => $provider_type,
                    'tenant' => $tenant,
                ];
                $mail->SMTPAuth = true;
                $mail->AuthType = 'XOAUTH2';
                $oauthProvider = new QP_Mailer_OAuthProvider($oauthOpts);
                $mail->setOAuth($oauthProvider);
                // ensure username is set for XOAUTH2
                if (empty($mail->Username)) $mail->Username = $oauthOpts['userEmail'];
            } catch (Throwable $_ex) {
                // leave SMTPAuth as-is; if OAuth provider fails, fallback to username/password
            }
        }
    } else {
        $mail->isMail();
    }

    // Always set a From address: use configured or default fallback
    try {
        $from_email = qp_mail_default_from($settings);
        $from_name = $settings['from_name'] ?? '';
        if ($from_name === '' && function_exists('get_option_meta')) {
            $sn = get_option_meta('site_name');
            if (is_string($sn) && $sn !== '') $from_name = $sn;
        }
        $mail->setFrom($from_email, $from_name);
    } catch (Throwable $_e) { /* ignore setFrom errors */ }

    // Apply DKIM if enabled and key present
    if (!empty($settings['dkim_enabled']) && ($settings['dkim_enabled'] === '1' || $settings['dkim_enabled'] === 1)) {
        try {
            if (!empty($settings['dkim_private_key']) && !empty($settings['dkim_selector'])) {
                $dkim_key = qp_mail_decrypt($settings['dkim_private_key']);
                // PHPMailer supports DKIM by setting these properties
                $mail->DKIM_selector = $settings['dkim_selector'];
                // Domain: infer from from_email host
                $from = $settings['from_email'] ?? '';
                $host = parse_url('mailto://' . $from, PHP_URL_HOST) ?: null;
                if ($host) $mail->DKIM_domain = $host;
                $mail->DKIM_private = $dkim_key;
                $mail->DKIM_identity = $mail->From;
            }
        } catch (Throwable $_e) {
            // ignore DKIM setup errors; signing will be skipped
        }
    }

    return $mail;
}

// OAuth access token cache helpers
function qp_mail_get_oauth_cache(): array {
    $s = qp_mail_get_settings();
    $enc = $s['oauth_access_token'] ?? '';
    $expiry = intval($s['oauth_access_token_expiry'] ?? 0);
    if (empty($enc)) return ['access_token'=>'','expiry'=>0];
    $token = qp_mail_decrypt($enc);
    return ['access_token'=>$token, 'expiry'=>$expiry];
}

function qp_mail_set_oauth_cache(string $access_token, int $expiry_ts): bool {
    $s = qp_mail_get_settings();
    $s['oauth_access_token'] = qp_mail_encrypt($access_token);
    $s['oauth_access_token_expiry'] = (int)$expiry_ts;
    return qp_mail_update_settings($s);
}

function qp_mail_clear_oauth(): bool {
    $s = qp_mail_get_settings();
    unset($s['oauth_refresh_token']);
    unset($s['oauth_client_secret']);
    unset($s['oauth_access_token']);
    unset($s['oauth_access_token_expiry']);
    return qp_mail_update_settings($s);
}

// Build provider-specific OAuth authorize URL for admin to visit.
function qp_mail_oauth_authorize_url(string $provider = 'google', string $clientId = '', string $redirect_uri = '', array $scopes = [], string $tenant = 'common'): string {
    $p = strtolower($provider);
    $redirect = $redirect_uri ?: (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . (function_exists('qp_admin_dir') ? '/' . qp_admin_dir() : '/admin') . '/mail.php';
    $scope = !empty($scopes) ? implode(' ', $scopes) : ($p === 'microsoft' ? 'offline_access openid smtp.send' : 'https://mail.google.com/');
    if ($p === 'microsoft' || $p === 'azure' || $p === 'office365') {
        $tenant = $tenant ?: 'common';
        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirect,
            'response_mode' => 'query',
            'scope' => $scope,
            'prompt' => 'consent'
        ]);
        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?{$params}";
    }
    // default Google
    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => $scope,
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
    return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
}

// Exchange authorization code for tokens and persist refresh token in settings (encrypted). Returns array or throws.
function qp_mail_oauth_exchange_code(string $provider, string $code, string $clientId, string $clientSecret, string $redirect_uri = '', string $tenant = 'common'): array {
    $p = strtolower($provider);
    $redirect = $redirect_uri ?: (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . (function_exists('qp_admin_dir') ? '/' . qp_admin_dir() : '/admin') . '/mail.php';
    if ($p === 'microsoft' || $p === 'azure' || $p === 'office365') {
        $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
        ];
    } else {
        $url = 'https://oauth2.googleapis.com/token';
        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
        ];
    }
    // POST
    $opts = ['http' => ['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query($data),'timeout'=>15]];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new Exception('OAuth token request failed: ' . $err);
        $json = json_decode($resp, true);
    } else {
        $resp = @file_get_contents($url, false, stream_context_create($opts));
        if ($resp === false) throw new Exception('OAuth token request failed');
        $json = json_decode($resp, true);
    }
    if (!is_array($json) || empty($json)) throw new Exception('Invalid token response: ' . json_encode($json));
    // Persist refresh token if provided
    if (!empty($json['refresh_token'])) {
        $s = qp_mail_get_settings();
        $s['oauth_refresh_token'] = qp_mail_encrypt($json['refresh_token']);
        $s['oauth_client_id'] = $clientId;
        $s['oauth_client_secret'] = qp_mail_encrypt($clientSecret);
        $s['oauth_provider'] = $p;
        $s['oauth_tenant'] = $tenant;
        qp_mail_update_settings($s);
    }
    return $json;
}

// Best-effort revoke of OAuth refresh token (Google/Microsoft). Returns array with ok and details.
function qp_mail_oauth_revoke(string $provider, string $refresh_token, string $clientId = '', string $clientSecret = '', string $tenant = 'common'): array {
    $p = strtolower($provider);
    if ($p === 'google') {
        $url = 'https://oauth2.googleapis.com/revoke';
        $data = ['token' => $refresh_token];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'response' => $resp];
        }
        $resp = @file_get_contents($url . '?' . http_build_query($data));
        return ['ok' => $resp !== false, 'code' => $resp !== false ? 200 : 0, 'response' => $resp];
    }
    if ($p === 'microsoft' || $p === 'azure' || $p === 'office365') {
        // Microsoft has no simple revoke endpoint for refresh tokens in all cases; attempt Graph revokeSignInSessions if possible (admin) or call token revocation endpoint if available
        // Best-effort: try to call token revocation endpoint (not universally supported)
        $url = 'https://login.microsoftonline.com/' . ($tenant ?: 'common') . '/oauth2/v2.0/logout';
        return ['ok' => false, 'code' => 0, 'response' => 'Microsoft token revocation requires Graph API; use admin revoke flow'];
    }
    return ['ok'=>false,'code'=>0,'response'=>'unsupported_provider'];
}

// DKIM helpers
// Given a PEM private key, derive the public key and return the DNS TXT value for a selector/domain.
function qp_mail_dkim_dns_record(string $selector, string $domain, string $privateKeyPem): string {
    if (empty($selector) || empty($domain) || empty($privateKeyPem)) return '';
    // Try to extract public key via OpenSSL
    if (function_exists('openssl_pkey_get_private') && function_exists('openssl_pkey_get_details')) {
        $priv = @openssl_pkey_get_private($privateKeyPem);
        if ($priv !== false) {
            $details = openssl_pkey_get_details($priv);
            if (!empty($details['key'])) {
                $pubPem = $details['key'];
                // strip header/footer and newlines
                $b64 = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pubPem);
                return "{$selector}._domainkey.{$domain}    TXT    \"v=DKIM1; k=rsa; p={$b64}\"";
            }
        }
    }
    // Fallback: attempt to extract from private key by stripping headers (not ideal)
    $b64 = preg_replace('/-----BEGIN RSA PRIVATE KEY-----|-----END RSA PRIVATE KEY-----|-----BEGIN PRIVATE KEY-----|-----END PRIVATE KEY-----|\s+/', '', $privateKeyPem);
    return "{$selector}._domainkey.{$domain}    TXT    \"v=DKIM1; k=rsa; p={$b64}\"";
}

function qp_mail_validate_dkim_private(string $privateKeyPem): bool {
    if (empty($privateKeyPem)) return false;
    if (function_exists('openssl_pkey_get_private')) {
        $res = @openssl_pkey_get_private($privateKeyPem);
        if ($res === false) return false;
        @openssl_pkey_free($res);
        return true;
    }
    // Without OpenSSL we just do a basic PEM header check
    return (bool) preg_match('/-----BEGIN .*PRIVATE KEY-----/i', $privateKeyPem);
}

// Check DKIM DNS TXT record for selector/domain. Returns array with keys: ok, record, details
function qp_mail_check_dkim_dns(string $selector, string $domain): array {
    $selector = trim($selector);
    $domain = trim($domain);
    if (empty($selector) || empty($domain)) return ['ok'=>false,'record'=>null,'details'=>'empty selector or domain'];
    $name = $selector . '._domainkey.' . $domain;
    if (function_exists('dns_get_record')) {
        $recs = @dns_get_record($name, DNS_TXT);
        if ($recs === false) return ['ok'=>false,'record'=>null,'details'=>'dns_get_record failed'];
        foreach ($recs as $r) {
            $txt = '';
            if (isset($r['txt'])) $txt = is_array($r['txt']) ? implode('', $r['txt']) : $r['txt'];
            if ($txt !== '' && stripos($txt, 'v=DKIM1') !== false) {
                return ['ok'=>true,'record'=>$txt,'details'=>'found'];
            }
        }
        return ['ok'=>false,'record'=>null,'details'=>'no DKIM TXT found'];
    }
    return ['ok'=>false,'record'=>null,'details'=>'dns_get_record unavailable'];
}

// Suggest an SPF record string for a domain. Accepts optional include domains (e.g. sendgrid.net, _spf.google.com)
function qp_mail_spf_record(string $domain, array $includes = []): string {
    $parts = ['v=spf1', 'mx'];
    foreach ($includes as $inc) {
        $inc = trim($inc);
        if ($inc !== '') $parts[] = 'include:' . $inc;
    }
    $parts[] = '~all';
    return implode(' ', $parts);
}

// Suggest a DMARC DNS TXT record line for a domain. Returns the TXT record content (not automatically added).
function qp_mail_dmarc_record(string $domain, string $rua = '', string $policy = 'quarantine', int $pct = 100): string {
    $d = trim($domain);
    if ($d === '') return '';
    $ruaPart = $rua !== '' ? 'rua=mailto:' . $rua . '; ' : '';
    $policy = in_array($policy, ['none','quarantine','reject']) ? $policy : 'quarantine';
    return "_dmarc.{$d}    TXT    \"v=DMARC1; p={$policy}; pct={$pct}; {$ruaPart}fo=1\"";
}

// Send a decoded payload directly (used by queue worker). Payload format: ['to','subject','message','headers','attachments','transport']
function qp_mail_send_payload(array $payload): bool {
    $settings = qp_mail_get_settings();
    $transport = $payload['transport'] ?? ($settings['transport'] ?? 'mail');
    $to = $payload['to'] ?? '';
    $subject = $payload['subject'] ?? '';
    $message = $payload['message'] ?? '';
    $headers = $payload['headers'] ?? [];
    $attachments = $payload['attachments'] ?? [];
    [$header_lines, $header_map] = qp_mail_normalize_headers($headers);
    $text = isset($payload['text']) && is_string($payload['text']) && trim($payload['text']) !== '' ? $payload['text'] : qp_mail_given_text($headers);
    $is_html = qp_mail_message_is_html((string)$message, $header_map, true);

    $debugLines = [];
    try {
        $mail = qp_mail_prepare_phpmailer($settings, $transport);
        qp_mail_capture_debug($mail, $debugLines);
        if (is_array($to)) foreach ($to as $r) $mail->addAddress($r); else $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $message;
        if (!$is_html) $mail->isHTML(false); // a queued text/plain message stays a single plain part
        qp_mail_set_alt_body($mail, $text);
        if (!empty($attachments) && is_array($attachments)) foreach ($attachments as $a) if (is_string($a) && file_exists($a)) $mail->addAttachment($a);
        $sent = $mail->send();
        qp_mail_log(['to'=>$to,'subject'=>$subject,'message'=>$message,'headers'=>$headers,'attachments'=>$attachments,'transport'=>$transport,'status'=>$sent ? 'sent' : 'failed','error' => $sent ? '' : $mail->ErrorInfo,'meta'=>['debug'=>implode("\n", $debugLines)]]);
        return (bool)$sent;
    } catch (Throwable $e) {
        // If PHPMailer not available or send failed, fallback to mail()
        // Try SendGrid Web API if configured
        $sg_key = $settings['sendgrid_api_key'] ?? '';
        if (!empty($sg_key)) {
            try {
                $sgres = qp_mail_send_via_sendgrid_api(['to'=>$to,'subject'=>$subject,'message'=>$message,'text'=>$text,'is_html'=>$is_html,'attachments'=>$attachments], $sg_key, $settings);
                $ok = is_array($sgres) ? ($sgres['ok'] ?? false) : (bool)$sgres;
                $debug = 'sendgrid_api_attempt';
                if (is_array($sgres)) $debug .= ' code=' . ($sgres['code'] ?? '') . ' resp=' . ($sgres['response'] ?? '');
                qp_mail_log(['to'=>$to,'subject'=>$subject,'message'=>$message,'headers'=>$headers,'attachments'=>$attachments,'transport'=>'sendgrid_api','status'=>$ok ? 'sent' : 'failed','error'=>$ok? '' : ($sgres['response'] ?? $e->getMessage()),'meta'=>['debug'=>$debug]]);
                return (bool)$ok;
            } catch (Throwable $_e) { /* continue to fallback */ }
        }
        try {
            $to_str = is_array($to) ? implode(',', $to) : $to;
            if ($is_html) {
                [$alt_headers, $alt_body] = qp_mail_alternative_mime((string)$message, $text, $header_lines, $settings['charset'] ?? 'UTF-8');
                $ok = @mail($to_str, $subject, $alt_body, $alt_headers);
            } else {
                $ok = @mail($to_str, $subject, $message, !empty($header_lines) ? implode("\r\n", $header_lines) : '');
            }
            qp_mail_log(['to'=>$to,'subject'=>$subject,'message'=>$message,'headers'=>$headers,'attachments'=>$attachments,'transport'=>'mail','status'=>$ok ? 'sent' : 'failed','error'=>$ok? '': $e->getMessage(),'meta'=>['debug'=>implode("\n", $debugLines)]]);
            return (bool)$ok;
        } catch (Throwable $_e) {
            qp_mail_log(['to'=>$to,'subject'=>$subject,'message'=>$message,'headers'=>$headers,'attachments'=>$attachments,'transport'=>'mail','status'=>'failed','error'=>$_e->getMessage(),'meta'=>['debug'=>implode("\n", $debugLines)]]);
            return false;
        }
    }
}

// Send via SendGrid Web API (v3). Accepts payload with to (string or array), subject, message, attachments,
// and optionally text (own plain-text version) and is_html (false = send message as text/plain only).
function qp_mail_send_via_sendgrid_api(array $payload, string $api_key, array $settings = [], int $max_attempts = 3): array {
    $json = json_encode(qp_mail_sendgrid_body($payload, $settings));

    $attempt = 0;
    $lastResp = null;
    while ($attempt < $max_attempts) {
        $attempt++;
        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $lastResp = ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'response' => $resp, 'attempts' => $attempt];

        // Retry on 429 or 5xx
        if ($lastResp['ok']) break;
        if ($code === 429 || ($code >= 500 && $code < 600)) {
            $wait = pow(2, $attempt);
            sleep(min($wait, 30));
            continue;
        }
        break;
    }

    return $lastResp ?: ['ok'=>false,'code'=>0,'response'=>null,'attempts'=>$attempt];
}

// The SendGrid v3 request body for a payload (split out so it can be checked without a network call).
function qp_mail_sendgrid_body(array $payload, array $settings = []): array {
    $to = $payload['to'] ?? '';
    $subject = $payload['subject'] ?? '';
    $message = $payload['message'] ?? '';
    $from = qp_mail_default_from($settings);
    $personalizations = [];
    if (is_array($to)) {
        $tos = array_map(function($t){ return ['email'=> (string)$t]; }, $to);
        $personalizations[] = ['to'=>$tos];
    } else {
        $personalizations[] = ['to'=>[['email'=> (string)$to]]];
    }
    // SendGrid wants text/plain before text/html in `content`.
    if (($payload['is_html'] ?? true) === false) {
        $content = [['type'=>'text/plain','value'=>$message]];
    } else {
        $text = isset($payload['text']) && is_string($payload['text']) && trim($payload['text']) !== '' ? $payload['text'] : qp_mail_text_version((string)$message);
        $content = $text !== '' ? [['type'=>'text/plain','value'=>$text], ['type'=>'text/html','value'=>$message]] : [['type'=>'text/html','value'=>$message]];
    }
    $body = ['personalizations'=>$personalizations, 'from'=>['email'=>$from], 'subject'=>$subject, 'content'=>$content];

    // Attachments handling: accept array of ['path'=>'/abs/file','filename'=>'name.ext'] or ['name'=>'file.txt','content'=>'base64...']
    if (!empty($payload['attachments']) && is_array($payload['attachments'])) {
        $body['attachments'] = [];
        foreach ($payload['attachments'] as $att) {
            if (is_string($att) && file_exists($att)) {
                $content = base64_encode(file_get_contents($att));
                $body['attachments'][] = ['content'=>$content, 'filename'=>basename($att)];
            } elseif (is_array($att)) {
                if (!empty($att['path']) && file_exists($att['path'])) {
                    $content = base64_encode(file_get_contents($att['path']));
                    $body['attachments'][] = ['content'=>$content, 'filename'=>$att['filename'] ?? basename($att['path'])];
                } elseif (!empty($att['content']) && !empty($att['name'])) {
                    $content = $att['is_base64'] ?? false ? $att['content'] : base64_encode($att['content']);
                    $body['attachments'][] = ['content'=>$content, 'filename'=>$att['name']];
                }
            }
        }
    }

    return $body;
}

// Process queue: fetch eligible items and attempt to send them. Returns summary array.
function qp_mail_process_queue(int $limit = 20, int $max_attempts = 5, ?int $rate_limit_per_minute = null): array {
    global $pdo;
    $summary = ['processed'=>0,'sent'=>0,'failed'=>0,'errors'=>[]];
    if (empty($pdo)) return $summary;
    try {
        // Optional rate limit check
        if ($rate_limit_per_minute && $rate_limit_per_minute > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . qp_mail_table('log') . " WHERE status = 'sent' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 MINUTE)");
            $stmt->execute();
            $sent_last_min = intval($stmt->fetchColumn());
            if ($sent_last_min >= $rate_limit_per_minute) {
                return $summary; // nothing to do now
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM ' . qp_mail_table('queue') . ' WHERE next_attempt_at <= UTC_TIMESTAMP() ORDER BY next_attempt_at ASC LIMIT ?');
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $summary['processed']++;
            $id = (int)$row['id'];
            $payload = json_decode($row['payload'], true) ?: [];
            $attempts = (int)$row['attempts'];
            // Attempt send within transaction to avoid races
            try {
                $pdo->beginTransaction();
                // lock this row by selecting for update
                $lock = $pdo->prepare('SELECT attempts FROM ' . qp_mail_table('queue') . ' WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $cur = $lock->fetchColumn();
                if ($cur === false) { $pdo->commit(); continue; }
                $sent = qp_mail_send_payload($payload);
                if ($sent) {
                    $del = $pdo->prepare('DELETE FROM ' . qp_mail_table('queue') . ' WHERE id = ?');
                    $del->execute([$id]);
                    $summary['sent']++;
                } else {
                    $attempts++;
                    if ($attempts >= $max_attempts) {
                        // move to logs as failed permanently (qp_mail_log already recorded), and remove from queue
                        $del = $pdo->prepare('DELETE FROM ' . qp_mail_table('queue') . ' WHERE id = ?');
                        $del->execute([$id]);
                        $summary['failed']++;
                    } else {
                        $backoff = pow(2, $attempts) * 60; // seconds
                        $next = gmdate('Y-m-d H:i:s', time() + $backoff);
                        $upd = $pdo->prepare('UPDATE ' . qp_mail_table('queue') . ' SET attempts = ?, next_attempt_at = ? WHERE id = ?');
                        $upd->execute([$attempts, $next, $id]);
                        $summary['failed']++;
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $_) {}
                $summary['errors'][] = $e->getMessage();
                $summary['failed']++;
            }
            // check rate limit per minute between items
            if ($rate_limit_per_minute && $rate_limit_per_minute > 0) {
                $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM " . qp_mail_table('log') . " WHERE status = 'sent' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 MINUTE)");
                $stmt2->execute();
                $sent_last_min = intval($stmt2->fetchColumn());
                if ($sent_last_min >= $rate_limit_per_minute) break;
            }
        }
    } catch (Throwable $e) {
        $summary['errors'][] = $e->getMessage();
    }
    return $summary;
}

?>
