let deleteUrl = '';
let deleteModalCallback = null;

// ============ Theme Toggle ============
var currentTheme = localStorage.getItem('theme') || 'dark';

function setIcon(theme) {
    var sun = document.getElementById('iconLight');
    var moon = document.getElementById('iconDark');
    var btn = document.getElementById('themeToggleBtn');
    if (!sun || !moon) return;

    if (theme === 'light') {
        sun.style.opacity = '1';
        sun.style.transform = 'rotate(0) scale(1)';
        moon.style.opacity = '0';
        moon.style.transform = 'rotate(90deg) scale(0.5)';
    } else {
        moon.style.opacity = '1';
        moon.style.transform = 'rotate(0) scale(1)';
        sun.style.opacity = '0';
        sun.style.transform = 'rotate(-90deg) scale(0.5)';
    }
    if (btn) btn.title = theme === 'light' ? 'Switch to Dark' : 'Switch to Light';
}

function applyTheme(theme) {
    if (theme === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

function toggleTheme() {
    currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('theme', currentTheme);
    applyTheme(currentTheme);
    setIcon(currentTheme);
}

// ============ Form Guard (Unsaved Changes) ============
function showUnsavedModal(callback) {
    const modal = document.getElementById('unsavedModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal._callback = callback || null;
}

function hideUnsavedModal() {
    const modal = document.getElementById('unsavedModal');
    if (modal) modal.classList.add('hidden');
}

function confirmLeavePage() {
    const modal = document.getElementById('unsavedModal');
    if (modal && modal._callback) {
        const cb = modal._callback;
        hideUnsavedModal();
        cb();
    }
}

function toggleDropdown(id) {
    const dropdown = document.getElementById(id);
    if (!dropdown) return;
    const isHidden = dropdown.classList.contains('hidden');
    document.querySelectorAll('[id$="Dropdown"]').forEach(el => {
        if (el.id !== id) el.classList.add('hidden');
    });
    dropdown.classList.toggle('hidden', !isHidden);
}

function openDeleteModal(id, name, url) {
    deleteUrl = url + '?confirm_delete=' + id;
    document.getElementById('deleteItemName').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    deleteUrl = '';
}

function confirmDelete() {
    if (deleteUrl) {
        window.location.href = deleteUrl;
    }
}

function closeToast(btn) {
    const toast = btn ? btn.closest('#toast, .toast') : document.getElementById('toast');
    if (toast) {
        toast.classList.add('toast-exit-active');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }
}

function showToast(type, message) {
    const iconMap = {
        success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />',
        error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />',
        warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />',
        info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />'
    };
    const colorMap = {
        success: 'bg-green-100 text-green-600',
        error: 'bg-red-100 text-red-600',
        warning: 'bg-amber-100 text-amber-600',
        info: 'bg-blue-100 text-blue-600'
    };
    const titleMap = {
        success: 'Success!',
        error: 'Error!',
        warning: 'Warning!',
        info: 'Information'
    };

    const toast = document.createElement('div');
    toast.className = 'toast fixed top-4 right-4 z-[100] flex items-start gap-3 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 px-4 py-3 min-w-[320px] max-w-md';
    toast.innerHTML = `
        <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center mt-0.5 ${colorMap[type]}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                ${iconMap[type]}
            </svg>
        </div>
        <div class="flex-1">
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">${titleMap[type]}</p>
            <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">${message}</p>
        </div>
        <button onclick="closeToast(this)" class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
        if (toast.parentNode) closeToast(toast.querySelector('button'));
    }, 4000);
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}

function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.classList.add('sidebar-transition');
        sidebar.classList.toggle('-translate-x-full');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const toast = document.getElementById('toast');
    if (toast) {
        setTimeout(() => {
            const btn = toast.querySelector('button');
            if (btn) closeToast(btn);
        }, 4000);
    }

    document.addEventListener('click', function (e) {
        document.querySelectorAll('[id$="Dropdown"]').forEach(el => {
            if (!el.classList.contains('hidden')) {
                const trigger = e.target.closest('button');
                const isTrigger = trigger && trigger.getAttribute('onclick') && trigger.getAttribute('onclick').includes(el.id);
                if (!isTrigger && !el.contains(e.target)) {
                    el.classList.add('hidden');
                }
            }
        });

        const overlay = e.target.closest('.modal-overlay');
        if (overlay && e.target === overlay) {
            const modal = overlay.querySelector('[id$="Modal"]');
            if (modal) modal.classList.add('hidden');
            overlay.classList.add('hidden');
        }
    });

    // Close delete modal on ESC key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal && !deleteModal.classList.contains('hidden')) {
                closeDeleteModal();
            }
        }
    });
});
