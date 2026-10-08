<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// qpmeta metabox registry
$GLOBALS['qpmeta_metaboxes'] = [];

/**
 * Register a metabox.
 * 
 * @param string $id Unique metabox ID.
 * @param array $args Metabox args, e.g. title, object_types (e.g. ['post','term']), fields array.
 */
function qpmeta_register_metabox($id, array $args) {
    $args['fields'] = $args['fields'] ?? [];
    $args['context'] = $args['context'] ?? 'normal'; // 'normal' (left) or 'side' (right)
        $args['id'] = $id;
        // If any field declares a taxonomy, register it (hidden by default)
        foreach ($args['fields'] as $field) {
            if (!empty($field['taxonomy'])) {
                $tax_name = $field['taxonomy'];
                $menu_visible = isset($field['taxonomy_menu_visible']) ? !!$field['taxonomy_menu_visible'] : false;
                // Register taxonomy if not already
                if (empty($GLOBALS['qpmeta_registered_taxonomies'][$tax_name])) {
                    $GLOBALS['qpmeta_registered_taxonomies'][$tax_name] = [
                        'name' => $tax_name,
                        'menu_visible' => $menu_visible,
                    ];
                }
            }
        }
        $GLOBALS['qpmeta_metaboxes'][] = $args;
}

/**
 * Add a field to an existing metabox.
 */
function qpmeta_add_field($metabox_id, array $field) {
    if (!isset($GLOBALS['qpmeta_metaboxes'][$metabox_id])) return;
    $GLOBALS['qpmeta_metaboxes'][$metabox_id]['fields'][] = $field;
}

/**
 * Render all metaboxes for given $object_type and $object_id.
 * Outputs HTML form with JS for repeatables, conditionals, ajax saving.
 */
function qpmeta_render_metaboxes($object_type, $object_id = null, $option_prefix = null) {
    // Compute site root and use admin ajax consistently from any location
    $site_root = dirname(dirname($_SERVER['SCRIPT_NAME'])); // e.g. /advcms
    if (substr($site_root, -1) !== '/') { $site_root .= '/'; }
    $ajax_url = $site_root . 'admin/ajax.php?action=qpmeta_save';

    foreach ($GLOBALS['qpmeta_metaboxes'] as $mb_id => $mb) {
        $targets = (array)($mb['object_types'] ?? []);
        if (!in_array($object_type, $targets)) {
            continue;
        }
        // If caller requested only metaboxes for a specific option prefix,
        // skip any option metabox that doesn't declare the same prefix.
        if ($object_type === 'option' && $option_prefix !== null) {
            $mb_prefix = $mb['option_prefix'] ?? '';
            if ($mb_prefix !== $option_prefix) {
                continue;
            }
        }
        // If metabox declares specific taxonomies and we're rendering term context,
        // ensure the current taxonomy matches. Use a stricter API: `taxonomies` may
        // be a string or array and the current taxonomy MUST be set via
        // `$GLOBALS['qpmeta_current_taxonomy']` before rendering.
        if ($object_type === 'term' && !empty($mb['taxonomies'])) {
            $mb_taxonomies = (array)$mb['taxonomies'];
            $current_tax = $GLOBALS['qpmeta_current_taxonomy'] ?? null;
            if (!$current_tax || !in_array($current_tax, $mb_taxonomies, true)) {
                continue;
            }
        }
        
        // For option type: render standalone form with Save button and AJAX
        // For post/term: render fields only (no form wrapper, integrates with parent form)
        $is_option = ($object_type === 'option');
        
        if ($is_option) {
            echo '<form class="qpmeta-metabox-form" method="post" enctype="multipart/form-data" '
               . 'data-object-type="' . htmlspecialchars($object_type) . '" '
               . 'data-object-id="' . (int)$object_id . '" '
               . 'style="margin:20px 0; padding:10px; border:1px solid #ccc;">'
               . '<input type="hidden" name="metabox_id" value="' . htmlspecialchars($mb['id'] ?? $mb_id) . '">';
        }

        // Metabox-level conditional attribute support
        $mb_condition_json = !empty($mb['conditional']) ? ' data-qpmeta-conditional="' . htmlspecialchars(json_encode($mb['conditional'])) . '"' : '';

        echo '<fieldset class="qpmeta-metabox"' . $mb_condition_json . ' data-object-type="' . htmlspecialchars($object_type) . '" data-object-id="' . (int)$object_id . '" style="margin:20px 0; padding:10px; border:1px solid #ccc;">';
        echo '<legend style="font-weight:bold;">' . htmlspecialchars($mb['title']) . '</legend>';

        // Check if metabox itself is repeatable (group of fields)
        $is_metabox_repeatable = !empty($mb['repeatable']);
        
        if ($is_metabox_repeatable) {
            // Fetch existing groups data
            $groups_data = [];
            if ($object_id !== null) {
                // Get data for first field to determine how many groups exist
                $first_field_id = $mb['fields'][0]['id'] ?? '';
                if ($first_field_id) {
                    $storage_id = ($object_type === 'option' && !empty($mb['option_prefix'])) 
                        ? $mb['option_prefix'] . '_' . $first_field_id 
                        : $first_field_id;
                    $first_values = qpmeta_get_object_meta($object_type, $object_id, $storage_id);
                    if (is_array($first_values)) {
                        // Normalize numeric arrays to zero-based sequential indexes
                        $groups_data = array_values($first_values);
                    }
                }
            }
            // Ensure we render a visible initial group plus a hidden prototype
            if (empty($groups_data)) $groups_data = [];

            echo "<div class='qpmeta-repeat-group'>";
            // Render existing groups as visible items
            foreach ($groups_data as $group_index => $group_val) {
                $style = "";
                echo "<div class='qpmeta-repeat-item' style='{$style} margin-bottom:15px;'>";
                    // Panel wrapper: header (handle + title + toggle) and body
                    $panel_body_display = 'block';
                    echo "<div class='qpmeta-panel' style='border:1px solid #ddd;background:#f9f9f9;'>";
                    echo "<div class='qpmeta-panel-header' style='display:flex;align-items:center;padding:8px;'>";
                    echo "<span class='qpmeta-panel-handle' title='Drag to reorder' style='cursor:move;margin-right:8px;'>☰</span>";
                    echo "<strong class='qpmeta-panel-title' style='flex:1;'>" . htmlspecialchars(($mb['title'] ?? 'Item')) . "</strong>";
                    echo "<button type='button' class='qpmeta-panel-toggle btn btn-sm btn-secondary' style='margin-left:8px;'>Toggle</button>";
                    echo "</div>";
                    echo "<div class='qpmeta-panel-body' style='display:{$panel_body_display};padding:10px;'>";

                    // Render all fields in this group (inside panel body)
                    foreach ($mb['fields'] as $field) {
                        if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                            $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                        } else {
                            $field['storage_id'] = $field['id'];
                        }
                        // Get value for this specific group index
                        $field_value = '';
                        if ($object_id !== null) {
                            $all_values = qpmeta_get_object_meta($object_type, $object_id, $field['storage_id']);
                            if (is_array($all_values)) {
                                $all_values = array_values($all_values);
                                if (isset($all_values[$group_index])) {
                                    $field_value = $all_values[$group_index];
                                }
                            }
                        }
                        qpmeta_render_field_in_group($field, $object_type, $object_id, $field_value);
                    }

                    echo "<div style='margin-top:8px;'>";
                    echo "<button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger'>Remove</button>";
                    echo "</div>";

                    echo "</div>"; // .qpmeta-panel-body
                    echo "</div>"; // .qpmeta-panel
                echo "</div>"; // .qpmeta-repeat-item
            }
            // Append a hidden prototype for client-side cloning
            echo "<div class='qpmeta-repeat-item qpmeta-repeatable-prototype' style='display:none; margin-bottom:15px;'>";
                $panel_body_display = 'none';
                echo "<div class='qpmeta-panel' style='border:1px solid #ddd;background:#f9f9f9;'>";
                echo "<div class='qpmeta-panel-header' style='display:flex;align-items:center;padding:8px;'>";
                echo "<span class='qpmeta-panel-handle' title='Drag to reorder' style='cursor:move;margin-right:8px;'>☰</span>";
                echo "<strong class='qpmeta-panel-title' style='flex:1;'>" . htmlspecialchars(($mb['title'] ?? 'Item')) . "</strong>";
                echo "<button type='button' class='qpmeta-panel-toggle btn btn-sm btn-secondary' style='margin-left:8px;'>Toggle</button>";
                echo "</div>";
                echo "<div class='qpmeta-panel-body' style='display:{$panel_body_display};padding:10px;'>";
                // render empty fields for prototype
                foreach ($mb['fields'] as $field) {
                    if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                        $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                    } else {
                        $field['storage_id'] = $field['id'];
                    }
                    qpmeta_render_field_in_group($field, $object_type, $object_id, '');
                }
                echo "<div style='margin-top:8px;'>";
                echo "<button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger'>Remove</button>";
                echo "</div>";
                echo "</div>"; // .qpmeta-panel-body
                echo "</div>"; // .qpmeta-panel
            echo "</div>";
            echo "<button type='button' class='qpmeta-repeat-add btn btn-primary' style='display:inline-block;'>Add " . htmlspecialchars($mb['title']) . "</button>";
            echo "</div>";
        } else {
            // Non-repeatable metabox: render fields normally
            foreach ($mb['fields'] as $field) {
                // Compute storage_id for option type with namespace prefix
                if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                    $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                } else {
                    $field['storage_id'] = $field['id'];
                }
                qpmeta_render_field($field, $object_type, $object_id);
            }
        }
        
        // Render any sub-groups defined in 'groups' key
        if (!empty($mb['groups']) && is_array($mb['groups'])) {
            foreach ($mb['groups'] as $group) {
                $group_title = $group['title'] ?? 'Group';
                $group_repeatable = !empty($group['repeatable']);
                $group_fields = $group['fields'] ?? [];
                
                echo '<hr style="margin:20px 0;">';
                echo '<h4 style="margin-bottom:15px;">' . htmlspecialchars($group_title) . '</h4>';
                
                if ($group_repeatable) {
                    // Repeatable group logic
                    $groups_data = [];
                    if ($object_id !== null && !empty($group_fields)) {
                        $first_field_id = $group_fields[0]['id'] ?? '';
                        if ($first_field_id) {
                            $storage_id = ($object_type === 'option' && !empty($mb['option_prefix']))
                                ? $mb['option_prefix'] . '_' . $first_field_id
                                : $first_field_id;
                            $first_values = qpmeta_get_object_meta($object_type, $object_id, $storage_id);
                            if (is_array($first_values)) {
                                $groups_data = array_values($first_values);
                            }
                        }
                    }
                    if (empty($groups_data)) $groups_data = [];

                    echo "<div class='qpmeta-repeat-group'>";
                    // render existing items visible
                    foreach ($groups_data as $group_index => $group_val) {
                        echo "<div class='qpmeta-repeat-item' style='margin-bottom:15px; padding:10px; border:1px solid #ddd; background:#f9f9f9;'>";

                        foreach ($group_fields as $field) {
                            if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                                $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                            } else {
                                $field['storage_id'] = $field['id'];
                            }
                            $field_value = '';
                            if ($object_id !== null) {
                                $all_values = qpmeta_get_object_meta($object_type, $object_id, $field['storage_id']);
                                if (is_array($all_values)) {
                                    $all_values = array_values($all_values);
                                    if (isset($all_values[$group_index])) {
                                        $field_value = $all_values[$group_index];
                                    }
                                }
                            }
                            qpmeta_render_field_in_group($field, $object_type, $object_id, $field_value);
                        }

                        echo "<button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger mt-2'>Remove</button>";
                        echo "</div>";
                    }
                    // append hidden prototype
                    echo "<div class='qpmeta-repeat-item qpmeta-repeatable-prototype' style='display:none; margin-bottom:15px; padding:10px; border:1px solid #ddd; background:#f9f9f9;'>";
                    foreach ($group_fields as $field) {
                        if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                            $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                        } else {
                            $field['storage_id'] = $field['id'];
                        }
                        qpmeta_render_field_in_group($field, $object_type, $object_id, '');
                    }
                    echo "<button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger mt-2'>Remove</button>";
                    echo "</div>";
                    echo "<button type='button' class='qpmeta-repeat-add btn btn-primary' style='display:inline-block;'>Add " . htmlspecialchars($group_title) . "</button>";
                    echo "</div>";
                } else {
                    // Non-repeatable group
                    foreach ($group_fields as $field) {
                        if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                            $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                        } else {
                            $field['storage_id'] = $field['id'];
                        }
                        qpmeta_render_field($field, $object_type, $object_id);
                    }
                }
            }
        }
        
        if ($is_option) {
            echo '<input type="submit" value="Save"/>';
            echo '<span class="qpmeta-msg" style="margin-left:1em;color:#c00;"></span>';
        }
        
        echo '</fieldset>';
        
        if ($is_option) {
            echo '</form>';
        }
    }
    
    // Output JavaScript for handling form submission
    qpmeta_print_scripts();
}

