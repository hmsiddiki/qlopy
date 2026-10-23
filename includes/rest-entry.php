<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/rest-entry.php
// Entry for the REST API (core upgrade plan C4). index.php includes this file only when the request URL contains "/api/" or the
// query has qp_rest; every other request never reads it. The API runs before the theme is resolved and before page caching, and
// loads auth.php, the plugin loader and the route registrations, nothing else.
//
// Address:   <site>/api/<namespace>/<route>      e.g. /api/qlopy/v1/posts
// Fallback:  <site>/index.php?qp_rest=/qlopy/v1/posts   (hosts whose server does not send unknown paths to index.php)
// A path under /api/ whose namespace nobody registered is NOT claimed: the request carries on as a normal page request.

/** The API route ("/qlopy/v1/posts") for this request, or null when the request is not for the API. */
function qp_rest_request_route(array $config): ?string {
    if (isset($_GET['qp_rest']) && is_string($_GET['qp_rest'])) return '/' . ltrim(rawurldecode($_GET['qp_rest']), '/');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? rawurldecode($path) : '/';
    $base = rtrim((string)(parse_url((string)($config['site_url'] ?? ''), PHP_URL_PATH) ?? ''), '/');
    if ($base !== '' && stripos($path, $base) === 0) $path = substr($path, strlen($base));
    $path = '/' . ltrim($path, '/');
    if (strncmp($path, '/api/', 5) !== 0) return null;
    $route = substr($path, 4);
    return rtrim($route, '/') === '' ? '/' : rtrim($route, '/');
}

/** The URL of an API route, for themes and plugins: qp_rest_url('/qlopy/v1/posts'). */
function qp_rest_url(string $route = '/qlopy/v1/'): string {
    return (defined('SITE_URL') ? SITE_URL : '') . '/api/' . ltrim($route, '/');
}

/** Second half of the entry, after index.php has loaded auth.php and the plugins at global scope (those files expect it). */
function qp_rest_finish(string $route): void {
    $ns = explode('/', ltrim($route, '/'))[0] ?? '';
    require_once __DIR__ . '/rest-routes.php';
    if ($route === '/qlopy/oauth/authorize') { require_once __DIR__ . '/rest-oauth.php'; qp_oauth_authorize_page($GLOBALS['config'] ?? []); exit; }   // the consent page is HTML, not JSON
    if (function_exists('do_action')) do_action('qp_rest_init');
    $known = false;
    foreach (array_keys(qp_rest_routes_ref()) as $registered) if (strncmp($registered, $ns . '/', strlen($ns) + 1) === 0) { $known = true; break; }
    if (!$known) { while (ob_get_level() > 0) ob_end_clean(); return; }   // not ours: carry on as an ordinary request
    qp_rest_serve($route);
    exit;
}