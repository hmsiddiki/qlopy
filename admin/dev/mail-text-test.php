<?php
// Dev tool (C11): every HTML email also carries a plain-text version (multipart/alternative). CLI only.
//   php admin/dev/mail-text-test.php
// Sends through a fake SMTP server on 127.0.0.1 started by this script (nothing leaves the machine), with the mail
// settings replaced in memory only (the stored option is not touched). Mail-log rows it writes are removed at the end.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ---- Child mode: a fake SMTP server that saves each DATA body to <out>.<n> --------------------------------------
if (($argv[1] ?? '') === '--smtp-server') {
    $out = $argv[2];
    $want = (int)$argv[3];
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$srv) { echo "ERR $errstr\n"; exit(1); }
    echo explode(':', stream_socket_get_name($srv, false))[1] . "\n";
    fflush(STDOUT);
    for ($n = 0; $n < $want; $n++) {
        $c = @stream_socket_accept($srv, 30);
        if (!$c) break;
        fwrite($c, "220 fake.test ESMTP\r\n");
        while (($line = fgets($c)) !== false) {
            $cmd = strtoupper(substr(trim($line), 0, 4));
            if ($cmd === 'EHLO') { fwrite($c, "250-fake.test\r\n250 8BITMIME\r\n"); }
            elseif ($cmd === 'DATA') {
                fwrite($c, "354 go\r\n");
                $data = '';
                while (($l = fgets($c)) !== false && rtrim($l, "\r\n") !== '.') $data .= $l;
                file_put_contents($out . '.' . $n, $data);
                fwrite($c, "250 OK queued\r\n");
            }
            elseif ($cmd === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
            else { fwrite($c, "250 OK\r\n"); }
        }
        fclose($c);
    }
    exit(0);
}

define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/qp-mail.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }

