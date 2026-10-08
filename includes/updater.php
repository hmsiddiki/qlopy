<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * includes/updater.php
 *
 * Initial skeleton for central updater helpers.
 * - uses project `uploads/updates` as root for downloads/backups/logs
 * - provides register helpers and get_* accessors reading `available_updates`
 * - includes basic SHA256 and RSA signature verification helpers
 *
 * Note: this is a minimal, safe starting point. Integrate with existing
 * site option APIs and qp-cron scheduling as next steps.
 */

if (!isset($GLOBALS['qlopy_update_checks'])) {
    $GLOBALS['qlopy_update_checks'] = [];
}

// Helper: determine project root (assumes includes/ is at project_root/includes)
function qlopy_project_root()
{
    return dirname(__DIR__);
}

// Uploads updates root: use root `uploads/updates` per instructions
function qlopy_updates_root()
{
    // Prefer site uploads helper or config values when available
    if (function_exists('qp_uploads_base')) {
        $base = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR);
    } elseif (!empty($GLOBALS['config']['uploads_dir'])) {
        $base = rtrim($GLOBALS['config']['uploads_dir'], DIRECTORY_SEPARATOR);
    } elseif (!empty($GLOBALS['config']['upload_dir'])) {
        $base = rtrim($GLOBALS['config']['upload_dir'], DIRECTORY_SEPARATOR);
    } else {
        $base = qlopy_project_root() . DIRECTORY_SEPARATOR . 'uploads';
    }
    $path = $base . DIRECTORY_SEPARATOR . 'updates';
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    return $path;
}

