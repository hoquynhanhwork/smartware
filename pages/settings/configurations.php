<?php
// pages/settings/configurations.php
$page_title = 'CẤU HÌNH HỆ THỐNG';

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

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css">

<div class="settings-container">
    <?php include '_sidebar.php'; ?>

    <div class="settings-content">
        <div class="settings-header">
            <h1><i class="ri-settings-3-line"></i> Cấu hình hệ thống</h1>
            <p class="page-subtitle">Tùy chỉnh các thông số vận hành cho công ty</p>
        </div>

        <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error):   ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

        <form method="POST" action="process.php" class="settings-form">
            <input type="hidden" name="action"     value="update_configs">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf->getToken()) ?>">

            <!-- Cảnh báo -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="ri-alarm-warning-line"></i> Cài đặt cảnh báo</h3>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Cảnh báo hết hạn trước (ngày)</label>
                        <input type="number" name="<?= SettingService::KEY_ALERT_EXPIRY_DAYS ?>"
                               min="1" max="90"
                               value="<?= htmlspecialchars($configs[SettingService::KEY_ALERT_EXPIRY_DAYS] ?? '30') ?>">
                        <small style="color:#94a3b8;font-size:12px">Hệ thống cảnh báo khi lô hàng còn ít hơn X ngày. Mặc định: 30</small>
                    </div>
                    <div class="form-group">
                        <label>Số ngày thanh toán mặc định</label>
                        <input type="number" name="<?= SettingService::KEY_DEFAULT_PAYMENT_DAYS ?>"
                               min="0" max="365"
                               value="<?= htmlspecialchars($configs[SettingService::KEY_DEFAULT_PAYMENT_DAYS] ?? '30') ?>">
                        <small style="color:#94a3b8;font-size:12px">Payment terms mặc định với nhà cung cấp. Mặc định: 30</small>
                    </div>
                </div>
                <div class="form-grid-2" style="margin-top:12px">
                    <label class="toggle-row">
                        <span class="toggle-label">
                            <strong>Cảnh báo tồn kho thấp</strong>
                            <small>Tạo alert khi sản phẩm đạt ngưỡng min_stock</small>
                        </span>
                        <input type="checkbox" name="<?= SettingService::KEY_ALERT_LOW_STOCK ?>"
                               class="toggle-cb"
                               <?= ($configs[SettingService::KEY_ALERT_LOW_STOCK] ?? '1') === '1' ? 'checked' : '' ?>>
                    </label>
                    <label class="toggle-row">
                        <span class="toggle-label">
                            <strong>Cảnh báo tồn kho cao</strong>
                            <small>Tạo alert khi sản phẩm vượt ngưỡng max_stock</small>
                        </span>
                        <input type="checkbox" name="<?= SettingService::KEY_ALERT_OVERSTOCK ?>"
                               class="toggle-cb"
                               <?= ($configs[SettingService::KEY_ALERT_OVERSTOCK] ?? '1') === '1' ? 'checked' : '' ?>>
                    </label>
                </div>
            </div>

            <!-- Mã phiếu -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="ri-barcode-line"></i> Định dạng mã phiếu</h3>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Prefix phiếu nhập</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="text" name="<?= SettingService::KEY_INBOUND_PREFIX ?>"
                                   maxlength="5" style="width:100px;text-transform:uppercase"
                                   value="<?= htmlspecialchars($configs[SettingService::KEY_INBOUND_PREFIX] ?? 'PN') ?>">
                            <span style="color:#94a3b8;font-size:13px">+ 00001 → <strong id="inPreview">PN00001</strong></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Prefix phiếu xuất</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="text" name="<?= SettingService::KEY_OUTBOUND_PREFIX ?>"
                                   maxlength="5" style="width:100px;text-transform:uppercase"
                                   value="<?= htmlspecialchars($configs[SettingService::KEY_OUTBOUND_PREFIX] ?? 'PX') ?>">
                            <span style="color:#94a3b8;font-size:13px">+ 00001 → <strong id="outPreview">PX00001</strong></span>
                        </div>
                    </div>
                </div>
                <small style="color:#f59e0b;font-size:12px">
                    <i class="ri-alert-line"></i> Thay đổi prefix chỉ ảnh hưởng các phiếu tạo mới. Phiếu cũ không bị đổi.
                </small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><i class="ri-save-line"></i> Lưu cấu hình</button>
            </div>
        </form>
    </div>
</div>

<script>
// Preview prefix
document.querySelectorAll('input[name="<?= SettingService::KEY_INBOUND_PREFIX ?>"], input[name="<?= SettingService::KEY_OUTBOUND_PREFIX ?>"]').forEach(input => {
    input.addEventListener('input', function() {
        const val = this.value.toUpperCase();
        this.value = val;
        if (this.name.includes('inbound')) document.getElementById('inPreview').textContent  = val + '00001';
        else                               document.getElementById('outPreview').textContent = val + '00001';
    });
});
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>