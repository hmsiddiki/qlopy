<?php
require_once __DIR__ . '/admin_head.php';

$page_title = 'General Settings';

if ( !current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/navbar.php';

// Fetch all pages (post_type 'page') for static page front option
$stmt_pages = $pdo->prepare("SELECT id, title FROM " . table_name('posts') . " WHERE post_type='page' ORDER BY title");
$stmt_pages->execute();
$pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_name = trim($_POST['site_name'] ?? '');
    $site_tagline = trim($_POST['site_tagline'] ?? '');
    $front_page_option = $_POST['front_page_option'] ?? 'theme'; // default 'theme'
    $front_page_id = intval($_POST['front_page_id'] ?? 0);
    $menu_max_depth = intval($_POST['menu_max_depth'] ?? 2);
    $assets_cache_ttl = intval($_POST['assets_cache_ttl'] ?? 0);

    // Validate selected page for static front page
    if ($front_page_option === 'static' && $front_page_id > 0) {
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE id = ? AND post_type='page'");
        $stmt_check->execute([$front_page_id]);
        if ($stmt_check->fetchColumn() == 0) {
            $front_page_id = 0; // reset if invalid
        }
    } else {
        $front_page_id = 0; // reset if not static
    }

    $options = [
        'site_name'          => $site_name,
        'site_tagline'       => $site_tagline,
        'front_page_option'  => $front_page_option,
        'front_page_id' => $front_page_id,
        'menu_max_depth'     => $menu_max_depth,
        'assets_cache_ttl'   => $assets_cache_ttl,
        // timezone & display formats
        'timezone_string'    => trim($_POST['timezone_string'] ?? ''),
        'date_format'        => trim($_POST['date_format'] ?? ''),
        'time_format'        => trim($_POST['time_format'] ?? ''),
        'week_starts_on'     => intval($_POST['week_starts_on'] ?? 1),
    ];

    foreach ($options as $name => $value) {
        $stmt = $pdo->prepare("INSERT INTO " . table_name('site_options') . " (option_name, option_value) VALUES (?, ?) 
                       ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
        $stmt->execute([$name, $value]);
    }
    echo '<div class="alert alert-success mt-3">Settings saved.</div>';

    // Friendly notice if user requested deeper-than-default menus (3 or more)
    if ($menu_max_depth > 2) {
        echo '<div class="alert alert-warning mt-2">';
        echo 'You set Menu Max Depth to ' . htmlspecialchars($menu_max_depth) . '. Note: Bootstrap 4 and 5 do not provide full multi-level dropdown support out-of-the-box beyond two levels. For deeper menus consider using the <strong>Simple</strong> or <strong>Plain</strong> framework option, or adjust your theme to handle deep menus.';
        echo '</div>';
    }
}

function get_site_option($pdo, $name, $default='') {
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

$site_name = get_site_option($pdo, 'site_name', 'My Qlopy');
$site_tagline = get_site_option($pdo, 'site_tagline', '');
$front_page_option = get_site_option($pdo, 'front_page_option', 'theme');
$front_page_id = intval(get_site_option($pdo, 'front_page_id', 0));
$menu_max_depth = intval(get_site_option($pdo, 'menu_max_depth', 2));
$assets_cache_ttl = intval(get_site_option($pdo, 'assets_cache_ttl', ''));
// New settings
$site_timezone = get_site_option($pdo, 'timezone_string', 'UTC');
$site_date_format = get_site_option($pdo, 'date_format', 'F j, Y');
$site_time_format = get_site_option($pdo, 'time_format', 'g:i a');
$site_week_start = intval(get_site_option($pdo, 'week_starts_on', 1));
?>

<div class="container mt-4">
<h2>General Settings</h2>
<form method="POST" novalidate>
    <div class="form-group">
        <label for="site_name">Site Name</label>
        <input id="site_name" name="site_name" type="text" class="form-control" value="<?= htmlspecialchars($site_name) ?>" required>
    </div>

    <div class="form-group">
        <label for="site_tagline">Site Tagline</label>
        <input id="site_tagline" name="site_tagline" type="text" class="form-control" value="<?= htmlspecialchars($site_tagline) ?>">
    </div>

    <!-- Permalink Structure removed: managed via dedicated Permalinks settings -->

    <div class="form-group">
        <label for="front_page_option">Front Page Option</label>
        <select id="front_page_option" name="front_page_option" class="form-control" onchange="togglePageSelector()">
            <option value="theme" <?= $front_page_option === 'theme' ? 'selected' : '' ?>>Theme Index Page</option>
            <option value="archive" <?= $front_page_option === 'archive' ? 'selected' : '' ?>>Archive Page</option>
            <option value="static" <?= $front_page_option === 'static' ? 'selected' : '' ?>>Static Page</option>
        </select>
    </div>

    <div class="form-group">
        <label for="menu_max_depth">Menu Max Depth</label>
        <input id="menu_max_depth" name="menu_max_depth" type="number" min="0" max="10" class="form-control" value="<?= htmlspecialchars($menu_max_depth) ?>">
        <small class="form-text text-muted">Maximum nested menu levels to render. Themes/plugins can still override via the <code>menu_max_depth</code> filter.</small>
    </div>

    <div class="form-group" id="pageSelectContainer">
        <label for="front_page_id">Select Page for Front Page</label>
        <select id="front_page_id" name="front_page_id" class="form-control" <?= $front_page_option !== 'static' ? 'disabled' : '' ?>>
            <option value="0">-- Select a Page --</option>
            <?php foreach ($pages as $page): ?>
                <option value="<?= $page['id'] ?>" <?= $front_page_id === intval($page['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($page['title']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>

    <div class="form-group">
        <label for="assets_cache_ttl">Assets Cache TTL (seconds)</label>
        <input id="assets_cache_ttl" name="assets_cache_ttl" type="number" min="0" class="form-control" value="<?= htmlspecialchars($assets_cache_ttl) ?>">
        <small class="form-text text-muted">Server-side cache TTL for generated asset HTML. Set to 0 to disable caching. Default: 300s (short). In production you may want a larger value.</small>
    </div>

    <h4 class="mt-4">Localization & Date/Time</h4>
    <div class="form-group">
        <label for="timezone_string">Timezone</label>
        <select id="timezone_string" name="timezone_string" class="form-control">
            <?php
            $zones = DateTimeZone::listIdentifiers();
            foreach ($zones as $z) {
                $sel = ($z === $site_timezone) ? 'selected' : '';
                echo '<option value="' . htmlspecialchars($z) . '" ' . $sel . '>' . htmlspecialchars($z) . '</option>';
            }
            ?>
        </select>
        <small class="form-text text-muted">Choose either a city in the same timezone as you or a UTC (Coordinated Universal Time) offset.</small>
        <div class="mt-2">Universal time is : <span id="tzPreview"></span></div>
    </div>

    <div class="form-group">
        <label>Date Format</label>
        <?php
        $date_choices = [
            'F j, Y' => date('F j, Y'),
            'Y-m-d' => date('Y-m-d'),
            'm/d/Y' => date('m/d/Y'),
            'd/m/Y' => date('d/m/Y'),
            'd.m.Y' => date('d.m.Y'),
        ];
        foreach ($date_choices as $fmt => $example) {
            $checked = ($fmt === $site_date_format) ? 'checked' : '';
            echo '<div class="form-check">';
            echo '<input class="form-check-input" type="radio" name="date_format" id="date_' . md5($fmt) . '" value="' . htmlspecialchars($fmt) . '" ' . $checked . '>';
            echo '<label class="form-check-label" for="date_' . md5($fmt) . '">' . htmlspecialchars($example) . ' <small class="text-muted">' . htmlspecialchars($fmt) . '</small></label>';
            echo '</div>';
        }
        $custom_date = !in_array($site_date_format, array_keys($date_choices));
        echo '<div class="form-check mt-2"><input class="form-check-input" type="radio" name="date_format" id="date_custom" value="' . htmlspecialchars($site_date_format) . '" ' . ($custom_date ? 'checked' : '') . '><label class="form-check-label" for="date_custom">Custom:</label> <input type="text" name="date_format_custom" value="' . ($custom_date ? htmlspecialchars($site_date_format) : '') . '" class="form-control" style="width:220px;display:inline-block;margin-left:8px;"> </div>';
        ?>
        <div class="mt-1"><small class="form-text text-muted">Preview: <span id="datePreview"></span></small></div>
    </div>

    <div class="form-group">
        <label>Time Format</label>
        <?php
        $time_choices = [
            'g:i a' => date('g:i a'),
            'g:i A' => date('g:i A'),
            'H:i' => date('H:i'),
        ];
        foreach ($time_choices as $fmt => $example) {
            $checked = ($fmt === $site_time_format) ? 'checked' : '';
            echo '<div class="form-check">';
            echo '<input class="form-check-input" type="radio" name="time_format" id="time_' . md5($fmt) . '" value="' . htmlspecialchars($fmt) . '" ' . $checked . '>';
            echo '<label class="form-check-label" for="time_' . md5($fmt) . '">' . htmlspecialchars($example) . ' <small class="text-muted">' . htmlspecialchars($fmt) . '</small></label>';
            echo '</div>';
        }
        $custom_time = !in_array($site_time_format, array_keys($time_choices));
        echo '<div class="form-check mt-2"><input class="form-check-input" type="radio" name="time_format" id="time_custom" value="' . htmlspecialchars($site_time_format) . '" ' . ($custom_time ? 'checked' : '') . '><label class="form-check-label" for="time_custom">Custom:</label> <input type="text" name="time_format_custom" value="' . ($custom_time ? htmlspecialchars($site_time_format) : '') . '" class="form-control" style="width:220px;display:inline-block;margin-left:8px;"> </div>';
        ?>
        <div class="mt-1"><small class="form-text text-muted">Preview: <span id="timePreview"></span></small></div>
    </div>

    <div class="form-group">
        <label for="week_starts_on">Week Starts On</label>
        <select id="week_starts_on" name="week_starts_on" class="form-control" style="width:auto;display:inline-block;">
            <?php
            $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            for ($i=0;$i<7;$i++) {
                $s = ($i === $site_week_start) ? 'selected' : '';
                echo '<option value="' . $i . '" ' . $s . '>' . $days[$i] . '</option>';
            }
            ?>
        </select>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
</form>
</div>

    <div class="container mt-4">
        <h3>Security</h3>
        <p>Rotate the site's security salt to immediately invalidate all other sessions (admins will be logged out). The account performing the rotation will remain logged in.</p>
        <button id="rotateSaltBtn" class="btn btn-warning">Rotate Security Salt</button>
        <div id="rotateResult" class="mt-2"></div>
    </div>

<script>
function togglePageSelector() {
    const frontOption = document.getElementById('front_page_option').value;
    const pageSelector = document.getElementById('front_page_id');
    if (pageSelector) pageSelector.disabled = frontOption !== 'static';
}
window.onload = togglePageSelector;
</script>

<script>
// Live previews for timezone/date/time
(function(){
    function updateTZPreview(){
        var sel = document.getElementById('timezone_string');
        if(!sel) return;
        try{
            var tz = sel.value;
            var now = new Date();
            // show UTC-based current time adjusted to tz by formatting via toLocaleString
            var opt = { timeZone: tz, hour12: true, year:'numeric', month:'long', day:'numeric', hour:'numeric', minute:'numeric', second:'numeric' };
            var s = now.toLocaleString(undefined, opt);
            document.getElementById('tzPreview').textContent = s;
        }catch(e){ document.getElementById('tzPreview').textContent = '' }
    }
    function updateDatePreview(){
        var fmt = document.querySelector('input[name="date_format"]:checked');
        var custom = document.querySelector('input[name="date_format_custom"]');
        var f = fmt ? fmt.value : (custom ? custom.value : 'F j, Y');
        // attempt to map PHP format tokens to a rough JS format used by toLocaleDateString
        var d = new Date();
        // simple fallback: show example by server-rendered example embedded earlier
        document.getElementById('datePreview').textContent = (new Date()).toLocaleDateString();
    }
    function updateTimePreview(){
        document.getElementById('timePreview').textContent = (new Date()).toLocaleTimeString();
    }
    document.getElementById('timezone_string')?.addEventListener('change', updateTZPreview);
    document.querySelectorAll('input[name="date_format"]').forEach(function(r){ r.addEventListener('change', updateDatePreview); });
    document.querySelectorAll('input[name="time_format"]').forEach(function(r){ r.addEventListener('change', updateTimePreview); });
    updateTZPreview(); updateDatePreview(); updateTimePreview();
})();
</script>

<script>
document.getElementById('rotateSaltBtn')?.addEventListener('click', function(){
    if (!confirm('Rotate the security salt? This will invalidate other users\' sessions. Continue?')) return;
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Rotating...';
    fetch('ajax.php', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({ action: 'rotate_salt' }),
        credentials: 'same-origin'
    }).then(r => r.json()).then(data => {
        const out = document.getElementById('rotateResult');
        if (data && data.status === 'success') {
            out.innerHTML = '<div class="alert alert-success">' + (data.message || 'Salt rotated') + '</div>';
        } else {
            out.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Rotation failed') + '</div>';
        }
    }).catch(err => {
        document.getElementById('rotateResult').innerHTML = '<div class="alert alert-danger">Request failed</div>';
    }).finally(() => {
        btn.disabled = false;
        btn.textContent = 'Rotate Security Salt';
    });
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