// Render metaboxes filtered by context: 'normal' (left) or 'side' (right)
function qpmeta_render_metaboxes_in_context($object_type, $object_id = null, $context = 'normal') {
    $site_root = dirname(dirname($_SERVER['SCRIPT_NAME']));
    if (substr($site_root, -1) !== '/') { $site_root .= '/'; }
    $ajax_url = $site_root . 'admin/ajax.php?action=qpmeta_save';
    // Detect current post_type for filtering post-type-specific metaboxes
    $current_post_type = $_GET['post_type'] ?? null;

    foreach ($GLOBALS['qpmeta_metaboxes'] as $mb_id => $mb) {
        $targets = (array)($mb['object_types'] ?? []);
        if (!in_array($object_type, $targets)) continue;

        // If this is a post object and the metabox restricts to specific post_types,
        // determine the effective post_type (prefer submitted value) and skip the
        // metabox when it does not apply. This mirrors the render-time check
        // performed in qpmeta_render_metaboxes_in_context.
        if ($object_type === 'post' && !empty($mb['post_types']) && is_array($mb['post_types'])) {
            $current_post_type = $post_data['post_type'] ?? null;
            if (!$current_post_type && $object_id) {
                if (function_exists('get_post')) {
                    $p = get_post($object_id);
                    if (is_array($p)) $current_post_type = $p['post_type'] ?? null;
                    elseif (is_object($p)) $current_post_type = $p->post_type ?? null;
                } else {
                    global $pdo;
                    $stmt = $pdo->prepare("SELECT post_type FROM " . table_name('posts') . " WHERE id = ? LIMIT 1");
                    $stmt->execute([$object_id]);
                    $current_post_type = $stmt->fetchColumn() ?: null;
                }
            }
            if (!$current_post_type || !in_array($current_post_type, $mb['post_types'], true)) {
                continue;
            }
        }
        // Respect 'taxonomies' filter for term metaboxes (strict API)
        if ($object_type === 'term' && !empty($mb['taxonomies'])) {
            $mb_taxonomies = (array)$mb['taxonomies'];
            $current_tax = $GLOBALS['qpmeta_current_taxonomy'] ?? null;
            if (!$current_tax || !in_array($current_tax, $mb_taxonomies, true)) {
                continue;
            }
        }
        
        // If metabox specifies post_types, enforce match
        if ($object_type === 'post' && !empty($mb['post_types']) && is_array($mb['post_types'])) {
            if (!$current_post_type || !in_array($current_post_type, $mb['post_types'], true)) {
                continue;
            }
        }
        
        $mbContext = $mb['context'] ?? 'normal';
        if ($mbContext !== $context) continue;

        // Skip rendering if object_id is required but not provided
        $requiresId = $mb['requires_object_id'] ?? false;
        if ($requiresId && (!$object_id || $object_id <= 0)) {
            continue;
        }
        
        // For option type: render standalone form with Save button and AJAX
        // For post/term: render fields only (no form wrapper, integrates with parent form)
        $is_option = ($object_type === 'option');
        
        if ($is_option) {
            echo '<form class="qpmeta-metabox-form" method="post" enctype="multipart/form-data" '
               . 'data-object-type="' . htmlspecialchars($object_type) . '" '
               . 'data-object-id="' . (int)$object_id . '" '
               . 'style="margin:20px 0; padding:10px; border:1px solid #ccc;">'
               . '<input type="hidden" name="metabox_id" value="' . htmlspecialchars($mb['id'] ?? $mb_id) . '">';
        }

        // Metabox-level conditional attribute support
        $mb_condition_json = !empty($mb['conditional']) ? ' data-qpmeta-conditional="' . htmlspecialchars(json_encode($mb['conditional'])) . '"' : '';

        echo '<fieldset class="qpmeta-metabox"' . $mb_condition_json . ' data-object-type="' . htmlspecialchars($object_type) . '" data-object-id="' . (int)$object_id . '" style="margin:20px 0; padding:10px; border:1px solid #ccc;">';
        echo '<legend style="font-weight:bold;">' . htmlspecialchars($mb['title']) . '</legend>';

        // Support metabox-level repeatable groups, like Team Members on Pages
        $is_metabox_repeatable = !empty($mb['repeatable']);
        if ($is_metabox_repeatable) {
            // Determine number of groups from first field values
            $groups_data = [];
            if ($object_id !== null && !empty($mb['fields'])) {
                $first_field_id = $mb['fields'][0]['id'] ?? '';
                if ($first_field_id) {
                    $storage_id = ($object_type === 'option' && !empty($mb['option_prefix']))
                        ? $mb['option_prefix'] . '_' . $first_field_id
                        : $first_field_id;
                    $first_values = qpmeta_get_object_meta($object_type, $object_id, $storage_id);
                    if (is_array($first_values)) {
                        $groups_data = array_values($first_values);
                    }
                }
            }
            if (empty($groups_data)) $groups_data = ['', ''];

            echo "<div class='qpmeta-repeat-group'>";
            foreach ($groups_data as $group_index => $group_val) {
                // Render all saved groups as visible items. Prototype is appended after.
                $style = "";
                $class = '';
                echo "<div class='qpmeta-repeat-item {$class}' style='{$style} margin-bottom:15px; padding:10px; border:1px solid #ddd; background:#f9f9f9;'>";

                foreach ($mb['fields'] as $field) {
                    if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                        $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                    } else {
                        $field['storage_id'] = $field['id'];
                    }
                    $field_value = '';
                    if ($object_id !== null) {
                        $all_values = qpmeta_get_object_meta($object_type, $object_id, $field['storage_id']);
                        if (is_array($all_values)) {
                            $all_values = array_values($all_values);
                            if (isset($all_values[$group_index])) {
                                $field_value = $all_values[$group_index];
                            }
                        }
                    }
                    qpmeta_render_field_in_group($field, $object_type, $object_id, $field_value);
                }

                echo "<button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger mt-2'>Remove</button>";
                echo "</div>";
            }
            // Append a hidden prototype for client-side cloning
            echo "<div class='qpmeta-repeat-item qpmeta-repeatable-prototype' style='display:none; margin-bottom:15px; padding:10px; border:1px solid #ddd; background:#f9f9f9;'>";
            foreach ($mb['fields'] as $field) {
                if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                    $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                } else {
                    $field['storage_id'] = $field['id'];
                }
                qpmeta_render_field_in_group($field, $object_type, $object_id, '');
            }
            echo "<button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger mt-2'>Remove</button>";
            echo "</div>";
            echo "<button type='button' class='qpmeta-repeat-add btn btn-primary' style='display:inline-block;'>Add " . htmlspecialchars($mb['title']) . "</button>";
            echo "</div>";
        } else {
            foreach ($mb['fields'] as $field) {
                if ($object_type === 'option' && !empty($mb['option_prefix'])) {
                    $field['storage_id'] = $mb['option_prefix'] . '_' . $field['id'];
                } else {
                    $field['storage_id'] = $field['id'];
                }
                qpmeta_render_field($field, $object_type, $object_id);
            }
        }
        
        if ($is_option) {
            echo '<input type="submit" value="Save"/>';
            echo '<span class="qpmeta-msg" style="margin-left:1em;color:#c00;"></span>';
        }
        
        echo '</fieldset>';
        
        if ($is_option) {
            echo '</form>';
        }
    }
    
    // Output JavaScript for handling form submission (call once after rendering all metaboxes)
    qpmeta_print_scripts();
}

/**
 * Render a single field within a repeatable group (no outer wrap).
 */
function qpmeta_render_field_in_group($field, $object_type, $object_id, $value) {
    $name = htmlspecialchars($field['name']);
    $id = htmlspecialchars($field['id']);
    $storage_id = htmlspecialchars($field['storage_id'] ?? $field['id']);
    $type = $field['type'];
    $desc_html = isset($field['desc']) ? '<small>' . htmlspecialchars($field['desc']) . '</small>' : '';

    echo "<div class='qpmeta-field-wrap' data-field-name='{$id}' style='margin-bottom:1em;'>";
    echo "<label for='{$id}' style='font-weight:600;'>{$name}:</label><br>";
    
    if ($type === 'tags') {
        $field['object_id'] = $object_id;
    }
    // Use array name for group fields to store as array
    qpmeta_field_control($type, $id . '[]', $value, $field);
    
    echo $desc_html;
    echo "</div>";
}

/**
 * Render a single field (with support for repeatable and conditionals).
 */
