// js/settings.js
'use strict';

function getSettingsCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content 
        || document.querySelector('input[name="csrf_token"]')?.value 
        || '';
}

// ── 1. LỌC VÀ TÌM KIẾM THÀNH VIÊN TRÊN BẢNG (SEARCH & FILTER) ─────────────
function filterMemberCards() {
    const searchVal = document.getElementById('settingsSearchInput')?.value.toLowerCase().trim() || '';
    const rows = document.querySelectorAll('#memberListStack .member-table-row');

    rows.forEach(row => {
        const name = row.dataset.name || '';
        const matchSearch = name.includes(searchVal);
        row.style.display = matchSearch ? '' : 'none';
    });
}

function filterMemberStatus(status) {
    const rows = document.querySelectorAll('#memberListStack .member-table-row');
    const txt = document.getElementById('currentStatusText');
    if (txt) {
        txt.textContent = status === 'active' ? 'Hoạt động' : (status === 'inactive' ? 'Bị khóa' : 'Tất cả');
    }

    rows.forEach(row => {
        const rowStatus = row.dataset.status;
        if (status === 'all' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    document.querySelectorAll('#statusFilterMenu a').forEach(a => a.classList.remove('active'));
    document.getElementById('statusFilterMenu')?.classList.remove('show');
}

let _sortAsc = true;
function toggleSortMembers() {
    const stack = document.getElementById('memberListStack');
    if (!stack) return;
    const rows = Array.from(stack.querySelectorAll('.member-table-row'));

    rows.sort((a, b) => {
        const nameA = a.dataset.name || '';
        const nameB = b.dataset.name || '';
        return _sortAsc ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
    });

    _sortAsc = !_sortAsc;
    rows.forEach(r => stack.appendChild(r));
}

// ── 2. MODAL THÊM / SỬA USER (XỬ LÝ ROLE RADIO CARDS) ─────────────────────
function openUserModal(mode, user = null) {
    const modal = document.getElementById('userModal');
    if (!modal) return;

    document.getElementById('userModalTitle').textContent = mode === 'add' ? 'Thêm nhân viên mới' : 'Sửa thông tin nhân viên';
    document.getElementById('userAction').value           = mode === 'add' ? 'add_user' : 'edit_user';
    document.getElementById('userId').value               = user?.id ?? 0;
    document.getElementById('uUsername').value            = user?.username ?? '';
    document.getElementById('uFullName').value            = user?.full_name ?? '';
    document.getElementById('uEmail').value               = user?.email ?? '';
    document.getElementById('uPassword').value            = '';

    // Tích chọn radio tương ứng với vai trò của user
    const selectedRole = user?.role ?? 'staff';
    const targetRadio = document.querySelector(`input[name="role"][value="${selectedRole}"]`);
    if (targetRadio) {
        targetRadio.checked = true;
    }

    document.getElementById('usernameGroup').style.display = mode === 'add' ? '' : 'none';
    document.getElementById('passwordGroup').style.display = mode === 'add' ? '' : 'none';

    modal.style.display = 'flex';
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (modal) modal.style.display = 'none';
}

async function submitUser(e) {
    e.preventDefault();
    const form = document.getElementById('userForm');
    const fd   = new FormData(form);
    fd.append('csrf_token', getSettingsCsrfToken());

    try {
        const resp = await fetch('process.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            alert(data.message || 'Lưu nhân viên thành công!');
            location.reload();
        } else {
            alert('Lỗi: ' + (data.message || 'Không thể lưu'));
        }
    } catch (err) {
        alert('Lỗi kết nối máy chủ: ' + err.message);
    }
}

// ── 3. MODAL RESET MẬT KHẨU USER ──────────────────────────────────────────
function openResetModal(id, username) {
    document.getElementById('resetUserId').value          = id;
    document.getElementById('resetUserLabel').textContent = `Tài khoản: @${username}`;
    document.getElementById('newPassword').value          = '';
    document.getElementById('resetModal').style.display   = 'flex';
}

function closeResetModal() {
    const modal = document.getElementById('resetModal');
    if (modal) modal.style.display = 'none';
}

async function submitReset(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('resetForm'));
    fd.append('csrf_token', getSettingsCsrfToken());

    try {
        const resp = await fetch('process.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            alert(data.message || 'Đặt lại mật khẩu thành công!');
            closeResetModal();
        } else {
            alert('Lỗi: ' + data.message);
        }
    } catch (err) {
        alert('Lỗi kết nối: ' + err.message);
    }
}

// ── 4. KHÓA / MỞ KHÓA TÀI KHOẢN ──────────────────────────────────────────
async function toggleStatus(id, username, currentStatus) {
    const action = currentStatus === 'active' ? 'khóa' : 'mở khóa';
    if (!confirm(`Bạn có chắc muốn ${action} tài khoản @${username}?`)) return;

    const fd = new FormData();
    fd.append('action', 'toggle_user_status');
    fd.append('id', id);
    fd.append('csrf_token', getSettingsCsrfToken());

    try {
        const resp = await fetch('process.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            location.reload();
        } else {
            alert('Lỗi: ' + data.message);
        }
    } catch (err) {
        alert('Lỗi kết nối: ' + err.message);
    }
}

// ── 5. XỬ LÝ CẢNH BÁO (ALERTS TAB) ───────────────────────────────────────
async function markAlertRead(id) {
    const fd = new FormData();
    fd.append('action', 'mark_alert_read');
    fd.append('id', id);
    fd.append('csrf_token', getSettingsCsrfToken());

    try {
        const resp = await fetch('process.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            const el = document.getElementById('alert-' + id);
            if (el) {
                el.classList.add('alert-read-muted');
                el.querySelector('.unread-dot')?.remove();
                el.querySelector('[onclick*="markAlertRead"]')?.remove();
            }
        }
    } catch (err) {
        console.error(err);
    }
}

async function resolveAlert(id) {
    if (!confirm('Xác nhận đã xử lý xong cảnh báo này?')) return;
    const fd = new FormData();
    fd.append('action', 'resolve_alert');
    fd.append('id', id);
    fd.append('csrf_token', getSettingsCsrfToken());

    try {
        const resp = await fetch('process.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) location.reload();
    } catch (err) {
        alert('Lỗi: ' + err.message);
    }
}

// ── 6. KHỞI TẠO DOM & SỰ KIỆN TỔNG QUAN ──────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // 1. Tìm kiếm tức thì trong Team members
    const searchInput = document.getElementById('settingsSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', filterMemberCards);
    }

    // 2. Phím tắt ⌘ F hoặc Ctrl + F
    window.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'f') {
            e.preventDefault();
            searchInput?.focus();
        }
    });

    // 3. Dropdown Menu Status Filter
    const btnStatus = document.getElementById('btnStatusFilter');
    const menuStatus = document.getElementById('statusFilterMenu');
    if (btnStatus && menuStatus) {
        btnStatus.addEventListener('click', function (e) {
            e.stopPropagation();
            menuStatus.classList.toggle('show');
        });
    }

    // 4. Kiểm tra khớp mật khẩu ở tab Profile
    const newPw = document.getElementById('newPw');
    const cfmPw = document.getElementById('cfmPw');
    if (newPw && cfmPw) {
        const checkMatch = () => {
            const hint = document.getElementById('pwMatchHint');
            if (!hint) return;
            if (!cfmPw.value) { hint.textContent = ''; return; }
            const match = newPw.value === cfmPw.value;
            hint.textContent = match ? '✓ Mật khẩu mới trùng khớp' : '✗ Mật khẩu xác nhận không khớp';
            hint.style.color = match ? '#2F7F6F' : '#C24B33';
        };
        newPw.addEventListener('input', checkMatch);
        cfmPw.addEventListener('input', checkMatch);
    }

    // 5. Preview tiền tố chứng từ ở tab Configurations
    const inPrefixInput = document.querySelector('input[name="inbound_prefix"]');
    const outPrefixInput = document.querySelector('input[name="outbound_prefix"]');
    if (inPrefixInput) {
        inPrefixInput.addEventListener('input', function () {
            this.value = this.value.toUpperCase();
            const preview = document.getElementById('inPreview');
            if (preview) preview.textContent = (this.value || 'PN') + '00001';
        });
    }
    if (outPrefixInput) {
        outPrefixInput.addEventListener('input', function () {
            this.value = this.value.toUpperCase();
            const preview = document.getElementById('outPreview');
            if (preview) preview.textContent = (this.value || 'PX') + '00001';
        });
    }

    // 6. Click đóng popup ngoài vùng
    window.addEventListener('click', function (e) {
        if (!e.target.closest('.st-dropdown-tool')) {
            menuStatus?.classList.remove('show');
        }
        if (e.target === document.getElementById('userModal'))  closeUserModal();
        if (e.target === document.getElementById('resetModal')) closeResetModal();
    });
});

// Gán toàn cục vào window
window.openUserModal        = openUserModal;
window.closeUserModal       = closeUserModal;
window.submitUser           = submitUser;
window.openResetModal       = openResetModal;
window.closeResetModal      = closeResetModal;
window.submitReset          = submitReset;
window.toggleStatus         = toggleStatus;
window.filterMemberStatus   = filterMemberStatus;
window.toggleSortMembers    = toggleSortMembers;
window.markAlertRead        = markAlertRead;
window.resolveAlert         = resolveAlert;