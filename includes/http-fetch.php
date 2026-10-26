<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/http-fetch.php
// Fetch a remote file or page safely (core upgrade plan C8e). Used by the import and export engine; any plugin may call it.
//
//   [$ok, $info] = qp_http_download('https://example.com/a.jpg', $tmpFile, ['max_bytes' => 10 * 1024 * 1024]);
//   [$ok, $info] = qp_http_get('https://example.com/api/products?page=1', ['headers' => ['Accept: application/json']]);
//
// What it guarantees:
//   - Only the schemes you allow (default https only; pass ['schemes' => ['https', 'http']] for plain http).
//   - The address is resolved once, every address must be public (no private, loopback, link-local, carrier-grade or reserved range),
//     and the connection is pinned to the address that was checked (CURLOPT_RESOLVE), so DNS cannot change between check and connect.
//     ['allow_private' => true] lifts the address rule (a local development server); it never lifts the other rules.
//     ['verify_tls' => false] skips certificate checks (a local server with a self-made certificate); callers pass it only together with allow_private.
//   - No credentials inside the address. Redirects are followed by hand, at most 3 by default, and every hop is checked again. Custom
//     headers and basic auth are dropped when a redirect leaves the host they were meant for.
//   - A size cap enforced while writing (default 20 MB for a download, 5 MB for a page), a connect and a total timeout, and a partial
//     file is always removed. The final file type is NOT decided here: check it where it is used (qp_handle_upload checks real MIME).
// Nothing here runs unless a caller asks, so it costs a request nothing.
//
// The updater's own downloader (qlopy_download_file in includes/updater.php) is for trusted, signed update packages and is not changed.

/** Is this address inside a range a public server must never reach? */
function qp_http_ip_is_public(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        // ranges the PHP flags do not cover: carrier-grade NAT, IETF protocol assignments, network benchmarking, documentation and multicast
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['192.0.2.0', 24], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4]] as [$base, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($base) & $mask)) return false;
        }
    } else {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        // an IPv4-mapped address is judged as the IPv4 address it carries
        if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return qp_http_ip_is_public(inet_ntop(substr($bin, 12)));
        $first = ord($bin[0]);
        if (($first & 0xfe) === 0xfc || $first === 0xff) return false;                 // unique local fc00::/7, multicast ff00::/8
        if ($first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) return false;           // link-local fe80::/10
    }
    return true;
}

/** Every IPv4 and IPv6 address a host name has. A literal address is its own answer. */
function qp_http_resolve(string $host): array {
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    $ips = gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record')) {
        foreach ((array)@dns_get_record($host, DNS_AAAA) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    return array_values(array_unique($ips));
}

/**
 * May this address be fetched? Returns [ok, message, ip to pin, parts].
 * Options: schemes (default ['https']), allow_private (default false).
 */
function qp_http_url_check(string $url, array $opts = []): array {
    $schemes = $opts['schemes'] ?? ['https'];
    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['scheme'])) return [false, 'Use a full web address (https://...).', null, null];
    $scheme = strtolower($p['scheme']);
    if (!in_array($scheme, $schemes, true)) return [false, 'This address must use ' . implode(' or ', $schemes) . '.', null, null];
    if (!empty($p['user']) || !empty($p['pass'])) return [false, 'No credentials in the address.', null, null];
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port < 1 || $port > 65535) return [false, 'The port is not valid.', null, null];
    $ips = qp_http_resolve((string)$p['host']);
    if (!$ips) return [false, 'The host name does not resolve.', null, null];
    $allowPrivate = !empty($opts['allow_private']);
    foreach ($ips as $ip) {
        if (!$allowPrivate && !qp_http_ip_is_public($ip)) return [false, 'That address points to a private or reserved network.', null, null];
    }
    // pin an IPv4 address when there is one (CURLOPT_RESOLVE takes IPv6 only in brackets on some builds)
    $pin = null;
    foreach ($ips as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $pin = $ip; break; }
    if ($pin === null) $pin = $ips[0];
    return [true, '', $pin, ['scheme' => $scheme, 'host' => trim((string)$p['host'], '[]'), 'port' => $port]];
}

/** Resolve a Location header against the address it came from. */
function qp_http_absolute_url(string $base, string $location): string {
    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location)) return $location;
    $p = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($location, '//') === 0) return $p['scheme'] . ':' . $location;
    if ($location !== '' && $location[0] === '/') return $origin . $location;
    $dir = isset($p['path']) ? preg_replace('~/[^/]*$~', '/', $p['path']) : '/';
    return $origin . ($dir === '' ? '/' : $dir) . $location;
}

/**
 * The shared fetch, writing the body to an open stream. Returns [ok, info or message].
 * info: status, bytes, content_type, final_url, headers (the last answer's headers, names in lower case).
 */
