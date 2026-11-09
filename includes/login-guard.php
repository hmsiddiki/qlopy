<?php
/**
 * Sign-in protection and the visitor's real IP.
 *
 * Loaded only when a password is checked (login_user / login_user_versatile in auth.php) and by the REST API for the client IP,
 * so ordinary page views never include it.
 *
 * Limits (filter `qp_login_guard_limits`), all counted over a 15 minute window:
 *  - 5 failures from one IP: each further try has to wait 30 s after the previous failure;
 *  - 10 failures from one IP: that IP is refused for 15 minutes after its last failure;
 *  - 20 failures on one account from any IPs: the account is refused for 15 minutes, except from an IP that signed in to it
 *    successfully in the last 90 days (so a bot cannot lock the owner out from their usual connection).
 * A refused try is answered before the password is checked, so a flood costs less than an ordinary failed sign-in.
 * A successful sign-in clears the counters of its IP and its account.
 *
 * Every failure and every success is written to the `login_log` table (IP, account, the name typed, reason; never the password).
 * Failures are kept 30 days, successes 90 days.
 */

defined('QLOPY_INIT') || exit;

/** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). Requests from these may carry CF-Connecting-IP. */
const QP_CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
    '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/** A bcrypt hash of a random string (cost 10, like the site's own), checked when the account does not exist so both take as long. */
const QP_LOGIN_DUMMY_HASH = '$2y$10$BYp2MWKygNPIVMkPi2jhAuyEJ6S38G10SJhLEjcTBlJm5mBhrW6na';

const QP_LOGIN_GUARD_SCHEMA = 1;