// Minimal MIME reader: [top headers (lowercase name => value), parts: list of [content-type, decoded body]].
function mime_parse(string $raw): array {
    $raw = str_replace("\r\n", "\n", $raw);
    [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
    $headers = [];
    foreach (explode("\n", preg_replace("/\n[ \t]+/", ' ', $head)) as $l) {
        if (strpos($l, ':') !== false) { [$k, $v] = explode(':', $l, 2); $headers[strtolower(trim($k))] = trim($v); }
    }
    $decode = function (string $enc, string $b) {
        $enc = strtolower(trim($enc));
        if ($enc === 'base64') return base64_decode($b);
        if ($enc === 'quoted-printable') return quoted_printable_decode($b);
        return $b;
    };
    $ct = $headers['content-type'] ?? '';
    if (stripos($ct, 'multipart/') === false) return [$headers, [[strtolower(strtok($ct, ';')), $decode($headers['content-transfer-encoding'] ?? '', $body)]]];
    preg_match('/boundary="?([^";]+)"?/i', $ct, $m);
    $parts = [];
    foreach (explode('--' . $m[1], $body) as $chunk) {
        $chunk = ltrim($chunk, "\n");
        if ($chunk === '' || strpos($chunk, '--') === 0 || stripos($chunk, 'content-type') === false) continue;
        [$ph, $pb] = array_pad(explode("\n\n", $chunk, 2), 2, '');
        $pht = [];
        foreach (explode("\n", preg_replace("/\n[ \t]+/", ' ', $ph)) as $l) {
            if (strpos($l, ':') !== false) { [$k, $v] = explode(':', $l, 2); $pht[strtolower(trim($k))] = trim($v); }
        }
        $parts[] = [strtolower(trim(strtok($pht['content-type'] ?? '', ';'))), $decode($pht['content-transfer-encoding'] ?? '', rtrim($pb, "\n"))];
    }
    return [$headers, $parts];
}

$link = 'https://shop.example.test/my-orders?view=addresses&confirm_email=8.0123abcd&copy=1';
$html = '<!doctype html><html><head><title>t</title><style>p{color:red}</style></head><body style="x">'
    . '<h1 style="font-size:20px;">New email address</h1>' . "\n"
    . '<p>You asked to use new@example.test &amp; more&nbsp;text.</p>'
    . '<p>Finish the change:<br><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
    . '<p>Visit <a href="https://shop.example.test/">our shop</a> or <a href="mailto:help@example.test">help@example.test</a>.</p>'
    . '<ul><li>One</li><li>Two</li></ul><script>alert(1)</script><!-- hidden --></body></html>';

// ---- 1. The converter -------------------------------------------------------------------------------------------
$t = qp_mail_text_version($html);
ok(strpos($t, '<') === false && strpos($t, 'color:red') === false && strpos($t, 'alert') === false && strpos($t, 'hidden') === false, 'text version has no tags, style, script or comments');
ok(strpos($t, "New email address\n\nYou asked") === 0, 'heading and paragraphs become separate lines');
ok(strpos($t, "Finish the change:\n" . $link) !== false, 'a link whose text is its address is kept once, exactly (&amp; decoded, &copy= not turned into ©)');
ok(strpos($t, 'our shop (https://shop.example.test/)') !== false, 'a labelled link keeps its address after the label');
ok(strpos($t, 'or help@example.test.') !== false && strpos($t, 'mailto:') === false, 'a mailto link shows just the address');
ok(strpos($t, 'new@example.test & more text.') !== false, 'entities and &nbsp; are decoded');
ok(strpos($t, "- One\n- Two") !== false, 'list items become "- " lines');
$GLOBALS['c11_filter_on'] = true;
add_filter('qp_mail_text_version', function ($text) { return !empty($GLOBALS['c11_filter_on']) ? 'FILTERED' : $text; });
ok(qp_mail_text_version('<p>x</p>') === 'FILTERED', 'filter qp_mail_text_version can replace the text');
$GLOBALS['c11_filter_on'] = false;

// ---- 2. HTML detection ------------------------------------------------------------------------------------------
ok(qp_mail_message_is_html('<p>x</p>', ['content-type' => 'text/plain; charset=UTF-8']) === false, 'explicit text/plain wins over an HTML-looking body');
ok(qp_mail_message_is_html('plain', ['content-type' => 'text/html']) === true, 'explicit text/html wins');
ok(qp_mail_message_is_html('Total < 5 and a > b', []) === false && qp_mail_message_is_html('<p>hi</p>', []) === true, 'without a header the body decides');
ok(qp_mail_message_is_html('plain', [], true) === true, 'without a header a given default is used (queue path keeps its HTML default)');

// ---- 3. mail() fallback MIME ------------------------------------------------------------------------------------
[$h, $b] = qp_mail_alternative_mime($html, null, ['From: Shop <shop@example.test>', 'Content-Type: text/html; charset=UTF-8', 'Reply-To: r@example.test'], 'UTF-8');
[$th, $parts] = mime_parse($h . "\r\n\r\n" . $b);
ok(stripos($th['content-type'] ?? '', 'multipart/alternative') === 0 && substr_count(strtolower($h), 'content-type') === 1, 'mail() fallback: one multipart/alternative Content-Type, the caller\'s text/html one removed');
ok(($th['from'] ?? '') === 'Shop <shop@example.test>' && ($th['reply-to'] ?? '') === 'r@example.test', 'mail() fallback: other caller headers kept');
ok(count($parts) === 2 && $parts[0][0] === 'text/plain' && $parts[1][0] === 'text/html', 'mail() fallback: text/plain part then text/html part');
ok(($parts[0][1] ?? '') === qp_mail_text_version($html) && ($parts[1][1] ?? '') === $html, 'mail() fallback: both parts decode back exactly');
ok(max(array_map('strlen', explode("\r\n", $b))) <= 998, 'mail() fallback: no body line over the SMTP limit');

// ---- 4. SendGrid request body -----------------------------------------------------------------------------------
$sg = qp_mail_sendgrid_body(['to' => 'a@example.test', 'subject' => 's', 'message' => $html], ['from_email' => 'shop@example.test']);
ok(array_column($sg['content'], 'type') === ['text/plain', 'text/html'] && $sg['content'][0]['value'] === qp_mail_text_version($html), 'SendGrid: text/plain first, then text/html');
$sg = qp_mail_sendgrid_body(['to' => 'a@example.test', 'subject' => 's', 'message' => $html, 'text' => 'Own text'], []);
ok($sg['content'][0]['value'] === 'Own text', 'SendGrid: a sender\'s own text is used');
$sg = qp_mail_sendgrid_body(['to' => 'a@example.test', 'subject' => 's', 'message' => 'Just text', 'is_html' => false], []);
ok(array_column($sg['content'], 'type') === ['text/plain'], 'SendGrid: a plain message stays one text/plain part');

// ---- 5. Real sends through PHPMailer (qp_mail direct, and the queue's qp_mail_send_payload) -----------------------
$tmp = sys_get_temp_dir() . '/qp-c11-' . bin2hex(random_bytes(4));
$sends = 7;
$proc = proc_open([PHP_BINARY, __FILE__, '--smtp-server', $tmp, (string)$sends], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$port = (int)trim((string)fgets($pipes[1]));
ok($port > 0, 'fake SMTP server started');
// Replace the settings for this process only (filter, nothing stored). Every key is set, so no real credential,
// SendGrid key or OAuth token from the site's settings can be used.
add_filter('qp_mail_settings', function () use ($port) {
    return [
        'transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_encryption' => '', 'smtp_auth' => 0,
        'from_email' => 'shop@example.test', 'from_name' => 'C11 Shop', 'use_queue' => 0,
    ];
}, 1000);
$active = qp_mail_get_settings();
if (($active['smtp_host'] ?? '') !== '127.0.0.1' || (int)($active['smtp_port'] ?? 0) !== $port || !empty($active['sendgrid_api_key'])) {
    // Never fall through to the site's real mail server.
    proc_terminate($proc);
    echo "FAIL the in-memory mail settings did not take effect; stopped before sending anything.\n";
    exit(1);
}
$subj = 'C11 test ' . bin2hex(random_bytes(3));
$sent = [
    qp_mail('a@example.test', $subj . ' html', $html, ['Content-Type' => 'text/html; charset=UTF-8']),
    qp_mail('a@example.test', $subj . ' own', $html, ['Content-Type' => 'text/html; charset=UTF-8', 'qp_text_body' => "Own text\n" . $link]),
    qp_mail('a@example.test', $subj . ' plain', "Line one\nLine two", ['Content-Type' => 'text/plain; charset=UTF-8']),
    qp_mail_send_payload(['to' => 'a@example.test', 'subject' => $subj . ' queued', 'message' => $html, 'headers' => ['Content-Type' => 'text/html; charset=UTF-8', 'qp_queue' => true]]),
    qp_mail_send_payload(['to' => 'a@example.test', 'subject' => $subj . ' queued plain', 'message' => "Plain <not a tag>", 'headers' => ['Content-Type' => 'text/plain; charset=UTF-8']]),
    qp_mail_send_payload(['to' => 'a@example.test', 'subject' => $subj . ' queued same domain', 'message' => $html, 'headers' => ['Content-Type' => 'text/html; charset=UTF-8', 'From' => 'Health n Mart <orders@example.test>', 'Cc' => 'copy@example.test', 'X-Qc-Order' => '42']]),
    qp_mail_send_payload(['to' => 'a@example.test', 'subject' => $subj . ' queued other domain', 'message' => $html, 'headers' => ['Content-Type' => 'text/html; charset=UTF-8', 'From' => 'Health n Mart <sales@other.test>']]),
];
proc_close($proc);
ok($sent === array_fill(0, $sends, true), 'all seven messages accepted by the fake server');
$msg = function (int $i) use ($tmp) { return is_file("$tmp.$i") ? mime_parse((string)file_get_contents("$tmp.$i")) : [[], []]; };

[$h0, $p0] = $msg(0);
ok(stripos($h0['content-type'] ?? '', 'multipart/alternative') === 0 && array_column($p0, 0) === ['text/plain', 'text/html'], 'qp_mail(): HTML goes out as multipart/alternative, text/plain first');
ok(trim($p0[0][1] ?? '') === qp_mail_text_version($html) && strpos($p0[0][1] ?? '', $link) !== false, 'qp_mail(): the text part is the generated text, link intact');
ok(!isset($h0['qp_text_body']) && !isset($h0['qp_queue']) && stripos(file_get_contents("$tmp.0"), 'qp_') === false, 'qp_mail(): internal qp_ keys never become headers');
[, $p1] = $msg(1);
ok(trim($p1[0][1] ?? '') === "Own text\n" . $link, 'qp_mail(): a sender\'s own text (qp_text_body) is used as given');
[$h2, $p2] = $msg(2);
ok(stripos($h2['content-type'] ?? '', 'text/plain') === 0 && count($p2) === 1 && trim($p2[0][1]) === "Line one\nLine two", 'qp_mail(): a text/plain message stays a single plain part');
[$h3, $p3] = $msg(3);
ok(stripos($h3['content-type'] ?? '', 'multipart/alternative') === 0 && array_column($p3, 0) === ['text/plain', 'text/html'] && trim($p3[0][1] ?? '') === qp_mail_text_version($html), 'queue (qp_mail_send_payload): HTML gets the same text part');
[$h4, $p4] = $msg(4);
ok(stripos($h4['content-type'] ?? '', 'text/plain') === 0 && count($p4) === 1 && trim($p4[0][1]) === 'Plain <not a tag>', 'queue: a text/plain message is now sent as plain text (it was sent as HTML before)');

[$h5] = $msg(5);
ok(stripos($h5['from'] ?? '', 'orders@example.test') !== false && stripos($h5['from'] ?? '', 'Health n Mart') !== false, 'queue: the caller\'s From (same domain as the sender) is used, name and address');
ok(stripos($h5['cc'] ?? '', 'copy@example.test') !== false && ($h5['x-qc-order'] ?? '') === '42', 'queue: Cc and custom headers are kept');
[$h6] = $msg(6);
ok(stripos($h6['from'] ?? '', 'shop@example.test') !== false && stripos($h6['from'] ?? '', 'Health n Mart') !== false, 'queue: a From on another domain keeps the configured address (SPF/DMARC) and uses the caller\'s name');
ok(stripos($h6['reply-to'] ?? '', 'sales@other.test') !== false, 'queue: the other-domain address becomes Reply-To');

for ($i = 0; $i < $sends; $i++) @unlink("$tmp.$i");
try {
    $ids = $pdo->prepare('SELECT id FROM ' . qp_mail_table('log') . ' WHERE subject LIKE ?');
    $ids->execute([$subj . '%']);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $logId) {
        $pdo->prepare('DELETE FROM ' . qp_mail_table('debug') . ' WHERE log_id = ?')->execute([(int)$logId]);
    }
    $del = $pdo->prepare('DELETE FROM ' . qp_mail_table('log') . ' WHERE subject LIKE ?');
    $del->execute([$subj . '%']);
    echo 'Removed ' . $del->rowCount() . " mail-log rows written by this test.\n";
} catch (Throwable $e) { echo 'Could not remove test log rows: ' . $e->getMessage() . "\n"; }

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