function qp_http_fetch_stream(string $url, $out, array $opts = []): array {
    if (!function_exists('curl_init')) return [false, 'The PHP curl extension is not installed.'];
    $maxBytes = (int)($opts['max_bytes'] ?? 20 * 1024 * 1024);
    $maxRedirects = max(0, min(10, (int)($opts['max_redirects'] ?? 3)));
    $headers = array_values(array_filter((array)($opts['headers'] ?? []), 'is_string'));
    $auth = isset($opts['basic_auth']) && is_string($opts['basic_auth']) ? $opts['basic_auth'] : null;
    $firstHost = null;

    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        [$ok, $msg, $pin, $parts] = qp_http_url_check($url, $opts);
        if (!$ok) return [false, $msg];
        $firstHost = $firstHost ?? strtolower($parts['host']);
        $sameHost = strtolower($parts['host']) === $firstHost;
        rewind($out); ftruncate($out, 0);
        $bytes = 0; $tooBig = false; $location = null; $contentType = ''; $respHeaders = [];
        $c = curl_init($url);
        $o = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$parts['host'] . ':' . $parts['port'] . ':' . $pin],
            CURLOPT_CONNECTTIMEOUT => (int)($opts['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => ($opts['verify_tls'] ?? true) !== false,
            CURLOPT_SSL_VERIFYHOST => ($opts['verify_tls'] ?? true) !== false ? 2 : 0,
            CURLOPT_USERAGENT => (string)($opts['user_agent'] ?? 'Qlopy-Fetch/1'),
            CURLOPT_HTTPHEADER => $sameHost ? $headers : [],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location, &$contentType, &$respHeaders) {
                if (stripos($line, 'HTTP/') === 0) $respHeaders = [];     // a new answer (a redirect hop starts again)
                elseif (strpos($line, ':') > 0) { [$hn, $hv] = explode(':', $line, 2); $respHeaders[strtolower(trim($hn))] = trim($hv); }
                if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                elseif (stripos($line, 'content-type:') === 0) $contentType = trim(substr($line, 13));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($out, &$bytes, &$tooBig, $maxBytes) {
                $bytes += strlen($data);
                if ($bytes > $maxBytes) { $tooBig = true; return 0; }   // returning less than given aborts the transfer
                return fwrite($out, $data) === strlen($data) ? strlen($data) : 0;
            },
        ];
        if ($auth !== null && $sameHost) $o[CURLOPT_USERPWD] = $auth;
        curl_setopt_array($c, $o);
        curl_exec($c);
        $status = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $err = curl_error($c);
        curl_close($c);

        if ($tooBig) return [false, 'The file is larger than the allowed ' . round($maxBytes / 1048576, 1) . ' MB.'];
        if ($status >= 300 && $status < 400 && $location !== null) {
            if ($hop === $maxRedirects) return [false, 'Too many redirects.'];
            $url = qp_http_absolute_url($url, $location);
            continue;
        }
        if ($status === 0) return [false, $err !== '' ? $err : 'The server did not answer.'];
        if ($status < 200 || $status >= 300) return [false, 'The server answered HTTP ' . $status . '.'];
        return [true, ['status' => $status, 'bytes' => $bytes, 'content_type' => strtolower(trim(explode(';', $contentType)[0])), 'final_url' => $url, 'headers' => $respHeaders]];
    }
    return [false, 'Too many redirects.'];
}

/**
 * Download a file to $dest (written to a temporary name and renamed only when complete).
 * Returns [true, info] with path, bytes, content_type, final_url, status; or [false, message].
 */
function qp_http_download(string $url, string $dest, array $opts = []): array {
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return [false, 'The destination folder could not be created.'];
    $tmp = $dest . '.part' . bin2hex(random_bytes(4));
    $fh = @fopen($tmp, 'w+b');
    if (!$fh) return [false, 'The destination could not be written.'];
    try {
        [$ok, $info] = qp_http_fetch_stream($url, $fh, $opts);
    } finally {
        fclose($fh);
    }
    if (!$ok) { @unlink($tmp); return [false, $info]; }
    if (!@rename($tmp, $dest)) { @unlink($tmp); return [false, 'The file could not be saved.']; }
    return [true, ['path' => $dest] + $info];
}

/** Fetch a page or API answer into memory (at most 5 MB unless max_bytes says otherwise). Returns [true, body, info] or [false, message, []]. */
function qp_http_get(string $url, array $opts = []): array {
    $opts += ['max_bytes' => 5 * 1024 * 1024];
    $fh = fopen('php://temp/maxmemory:' . (int)$opts['max_bytes'], 'w+b');
    [$ok, $info] = qp_http_fetch_stream($url, $fh, $opts);
    if (!$ok) { fclose($fh); return [false, $info, []]; }
    rewind($fh);
    $body = stream_get_contents($fh);
    fclose($fh);
    return [true, (string)$body, $info];
}