function qlopy_updates_tmp_dir()
{
    $d = qlopy_updates_root() . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function qlopy_updates_backups_dir()
{
    $d = qlopy_updates_root() . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function qlopy_updates_logs_dir()
{
    $d = qlopy_updates_root() . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function qlopy_updater_log($msg)
{
    // Respect updater setting `enable_logging` (default: false)
    if (function_exists('qlopy_get_updater_settings')) {
        $s = qlopy_get_updater_settings();
        if (empty($s['enable_logging'])) return; // logging disabled
    }
    $d = qlopy_updates_logs_dir();
    $file = $d . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
    $line = '[' . date('c') . '] ' . trim($msg) . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// Structured audit log for higher-level actions (backups/restores/exports/applies)
function qlopy_audit_log(string $action, array $meta = [])
{
    // Respect updater setting `enable_logging` (default: false)
    if (function_exists('qlopy_get_updater_settings')) {
        $s = qlopy_get_updater_settings();
        if (empty($s['enable_logging'])) return; // logging disabled
    }
    $d = qlopy_updates_logs_dir();
    $file = $d . DIRECTORY_SEPARATOR . 'audit.log';
    $entry = [
        'time' => date('c'),
        'action' => $action,
        'actor' => $_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? ($_SERVER['USER'] ?? 'unknown'),
        'meta' => $meta,
    ];
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// List backup directories with simple metadata
function qlopy_list_backups()
{
    $d = qlopy_updates_backups_dir();
    $dirs = array_filter(@glob($d . DIRECTORY_SEPARATOR . '*'), 'is_dir');
    rsort($dirs);
    $out = [];
    foreach ($dirs as $path) {
        $name = basename($path);
        $hasDb = is_file($path . DIRECTORY_SEPARATOR . 'db-backup.sql');
        $metaFile = $path . DIRECTORY_SEPARATOR . 'metadata.json';
        $meta = null;
        if (is_file($metaFile)) {
            $json = @file_get_contents($metaFile);
            $meta = json_decode($json, true);
        }
        $entry = [
            'name' => $name,
            'path' => $path,
            'has_db' => $hasDb,
            'label' => $meta['created_at'] ?? $name,
            'timestamp' => filemtime($path) ?: null,
            'metadata' => $meta,
        ];
        // Provide raw and site-formatted created_at for admin UI convenience
        $entry['created_at'] = $meta['created_at'] ?? null;
        $entry['created_at_formatted'] = (function_exists('format_site_datetime') ? format_site_datetime($entry['created_at']) : $entry['created_at']);
        $out[] = $entry;
    }
    return $out;
}


// Register/update check entry (generic)
function qlopy_register_update_check($type, $folder, $manifest_url, $current_version = '')
{
    if (!in_array($type, ['core', 'theme', 'plugin'])) return false;
    $entry = [
        'type' => $type,
        'folder' => $folder === null ? null : (string)$folder,
        'update_url' => (string)$manifest_url,
        'current_version' => (string)$current_version,
    ];
    $GLOBALS['qlopy_update_checks'][] = $entry;
    return true;
}

// Convenience functions for authors to call from their inc/updater.php
function set_plugin_updater($foldername, $current_version, $update_url)
{
    return qlopy_register_update_check('plugin', $foldername, $update_url, $current_version);
}

function set_theme_updater($foldername, $current_version, $update_url)
{
    return qlopy_register_update_check('theme', $foldername, $update_url, $current_version);
}

// Central init for core: uses defined constants if present
function central_init_update()
{
    if (defined('QLOPY_VERSION') && defined('qlopy_core_update_url')) {
            qlopy_register_update_check('core', null, qlopy_constant_or('qlopy_core_update_url'), QLOPY_VERSION);
    } else {
        // try alternative constant names (backwards compat)
        if (defined('QLOPY_VERSION') && defined('QLOPY_CORE_UPDATE_URL')) {
            qlopy_register_update_check('core', null, qlopy_constant_or('QLOPY_CORE_UPDATE_URL'), QLOPY_VERSION);
        }
    }
    // Discover theme and plugin updaters by scanning dynamic content directories
    $project = qlopy_project_root();
    // Try to get dynamic content dir names from config constants if available
    $content = defined('QLOPY_CONTENT_DIR') ? QLOPY_CONTENT_DIR : (defined('CONTENT_DIR') ? CONTENT_DIR : 'content');
    $themes = defined('QLOPY_THEMES_DIR') ? QLOPY_THEMES_DIR : (defined('THEMES_DIR') ? THEMES_DIR : 'themes');
    $plugins = defined('QLOPY_PLUGINS_DIR') ? QLOPY_PLUGINS_DIR : (defined('PLUGINS_DIR') ? PLUGINS_DIR : 'plugins');

    // scan themes
    $themeRoot = $project . DIRECTORY_SEPARATOR . $content . DIRECTORY_SEPARATOR . $themes;
    if (is_dir($themeRoot)) {
        foreach (scandir($themeRoot) as $theme) {
            if ($theme[0] === '.') continue;
            $inc = $themeRoot . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'updater.php';
            if (is_file($inc)) {
                // allow theme updater to call set_theme_updater()
                @include_once $inc;
            }
        }
    }

    // scan plugins
    $pluginRoot = $project . DIRECTORY_SEPARATOR . $content . DIRECTORY_SEPARATOR . $plugins;
    if (is_dir($pluginRoot)) {
        foreach (scandir($pluginRoot) as $plugin) {
            if ($plugin[0] === '.') continue;
            $inc = $pluginRoot . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'updater.php';
            if (is_file($inc)) {
                @include_once $inc;
            }
        }
    }
}

function qlopy_constant_or($name)
{
    return defined($name) ? constant($name) : null;
}

// Settings helpers for updater (stored via site option API if available, fallback to JSON file)
function qlopy_get_updater_settings()
{
    // Prefer site option API `get_option_meta` if available (returns JSON-decoded values)
    if (function_exists('get_option_meta')) {
        $s = @get_option_meta('qlopy_updater_settings');
        if (is_array($s)) return $s;
    }
    
    // defaults
    return [
        'checker_interval' => 21600, // 6 hours
        'initiator_interval' => 43200, // 12 hours
        'stop_auto_updates' => false,
        'allow_risky_migrations' => false,
        'enable_logging' => false,
        'require_signed_manifests' => false,
        'use_temporary_downloads' => true,
        'backup_retention' => 10,
    ];
}

function qlopy_set_updater_settings(array $settings)
{
    $defaults = qlopy_get_updater_settings();
    $merged = array_merge($defaults, $settings);
    // Prefer the site's option meta API when available (stores JSON in DB)
    if (function_exists('update_option_meta')) {
        return (bool)@update_option_meta('qlopy_updater_settings', $merged);
    }
   
}

// Available updates storage helpers
function qlopy_get_available_updates()
{
    // Try to use existing site option API if available
    // Prefer option meta storage if available
    if (function_exists('get_option_meta')) {
        $v = @get_option_meta('available_updates');
        if ($v !== null) return $v;
    }
    if (function_exists('get_option')) {
        $v = @get_option('available_updates', null);
        if ($v !== null) return $v;
    }

    // Fallback to JSON file in uploads/updates
    $file = qlopy_updates_root() . DIRECTORY_SEPARATOR . 'available_updates.json';
    if (!file_exists($file)) return [];
    $json = @file_get_contents($file);
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function qlopy_set_available_updates(array $items)
{
    if (function_exists('update_option')) {
        @update_option('available_updates', $items);
        return true;
    }
    $file = qlopy_updates_root() . DIRECTORY_SEPARATOR . 'available_updates.json';
    return (bool)@file_put_contents($file, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Accessors to be used by admin UIs
function get_core_update()
{
    $all = qlopy_get_available_updates();
    foreach ($all as $it) {
        if (isset($it['type']) && $it['type'] === 'core') return $it;
    }
    return null;
}

function get_plugin_update($pluginfoldername)
{
    $all = qlopy_get_available_updates();
    foreach ($all as $it) {
        if (isset($it['type']) && $it['type'] === 'plugin' && isset($it['folder']) && $it['folder'] === $pluginfoldername) return $it;
    }
    return null;
}

function get_theme_update($themefoldername)
{
    $all = qlopy_get_available_updates();
    foreach ($all as $it) {
        if (isset($it['type']) && $it['type'] === 'theme' && isset($it['folder']) && $it['folder'] === $themefoldername) return $it;
    }
    return null;
}

// Download helper (tries curl, falls back to file_get_contents)
function qlopy_download_file($url, $dest)
{
    $tmp = $dest . '.down';
    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        $fp = fopen($tmp, 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false) {
            @unlink($tmp);
            qlopy_updater_log("Download failed ($url): $err");
            return false;
        }
        rename($tmp, $dest);
        return true;
    }

    // fallback
    $opts = ['http' => ['method' => 'GET', 'timeout' => 30]];
    $context = stream_context_create($opts);
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        qlopy_updater_log("file_get_contents failed for $url");
        return false;
    }
    $written = @file_put_contents($dest, $data);
    return $written !== false;
}

// SHA-256 verification
function verify_package_sha256($filePath, $expectedHex)
{
    if (!file_exists($filePath)) return false;
    $actual = hash_file('sha256', $filePath);
    if (!is_string($expectedHex)) return false;
    return hash_equals(strtolower($expectedHex), strtolower($actual));
}

// Verify RSA signature over plain hex string (base64 signature)
function verify_signature_over_shahex($publicPem, $expectedHex, $signatureB64)
{
    if (empty($publicPem) || empty($signatureB64) || !is_string($expectedHex)) return false;
    $pubKey = openssl_pkey_get_public($publicPem);
    if ($pubKey === false) return false;
    $sig = base64_decode($signatureB64, true);
    if ($sig === false) return false;
    $rv = openssl_verify($expectedHex, $sig, $pubKey, OPENSSL_ALGO_SHA256);
    openssl_free_key($pubKey);
    return $rv === 1;
}

// Resolve public key for manifest: prefer inline 'public_key', fallback to 'key_id' stored keys
function qlopy_resolve_manifest_public_key(array $manifest): ?string
{
    if (!empty($manifest['public_key'])) return $manifest['public_key'];
    if (!empty($manifest['key_id'])) {
        $pk = qlopy_get_public_key_by_id($manifest['key_id']);
        if ($pk) return $pk;
    }
    return null;
}

// Note: internal live-debugging helper removed. Use qlopy_updater_log() for persisted logs.

// Minimal helper to fetch remote manifest and parse JSON
function qlopy_fetch_manifest($url)
{
    // Prefer cURL when available (more reliable for TLS on some hosts)
    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        // Allow disabling verification for debugging/testing via constant
        $verify = !(defined('QLOPY_INSECURE_FETCH') && QLOPY_INSECURE_FETCH === true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && isset($info['http_code']) && $info['http_code'] >= 200 && $info['http_code'] < 300) {
            // strip UTF-8 BOM if present
            if (substr($resp, 0, 3) === "\xEF\xBB\xBF") $resp = substr($resp, 3);
            $arr = json_decode($resp, true);
            if (!is_array($arr)) {
                $err = json_last_error_msg();
                $snippet = substr($resp, 0, 1000);
                qlopy_updater_log('Manifest JSON parse failed for ' . $url . ' | json_error=' . $err . ' | body_snippet=' . preg_replace('/\s+/', ' ', $snippet));
                return null;
            }
            return $arr;
        }
        // Log detailed curl failure info to help debugging (TLS, DNS, HTTP code, body)
        $msg = 'cURL fetch failed for ' . $url . ' | http_code=' . ($info['http_code'] ?? 'n/a') . ' | error=' . ($err ?: 'none');
        if (isset($resp) && $resp !== false && strlen($resp) > 0) {
            $snippet = substr($resp, 0, 1000);
            $msg .= ' | body_snippet=' . preg_replace('/\s+/', ' ', $snippet);
        }
        qlopy_updater_log($msg);
        // fall through to stream wrapper fallback
    }

    $sslOptions = [];
    if (defined('QLOPY_INSECURE_FETCH') && QLOPY_INSECURE_FETCH === true) {
        $sslOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
    }
    $ctx = stream_context_create(array_merge(['http' => ['timeout' => 10]], $sslOptions));
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        // try to extract http response header if available
        $hdr = '';
        if (isset($http_response_header) && is_array($http_response_header)) $hdr = implode(' | ', $http_response_header);
        qlopy_updater_log('file_get_contents fetch failed for ' . $url . ' | headers=' . $hdr);
        return null;
    }
    // strip UTF-8 BOM if present
    if (substr($json, 0, 3) === "\xEF\xBB\xBF") $json = substr($json, 3);
    $arr = json_decode($json, true);
    if (!is_array($arr)) {
        $err = json_last_error_msg();
        $snippet = substr($json, 0, 1000);
        qlopy_updater_log('Manifest JSON parse failed for ' . $url . ' | json_error=' . $err . ' | body_snippet=' . preg_replace('/\s+/', ' ', $snippet));
        return null;
    }
    return $arr;
}

// Validate manifest structure and basic security expectations
function qlopy_validate_manifest(array $m, string $type = 'core')
{
    $errors = [];
    $warnings = [];
    if (empty($m['version'])) $errors[] = 'missing_version';
    if (empty($m['package_url']) && empty($m['update_file'])) $warnings[] = 'no_package_url';
    // prefer sha presence for package integrity
    if (empty($m['package_sha256'])) $warnings[] = 'no_package_sha256';
    // if db_migrate true, require either migration_sql or migration_info
    if (!empty($m['db_migrate'])) {
        if (empty($m['migration_sql']) && empty($m['migration_info'])) $errors[] = 'db_migrate_but_no_migration_info';
    }
    // basic type check
    if (!in_array($type, ['core','theme','plugin'])) $warnings[] = 'unknown_type';
    // enforce signed manifests if configured
    $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
    if (!empty($settings['require_signed_manifests'])) {
        if (empty($m['signature']) && empty($m['key_id'])) $errors[] = 'signature_required';
    }
    return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
}

// Keys directory for stored trusted public keys
function qlopy_keys_dir()
{
    $d = qlopy_updates_root() . DIRECTORY_SEPARATOR . 'keys';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

// Store a public key PEM with optional id (filename). Returns id or null
function qlopy_store_public_key(string $pem, string $id = null): ?string
{
    $dir = qlopy_keys_dir();
    $id = $id ? preg_replace('/[^a-z0-9_\-]/i','_', $id) : 'key_' . time();
    $file = $dir . DIRECTORY_SEPARATOR . $id . '.pem';
    if (@file_put_contents($file, $pem) === false) return null;
    return $id . '.pem';
}

function qlopy_list_public_keys(): array
{
    $dir = qlopy_keys_dir();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.pem') ?: [];
    $out = [];
    foreach ($files as $f) {
        $out[] = basename($f);
    }
    return $out;
}

function qlopy_get_public_key_by_id(string $id): ?string
{
    $dir = qlopy_keys_dir();
    $file = realpath($dir . DIRECTORY_SEPARATOR . $id);
    if (!$file || strpos($file, realpath($dir)) !== 0 || !is_file($file)) return null;
    return @file_get_contents($file);
}

// Robust SQL splitter: handles DELIMITER changes and returns an array of statements
function qlopy_split_sql_statements(string $sql): array
{
    $statements = [];
    // normalize line endings
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $len = strlen($sql);
    $pos = 0;
    $current = '';
    $delimiter = ';';
    $inString = false;
    $stringChar = '';
    $inSingleLineComment = false;
    $inMultiLineComment = false;
    while ($pos < $len) {
        $ch = $sql[$pos];
        $next = $sql[$pos+1] ?? '';

        // handle end of single-line comment
        if ($inSingleLineComment) {
            if ($ch === "\n") { $inSingleLineComment = false; $current .= $ch; }
            $pos++; continue;
        }
        // handle end of multi-line comment
        if ($inMultiLineComment) {
            if ($ch === '*' && $next === '/') { $inMultiLineComment = false; $pos += 2; $current .= '*/'; continue; }
            $current .= $ch; $pos++; continue;
        }

        // handle string entry/exit (support '' escaping and backslash)
        if ($inString) {
            $current .= $ch;
            if ($ch === $stringChar) {
                // check for doubled quote (''), stay in string if doubled
                $peek = $sql[$pos+1] ?? '';
                if ($peek === $stringChar) {
                    // doubled quote -> consume next char and remain in string
                    $current .= $peek; $pos += 2; continue;
                }
                // check backslash escape
                $prev = $sql[$pos-1] ?? '';
                if ($prev !== '\\') { $inString = false; $stringChar = ''; }
            }
            $pos++; continue;
        }

        // start single-line comment -- or #
        if ($ch === '-' && $next === '-') {
            // ensure either followed by space or end (MySQL uses '-- '), but accept anyway
            $inSingleLineComment = true; $pos += 2; continue;
        }
        if ($ch === '#') { $inSingleLineComment = true; $pos++; continue; }
        // start multi-line comment
        if ($ch === '/' && $next === '*') { $inMultiLineComment = true; $current .= '/*'; $pos += 2; continue; }

        // check for DELIMITER directive at start of line (allow preceding whitespace)
        $lineStart = ($pos === 0 || $sql[$pos-1] === "\n");
        if ($lineStart && preg_match('/^\s*DELIMITER\s+([^\s]+)/i', substr($sql, $pos), $m)) {
            $delimiter = $m[1];
            // advance to next line
            $nl = strpos($sql, "\n", $pos);
            if ($nl === false) { $pos = $len; break; } else { $pos = $nl + 1; $current = rtrim($current); continue; }
        }

        // check for string start
        if ($ch === '"' || $ch === "'") { $inString = true; $stringChar = $ch; $current .= $ch; $pos++; continue; }

        // check for delimiter at current position (match full delimiter string) when not empty
        if ($delimiter !== '' && substr($sql, $pos, strlen($delimiter)) === $delimiter) {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = '';
            $pos += strlen($delimiter);
            // consume a single newline if present
            if (isset($sql[$pos]) && $sql[$pos] === "\n") $pos++;
            continue;
        }

        // normal char
        $current .= $ch;
        $pos++;
    }
    $rem = trim($current);
    if ($rem !== '') $statements[] = $rem;
    return $statements;
}

// Perform remote validation (license/condition) if manifest exposes a validation URL.
// Returns ['ok'=>bool,'response'=>mixed,'http_code'=>int]
function qlopy_remote_validate_manifest(array $manifest)
{
    $urls = [];
    if (!empty($manifest['validation_url'])) $urls[] = $manifest['validation_url'];
    if (!empty($manifest['license_check_url'])) $urls[] = $manifest['license_check_url'];
    if (empty($urls)) return ['ok' => true, 'response' => null, 'http_code' => 0];
    $url = $urls[0];
    // prepare payload: site info, optional license key
    $site = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (defined('SITE_URL')?SITE_URL:'unknown'));
    $payload = ['site' => $site, 'timestamp' => time()];
    if (!empty($manifest['license_key'])) $payload['license_key'] = $manifest['license_key'];
    if (!empty($manifest['site_id'])) $payload['site_id'] = $manifest['site_id'];
    $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($payload), 'timeout' => 10]];
    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; break; }
        }
    }
    if ($resp === false) {
        qlopy_updater_log('Remote validation failed for ' . $url);
        return ['ok' => false, 'response' => null, 'http_code' => $code];
    }
    $dec = json_decode($resp, true);
    // expect JSON response with {ok:true} or {allowed:true}
    $ok = false;
    if (is_array($dec)) {
        $ok = !empty($dec['ok']) || !empty($dec['allowed']) || (!empty($dec['status']) && strtolower($dec['status']) === 'ok');
    } else {
        // fallback: treat HTTP 200 as success
        $ok = ($code >= 200 && $code < 300);
    }
    return ['ok' => (bool)$ok, 'response' => $dec ?? $resp, 'http_code' => $code];
}

