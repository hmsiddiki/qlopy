<?php
// admin/taxonomies.php

require_once __DIR__ . '/admin_head.php';
//require_once __DIR__ . '/../db.php';

//require_once __DIR__ . '/../includes/qpmeta.php'; // ensure QPMeta is loaded

$taxonomies = get_taxonomies();
$taxonomy = $_GET['taxonomy'] ?? '';
if (!isset($taxonomies[$taxonomy])) {
    die("Invalid taxonomy.");
}

// Make current taxonomy available to QPMeta rendering logic (strict API)
$GLOBALS['qpmeta_current_taxonomy'] = $taxonomy;

if (isset($_GET['edit'])) {
    $page_title = 'Edit ' . ($taxonomies[$taxonomy]['label'] ?? ucfirst($taxonomy));
}else{
    $page_title = 'Manage ' . ($taxonomies[$taxonomy]['label'] ?? ucfirst($taxonomy));
}

// Pagination for terms list
$per_page = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 20;
$paged = max(1, (int)($_GET['paged'] ?? 1));
$offset = ($paged - 1) * $per_page;

// Total count for pager
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ?");
$count_stmt->execute([$taxonomy]);
$total_count = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_count / $per_page));

// Fetch terms for current page ordered by parent and term_order
$stmt = $pdo->prepare("SELECT * FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? ORDER BY COALESCE(parent_id, 0), term_order ASC, term ASC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $taxonomy);
$stmt->bindValue(2, $per_page, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$edit_term_id = (int)($_GET['edit'] ?? 0);
$edit_term = null;

if ($edit_term_id) {
    $stmt = $pdo->prepare("SELECT * FROM " . table_name('taxonomy_terms') . " WHERE id = ? AND taxonomy = ?");
    $stmt->execute([$edit_term_id, $taxonomy]);
    $edit_term = $stmt->fetch(PDO::FETCH_ASSOC);
}
// Load saved term description from term meta if available
$edit_term_description = '';
if ($edit_term_id) {
    if (function_exists('get_term_meta')) {
        $md = get_term_meta($edit_term_id, 'description');
        if ($md !== false && $md !== null) $edit_term_description = $md;
    }
    // fallback to description column if present
    if (empty($edit_term_description) && !empty($edit_term['description'])) {
        $edit_term_description = $edit_term['description'];
    }
}

// Group terms by parent_id to build hierarchy
$terms_by_parent = [];
foreach ($terms as $term) {
    $parent_id = $term['parent_id'] ?? 0;
    $terms_by_parent[$parent_id][] = $term;
}

function render_terms_tree($parent_id, $terms_by_parent, $edit_term_id, $depth = 0) {
    if (empty($terms_by_parent[$parent_id])) {
        return;
    }
    foreach ($terms_by_parent[$parent_id] as $term) {
        if ($term['id'] == $edit_term_id) continue; // prevent term being parent of itself
        echo '<div class="form-check" style="margin-left: ' . (20 * $depth) . 'px;">';
        echo '<input type="checkbox" class="form-check-input" id="term-' . $term['id'] . '" name="terms[]" value="' . $term['id'] . '">';
        echo '<label class="form-check-label" for="term-' . $term['id'] . '">' . htmlspecialchars($term['term']) . '</label>';
        echo '</div>';
        render_terms_tree($term['id'], $terms_by_parent, $edit_term_id, $depth + 1);
    }
}

// Dynamic admin ajax url
$admin_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$admin_ajax_url = $admin_base_path . '/ajax.php';
require_once __DIR__ . '/inc/header.php';
?>

<?php if (!$edit_term): ?>
    <h2><?= htmlspecialchars($page_title) ?></h2>
<?php endif; ?>

<?php if ($edit_term): ?>
<!-- Edit screen: posts-style two-column layout (main + sidebar) -->
<div class="container mt-3">
        <h2>Edit <?= htmlspecialchars($taxonomies[$taxonomy]['label'] ?? $taxonomy) ?></h2>
        <form id="editTermForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_term" />
            <input type="hidden" name="term_id" value="<?= $edit_term_id ?>" />
            <input type="hidden" name="taxonomy" value="<?= htmlspecialchars($taxonomy) ?>" />

            <div class="row">
                <div class="col-md-8">
                    <div class="form-group">
                        <label for="term">Name</label>
                        <input type="text" id="term" name="term" class="form-control" required value="<?= htmlspecialchars($edit_term['term'] ?? '') ?>" />
                    </div>

                    <div class="form-group">
                        <label for="slug">Slug</label>
                        <input type="text" id="slug" name="slug" class="form-control" value="<?= htmlspecialchars($edit_term['slug'] ?? '') ?>" />
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control qpmeta-richtext" rows="10"><?= htmlspecialchars($edit_term_description) ?></textarea>
                    </div>

                    <hr />
                    <div id="qpmeta-normal-container" class="qpmeta-normal-container">
                        <?php // Render QPMeta metaboxes for term in the normal (main) context ?>
                        <?php qpmeta_render_metaboxes_in_context('term', $edit_term_id, 'normal'); ?>
                    </div>
                </div>

                <div class="col-md-4">
                        <?php if (!empty($taxonomies[$taxonomy]['hierarchical'])): ?>
                        <div class="form-group">
                        <label for="parent_id">Parent Term</label>
                        <select id="parent_id" name="parent_id" class="form-control">
                            <option value="">No parent (root term)</option>
                            <?php
                            // Render parent options recursively for select
                            function render_parent_options($parent_id, $terms_by_parent, $edit_term_id, $depth, $selected_id) {
                                if (empty($terms_by_parent[$parent_id])) return;
                                foreach ($terms_by_parent[$parent_id] as $term) {
                                    if ($term['id'] == $edit_term_id) continue;
                                    $sel = ($term['id'] == $selected_id) ? 'selected' : '';
                                    echo '<option value="' . $term['id'] . '" ' . $sel . '>';
                                    echo str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . htmlspecialchars($term['term']);
                                    echo '</option>';
                                    render_parent_options($term['id'], $terms_by_parent, $edit_term_id, $depth + 1, $selected_id);
                                }
                            }
                            render_parent_options(0, $terms_by_parent, $edit_term_id, 0, $edit_term['parent_id'] ?? '');
                            ?>
                        </select>
                        </div>
                        <?php endif; ?>

                    <div class="form-group">
                        <label for="term_order">Order</label>
                        <input type="number" id="term_order" name="term_order" class="form-control" value="<?= (int)($edit_term['term_order'] ?? 0) ?>" />
                    </div>

                    <?php qpmeta_render_metaboxes_in_context('term', $edit_term_id, 'side'); ?>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save</button>
                <a class="btn btn-secondary" href="taxonomies.php?taxonomy=<?= urlencode($taxonomy) ?>">Cancel</a>
            </div>
            <div id="editTermResult" class="mt-3"></div>
        </form>
    </div>

<?php else: ?>

<!-- Add + List side-by-side -->
<div class="container mt-3">
    <div class="row">
        <div class="col-md-4">
            <form id="addTermForm" method="post" enctype="multipart/form-data" class="mb-4">
                <input type="hidden" name="action" value="add_term" />
                <input type="hidden" name="taxonomy" value="<?= htmlspecialchars($taxonomy) ?>" />
                <div class="form-group">
                    <label for="new_term">New Term</label>
                    <input type="text" id="new_term" name="term" class="form-control" placeholder="New Term" required>
                </div>
                <?php if (!empty($taxonomies[$taxonomy]['hierarchical'])): ?>
                <div class="form-group">
                    <label for="new_parent">Parent Term</label>
                    <select id="new_parent" name="parent_id" class="form-control">
                        <option value="">No parent (root term)</option>
                        <?php
                        function render_all_parent_options($parent_id, $terms_by_parent, $depth) {
                                if (empty($terms_by_parent[$parent_id])) return;
                                foreach ($terms_by_parent[$parent_id] as $term) {
                                        echo '<option value="' . $term['id'] . '">';
                                        echo str_repeat('— ', $depth) . htmlspecialchars($term['term']);
                                        echo '</option>';
                                        render_all_parent_options($term['id'], $terms_by_parent, $depth + 1);
                                }
                        }
                        render_all_parent_options(0, $terms_by_parent, 0);
                        ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="new_order">Order</label>
                    <input type="number" id="new_order" name="term_order" class="form-control" placeholder="Order" value="0" min="0" />
                </div>
                <?php /* Do not render QPMeta metaboxes on the Add Term box (only render when editing) */ ?>
                <div id="addTermResult" class="mt-2"></div>
                <div class="mt-2"><button type="submit" class="btn btn-primary">Add Term</button></div>
            </form>
        </div>

        <div class="col-md-8">
            <form id="termOrderForm" method="post" class="mt-0">
                <input type="hidden" name="action" value="update_term_order" />
                <input type="hidden" name="taxonomy" value="<?= htmlspecialchars($taxonomy) ?>" />
                <table class="table table-striped table-bordered" id="termsTable">
                    <thead class="thead-dark">
                        <tr>
                            <th>Order</th>
                            <th>ID</th>
                            <th>Term</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody class="sortable">
                        <?php
                        function render_terms_rows($parent_id, $terms_by_parent, $depth, $taxonomy = '') {
                                if (empty($terms_by_parent[$parent_id])) return;
                                foreach ($terms_by_parent[$parent_id] as $term) {
                                        echo '<tr data-term-id="' . $term['id'] . '">';
                                        echo '<td><input type="number" name="order[' . $term['id'] . ']" class="form-control form-control-sm" value="' . (int)$term['term_order'] . '" style="width:70px"></td>';
                                        echo '<td>' . $term['id'] . '</td>';
                                        echo '<td style="padding-left:' . (20 * $depth) . 'px;">' . htmlspecialchars($term['term']) . '</td>';
                                        echo '<td>';
                                $tx = is_string($taxonomy) ? $taxonomy : (string)($taxonomy ?? '');
                                echo '<a href="?taxonomy=' . urlencode($tx) . '&edit=' . $term['id'] . '" class="btn btn-sm btn-secondary mr-2">Edit</a>';
                                        echo '<button type="button" class="btn btn-sm btn-danger btn-delete-term" data-id="' . $term['id'] . '">Delete</button>';
                                        echo '</td></tr>';
                                render_terms_rows($term['id'], $terms_by_parent, $depth + 1, $taxonomy);
                                }
                        }
                        render_terms_rows(0, $terms_by_parent, 0, $taxonomy ?? '');
                        ?>
                    </tbody>
                </table>
                                <?php if (!empty($total_pages) && $total_pages > 1): ?>
                                    <nav aria-label="Terms pagination" class="mt-2">
                                        <ul class="pagination">
                                            <?php
                                                $qs = $_GET;
                                                for ($p = 1; $p <= $total_pages; $p++):
                                                    $qs['paged'] = $p;
                                                    $qs['per_page'] = $per_page;
                                                    $url = '?taxonomy=' . urlencode($taxonomy) . '&' . http_build_query($qs);
                                            ?>
                                            <li class="page-item <?= $p === $paged ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($url) ?>"><?= $p ?></a></li>
                                            <?php endfor; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>

                                <div><button type="submit" class="btn btn-primary">Save Order</button></div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>


<!-- TinyMCE for rich description editing on term edit screen (local enqueued editor-init handles initialization) -->
<script>
$(function () {

    // Delete term ajax
    $('.btn-delete-term').click(function() {
        if (!confirm('Delete this term?')) return;
        var termId = $(this).data('id');
        $.post('<?= $admin_ajax_url ?>', {
            action: 'delete_term',
            term_id: termId,
            taxonomy: <?= json_encode($taxonomy) ?>
        }, function(resp) {
            if (resp.status === 'success') location.reload();
            else alert('Error: ' + resp.message);
        }, 'json');
    });

    // Add term form ajax submit
    $('#addTermForm').submit(function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        formData.append('action', 'add_term');
        $.ajax({
            url: '<?= $admin_ajax_url ?>',
            type: 'POST',
            processData: false,
            contentType: false,
            data: formData,
            dataType: 'json',
            success: function(resp) {
                if (resp.status === 'success') {
                    $('#addTermResult').html('<div class="alert alert-success">Term added, reloading...</div>');
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    $('#addTermResult').html('<div class="alert alert-danger">'+resp.message+'</div>');
                }
            },
            error: function() {
                $('#addTermResult').html('<div class="alert alert-danger">Server error</div>');
            }
        });
    });

    // Edit term form ajax submit — trigger TinyMCE save, log payload, and stay on page
    $('#editTermForm').submit(function(e) {
        e.preventDefault();
        try { if (window.tinymce && typeof tinymce.triggerSave === 'function') tinymce.triggerSave(); } catch(e) {}

        var formData = new FormData(this);
        formData.append('action', 'update_term');

        // Build FormData and submit (TinyMCE content already synchronized above)

        $.ajax({
            url: '<?= $admin_ajax_url ?>',
            type: 'POST',
            processData: false,
            contentType: false,
            data: formData,
            dataType: 'json',
            success: function(resp) {
                console.info('update_term response:', resp);
                if (resp.status === 'success') {
                    $('#editTermResult').html('<div class="alert alert-success">'+resp.message+'</div>');
                    try {
                        var newName = $('input[name="term"]').val();
                        if (newName) {
                            $('.container h2').first().text('Edit ' + newName);
                        }
                    } catch(e) {}
                } else {
                    $('#editTermResult').html('<div class="alert alert-danger">'+resp.message+'</div>');
                }
            },
            error: function(xhr) {
                try { console.error('update_term XHR error:', xhr.status, xhr.responseText); } catch(e) {}
                $('#editTermResult').html('<div class="alert alert-danger">Server error</div>');
            }
        });
    });

    // Term order form submit -> send via AJAX to admin ajax endpoint
    $('#termOrderForm').submit(function(e){
        e.preventDefault();
        var taxonomy = <?= json_encode($taxonomy) ?>;
        var data = { action: 'update_term_order', taxonomy: taxonomy };
        // collect order inputs; use form-encoded keys so PHP receives array
        $('.sortable tr').each(function(index){
            var termId = $(this).data('term-id');
            if (!termId) return;
            var val = $(this).find('input[name^="order"]').val();
            // fallback to index if empty
            if (val === undefined || val === null || val === '') val = index;
            data['order[' + termId + ']'] = val;
        });
        $.post('<?= $admin_ajax_url ?>', data, function(resp){
            if (resp && resp.status === 'success') {
                alert('Order saved');
                // Optionally reload to reflect saved order
                setTimeout(function(){ location.reload(); }, 300);
            } else {
                alert('Error saving order: ' + (resp && resp.message ? resp.message : 'Unknown'));
            }
        }, 'json').fail(function(xhr){
            alert('Server error while saving order');
        });
    });
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
