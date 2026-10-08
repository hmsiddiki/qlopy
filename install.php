<?php
// Public entrypoint: mark authorized initialization for include-only files
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);
// install.php - multi-step installer for Qlopy

// Installer uses a short-lived temp-file + cookie flow for state instead of PHP sessions.
// This avoids requiring DB or config.php to persist state across install steps.

$basePath = __DIR__;

function _installer_base_path() {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($base === '/' || $base === '.') return '';
    return $base;
}

function _installer_token_file_path($token) {
    $safe = preg_replace('/[^a-z0-9]/i', '', (string)$token);
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'qp_install_' . $safe . '.json';
}
function set_install_state(array $data) {
    try { $token = bin2hex(random_bytes(10)); } catch (Exception $e) { $token = bin2hex(openssl_random_pseudo_bytes(10)); }
    $path = _installer_token_file_path($token);
    @file_put_contents($path, json_encode($data));
    @chmod($path, 0600);
    setcookie('qp_install_token', $token, time() + 3600, '/', '', false, true);
    return $token;
}
function get_install_state() {
    $token = $_COOKIE['qp_install_token'] ?? '';
    if (!$token) return null;
    $path = _installer_token_file_path($token);
    if (!file_exists($path)) return null;
    $json = @file_get_contents($path);
    if ($json === false) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}
function clear_install_state() {
    $token = $_COOKIE['qp_install_token'] ?? '';
    if ($token) {
        $path = _installer_token_file_path($token);
        if (file_exists($path)) @unlink($path);
        setcookie('qp_install_token', '', time() - 3600, '/', '', false, true);
    }
}

// Helper: compute base path portion of URL (e.g. '/advcms' or '')
// If config already exists, don't run installer
if (file_exists($basePath . '/config.php')) {
    $base = _installer_base_path();
    header('Location: ' . ($base ?: '/') );
    exit;
}

function render_header($title = 'Installer') {
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . htmlspecialchars($title) . "</title>";
    echo "<link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css\">";
    echo "</head><body class=\"bg-light\"><div class=\"container py-5\">";
}
function render_footer() {
    echo "</div></body></html>";
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['step']) && $_POST['step'] == '1') {
        // Save DB connection info to a temp-file + cookie and try to connect
        $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
        $db_name = trim($_POST['db_name'] ?? 'qlopy_db');
        $db_user = trim($_POST['db_user'] ?? 'root');
        $db_pass = trim($_POST['db_pass'] ?? '');
        $table_prefix = trim($_POST['table_prefix'] ?? 'qp_');
        $state = compact('db_host','db_name','db_user','db_pass');
        $state['table_prefix'] = $table_prefix;
        set_install_state($state);

        try {
            $pdo = new PDO("mysql:host={$db_host}", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            // create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $base = _installer_base_path();
            header('Location: ' . $base . '/install.php?step=2');
            exit;
        } catch (PDOException $e) {
            $error = 'DB connection failed: ' . $e->getMessage();
        }
    }

    if (isset($_POST['step']) && $_POST['step'] == '2') {
        // Site & admin info - finalize installation
        $site_url = rtrim(trim($_POST['site_url'] ?? ''), '/');
        $site_name = trim($_POST['site_name'] ?? 'My Site');
        $admin_user = trim($_POST['admin_user'] ?? 'admin');
        $admin_email = trim($_POST['admin_email'] ?? 'admin@example.com');
        $admin_pass = $_POST['admin_pass'] ?? '';
        $admin_pass_repeat = $_POST['admin_pass_repeat'] ?? '';
        // Do not ask admin directory during install; default to 'admin'
        $admin_dir = 'admin';

        if (empty($admin_pass) || empty($admin_user) || empty($admin_email) || empty($site_url)) {
            $error = 'Please fill all required fields.';
        } elseif ($admin_pass !== $admin_pass_repeat) {
            $error = 'Passwords do not match.';
        } else {
            $db = get_install_state();
            if (!$db) { $error = 'Missing DB configuration. Please start from step 1.'; }
            else {
                try {
                    $pdo = new PDO("mysql:host={$db['db_host']};dbname={$db['db_name']}", $db['db_user'], $db['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                    // Use table prefix from installer session
                    $prefix = $db['table_prefix'] ?? 'qp_';

                    // Create schema using the configured prefix
                    $sql = "CREATE TABLE IF NOT EXISTS {$prefix}users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS {$prefix}user_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value TEXT,
    UNIQUE KEY user_meta_unique (user_id, meta_key)
);

    CREATE TABLE IF NOT EXISTS {$prefix}posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    content TEXT NOT NULL,
    post_type VARCHAR(50) NOT NULL,
    author_id INT NOT NULL DEFAULT 0,
    -- status: draft, published, pending_review (requires admin approval), scheduled (waiting for publish), trash (soft-deleted)
    status ENUM('draft','published','pending_review','scheduled','trash') DEFAULT 'draft',
    -- visibility: public, password, private
    visibility VARCHAR(20) NOT NULL DEFAULT 'public',
    -- optional password for password-protected posts
    post_password VARCHAR(255) DEFAULT NULL,
    -- canonical publish timestamp. NULL means not published yet.
    published_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (published_at),
    INDEX (visibility)
);

CREATE TABLE IF NOT EXISTS {$prefix}post_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value TEXT,
    UNIQUE KEY post_meta_unique (post_id, meta_key)
);

CREATE TABLE IF NOT EXISTS {$prefix}comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    object_type VARCHAR(50) NOT NULL,
    object_id INT NOT NULL,
    parent_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    author_name VARCHAR(255) DEFAULT NULL,
    author_email VARCHAR(100) DEFAULT NULL,
    author_url VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    content TEXT NOT NULL,
    status ENUM('approved','pending','spam','trash') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_comments_object (object_type, object_id),
    INDEX idx_comments_parent (parent_id),
    INDEX idx_comments_status (status)
);

CREATE TABLE IF NOT EXISTS {$prefix}comment_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comment_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value TEXT,
    UNIQUE KEY comment_meta_unique (comment_id, meta_key)
);

CREATE TABLE IF NOT EXISTS {$prefix}taxonomy_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taxonomy VARCHAR(50) NOT NULL,
    term VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    parent_id INT DEFAULT NULL,
    term_order INT DEFAULT 0
);

CREATE TABLE IF NOT EXISTS {$prefix}term_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value TEXT,
    UNIQUE KEY term_meta_unique (term_id, meta_key)
);

