/* TP Planner - Global JS */
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alerts after 5s
    document.querySelectorAll('.alert-dismissible').forEach(function(alert) {
        setTimeout(function() {
            var b = new bootstrap.Alert(alert);
            b.close();
        }, 5000);
    });

    // Confirm delete
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(this.getAttribute('data-confirm') || 'Are you sure?')) e.preventDefault();
        });
    });

    // Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(el) { return new bootstrap.Tooltip(el); });

    // "OK" multi-line fields (textarea) with preview/edit
    document.querySelectorAll('[data-ok-textarea]').forEach(function(wrap) {
        var ta = wrap.querySelector('textarea');
        var okBtn = wrap.querySelector('[data-ok-btn]');
        var editBtn = wrap.querySelector('[data-edit-btn]');
        var preview = wrap.querySelector('[data-preview]');
        if (!ta || !okBtn || !editBtn || !preview) return;

        function renderPreview() {
            var text = (ta.value || '').trim();
            preview.innerHTML = text ? text.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;')
                .replace(/\n/g, '<br>') : '<span class="text-muted">—</span>';
        }

        okBtn.addEventListener('click', function() {
            var required = ta.hasAttribute('required') || ta.classList.contains('js-required');
            if (required && !(ta.value || '').trim()) {
                ta.classList.add('is-invalid');
                ta.focus();
                return;
            }
            ta.classList.remove('is-invalid');
            renderPreview();
            ta.classList.add('d-none');
            preview.classList.remove('d-none');
            okBtn.classList.add('d-none');
            editBtn.classList.remove('d-none');
        });

        editBtn.addEventListener('click', function() {
            ta.classList.remove('d-none');
            preview.classList.add('d-none');
            okBtn.classList.remove('d-none');
            editBtn.classList.add('d-none');
            ta.focus();
        });

        ta.addEventListener('input', function() {
            ta.classList.remove('is-invalid');
        });
    });
});