function qpmeta_render_field($field, $object_type, $object_id = null) {
    $name = htmlspecialchars($field['name']);
    $id = htmlspecialchars($field['id']); // original id for form field name
    $storage_id = htmlspecialchars($field['storage_id'] ?? $field['id']); // actual meta key used in storage
    $type = $field['type'];
    $desc_html = isset($field['desc']) ? '<small>' . htmlspecialchars($field['desc']) . '</small>' : '';
    $repeatable = $field['repeatable'] ?? false;
    $condition_json = !empty($field['conditional']) ? ' data-qpmeta-conditional="' . htmlspecialchars(json_encode($field['conditional'])) . '"' : '';

    $value = '';
    if ($object_id !== null) {
        $val = qpmeta_get_object_meta($object_type, $object_id, $storage_id);
        if ($repeatable) {
            $value = is_array($val) ? array_values($val) : [];
        } else {
            $value = $val ?? '';
        }
    } else {
        $value = $repeatable ? [''] : '';
    }

    // Respect field default when no stored value exists.
    // For non-repeatable fields, use `field['default']` when value is empty.
    // For repeatable fields, populate first prototype item with the default.
    if (!$repeatable) {
        if (($value === '' || $value === null) && isset($field['default'])) {
            $value = $field['default'];
        }
    } else {
        if ((empty($value) || !is_array($value)) && isset($field['default'])) {
            $value = [$field['default']];
        }
    }

    echo "<div class='qpmeta-field-wrap' data-field-name='{$id}'{$condition_json} style='margin-bottom:1em;'>";
    echo "<label for='{$id}' style='font-weight:600;'>{$name}:</label><br>";
    // Multilingual support: if plugin enabled and this post type is i18n-enabled,
    // render additional inputs per language. Plugin stores i18n values under
    // meta key: i18n_<storage_id> as an associative array {lang: value}
    $is_lang_enabled = false;
    $current_post_type = null;
    if ($object_type === 'post' && $object_id) {
        try {
            $stmt = db()->prepare("SELECT post_type FROM " . table_name('posts') . " WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$object_id]);
            $current_post_type = $stmt->fetchColumn() ?: null;
        } catch (Throwable $_e) { $current_post_type = null; }
    }
    if (function_exists('ml_get_option') && $current_post_type) {
        $opt = ml_get_option();
        $components = $opt['components'] ?? [];
        if (!empty($components['post_types']) && !empty($components['post_types'][$current_post_type])) $is_lang_enabled = true;
    }
    // Allow field-level override: field['i18n'] === true forces per-language inputs
    if (!empty($field['i18n'])) {
        $is_lang_enabled = true;
    }

    if ($repeatable) {
    echo "<div class='qpmeta-repeat-group'>";
    if (empty($value) || !is_array($value)) $value = [];
    foreach ($value as $index => $val) {
            $style = "";
            echo "<div class='qpmeta-repeat-item' style='{$style} margin-bottom:5px;'>";
            // Panel wrapper for field-level repeatables
            $panel_body_display = 'block';
            echo "<div class='qpmeta-panel' style='border:1px solid #ddd;background:#fff;'>";
            echo "<div class='qpmeta-panel-header' style='display:flex;align-items:center;padding:6px;'>";
            echo "<span class='qpmeta-panel-handle' style='cursor:move;margin-right:8px;'>☰</span>";
            echo "<strong class='qpmeta-panel-title' style='flex:1;'>" . htmlspecialchars($name) . "</strong>";
            echo "<button type='button' class='qpmeta-panel-toggle btn btn-sm btn-secondary' style='margin-left:8px;'>Toggle</button>";
            echo "</div>";
            echo "<div class='qpmeta-panel-body' style='display:{$panel_body_display};padding:8px;'>";
            qpmeta_field_control($type, $id . '[]', $val, $field);
            echo "<div style='margin-top:8px;'><button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger'>Remove</button></div>";
            echo "</div>"; // .qpmeta-panel-body
            echo "</div>"; // .qpmeta-panel
            echo "</div>";
        }
        // prototype
        echo "<div class='qpmeta-repeat-item qpmeta-repeatable-prototype' style='display:none; margin-bottom:5px;'>";
        echo "<div class='qpmeta-panel' style='border:1px solid #ddd;background:#fff;'>";
        echo "<div class='qpmeta-panel-header' style='display:flex;align-items:center;padding:6px;'>";
        echo "<span class='qpmeta-panel-handle' style='cursor:move;margin-right:8px;'>☰</span>";
        echo "<strong class='qpmeta-panel-title' style='flex:1;'>" . htmlspecialchars($name) . "</strong>";
        echo "<button type='button' class='qpmeta-panel-toggle btn btn-sm btn-secondary' style='margin-left:8px;'>Toggle</button>";
        echo "</div>";
        echo "<div class='qpmeta-panel-body' style='display:none;padding:8px;'>";
        qpmeta_field_control($type, $id . '[]', '', $field);
        echo "<div style='margin-top:8px;'><button type='button' class='qpmeta-repeat-remove btn btn-sm btn-danger'>Remove</button></div>";
        echo "</div>"; // .qpmeta-panel-body
        echo "</div>"; // .qpmeta-panel
        echo "</div>";
        echo "<button type='button' class='qpmeta-repeat-add'>Add</button>";
        echo "</div>";
    } else {
        // Pass object_id to tags field so qpmeta_field_control can use it
        if ($type === 'tags') {
            $field['object_id'] = $object_id;
        }
        // If multilingual enabled, render base control plus per-lang controls
        if ($is_lang_enabled && !in_array($type, ['file','filesortable','tags'])) {
            // Base (default) field
            qpmeta_field_control($type, $id, $value, $field);
            // Render per-language inputs
            $langs = function_exists('ml_get_languages') ? ml_get_languages() : [];
            $i18n_key = 'i18n_' . $storage_id;
            // Read raw meta directly from DB to avoid any ml_get_meta filters
            $i18n_values = [];
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT meta_value FROM " . table_name('post_meta') . " WHERE post_id = ? AND meta_key = ? LIMIT 1");
                $stmt->execute([(int)$object_id, $i18n_key]);
                $raw = $stmt->fetchColumn();
                if ($raw !== false && $raw !== null && $raw !== '') {
                    $dec = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                        $i18n_values = $dec;
                        // Fallback: sometimes i18n meta was stored as a numeric
                        // list (e.g. ["1"]) instead of an associative lang=>val
                        // map. Attempt to map sequential entries to non-default
                        // language codes so the editor can display them.
                        $is_list = array_keys($i18n_values) === range(0, count($i18n_values) - 1);
                        if ($is_list && !empty($i18n_values) && !empty($langs)) {
                            $default = function_exists('ml_get_default_lang') ? ml_get_default_lang() : null;
                            $lang_keys = array_keys($langs);
                            $lang_keys = array_values(array_filter($lang_keys, function($k) use ($default) { return $k !== $default; }));
                            $mapped = [];
                            foreach (array_values($i18n_values) as $idx => $v) {
                                if (isset($lang_keys[$idx])) $mapped[$lang_keys[$idx]] = $v;
                            }
                            if (!empty($mapped)) $i18n_values = $mapped;
                        }
                    }
                }
            } catch (Throwable $_) { $i18n_values = []; }
            foreach ($langs as $lang => $ldata) {
                // skip default lang (we keep base input as default)
                $default = ml_get_default_lang();
                if ($lang === $default) continue;
                $lang_val = $i18n_values[$lang] ?? '';
                $label = htmlspecialchars(($ldata['label'] ?? $lang));
                echo "<div style='margin-top:6px;'>";
                echo "<label style='font-weight:600;'>" . htmlspecialchars($name) . " (" . $label . ")</label><br>";
                // Name fields using convention: storage_id__i18n[lang]
                $field_name = htmlspecialchars($storage_id . "__i18n[" . $lang . "]");
                qpmeta_field_control($type, $field_name, $lang_val, $field);
                echo "</div>";
            }
        } else {
            qpmeta_field_control($type, $id, $value, $field);
        }
    }
    echo $desc_html;
    echo "</div>";
}

/**
 * Echo HTML input for a single field.
 */
