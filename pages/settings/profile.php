<?php
// pages/settings/profile.php
$page_title = 'HỒ SƠ CÁ NHÂN';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SettingRepository;
use App\Services\SettingService;
use App\Services\CsrfService;

requireLogin();

$user_id    = (int) $_SESSION['user_id'];
$service    = new SettingService(new SettingRepository($pdo));
$csrf       = new CsrfService();

$user          = $service->findUser($user_id) ?: currentUser();
$csrfToken     = $csrf->getToken();
$flash_success = getFlash('success');
$flash_error   = getFlash('error');

$roleMap = ['admin' => 'Quản trị viên', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css">

<div class="settings-container">
    <?php include '_sidebar.php'; ?>

    <div class="settings-content">
        <div class="settings-header">
            <h1><i class="ri-user-line"></i> Hồ sơ cá nhân</h1>
            <p class="page-subtitle">Cập nhật thông tin và mật khẩu của bạn</p>
        </div>

        <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

            <!-- Thông tin cá nhân -->
            <div class="form-section-card">
                <h3 class="form-section-title"><i class="ri-id-card-line"></i> Thông tin cơ bản</h3>
                <form method="POST" action="process.php">
                    <input type="hidden" name="action"     value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="form-group">
                        <label>Tên đăng nhập</label>
                        <input type="text" value="@<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" readonly class="input-readonly">
                    </div>
                    <div class="form-group">
                        <label>Vai trò</label>
                        <input type="text" value="<?= $roleMap[$_SESSION['user_role'] ?? ''] ?? '' ?>" readonly class="input-readonly">
                    </div>
                    <div class="form-group">
                        <label>Họ và tên <span class="required">*</span></label>
                        <input type="text" name="full_name" required
                               value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email"
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary"><i class="ri-save-line"></i> Cập nhật</button>
                    </div>
                </form>
            </div>

            <!-- Đổi mật khẩu -->
            <div class="form-section-card">
                <h3 class="form-section-title"><i class="ri-lock-password-line"></i> Đổi mật khẩu</h3>
                <form method="POST" action="process.php">
                    <input type="hidden" name="action"     value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="form-group">
                        <label>Mật khẩu hiện tại <span class="required">*</span></label>
                        <input type="password" name="old_password" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label>Mật khẩu mới <span class="required">*</span></label>
                        <input type="password" name="new_password" id="newPw" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>Xác nhận mật khẩu mới <span class="required">*</span></label>
                        <input type="password" name="confirm_password" id="cfmPw" required minlength="6" autocomplete="new-password"
                               oninput="checkPwMatch()">
                        <small id="pwMatchHint" style="font-size:12px;margin-top:4px;display:block"></small>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary"><i class="ri-key-line"></i> Đổi mật khẩu</button>
                    </div>
                </form>
            </div>

        </div>

        <!-- Thông tin phiên -->
        <div class="form-section-card" style="margin-top:20px">
            <h3 class="form-section-title"><i class="ri-shield-check-line"></i> Thông tin phiên đăng nhập</h3>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;font-size:13px">
                <div>
                    <div style="color:#94a3b8;margin-bottom:4px">Đăng nhập lúc</div>
                    <div style="font-weight:500"><?= isset($_SESSION['logged_in_at']) ? date('d/m/Y H:i', $_SESSION['logged_in_at']) : '—' ?></div>
                </div>
                <div>
                    <div style="color:#94a3b8;margin-bottom:4px">Phiên hết hạn lúc</div>
                    <div style="font-weight:500"><?= isset($_SESSION['logged_in_at']) ? date('d/m/Y H:i', $_SESSION['logged_in_at'] + 28800) : '—' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function checkPwMatch() {
    const hint = document.getElementById('pwMatchHint');
    const match = document.getElementById('newPw').value === document.getElementById('cfmPw').value;
    hint.textContent = match ? '✓ Khớp' : '✗ Không khớp';
    hint.style.color = match ? '#16a34a' : '#dc2626';
}
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>