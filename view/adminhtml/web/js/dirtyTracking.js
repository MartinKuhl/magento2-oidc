/**
 * Dirty-field tracking for the OIDC Provider edit form.
 *
 * Snapshots every form control's value on page load, then listens for changes.
 * When a field's current value differs from its original value the field
 * receives the CSS class `m2oidc-field-modified` and its nearest row ancestor
 * (a `.admin__field` on the fieldset-based tabs, or a `<tr>` on the remaining
 * grid-style dynamic tables) receives `m2oidc-row-modified` — used to accent
 * rows whose only interactive element is a checkbox or color-picker.
 *
 * Dynamic mapping rows added via cloneNode() are handled by a MutationObserver
 * so newly added inputs are snapshotted as "empty = original" and highlight as
 * soon as the user types a value.
 */
define(['domReady!'], function () {
    'use strict';

    var DIRTY_CLASS     = 'm2oidc-field-modified';
    var ROW_DIRTY_CLASS = 'm2oidc-row-modified';

    // WeakMap keeps element references without preventing GC.
    var originals = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

    /** Store the current value/checked state as the baseline for this element. */
    function snapshot(el) {
        if (!originals) { return; }
        if (el.type === 'checkbox' || el.type === 'radio') {
            originals.set(el, el.checked);
        } else if (el.type === 'password') {
            // Password fields are never pre-filled — treat blank as the original
            // regardless of what the DOM says.
            originals.set(el, '');
        } else {
            originals.set(el, el.value);
        }
    }

    /** Return true when the element's current state differs from its snapshot. */
    function isDirty(el) {
        if (!originals) { return false; }
        if (!originals.has(el)) {
            // Unseen element (e.g. cloned row before MutationObserver fired):
            // treat as dirty only if it already has a value.
            return el.type === 'password' ? el.value !== '' : el.value !== '';
        }
        var orig = originals.get(el);
        if (el.type === 'checkbox' || el.type === 'radio') {
            return el.checked !== orig;
        }
        return el.value !== orig;
    }

    /**
     * Apply or remove the dirty CSS classes on the field and its row ancestor.
     * For checkboxes the field itself can't easily show a border/background, so
     * the row accent is the primary indicator.
     */
    function refresh(el) {
        var dirty = isDirty(el);
        el.classList.toggle(DIRTY_CLASS, dirty);
        var row = el.closest ? el.closest('tr, .admin__field') : (function () {
            // IE fallback — walk up manually
            var node = el.parentNode;
            while (node && node.tagName !== 'TR' &&
                   !(node.classList && node.classList.contains('admin__field'))) {
                node = node.parentNode;
            }
            return node;
        }());
        if (row) {
            // A row may contain several fields; keep accent if ANY is dirty.
            if (dirty) {
                row.classList.add(ROW_DIRTY_CLASS);
            } else {
                // Re-check all sibling fields in this row before removing the class.
                var siblings = row.querySelectorAll(
                    'input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea'
                );
                var anyDirty = false;
                for (var i = 0; i < siblings.length; i++) {
                    if (isDirty(siblings[i])) { anyDirty = true; break; }
                }
                row.classList.toggle(ROW_DIRTY_CLASS, anyDirty);
            }
        }
    }

    var FIELD_SELECTOR =
        'input:not([type=hidden]):not([type=submit]):not([type=button]),' +
        ' select,' +
        ' textarea';

    function initForm(form) {
        // ── 1. Snapshot all existing controls ────────────────────────────────
        var fields = form.querySelectorAll(FIELD_SELECTOR);
        for (var i = 0; i < fields.length; i++) {
            snapshot(fields[i]);
        }

        // ── 2. Listen for user changes (bubbling — one listener on the form) ──
        form.addEventListener('input', function (e) {
            if (e.target && e.target.matches && e.target.matches(FIELD_SELECTOR)) {
                refresh(e.target);
            }
        });
        form.addEventListener('change', function (e) {
            if (e.target && e.target.matches && e.target.matches(FIELD_SELECTOR)) {
                refresh(e.target);
            }
        });

        // ── 3. Watch for dynamically added rows (role/group mapping rows) ─────
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(function (node) {
                        if (node.nodeType !== 1) { return; }
                        var newFields = node.querySelectorAll(FIELD_SELECTOR);
                        for (var i = 0; i < newFields.length; i++) {
                            snapshot(newFields[i]);
                        }
                        // Also snapshot the node itself if it matches.
                        if (node.matches && node.matches(FIELD_SELECTOR)) {
                            snapshot(node);
                        }
                    });
                });
            });

            // Target the containers used by attrsettings.phtml for dynamic rows.
            var containers = form.querySelectorAll(
                '#m2oidc-role-mapping-container, #m2oidc-cgm-mapping-container, #m2oidc-attr-mapping-container'
            );
            for (var c = 0; c < containers.length; c++) {
                observer.observe(containers[c], { childList: true, subtree: true });
            }
        }
    }

    // ── Bootstrap — domReady! dependency ensures DOM is fully loaded ──────────
    var form = document.getElementById('provider-edit-form') ||
               document.querySelector('form[action*="provider/save"]');
    if (form) {
        initForm(form);
    }
});
