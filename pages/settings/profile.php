<?php
// pages/settings/profile.php
$page_title = 'CÀI ĐẶT HỆ THỐNG';

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

$roleMap = ['admin' => 'Quản trị viên', 'manager' => 'Quản lý kho', 'staff' => 'Nhân viên kho'];

$body_page = 'settings-page';
include __DIR__ . '/../../layout/header.php';
?>
<!-- Font Be Vietnam Pro & Style Chuẩn -->
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<div class="settings-container-modern">
    <!-- Thanh Tab ngang đồng bộ -->
    <?php include '_tabs.php'; ?>

    <div class="settings-tab-content">
        <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <!-- SECTION: THÔNG TIN TỔNG QUAN USER -->
        <div class="settings-profile-hero">
            <div class="profile-hero-avatar">
                <?= strtoupper(mb_substr($user['full_name'] ?? $_SESSION['user_name'], 0, 1, 'UTF-8')) ?>
            </div>
            <div class="profile-hero-info">
                <h3><?= htmlspecialchars($user['full_name'] ?? $_SESSION['user_name']) ?></h3>
                <p><?= htmlspecialchars($user['email'] ?? 'Chưa cập nhật email') ?> · <span class="clean-badge st-completed"><?= $roleMap[$_SESSION['user_role'] ?? ''] ?? '' ?></span></p>
            </div>
        </div>

        <div class="settings-form-grid-split mt-30">
            <!-- Form: Cập nhật thông tin cơ bản -->
            <div class="settings-card-box">
                <div class="box-header">
                    <i class="ri-user-settings-line"></i>
                    <div>
                        <h4 class="box-title">Thông tin tài khoản</h4>
                        <p class="box-desc">Cập nhật họ tên hiển thị và địa chỉ liên lạc của bạn</p>
                    </div>
                </div>

                <form method="POST" action="process.php" class="box-body">
                    <input type="hidden" name="action"     value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-row-modern">
                        <label class="form-label-modern">Tên đăng nhập hệ thống</label>
                        <input type="text" value="@<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" readonly class="form-input-modern input-readonly">
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Vai trò đảm nhiệm</label>
                        <input type="text" value="<?= $roleMap[$_SESSION['user_role'] ?? ''] ?? '' ?>" readonly class="form-input-modern input-readonly">
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Họ và tên hiển thị <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" required class="form-input-modern"
                               value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Địa chỉ Email</label>
                        <input type="email" name="email" class="form-input-modern"
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="example@smartware.vn">
                    </div>

                    <div class="box-footer">
                        <button type="submit" class="btn-modern-dark">
                            <i class="ri-save-line"></i> Lưu thông tin
                        </button>
                    </div>
                </form>
            </div>

            <!-- Form: Đổi mật khẩu bảo mật -->
            <div class="settings-card-box">
                <div class="box-header">
                    <i class="ri-lock-password-line"></i>
                    <div>
                        <h4 class="box-title">Đổi mật khẩu bảo mật</h4>
                        <p class="box-desc">Khuyến nghị sử dụng mật khẩu mạnh tối thiểu 6 ký tự</p>
                    </div>
                </div>

                <form method="POST" action="process.php" class="box-body">
                    <input type="hidden" name="action"     value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-row-modern">
                        <label class="form-label-modern">Mật khẩu hiện tại <span class="text-danger">*</span></label>
                        <input type="password" name="old_password" required class="form-input-modern" autocomplete="current-password">
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Mật khẩu mới <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" id="newPw" required minlength="6" class="form-input-modern" autocomplete="new-password">
                    </div>

                    <div class="form-row-modern">
                        <label class="form-label-modern">Xác nhận mật khẩu mới <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" id="cfmPw" required minlength="6" class="form-input-modern" autocomplete="new-password">
                        <small id="pwMatchHint" style="font-size: 12px; margin-top: 4px; display: block; font-weight: 500;"></small>
                    </div>

                    <div class="box-footer">
                        <button type="submit" class="btn-modern-dark">
                            <i class="ri-key-line"></i> Đổi mật khẩu
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- THÔNG TIN PHIÊN ĐĂNG NHẬP -->
        <div class="settings-card-box mt-30">
            <div class="box-header">
                <i class="ri-shield-keyhole-line"></i>
                <div>
                    <h4 class="box-title">Phiên đăng nhập & Bảo mật phiên</h4>
                    <p class="box-desc">Thời hạn tự động đăng xuất để bảo vệ dữ liệu kho</p>
                </div>
            </div>
            <div class="box-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="session-info-cell">
                    <span class="session-lbl">Thời điểm đăng nhập:</span>
                    <strong class="session-val"><?= isset($_SESSION['logged_in_at']) ? date('d/m/Y H:i:s', $_SESSION['logged_in_at']) : '—' ?></strong>
                </div>
                <div class="session-info-cell">
                    <span class="session-lbl">Thời hạn phiên làm việc (8 tiếng):</span>
                    <strong class="session-val"><?= isset($_SESSION['logged_in_at']) ? date('d/m/Y H:i:s', $_SESSION['logged_in_at'] + 28800) : '—' ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/settings.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>