<?php
// Updater migration (manifest "migration_script"): adds the post_meta(meta_key, post_id) index, in place only.
// A skipped index is not an error: the site works the same without it, so this never fails the update.
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/schema.php';
$r = qp_schema_add_post_meta_index();
if (function_exists('qlopy_updater_log')) qlopy_updater_log('C1d post_meta index: ' . $r['status'] . ($r['reason'] !== '' ? ' (' . $r['reason'] . ')' : ''));
return true;
