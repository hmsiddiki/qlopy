<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/webhooks.php
// Outgoing webhooks (core upgrade plan C4): an event registry, signed deliveries, retries through the C2 task queue, a delivery log.
// Loaded from auth.php only while at least one subscription exists (option `qp_webhooks`), so a site without webhooks pays nothing.
//
// For plugins:  add_filter('qp_webhook_events', fn($e) => $e + ['order.paid' => 'An order was paid']);
//               qp_webhook_emit('order.paid', ['id' => 123]);      // does nothing when no active subscription wants it
// A delivery is a POST with a JSON body {id, event, created, data} and the headers
//   X-QP-Event, X-QP-Delivery (unique id), X-QP-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>" with the subscription secret>
// Receivers should reject a timestamp more than five minutes old. A delivery counts as done on any 2xx answer; otherwise it is retried
// after 1 min, 5 min, 30 min, 2 h and 6 h, then dropped and logged. A subscription that fails 20 deliveries in a row is switched off.

const QP_WEBHOOKS_OPTION = 'qp_webhooks';
const QP_WEBHOOK_RETRY = [60, 300, 1800, 7200, 21600];

function qp_webhook_events(): array {
    $e = [
        'post.saved' => 'A post, page or other content was created or changed', 'post.deleted' => 'Content was deleted',
        'term.created' => 'A category, tag or other term was created', 'term.updated' => 'A term was changed', 'term.deleted' => 'A term was deleted',
        'comment.created' => 'A comment was submitted', 'comment.status_changed' => 'A comment was approved, marked spam or trashed', 'user.registered' => 'A user account was created',
    ];
    $f = function_exists('apply_filters') ? apply_filters('qp_webhook_events', $e) : $e;
    return is_array($f) ? $f : $e;
}

function qp_webhook_subscriptions(): array {
    $v = get_option_meta(QP_WEBHOOKS_OPTION);
    return is_array($v) ? $v : [];
}

function qp_webhook_save_subscriptions(array $subs): void { update_option_meta(QP_WEBHOOKS_OPTION, $subs); }

/** Is this URL one we may call? Returns [ok, message, ip to pin]. Public https only, unless the owner allowed private addresses. */
function qp_webhook_url_check(string $url): array {
    $allowPrivate = function_exists('qp_rest_settings') ? !empty(qp_rest_settings()['webhook_allow_private']) : false;
    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['scheme']) || !in_array(strtolower($p['scheme']), ['https', 'http'], true)) return [false, 'Use a full http(s) address.', null];
    if (strtolower($p['scheme']) === 'http' && !$allowPrivate) return [false, 'Webhook addresses must use https.', null];
    if (!empty($p['user']) || !empty($p['pass'])) return [false, 'No credentials in the address.', null];
    $host = trim($p['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return [false, 'The host name does not resolve.', null];
    foreach ($ips as $ip) {
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if (!$public && !$allowPrivate) return [false, 'That address points to a private or reserved network.', null];
    }
    return [true, '', $ips[0]];
}

function qp_webhook_secret_new(): string { return 'whsec_' . bin2hex(random_bytes(20)); }

/** Add or replace a subscription. Returns [id, error]. */
function qp_webhook_subscribe(string $url, array $events, ?string $id = null): array {
    [$ok, $msg] = qp_webhook_url_check($url);
    if (!$ok) return [null, $msg];
    $known = array_keys(qp_webhook_events());
    $events = array_values(array_intersect($known, $events));
    if (!$events) return [null, 'Choose at least one event.'];
    $subs = qp_webhook_subscriptions();
    $id = $id && isset($subs[$id]) ? $id : bin2hex(random_bytes(5));
    $subs[$id] = ($subs[$id] ?? ['secret' => qp_webhook_secret_new(), 'created' => time(), 'fails' => 0]) + [];
    $subs[$id] = array_merge($subs[$id], ['url' => $url, 'events' => $events, 'active' => true, 'fails' => 0]);
    qp_webhook_save_subscriptions($subs);
    return [$id, null];
}

function qp_webhook_unsubscribe(string $id): bool {
    $subs = qp_webhook_subscriptions();
    if (!isset($subs[$id])) return false;
    unset($subs[$id]);
    qp_webhook_save_subscriptions($subs);
    return true;
}

// ---------------------------------------------------------------- queue (a small file; the C2 task only wakes the sender)

function qp_webhook_queue_file(): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    $d = dirname(__DIR__) . '/' . ($cfg['uploads_dir'] ?? 'uploads') . '/webhooks';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d . '/queue.json';
}