function qpmeta_field_control($type, $name, $value, $field) {
    switch ($type) {
        case 'text':
            echo "<input type='text' name='{$name}' value='" . htmlspecialchars($value) . "' class='form-control'>";
            break;
        case 'textarea':
            echo "<textarea name='{$name}' class='form-control'>" . htmlspecialchars($value) . "</textarea>";
            break;
        case 'select':
            echo "<select name='{$name}' class='form-control'>";
            foreach ($field['options'] as $opt_val => $opt_label) {
                $sel = ($value == $opt_val) ? 'selected' : '';
                echo "<option value='" . htmlspecialchars($opt_val) . "' $sel>" . htmlspecialchars($opt_label) . "</option>";
            }
            echo "</select>";
            break;
        case 'checkbox':
            $checked = ($value) ? 'checked' : '';
            echo "<input type='hidden' name='{$name}' value='0'>";
            echo "<input type='checkbox' name='{$name}' value='1' $checked>";
            break;
        case 'multicheck':
            // Multiple checkbox group (stores array)
            $options = $field['options'] ?? [];
            $vals = $value;
            if (!is_array($vals)) {
                if (is_string($vals) && strlen($vals)) {
                    $decoded = json_decode($vals, true);
                    if (is_array($decoded)) {
                        $vals = $decoded;
                    } else {
                        // fallback: single value string
                        $vals = [$vals];
                    }
                } else {
                    $vals = [];
                }
            }
            foreach ($options as $opt_val => $opt_label) {
                $checked = in_array($opt_val, $vals, true) ? 'checked' : '';
                echo "<label style='display:inline-block;margin-right:10px;'>";
                echo "<input type='checkbox' name='{$name}[]' value='" . htmlspecialchars($opt_val) . "' {$checked}> " . htmlspecialchars($opt_label);
                echo "</label>";
            }
            break;
        case 'radio':
            foreach ($field['options'] as $opt_val => $opt_label) {
                $checked = ($value == $opt_val) ? 'checked' : '';
                echo "<label><input type='radio' name='{$name}' value='" . htmlspecialchars($opt_val) . "' $checked> " . htmlspecialchars($opt_label) . "</label> ";
            }
            break;
        case 'number':
            echo "<input type='number' name='{$name}' value='" . htmlspecialchars($value) . "' class='form-control'>";
            break;
        case 'date':
            echo "<input type='date' name='{$name}' value='" . htmlspecialchars($value) . "' class='form-control'>";
            break;
        case 'file':
            // Multiple support: if field['multiple'] true, allow multiple uploads
            $is_multiple = !empty($field['multiple']);
            $storage_id_attr = isset($field['storage_id']) ? ' data-storage-id="' . htmlspecialchars($field['storage_id']) . '"' : '';
            $multiple_attr = $is_multiple ? ' multiple' : '';
            $input_name = $name . ($is_multiple ? '[]' : '');
            // Options for file input behavior
            $input_mode = isset($field['input']) ? $field['input'] : 'simple'; // 'simple' or 'modal'
            $filetype = isset($field['filetype']) ? $field['filetype'] : 'all'; // 'all', 'images', or comma-separated extensions

            // Value: array for multiple, int for single - ensure $ids is always an array
            if ($is_multiple) {
                if (is_array($value)) {
                    $ids = $value;
                } elseif (is_string($value) && strlen($value) > 0) {
                    $decoded = json_decode($value, true);
                    $ids = is_array($decoded) ? $decoded : [];
                } else {
                    $ids = [];
                }
            } else {
                // Single file: convert to array with one element for uniform processing
                if (is_numeric($value) && (int)$value > 0) {
                    $ids = [(int)$value];
                } else {
                    $ids = [];
                }
            }

            // Prepare accept attribute / display text
            $accept_attr = '';
            $allowed_text = 'Allowed: any file type';
            if ($filetype === 'images') {
                $accept_attr = " accept=\"image/*\"";
                $allowed_text = 'Allowed: images (.jpg .jpeg .png .gif .webp)';
            } elseif (is_string($filetype) && strlen(trim($filetype)) && strtolower($filetype) !== 'all') {
                // parse comma separated extensions
                $parts = preg_split('/\s*,\s*/', $filetype);
                $clean = [];
                foreach ($parts as $p) { $p = trim($p); if ($p === '') continue; $p = ltrim($p, '.'); $clean[] = strtolower($p); }
                if (count($clean)) {
                    $accept_attr = ' accept="' . htmlspecialchars(implode(',', array_map(function($e){ return '.' . $e; }, $clean))) . '"';
                    $allowed_text = 'Allowed: ' . implode(' ', array_map(function($e){ return '.' . $e; }, $clean));
                }
            }

            $is_filesortable = ($input_mode === 'modal' && isset($field['input']) && $field['input'] === 'filesortable');
            if ($input_mode === 'modal') {
                // Render a modal-triggering button. The modal will handle upload/select and we will save via AJAX on insert.
                echo "<input type='hidden' name='{$name}_id' value='" . ($is_multiple ? htmlspecialchars(json_encode($ids)) : (isset($ids[0]) ? $ids[0] : '')) . "' class='qpmeta-file-id'>";
                $btn_label = $is_multiple ? 'Select files' : 'Select file';
                $field_name_attr = isset($field['id']) ? $field['id'] : $name;
                $data_input_type = isset($field['input']) ? " data-input-type='" . htmlspecialchars($field['input']) . "'" : '';
                echo "<div style='margin-bottom:6px;'><button type='button' class='btn btn-sm btn-outline-secondary qpmeta-open-modal' data-field-name='" . htmlspecialchars($field_name_attr) . "' data-storage-id='" . (isset($field['storage_id']) ? htmlspecialchars($field['storage_id']) : '') . "' data-multiple='" . ($is_multiple ? '1' : '0') . "' data-filetype='" . htmlspecialchars($filetype) . "'" . $data_input_type . ">" . htmlspecialchars($btn_label) . "</button> <span class='small text-muted qpmeta-file-hint'>" . htmlspecialchars($allowed_text) . "</span></div>";
                $preview_extra_cls = $is_filesortable ? ' qpmeta-filesortable' : '';
                echo "<div class='qpmeta-file-preview" . $preview_extra_cls . "' style='margin-top:10px;'>";
            } else {
                // simple/native file input
                echo "<input type='file' name='{$input_name}' class='form-control qpmeta-file-input'{$accept_attr}{$storage_id_attr}{$multiple_attr}>";
                echo "<input type='hidden' name='{$name}_id' value='" . ($is_multiple ? htmlspecialchars(json_encode($ids)) : (isset($ids[0]) ? $ids[0] : '')) . "' class='qpmeta-file-id'>";
                $preview_extra_cls = $is_filesortable ? ' qpmeta-filesortable' : '';
                echo "<div class='qpmeta-file-preview" . $preview_extra_cls . "' style='margin-top:10px;'>";
            }
            foreach ($ids as $attachment_id) {
                if ($attachment_id && function_exists('qp_get_attachment_metadata')) {
                    $meta = qp_get_attachment_metadata($attachment_id);
                    if ($meta && isset($meta['file']['url'])) {
                        $url = $meta['file']['url'];
                        $mime = $meta['file']['mime'] ?? '';
                        $thumb_src = function_exists('qp_get_attachment_image_src') 
                            ? qp_get_attachment_image_src($attachment_id, 'thumbnail') 
                            : null;
                        $preview_url = ($thumb_src && isset($thumb_src[0])) ? $thumb_src[0] : $url;
                        if (strpos($mime, 'image/') === 0) {
                            echo "<div class='qpmeta-file-preview-item' data-attach-id='{$attachment_id}' style='position:relative;display:inline-block;margin-right:8px;'>";
                            echo "<img src='" . htmlspecialchars($preview_url) . "' style='max-width:150px;max-height:150px;border:1px solid #ddd;padding:4px;'>";
                            echo "<button type='button' class='qpmeta-file-remove' title='Remove' style='position:absolute;top:2px;right:2px;border:none;background:#000;color:#fff;width:22px;height:22px;line-height:22px;text-align:center;border-radius:50%;opacity:0.8'>&times;</button>";
                            echo "</div>";
                        } else {
                            echo "<a href='" . htmlspecialchars($url) . "' target='_blank'>View file</a>";
                            echo "<button type='button' class='qpmeta-file-remove btn btn-sm btn-outline-danger ml-2'>Remove</button>";
                        }
                    }
                }
            }
            echo "</div>";
            break;
        case 'filesortable':
            // Render a modal-triggering sortable multi-file selector. Stores ordered JSON array of attachment IDs
            $is_multiple = true;
            $storage_id_attr = isset($field['storage_id']) ? ' data-storage-id="' . htmlspecialchars($field['storage_id']) . '"' : '';
            $filetype = isset($field['filetype']) ? $field['filetype'] : 'images';
            // Value should be an ordered array (or JSON string)
            if (is_array($value)) {
                $ids = $value;
            } elseif (is_string($value) && strlen($value) > 0) {
                $decoded = json_decode($value, true);
                $ids = is_array($decoded) ? $decoded : [];
            } else {
                $ids = [];
            }
            echo "<input type='hidden' name='" . htmlspecialchars($name) . "_id' value='" . htmlspecialchars(json_encode($ids)) . "' class='qpmeta-file-id'>";
            $btn_label = 'Select images';
            $field_name_attr = isset($field['id']) ? $field['id'] : $name;
            echo "<div style='margin-bottom:6px;'><button type='button' class='btn btn-sm btn-outline-secondary qpmeta-open-modal' data-field-name='" . htmlspecialchars($field_name_attr) . "' data-storage-id='" . (isset($field['storage_id']) ? htmlspecialchars($field['storage_id']) : '') . "' data-multiple='1' data-filetype='" . htmlspecialchars($filetype) . "' data-input-type='filesortable'>" . htmlspecialchars($btn_label) . "</button> <span class='small text-muted qpmeta-file-hint'>Allowed: images (.jpg .jpeg .png .gif .webp)</span></div>";
            echo "<div class='qpmeta-file-preview qpmeta-filesortable' style='margin-top:10px;'>";
            foreach ($ids as $attachment_id) {
                if ($attachment_id && function_exists('qp_get_attachment_metadata')) {
                    $meta = qp_get_attachment_metadata($attachment_id);
                    if ($meta && isset($meta['file']['url'])) {
                        $url = $meta['file']['url'];
                        $mime = $meta['file']['mime'] ?? '';
                        $thumb_src = function_exists('qp_get_attachment_image_src') 
                            ? qp_get_attachment_image_src($attachment_id, 'thumbnail') 
                            : null;
                        $preview_url = ($thumb_src && isset($thumb_src[0])) ? $thumb_src[0] : $url;
                        if (strpos($mime, 'image/') === 0) {
                            echo "<div class='qpmeta-file-preview-item' data-attach-id='" . intval($attachment_id) . "' style='position:relative;display:inline-block;margin-right:8px;' draggable='true'>";
                            echo "<img src='" . htmlspecialchars($preview_url) . "' style='max-width:150px;max-height:150px;border:1px solid #ddd;padding:4px;'>";
                            echo "<button type='button' class='qpmeta-file-remove' title='Remove' style='position:absolute;top:2px;right:2px;border:none;background:#000;color:#fff;width:22px;height:22px;line-height:22px;text-align:center;border-radius:50%;opacity:0.8'>&times;</button>";
                            echo "</div>";
                        } else {
                            echo "<a href='" . htmlspecialchars($url) . "' target='_blank'>View file</a>";
                            echo "<button type='button' class='qpmeta-file-remove btn btn-sm btn-outline-danger ml-2'>Remove</button>";
                        }
                    }
                }
            }
            echo "</div>";
            break;
        case 'richtext':
            // Admin-only rich text editor field; initialized by editor-init.js (TinyMCE)
            echo "<textarea name='" . htmlspecialchars($name) . "' class='form-control qpmeta-richtext' rows='8'>" . htmlspecialchars($value) . "</textarea>";
            break;
        case 'tags':
            $taxonomy = $field['taxonomy'] ?? '';
            // Derive object_id from field (set in render) or fallback global
            $object_id = $field['object_id'] ?? ($GLOBALS['qpmeta_current_object_id'] ?? 0);
            $existing_tags = [];
            if ($object_id && $taxonomy) {
                // Use existing taxonomy functions
                $existing_tags = qp_get_object_terms($object_id, $taxonomy);
            }
            echo "<div class='qpmeta-tags-field' data-taxonomy='" . htmlspecialchars($taxonomy) . "' data-object-id='" . (int)$object_id . "'>";
            echo "<div class='qpmeta-tags-chips'>";
            foreach ($existing_tags as $tag) {
                echo "<span class='qpmeta-tag-chip' data-tag-id='" . (int)$tag['id'] . "'>" . htmlspecialchars($tag['term']) . " <span class='qpmeta-tag-remove'>&times;</span></span> ";
            }
            echo "</div>";
            // Hidden inputs so tags persist on form submission
            echo "<div class='qpmeta-tags-hidden'>";
            foreach ($existing_tags as $tag) {
                echo "<input type='hidden' name='terms[" . htmlspecialchars($taxonomy) . "][]' value='" . (int)$tag['id'] . "' class='qpmeta-tag-hidden-input'>";
            }
            echo "</div>";
            echo "<input type='text' class='qpmeta-tags-input' placeholder='Type to add tags...'>";
            echo "</div>";
            // JS will handle suggestions, adding/removing chips, and AJAX
            break;
    }
}

/**
 * Save posted fields with validation.
 * Returns true if success, false if validation errors exist.
 * $validation_errors filled with error strings by reference.
 */
function qpmeta_save_metaboxes($object_type, $object_id, $post_data, $files_data = [], &$validation_errors = []) {
    $validation_errors = [];
    
    // No debug file writes in production flow
    // Filter by submitted metabox_id (for option forms with separate Save buttons)
    $submitted_metabox_id = $post_data['metabox_id'] ?? null;
    
    foreach ($GLOBALS['qpmeta_metaboxes'] as $mb) {
        // Get metabox id from the registered metabox array
        $mb_id = $mb['id'] ?? null;
        
        // Skip if this is an option type form submission and metabox_id doesn't match
        if ($submitted_metabox_id && $mb_id && $mb_id !== $submitted_metabox_id) {
            continue;
        }
        
        $targets = (array)($mb['object_types'] ?? []);
        if (!in_array($object_type, $targets)) continue;

        $prefix = ($object_type === 'option' && !empty($mb['option_prefix'])) ? $mb['option_prefix'] : '';
        
        // Process main fields
        foreach ($mb['fields'] as $field) {
            $id = $field['id'];            // original form field id
            $storage_id = $prefix ? ($prefix . '_' . $id) : $id; // actual key used for storage
            $type = $field['type'];
            $repeatable = $field['repeatable'] ?? false;
            // Only process this field if it was submitted in the request (prevents
            // creating empty meta for metaboxes that weren't rendered on the form).
            $has_post_value = array_key_exists($id, $post_data);
            $has_hidden_file = isset($post_data[$id . '_id']) && $post_data[$id . '_id'] !== '';
            $has_file_upload = isset($files_data[$id]) && $files_data[$id]['error'] !== UPLOAD_ERR_NO_FILE;
            if (!$has_post_value && !$has_hidden_file && !$has_file_upload) {
                continue; // skip saving this field
            }

            // If this is an "add post" submission, ignore empty string values
            // that were included in the form for fields that were not actually
            // rendered (client sometimes posts empty hidden inputs for all
            // registered metabox fields). This prevents creating lots of
            // empty meta rows on new posts. We detect add via the 'action'
            // field (client sends 'add_post' when creating a new post).
            $is_add_request = isset($post_data['action']) && $post_data['action'] === 'add_post';
            if ($is_add_request && $has_post_value && !$has_hidden_file && !$has_file_upload) {
                if ($repeatable) {
                    $arr = is_array($post_data[$id]) ? $post_data[$id] : [];
                    $has_non_empty = count(array_filter($arr, fn($v) => $v !== '' && $v !== null)) > 0;
                    if (!$has_non_empty) continue;
                } else {
                    if ($post_data[$id] === '' || $post_data[$id] === null) continue;
                }
            }

            $val = $repeatable ? ($post_data[$id] ?? []) : ($post_data[$id] ?? '');
            // If repeatable, remove empty entries (strings or empty sub-arrays)
            if ($repeatable) {
                if (!is_array($val)) $val = [];
                $val = array_values(array_filter($val, function($v){
                    if (is_array($v)) {
                        // keep if any non-empty value exists in sub-array
                        foreach ($v as $sub) { if ($sub !== '' && $sub !== null) return true; }
                        return false;
                    }
                    return ($v !== '' && $v !== null);
                }));
            }

            // Normal save flow (no per-field debug logging)

            if (!empty($field['validate']['required'])) {
                if ($repeatable) {
                    if (!is_array($val) || count(array_filter($val, fn($v) => $v !== '')) === 0) {
                        $validation_errors[] = $field['name'] . ' is required.';
                        continue;
                    }
                } else {
                    if ($val === '' || $val === null) {
                        $validation_errors[] = $field['name'] . ' is required.';
                        continue;
                    }
                }
            }

            // File fields: prefer existing attachment ID to avoid duplicate uploads
            if (($type === 'file' || $type === 'filesortable') && isset($post_data[$id . '_id']) && $post_data[$id . '_id'] !== '') {
                $file_value = $post_data[$id . '_id'];
                // Check if it's multiple (JSON array string) or single (numeric)
                // For filesortable and multiple file fields we expect ordered JSON array
                if ($type === 'filesortable' || !empty($field['multiple'])) {
                    $decoded = json_decode($file_value, true);
                    $save_value = is_array($decoded) ? $decoded : [];
                } else {
                    $save_value = (int)$file_value;
                }
                qpmeta_update_object_meta($object_type, $object_id, $storage_id, $save_value);
            } else if ($type === 'file' && isset($files_data[$id]) && $files_data[$id]['error'] === UPLOAD_ERR_OK) {
                // Use the media system's upload handler
                if (function_exists('qp_handle_upload')) {
                    $file = [
                        'name' => $files_data[$id]['name'],
                        'type' => $files_data[$id]['type'],
                        'tmp_name' => $files_data[$id]['tmp_name'],
                        'size' => $files_data[$id]['size'],
                        'error' => $files_data[$id]['error']
                    ];
                    
                    try {
                        $result = qp_handle_upload($file);
                        
                        // qp_handle_upload returns [true, ['post_id' => ..., ...]] or [false, 'error']
                        if (is_array($result) && count($result) === 2) {
                            if ($result[0] === true && is_array($result[1]) && isset($result[1]['post_id'])) {
                                // Success - store the attachment ID
                                qpmeta_update_object_meta($object_type, $object_id, $storage_id, $result[1]['post_id']);
                            } else if ($result[0] === false) {
                                // Error message
                                $validation_errors[] = $field['name'] . ' upload failed: ' . $result[1];
                            } else {
                                $validation_errors[] = $field['name'] . ' upload failed: Unexpected result format';
                            }
                        } else {
                            $validation_errors[] = $field['name'] . ' upload failed: Invalid return format';
                        }
                    } catch (Exception $e) {
                        $validation_errors[] = $field['name'] . ' upload exception: ' . $e->getMessage();
                    }
                } else {
                    // Fallback to simple upload if media system not available
                    $upload_dir = __DIR__ . '/../uploads/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $filename = basename($files_data[$id]['name']);
                    $target = $upload_dir . $filename;
                    if (move_uploaded_file($files_data[$id]['tmp_name'], $target)) {
                        qpmeta_update_object_meta($object_type, $object_id, $storage_id, '/uploads/' . $filename);
                    } else {
                        $validation_errors[] = $field['name'] . ' upload failed: Could not move file';
                    }
                }
            } else if ($type === 'file' && isset($files_data[$id]) && $files_data[$id]['error'] !== UPLOAD_ERR_NO_FILE) {
                // Handle upload errors
                $error_messages = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                    UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
                ];
                $error_code = $files_data[$id]['error'];
                $error_msg = $error_messages[$error_code] ?? "Unknown error (code: $error_code)";
                $validation_errors[] = $field['name'] . ' upload error: ' . $error_msg;
            } else {
                // Persist main value
                qpmeta_update_object_meta($object_type, $object_id, $storage_id, $val);
                // Also detect any i18n submitted values for this field.
                // Support both `name="storage__i18n[fr]"` (becomes $post_data['storage__i18n']['fr'])
                // and raw bracketed variants (older detection).
                $i18n_values = [];
                $arr_key = $storage_id . '__i18n';
                if (isset($post_data[$arr_key]) && is_array($post_data[$arr_key])) {
                    $i18n_values = $post_data[$arr_key];
                } else {
                    foreach ($post_data as $k => $v) {
                        if (strpos($k, $storage_id . '__i18n[') === 0) {
                            if (preg_match('/' . preg_quote($storage_id, '/') . '__i18n\[([^\]]+)\]/', $k, $m)) {
                                $lang = $m[1];
                                $i18n_values[$lang] = $v;
                            }
                        }
                    }
                }
                if (!empty($i18n_values)) {
                    qpmeta_update_object_meta($object_type, $object_id, 'i18n_' . $storage_id, $i18n_values);
                }
            }
        }
        
        // Process group fields
        if (!empty($mb['groups']) && is_array($mb['groups'])) {
            foreach ($mb['groups'] as $group) {
                $group_fields = $group['fields'] ?? [];
                foreach ($group_fields as $field) {
                    $id = $field['id'];
                    $storage_id = $prefix ? ($prefix . '_' . $id) : $id;
                    $type = $field['type'];
                    $val = $post_data[$id] ?? [];
                    // Clean group repeatable arrays before saving
                    if (!is_array($val)) $val = [];
                    $val = array_values(array_filter($val, function($v){
                        if (is_array($v)) {
                            foreach ($v as $s) { if ($s !== '' && $s !== null) return true; }
                            return false;
                        }
                        return ($v !== '' && $v !== null);
                    }));
                    // Groups are always array-based (repeatable pattern)
                    qpmeta_update_object_meta($object_type, $object_id, $storage_id, $val);
                }
            }
        }
    }
    return empty($validation_errors);
}