// Admin AJAX: upload public key (action: 'qlopy_upload_key')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_upload_key', function($req) {
        // Expect raw POST file in $_FILES['keyfile'] or 'key' text
        if (!empty($_FILES['keyfile']) && is_uploaded_file($_FILES['keyfile']['tmp_name'])) {
            $pem = @file_get_contents($_FILES['keyfile']['tmp_name']);
            $name = $_FILES['keyfile']['name'] ?? null;
        } else {
            $r = is_array($req) ? ($req[0] ?? $req) : [];
            $pem = $r['key'] ?? null; $name = $r['name'] ?? null;
        }
        if (!$pem) { echo json_encode(['status'=>'error','message'=>'key_required']); return; }
        $id = qlopy_store_public_key($pem, $name ?: null);
        if (!$id) { echo json_encode(['status'=>'error','message'=>'store_failed']); return; }
        qlopy_audit_log('key_uploaded', ['id'=>$id]);
        echo json_encode(['status'=>'success','id'=>$id]);
    });
}

// Admin AJAX: list keys (action: 'qlopy_list_keys')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_list_keys', function($req) {
        $list = qlopy_list_public_keys(); echo json_encode(['status'=>'success','keys'=>$list]);
    });
}

// Admin AJAX: delete key (action: 'qlopy_delete_key')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_delete_key', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $id = $r['id'] ?? null; if (!$id) { echo json_encode(['status'=>'error','message'=>'id required']); return; }
        $dir = qlopy_keys_dir(); $file = realpath($dir . DIRECTORY_SEPARATOR . $id);
        if (!$file || strpos($file, realpath($dir)) !== 0 || !is_file($file)) { echo json_encode(['status'=>'error','message'=>'not_found']); return; }
        @unlink($file); qlopy_audit_log('key_deleted', ['id'=>$id]); echo json_encode(['status'=>'success']);
    });
}

