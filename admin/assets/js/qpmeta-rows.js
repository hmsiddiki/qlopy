/* qpmeta `repeatable_row` field: add, remove, reorder and select rows.
   Markup comes from qpmeta_rows_control() in includes/qpmeta-rows.php: every row is a <tbody class="qpmeta-rows-row">
   (a main line and an optional hidden detail line). New rows are cloned from an inert <template>, so nothing in the
   template is ever submitted. Fires `qpmeta:rows-changed` (bubbles, detail.wrap) after every change so plugins can
   react, and exposes window.qpmetaRows for plugin scripts. No dependencies. */
(function () {
    if (window.__qpmeta_rows_initialized) return;
    window.__qpmeta_rows_initialized = true;

    function rows(wrap) { return Array.prototype.slice.call(wrap.querySelectorAll('table.qpmeta-rows-table > tbody.qpmeta-rows-row')); }

    function inputs(row, column) { return row.querySelectorAll('[name$="[' + column + ']"]'); }

    /** Current value of a column in a row (checkbox: '1' or '0'). */
    function get(row, column) {
        var list = inputs(row, column);
        var field = list[list.length - 1];
        if (!field) return '';
        return field.type === 'checkbox' ? (field.checked ? '1' : '0') : field.value;
    }

    function set(row, column, value) {
        var list = inputs(row, column);
        Array.prototype.forEach.call(list, function (field) {
            if (field.type === 'checkbox') {
                field.checked = value === true || value === '1' || value === 1;
            } else if (field.type !== 'hidden' || list.length === 1) {
                field.value = value == null ? '' : String(value);
            }
        });
    }

    function refresh(wrap) {
        var max = parseInt(wrap.getAttribute('data-max'), 10) || 0;
        var min = parseInt(wrap.getAttribute('data-min'), 10) || 0;
        var n = rows(wrap).length;
        var addButton = wrap.querySelector('.qpmeta-rows-add');
        if (addButton) addButton.disabled = max > 0 && n >= max;
        Array.prototype.forEach.call(wrap.querySelectorAll('.qpmeta-rows-remove'), function (b) { b.disabled = n <= min; });
        var all = wrap.querySelector('.qpmeta-rows-select-all');
        if (all) all.checked = n > 0 && wrap.querySelectorAll('.qpmeta-rows-select:checked').length === n;
        wrap.dispatchEvent(new CustomEvent('qpmeta:rows-changed', { bubbles: true, detail: { wrap: wrap, count: n } }));
    }

    /** Adds a row (null at the row limit), optionally filling columns from an object. */
    function add(wrap, values) {
        var max = parseInt(wrap.getAttribute('data-max'), 10) || 0;
        if (max > 0 && rows(wrap).length >= max) return null;
        var tpl = wrap.querySelector('template.qpmeta-rows-template');
        var next = parseInt(wrap.getAttribute('data-next'), 10) || 0;
        wrap.setAttribute('data-next', next + 1);
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__i__/g, String(next));
        var row = holder.querySelector('tbody.qpmeta-rows-row');
        wrap.querySelector('table.qpmeta-rows-table').appendChild(row);
        Object.keys(values || {}).forEach(function (column) { set(row, column, values[column]); });
        refresh(wrap);
        return row;
    }

    function selected(wrap) {
        return rows(wrap).filter(function (row) {
            var box = row.querySelector('.qpmeta-rows-select');
            return box && box.checked;
        });
    }

    window.qpmetaRows = {
        rows: rows,
        add: add,
        get: get,
        set: set,
        selected: selected,
        refresh: refresh,
        wrap: function (field) { return document.querySelector('.qpmeta-rows[data-field="' + field + '"]'); }
    };

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.qpmeta-rows-add, .qpmeta-rows-remove, .qpmeta-rows-up, .qpmeta-rows-down, .qpmeta-rows-toggle');
        if (!btn) return;
        var wrap = btn.closest('.qpmeta-rows');
        if (!wrap) return;
        e.preventDefault();

        if (btn.classList.contains('qpmeta-rows-add')) {
            var row = add(wrap);
            if (row) {
                var first = row.querySelector('input:not([type=hidden]):not(.qpmeta-rows-select),select,textarea');
                if (first) first.focus();
            }
            return;
        }

        var item = btn.closest('tbody.qpmeta-rows-row');
        if (btn.classList.contains('qpmeta-rows-toggle')) {
            var detail = item.querySelector('.qpmeta-rows-detail');
            if (detail) {
                detail.hidden = !detail.hidden;
                btn.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true');
            }
            return;
        }

        var parent = item.parentNode;
        if (btn.classList.contains('qpmeta-rows-remove')) {
            parent.removeChild(item);
        } else if (btn.classList.contains('qpmeta-rows-up') && item.previousElementSibling && item.previousElementSibling.classList.contains('qpmeta-rows-row')) {
            parent.insertBefore(item, item.previousElementSibling);
        } else if (btn.classList.contains('qpmeta-rows-down') && item.nextElementSibling) {
            parent.insertBefore(item.nextElementSibling, item);
        }
        refresh(wrap);
    });

    // `media` columns: pick an image in Qlopy's media modal; the cell stores the attachment id.
    function drawPreview(box, url) {
        box.innerHTML = '';
        if (!url) return;
        var img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.style.cssText = 'max-width:48px;max-height:48px;vertical-align:middle;margin-right:6px;';
        box.appendChild(img);
    }

    document.addEventListener('click', function (e) {
        var pick = e.target.closest && e.target.closest('.qpmeta-rows-media-pick');
        var clear = !pick && e.target.closest && e.target.closest('.qpmeta-rows-media-clear');
        var button = pick || clear;
        if (!button) return;
        e.preventDefault();
        var cellBox = button.parentNode;
        var input = cellBox.querySelector('.qpmeta-rows-media-id');
        var preview = cellBox.querySelector('.qpmeta-rows-media-preview');
        var wrap = button.closest('.qpmeta-rows');

        if (clear) {
            input.value = '';
            drawPreview(preview, '');
            if (wrap) refresh(wrap);
            return;
        }

        if (!window.qlopyMedia || typeof window.qlopyMedia.openModal !== 'function') {
            window.alert('The media library is not available on this screen.');
            return;
        }
        window.qlopyMedia.openModal({
            multiple: false,
            fileMode: 'images',
            preselect: input.value ? [String(input.value)] : [],
            onInsert: function (selection) {
                var item = Array.isArray(selection) ? selection[0] : selection;
                var id = item && (item.id || item);
                if (!id || typeof id === 'object') return;
                input.value = String(id);
                var url = item && (item.thumb || item.thumbnail || item.url);
                if (url) {
                    drawPreview(preview, url);
                } else if (window.ajaxurl && window.fetch) {
                    window.fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=qp_media_batch_get&ids=' + encodeURIComponent(id) })
                        .then(function (response) { return response.json(); })
                        .then(function (json) {
                            var first = json && json.items && json.items[0];
                            drawPreview(preview, first ? (first.thumb || first.url) : '');
                        })
                        .catch(function () {});
                }
                if (wrap) refresh(wrap);
            }
        });
    });

    document.addEventListener('change', function (e) {
        var box = e.target;
        if (!box.classList || !box.closest) return;
        var wrap = box.closest('.qpmeta-rows');
        if (!wrap) return;
        if (box.classList.contains('qpmeta-rows-select-all')) {
            Array.prototype.forEach.call(wrap.querySelectorAll('.qpmeta-rows-select'), function (c) { c.checked = box.checked; });
        } else if (box.classList.contains('qpmeta-rows-select')) {
            refresh(wrap);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.qpmeta-rows'), refresh);
    });
})();