function qpmeta_get_object_meta($type, $id, $key) {
    if ($type === 'user') return get_user_meta($id, $key);
    if ($type === 'post') return get_post_meta($id, $key);
    if ($type === 'term') return get_term_meta($id, $key);
    if ($type === 'option') return get_option_meta($key); // id unused for options
    return null;
}

function qpmeta_update_object_meta($type, $id, $key, $value) {
    if ($type === 'user') return update_user_meta($id, $key, $value);
    if ($type === 'post') {
        // Defensive cleanup: if incoming value is an array or a JSON-encoded
        // array, remove empty entries before persisting. This is a last-line
        // defense to avoid saving prototype/template entries that slipped
        // through client-side checks.
        if (is_string($value)) {
            $maybe = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($maybe)) {
                $valueArr = $maybe;
                $valueArr = array_values(array_filter($valueArr, function($v){
                    if (is_array($v)) {
                        foreach ($v as $s) { if ($s !== '' && $s !== null) return true; }
                        return false;
                    }
                    return ($v !== '' && $v !== null);
                }));
                if (empty($valueArr)) {
                    return delete_post_meta($id, $key);
                }
                $value = $valueArr;
            }
        } elseif (is_array($value)) {
            $value = array_values(array_filter($value, function($v){
                if (is_array($v)) {
                    foreach ($v as $s) { if ($s !== '' && $s !== null) return true; }
                    return false;
                }
                return ($v !== '' && $v !== null);
            }));
            if (empty($value)) {
                return delete_post_meta($id, $key);
            }
        }
        // Special-case: when the admin UI saves the legacy `featured_image` field,
        // also persist the canonical `_thumbnail_id` so front-end helpers like
        // `get_post_thumbnail_id()` and `has_post_thumbnail()` behave correctly.
        if ($key === 'featured_image') {
            // Empty or zeroy values should remove the thumbnail
            if ($value === '' || $value === null || (is_string($value) && trim($value) === '') || (is_string($value) && $value === '0') || (is_int($value) && $value === 0)) {
                return delete_post_thumbnail($id);
            }

            // Try to extract a numeric attachment ID from the provided value.
            $attachment_id = null;
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                    if (is_array($decoded)) {
                        foreach (['ID','id','post_ID','post_id','attachment_id'] as $k) {
                            if (isset($decoded[$k]) && (is_int($decoded[$k]) || (is_string($decoded[$k]) && ctype_digit($decoded[$k])))) {
                                $attachment_id = (int)$decoded[$k];
                                break;
                            }
                        }
                        if ($attachment_id === null) {
                            foreach ($decoded as $candidate) {
                                if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) { $attachment_id = (int)$candidate; break; }
                            }
                        }
                    } else {
                        // JSON decoded to a scalar (number or numeric string), handle as attachment id
                        if (is_int($decoded) || (is_string($decoded) && ctype_digit($decoded))) {
                            $attachment_id = (int)$decoded;
                        } elseif (is_numeric($decoded)) {
                            $attachment_id = (int)$decoded;
                        }
                    }
                } else {
                    if (ctype_digit($value)) $attachment_id = (int)$value;
                }
            } elseif (is_int($value)) {
                $attachment_id = $value;
            } elseif (is_array($value)) {
                foreach (['ID','id','post_ID','post_id','attachment_id'] as $k) {
                    if (isset($value[$k]) && (is_int($value[$k]) || (is_string($value[$k]) && ctype_digit($value[$k])))) {
                        $attachment_id = (int)$value[$k];
                        break;
                    }
                }
                if ($attachment_id === null) {
                    foreach ($value as $candidate) {
                        if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) { $attachment_id = (int)$candidate; break; }
                    }
                }
            }

            if ($attachment_id !== null) {
                return set_post_thumbnail($id, $attachment_id);
            }

            // Couldn't parse an attachment id — do not persist legacy `featured_image`.
            // Ensure no stale thumbnail remains.
            return delete_post_thumbnail($id);
        }
        return update_post_meta($id, $key, $value);
    }
    if ($type === 'term') return update_term_meta($id, $key, $value);
    if ($type === 'option') return update_option_meta($key, $value); // id ignored for options
}

// Helper: get prefixed option meta safely (namespace collisions avoidance)
function qpmeta_get_prefixed_option(string $prefix, string $key, $default = null) {
    if ($prefix === '') return get_option_meta($key) ?? $default;
    $val = get_option_meta($prefix . '_' . $key);
    return $val === null ? $default : $val;
}

// Helper: update prefixed option meta
function qpmeta_update_prefixed_option(string $prefix, string $key, $value) {
    $storage = $prefix ? ($prefix . '_' . $key) : $key;
    return update_option_meta($storage, $value);
}


// Add a new taxonomy term
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_add_term', function($req) {
    global $pdo;
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
    if (defined('QP_MULTILANG_DEBUG') && QP_MULTILANG_DEBUG) {
        try { error_log('[qp_multilang-debug] admin_update_post payload content__i18n: ' . var_export($req['content__i18n'] ?? null, true)); } catch (Throwable $_) {}
        try {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log';
            $line = date('c') . " - content__i18n: " . json_encode($req['content__i18n'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents($tmp, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $_) {}

        try {
            $uploads = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log';
            $uploads = realpath(dirname($uploads)) ? dirname($uploads) . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log' : __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log';
            $uploads_dir = dirname($uploads);
            if (!is_dir($uploads_dir)) @mkdir($uploads_dir, 0755, true);
            @file_put_contents($uploads, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $_) {}
    }

    $taxonomy = $req['taxonomy'] ?? '';
    $term = trim($req['term'] ?? '');
    $parent_id = (isset($req['parent_id']) && $req['parent_id'] !== '') ? (int)$req['parent_id'] : null;

    // Respect taxonomy 'hierarchical' flag from core registry: if taxonomy is
    // non-hierarchical, ignore any provided parent_id. If hierarchical, ensure
    // the parent exists and belongs to the same taxonomy.
    $is_hierarchical = $GLOBALS['qlopy_taxonomies'][$taxonomy]['hierarchical'] ?? false;
    if (!$is_hierarchical) {
        // Non-hierarchical taxonomies do not support parent-child relationships.
        $parent_id = null;
    } else {
        if ($parent_id !== null) {
            $stmtParent = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('taxonomy_terms') . " WHERE id = ? AND taxonomy = ?");
            $stmtParent->execute([$parent_id, $taxonomy]);
            if ($stmtParent->fetchColumn() == 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid parent term']);
                exit;
            }
        }
    }

    if (!$taxonomy || $term === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing taxonomy or term']);
        exit;
    }

    $slug = trim($req['slug'] ?? '');
    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $term), '-'));
    }

    // Check existence
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? AND (term = ? OR slug = ?)");
    $stmtCheck->execute([$taxonomy, $term, $slug]);
    if ($stmtCheck->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Term or slug already exists']);
        exit;
    }

    // Insert with proper parent_id or NULL
    $stmtMaxOrder = $pdo->prepare("SELECT COALESCE(MAX(term_order),0) FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ?");
    $stmtMaxOrder->execute([$taxonomy]);
    $maxOrder = (int)$stmtMaxOrder->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO " . table_name('taxonomy_terms') . " (taxonomy, term, slug, parent_id, term_order) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$taxonomy, $term, $slug, $parent_id, $maxOrder + 1]);
    $newTermId = $pdo->lastInsertId();

    // Invalidate compiled rewrite cache so new term appears in compiled tax slugs
    if (function_exists('flush_rewrite_rules')) {
        try { flush_rewrite_rules(); } catch (Throwable $_e) { /* ignore */ }
    }

    echo json_encode(['status' => 'success', 'message' => 'Term added', 'term_id' => $newTermId]);
    if (function_exists('do_action')) do_action('create_term', (int)$newTermId, $taxonomy);
    exit;
});


