<?php
// pages/settings/users.php
$page_title = 'QUẢN LÝ NGƯỜI DÙNG';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SettingRepository;
use App\Services\SettingService;
use App\Services\CsrfService;

requireLogin();
requireRole('admin');

$current_uid = (int) $_SESSION['user_id'];
$service     = new SettingService(new SettingRepository($pdo));
$csrf        = new CsrfService();

$users         = $service->listUsers();
$csrfToken     = $csrf->getToken();
$flash_success = getFlash('success');
$flash_error   = getFlash('error');

$roleMap = ['admin' => 'Quản trị viên', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'];
$roleColor = ['admin' => '#7c3aed', 'manager' => '#0369a1', 'staff' => '#374151'];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<div class="settings-container">
    <?php include '_sidebar.php'; ?>

    <div class="settings-content">
        <div class="settings-header" style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
                <h1><i class="ri-team-line"></i> Quản lý người dùng</h1>
                <p class="page-subtitle"><?= count($users) ?> tài khoản trong hệ thống</p>
            </div>
            <button class="btn-primary" onclick="openUserModal('add')">
                <i class="ri-user-add-line"></i> Thêm người dùng
            </button>
        </div>

        <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <div class="table-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Người dùng</th>
                        <th>Email</th>
                        <th>Vai trò</th>
                        <th>Lần đăng nhập cuối</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u):
                    $isSelf = ($u['id'] === $current_uid);
                ?>
                <tr>
                    <td>
                        <div style="font-weight:500"><?= htmlspecialchars($u['full_name'] ?? $u['username']) ?></div>
                        <div style="font-size:12px;color:#94a3b8">@<?= htmlspecialchars($u['username']) ?></div>
                    </td>
                    <td class="text-muted"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                    <td>
                        <span style="font-size:12px;font-weight:500;padding:2px 10px;border-radius:999px;
                            background:<?= $roleColor[$u['role']] ?? '#374151' ?>18;
                            color:<?= $roleColor[$u['role']] ?? '#374151' ?>">
                            <?= $roleMap[$u['role']] ?? $u['role'] ?>
                        </span>
                    </td>
                    <td class="text-muted" style="font-size:13px">
                        <?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'Chưa đăng nhập' ?>
                    </td>
                    <td>
                        <span style="font-size:12px;padding:2px 8px;border-radius:999px;font-weight:500;
                            background:<?= $u['status']==='active' ? '#f0fdf4' : '#fef2f2' ?>;
                            color:<?= $u['status']==='active' ? '#16a34a' : '#dc2626' ?>">
                            <?= $u['status']==='active' ? 'Hoạt động' : 'Bị khóa' ?>
                        </span>
                        <?php if ($isSelf): ?>
                            <span style="font-size:11px;color:#94a3b8;margin-left:4px">(bạn)</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right actions-cell">
                        <button class="btn-icon-subtle" title="Sửa"
                                onclick="openUserModal('edit', <?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                            <i class="ri-edit-line"></i>
                        </button>
                        <button class="btn-icon-subtle" title="Đặt lại mật khẩu"
                                onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                            <i class="ri-key-line"></i>
                        </button>
                        <?php if (!$isSelf): ?>
                        <button class="btn-icon-subtle <?= $u['status']==='active' ? 'text-danger' : 'text-success' ?>"
                                title="<?= $u['status']==='active' ? 'Khóa' : 'Mở khóa' ?>"
                                onclick="toggleStatus(<?= $u['id'] ?>, '<?= $u['username'] ?>', '<?= $u['status'] ?>')">
                            <i class="ri-<?= $u['status']==='active' ? 'lock' : 'lock-unlock' ?>-line"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal thêm/sửa user -->