// Prune backups keeping the most recent $keep entries (best-effort)
function qlopy_prune_backups($keep = null)
{
    $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
    $keep = $keep ?? (int)($settings['backup_retention'] ?? 10);
    $d = qlopy_updates_backups_dir();
    $dirs = array_filter(@glob($d . DIRECTORY_SEPARATOR . '*'), 'is_dir');
    usort($dirs, function($a, $b){ return filemtime($b) <=> filemtime($a); });
    $keep = max(1, (int)$keep);
    $i = 0;
    foreach ($dirs as $dir) {
        $i++; if ($i <= $keep) continue;
        // remove directory recursively (best-effort)
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

// Reschedule updater cron tasks using current settings
function qlopy_reschedule_scheduler_tasks()
{
    if (!function_exists('qp_get_scheduled_events') || !function_exists('qp_remove_task_by_id') || !function_exists('qp_schedule_single_event')) return false;
    $events = qp_get_scheduled_events();
    foreach ($events as $ev) {
        $hook = $ev['hook'] ?? null; $id = $ev['id'] ?? null; $status = $ev['status'] ?? null;
        if (!$hook || !$id) continue;
        if (in_array($hook, ['qlopy_update_checker','qlopy_update_initiator']) && in_array($status, ['pending','running'])) {
            // remove existing pending/running entries so we can recreate with new interval
            qp_remove_task_by_id((int)$id);
        }
    }
    $s = qlopy_get_updater_settings();
    $checker = (int)($s['checker_interval'] ?? 21600);
    $initiator = (int)($s['initiator_interval'] ?? 43200);
    qp_schedule_single_event(time(), 'qlopy_update_checker', ['_origin' => 'core'], $checker);
    qp_schedule_single_event(time()+60, 'qlopy_update_initiator', ['_origin' => 'core'], $initiator);
    return true;
}

// Compare simple dotted versions (works for 1.2.3 style)
function qlopy_version_compare($a, $b)
{
    // return -1 if a<b, 0 if equal, 1 if a>b
    $pa = array_map('intval', explode('.', (string)$a));
    $pb = array_map('intval', explode('.', (string)$b));
    $len = max(count($pa), count($pb));
    for ($i = 0; $i < $len; $i++) {
        $va = $pa[$i] ?? 0; $vb = $pb[$i] ?? 0;
        if ($va < $vb) return -1;
        if ($va > $vb) return 1;
    }
    return 0;
}

// Helper to schedule an update task via qp-cron queue
function qlopy_schedule_update_task(array $args, int $when = null)
{
    if (!function_exists('qp_schedule_single_event')) {
        qlopy_updater_log('qp_schedule_single_event not available; cannot schedule update task');
        return false;
    }
    $hook = 'qlopy_do_update';
    $ts = $when ?: time();
    // Ensure origin is set for tasks scheduled by updater core
    if (!isset($args['_origin'])) $args['_origin'] = 'core';
    // Wrap args in an array so qp-cron will pass a single associative argument
    return qp_schedule_single_event($ts, $hook, [$args], 0);
}

// Central checker: iterate global checks, fetch manifests, and populate available_updates
function central_update_checker()
{
    // Ensure update checks are initialized (scan plugins/themes) when running
    // from contexts that didn't run `central_init_update()` (cron/login runs).
    if ((empty($GLOBALS['qlopy_update_checks']) || !is_array($GLOBALS['qlopy_update_checks']) || count($GLOBALS['qlopy_update_checks']) === 0) && function_exists('central_init_update')) {
        central_init_update();
    }
    $checks = $GLOBALS['qlopy_update_checks'] ?? [];
    if (!is_array($checks) || count($checks) === 0) return [];
    $available = qlopy_get_available_updates();
    $changed = false;
    foreach ($checks as $c) {
        $type = $c['type'] ?? null; $folder = $c['folder'] ?? null; $url = $c['update_url'] ?? null; $current = $c['current_version'] ?? '';
        if (!$type || !$url) continue;
        $m = qlopy_fetch_manifest($url);
        if (!$m) { qlopy_updater_log('Manifest fetch failed for ' . $url); continue; }
        // validate manifest
        $val = qlopy_validate_manifest($m, $type);
        if (!$val['ok']) { qlopy_updater_log('Manifest validation errors for ' . $url . ': ' . json_encode($val['errors'])); continue; }
        if (!empty($val['warnings'])) qlopy_updater_log('Manifest warnings for ' . $url . ': ' . json_encode($val['warnings']));
        $remoteVersion = (string)($m['version'] ?? $m['new_version'] ?? '');
        if ($remoteVersion === '') { qlopy_updater_log('Manifest missing version at ' . $url); continue; }
        $remoteVersion = (string)$m['version'];
        $force = !empty($m['force_update']);
        // compare versions
        $cmp = qlopy_version_compare($current, $remoteVersion);
        // Only consider remote update available when remoteVersion is greater than current.
        // Previously `force_update` also triggered available update even when versions matched;
        // change: treat `force_update` as scheduling hint only when remote version is newer.
        if ($cmp < 0) {
            // ensure unique key by type+folder
            $key = $type . '::' . ($folder ?? '');
            $found = false;
            foreach ($available as $idx => $it) {
                if (($it['type'] ?? '') === $type && (($it['folder'] ?? '') === ($folder ?? ''))) {
                    $available[$idx] = array_merge($it, [
                        'type' => $type,
                        'folder' => $folder,
                        'update_url' => $url,
                        'new_version' => $remoteVersion,
                        'manifest' => $m,
                    ]);
                    $found = true; $changed = true; break;
                }
            }
            if (!$found) {
                $available[] = [
                    'type' => $type,
                    'folder' => $folder,
                    'update_url' => $url,
                    'new_version' => $remoteVersion,
                    'manifest' => $m,
                    'force_required' => !empty($force) && $type !== 'core' && empty($m['force_allowed']),
                ];
                $changed = true;
            }
            // If force update, schedule immediately for core OR when manifest allows it
            if ($force) {
                if ($type === 'core' || !empty($m['force_allowed'])) {
                    // For non-core, if manifest provides validation_url, perform remote validation before scheduling
                    // Respect site-level stop_auto_updates setting: do not auto-schedule forced updates when enabled
                    $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
                    if (!empty($settings['stop_auto_updates'])) {
                        qlopy_updater_log('Auto-updates stopped by site settings; skipping force schedule for ' . ($type ?? '') . '/' . ($folder ?? ''));
                        continue;
                    }
                    if ($type !== 'core' && !empty($m['validation_url'] || $m['license_check_url'])) {
                        $v = qlopy_remote_validate_manifest($m);
                        if (!empty($v['ok'])) {
                            qlopy_schedule_update_task(['type'=>$type,'folder'=>$folder,'manifest'=>$m]);
                        } else {
                            qlopy_updater_log('Force update validation failed for ' . $type . '/' . ($folder ?? '') . ' : ' . json_encode($v));
                        }
                    } else {
                        qlopy_schedule_update_task(['type'=>$type,'folder'=>$folder,'manifest'=>$m]);
                    }
                } else {
                    qlopy_updater_log('Force update flagged for ' . $type . '/' . ($folder ?? '') . ' but not permitted by manifest (force_allowed missing)');
                }
            }
        } else {
            // remote not newer; remove any existing available update
            foreach ($available as $idx => $it) {
                if (($it['type'] ?? '') === $type && (($it['folder'] ?? '') === ($folder ?? ''))) {
                    unset($available[$idx]); $changed = true; break;
                }
            }
        }
    }
    if ($changed) qlopy_set_available_updates(array_values($available));
    return $available;
}

// Initiator: schedule one-time update tasks for all available updates
function central_update_initiator()
{
    $available = qlopy_get_available_updates();
    if (!is_array($available) || count($available) === 0) return [];
    $scheduled = [];
    foreach ($available as $it) {
        $manifest = $it['manifest'] ?? null;
        // Only auto-schedule items that explicitly request force_update in their manifest.
        // This mirrors the behavior in central_update_checker which only schedules
        // immediate updates for force_update entries after validation.
        if (empty($manifest) || empty($manifest['force_update'])) {
            continue;
        }
        // respect site-level opt-out for auto updates
        $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
        if (!empty($settings['stop_auto_updates'])) {
            qlopy_updater_log('Auto-updates stopped by site settings; skipping initiator scheduling for ' . ($it['type'] ?? '') . '/' . ($it['folder'] ?? ''));
            continue;
        }
        $args = ['type'=>$it['type'] ?? null, 'folder'=>$it['folder'] ?? null, 'manifest'=>$manifest];
        $res = qlopy_schedule_update_task($args);
        $scheduled[] = ['item'=>$it, 'task_id'=>$res];
    }
    return $scheduled;
}

// Minimal updater runner: this is invoked by qp-cron queue hook 'qlopy_do_update'
function qlopy_do_update_action($args = [])
{
    // Accept $args either as array or list; normalize
    if (is_array($args) && array_keys($args) === range(0, count($args)-1)) {
        // qp-cron passes args as positional array; we expect associative first element
        $args = $args[0] ?? [];
    }
    $type = $args['type'] ?? null; $folder = $args['folder'] ?? null; $manifest = $args['manifest'] ?? null;
    qlopy_updater_log('Starting update task: ' . json_encode(['type'=>$type,'folder'=>$folder]));
    if (!$manifest || !is_array($manifest)) {
        qlopy_updater_log('No manifest provided for update task');
        return ['error'=>'no_manifest'];
    }
    // manifest expected fields: package_url, package_sha256, signature (optional), whitelist (optional), db_migrate
    $pkgUrl = $manifest['package_url'] ?? ($manifest['update_file'] ?? null);
    $pkgSha = $manifest['package_sha256'] ?? $manifest['cechksum'] ?? null;
    $signature = $manifest['signature'] ?? null;
    $whitelist = $manifest['whitelist'] ?? [];
    if (!$pkgUrl) { qlopy_updater_log('Manifest missing package_url'); return ['error'=>'no_package_url']; }

    $tmpDir = qlopy_updates_tmp_dir() . DIRECTORY_SEPARATOR . uniqid('pkg_', true);
    @mkdir($tmpDir, 0755, true);
    $pkgPath = $tmpDir . DIRECTORY_SEPARATOR . basename(parse_url($pkgUrl, PHP_URL_PATH) ?: 'package.zip');
    // download
    if (!qlopy_download_file($pkgUrl, $pkgPath)) { qlopy_updater_log('Download failed: ' . $pkgUrl); return ['error'=>'download_failed']; }
    // verify sha if provided
    if ($pkgSha && !verify_package_sha256($pkgPath, $pkgSha)) { qlopy_updater_log('SHA256 mismatch for downloaded package'); return ['error'=>'sha_mismatch']; }
    // verify signature if provided (we assume signature is base64 over shahex and public key is embedded in manifest as 'public_key' OR not used)
    if ($signature) {
        $pub = qlopy_resolve_manifest_public_key($manifest);
        if ($pub) {
            if (!verify_signature_over_shahex($pub, $pkgSha, $signature)) { qlopy_updater_log('Signature verification failed'); return ['error'=>'sig_failed']; }
        } else {
            // signature present but no resolved key; check policy
            $settings = qlopy_get_updater_settings();
            if (!empty($settings['require_signed_manifests'])) {
                qlopy_updater_log('Signature present but no public key resolved; signature required by policy');
                return ['error'=>'sig_key_missing'];
            }
            qlopy_updater_log('Signature present but no public key; skipping verification (optional)');
        }
    } else {
        // if signatures are required, reject
        $settings = qlopy_get_updater_settings();
        if (!empty($settings['require_signed_manifests'])) {
            qlopy_updater_log('Manifest missing signature while signatures are required');
            return ['error'=>'sig_required'];
        }
    }

    // extract zip
    $zip = new ZipArchive();
    if ($zip->open($pkgPath) !== true) { qlopy_updater_log('Failed to open zip: ' . $pkgPath); return ['error'=>'zip_open']; }
    $extractTo = $tmpDir . DIRECTORY_SEPARATOR . 'extracted'; @mkdir($extractTo, 0755, true);
    if (!$zip->extractTo($extractTo)) { qlopy_updater_log('Zip extract failed'); $zip->close(); return ['error'=>'zip_extract']; }
    $zip->close();

    // Determine target path based on type
    $projectRoot = qlopy_project_root();
    if ($type === 'core') {
        $target = $projectRoot;
    } elseif ($type === 'theme') {
        // try to read dynamic content dir from config if defined, fallback to content/themes
        $content = defined('QLOPY_CONTENT_DIR') ? QLOPY_CONTENT_DIR : 'content';
        $themes = defined('QLOPY_THEMES_DIR') ? QLOPY_THEMES_DIR : 'themes';
        $target = $projectRoot . DIRECTORY_SEPARATOR . $content . DIRECTORY_SEPARATOR . $themes . DIRECTORY_SEPARATOR . ($folder ?: '');
    } elseif ($type === 'plugin') {
        $content = defined('QLOPY_CONTENT_DIR') ? QLOPY_CONTENT_DIR : 'content';
        $plugins = defined('QLOPY_PLUGINS_DIR') ? QLOPY_PLUGINS_DIR : 'plugins';
        $target = $projectRoot . DIRECTORY_SEPARATOR . $content . DIRECTORY_SEPARATOR . $plugins . DIRECTORY_SEPARATOR . ($folder ?: '');
    } else {
        qlopy_updater_log('Unknown update type: ' . $type); return ['error'=>'unknown_type'];
    }

    // Backup is intentionally skipped to speed update (no filesystem or DB backups).
    // Set this to true if you want to re-enable backups.
    $doBackup = true;
    $backupDir = null;
    if ($doBackup) {
        // Backup: copy target to backups dir
        $backupDir = qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . uniqid();
        @mkdir($backupDir, 0755, true);
        // backup directory created (logging removed)
        // simple recursive copy (best-effort)
        if (is_dir($target)) {
            qlopy_recursive_copy($target, $backupDir);
        }
        // write simple metadata for this backup
        $meta = [
            'created_at' => date('c'),
            'type' => $type,
            'folder' => $folder,
            'manifest' => $manifest,
            'target' => $target,
        ];
        $meta['actor'] = $_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? ($_SERVER['USER'] ?? 'unknown');
        @file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'metadata.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        qlopy_audit_log('backup_created', $meta);
        // prune older backups (keep last N)
        qlopy_prune_backups();
        // DB backup if manifest requests migration
        if (!empty($manifest['db_migrate'])) {
            $dbBackup = qlopy_db_backup();
            if ($dbBackup === null) {
                qlopy_updater_log('DB backup failed or unavailable');
            } else {
                // move db backup into current backup dir if different
                $dbFile = rtrim($dbBackup, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'db-backup.sql';
                if (is_file($dbFile)) {
                    @copy($dbFile, $backupDir . DIRECTORY_SEPARATOR . 'db-backup.sql');
                }
            }
        }
    } else {
        // backups skipped (logging removed)
    }

    // Apply update: copy from extracted into target while respecting whitelist and always skipping config.php in project root
    $skip = array_map(function($p){ return trim($p, "\/"); }, (array)$whitelist);
    // Always enforce config.php skip at root
    $skip[] = 'config.php';
    // If the zip extracted into a single top-level directory (common when zipping a folder),
    // use that directory as the source so we don't create double-nested folders.
    $source = $extractTo;
    $entries = array_values(array_filter(scandir($extractTo), function($n){ return $n !== '.' && $n !== '..'; }));
    if (count($entries) === 1) {
        $single = $entries[0];
        $singlePath = $extractTo . DIRECTORY_SEPARATOR . $single;
        if (is_dir($singlePath)) {
            // If the single top-level directory matches the expected plugin/theme folder name,
            // or if it's the only directory, prefer it as the source to avoid double-nesting.
            if ($single === ($folder ?? '') || true) {
                $source = $singlePath;
            }
        }
    }
    // Remap package `admin/` to the site's configured admin folder when applying core updates.
    // This avoids creating a literal `admin/` folder when the site uses a custom admin directory name.
    if ($type === 'core') {
        $siteAdmin = $GLOBALS['config']['admin_dir'] ?? ($GLOBALS['admin_dir'] ?? 'admin');
        $siteAdmin = is_string($siteAdmin) ? trim($siteAdmin, "\\/ \t\n\r") : 'admin';
        if ($siteAdmin === '') $siteAdmin = 'admin';
        if (strcasecmp($siteAdmin, 'admin') !== 0) {
            $pkgAdminPath = $source . DIRECTORY_SEPARATOR . 'admin';
            $mappedPath = $source . DIRECTORY_SEPARATOR . $siteAdmin;
            if (is_dir($pkgAdminPath)) {
                // If mapped path already exists, merge contents; otherwise try rename for efficiency.
                if (is_dir($mappedPath)) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkgAdminPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                    $slen = strlen(rtrim($pkgAdminPath, DIRECTORY_SEPARATOR)) + 1;
                    foreach ($it as $item) {
                        $rel = substr($item->getPathname(), $slen);
                        $rel = ltrim(str_replace('\\', '/', $rel), '/');
                        $dst = $mappedPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                        if ($item->isDir()) { if (!is_dir($dst)) @mkdir($dst, 0755, true); }
                        else { $dDir = dirname($dst); if (!is_dir($dDir)) @mkdir($dDir, 0755, true); @copy($item->getPathname(), $dst); }
                    }
                    // Remove original package admin dir (best-effort)
                    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkgAdminPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($it2 as $f) { if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname()); }
                    @rmdir($pkgAdminPath);
                } else {
                    @rename($pkgAdminPath, $mappedPath);
                }
            }
        }
    }

    qlopy_recursive_apply($source, $target, $skip);

    // Collect the list of files that were extracted/applied so we can do precise invalidation.
    $appliedFiles = [];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        $slen = strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1;
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), $slen));
                $appliedFiles[] = ltrim($rel, "/");
            }
        }
    } catch (Throwable $_) {
        $appliedFiles = [];
    }

    // Run packaged PHP migration script if provided (preferred for complex migrations)
    $migScript = $manifest['migration_script'] ?? $manifest['migration_php'] ?? null;
    if (!empty($migScript) && is_string($migScript)) {
        // Prefer the file as installed into target (files already applied), fallback to extracted source
        $migRel = ltrim($migScript, "\\/\n\r");
        $migTargetPath = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $migRel);
        $migExtractPath = $extractTo . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $migRel);

        // Ensure DB backup exists before running any migration
   /*     if (empty($backupDir) || !is_dir($backupDir)) {
            if (function_exists('qlopy_updater_log')) qlopy_updater_log('Ensuring DB backup before running packaged PHP migration');
            $backupDir = qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . uniqid('db_', true);
            @mkdir($backupDir, 0755, true);
            if (function_exists('qlopy_updater_log')) qlopy_updater_log('Created temp backup dir: ' . $backupDir);
            $dbBackup = qlopy_db_backup();
            if (function_exists('qlopy_updater_log')) qlopy_updater_log('qlopy_db_backup returned: ' . var_export($dbBackup, true));
            if ($dbBackup && is_file($dbBackup . DIRECTORY_SEPARATOR . 'db-backup.sql')) {
                $srcDb = $dbBackup . DIRECTORY_SEPARATOR . 'db-backup.sql';
                $dstDb = $backupDir . DIRECTORY_SEPARATOR . 'db-backup.sql';
                if (@copy($srcDb, $dstDb)) {
                    if (function_exists('qlopy_updater_log')) qlopy_updater_log('Copied DB backup to: ' . $dstDb);
                } else {
                    if (function_exists('qlopy_updater_log')) qlopy_updater_log('Failed to copy DB backup from ' . $srcDb . ' to ' . $dstDb);
                }
            } else {
                if (function_exists('qlopy_updater_log')) qlopy_updater_log('No db-backup.sql found in qlopy_db_backup result: ' . var_export($dbBackup, true));
            }
        }*/

        $ran = false;
        try {
            // Run the migration script from the installed target when possible so it uses new code
            $runPath = is_file($migTargetPath) ? $migTargetPath : (is_file($migExtractPath) ? $migExtractPath : null);
            if ($runPath) {
                if (!defined('QLOPY_UPDATER_RUNNING')) define('QLOPY_UPDATER_RUNNING', true);
                if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);
                // Include the migration file in isolated scope
                if (function_exists('qlopy_updater_log')) {
                    $bd = empty($backupDir) ? 'none' : $backupDir;
                    $hasDb = (!empty($backupDir) && is_file($backupDir . DIRECTORY_SEPARATOR . 'db-backup.sql')) ? '1' : '0';
                    qlopy_updater_log('About to include migration script: ' . $runPath . ' ; backupDir=' . $bd . ' ; db_backup_exists=' . $hasDb);
                }
                $res = include $runPath;
                // Expect migration script to return truthy on success or null if not returning anything
                if ($res === false) throw new Exception('Migration script returned false');
                $ran = true;
                qlopy_updater_log('Migration script executed: ' . $runPath);
                // Remove migration file from target to avoid leaving executable code
                if (is_file($migTargetPath)) {@unlink($migTargetPath);} 
                // also remove extracted copy if present
                if (is_file($migExtractPath)) {@unlink($migExtractPath);} 
            } else {
                qlopy_updater_log('Migration script referenced but not found: ' . $migRel);
            }
        } catch (Throwable $e) {
            qlopy_updater_log('PHP migration failed: ' . $e->getMessage());
            // Attempt rollback if backup exists
            if (!empty($backupDir) && is_dir($backupDir)) {
                qlopy_restore_backup($backupDir);
            }
            return ['error' => 'php_migration_failed', 'message' => $e->getMessage()];
        }
    }

    // Handle DB migration if requested
    if (!empty($manifest['db_migrate'])) {
        $mig = $manifest['migration_info'] ?? null;
        $sqlText = '';
        if (is_string($mig) && $mig !== '') {
            // migration_info may be a relative path inside extracted dir
                $migPath = $extractTo . DIRECTORY_SEPARATOR . ltrim($mig, '/\\');
            if (is_file($migPath)) {
                $sqlText = file_get_contents($migPath);
            }
        } elseif (!empty($manifest['migration_sql'])) {
            $sqlText = $manifest['migration_sql'];
        }
        if ($sqlText !== '') {
            // if dry_run flag present, perform dry-run instead of applying
            if (!empty($args['dry_run'])) {
                $res = qlopy_dry_run_sql_script($sqlText);
            } else {
                $res = qlopy_run_sql_script($sqlText);
            }
            if (isset($res['error'])) {
                qlopy_updater_log('Migration failed: ' . json_encode($res));
                // Attempt rollback if a backup was created
                if (!empty($backupDir) && is_dir($backupDir)) {
                    qlopy_restore_backup($backupDir);
                } else {
                    qlopy_updater_log('Migration failed and no backup available to restore');
                }
                return ['error'=>'migration_failed','details'=>$res];
            }
        }
    }

    // Remove available_updates entry for this item
    $avail = qlopy_get_available_updates();
    foreach ($avail as $i => $it) {
        if (($it['type'] ?? '') === $type && (($it['folder'] ?? '') === ($folder ?? ''))) { unset($avail[$i]); }
    }
    qlopy_set_available_updates(array_values($avail));

    qlopy_updater_log('Update applied: ' . json_encode(['type'=>$type,'folder'=>$folder]));
    // Invalidate asset caches for themes/plugins so frontend reflects new files immediately
    if (in_array($type, ['theme','plugin'])) {
        // Prefer exact file-based invalidation when we have the list of applied files
        if (!empty($appliedFiles) && function_exists('qp_assets_invalidate_by_files')) {
            try { qp_assets_invalidate_by_files($appliedFiles); } catch (Throwable $_) { /* fallback next */ }
        }
        // Fallback to folder-based invalidation if provided
        if (!empty($folder) && function_exists('qp_assets_invalidate_by_folder')) {
            try { qp_assets_invalidate_by_folder($folder); } catch (Throwable $_) { /* fallback next */ }
        } else if (function_exists('qp_assets_invalidate')) {
            try { qp_assets_invalidate('all'); } catch (Throwable $_) {}
        }
    }
    return ['success'=>true];
}