// Update taxonomy term (including slug, parent_id, term_order)
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_update_term', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
    global $pdo;
    $term_id = intval($req['term_id'] ?? 0);
    $new_term = trim($req['term'] ?? '');
    $slug = trim($req['slug'] ?? '');
    $taxonomy = $req['taxonomy'] ?? '';
    $parent_id = isset($req['parent_id']) && $req['parent_id'] !== '' ? (int)$req['parent_id'] : null;

    // Enforce taxonomy hierarchical rules: ignore parent_id for non-hierarchical
    // taxonomies; for hierarchical taxonomies validate the parent belongs to the
    // same taxonomy.
    $is_hierarchical = $GLOBALS['qlopy_taxonomies'][$taxonomy]['hierarchical'] ?? false;
    if (!$is_hierarchical) {
        $parent_id = null;
    } else {
        if ($parent_id !== null) {
            $stmtParent = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('taxonomy_terms') . " WHERE id = ? AND taxonomy = ?");
            $stmtParent->execute([$parent_id, $taxonomy]);
            if ($stmtParent->fetchColumn() == 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid parent term']);
                exit;
            }
        }
    }
    $term_order = isset($req['term_order']) ? (int)$req['term_order'] : 0;

    if ($term_id <= 0 || !$new_term || !$taxonomy) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $new_term), '-'));
    }

    // Check uniqueness of term and slug excluding current term
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? AND (term = ? OR slug = ?) AND id != ?");
    $stmt->execute([$taxonomy, $new_term, $slug, $term_id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Term or slug already exists']);
        exit;
    }

    // Prevent setting parent to self
    if ($parent_id === $term_id) {
        echo json_encode(['status' => 'error', 'message' => 'Parent term cannot be self']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE " . table_name('taxonomy_terms') . " SET term = ?, slug = ?, parent_id = ?, term_order = ? WHERE id = ? AND taxonomy = ?");
    $ok = $stmt->execute([$new_term, $slug, $parent_id, $term_order, $term_id, $taxonomy]);

    if ($ok) {
        // Persist plain description into term meta when provided (keeps behavior consistent with older handlers)
        if (array_key_exists('description', $req) && function_exists('update_term_meta')) {
            try {
                update_term_meta($term_id, 'description', $req['description']);
            } catch (Throwable $e) {
                // Don't break primary update flow on meta save errors
            }
        }

        // Persist any QPMeta metabox fields embedded in the term edit form
        if (function_exists('qpmeta_save_metaboxes')) {
            $metaErrors = [];
            try {
                qpmeta_save_metaboxes('term', $term_id, $req, $_FILES, $metaErrors);
            } catch (Throwable $e) {
                // Catch unexpected exceptions during meta save and return a clear JSON error
                echo json_encode(['status' => 'error', 'message' => 'Meta save exception: ' . $e->getMessage()]);
                exit;
            }
            if (!empty($metaErrors)) {
                // Return success but include meta validation warnings for visibility
                echo json_encode(['status' => 'success', 'message' => 'Term updated (with meta warnings: ' . implode('; ', $metaErrors) . ')']);
                exit;
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Term updated']);
        if (function_exists('do_action')) do_action('edit_term', (int)$term_id, $taxonomy);
        if (function_exists('flush_rewrite_rules')) {
            try { flush_rewrite_rules(); } catch (Throwable $_e) { }
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    }
    exit;
});

// Delete taxonomy term (consider child terms if necessary)
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_delete_term', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
    global $pdo;
    $term_id = intval($req['term_id'] ?? 0);
    $taxonomy = $req['taxonomy'] ?? '';

    if ($term_id <= 0 || !$taxonomy) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    // Optional: handle cascade delete or reassign children here (not implemented)

    $stmt = $pdo->prepare("DELETE FROM " . table_name('taxonomy_terms') . " WHERE id = ? AND taxonomy = ?");
    $ok = $stmt->execute([$term_id, $taxonomy]);

    if ($ok) {
        echo json_encode(['status' => 'success', 'message' => 'Term deleted']);
        if (function_exists('do_action')) do_action('delete_term', (int)$term_id, $taxonomy);
        if (function_exists('flush_rewrite_rules')) {
            try { flush_rewrite_rules(); } catch (Throwable $_e) { }
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
    }
    exit;
});

// Update term_order for multiple terms (after drag & drop or form submit)
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_update_term_order', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
    global $pdo;
    $taxonomy = $req['taxonomy'] ?? '';
    $orders = $req['order'] ?? [];

    if (!$taxonomy || !is_array($orders)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE " . table_name('taxonomy_terms') . " SET term_order = ? WHERE id = ? AND taxonomy = ?");

    foreach ($orders as $term_id => $order) {
        $term_id = (int)$term_id;
        $order = (int)$order;
        $stmt->execute([$order, $term_id, $taxonomy]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Order updated']);
    exit;
});


// admin/ajax-hooks.php or your admin AJAX handlers file

if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_add_post', function($req) {
    global $pdo;
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }

    if (defined('QP_MULTILANG_DEBUG') && QP_MULTILANG_DEBUG) {
        try { error_log('[qp_multilang-debug] add_post payload content__i18n: ' . var_export($req['content__i18n'] ?? null, true)); } catch (Throwable $_) {}
        try {
            $line = date('c') . " - content__i18n: " . json_encode($req['content__i18n'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log';
            @file_put_contents($tmp, $line, FILE_APPEND | LOCK_EX);
            $uploads_dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads') ?: (__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads');
            if (!is_dir($uploads_dir)) @mkdir($uploads_dir, 0755, true);
            $uploads = rtrim($uploads_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log';
            @file_put_contents($uploads, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $_) {}
    }

    $post_type = $req['post_type'] ?? '';
    $title = trim($req['title'] ?? '');
    $slug = trim($req['slug'] ?? '');
    $content = trim($req['content'] ?? '');
    // Respect post-type supports: title/slug/editor may be optional
    $pt_args = get_post_types()[$post_type] ?? [];
    $pt_supports = $pt_args['supports'] ?? ['title','slug','editor','comments'];
    $supports_has = function($name) use ($pt_supports) {
        if (!is_array($pt_supports)) return false;
        foreach ($pt_supports as $k => $v) {
            if ((is_int($k) && $v === $name) || (is_string($k) && $k === $name)) return true;
        }
        return false;
    };
    $status = trim($req['status'] ?? '');
    $visibility = $req['visibility'] ?? 'public';
    $post_password = $req['post_password'] ?? null;
    $published_at_raw = $req['published_at'] ?? null; // expected string

    // Normalize visibility
    $allowed_visibility = ['public','password','private'];
    if (!in_array($visibility, $allowed_visibility, true)) $visibility = 'public';

    // Parse published_at (interpret in site timezone, return UTC timestamp)
    $published_at_ts = null;
    if (!empty($published_at_raw)) {
        if (function_exists('parse_site_datetime_to_utc')) {
            $ts = parse_site_datetime_to_utc($published_at_raw);
            if ($ts !== null) $published_at_ts = $ts;
        } else {
            $ts = strtotime($published_at_raw);
            if ($ts !== false) $published_at_ts = $ts;
        }
    }

    // Validate required fields depending on supports
    if (!$post_type || ($supports_has('title') && $title === '') || ($supports_has('slug') && $slug === '') || ($supports_has('editor') && $content === '') || $status === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing required post data']);
        exit;
    }

    try {
        // Determine effective status and published_at for insertion
        $now = time();
        $effective_status = $status;
        $published_at_sql = null;

        if ($status === 'published') {
            if ($published_at_ts !== null && $published_at_ts > $now) {
                // schedule for future
                $effective_status = 'scheduled';
                $published_at_sql = date('Y-m-d H:i:s', $published_at_ts);
            } else {
                // publish immediately
                $effective_status = 'published';
                $published_at_sql = date('Y-m-d H:i:s', $now);
            }
        } elseif ($status === 'scheduled') {
            if ($published_at_ts === null || $published_at_ts <= $now) {
                echo json_encode(['status' => 'error', 'message' => 'Scheduled posts require a future published_at timestamp']); exit;
            }
            $published_at_sql = date('Y-m-d H:i:s', $published_at_ts);
        } else {
            // draft or pending_review: published_at remains NULL
            $published_at_sql = null;
        }

        // Auto-generate title or slug when the post-type does not expose those fields
        if (!$supports_has('title')) {
            if ($title === '') {
                $gen = trim(strip_tags($content));
                if ($gen === '') $gen = 'Untitled';
                $title = mb_substr($gen, 0, 60);
            }
        }
        if (!$supports_has('slug')) {
            if ($slug === '') {
                // simple slugify
                $slug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower(trim($title)));
                $slug = trim(preg_replace('/\-+/', '-', $slug), '-');
                if ($slug === '') $slug = 'post-' . time();
                // ensure unique for post_type
                $check = $pdo->prepare("SELECT id FROM " . table_name('posts') . " WHERE slug = ? AND post_type = ? LIMIT 1");
                $base = $slug; $i = 2;
                while (true) {
                    $check->execute([$slug, $post_type]);
                    $row = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$row) break;
                    $slug = $base . '-' . $i; $i++;
                }
            }
        }

        $author_id = function_exists('qp_current_user_id') ? (int)qp_current_user_id() : 0;
        $stmt = $pdo->prepare("INSERT INTO " . table_name('posts') . " (post_type, title, slug, content, status, visibility, post_password, published_at, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        $stmt->execute([$post_type, $title, $slug, $content, $effective_status, $visibility, $post_password, $published_at_sql, $author_id]);
        $post_id = $pdo->lastInsertId();

        // Save taxonomy terms if provided
        if (isset($req['terms']) && is_array($req['terms'])) {
                    $stmtTerms = $pdo->prepare("INSERT INTO " . table_name('post_terms') . " (post_id, term_id) VALUES (?, ?)");
            foreach ($req['terms'] as $tax => $termIds) {
                if(is_array($termIds)) {
                    foreach ($termIds as $termId) {
                        $stmtTerms->execute([$post_id, (int)$termId]);
                    }
                }
            }
        }

        // Save selected page template (allow clearing when Default selected)
        if (array_key_exists('page_template', $req)) {
            $pt = $req['page_template'];
            if ($pt !== null && $pt !== '') {
                update_post_meta($post_id, 'page_template', $pt);
            } else {
                // remove meta row if exists
                delete_post_meta($post_id, 'page_template');
            }
        }
        // Save comments_open flag if this post type supports comments
        if (array_key_exists('comments_open', $req)) {
            update_post_meta($post_id, 'comments_open', !empty($req['comments_open']) ? 1 : 0);
        }
        // Save any qpmeta metabox fields submitted with the add-post form (uploads handled via separate AJAX)
        if (function_exists('qpmeta_save_metaboxes')) {
            $metaErrors = [];
            qpmeta_save_metaboxes('post', $post_id, $req, $_FILES, $metaErrors);
            if (!empty($metaErrors)) {
                echo json_encode(['status' => 'success', 'message' => 'Post added successfully (with meta warnings: ' . implode('; ', $metaErrors) . ')', 'post_id' => (int)$post_id]);
                exit;
            }
        }

        // Persist i18n title/content if submitted (arrays like title__i18n[fr] -> $req['title__i18n'])
        if (!empty($req['title__i18n']) && is_array($req['title__i18n'])) {
            update_post_meta($post_id, 'i18n_title', $req['title__i18n']);
        }
        if (!empty($req['content__i18n']) && is_array($req['content__i18n'])) {
            update_post_meta($post_id, 'i18n_content', $req['content__i18n']);
        }

        echo json_encode(['status' => 'success', 'message' => 'Post added successfully', 'post_id' => (int)$post_id]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $e->getMessage()]);
    }
    exit;
});


if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_update_post', function($req) {
    global $pdo;
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }

    if (defined('QP_MULTILANG_DEBUG') && QP_MULTILANG_DEBUG) {
        try { error_log('[qp_multilang-debug] update_post payload content__i18n: ' . var_export($req['content__i18n'] ?? null, true)); } catch (Throwable $_) {}
        try {
            $line = date('c') . " - content__i18n: " . json_encode($req['content__i18n'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log';
            @file_put_contents($tmp, $line, FILE_APPEND | LOCK_EX);
            $uploads_dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads') ?: (__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads');
            if (!is_dir($uploads_dir)) @mkdir($uploads_dir, 0755, true);
            $uploads = rtrim($uploads_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qp_multilang_debug.log';
            @file_put_contents($uploads, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $_) {}
    }
    $post_id = intval($req['post_id'] ?? 0);
    $post_type = $req['post_type'] ?? '';
    $title = trim($req['title'] ?? '');
    $slug = trim($req['slug'] ?? '');
    $content = trim($req['content'] ?? '');
    // Respect post-type supports for updates too
    $pt_args = get_post_types()[$post_type] ?? [];
    $pt_supports = $pt_args['supports'] ?? ['title','slug','editor','comments'];
    $supports_has = function($name) use ($pt_supports) {
        if (!is_array($pt_supports)) return false;
        foreach ($pt_supports as $k => $v) {
            if ((is_int($k) && $v === $name) || (is_string($k) && $k === $name)) return true;
        }
        return false;
    };
    $status = trim($req['status'] ?? '');
    $visibility = $req['visibility'] ?? 'public';
    $post_password = $req['post_password'] ?? null;
    $published_at_raw = $req['published_at'] ?? null;

    $allowed_visibility = ['public','password','private'];
    if (!in_array($visibility, $allowed_visibility, true)) $visibility = 'public';
    $published_at_ts = null;
    if (!empty($published_at_raw)) {
        if (function_exists('parse_site_datetime_to_utc')) {
            $ts = parse_site_datetime_to_utc($published_at_raw);
            if ($ts !== null) $published_at_ts = $ts;
        } else {
            $ts = strtotime($published_at_raw);
            if ($ts !== false) $published_at_ts = $ts;
        }
    }

    if (!$post_id || !$post_type || ($supports_has('title') && $title === '') || $status === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing required post data']);
        exit;
    }

    try {
        // Load existing post to enforce scheduling rules
        $stmtLoad = $pdo->prepare("SELECT status, published_at FROM " . table_name('posts') . " WHERE id = ? LIMIT 1");
        $stmtLoad->execute([$post_id]);
        $existing = $stmtLoad->fetch(PDO::FETCH_ASSOC);
        $now = time();

        if ($existing) {
            $existing_status = $existing['status'];
            $existing_published_at = $existing['published_at'] ?? null;
            $existing_published_ts = $existing_published_at ? strtotime($existing_published_at) : null;
        } else {
            echo json_encode(['status'=>'error','message'=>'Post not found']); exit;
        }

        // Disallow scheduling a future publish for a post that is already published
        if ($existing_status === 'published' && ($status === 'scheduled' || ($status === 'published' && $published_at_ts !== null && $published_at_ts > $now))) {
            echo json_encode(['status'=>'error','message'=>'Cannot schedule a future publish for a post that is already published']); exit;
        }

        // Determine effective published_at value
        $published_at_sql = null;
        $effective_status = $status;
        if ($status === 'published') {
            if ($published_at_ts !== null) {
                // If published_at in past or now, allow backdate; if future and post not already published, convert to scheduled
                if ($published_at_ts > $now) {
                    $effective_status = 'scheduled';
                    $published_at_sql = date('Y-m-d H:i:s', $published_at_ts);
                } else {
                    $effective_status = 'published';
                    $published_at_sql = date('Y-m-d H:i:s', $published_at_ts);
                }
            } else {
                // No explicit published_at: set to now
                $effective_status = 'published';
                $published_at_sql = date('Y-m-d H:i:s', $now);
            }
        } elseif ($status === 'scheduled') {
            if ($published_at_ts === null || $published_at_ts <= $now) {
                echo json_encode(['status'=>'error','message'=>'Scheduled posts require a future published_at timestamp']); exit;
            }
            $published_at_sql = date('Y-m-d H:i:s', $published_at_ts);
        } else {
            // draft or pending_review: clear published_at
            $published_at_sql = null;
        }

        // Do not update slug here. Slug is managed separately via standalone save action.
        $stmt = $pdo->prepare("UPDATE " . table_name('posts') . " SET title = ?, content = ?, status = ?, visibility = ?, post_password = ?, published_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND post_type = ?");
        $stmt->execute([$title, $content, $effective_status, $visibility, $post_password, $published_at_sql, $post_id, $post_type]);

        // Replace only terms for submitted taxonomies (preserve others, e.g. async added tags if not in form)
        if (isset($req['terms']) && is_array($req['terms']) && !empty($req['terms'])) {
            $submitted_taxonomies = array_keys($req['terms']);
            $placeholders = implode(',', array_fill(0, count($submitted_taxonomies), '?'));
            $sqlSel = "SELECT pt.term_id FROM " . table_name('post_terms') . " pt INNER JOIN " . table_name('taxonomy_terms') . " tt ON tt.id = pt.term_id WHERE pt.post_id = ? AND tt.taxonomy IN ($placeholders)";
            $stmtSel = $pdo->prepare($sqlSel);
            $stmtSel->execute(array_merge([$post_id], $submitted_taxonomies));
            $toDelete = $stmtSel->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($toDelete)) {
                $in = implode(',', array_fill(0, count($toDelete), '?'));
                $stmtDel = $pdo->prepare("DELETE FROM " . table_name('post_terms') . " WHERE post_id = ? AND term_id IN ($in)");
                $stmtDel->execute(array_merge([$post_id], $toDelete));
            }
            $stmtInsert = $pdo->prepare("INSERT INTO " . table_name('post_terms') . " (post_id, term_id) VALUES (?, ?)");
            foreach ($req['terms'] as $tax => $termIds) {
                if (is_array($termIds)) {
                    foreach ($termIds as $termId) {
                        $stmtInsert->execute([$post_id, (int)$termId]);
                    }
                }
            }
        }

        // Save selected page template (allow clearing when Default selected)
        if (array_key_exists('page_template', $req)) {
            $pt = $req['page_template'];
            if ($pt !== null && $pt !== '') {
                update_post_meta($post_id, 'page_template', $pt);
            } else {
                delete_post_meta($post_id, 'page_template');
            }
        }

        // Save comments_open flag if this post type supports comments
        if (array_key_exists('comments_open', $req)) {
            update_post_meta($post_id, 'comments_open', !empty($req['comments_open']) ? 1 : 0);
        }

        // Save QPMeta fields embedded in the post edit form
        // This ensures non-file fields like text/textarea/select/radio/checkbox persist
        if (function_exists('qpmeta_save_metaboxes')) {
            $metaErrors = [];
            // Use the same request payload; files are not processed here for uploads (handled via AJAX)
            qpmeta_save_metaboxes('post', $post_id, $req, $_FILES, $metaErrors);
            // We do not fail the post update if meta save has validation errors; include message if needed
            if (!empty($metaErrors)) {
                // Append meta validation info to success message for visibility
                echo json_encode(['status' => 'success', 'message' => 'Post updated successfully (with meta warnings: ' . implode('; ', $metaErrors) . ')']);
                exit;
            }
        }

        // Persist i18n title/content if submitted (arrays like title__i18n[fr] -> $req['title__i18n'])
        if (!empty($req['title__i18n']) && is_array($req['title__i18n'])) {
            update_post_meta($post_id, 'i18n_title', $req['title__i18n']);
        }
        if (!empty($req['content__i18n']) && is_array($req['content__i18n'])) {
            update_post_meta($post_id, 'i18n_content', $req['content__i18n']);
        }

        echo json_encode(['status' => 'success', 'message' => 'Post updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()]);
    }
    exit;
});


// AJAX: check slug availability and suggest a free slug (like WP's -2/-3)
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_check_slug', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
    global $pdo;
    $post_type = $req['post_type'] ?? '';
    $slug = trim($req['slug'] ?? '');
    $post_id = isset($req['post_id']) ? intval($req['post_id']) : 0;

    if ($post_type === '' || $slug === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
        exit;
    }

    // Normalize: leave as provided (client should send pretty slug), but server will check raw
    try {
        $stmt = $pdo->prepare("SELECT id FROM " . table_name('posts') . " WHERE slug = ? AND post_type = ? LIMIT 1");
        $stmt->execute([$slug, $post_type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($post_id && intval($row['id']) === $post_id)) {
            // available
            echo json_encode(['status' => 'success', 'available' => true, 'suggestion' => $slug]);
            exit;
        }

        // Not available — try suffixes -2, -3, ...
        $base = $slug;
        $i = 2;
        $suggest = '';
        while ($i < 10000) {
            $cand = $base . '-' . $i;
            $stmt->execute([$cand, $post_type]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) { $suggest = $cand; break; }
            $i++;
        }
        if ($suggest === '') $suggest = $base . '-' . time();
        echo json_encode(['status' => 'success', 'available' => false, 'suggestion' => $suggest]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
});


// AJAX: save slug for a post (standalone). Returns available:true when saved, or available:false + suggestion when collision
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_save_slug', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
    global $pdo;
    $post_type = $req['post_type'] ?? '';
    $slug = trim($req['slug'] ?? '');
    $post_id = isset($req['post_id']) ? intval($req['post_id']) : 0;

    if ($post_type === '' || $slug === '' || !$post_id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
        exit;
    }

    try {
        // Check current collision
        $stmt = $pdo->prepare("SELECT id FROM " . table_name('posts') . " WHERE slug = ? AND post_type = ? LIMIT 1");
        $stmt->execute([$slug, $post_type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && intval($row['id']) !== $post_id) {
            // find suggestion
            $base = $slug;
            $i = 2;
            $suggest = '';
            while ($i < 10000) {
                $cand = $base . '-' . $i;
                $stmt->execute([$cand, $post_type]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$r) { $suggest = $cand; break; }
                $i++;
            }
            if ($suggest === '') $suggest = $base . '-' . time();
            echo json_encode(['status' => 'success', 'available' => false, 'suggestion' => $suggest]);
            exit;
        }

        // Update slug for post
        $stmtUp = $pdo->prepare("UPDATE " . table_name('posts') . " SET slug = ? WHERE id = ? AND post_type = ?");
        $ok = $stmtUp->execute([$slug, $post_id, $post_type]);
        if ($ok) {
            echo json_encode(['status' => 'success', 'available' => true, 'suggestion' => $slug]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save slug']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
});



if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_fetch_terms', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }

    global $pdo;

    $taxonomy = $req['taxonomy'] ?? '';
    $post_id = isset($req['post_id']) ? intval($req['post_id']) : 0; // optional

    if (!$taxonomy) {
        echo json_encode(['status' => 'error', 'message' => 'Missing taxonomy']);
        exit;
    }

    // Fetch all terms for the taxonomy ordered
    $stmt = $pdo->prepare("SELECT id, term, parent_id FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? ORDER BY term_order ASC, term ASC");
    $stmt->execute([$taxonomy]);
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Determine which terms are currently assigned to post (if post_id given)
    $checked_terms = [];
    if ($post_id) {
        $stmt2 = $pdo->prepare("SELECT term_id FROM " . table_name('post_terms') . " WHERE post_id = ?");
        $stmt2->execute([$post_id]);
        $checked_terms = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    }

    // Annotate terms with checked status
    foreach ($terms as &$term) {
        $term['checked'] = in_array($term['id'], $checked_terms);
    }
    unset($term);

    echo json_encode([
        'status' => 'success',
        'terms' => $terms
    ]);
    exit;
});

// Get field value from database
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qpmeta_get_field_value', function($req) {
    $object_type = $req['object_type'] ?? '';
    $object_id = intval($req['object_id'] ?? 0);
    $field_name = $req['field_name'] ?? '';

    if (!$object_type || !$object_id || !$field_name) {
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
        exit;
    }

    $value = qpmeta_get_object_meta($object_type, $object_id, $field_name, '');
    
    echo json_encode([
        'status' => 'success',
        'value' => $value
    ]);
    exit;
});

// Save field value to database
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qpmeta_save_field_value', function($req) {
    global $pdo;
    $object_type = $req['object_type'] ?? '';
    $object_id = intval($req['object_id'] ?? 0);
    $field_name = $req['field_name'] ?? '';
    $field_value = $req['field_value'] ?? '';

    if (!$object_type || !$object_id || !$field_name) {
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
        exit;
    }

    // If saving a post meta field, ensure the field is registered for this post_type
    if ($object_type === 'post') {
        $post_type = $req['post_type'] ?? null;
        if (!$post_type && $object_id) {
            if (function_exists('get_post')) {
                $p = get_post($object_id);
                if (is_array($p)) $post_type = $p['post_type'] ?? null;
                elseif (is_object($p)) $post_type = $p->post_type ?? null;
            } else {
                $stmt = $pdo->prepare("SELECT post_type FROM " . table_name('posts') . " WHERE id = ? LIMIT 1");
                $stmt->execute([$object_id]);
                $post_type = $stmt->fetchColumn() ?: null;
            }
        }

        $allowed = false;

        // Special-case: allow quick-save of the inline `featured_image` field
        // even when the canonical qpmeta metabox entry was removed from
        // `$GLOBALS['qpmeta_metaboxes']` (admin/posts.php removes that
        // metabox to render a custom inline control). Permit saves when
        // the post type declares `has_featured` support.
        if ($field_name === 'featured_image') {
            $pt_supports_featured = false;
            if ($post_type) {
                if (function_exists('get_post_types')) {
                    $pt_args = get_post_types()[$post_type] ?? [];
                    if (!empty($pt_args['has_featured'])) $pt_supports_featured = true;
                }
            }
            if (!$pt_supports_featured && $object_id) {
                // Try to derive post_type from DB if not available
                if (empty($post_type)) {
                    try {
                        $stmt = $pdo->prepare("SELECT post_type FROM " . table_name('posts') . " WHERE id = ? LIMIT 1");
                        $stmt->execute([$object_id]);
                        $post_type = $stmt->fetchColumn() ?: null;
                    } catch (Throwable $_) { $post_type = null; }
                }
                if ($post_type && function_exists('get_post_types')) {
                    $pt_args = get_post_types()[$post_type] ?? [];
                    if (!empty($pt_args['has_featured'])) $pt_supports_featured = true;
                }
            }
            if ($pt_supports_featured) {
                $allowed = true;
            } else {
                // Fallback: if we couldn't reliably determine post_type support
                // but this is an existing post, allow the inline featured_image
                // quick-save. The admin UI only renders this control for types
                // that support featured images, so this is a safe fallback.
                if ($object_id && $object_id > 0) {
                    $allowed = true;
                }
            }
        }
        foreach ($GLOBALS['qpmeta_metaboxes'] as $mb) {
            $targets = (array)($mb['object_types'] ?? []);
            if (!in_array($object_type, $targets)) continue;
            if (!empty($mb['post_types']) && is_array($mb['post_types'])) {
                if (!$post_type || !in_array($post_type, $mb['post_types'], true)) continue;
            }
            // Check top-level fields
            foreach ($mb['fields'] ?? [] as $f) {
                if (($f['id'] ?? '') === $field_name) { $allowed = true; break 2; }
            }
            // Check grouped fields
            if (!empty($mb['groups'])) {
                foreach ($mb['groups'] as $group) {
                    foreach ($group['fields'] ?? [] as $gf) {
                        if (($gf['id'] ?? '') === $field_name) { $allowed = true; break 3; }
                    }
                }
            }
        }

        if (!$allowed) {
            echo json_encode(['status' => 'error', 'message' => 'Field not allowed for this post type']);
            exit;
        }
    }

    qpmeta_update_object_meta($object_type, $object_id, $field_name, $field_value);

    echo json_encode([
        'status' => 'success',
        'message' => 'Saved'
    ]);
    exit;
});

