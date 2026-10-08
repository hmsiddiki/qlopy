<?php
require_once __DIR__ . '/admin_head.php';

  if ( !current_user_can('manage_users')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
function generate_nicename($first_name, $last_name, $username) {
    $name_candidates = array_filter([$first_name, $last_name, $username]);
    foreach ($name_candidates as $name) {
        $slug = trim($name); // allow spaces, dashes, special chars, unicode
        if ($slug) return $slug;
    }
    return bin2hex(random_bytes(4));
}

function do_action_user_form_extra_fields($user_id = null) {
    if (function_exists('do_admin_action')) {
        do_admin_action('user_form_extra_fields', $user_id);
    }
    if (function_exists('do_action')) {
        do_action('user_form_extra_fields', $user_id);
    }
}

function do_action_save_user_extra_fields($user_id, $post_data) {
    // Extensible hook placeholder
    if (function_exists('do_admin_action')) {
        do_admin_action('user_form_extra_fields_save', $user_id, $post_data);
    }
    if (function_exists('do_action')) {
        do_action('user_form_extra_fields_save', $user_id, $post_data);
    }
}

function current_user_can_edit_role() {
    $current_user = get_logged_in_user();
    $role = get_user_meta($current_user['id'], 'role');
    return $role === 'admin';
}

$action = $_GET['action'] ?? 'list';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : null;

// Handle delete action
if ($action === 'delete' && $user_id) {
    // require manage_users capability
    if (!function_exists('check_permission') || !check_permission('manage_users')) {
        http_response_code(403);
        echo 'Permission denied';
        exit;
    }
    $current = get_logged_in_user();
    $current_id = $current['id'] ?? null;
    if ($current_id && intval($current_id) === intval($user_id)) {
        // Prevent deleting self
        header('Location: users.php?error=cannot_delete_self');
        exit;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM " . table_name('user_meta') . " WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $stmt2 = $pdo->prepare("DELETE FROM " . table_name('users') . " WHERE id = ?");
        $stmt2->execute([$user_id]);
    } catch (Exception $e) {
        // non-fatal: redirect with error
        header('Location: users.php?error=delete_failed');
        exit;
    }
    header('Location: users.php?deleted=1');
    exit;
}

if ($action === 'add' || ($action === 'edit' && $user_id)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $nicename = trim($_POST['nicename'] ?? '');
        $gender = $_POST['gender'] ?? '';

        $role = $_POST['role'] ?? 'subscriber';
        if (!current_user_can_edit_role()) {
            if ($action === 'edit' && $user_id) {
                $role = get_user_meta($user_id, 'role') ?? 'subscriber';
            } else {
                $role = 'subscriber';
            }
        }

        $password = $_POST['password'] ?? '';
        $password_repeat = $_POST['password_repeat'] ?? '';

                $sql = $action === 'add'
                    ? "SELECT COUNT(*) FROM " . table_name('users') . " WHERE username = ?"
                    : "SELECT COUNT(*) FROM " . table_name('users') . " WHERE username = ? AND id != ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($action === 'add' ? [$username] : [$username, $user_id]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Username already exists, please choose another.';
        } else {
            if (!$nicename) {
                $nicename = generate_nicename($first_name, $last_name, $username);
            }

            if ($action === 'add') {
                if (!$password) {
                    $error = 'Password is required.';
                } elseif ($password !== $password_repeat) {
                    $error = 'Passwords do not match.';
                }
            }

            if ($action === 'edit') {
                if (($password || $password_repeat) && $password !== $password_repeat) {
                    $error = 'Passwords do not match.';
                }
            }

            if (!isset($error)) {
                if ($action === 'add') {
                    $hash_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO " . table_name('users') . " (username, email, password_hash, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())");
                    $stmt->execute([$username, $email, $hash_password]);
                    $user_id_for_meta = $pdo->lastInsertId();
                } else {
                    if ($password && $password_repeat) {
                        $hash_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE " . table_name('users') . " SET username = ? , email = ?, password_hash = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $hash_password, $user_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE " . table_name('users') . " SET username = ? , email = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $user_id]);
                    }
                    $user_id_for_meta = $user_id;
                }

                update_user_meta($user_id_for_meta, 'first_name', $first_name);
                update_user_meta($user_id_for_meta, 'last_name', $last_name);
                update_user_meta($user_id_for_meta, 'nicename', $nicename);
                update_user_meta($user_id_for_meta, 'gender', $gender);
                update_user_meta($user_id_for_meta, 'role', $role);
                // assign capabilities that belong to this role so check_permission() works
if (function_exists('get_role_capabilities') && function_exists('assign_capabilities_to_user')) {
    $caps = get_role_capabilities($role);
    assign_capabilities_to_user($user_id_for_meta, $caps);
}

                // Save avatar attachment id if provided (allow clearing)
                if (isset($_POST['avatar_attachment_id'])) {
                    $aid = (int)($_POST['avatar_attachment_id'] ?? 0);
                    update_user_meta($user_id_for_meta, 'avatar_attachment_id', $aid);
                }

                do_action_save_user_extra_fields($user_id_for_meta, $_POST);

                header('Location: users.php');
                exit;
            }
        }
    }

    if ($action === 'edit' && $user_id) {
        $stmt = $pdo->prepare("SELECT * FROM " . table_name('users') . " WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo "User not found.";
            exit;
        }
        $first_name = get_user_meta($user_id, 'first_name');
        $last_name = get_user_meta($user_id, 'last_name');
        $nicename = get_user_meta($user_id, 'nicename');
        $gender = get_user_meta($user_id, 'gender');
        $role = get_user_meta($user_id, 'role') ?? 'subscriber';
        $avatar_attachment_id = get_user_meta($user_id, 'avatar_attachment_id');
        $current_avatar_url = '';
        if (!empty($avatar_attachment_id) && function_exists('qp_get_attachment_image_src')) {
            $src = qp_get_attachment_image_src((int)$avatar_attachment_id, 'thumbnail');
            if ($src) $current_avatar_url = $src[0];
        }
        if (!$current_avatar_url) {
            $legacy = get_user_meta($user_id, 'avatar');
            if (!empty($legacy)) {
                if (strpos($legacy, '/uploads') === 0 || strpos($legacy, 'uploads/') === 0) {
                    if (function_exists('qp_uploads_url_base')) $current_avatar_url = rtrim(qp_uploads_url_base(), '/') . '/' . ltrim(str_replace('uploads/', '', $legacy), '/');
                } else {
                    $current_avatar_url = $legacy;
                }
            }
        }
    } else {
        $user = null;
        $first_name = $last_name = $nicename = $gender = '';
        $role = 'subscriber';
    }
    ?>

    <?php $page_title = $action === 'add' ? 'Add New User' : 'Edit User'; 
    
  
    ?>
    <?php  require_once __DIR__ . '/inc/header.php'; ?>
 <div class="container mt-4">
    <h2><?= $action === 'add' ? 'Add New User' : 'Edit User' ?></h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
        <label>Username (Must be unique):</label><br />
        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?>" required /><br />
    </div>
        <?php if ($action === 'add'): ?>
            <div class="form-group">
                <label>Password:</label><br />
                <input type="password" name="password" class="form-control" required /><br />
                <label>Repeat Password:</label><br />
                <input type="password" name="password_repeat" class="form-control" required /><br />
            </div>
        <?php else: ?>
            <div class="form-group">
                <label>Password (Leave empty to keep unchanged):</label><br />
                <input type="password" name="password" class="form-control" /><br />
                <label>Repeat Password:</label><br />
                <input type="password" name="password_repeat" class="form-control" /><br />
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label>Email:</label><br />
            <?php if ($action === 'edit'): ?>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?>" readonly /><br />
            <?php else: ?>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?>" required /><br />
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>First Name:</label><br />
            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($first_name ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?>" /><br />
        </div>

        <div class="form-group">
            <label>Last Name:</label><br />
            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($last_name ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?>" /><br />
        </div>

        <div class="form-group">
            <label>Nicename (auto generated if empty):</label><br />
            <input type="text" name="nicename" class="form-control" value="<?= htmlspecialchars($nicename ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?>" /><br />
        </div>

        <div class="form-group">
            <label>Gender:</label><br />
            <select name="gender" class="form-control">
                <option value="" <?= ($gender ?? '') === '' ? 'selected' : '' ?>>Select Gender</option>
                <option value="male" <?= ($gender ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= ($gender ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other" <?= ($gender ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
            </select><br />
        </div>

        <?php if (current_user_can_edit_role()): ?>
        <div class="form-group">
            <label>User Role:</label><br />
            <select name="role" class="form-control" required>
                <?php
               // $roles = ['admin' => 'Admin', 'editor' => 'Editor', 'subscriber' => 'Subscriber'];
               $roles = get_role_list();
               foreach ($roles as $key => $label) {
                    $selected = ($role ?? '') === $key ? 'selected' : '';
                    echo "<option value=\"" . htmlspecialchars($key) . "\" $selected>" . htmlspecialchars($label) . "</option>";
                }
                ?>
            </select><br />
        </div>
        <?php else: ?>
            <input type="hidden" name="role" value="<?= htmlspecialchars($role ?? 'subscriber') ?>" />
        <?php endif; ?>

                <h4>Avatar</h4>
                <input type="hidden" id="avatar_attachment_id" name="avatar_attachment_id" value="<?= htmlspecialchars($avatar_attachment_id ?? '') ?>">
                <?php
                        // Determine preview src: prefer current user avatar, fall back to site default
                        $preview_src = '';
                        $has_avatar = false;
                        if (!empty($current_avatar_url)) { $preview_src = $current_avatar_url; $has_avatar = true; }
                        else if (!empty($avatar_attachment_id) && function_exists('qp_get_attachment_image_src')) {
                                $tmp = qp_get_attachment_image_src((int)$avatar_attachment_id, 'thumbnail'); if ($tmp) { $preview_src = $tmp[0]; $has_avatar = true; }
                        }
                        if (!$preview_src && function_exists('qp_default_avatar_url')) { $preview_src = qp_default_avatar_url(); }
                ?>
                <div id="avatar-preview" style="margin-bottom:8px;">
                        <img id="avatar-img" src="<?= htmlspecialchars($preview_src) ?>" style="max-width:96px;height:auto;border:1px solid #ddd;padding:4px;background:#fff">
                </div>
                <div style="margin-bottom:8px;">
                        <button type="button" id="choose_avatar" class="btn btn-sm btn-outline-primary">Choose from Media Library</button>
                        <button type="button" id="remove_avatar" class="btn btn-sm btn-outline-secondary" <?= $has_avatar ? '' : 'style="display:none;"' ?>>Remove</button>
                </div>

                <!-- Plugin extra fields hook -->
                <?php do_action_user_form_extra_fields($user['id'] ?? null); ?>

                <br /><button type="submit" class="btn btn-primary"><?= $action === 'add' ? 'Add User' : 'Update User' ?></button>
    </form>

        <?php include __DIR__ . '/inc/footer.php'; ?>
        <script>
        jQuery(function($){
            $('#choose_avatar').on('click', function(){
                var pre = $('#avatar_attachment_id').val() ? [$('#avatar_attachment_id').val()] : [];
                if (window.qlopyMedia && typeof qlopyMedia.openModal === 'function') {
                    qlopyMedia.openModal({ multiple: false, fileMode: 'images', preselect: pre, onInsert: function(item){
                        var id = null, url = null;
                        if (!item) return;
                        if (Array.isArray(item)) { id = item.length ? item[0].id : null; url = item.length ? item[0].url : null; }
                        else { id = item.id || item; url = item.url || null; }
                        if (!url && id) {
                            var adminAjax = window.qlopyAdminAjax || '/admin/ajax.php';
                            $.post(adminAjax, { action: 'qp_media_batch_get', ids: String(id) }, function(resp){
                                if (resp && resp.status === 'success' && resp.items && resp.items.length) {
                                    var it = resp.items[0]; var thumb = it.thumb || it.url || '';
                                    $('#avatar_attachment_id').val(id);
                                    if (thumb) { $('#avatar-img').attr('src', thumb).show(); } else { $('#avatar-img').hide(); }
                                }
                            }, 'json');
                        } else {
                            $('#avatar_attachment_id').val(id);
                            if (url) $('#avatar-img').attr('src', url).show(); else $('#avatar-img').hide();
                        }
                    }});
                } else {
                    alert('Media modal not available.');
                }
            });
            $('#remove_avatar').on('click', function(){ if (!confirm('Remove avatar selection?')) return; $('#avatar_attachment_id').val(''); $('#avatar-img').attr('src','').hide(); });
        });
        </script>

        <?php
        exit;
}

// Users list page with pagination
$per_page = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 20;
$paged = max(1, (int)($_GET['paged'] ?? 1));
$offset = ($paged - 1) * $per_page;

$count_stmt = $pdo->query("SELECT COUNT(*) FROM " . table_name('users'));
$total_count = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_count / $per_page));

$stmt = $pdo->prepare("SELECT id, username, email, created_at FROM " . table_name('users') . " ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$page_title = 'Manage Users';

require_once __DIR__ . '/inc/header.php';
//require_once __DIR__ . '/inc/navbar.php';
?>
<div class="container mt-4">
<h2>Users</h2>
<a href="users.php?action=add" class="btn btn-primary mb-3">Add New User</a>

<table class="table table-bordered table-striped" id="usersTable">
    <thead class="thead-dark">
        <tr>
            <th>ID</th><th>Avatar</th><th>Username</th><th>Email</th><th>Role</th><th>Created</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $user):
            $role = get_user_meta($user['id'], 'role') ?? 'subscriber';
        ?>
        <tr>
            <td><?= $user['id'] ?></td>
            <td style="width:72px;">
                <?php
                    if (function_exists('get_avatar')) {
                        $avatar_html = get_avatar($user['id'], 48);
                        // ensure consistent inline sizing/styling when get_avatar() returns plain img
                        if (preg_match('/<img\b/i', $avatar_html) && stripos($avatar_html, 'style=') === false) {
                            $avatar_html = preg_replace('/<img\b(?![^>]*\bstyle=)/i', '<img style="width:48px;height:48px;object-fit:cover;border-radius:4px;border:1px solid #ddd;" ', $avatar_html, 1);
                        }
                        echo $avatar_html;
                    } else {
                        $avatar_preview = function_exists('qp_user_avatar_url') ? qp_user_avatar_url($user['id'], 48) : null;
                        if (!empty($avatar_preview)) {
                            echo '<img src="' . htmlspecialchars($avatar_preview) . '" style="width:48px;height:48px;object-fit:cover;border-radius:4px;border:1px solid #ddd;" alt="avatar">';
                        } else {
                            echo '<div style="width:48px;height:48px;display:inline-block;background:#f6f6f6;border:1px solid #eee;border-radius:4px;"></div>';
                        }
                    }
                ?>
            </td>
            <td><?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE) ?></td>
            <td><?= htmlspecialchars($user['email'], ENT_QUOTES | ENT_SUBSTITUTE) ?></td>
            <td><?= htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE) ?></td>
            <td><?= function_exists('format_site_datetime') ? htmlspecialchars(format_site_datetime($user['created_at'])) : htmlspecialchars($user['created_at']) ?></td>
            <td>
                <a href="users.php?action=edit&id=<?= $user['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                <a href="users.php?action=delete&id=<?= $user['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure to delete this user?');">Delete</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($total_pages) && $total_pages > 1): ?>
    <nav aria-label="Users pagination" class="mt-2">
        <ul class="pagination">
            <?php
                $qs = $_GET;
                for ($p = 1; $p <= $total_pages; $p++):
                    $qs['paged'] = $p;
                    $qs['per_page'] = $per_page;
                    $url = 'users.php?' . http_build_query($qs);
            ?>
            <li class="page-item <?= $p === $paged ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($url) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
                </div>  
<?php require_once __DIR__ . '/inc/footer.php'; ?>