function qp_webhook_queue_update(callable $fn): array {
    $fh = @fopen(qp_webhook_queue_file(), 'c+');
    if (!$fh) return [];
    $out = [];
    if (flock($fh, LOCK_EX)) {
        $cur = json_decode((string)stream_get_contents($fh), true);
        $cur = is_array($cur) ? $cur : [];
        $new = $fn($cur);
        if (is_array($new)) { ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($new)); $out = $new; } else $out = $cur;
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $out;
}

function qp_webhook_wake(int $when): void {
    if (!function_exists('qp_queue_task')) return;
    if (function_exists('qp_remove_tasks_by_hook')) qp_remove_tasks_by_hook('qp_webhook_flush');   // one pending wake-up, always the earliest
    qp_queue_task('qp_webhook_flush', $when, [], 3, 60);
}

/** Queue a delivery for every active subscription that wants this event. Cheap and safe to call from any hook. */
function qp_webhook_emit(string $event, array $data): int {
    $subs = qp_webhook_subscriptions();
    $targets = [];
    foreach ($subs as $id => $s) if (!empty($s['active']) && in_array($event, (array)$s['events'], true)) $targets[] = $id;
    if (!$targets) return 0;
    $now = time();
    qp_webhook_queue_update(function ($q) use ($targets, $event, $data, $now) {
        foreach ($targets as $sub) $q[] = ['id' => bin2hex(random_bytes(8)), 'sub' => $sub, 'event' => $event, 'data' => $data, 'created' => $now, 'attempt' => 0, 'next' => $now];
        return array_slice($q, -2000);
    });
    qp_webhook_wake($now);
    return count($targets);
}

function qp_webhook_log(array $entry): void {
    $log = get_option_meta('qp_webhook_log');
    $log = is_array($log) ? $log : [];
    array_unshift($log, $entry + ['t' => time()]);
    update_option_meta('qp_webhook_log', array_slice($log, 0, 100));
}

/** Send one delivery. Returns [http code, error text]. */
function qp_webhook_send(array $sub, array $item): array {
    [$ok, $msg, $ip] = qp_webhook_url_check((string)$sub['url']);
    if (!$ok) return [0, $msg];
    $body = json_encode(['id' => $item['id'], 'event' => $item['event'], 'created' => $item['created'], 'data' => $item['data']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $t = time();
    $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, (string)$sub['secret']);
    $p = parse_url((string)$sub['url']);
    $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
    $c = curl_init((string)$sub['url']);
    curl_setopt_array($c, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => [$p['host'] . ':' . $port . ':' . $ip],   // connect to the address that was checked (no DNS rebinding)
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: Qlopy-Webhook/1', 'X-QP-Event: ' . $item['event'], 'X-QP-Delivery: ' . $item['id'], 'X-QP-Signature: ' . $sig],
    ]);
    curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);
    return [$code, $err];
}