/** True when $ip is inside $cidr (IPv4 or IPv6; a bare address matches only itself). */
function qp_ip_in_cidr(string $ip, string $cidr): bool {
    $parts = explode('/', $cidr, 2);
    $a = @inet_pton($ip);
    $b = @inet_pton($parts[0]);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = isset($parts[1]) ? (int)$parts[1] : strlen($a) * 8;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && strncmp($a, $b, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = (0xff << (8 - $rem)) & 0xff;

    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

/** @param string[] $ranges */
function qp_ip_in_ranges(string $ip, array $ranges): bool {
    foreach ($ranges as $r) {
        if (qp_ip_in_cidr($ip, (string)$r)) return true;
    }
    return false;
}

/**
 * The visitor's IP. Behind Cloudflare REMOTE_ADDR is a Cloudflare edge, so CF-Connecting-IP is used, but only when the request
 * really comes from a Cloudflare range (anyone else could send that header). Next, the first X-Forwarded-For entry when the
 * config says `trust_proxy`. Otherwise REMOTE_ADDR. Filter `qp_trusted_proxy_ranges` adds or replaces the Cloudflare ranges.
 */
function qp_client_ip(): string {
    static $ip = null;
    if ($ip !== null && PHP_SAPI !== 'cli') return $ip;
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $found = $remote;
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    $ranges = function_exists('apply_filters') ? (array)apply_filters('qp_trusted_proxy_ranges', QP_CLOUDFLARE_RANGES) : QP_CLOUDFLARE_RANGES;
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && $remote !== '' && qp_ip_in_ranges($remote, $ranges)) {
        $found = $cf;
    } else {
        $cfg = function_exists('get_config') ? get_config() : [];
        if (!empty($cfg['trust_proxy']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) $found = $first;
        }
    }

    return $ip = $found;
}

/** @return array{window: int, slow_after: int, slow_wait: int, ip_max: int, user_max: int, trusted_days: int} */
function qp_login_guard_limits(): array {
    $d = ['window' => 900, 'slow_after' => 5, 'slow_wait' => 30, 'ip_max' => 10, 'user_max' => 20, 'trusted_days' => 90];
    $l = function_exists('apply_filters') ? apply_filters('qp_login_guard_limits', $d) : $d;

    return is_array($l) ? array_map('intval', array_merge($d, array_intersect_key($l, $d))) : $d;
}

function qp_login_log_table(): string { return table_name('login_log'); }

function qp_login_guard_install(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('get_option_meta') && (int)get_option_meta('qp_login_guard_schema') >= QP_LOGIN_GUARD_SCHEMA) return;
    db()->exec('CREATE TABLE IF NOT EXISTS `' . qp_login_log_table() . '` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ip` VARCHAR(45) NOT NULL DEFAULT \'\',
        `user_id` BIGINT UNSIGNED DEFAULT NULL,
        `login` VARCHAR(100) NOT NULL DEFAULT \'\',
        `reason` VARCHAR(20) NOT NULL DEFAULT \'\',
        `cleared` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` INT NOT NULL,
        PRIMARY KEY (`id`),
        INDEX `ip_time` (`ip`, `created_at`),
        INDEX `user_time` (`user_id`, `created_at`),
        INDEX `time` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (function_exists('update_option_meta')) update_option_meta('qp_login_guard_schema', QP_LOGIN_GUARD_SCHEMA);
}

/** Seconds of the last refusal (0 = not refused), for the sign-in forms' message. */
function qp_login_guard_retry_after(?int $set = null): int {
    static $wait = 0;
    if ($set !== null) $wait = max(0, $set);

    return $wait;
}

/** The message for a refused try, or '' when the last try was not refused. */
function qp_login_guard_message(): string {
    $w = qp_login_guard_retry_after();
    if ($w <= 0) return '';
    if ($w < 60) return 'Too many failed sign-ins. Please try again in ' . $w . ' seconds.';
    $m = (int)ceil($w / 60);

    return 'Too many failed sign-ins. Please try again in ' . $m . ' minute' . ($m === 1 ? '' : 's') . '.';
}

/**
 * Seconds this IP (and, when known, this account) has to wait before another try; 0 = go ahead. Never blocks when the log cannot
 * be read (a database problem must not lock everyone out).
 */
function qp_login_guard_wait(?int $userId = null): int {
    $wait = 0;
    try {
        qp_login_guard_install();
        $L = qp_login_guard_limits();
        $now = time();
        $since = $now - $L['window'];
        $ip = qp_client_ip();
        $t = qp_login_log_table();
        $st = db()->prepare("SELECT COUNT(*), MAX(created_at) FROM {$t} WHERE ip = ? AND cleared = 0 AND reason <> 'success' AND created_at >= ?");
        $st->execute([$ip, $since]);
        [$n, $last] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $n = (int)$n; $last = (int)$last;
        if ($n >= $L['ip_max']) {
            $wait = $last + $L['window'] - $now;
        } elseif ($n >= $L['slow_after']) {
            $wait = $last + $L['slow_wait'] - $now;
        }
        if ($userId !== null && $userId > 0) {
            $st = db()->prepare("SELECT COUNT(*), MAX(created_at) FROM {$t} WHERE user_id = ? AND cleared = 0 AND reason <> 'success' AND created_at >= ?");
            $st->execute([$userId, $since]);
            [$un, $ulast] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            if ((int)$un >= $L['user_max']) {
                $trusted = db()->prepare("SELECT 1 FROM {$t} WHERE user_id = ? AND ip = ? AND reason = 'success' AND created_at >= ? LIMIT 1");
                $trusted->execute([$userId, $ip, $now - $L['trusted_days'] * 86400]);
                if (!$trusted->fetchColumn()) $wait = max($wait, (int)$ulast + $L['window'] - $now);
            }
        }
    } catch (Throwable $e) {
        error_log('login guard: ' . $e->getMessage());
        $wait = 0;
    }
    $wait = max(0, $wait);
    qp_login_guard_retry_after($wait);
    if ($wait > 0 && function_exists('do_action')) do_action('qp_login_refused', qp_client_ip(), $userId, $wait);

    return $wait;
}

/** One row in the log. $reason: success, unknown (no such account), password, not_allowed (right password, account may not use this form). */
function qp_login_guard_record(string $login, ?int $userId, string $reason): void {
    try {
        qp_login_guard_install();
        $t = qp_login_log_table();
        $pdo = db();
        $ip = qp_client_ip();
        $now = time();
        if ($reason === 'success' && $userId) {
            $pdo->prepare("UPDATE {$t} SET cleared = 1 WHERE cleared = 0 AND (ip = ? OR user_id = ?)")->execute([$ip, $userId]);
        }
        $pdo->prepare("INSERT INTO {$t} (ip, user_id, login, reason, cleared, created_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([substr($ip, 0, 45), $userId ?: null, mb_substr($login, 0, 100), $reason, $reason === 'success' ? 1 : 0, $now]);
        if (random_int(1, 50) === 1) {
            $pdo->prepare("DELETE FROM {$t} WHERE (reason <> 'success' AND created_at < ?) OR created_at < ?")
                ->execute([$now - 30 * 86400, $now - qp_login_guard_limits()['trusted_days'] * 86400]);
        }
    } catch (Throwable $e) {
        error_log('login guard: ' . $e->getMessage());
    }
    if (function_exists('do_action')) do_action($reason === 'success' ? 'qp_login_succeeded' : 'qp_login_failed', $login, $userId, $reason, qp_client_ip());
}

/**
 * The guarded password check behind login_user() and login_user_versatile(): refuses while this IP or account is locked, takes
 * as long for an unknown account as for a known one, and logs the result. $allow (optional) decides whether a correctly signed-in
 * account may use this form; a "no" is answered exactly like a wrong password, so the form never tells which accounts exist.
 *
 * @param callable(array): bool|null $allow
 * @return array{0: array, 1: int}|null the formatted user and its id, or null
 */
function qp_login_check_credentials(string $login, string $password, bool $byEmail, ?callable $allow = null): ?array {
    qp_login_guard_retry_after(0);
    if ($login === '' || $password === '') return null;
    $user = null;
    if ($byEmail && strpos($login, '@') !== false) {
        if (function_exists('get_user_by_email')) $user = get_user_by_email($login);
        elseif (function_exists('get_user_by')) $user = get_user_by('email', $login);
    }
    if (empty($user)) {
        if (function_exists('get_user_by_username')) $user = get_user_by_username($login);
        elseif (function_exists('get_user_by')) $user = get_user_by('login', $login);
    }
    $raw = is_array($user) ? (is_array($user['raw'] ?? null) ? $user['raw'] : $user) : null;
    $uid = $raw ? (int)($raw['id'] ?? $user['ID'] ?? 0) : 0;
    if (qp_login_guard_wait($uid > 0 ? $uid : null) > 0) return null;

    $hash = $raw ? (string)($raw['password_hash'] ?? $raw['password'] ?? '') : '';
    if ($uid <= 0 || $hash === '') {
        password_verify($password, QP_LOGIN_DUMMY_HASH);
        qp_login_guard_record($login, $uid > 0 ? $uid : null, 'unknown');
        return null;
    }
    if (!password_verify($password, $hash)) {
        qp_login_guard_record($login, $uid, 'password');
        return null;
    }
    if ($allow !== null && !$allow($user)) {
        qp_login_guard_record($login, $uid, 'not_allowed');
        return null;
    }
    qp_login_guard_record($login, $uid, 'success');

    return [$user, $uid];
}
