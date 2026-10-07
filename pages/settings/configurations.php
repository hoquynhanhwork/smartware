<?php
// pages/settings/configurations.php
$page_title = 'CÀI ĐẶT HỆ THỐNG';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SettingRepository;
use App\Services\SettingService;
use App\Services\CsrfService;

requireLogin();
requireRole('admin');

$service       = new SettingService(new SettingRepository($pdo));
$csrf          = new CsrfService();
$configs       = $service->getConfigs();
$flash_success = getFlash('success');
$flash_error   = getFlash('error');

$body_page = 'settings-page';
include __DIR__ . '/../../layout/header.php';
?>
<!-- Font Be Vietnam Pro & Style Chuẩn -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/suppliers.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/inbound.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css">

<div class="settings-container-modern">
    <!-- Thanh Tab ngang đồng bộ -->
    <?php include '_tabs.php'; ?>

    <div class="settings-tab-content">
        <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <form method="POST" action="process.php" class="settings-form">
            <input type="hidden" name="action"     value="update_configs">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf->getToken()) ?>">

            <!-- CẤU HÌNH CẢNH BÁO TỒN KHO -->
            <div class="settings-card-box">
                <div class="box-header">
                    <i class="ri-alarm-warning-line"></i>
                    <div>
                        <h4 class="box-title">Tham số cảnh báo kho hàng</h4>
                        <p class="box-desc">Thiết lập các ngưỡng kiểm soát hạn dùng và số lượng tự động</p>
                    </div>
                </div>

                <div class="box-body">
                    <div class="form-grid-2">
                        <div class="form-row-modern">
                            <label class="form-label-modern">Cảnh báo hết hạn trước (ngày)</label>
                            <input type="number" name="<?= SettingService::KEY_ALERT_EXPIRY_DAYS ?>" class="form-input-modern"
                                   min="1" max="90"
                                   value="<?= htmlspecialchars($configs[SettingService::KEY_ALERT_EXPIRY_DAYS] ?? '30') ?>">
                            <small class="form-hint-text" style="font-size:12px; color:var(--text-muted); margin-top:2px;">Gửi thông báo khi lô hàng còn ít hơn X ngày trước ngày hết hạn (Mặc định: 30 ngày)</small>
                        </div>

                        <div class="form-row-modern">
                            <label class="form-label-modern">Thời hạn thanh toán mặc định (ngày)</label>
                            <input type="number" name="<?= SettingService::KEY_DEFAULT_PAYMENT_DAYS ?>" class="form-input-modern"
                                   min="0" max="365"
                                   value="<?= htmlspecialchars($configs[SettingService::KEY_DEFAULT_PAYMENT_DAYS] ?? '30') ?>">
                            <small class="form-hint-text" style="font-size:12px; color:var(--text-muted); margin-top:2px;">Điều khoản công nợ thanh toán mặc định áp dụng với nhà cung cấp (Mặc định: 30 ngày)</small>
                        </div>
                    </div>

                    <div class="form-grid-2 mt-16" style="margin-top: 14px;">
                        <label class="toggle-card-row">
                            <div class="toggle-info">
                                <strong>Cảnh báo tồn kho dưới mức tối thiểu</strong>
                                <small>Tạo cảnh báo khi số lượng sản phẩm chạm ngưỡng Min Stock</small>
                            </div>
                            <input type="checkbox" name="<?= SettingService::KEY_ALERT_LOW_STOCK ?>" class="toggle-switch-cb"
                                   <?= ($configs[SettingService::KEY_ALERT_LOW_STOCK] ?? '1') === '1' ? 'checked' : '' ?>>
                        </label>

                        <label class="toggle-card-row">
                            <div class="toggle-info">
                                <strong>Cảnh báo tồn kho vượt mức tối đa</strong>
                                <small>Tạo cảnh báo dư ứ đọng khi số lượng sản phẩm vượt ngưỡng Max Stock</small>
                            </div>
                            <input type="checkbox" name="<?= SettingService::KEY_ALERT_OVERSTOCK ?>" class="toggle-switch-cb"
                                   <?= ($configs[SettingService::KEY_ALERT_OVERSTOCK] ?? '1') === '1' ? 'checked' : '' ?>>
                        </label>
                    </div>
                </div>
            </div>

            <!-- CẤU HÌNH MÃ CHỨNG TỪ TỰ ĐỘNG -->
            <div class="settings-card-box mt-30">
                <div class="box-header">
                    <i class="ri-barcode-line"></i>
                    <div>
                        <h4 class="box-title">Quy chuẩn định dạng mã phiếu chứng từ</h4>
                        <p class="box-desc">Tùy biến tiền tố mã phiếu nhập kho và xuất kho tự động sinh</p>
                    </div>
                </div>

                <div class="box-body">
                    <div class="form-grid-2">
                        <div class="form-row-modern">
                            <label class="form-label-modern">Tiền tố phiếu Nhập kho (Inbound)</label>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <input type="text" name="<?= SettingService::KEY_INBOUND_PREFIX ?>" class="form-input-modern"
                                       maxlength="5" style="width:120px; text-transform:uppercase; font-weight:700;"
                                       value="<?= htmlspecialchars($configs[SettingService::KEY_INBOUND_PREFIX] ?? 'PN') ?>">
                                <span class="prefix-preview-label">+ 00001 &nbsp;→&nbsp; <strong id="inPreview"><?= htmlspecialchars($configs[SettingService::KEY_INBOUND_PREFIX] ?? 'PN') ?>00001</strong></span>
                            </div>
                        </div>

                        <div class="form-row-modern">
                            <label class="form-label-modern">Tiền tố phiếu Xuất kho (Outbound)</label>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <input type="text" name="<?= SettingService::KEY_OUTBOUND_PREFIX ?>" class="form-input-modern"
                                       maxlength="5" style="width:120px; text-transform:uppercase; font-weight:700;"
                                       value="<?= htmlspecialchars($configs[SettingService::KEY_OUTBOUND_PREFIX] ?? 'PX') ?>">
                                <span class="prefix-preview-label">+ 00001 &nbsp;→&nbsp; <strong id="outPreview"><?= htmlspecialchars($configs[SettingService::KEY_OUTBOUND_PREFIX] ?? 'PX') ?>00001</strong></span>
                            </div>
                        </div>
                    </div>
                    <div class="notice-warning-strip mt-12" style="margin-top: 12px;">
                        <i class="ri-information-line"></i>
                        <span>Việc thay đổi tiền tố chỉ áp dụng cho các phiếu được tạo sau thời điểm này. Các mã phiếu đã phát hành trước đây vẫn được giữ nguyên.</span>
                    </div>
                </div>

                <div class="box-footer">
                    <button type="submit" class="btn-modern-dark">
                        <i class="ri-save-line"></i> Lưu cấu hình hệ thống
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/settings.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>