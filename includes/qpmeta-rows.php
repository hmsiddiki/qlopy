<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * qpmeta field type `repeatable_row`: a table of rows with several typed columns, stored as ONE meta value
 * (a list of associative rows), so plugins get structured data instead of parallel arrays.
 *
 *   qpmeta_register_metabox('my_box', [
 *       'title' => 'Prices', 'object_types' => ['post'],
 *       'fields' => [[
 *           'id' => 'my_rows', 'name' => 'Price tiers', 'type' => 'repeatable_row',
 *           'columns' => [
 *               ['id' => 'label', 'label' => 'Label',  'type' => 'text',   'required' => true, 'maxlength' => 80],
 *               ['id' => 'price', 'label' => 'Price',  'type' => 'number', 'min' => 0, 'step' => '0.01'],
 *               ['id' => 'kind',  'label' => 'Kind',   'type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']],
 *               ['id' => 'on',    'label' => 'Active', 'type' => 'checkbox'],
 *           ],
 *           'min_rows' => 0, 'max_rows' => 50, 'add_label' => 'Add tier',
 *       ]],
 *   ]);
 *
 * Stored value:  [['label' => 'Small', 'price' => '9.50', 'kind' => 'a', 'on' => '1'], ...]  (empty rows dropped)
 * Column types:  text, textarea, hidden, number, date, select, checkbox, media (an image from Qlopy's media library; stores the attachment id). Column keys: id, label, type, required, maxlength,
 *                min, max, step, options, placeholder, width (CSS), default, detail (true: shown in a collapsible second
 *                line under the row, behind a Details button, to keep wide rows readable).
 * Field keys:    columns, min_rows, max_rows, add_label, selectable (true: a checkbox per row, never submitted, for bulk
 *                actions in a plugin script), min_width (CSS, keeps a wide table readable: it scrolls sideways instead of
 *                squeezing its inputs), validate.required.
 * Validation:    rows are checked on save by qpmeta_rows_normalize(); a refusal keeps the stored value and shows the
 *                message. Plugins can still refine the result through the `qpmeta_sanitize_field` filter.
 * Browser:       admin/assets/js/qpmeta-rows.js adds, removes and reorders rows and fires `qpmeta:rows-changed`. It exposes
 *                window.qpmetaRows (rows, add, get, set, selected) so plugin scripts can fill rows or act on the selection.
 */

/** The columns of a repeatable_row field with their defaults applied; invalid column definitions are skipped. */
function qpmeta_rows_columns(array $field): array
{
    $columns = [];
    foreach ((array)($field['columns'] ?? []) as $col) {
        if (!is_array($col) || !isset($col['id']) || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', (string)$col['id'])) continue;
        $type = (string)($col['type'] ?? 'text');
        if (!in_array($type, ['text', 'textarea', 'hidden', 'number', 'date', 'select', 'checkbox', 'media'], true)) $type = 'text';
        $col['type'] = $type;
        $col['label'] = (string)($col['label'] ?? $col['id']);
        $columns[(string)$col['id']] = $col;
    }
    return $columns;
}

/** True when a cell holds nothing the user typed or chose (hidden values and unticked boxes do not count). */
function qpmeta_rows_cell_empty(array $col, $value): bool
{
    if ($col['type'] === 'hidden') return true;
    if ($col['type'] === 'checkbox' || $col['type'] === 'media') return !$value || $value === '0';
    return $value === null || (is_scalar($value) && trim((string)$value) === '');
}

/**
 * Turn posted rows into the value to store. Unknown columns are dropped, values are cleaned per column type, rows
 * with nothing in them are removed and the list is re-indexed in submitted order.
 *
 * @param mixed $raw The posted array (numeric keys are rows; the `__rows` marker is ignored).
 * @return array<int, array<string, string>>
 * @throws InvalidArgumentException With a message naming the row and column that is wrong.
 */
function qpmeta_rows_normalize($raw, array $field): array
{
    $columns = qpmeta_rows_columns($field);
    $rows = [];
    $n = 0;
    foreach (is_array($raw) ? $raw : [] as $key => $posted) {
        if ($key === '__rows' || !is_array($posted)) continue;
        $row = [];
        $filled = false;
        foreach ($columns as $cid => $col) {
            $value = $posted[$cid] ?? ($col['type'] === 'checkbox' ? '0' : '');
            if (!qpmeta_rows_cell_empty($col, $value)) $filled = true;
            $row[$cid] = $value;
        }
        if (!$filled) continue;

        $n++;
        $where = ($field['name'] ?? 'Row') . ' row ' . $n;
        foreach ($columns as $cid => $col) {
            $label = $col['label'];
            $value = $row[$cid];
            if (!is_scalar($value)) throw new InvalidArgumentException("{$where}: {$label} is not valid.");
            $value = (string)$value;
            switch ($col['type']) {
                case 'checkbox':
                    $value = qpmeta_rows_cell_empty($col, $value) ? '0' : '1';
                    break;
                case 'number':
                    $value = trim($value);
                    if ($value !== '') {
                        if (!is_numeric($value) || !is_finite((float)$value)) throw new InvalidArgumentException("{$where}: {$label} must be a number.");
                        if (isset($col['min']) && (float)$value < (float)$col['min']) throw new InvalidArgumentException("{$where}: {$label} must be at least {$col['min']}.");
                        if (isset($col['max']) && (float)$value > (float)$col['max']) throw new InvalidArgumentException("{$where}: {$label} must be at most {$col['max']}.");
                    }
                    break;
                case 'media':
                    $value = trim($value);
                    if ($value === '0') $value = '';
                    if ($value !== '' && (preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1 || (function_exists('qp_get_attachment_metadata') && !qp_get_attachment_metadata((int)$value)))) {
                        throw new InvalidArgumentException("{$where}: {$label} is not a file in the media library.");
                    }
                    break;
                case 'date':
                    $value = trim($value);
                    if ($value !== '' && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $d) !== 1 || !checkdate((int)$d[2], (int)$d[3], (int)$d[1]))) {
                        throw new InvalidArgumentException("{$where}: {$label} must be a date.");
                    }
                    break;
                case 'select':
                    $value = trim($value);
                    $allowed = array_map('strval', array_keys((array)($col['options'] ?? [])));
                    if ($value !== '' && !in_array($value, $allowed, true)) throw new InvalidArgumentException("{$where}: choose a valid {$label}.");
                    break;
                default: // text, textarea, hidden
                    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', trim($value));
                    $max = (int)($col['maxlength'] ?? ($col['type'] === 'textarea' ? 5000 : 255));
                    if (function_exists('mb_strlen') ? mb_strlen($value) > $max : strlen($value) > $max) {
                        throw new InvalidArgumentException("{$where}: {$label} is longer than {$max} characters.");
                    }
            }
            if (!empty($col['required']) && ($col['type'] === 'checkbox' ? $value !== '1' : $value === '')) {
                throw new InvalidArgumentException("{$where}: {$label} is required.");
            }
            $row[$cid] = $value;
        }
        $rows[] = $row;
    }

    $max = (int)($field['max_rows'] ?? 0);
    if ($max > 0 && count($rows) > $max) throw new InvalidArgumentException(($field['name'] ?? 'Rows') . ": at most {$max} rows are allowed.");
    $min = max((int)($field['min_rows'] ?? 0), !empty($field['validate']['required']) ? 1 : 0);
    if (count($rows) < $min) throw new InvalidArgumentException(($field['name'] ?? 'Rows') . ": at least {$min} row" . ($min === 1 ? '' : 's') . ' required.');

    return $rows;
}