CREATE TABLE IF NOT EXISTS {$prefix}post_terms (
    post_id INT NOT NULL,
    term_id INT NOT NULL,
    PRIMARY KEY (post_id, term_id)
);

CREATE TABLE IF NOT EXISTS {$prefix}site_options (
    option_name VARCHAR(100) PRIMARY KEY,
    option_value TEXT
);
";

                    // Execute SQL statements (split by semicolon safely)
                    $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
                    foreach ($stmts as $s) {
                        if ($s) $pdo->exec($s);
                    }

                    // Ensure user_meta.meta_value can store large serialized values
                    // If you prefer to run migrations manually, see `sql/20251209_alter_user_meta_longtext.sql`.
                    try {
                        $alterSql = "ALTER TABLE " . $prefix . "user_meta MODIFY meta_value LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                        $pdo->exec($alterSql);
                    } catch (Exception $_e) {
                        // Non-fatal: if ALTER fails (insufficient privileges), installer continues.
                    }

                    // Add performance indexes for taxonomy queries (best-effort)
                    try {
                        $pdo->exec("ALTER TABLE " . $prefix . "taxonomy_terms ADD INDEX idx_taxonomy_slug (taxonomy, slug)");
                    } catch (Exception $_e) {
                        // ignore if already exists or no permission
                    }
                    try {
                        $pdo->exec("ALTER TABLE " . $prefix . "taxonomy_terms ADD INDEX idx_taxonomy_parent (taxonomy, parent_id)");
                    } catch (Exception $_e) {
                    }
                    try {
                        $pdo->exec("ALTER TABLE " . $prefix . "post_terms ADD INDEX idx_post_terms_term_id (term_id)");
                    } catch (Exception $_e) {
                    }


                    // Insert default options
                    $stmt = $pdo->prepare("INSERT INTO " . $prefix . "site_options (option_name, option_value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
                    $stmt->execute(['name' => 'site_name', 'value' => $site_name]);
                    $stmt->execute(['name' => 'site_url', 'value' => $site_url]);
                    $stmt->execute(['name' => 'active_theme', 'value' => 'default']);

                    // Create admin user
                    $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                    // Insert admin user (username, email, password_hash)
                    $stmt = $pdo->prepare("INSERT INTO " . $prefix . "users (username, email, password_hash) VALUES (?, ?, ?)");
                    $stmt->execute([$admin_user, $admin_email, $hash]);
                    $admin_id = $pdo->lastInsertId();
                    // store role and capabilities in user_meta
                    $stmt = $pdo->prepare("INSERT INTO " . $prefix . "user_meta (user_id, meta_key, meta_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
                    $stmt->execute([$admin_id, 'role', 'admin']);
                    $capabilities = serialize(['is_super_admin','manage_posts','manage_users','manage_options','manage_themes','manage_plugins','edit_posts','publish_posts','delete_posts','edit_pages','publish_pages','delete_pages','moderate_comments','manage_comments','manage_categories','upload_files','edit_others_posts','edit_others_pages','delete_others_posts','delete_others_pages','manage_menus','manage_widgets','manage_admin_pages']);
                    $stmt->execute([$admin_id, 'capabilities', $capabilities]);

                    // Create default category, sample page and welcome post
                    try {
                        // Default category
                        $stmt_cat = $pdo->prepare("INSERT INTO " . $prefix . "taxonomy_terms (taxonomy, term, slug, parent_id, term_order) VALUES (?, ?, ?, ?, ?)");
                        $stmt_cat->execute(['category', 'uncategorised', 'uncategorised', null, 0]);
                        $default_cat_id = $pdo->lastInsertId();

                        // Sample Page (published)
                        $about_content = 'This is a sample page. Edit or replace this page from the admin area.';
                        $stmt_page = $pdo->prepare("INSERT INTO " . $prefix . "posts (title, slug, content, post_type, status, author_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt_page->execute(['Sample Page', 'sample-page', $about_content, 'page', 'published', $admin_id]);
                        $about_page_id = $pdo->lastInsertId();

                        // Welcome post (published)
                        $post_content = 'Welcome to your new site! This is a sample post you can edit or delete.';
                        $stmt_post = $pdo->prepare("INSERT INTO " . $prefix . "posts (title, slug, content, post_type, status, author_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt_post->execute(['Welcome', 'welcome-to-your-site', $post_content, 'post', 'published', $admin_id]);
                        $welcome_post_id = $pdo->lastInsertId();

                        // Associate welcome post with default category
                        $stmt_pt = $pdo->prepare("INSERT INTO " . $prefix . "post_terms (post_id, term_id) VALUES (?, ?)");
                        $stmt_pt->execute([$welcome_post_id, $default_cat_id]);

                        // Set front page option to theme index (no static front page selected)
                        $stmt_opt = $pdo->prepare("INSERT INTO " . $prefix . "site_options (option_name, option_value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
                        $stmt_opt->execute(['name' => 'front_page_option', 'value' => 'theme']);
                    } catch (Exception $e) {
                        // Non-fatal: continue installation even if sample content creation fails
                    }

                    // Write config.php
                    $security_salt = bin2hex(random_bytes(16));
                    $config_array = [
                        'db_host' => $db['db_host'],
                        'db_name' => $db['db_name'],
                        'db_user' => $db['db_user'],
                        'db_pass' => $db['db_pass'],
                        'db_table_prefix' => $prefix,
                        'site_url' => $site_url,
                        'admin_dir' => $admin_dir,
                        'uploads_dir' => 'uploads',
                        'content_dir' => 'content',
                        'themes_dir' => 'themes',
                        'plugins_dir' => 'plugins',
                        'security_salt' => $security_salt
                    ];

                    $config_php = "<?php\nif (!defined('QLOPY_INIT')) { http_response_code(403); exit; }\n// Auto-generated config file - rename or delete to re-run installer\nreturn " . var_export($config_array, true) . ";\n";
                    file_put_contents($basePath . '/config.php', $config_php);

                    // Cleanup installer state
                    clear_install_state();
                    // Fire install-complete hook so plugins or core handlers can react (e.g. send welcome email)
                    // Ensure hook and default install listener are available.
                    $hooksFile = __DIR__ . '/includes/ajax-hooks.php';
                    if (file_exists($hooksFile)) require_once $hooksFile;
                    $welcome = __DIR__ . '/includes/install-welcome.php';
                    if (file_exists($welcome)) require_once $welcome;
                    // Fire the hook with admin and site details
                    if (function_exists('do_action')) {
                        do_action('qp_install_complete', (int)$admin_id, $admin_user, $admin_email, $site_name, $site_url);
                    }

                    $base = _installer_base_path();
                    header('Location: ' . $base . '/install.php?step=3');
                    exit;

                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Render forms for steps
    if ($step === 1) {
    render_header('Step 1 — Database');
    echo '<div class="card"><div class="card-body">';
    echo '<h4>Step 1: Database Configuration</h4>';
    if (!empty($error)) echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    $db = get_install_state() ?? ['db_host'=>'127.0.0.1','db_name'=>'','db_user'=>'root','db_pass'=>''];
    echo '<form method="post">';
    echo '<input type="hidden" name="step" value="1">';
    echo '<div class="form-group"><label>DB Host</label><input name="db_host" class="form-control" value="' . htmlspecialchars($db['db_host']) . '"></div>';
    echo '<div class="form-group"><label>DB Name</label><input name="db_name" class="form-control" value="' . htmlspecialchars($db['db_name']) . '"></div>';
    echo '<div class="form-group"><label>DB User</label><input name="db_user" class="form-control" value="' . htmlspecialchars($db['db_user']) . '"></div>';
    echo '<div class="form-group"><label>DB Password</label><input name="db_pass" type="password" class="form-control" value="' . htmlspecialchars($db['db_pass']) . '"></div>';
    echo '<div class="form-group"><label>Table Prefix</label><input name="table_prefix" class="form-control" value="' . htmlspecialchars($db['table_prefix'] ?? 'qp_') . '"><small class="form-text text-muted">Prefix for DB tables (default "qp_")</small></div>';
    echo '<button class="btn btn-primary">Continue</button>';
    echo '</form>';
    echo '</div></div>';
    render_footer();
    exit;
}

if ($step === 2) {
    render_header('Step 2 — Site & Admin');
    echo '<div class="card"><div class="card-body">';
    echo '<h4>Step 2: Site and Admin User</h4>';
    if (!empty($error)) echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    $db = get_install_state() ?? null;
    if (!$db) {
        $base = _installer_base_path();
        echo '<div class="alert alert-warning">Missing DB info. <a href="' . htmlspecialchars($base . '/install.php?step=1') . '">Start step 1</a></div>';
        render_footer(); exit;
    }
    echo '<form method="post">';
    echo '<input type="hidden" name="step" value="2">';
    $base = _installer_base_path();
    $default_site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . ($base ?: '');
    echo '<div class="form-group"><label>Site URL</label><input name="site_url" class="form-control" value="' . htmlspecialchars($default_site_url) . '"></div>';
    echo '<div class="form-group"><label>Site Name</label><input name="site_name" class="form-control" value="My Site"></div>';
    echo '<div class="form-group"><label>Admin Username</label><input name="admin_user" class="form-control" value="admin"></div>';
    echo '<div class="form-group"><label>Admin Email</label><input name="admin_email" class="form-control" value="admin@example.com"></div>';
    echo '<div class="form-group"><label>Admin Password</label><input name="admin_pass" type="password" class="form-control"></div>';
    echo '<div class="form-group"><label>Repeat Admin Password</label><input name="admin_pass_repeat" type="password" class="form-control"></div>';
    echo '<button class="btn btn-primary">Install</button>';
    echo '</form>';
    echo '</div></div>';
    render_footer();
    exit;
}

if ($step === 3) {
    render_header('Complete');
    echo '<div class="card"><div class="card-body">';
    echo '<h4>Installation Complete</h4>';
    echo '<p>Installation finished successfully. For security, remove or restrict access to <code>install.php</code>.</p>';
    $base = _installer_base_path();
    echo '<p><a class="btn btn-success" href="' . htmlspecialchars($base ?: '/') . '">Go to site</a> <a class="btn btn-secondary" href="' . htmlspecialchars($base . '/admin/login.php') . '">Admin Login</a></p>';
    echo '</div></div>';
    render_footer();
    exit;
}

// Default: redirect to step 1
// Default: redirect to step 1 (use computed base)
$base = _installer_base_path();
header('Location: ' . $base . '/install.php?step=1');
exit;