// Admin AJAX proxies for media actions: forward to global qp_media_* handlers
if (function_exists('add_admin_action')) {
    // Delegate media list to the canonical `qp_media_list` handler which understands
    // `file_mode` and `uploaded_month` filters. This ensures admin AJAX list honors
    // client-side filters and pagination.
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_list', function($req){
        // If a global qp_media_list handler exists, call it to produce JSON and exit.
        if (function_exists('do_action')) {
            if (do_action('qp_media_list', $req)) {
                exit;
            }
        }
        // Fallback: return empty list if no handler available
        echo json_encode(['status'=>'success', 'items'=>[], 'total'=>0]);
        exit;
    }, 10, 1);
    // Batch get by IDs (returns items in same order as requested)
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_batch_get', function($req){
        global $pdo;
        $ids_raw = $req['ids'] ?? '';
        if (is_array($ids_raw)) $ids = array_map('intval', $ids_raw);
        else $ids = array_filter(array_map('intval', array_map('trim', explode(',', $ids_raw))));
        if (empty($ids)) { echo json_encode(['status'=>'error','message'=>'No ids']); exit; }
        // Prepare ordered fetch
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $pdo->prepare('SELECT id, title, created_at FROM ' . table_name('posts') . ' WHERE post_type = ? AND id IN (' . $placeholders . ')');
            $params = array_merge(['attachment'], $ids);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Index rows by id for ordering
            $byId = [];
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $meta = function_exists('qp_get_attachment_metadata') ? (array)qp_get_attachment_metadata($id) : [];
                $file = $meta['file'] ?? [];
                $thumb = function_exists('qp_get_attachment_image_src') ? qp_get_attachment_image_src($id, 'thumbnail') : null;
                $created_raw = $r['created_at'] ?? null;
                $byId[$id] = [
                    'id' => $id,
                    'title' => $r['title'] ?? ('Attachment #' . $id),
                    'url' => $file['url'] ?? null,
                    'thumb' => $thumb ? ($thumb[0] ?? null) : null,
                    'created_at' => $created_raw,
                    'created_at_formatted' => (function_exists('format_site_datetime') ? format_site_datetime($created_raw) : $created_raw)
                ];
            }
            $items = [];
            foreach ($ids as $rid) { if (isset($byId[$rid])) $items[] = $byId[$rid]; }
            echo json_encode(['status'=>'success','items'=>$items]);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }, 10, 1);
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_get', function($req){ do_action('qp_media_get', $req); }, 10, 1);
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_update', function($req){ do_action('qp_media_update', $req); }, 10, 1);
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_delete', function($req){ do_action('qp_media_delete', $req); }, 10, 1);
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_bulk_delete', function($req){ do_action('qp_media_bulk_delete', $req); }, 10, 1);
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_upload', function($req){ do_action('qp_media_upload', $req); }, 10, 1);
    // Expose allowed mimes/extensions to admin JS
    if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qp_media_allowed_mimes', function($req){
        try {
            $allowed = function_exists('qp_allowed_mimes') ? qp_allowed_mimes() : [];
            $exts = [];
            foreach ($allowed as $mime => $arr) {
                foreach ((array)$arr as $e) $exts[] = strtolower($e);
            }
            $exts = array_values(array_unique($exts));
            echo json_encode(['status' => 'success', 'mimes' => $allowed, 'extensions' => $exts]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }, 10, 1);
}

