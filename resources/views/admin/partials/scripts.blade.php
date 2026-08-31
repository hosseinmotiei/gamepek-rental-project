<script>

// ── Admin toast + confirm ─────────────────────────────────────────────────
// The Store admin had no notification system at all and used native
// adminToast()/confirm(). These mirror the public site's showToast() so admin
// feedback looks like the rest of GamePek and stays RTL-correct.
function adminToast(message, type = 'success') {
    const container = document.getElementById('admin-toast-container');
    if (!container) { return; }
    const icons = {
        success: '<i class="fa-solid fa-circle-check text-green-400"></i>',
        error:   '<i class="fa-solid fa-circle-exclamation text-red-400"></i>',
        info:    '<i class="fa-solid fa-circle-info text-blue-400"></i>'
    };
    const toast = document.createElement('div');
    toast.className = 'flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg bg-gray-800 text-white text-xs md:text-sm transform transition-all duration-300 -translate-y-4 opacity-0';
    toast.innerHTML = `${icons[type] || icons.info} <span></span>`;
    toast.querySelector('span').textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.classList.remove('-translate-y-4', 'opacity-0'), 10);
    setTimeout(() => {
        toast.classList.add('-translate-y-4', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Promise-based replacement for confirm(). Resolves true/false.
function adminConfirm(message, title = 'تأیید عملیات') {
    return new Promise((resolve) => {
        const modal  = document.getElementById('admin-confirm-modal');
        const accept = document.getElementById('admin-confirm-accept');
        const cancel = document.getElementById('admin-confirm-cancel');
        if (!modal || !accept || !cancel) { resolve(window.confirm(message)); return; }

        document.getElementById('admin-confirm-title').textContent = title;
        document.getElementById('admin-confirm-message').textContent = message;
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        function close(result) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            accept.removeEventListener('click', onAccept);
            cancel.removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdrop);
            resolve(result);
        }
        function onAccept() { close(true); }
        function onCancel() { close(false); }
        function onBackdrop(e) { if (e.target === modal) { close(false); } }

        accept.addEventListener('click', onAccept);
        cancel.addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdrop);
    });
}

// Any form carrying data-confirm routes through adminConfirm() instead of
// the browser dialog -- no per-view wiring needed.
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) { return; }
    const message = form.getAttribute('data-confirm');
    if (!message || form.dataset.confirmed === '1') { return; }
    e.preventDefault();
    adminConfirm(message).then(function (ok) {
        if (ok) { form.dataset.confirmed = '1'; form.submit(); }
    });
}, true);

function toggleSidebar() {
    const s = document.getElementById('sidebar'), o = document.getElementById('sidebar-overlay');
    s.classList.toggle('translate-x-full');
    o.classList.toggle('hidden');
    document.body.style.overflow = s.classList.contains('translate-x-full') ? '' : 'hidden';
}
function closeSidebar() {
    const s = document.getElementById('sidebar'), o = document.getElementById('sidebar-overlay');
    s.classList.add('translate-x-full');
    o.classList.add('hidden');
    document.body.style.overflow = '';
}