// recursive copy helper
function qlopy_recursive_copy($src, $dst)
{
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) @mkdir($dst, 0755, true);

    // Normalize paths once for faster, case-insensitive comparisons on Windows
    $srcNorm = str_replace('\\', '/', rtrim($src, DIRECTORY_SEPARATOR));
    $dstNorm = str_replace('\\', '/', rtrim($dst, DIRECTORY_SEPARATOR));
    $dstReal = realpath($dst) ?: $dstNorm;
    $dstReal = str_replace('\\', '/', $dstReal);
    $projRoot = qlopy_project_root();
    $projRootNorm = str_replace('\\', '/', rtrim($projRoot, DIRECTORY_SEPARATOR));
    $projUploadsNorm = $projRootNorm . '/uploads';
    $isCopyingProjectRoot = (strtolower($srcNorm) === strtolower($projRootNorm));

    // Use an iterator to walk the tree once and copy items; avoid per-item realpath calls
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $slen = strlen($srcNorm) + 1;
    foreach ($it as $item) {
        $path = str_replace('\\', '/', $item->getPathname());

        // If copying project root, skip the top-level uploads directory entirely
        if ($isCopyingProjectRoot) {
                if (stripos($path, $projUploadsNorm) === 0) {
                    // skipped project uploads during backup copy
                    continue;
                }
        }

        // Avoid copying the backup destination (or its parents) back into itself.
        // Compare using normalized strings and case-insensitive on Windows.
        if (stripos($dstReal, $path) === 0) {
            // skip copying backup destination into itself
            continue;
        }

        // compute relative path to recreate structure under $dst
        $rel = substr($path, strlen($srcNorm) + 1);
        if ($rel === false) $rel = ltrim(str_replace($srcNorm, '', $path), '/');
        $dest = $dst . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        if ($item->isDir()) {
            if (!is_dir($dest)) @mkdir($dest, 0755, true);
        } else {
            $dDir = dirname($dest); if (!is_dir($dDir)) @mkdir($dDir, 0755, true);
            @copy($path, $dest);
        }
    }
    return true;
}

