<?php
// admin/attachment-download.php
require_once __DIR__ . '/admin_head.php';
if (!is_logged_in() || !check_permission('manage_options')) { http_response_code(403); echo 'Access denied'; exit; }
$rel = $_GET['file'] ?? '';
if (!$rel) { http_response_code(400); echo 'file required'; exit; }
$uploadsDir = null;
if (function_exists('qp_uploads_base')) {
	$uploadsDir = qp_uploads_base();
} elseif (!empty($GLOBALS['config']['uploads_dir'])) {
	$uploadsDir = $GLOBALS['config']['uploads_dir'];
} elseif (!empty($GLOBALS['config']['upload_dir'])) {
	$uploadsDir = $GLOBALS['config']['upload_dir'];
} else {
	$uploadsDir = __DIR__ . '/../uploads';
}
$uploadsDir = rtrim($uploadsDir, '/\\');
$base = realpath($uploadsDir);
$path = realpath($uploadsDir . DIRECTORY_SEPARATOR . ltrim($rel, '/\\'));
if (!$base || !$path) { http_response_code(404); echo 'Not found'; exit; }
// Ensure file is within uploads directory
if (strpos($path, $base) !== 0) { http_response_code(403); echo 'Forbidden'; exit; }
if (!is_file($path) || !is_readable($path)) { http_response_code(404); echo 'Not found'; exit; }
$basename = basename($path);
$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $basename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
?>