// ── Shared instant client-side image preview on file selection ────────────
// Shows the picked file immediately (before upload even starts) via
// createObjectURL -- no server round-trip needed for the preview itself.
function adminPreviewImage(input, previewImgId) {
    const img = document.getElementById(previewImgId);
    if (!img || !input.files || !input.files[0]) return;
    img.src = URL.createObjectURL(input.files[0]);
    img.classList.remove('hidden');
}
// ── Shared admin form submission with real upload progress ────────────────
// Used by any form with onsubmit="return submitFormWithProgress(this, {...})".
// Reports ACTUAL upload progress via XMLHttpRequest's upload.progress event
// (not a fake timer). Prevents duplicate submits, shows Laravel's real
// validation errors (422 JSON -- Laravel returns this automatically for any
// validate()/FormRequest call when the request sends Accept: application/json,
// no controller changes needed), and follows the normal redirect-on-success
// flow so existing flash messages keep working exactly as before.
function submitFormWithProgress(form, opts) {
    opts = opts || {};
    if (form.dataset.submitting === '1') return false;
    form.dataset.submitting = '1';

    const submitBtn = opts.submitBtn || form.querySelector('button[type="submit"]');
    const progressWrap = opts.progressWrap ? document.getElementById(opts.progressWrap) : null;
    const progressBar = opts.progressBar ? document.getElementById(opts.progressBar) : null;
    const progressText = opts.progressText ? document.getElementById(opts.progressText) : null;
    const submitBtnOriginalHtml = submitBtn ? submitBtn.innerHTML : null;

    const resetUi = function () {
        form.dataset.submitting = '';
        if (submitBtn) { submitBtn.disabled = false; if (submitBtnOriginalHtml !== null) submitBtn.innerHTML = submitBtnOriginalHtml; }
        if (progressWrap) progressWrap.classList.add('hidden');
    };

    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> در حال آپلود...'; }
    if (progressWrap) progressWrap.classList.remove('hidden');
    if (progressBar) progressBar.style.width = '0%';
    if (progressText) progressText.textContent = '0%';

    const xhr = new XMLHttpRequest();
    xhr.open(form.method || 'POST', form.action, true);
    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.addEventListener('progress', function (e) {
        if (!e.lengthComputable) return;
        const pct = Math.round((e.loaded / e.total) * 100);
        if (progressBar) progressBar.style.width = pct + '%';
        if (progressText) progressText.textContent = pct + '%';
    });

    xhr.addEventListener('load', function () {
        if (xhr.status >= 200 && xhr.status < 300) {
            if (progressBar) progressBar.style.width = '100%';
            if (progressText) progressText.textContent = '100%';
            window.location.href = xhr.responseURL || form.action;
            return;
        }

        resetUi();

        if (xhr.status === 422) {
            let errors = {};
            try { errors = (JSON.parse(xhr.responseText).errors) || {}; } catch (e) { /* ignore */ }
            adminShowFormErrors(form, errors);
        } else {
            // Show the server's real message when it sent one (e.g. file
            // too large, storage write failed) instead of a generic string
            // that hides what actually went wrong.
            let message = 'خطایی رخ داد. لطفاً دوباره تلاش کنید.';
            try {
                const parsed = JSON.parse(xhr.responseText);
                if (parsed && parsed.message) message = parsed.message;
            } catch (e) {
                // Not JSON (e.g. the raw debug-token exception dump) -- show
                // the raw text instead of hiding it behind the generic message.
                if (xhr.responseText && xhr.responseText.trim()) message = xhr.responseText;
            }
            adminToast(message);
        }
    });

    xhr.addEventListener('error', function () {
        resetUi();
        adminToast('خطا در ارتباط با سرور. اتصال اینترنت خود را بررسی کنید.');
    });

    xhr.send(new FormData(form));
    return false;
}

function adminShowFormErrors(form, errors) {
    form.querySelectorAll('.js-field-error').forEach(function (el) { el.remove(); });
    form.querySelectorAll('.js-field-error-border').forEach(function (el) { el.classList.remove('border-red-400', 'js-field-error-border'); });

    const messages = [];
    Object.keys(errors).forEach(function (field) {
        const message = Array.isArray(errors[field]) ? errors[field][0] : String(errors[field]);
        messages.push(message);
        const input = form.querySelector('[name="' + field + '"]');
        if (input) {
            input.classList.add('border-red-400', 'js-field-error-border');
            const p = document.createElement('p');
            p.className = 'text-red-500 text-xs mt-1 js-field-error';
            p.textContent = message;
            input.insertAdjacentElement('afterend', p);
        }
    });
    if (messages.length) adminToast(messages.join('\n'));
}
</script>