/** One cell's input. $name is the full input name, for example `my_rows[__i__][price]`. */
function qpmeta_rows_cell(array $col, string $name, $value): void
{
    $h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
    $n = $h($name);
    $label = $h($col['label']);
    $ph = isset($col['placeholder']) ? ' placeholder="' . $h($col['placeholder']) . '"' : '';
    $req = !empty($col['required']) ? ' required' : '';
    if (($value === '' || $value === null) && isset($col['default'])) $value = $col['default'];
    $value = is_scalar($value) ? (string)$value : '';

    switch ($col['type']) {
        case 'hidden':
            echo "<input type='hidden' name='{$n}' value='" . $h($value) . "'>";
            break;
        case 'textarea':
            echo "<textarea name='{$n}' class='form-control' rows='2' aria-label='{$label}'{$ph}{$req}>" . $h($value) . "</textarea>";
            break;
        case 'number':
            $attrs = '';
            foreach (['min', 'max', 'step'] as $a) if (isset($col[$a])) $attrs .= " {$a}='" . $h($col[$a]) . "'";
            echo "<input type='number' name='{$n}' value='" . $h($value) . "' class='form-control' aria-label='{$label}'{$attrs}{$ph}{$req}>";
            break;
        case 'media':
            // An attachment id chosen in Qlopy's media modal (see qpmeta-rows.js); shows a small preview.
            echo "<input type='hidden' name='{$n}' value='" . $h($value === '0' ? '' : $value) . "' class='qpmeta-rows-media-id'>";
            echo "<span class='qpmeta-rows-media-preview'>";
            if ($value !== '' && $value !== '0' && function_exists('qp_get_attachment_image_src') && ($src = qp_get_attachment_image_src((int)$value, 'thumbnail')) && !empty($src[0])) {
                echo "<img src='" . $h($src[0]) . "' alt='' style='max-width:48px;max-height:48px;vertical-align:middle;margin-right:6px;'>";
            }
            echo "</span><button type='button' class='btn btn-sm btn-outline-secondary qpmeta-rows-media-pick'>Choose</button> "
               . "<button type='button' class='btn btn-sm btn-outline-danger qpmeta-rows-media-clear' aria-label='Remove image'>&times;</button>";
            break;
        case 'date':
            echo "<input type='date' name='{$n}' value='" . $h($value) . "' class='form-control' aria-label='{$label}'{$req}>";
            break;
        case 'select':
            echo "<select name='{$n}' class='form-control' aria-label='{$label}'{$req}>";
            if (empty($col['required']) || $value === '') echo "<option value=''></option>";
            foreach ((array)($col['options'] ?? []) as $ov => $ol) {
                echo "<option value='" . $h($ov) . "'" . ((string)$ov === $value ? ' selected' : '') . ">" . $h($ol) . "</option>";
            }
            echo "</select>";
            break;
        case 'checkbox':
            echo "<input type='hidden' name='{$n}' value='0'>";
            echo "<input type='checkbox' name='{$n}' value='1' aria-label='{$label}'" . (($value !== '' && $value !== '0') ? ' checked' : '') . ">";
            break;
        default:
            $max = (int)($col['maxlength'] ?? 255);
            echo "<input type='text' name='{$n}' value='" . $h($value) . "' maxlength='{$max}' class='form-control' aria-label='{$label}'{$ph}{$req}>";
    }
}