/** The queue task: send what is due (at most 20 deliveries or 20 seconds), schedule the next wake-up. */
function qp_webhook_flush(): array {
    $start = microtime(true); $sent = 0; $now = time();
    $due = [];
    qp_webhook_queue_update(function ($q) use (&$due, $now) {
        foreach ($q as $i => $it) if (count($due) < 20 && $it['next'] <= $now) { $due[] = $it; unset($q[$i]); }
        return array_values($q);   // due items are taken out; a failed one is put back below
    });
    $subs = qp_webhook_subscriptions();
    $back = [];
    foreach ($due as $it) {
        if (microtime(true) - $start > 20) { $back[] = $it; continue; }
        $sub = $subs[$it['sub']] ?? null;
        if (!$sub || empty($sub['active'])) continue;
        $t0 = microtime(true);
        [$code, $err] = qp_webhook_send($sub, $it);
        $ms = (int)((microtime(true) - $t0) * 1000);
        $sent++;
        if ($code >= 200 && $code < 300) {
            qp_webhook_log(['sub' => $it['sub'], 'event' => $it['event'], 'delivery' => $it['id'], 'status' => 'ok', 'code' => $code, 'ms' => $ms, 'attempt' => $it['attempt'] + 1]);
            if (($subs[$it['sub']]['fails'] ?? 0) > 0) { $subs[$it['sub']]['fails'] = 0; qp_webhook_save_subscriptions($subs); }
            continue;
        }
        $subs[$it['sub']]['fails'] = (int)($subs[$it['sub']]['fails'] ?? 0) + 1;
        if ($subs[$it['sub']]['fails'] >= 20) { $subs[$it['sub']]['active'] = false; }
        qp_webhook_save_subscriptions($subs);
        $it['attempt']++;
        if ($it['attempt'] > count(QP_WEBHOOK_RETRY)) {
            qp_webhook_log(['sub' => $it['sub'], 'event' => $it['event'], 'delivery' => $it['id'], 'status' => 'failed', 'code' => $code, 'ms' => $ms, 'error' => $err ?: 'HTTP ' . $code, 'attempt' => $it['attempt']]);
            continue;
        }
        $it['next'] = time() + QP_WEBHOOK_RETRY[$it['attempt'] - 1];
        qp_webhook_log(['sub' => $it['sub'], 'event' => $it['event'], 'delivery' => $it['id'], 'status' => 'retry', 'code' => $code, 'ms' => $ms, 'error' => $err ?: 'HTTP ' . $code, 'attempt' => $it['attempt'], 'next' => $it['next']]);
        $back[] = $it;
    }
    $earliest = null;
    $q = qp_webhook_queue_update(function ($q) use ($back) { return array_merge($q, $back); });
    foreach ($q as $it) if ($earliest === null || $it['next'] < $earliest) $earliest = (int)$it['next'];
    if ($earliest !== null) qp_webhook_wake(max($earliest, time()));
    return ['sent' => $sent, 'pending' => count($q)];
}

/** Send a signed test event to one subscription right now (no queue); used by the admin screen. Returns [code, error]. */
function qp_webhook_test(string $id): array {
    $subs = qp_webhook_subscriptions();
    if (!isset($subs[$id])) return [0, 'Unknown subscription.'];
    return qp_webhook_send($subs[$id], ['id' => bin2hex(random_bytes(8)), 'event' => 'ping', 'created' => time(), 'data' => ['message' => 'Test delivery from Qlopy']]);
}

// ---------------------------------------------------------------- core events

function qp_webhooks_boot(): void {
    if (function_exists('add_qp_cron_action')) add_qp_cron_action('qp_webhook_flush', 'qp_webhook_flush');
    add_action('save_post', function ($id) {
        $st = db()->prepare('SELECT id, post_type, slug, title, status FROM ' . table_name('posts') . ' WHERE id = ?'); $st->execute([(int)$id]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) qp_webhook_emit('post.saved', ['id' => (int)$p['id'], 'type' => $p['post_type'], 'slug' => $p['slug'], 'title' => $p['title'], 'status' => $p['status']]);
    });
    add_action('delete_post', function ($id) { qp_webhook_emit('post.deleted', ['id' => (int)$id]); });
    add_action('create_term', function ($id, $tax = '') { qp_webhook_emit('term.created', ['id' => (int)$id, 'taxonomy' => (string)$tax]); }, 10, 2);
    add_action('edit_term', function ($id, $tax = '') { qp_webhook_emit('term.updated', ['id' => (int)$id, 'taxonomy' => (string)$tax]); }, 10, 2);
    add_action('delete_term', function ($id, $tax = '') { qp_webhook_emit('term.deleted', ['id' => (int)$id, 'taxonomy' => (string)$tax]); }, 10, 2);
    add_action('comment_inserted', function ($id, $row = []) { qp_webhook_emit('comment.created', ['id' => (int)$id, 'object_type' => $row['object_type'] ?? '', 'object_id' => (int)($row['object_id'] ?? 0), 'status' => $row['status'] ?? '']); }, 10, 2);
    add_action('comment_status_changed', function ($id, $new = '', $old = '') { qp_webhook_emit('comment.status_changed', ['id' => (int)$id, 'status' => (string)$new, 'previous' => (string)$old]); }, 10, 3);
    add_action('user_registered', function ($id) { qp_webhook_emit('user.registered', ['id' => (int)$id]); });
}

qp_webhooks_boot();