// recursive apply: copy files from source to target, skipping whitelisted relative paths
function qlopy_recursive_apply($source, $target, array $whitelist = [])
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $slen = strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1;
    foreach ($it as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), $slen));
        // normalize
        $relNorm = ltrim($rel, "/");
        // skip if matches whitelist (simple prefix match)
        $skip = false;
        foreach ($whitelist as $w) {
            $w = trim(str_replace('\\','/',$w), "/");
            if ($w === '') continue;
            if (strpos($relNorm, $w) === 0) { $skip = true; break; }
        }
        if ($skip) continue;
        $dest = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relNorm);
        if ($file->isDir()) {
            if (!is_dir($dest)) @mkdir($dest, 0755, true);
        } else {
            $dDir = dirname($dest); if (!is_dir($dDir)) @mkdir($dDir, 0755, true);
            @copy($file->getPathname(), $dest);
        }
    }
}

// Create a SQL dump of the database (schema + data) into backups dir
function qlopy_db_backup(): ?string
{
    if (!function_exists('db')) return null;
    $pdo = db();
    $backupDir = qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . uniqid('db_', true);
    @mkdir($backupDir, 0755, true);
    $file = $backupDir . DIRECTORY_SEPARATOR . 'db-backup.sql';
    $out = '';
    try {
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $create = $row['Create Table'] ?? ($row['Create View'] ?? null);
            if ($create) {
                $out .= "-- Table structure for {$table}\n";
                $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $out .= $create . ";\n\n";
            }
            // dump data
            $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $out .= "-- Dumping data for {$table}\n";
                foreach ($rows as $r) {
                    $cols = array_map(function($c){ return "`$c`"; }, array_keys($r));
                    $vals = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        if (is_bool($v)) return $v ? '1' : '0';
                        return $pdo->quote($v);
                    }, array_values($r));
                    $out .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ');\n';
                }
                $out .= "\n";
            }
        }
        file_put_contents($file, $out);
        return $backupDir;
    } catch (Throwable $e) {
        qlopy_updater_log('DB backup failed: ' . $e->getMessage());
        return null;
    }
}

// Run SQL script (simple splitter) with safety checks
function qlopy_run_sql_script(string $sql): array
{
    if (!function_exists('db')) return ['error' => 'no_db'];
    $pdo = db();
    // split with robust parser that understands DELIMITER and routines
    $parts = qlopy_split_sql_statements($sql);
    $pdo->beginTransaction();
    try {
        foreach ($parts as $idx => $part) {
            $stm = trim($part);
            if ($stm === '') continue;
            // Safety: only allow certain statements unless risky allowed
            if (preg_match('/^(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)/i', $stm)) {
                $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
                $allowed = defined('QLOPY_ALLOW_RISKY_MIGRATIONS') && QLOPY_ALLOW_RISKY_MIGRATIONS;
                if (!$allowed) {
                    if (function_exists('get_option_meta')) {
                        $opt = @get_option_meta('qlopy_allow_risky_migrations');
                        $allowed = (bool)$opt;
                    } else if (function_exists('get_option')) {
                        $opt = @get_option('qlopy_allow_risky_migrations', false);
                        $allowed = (bool)$opt;
                    }
                }
                if (!$allowed) {
                    // also respect settings
                    $allowed = !empty($settings['allow_risky_migrations']);
                }
                if (!$allowed) {
                    $pdo->rollBack();
                    return ['error' => 'risky_statements_not_allowed', 'statement' => $stm];
                }
            }
            $pdo->exec($stm);
        }
        $pdo->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        $stmtSnippet = isset($stm) ? substr($stm, 0, 300) : '';
        qlopy_updater_log('SQL migration failed at statement index ' . ($idx ?? '?') . ': ' . $e->getMessage() . ' | stmt: ' . $stmtSnippet);
        return ['error' => 'exec_failed', 'message' => $e->getMessage(), 'failed_index' => ($idx ?? null), 'failed_statement' => $stmtSnippet];
    }
}

// Execute statements but map back to original indices when available
function qlopy_execute_statements_array_with_map(array $statements, array $origMap = []): array
{
    if (!function_exists('db')) return ['error' => 'no_db'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($statements as $idx => $stm) {
            $stm = trim((string)$stm);
            if ($stm === '') continue;
            if (preg_match('/^(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)/i', $stm)) {
                $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
                $allowed = defined('QLOPY_ALLOW_RISKY_MIGRATIONS') && QLOPY_ALLOW_RISKY_MIGRATIONS;
                if (!$allowed) {
                    if (function_exists('get_option_meta')) {
                        $opt = @get_option_meta('qlopy_allow_risky_migrations'); $allowed = (bool)$opt;
                    } else if (function_exists('get_option')) {
                        $opt = @get_option('qlopy_allow_risky_migrations', false); $allowed = (bool)$opt;
                    }
                }
                $allowed = $allowed || !empty($settings['allow_risky_migrations']);
                if (!$allowed) {
                    $pdo->rollBack();
                    return ['error' => 'risky_statements_not_allowed', 'statement' => $stm, 'failed_index' => $idx, 'failed_orig_index' => $origMap[$idx] ?? null];
                }
            }
            $pdo->exec($stm);
        }
        $pdo->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
        $snippet = isset($stm) ? substr($stm, 0, 300) : '';
        qlopy_updater_log('Execute statements failed at index ' . ($idx ?? '?') . ': ' . $e->getMessage() . ' | stmt: ' . $snippet);
        return ['error' => 'exec_failed', 'message' => $e->getMessage(), 'failed_index' => ($idx ?? null), 'failed_orig_index' => ($origMap[$idx] ?? null), 'failed_statement' => $snippet];
    }
}

// Dry-run SQL script: execute statements inside a transaction and always roll back.
// Returns ['success'=>true] or ['error'=>...,'message'=>...]
function qlopy_dry_run_sql_script(string $sql): array
{
    if (!function_exists('db')) return ['error'=>'no_db'];
    $pdo = db();
    $parts = qlopy_split_sql_statements($sql);
    try {
        $pdo->beginTransaction();
        foreach ($parts as $idx => $part) {
            $stm = trim($part);
            if ($stm === '') continue;
            // run statement to detect errors
            $pdo->exec($stm);
        }
        // always roll back
        $pdo->rollBack();
        return ['success'=>true, 'note' => 'dry-run rolled back'];
    } catch (Throwable $e) {
        // attempt rollback if possible
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
        $stmtSnippet = isset($stm) ? substr($stm, 0, 300) : '';
        qlopy_updater_log('Dry-run migration failed at statement index ' . ($idx ?? '?') . ': ' . $e->getMessage() . ' | stmt: ' . $stmtSnippet);
        return ['error'=>'exec_failed', 'message'=>$e->getMessage(), 'failed_index' => ($idx ?? null), 'failed_statement' => $stmtSnippet];
    }
}

// Restore backup directory: copies files back and imports DB dump if present
function qlopy_restore_backup(string $backupDir): array
{
    if (!is_dir($backupDir)) return ['error'=>'not_found'];
    // restore files
    $items = scandir($backupDir);
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        if ($it === 'db-backup.sql') continue;
        $src = $backupDir . DIRECTORY_SEPARATOR . $it;
        // copy recursively into project root
        qlopy_recursive_apply($src, qlopy_project_root(), []);
    }
    $dbfile = $backupDir . DIRECTORY_SEPARATOR . 'db-backup.sql';
    if (is_file($dbfile)) {
        $sql = file_get_contents($dbfile);
        // allow risky during restore
        if (!defined('QLOPY_ALLOW_RISKY_MIGRATIONS')) define('QLOPY_ALLOW_RISKY_MIGRATIONS', true);
        $res = qlopy_run_sql_script($sql);
        if (isset($res['error'])) return ['error'=>'db_restore_failed','details'=>$res];
    }
    qlopy_audit_log('backup_restored', ['backup'=>$backupDir]);
    return ['success'=>true];
}

// Admin AJAX: restore latest backup (action: 'qlopy_restore_latest_backup')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_restore_latest_backup', function($req) {
        $d = qlopy_updates_backups_dir();
        $dirs = array_filter(glob($d . DIRECTORY_SEPARATOR . '*'), 'is_dir');
        rsort($dirs);
        $latest = $dirs[0] ?? null;
        if (!$latest) { echo json_encode(['status'=>'error','message'=>'no_backups']); return; }
        $res = qlopy_restore_backup($latest);
        if (isset($res['error'])) echo json_encode(['status'=>'error','message'=>$res]); else echo json_encode(['status'=>'success','restored'=>$latest]);
    });
}