/** The `repeatable_row` control: header row, existing rows, an inert row template and the add button. */
function qpmeta_rows_control(string $name, $value, array $field): void
{
    $h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
    $name = preg_replace('/\[\]$/', '', $name);
    $columns = qpmeta_rows_columns($field);
    if (!$columns) return;

    $rows = [];
    foreach (is_array($value) ? $value : [] as $row) if (is_array($row)) $rows[] = $row;
    $min = (int)($field['min_rows'] ?? 0);
    while (count($rows) < $min) $rows[] = [];
    $max = (int)($field['max_rows'] ?? 0);
    $add = $h($field['add_label'] ?? 'Add row');
    $selectable = !empty($field['selectable']);
    $main = array_filter($columns, static fn ($c) => $c['type'] !== 'hidden' && empty($c['detail']));
    $detail = array_filter($columns, static fn ($c) => $c['type'] !== 'hidden' && !empty($c['detail']));
    $span = count($main) + ($selectable ? 1 : 0) + 1;

    // One <tbody> per row: the main line, and (when the field has detail columns) a hidden line beneath it.
    $line = static function (string $index, array $row) use ($columns, $main, $detail, $name, $h, $selectable, $span): void {
        $cell = static fn (string $cid): string => $name . '[' . $index . '][' . $cid . ']';
        echo "<tbody class='qpmeta-rows-row'><tr class='qpmeta-rows-main'>";
        if ($selectable) {
            echo "<td style='width:1%;'><input type='checkbox' class='qpmeta-rows-select' aria-label='Select row'></td>";
        }
        foreach ($main as $cid => $col) {
            $w = isset($col['width']) ? ' style="width:' . $h($col['width']) . '"' : '';
            echo "<td{$w}>";
            qpmeta_rows_cell($col, $cell($cid), $row[$cid] ?? '');
            echo "</td>";
        }
        echo "<td style='white-space:nowrap;width:1%;'>";
        foreach ($columns as $cid => $col) {
            if ($col['type'] === 'hidden') qpmeta_rows_cell($col, $cell($cid), $row[$cid] ?? '');
        }
        if ($detail) {
            echo "<button type='button' class='btn btn-sm btn-outline-secondary qpmeta-rows-toggle' aria-expanded='false' title='Show or hide the details'>Details</button> ";
        }
        echo "<button type='button' class='btn btn-sm btn-outline-secondary qpmeta-rows-up' title='Move up' aria-label='Move row up'>&uarr;</button> "
           . "<button type='button' class='btn btn-sm btn-outline-secondary qpmeta-rows-down' title='Move down' aria-label='Move row down'>&darr;</button> "
           . "<button type='button' class='btn btn-sm btn-outline-danger qpmeta-rows-remove' title='Remove' aria-label='Remove row'>&times;</button>"
           . "</td></tr>";
        if ($detail) {
            echo "<tr class='qpmeta-rows-detail' hidden><td colspan='{$span}'><div style='display:flex;flex-wrap:wrap;gap:12px;'>";
            foreach ($detail as $cid => $col) {
                $w = isset($col['width']) ? $h($col['width']) : '160px';
                echo "<label style='flex:1 1 {$w};min-width:{$w};font-weight:normal;'><span style='display:block;font-weight:600;'>" . $h($col['label']) . "</span>";
                qpmeta_rows_cell($col, $cell($cid), $row[$cid] ?? '');
                echo "</label>";
            }
            echo "</div></td></tr>";
        }
        echo "</tbody>";
    };

    static $styled = false;
    if (!$styled) {
        $styled = true;
        echo "<style>.qpmeta-rows .form-control{padding:.25rem .4rem;height:auto;font-size:.9rem;min-width:0}"
           . ".qpmeta-rows td{vertical-align:middle;padding:.25rem}.qpmeta-rows .btn-sm{padding:.15rem .4rem}</style>";
    }
    echo "<div class='qpmeta-rows' data-next='" . count($rows) . "' data-max='" . $max . "' data-min='" . $min . "' data-field='" . $h($name) . "'>";
    // Marker so an emptied table still posts (and clears the stored rows).
    echo "<input type='hidden' name='" . $h($name) . "[__rows]' value='1'>";
    echo "<div style='overflow-x:auto;'><table class='table table-sm qpmeta-rows-table' style='width:100%;margin-bottom:8px;" . (isset($field['min_width']) ? 'min-width:' . $h($field['min_width']) . ';' : '') . "'><thead><tr>";
    if ($selectable) echo "<th scope='col'><input type='checkbox' class='qpmeta-rows-select-all' aria-label='Select all rows'></th>";
    foreach ($main as $col) {
        echo "<th scope='col'>" . $h($col['label']) . (!empty($col['required']) ? ' *' : '') . "</th>";
    }
    echo "<th></th></tr></thead>";
    foreach ($rows as $i => $row) $line((string)$i, $row);
    echo "</table></div>";
    echo "<template class='qpmeta-rows-template'><table>";
    $line('__i__', []);
    echo "</table></template>";
    echo "<button type='button' class='btn btn-sm btn-primary qpmeta-rows-add'>{$add}</button>";
    echo "</div>";
}
