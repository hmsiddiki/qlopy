<?php
require_once __DIR__ . '/admin_head.php';

$page_title = 'Permalinks';
if ( !current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
// Load current settings (used if the request is not a POST)
$structure = permalink_get_structure();
$patterns = permalink_get_patterns();
// Prepare textarea contents from saved patterns so the form shows current mappings
$pt_text = '';
if (!empty($patterns['post_types']) && is_array($patterns['post_types'])) {
    foreach ($patterns['post_types'] as $k => $v) {
        $pt_text .= $k . ' = ' . $v . "\n";
    }
    $pt_text = rtrim($pt_text, "\n");
}
$tax_text = '';
if (!empty($patterns['taxonomies']) && is_array($patterns['taxonomies'])) {
    foreach ($patterns['taxonomies'] as $k => $v) {
        $tax_text .= $k . ' = ' . $v . "\n";
    }
    $tax_text = rtrim($tax_text, "\n");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosen = $_POST['structure'] ?? 'query';
    permalink_set_structure($chosen);
    // Save patterns
    $patterns['page'] = trim($_POST['pattern_page'] ?? $patterns['page']);
    $patterns['post'] = trim($_POST['pattern_post'] ?? $patterns['post']);
    $patterns['taxonomy'] = trim($_POST['pattern_taxonomy'] ?? $patterns['taxonomy']);
    $patterns['search'] = trim($_POST['pattern_search'] ?? $patterns['search']);
    // Parse CPT mappings
    $ptRaw = $_POST['pattern_post_types'] ?? '';
    $ptMap = [];
    foreach (preg_split('/\r?\n/', $ptRaw) as $line) {
        if (!trim($line)) continue;
        if (strpos($line, '=') !== false) {
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            if ($k && $v) $ptMap[$k] = $v;
        }
    }
    $patterns['post_types'] = $ptMap;
    // Parse taxonomy mappings
    $taxRaw = $_POST['pattern_taxonomies_map'] ?? '';
    $taxMap = [];
    foreach (preg_split('/\r?\n/', $taxRaw) as $line) {
        if (!trim($line)) continue;
        if (strpos($line, '=') !== false) {
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            if ($k && $v) $taxMap[$k] = $v;
        }
    }
    $patterns['taxonomies'] = $taxMap;
    permalink_set_patterns($patterns);
    // Flush compiled rewrite rules cache so runtime uses updated permalink settings
    if (file_exists(__DIR__ . '/../includes/query-vars.php')) {
        require_once __DIR__ . '/../includes/query-vars.php';
        if (function_exists('flush_rewrite_rules')) {
            $ok = flush_rewrite_rules();
            if ($ok) {
                // optional: set a small admin notice
                $msg = 'Permalink settings saved; rewrite rules cache flushed.';
            } else {
                $msg = 'Permalink settings saved; failed to flush rewrite cache (check permissions).';
            }
        }
    }
    // Post-Redirect-Get: store a flash message in session and redirect
    // to the same page to avoid browser form-resubmission on reload.
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (empty($msg)) $msg = 'Permalink settings saved';
    $_SESSION['admin_notice'] = $msg;
    $redirect = strtok($_SERVER['REQUEST_URI'] ?? '/admin/permalinks.php', '?');
    header('Location: ' . $redirect);
    exit;
    // Rebuild textarea contents from the just-saved patterns so the form
    // immediately shows the updated CPT/taxonomy mappings without requiring
    // a separate page reload.
    $pt_text = '';
    if (!empty($patterns['post_types']) && is_array($patterns['post_types'])) {
        foreach ($patterns['post_types'] as $k => $v) {
            $pt_text .= $k . ' = ' . $v . "\n";
        }
        $pt_text = rtrim($pt_text, "\n");
    }
    $tax_text = '';
    if (!empty($patterns['taxonomies']) && is_array($patterns['taxonomies'])) {
        foreach ($patterns['taxonomies'] as $k => $v) {
            $tax_text .= $k . ' = ' . $v . "\n";
        }
        $tax_text = rtrim($tax_text, "\n");
    }
}
// Include admin header after processing POST so the page renders with
// the freshly-saved permalink values and not the previous ones.
require_once __DIR__ . '/inc/header.php';
?>
<div class="container mt-4">
    <h1>Permalink Settings</h1>
    <?php if (!empty($_SESSION['admin_notice'])) { echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['admin_notice']) . '</div>'; unset($_SESSION['admin_notice']); } ?>
    <form method="post">
        <div class="form-group">
            <label>Structure</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="structure" id="plQuery" value="query" <?= $structure==='query'?'checked':'' ?>>
                <label class="form-check-label" for="plQuery">Default (query): index.php?page=slug</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="structure" id="plPretty" value="pretty" <?= $structure==='pretty'?'checked':'' ?>>
                <label class="form-check-label" for="plPretty">Pretty: /slug</label>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <h5 class="card-title">Patterns (Pretty mode)</h5>
                <div class="form-group">
                    <label>Page pattern</label>
                    <input type="text" class="form-control" name="pattern_page" value="<?= htmlspecialchars($patterns['page']) ?>" placeholder="/%slug%">
                    <small class="form-text text-muted">Available tags: %slug%</small>
                </div>
                <div class="form-group">
                    <label>Post pattern</label>
                    <input type="text" class="form-control" name="pattern_post" value="<?= htmlspecialchars($patterns['post']) ?>" placeholder="/post/%slug%">
                    <small class="form-text text-muted">Available tags: %slug%</small>
                </div>
                <div class="form-group">
                    <label>Taxonomy pattern</label>
                    <input type="text" class="form-control" name="pattern_taxonomy" value="<?= htmlspecialchars($patterns['taxonomy']) ?>" placeholder="/%taxonomy%/%term%">
                    <small class="form-text text-muted">Available tags: %taxonomy%, %term%</small>
                </div>
                <div class="form-group">
                    <label>Search pattern</label>
                    <input type="text" class="form-control" name="pattern_search" value="<?= htmlspecialchars($patterns['search']) ?>" placeholder="/search/%q%">
                    <small class="form-text text-muted">Available tags: %q%</small>
                </div>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <h5 class="card-title">Custom Post Types</h5>
                <p class="text-muted">Define mappings like <code>/product/%slug%</code>. Enter one per line as <code>type = /type/%slug%</code>.</p>
                <textarea class="form-control" name="pattern_post_types" rows="4" placeholder="product = /product/%slug%&#10;news = /news/%slug%"><?= htmlspecialchars($pt_text) ?></textarea>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <h5 class="card-title">Taxonomies</h5>
                <p class="text-muted">Define mappings like <code>/tag/%term%</code>. Enter one per line as <code>taxonomy = /taxonomy/%term%</code>.</p>
                <textarea class="form-control" name="pattern_taxonomies_map" rows="4" placeholder="tag = /tag/%term%&#10;category = /category/%term%"><?= htmlspecialchars($tax_text) ?></textarea>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
    <hr />
    <p class="text-muted">This lightweight rewrite respects your front controller. Pretty URLs are generated client-side and do not change server routing; ensure your router parses the path accordingly.</p>
</div>
<?php include __DIR__ . '/inc/footer.php'; //print_admin_footer_scripts(); ?>