// Admin AJAX: list backups (action: 'qlopy_list_backups')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_list_backups', function($req) {
        try {
            $list = qlopy_list_backups();
            echo json_encode(['status'=>'success','backups'=>$list]);
        } catch (Throwable $e) {
            // Re-fetch manifest live to avoid scheduling with stale manifest entries
            $liveManifest = null;
            if (!empty($found['update_url'])) {
                $liveManifest = qlopy_fetch_manifest($found['update_url']);
            }
            if ($liveManifest && is_array($liveManifest)) {
                $found['manifest'] = $liveManifest;
            }
            qlopy_updater_log('Scheduling update task for ' . ($type ?? '') . '/' . ($folder ?? '') . ' using manifest version ' . ($found['manifest']['version'] ?? 'unknown'));
            $payload = ['type'=>$type,'folder'=>$folder,'manifest'=>$found['manifest'] ?? null];
            if (!isset($payload['_origin'])) $payload['_origin'] = 'core';
            $taskId = qp_schedule_single_event(time(), 'qlopy_do_update', [$payload], 0);
        }
    });
}

// Admin AJAX: delete a backup (action: 'qlopy_delete_backup')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_delete_backup', function($req) {
        $name = trim($req['backup'] ?? '');
        if (!$name) { echo json_encode(['status'=>'error','message'=>'missing_backup']); return; }
        // Prevent directory traversal
        if (strpos($name, '..') !== false || strpos($name, DIRECTORY_SEPARATOR) !== false) { echo json_encode(['status'=>'error','message'=>'invalid_backup']); return; }
        $base = qlopy_updates_backups_dir();
        $path = $base . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path)) { echo json_encode(['status'=>'error','message'=>'not_found']); return; }

        // recursive delete
        $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath()); else @unlink($file->getRealPath());
        }
        $ok = @rmdir($path);
        if ($ok) {
            qlopy_audit_log('backup_deleted', ['backup'=>$name]);
            echo json_encode(['status'=>'success']);
        } else {
            echo json_encode(['status'=>'error','message'=>'delete_failed']);
        }
    });
}

// Admin AJAX: restore specific backup (action: 'qlopy_restore_backup')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_restore_backup', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $backup = $r['backup'] ?? null;
        if (!$backup) { echo json_encode(['status'=>'error','message'=>'backup required']); return; }
        $base = realpath(qlopy_updates_backups_dir());
        $path = realpath($base . DIRECTORY_SEPARATOR . $backup);
        if (!$path || strpos($path, $base) !== 0 || !is_dir($path)) { echo json_encode(['status'=>'error','message'=>'invalid_backup']); return; }
        $res = qlopy_restore_backup($path);
        if (isset($res['error'])) echo json_encode(['status'=>'error','message'=>$res]); else echo json_encode(['status'=>'success','restored'=>$backup]);
    });
}

// register cron action hook so qp-cron can invoke the updater runner
if (function_exists('add_qp_cron_action')) {
    add_qp_cron_action('qlopy_do_update', 'qlopy_do_update_action');
    // Register scheduler hooks for checker and initiator
    add_qp_cron_action('qlopy_update_checker', 'central_update_checker');
    // Also allow an explicit login-triggered one-time checker hook
    add_qp_cron_action('qlopy_update_checker_login', 'central_update_checker');
    add_qp_cron_action('qlopy_update_initiator', 'central_update_initiator');
}

// Admin AJAX: schedule a single update for an item (action: 'qlopy_schedule_update')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_schedule_update', function($req) {
        // $req is an array containing $_REQUEST (admin/ajax.php passes $_REQUEST)
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $type = $r['type'] ?? ($r['t'] ?? null);
        $folder = $r['folder'] ?? ($r['f'] ?? null);
        if (!$type) {
            echo json_encode(['status'=>'error','message'=>'type required']); return;
        }
        // locate available_updates entry for validation
        $avail = qlopy_get_available_updates();
        $found = null;
        foreach ($avail as $it) {
            if (($it['type'] ?? '') === $type && (($it['folder'] ?? '') === ($folder ?? ''))) { $found = $it; break; }
        }
        if (!$found) {
            echo json_encode(['status'=>'error','message'=>'no update available']); return;
        }
        // schedule via qp_schedule_single_event
        if (!function_exists('qp_schedule_single_event')) {
            echo json_encode(['status'=>'error','message'=>'scheduler unavailable']); return;
        }
        // wrap associative args in an array so the cron runner passes them as a single parameter
        $payload = ['type'=>$type,'folder'=>$folder,'manifest'=>$found['manifest'] ?? null];
        if (!isset($payload['_origin'])) $payload['_origin'] = 'core';
        $taskId = qp_schedule_single_event(time(), 'qlopy_do_update', [$payload], 0);
        if ($taskId) {
            echo json_encode(['status'=>'success','task_id'=>$taskId]); return;
        }
        echo json_encode(['status'=>'error','message'=>'failed to schedule']);
    });
}

// Admin AJAX: check updates now (action: 'qlopy_check_updates')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_check_updates', function($req) {
        // Run central_update_checker and return JSON result
        try {
            $res = central_update_checker();
            echo json_encode(['status'=>'success','updates'=>$res]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
    });
}

// Admin AJAX: run initiator now (action: 'qlopy_run_initiator')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_run_initiator', function($req) {
        try {
            $res = central_update_initiator();
            echo json_encode(['status'=>'success','scheduled'=>$res]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
    });
}

// Admin AJAX: fetch recent update logs (action: 'qlopy_get_update_logs')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_get_update_logs', function($req) {
        try {
            if (function_exists('qp_get_logs')) {
                $logs = qp_get_logs(200, 'qlopy_do_update');
                echo json_encode(['status'=>'success','logs'=>$logs]);
            } else {
                // fallback: return updater logs file lines
                $logFile = qlopy_updates_logs_dir() . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
                if (file_exists($logFile)) {
                    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    echo json_encode(['status'=>'success','logs'=>$lines]);
                } else {
                    echo json_encode(['status'=>'success','logs'=>[]]);
                }
            }
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
    });
}

// Admin AJAX: get updater settings
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_get_settings', function($req) {
        try {
            $s = qlopy_get_updater_settings();
            echo json_encode(['status'=>'success','settings'=>$s]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
    });
}

// Admin AJAX: save updater settings
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_save_settings', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $checker = isset($r['checker_interval']) ? (int)$r['checker_interval'] : null;
        $initiator = isset($r['initiator_interval']) ? (int)$r['initiator_interval'] : null;
        $allowRisky = isset($r['allow_risky_migrations']) ? (bool)$r['allow_risky_migrations'] : false;
        $enableLogging = isset($r['enable_logging']) ? (bool)$r['enable_logging'] : false;
        $stopAuto = isset($r['stop_auto_updates']) ? (bool)$r['stop_auto_updates'] : false;
        $retain = isset($r['backup_retention']) ? (int)$r['backup_retention'] : null;
        $save = [];
        if ($checker !== null) $save['checker_interval'] = max(60, $checker);
        if ($initiator !== null) $save['initiator_interval'] = max(60, $initiator);
        $save['allow_risky_migrations'] = $allowRisky;
        $save['enable_logging'] = $enableLogging;
        $save['stop_auto_updates'] = $stopAuto;
        if ($retain !== null) $save['backup_retention'] = max(1, $retain);
        $ok = qlopy_set_updater_settings($save);
        // apply new schedule immediately
        if ($ok && function_exists('qlopy_reschedule_scheduler_tasks')) {
            qlopy_reschedule_scheduler_tasks();
        }
        echo json_encode(['status'=>$ok?'success':'error','saved'=>$save]);
    });
}

// Admin AJAX: preview migration SQL (action: 'qlopy_preview_migration')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_preview_migration', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $sql = $r['sql'] ?? null;
        if (!$sql) { echo json_encode(['status'=>'error','message'=>'sql required']); return; }
        // split naive by semicolons
        $parts = qlopy_split_sql_statements($sql);
        $out = [];
        foreach ($parts as $idx => $p) {
            $stm = trim($p);
            if ($stm === '') continue;
            $risky = preg_match('/^(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|DROP\s+COLUMN)/i', $stm) ? true : false;
            $out[] = ['index' => $idx, 'statement'=>$stm, 'is_risky'=>$risky];
        }
        echo json_encode(['status'=>'success','statements'=>$out]);
    });
}

// Admin AJAX: dry-run migration (action: 'qlopy_dry_run_migration')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_dry_run_migration', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $sql = $r['sql'] ?? null;
        if (!$sql) { echo json_encode(['status'=>'error','message'=>'sql required']); return; }
        try {
            $res = qlopy_dry_run_sql_script($sql);
            if (isset($res['error'])) echo json_encode(['status'=>'error','details'=>$res]); else echo json_encode(['status'=>'success','details'=>$res]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
    });
}

// Helper: execute an array of SQL statements transactionally with safety checks
function qlopy_execute_statements_array(array $statements): array
{
    if (!function_exists('db')) return ['error' => 'no_db'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($statements as $idx => $stm) {
            $stm = trim((string)$stm);
            if ($stm === '') continue;
            if (preg_match('/^(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)/i', $stm)) {
                $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
                $allowed = defined('QLOPY_ALLOW_RISKY_MIGRATIONS') && QLOPY_ALLOW_RISKY_MIGRATIONS;
                if (!$allowed) {
                    if (function_exists('get_option_meta')) {
                        $opt = @get_option_meta('qlopy_allow_risky_migrations'); $allowed = (bool)$opt;
                    } else if (function_exists('get_option')) {
                        $opt = @get_option('qlopy_allow_risky_migrations', false); $allowed = (bool)$opt;
                    }
                }
                $allowed = $allowed || !empty($settings['allow_risky_migrations']);
                if (!$allowed) {
                    $pdo->rollBack();
                    return ['error' => 'risky_statements_not_allowed', 'statement' => $stm, 'failed_index' => $idx];
                }
            }
            $pdo->exec($stm);
        }
        $pdo->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
        $snippet = isset($stm) ? substr($stm, 0, 300) : '';
        qlopy_updater_log('Execute statements failed at index ' . ($idx ?? '?') . ': ' . $e->getMessage() . ' | stmt: ' . $snippet);
        return ['error' => 'exec_failed', 'message' => $e->getMessage(), 'failed_index' => ($idx ?? null), 'failed_statement' => $snippet];
    }
}