// Provide default implementations for media actions if not registered elsewhere
// Removed secondary add_action fallback; direct admin handler above ensures functionality

/**
 * Print JavaScript handlers for qpmeta forms (once per page load)
 */
function qpmeta_print_scripts() {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    // Inline scripts were intentionally removed. The admin asset
    // `admin/assets/js/qpmeta-admin.js` should be enqueued from
    // `admin/admin_head.php`. This keeps behavior centralized and
    // avoids duplicate event bindings.
}

// --- Taxonomy/tag backend ---
function qpmeta_register_taxonomy($name, $menu_visible = false) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('taxonomy') . ' WHERE taxonomy = ?');
    $stmt->execute([$name]);
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO taxonomy (taxonomy, menu_visible) VALUES (?, ?)");
        $stmt->execute([$name, $menu_visible ? 1 : 0]);
    }
}

function qpmeta_search_tags($taxonomy, $query) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, term FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND term LIKE ? ORDER BY term ASC LIMIT 10');
    $stmt->execute([$taxonomy, "%$query%"]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function qpmeta_add_tag($taxonomy, $term) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT id FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND term = ?');
    $stmt->execute([$taxonomy, $term]);
    $id = $stmt->fetchColumn();
    if ($id) return $id;
    $stmt = $pdo->prepare('INSERT INTO ' . table_name('taxonomy_terms') . ' (taxonomy, term) VALUES (?, ?)');
    $stmt->execute([$taxonomy, $term]);
    return $pdo->lastInsertId();
}

function qpmeta_set_post_tags($post_id, $taxonomy, $tag_ids) {
    global $pdo;
    // Remove existing tag term relations for this taxonomy by selecting term_ids first
    $stmtSel = $pdo->prepare('SELECT pt.term_id FROM ' . table_name('post_terms') . " pt INNER JOIN " . table_name('taxonomy_terms') . " tt ON tt.id = pt.term_id WHERE pt.post_id = ? AND tt.taxonomy = ?");
    $stmtSel->execute([$post_id, $taxonomy]);
    $existing = $stmtSel->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existing)) {
        $in = implode(',', array_fill(0, count($existing), '?'));
        $params = array_merge([$post_id], $existing);
        $pdo->prepare("DELETE FROM post_terms WHERE post_id = ? AND term_id IN ($in)")->execute($params);
    }
    foreach ($tag_ids as $tid) {
        $pdo->prepare("INSERT INTO post_terms (post_id, term_id) VALUES (?, ?)")->execute([$post_id, $tid]);
    }
}

function qpmeta_get_post_tags($post_id, $taxonomy) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT t.id, t.term, t.slug FROM ' . table_name('taxonomy_terms') . " t INNER JOIN " . table_name('post_terms') . " pt ON pt.term_id = t.id WHERE pt.post_id = ? AND t.taxonomy = ?");
        $stmt->execute([$post_id, $taxonomy]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Missing table or other DB error — fail gracefully for front-end display.
        return [];
    }
}
    // Wrapper expected by tags field rendering (original call name)
if (!function_exists('qp_get_object_terms')) {
    function qp_get_object_terms($object_id, $taxonomy) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT t.id, t.term, t.slug FROM " . table_name('taxonomy_terms') . " t INNER JOIN " . table_name('post_terms') . " pt ON pt.term_id = t.id WHERE pt.post_id = ? AND t.taxonomy = ? ORDER BY t.term ASC");
        $stmt->execute([$object_id, $taxonomy]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --- AJAX endpoints ---
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qpmeta_tag_search', function($req) {
    $taxonomy = $req['taxonomy'] ?? '';
    $q = $req['q'] ?? '';
    echo json_encode(['status' => 'success', 'tags' => qpmeta_search_tags($taxonomy, $q)]);
    exit;
});
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qpmeta_tag_add', function($req) {
    $taxonomy = $req['taxonomy'] ?? '';
    $term = $req['term'] ?? '';
    $id = qpmeta_add_tag($taxonomy, $term);
    echo json_encode(['status' => 'success', 'id' => $id]);
    exit;
});
if (function_exists('add_admin_action')) add_admin_action('iitcm_admin_ajax_qpmeta_tag_set', function($req) {
    $post_id = intval($req['post_id'] ?? 0);
    $taxonomy = $req['taxonomy'] ?? '';
    $tag_ids = $req['tag_ids'] ?? [];
    qpmeta_set_post_tags($post_id, $taxonomy, $tag_ids);
    echo json_encode(['status' => 'success']);
    exit;
});