<div id="userModal" class="modal" style="display:none">
    <div class="modal-content" style="max-width:520px">
        <div class="modal-header">
            <h3 id="userModalTitle">Thêm người dùng</h3>
            <span class="close" onclick="closeUserModal()">&times;</span>
        </div>
        <form id="userForm" onsubmit="submitUser(event)">
            <input type="hidden" name="action" id="userAction" value="add_user">
            <input type="hidden" name="id"     id="userId"     value="0">
            <div style="padding:20px 24px">
                <div class="form-grid-2">
                    <div class="form-group" id="usernameGroup">
                        <label>Tên đăng nhập <span class="required">*</span></label>
                        <input type="text" name="username" id="uUsername" autocomplete="off">
                    </div>
                    <div class="form-group" id="passwordGroup">
                        <label>Mật khẩu <span class="required">*</span></label>
                        <input type="password" name="password" id="uPassword" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>Họ và tên <span class="required">*</span></label>
                        <input type="text" name="full_name" id="uFullName">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="uEmail">
                    </div>
                    <div class="form-group full-width">
                        <label>Vai trò <span class="required">*</span></label>
                        <select name="role" id="uRole">
                            <option value="staff">Nhân viên</option>
                            <option value="manager">Quản lý</option>
                            <option value="admin">Quản trị viên</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Lưu</button>
                    <button type="button" class="btn-secondary" onclick="closeUserModal()">Hủy</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal reset password -->
<div id="resetModal" class="modal" style="display:none">
    <div class="modal-content" style="max-width:400px">
        <div class="modal-header">
            <h3>Đặt lại mật khẩu</h3>
            <span class="close" onclick="closeResetModal()">&times;</span>
        </div>
        <form id="resetForm" onsubmit="submitReset(event)">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id"     id="resetUserId">
            <div style="padding:20px 24px">
                <p id="resetUserLabel" style="margin-bottom:16px;color:#64748b;font-size:13px"></p>
                <div class="form-group">
                    <label>Mật khẩu mới <span class="required">*</span></label>
                    <input type="password" name="new_password" id="newPassword" minlength="6" autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Xác nhận</button>
                    <button type="button" class="btn-secondary" onclick="closeResetModal()">Hủy</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function openUserModal(mode, user = null) {
    document.getElementById('userModalTitle').textContent = mode === 'add' ? 'Thêm người dùng' : 'Sửa người dùng';
    document.getElementById('userAction').value  = mode === 'add' ? 'add_user' : 'edit_user';
    document.getElementById('userId').value      = user?.id ?? 0;
    document.getElementById('uUsername').value   = user?.username  ?? '';
    document.getElementById('uFullName').value   = user?.full_name ?? '';
    document.getElementById('uEmail').value      = user?.email     ?? '';
    document.getElementById('uRole').value       = user?.role      ?? 'staff';
    document.getElementById('uPassword').value   = '';

    // Ẩn username + password khi edit
    document.getElementById('usernameGroup').style.display = mode === 'add' ? '' : 'none';
    document.getElementById('passwordGroup').style.display = mode === 'add' ? '' : 'none';

    document.getElementById('userModal').style.display = 'flex';
}
function closeUserModal() { document.getElementById('userModal').style.display = 'none'; }

async function submitUser(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('userForm'));
    fd.append('csrf_token', CSRF);
    const resp = await fetch('process.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) { alert(data.message); location.reload(); }
    else alert('Lỗi: ' + data.message);
}

function openResetModal(id, username) {
    document.getElementById('resetUserId').value      = id;
    document.getElementById('resetUserLabel').textContent = `Đặt lại mật khẩu cho tài khoản: @${username}`;
    document.getElementById('newPassword').value      = '';
    document.getElementById('resetModal').style.display = 'flex';
}
function closeResetModal() { document.getElementById('resetModal').style.display = 'none'; }

async function submitReset(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('resetForm'));
    fd.append('csrf_token', CSRF);
    const resp = await fetch('process.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) { alert(data.message); closeResetModal(); }
    else alert('Lỗi: ' + data.message);
}

async function toggleStatus(id, username, currentStatus) {
    const action = currentStatus === 'active' ? 'khóa' : 'mở khóa';
    if (!confirm(`Xác nhận ${action} tài khoản @${username}?`)) return;
    const fd = new FormData();
    fd.append('action', 'toggle_user_status');
    fd.append('id', id);
    fd.append('csrf_token', CSRF);
    const resp = await fetch('process.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) { alert(data.message); location.reload(); }
    else alert('Lỗi: ' + data.message);
}
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>