// Admin AJAX: apply selected migration statements (action: 'qlopy_apply_selected_migration')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_apply_selected_migration', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $stmts = $r['statements'] ?? null;
        if ($stmts === null) { echo json_encode(['status'=>'error','message'=>'statements required']); return; }
        // accept JSON-encoded or array
        if (is_string($stmts)) {
            $dec = json_decode($stmts, true);
            if (is_array($dec)) $stmts = $dec;
            else $stmts = [$stmts];
        }
        if (!is_array($stmts)) { echo json_encode(['status'=>'error','message'=>'statements must be array']); return; }

        // Create lightweight backup before applying (DB backup + metadata)
        $backupDir = qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . date('Ymd_His') . '_sel_' . uniqid();
        @mkdir($backupDir, 0755, true);
        $dbBackup = qlopy_db_backup();
        if ($dbBackup && is_dir($dbBackup)) {
            // move sql file into our backup
            $dbFile = $dbBackup . DIRECTORY_SEPARATOR . 'db-backup.sql';
            if (is_file($dbFile)) @copy($dbFile, $backupDir . DIRECTORY_SEPARATOR . 'db-backup.sql');
        }
        $meta = ['created_at'=>date('c'),'action'=>'apply_selected_migration','count'=>count($stmts)];
        $meta['actor'] = $_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? ($_SERVER['USER'] ?? 'unknown');
        @file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'metadata.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        qlopy_updater_log('Created pre-migration backup: ' . $backupDir);
        qlopy_audit_log('pre_migration_backup', $meta);

        // Normalize statements into [text] and keep original indices mapping
        $exec = [];
        $origMap = [];
        foreach ($stmts as $s) {
            if (is_array($s) || is_object($s)) {
                $sarr = (array)$s;
                $text = $sarr['statement'] ?? $sarr['stmt'] ?? $sarr['sql'] ?? null;
                $orig = isset($sarr['index']) ? $sarr['index'] : (isset($sarr['idx']) ? $sarr['idx'] : null);
            } else {
                $text = (string)$s; $orig = null;
            }
            $exec[] = $text; $origMap[] = $orig;
        }

        // Execute selected statements with mapping
        $res = qlopy_execute_statements_array_with_map($exec, $origMap);
        if (isset($res['error'])) {
            qlopy_updater_log('Selected migration failed: ' . json_encode($res));
            qlopy_audit_log('migration_failed', array_merge(['backup'=>$backupDir], $res));
            echo json_encode(['status'=>'error','details'=>$res]); return;
        }
        qlopy_updater_log('Selected migration applied successfully; backup: ' . $backupDir);
        qlopy_audit_log('migration_applied', ['backup'=>$backupDir,'count'=>count($exec)]);
        echo json_encode(['status'=>'success','backup'=>$backupDir]);
    });
}

// Helper: create a zip for a backup folder and return web-accessible URL (best-effort)
function qlopy_create_backup_zip(string $backupName): ?string
{
    $base = realpath(qlopy_updates_backups_dir());
    $path = realpath($base . DIRECTORY_SEPARATOR . $backupName);
    if (!$path || strpos($path, $base) !== 0 || !is_dir($path)) return null;
    $tmp = qlopy_updates_tmp_dir();
    $zipName = 'backup_' . preg_replace('/[^a-z0-9_\-]/i', '_', $backupName) . '_' . time() . '.zip';
    $zipPath = $tmp . DIRECTORY_SEPARATOR . $zipName;
    $za = new ZipArchive();
    if ($za->open($zipPath, ZipArchive::CREATE) !== true) return null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $filePath = $file->getPathname();
        $localPath = substr($filePath, strlen($path) + 1);
        $za->addFile($filePath, $localPath);
    }
    $za->close();
    // attempt to construct web URL relative to project root
    $proj = realpath(qlopy_project_root());
    $rel = str_replace('\\', '/', ltrim(str_replace($proj, '', $zipPath), '/\\'));
    $scheme = (!empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http'));
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url = rtrim($scheme . '://' . $host, '/') . '/' . $rel;
    qlopy_audit_log('backup_zipped', ['backup'=>$backupName, 'zip'=>$zipPath, 'url'=>$url]);
    return $zipPath;
}

// Admin AJAX: create backup zip and return URL (action: 'qlopy_create_backup_zip')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_create_backup_zip', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $backup = $r['backup'] ?? null;
        if (!$backup) { echo json_encode(['status'=>'error','message'=>'backup required']); return; }
        $zipPath = qlopy_create_backup_zip($backup);
        if ($zipPath === null) { echo json_encode(['status'=>'error','message'=>'zip_failed']); return; }
        qlopy_updater_log('Created backup zip for ' . $backup . ' at ' . $zipPath);
        echo json_encode(['status'=>'success','zip'=>$zipPath]);
    });
}

// Token helpers for temporary download links
function qlopy_download_tokens_file()
{
    $d = qlopy_updates_tmp_dir();
    $f = $d . DIRECTORY_SEPARATOR . 'download_tokens.json';
    if (!is_file($f)) @file_put_contents($f, json_encode([]));
    return $f;
}

function qlopy_create_download_token(string $filePath, int $ttl = 300)
{
    $tokensFile = qlopy_download_tokens_file();
    $tokens = json_decode(@file_get_contents($tokensFile) ?: '[]', true);
    if (!is_array($tokens)) $tokens = [];
    $token = bin2hex(random_bytes(16));
    $expires = time() + $ttl;
    $tokens[$token] = ['file' => $filePath, 'expires' => $expires];
    @file_put_contents($tokensFile, json_encode($tokens, JSON_PRETTY_PRINT));
    return $token;
}

function qlopy_resolve_download_token(string $token): ?string
{
    $tokensFile = qlopy_download_tokens_file();
    $tokens = json_decode(@file_get_contents($tokensFile) ?: '[]', true);
    if (!is_array($tokens) || empty($tokens[$token])) return null;
    $rec = $tokens[$token];
    if (time() > ($rec['expires'] ?? 0)) { unset($tokens[$token]); @file_put_contents($tokensFile, json_encode($tokens, JSON_PRETTY_PRINT)); return null; }
    // Optionally make token one-time: remove it
    unset($tokens[$token]); @file_put_contents($tokensFile, json_encode($tokens, JSON_PRETTY_PRINT));
    return $rec['file'];
}

// Admin AJAX: request a signed temporary download URL (action: 'qlopy_request_backup_download')
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qlopy_request_backup_download', function($req) {
        $r = is_array($req) ? ($req[0] ?? $req) : [];
        $backup = $r['backup'] ?? null; $ttl = isset($r['ttl']) ? (int)$r['ttl'] : 300;
        if (!$backup) { echo json_encode(['status'=>'error','message'=>'backup required']); return; }
        $zipPath = qlopy_create_backup_zip($backup);
        if ($zipPath === null) { echo json_encode(['status'=>'error','message'=>'zip_failed']); return; }
        $token = qlopy_create_download_token($zipPath, max(30, min(86400, $ttl)));
        $proj = realpath(qlopy_project_root());
        // Prefer configured SITE_URL (includes subpath like /advcms) when available
        if (defined('SITE_URL') && SITE_URL) {
            $baseUrl = rtrim(SITE_URL, '/');
        } else {
            $baseUrl = rtrim((!empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');
        }
        $downloadUrl = $baseUrl . '/includes/updater.php?download_backup_token=' . urlencode($token);
        qlopy_audit_log('backup_download_requested', ['backup'=>$backup,'token'=>$token,'ttl'=>$ttl]);
        echo json_encode(['status'=>'success','url'=>$downloadUrl,'token'=>$token]);
    });
}

// Serve backup download when requested directly via ?download_backup_token=<token>
if (php_sapi_name() !== 'cli' && isset($_GET['download_backup_token'])) {
    require_once __DIR__ . '/../auth.php';
    $token = (string)($_GET['download_backup_token'] ?? '');
    $file = qlopy_resolve_download_token($token);
    if (!$file || !is_file($file)) { http_response_code(404); echo 'invalid or expired token'; exit; }

    $authOk = false;
    if (function_exists('current_user_can')) {
        try { $authOk = (bool)current_user_can('manage_options'); } catch (Throwable $_) { $authOk = false; }
    }
    if (!$authOk && defined('QLOPY_ADMIN_CHECK_FUNCTION')) {
        $fnName = constant('QLOPY_ADMIN_CHECK_FUNCTION');
        if (is_callable($fnName)) {
            try { $authOk = (bool)call_user_func($fnName); } catch (Throwable $_) { $authOk = false; }
        }
    }
    if (!$authOk && function_exists('is_admin')) {
        try { $authOk = (bool)is_admin(); } catch (Throwable $_) { $authOk = false; }
    }
    if (!$authOk) {
        $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
        if ($cookie && function_exists('validate_session_token')) {
            $row = validate_session_token($cookie);
            if ($row) {
                $role = get_user_meta((int)$row['user_id'], 'role');
                $caps = get_user_meta((int)$row['user_id'], 'capabilities') ?? [];
                if ($role === 'admin' || (is_array($caps) && in_array('manage_options', $caps, true))) {
                    $authOk = true;
                }
            }
        }
    }
    if (!$authOk && !empty($_SERVER['REMOTE_USER'])) { $authOk = true; }
    if (!$authOk) { http_response_code(403); echo 'forbidden'; exit; }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// End of file
