<?php
// pages/settings/alerts.php
$page_title = 'CÀI ĐẶT HỆ THỐNG';

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SettingRepository;
use App\Services\SettingService;
use App\Services\CsrfService;

requireLogin();
requireRole('admin', 'manager');

$user_id       = (int) $_SESSION['user_id'];
$service       = new SettingService(new SettingRepository($pdo));
$csrf          = new CsrfService();

$unreadOnly    = isset($_GET['filter']) && $_GET['filter'] === 'unread';
$alerts        = $service->getAlerts($unreadOnly);
$unreadCount   = $service->countUnreadAlerts();
$csrfToken     = $csrf->getToken();
$flash_success = getFlash('success');

// Hệ màu Semantic cho cảnh báo kho hàng
$typeMap = [
    'low_stock'   => ['label' => 'Tồn kho thấp',  'icon' => 'ri-error-warning-fill',  'color' => 'var(--accent)'],
    'overstock'   => ['label' => 'Tồn kho cao',   'icon' => 'ri-stack-fill',          'color' => 'var(--info)'],
    'near_expiry' => ['label' => 'Sắp hết hạn',   'icon' => 'ri-time-fill',           'color' => 'var(--danger)'],
];
$sevMap = [
    'high'   => ['badge' => 'st-overdue',   'label' => 'Cao'],
    'medium' => ['badge' => 'st-pending',   'label' => 'Trung bình'],
    'low'    => ['badge' => 'st-completed', 'label' => 'Thấp'],
];

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

        <!-- SECTION: THỐNG KÊ CẢNH BÁO (KPI CARDS) -->
        <div class="settings-sub-section">
            <div class="section-title-wrap">
                <i class="ri-alarm-warning-line"></i>
                <h3 class="section-heading">Tổng quan cảnh báo</h3>
            </div>

            <div class="team-kpi-grid">
                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Tổng cảnh báo</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <div class="kpi-main-val"><?= sprintf("%02d", count($alerts)) ?> mục</div>
                    <div class="kpi-sub-text">Trong bộ lọc hiện tại</div>
                    <div class="kpi-trend trend-down">
                        <i class="ri-list-check"></i>
                        <span><?= $unreadOnly ? 'Chỉ chưa đọc' : 'Tất cả lịch sử' ?></span>
                    </div>
                </div>

                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Chưa đọc</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <div class="kpi-main-val" style="color: var(--status-danger);"><?= sprintf("%02d", $unreadCount) ?> mục</div>
                    <div class="kpi-sub-text">Cần xem xét</div>
                    <div class="kpi-trend <?= $unreadCount > 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="ri-notification-badge-line"></i>
                        <span><?= $unreadCount > 0 ? 'Đang kích hoạt' : 'Đã kiểm tra hết' ?></span>
                    </div>
                </div>

                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Rủi ro tồn kho</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <?php 
                        $lowStockCount = count(array_filter($alerts, fn($a) => $a['type'] === 'low_stock'));
                    ?>
                    <div class="kpi-main-val" style="color: var(--gold-accent);"><?= sprintf("%02d", $lowStockCount) ?> mặt hàng</div>
                    <div class="kpi-sub-text">Chạm hoặc dưới mức tồn tối thiểu</div>
                    <div class="kpi-trend trend-up">
                        <i class="ri-error-warning-line"></i>
                        <span>Cần tạo đơn nhập</span>
                    </div>
                </div>

                <div class="team-kpi-card">
                    <div class="kpi-top">
                        <span class="kpi-lbl">Rủi ro hạn dùng</span>
                        <button type="button" class="btn-more-dots"><i class="ri-more-2-fill"></i></button>
                    </div>
                    <?php 
                        $expCount = count(array_filter($alerts, fn($a) => $a['type'] === 'near_expiry'));
                    ?>
                    <div class="kpi-main-val" style="color: var(--status-danger);"><?= sprintf("%02d", $expCount) ?> lô hàng</div>
                    <div class="kpi-sub-text">Gần thời hạn sử dụng</div>
                    <div class="kpi-trend trend-up">
                        <i class="ri-time-line"></i>
                        <span>Ưu tiên xuất trước (FEFO)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION: DANH SÁCH CẢNH BÁO -->
        <div class="settings-sub-section mt-30">
            <div class="section-title-wrap" style="justify-content: space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="ri-notification-3-line"></i>
                    <h3 class="section-heading">Trung tâm thông báo</h3>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <a href="?filter=<?= $unreadOnly ? 'all' : 'unread' ?>" class="btn-tool-outline">
                        <i class="ri-filter-3-line"></i>
                        <span><?= $unreadOnly ? 'Xem tất cả' : 'Chưa đọc (' . $unreadCount . ')' ?></span>
                    </a>
                    <?php if ($unreadCount > 0): ?>
                    <form method="POST" action="process.php" style="margin:0">
                        <input type="hidden" name="action"     value="mark_all_read">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <button type="submit" class="btn-modern-dark btn-sm">
                            <i class="ri-check-double-line"></i> Đọc tất cả
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($alerts)): ?>
                <div class="clean-member-card-table" style="padding: 48px 24px; text-align: center;">
                    <i class="ri-checkbox-circle-line" style="font-size: 40px; color: var(--status-success); margin-bottom: 8px; display: inline-block;"></i>
                    <p style="color: var(--text-muted); font-size: 14px; margin: 0;">Hiện không có thông báo cảnh báo nào<?= $unreadOnly ? ' chưa đọc' : '' ?>.</p>
                </div>
            <?php else: ?>
                <div class="member-list-stack" id="alertListStack">
                    <?php foreach ($alerts as $a):
                        $type   = $typeMap[$a['type']] ?? ['label' => $a['type'], 'icon' => 'ri-alert-line', 'color' => 'var(--text-muted)'];
                        $sev    = $sevMap[$a['severity']] ?? $sevMap['low'];
                        $isRead = $a['is_read'] || $a['resolved_at'];
                    ?>
                    <div class="member-row-card <?= $isRead ? 'alert-read-muted' : '' ?>" id="alert-<?= $a['id'] ?>" style="grid-template-columns: 2.2fr 2fr 1.2fr 110px;">
                        <!-- Cột 1: Loại cảnh báo & Thông tin SP -->
                        <div class="cell-member-profile">
                            <div class="member-avatar" style="background: <?= $type['color'] ?>20; border-color: transparent;">
                                <i class="<?= $type['icon'] ?>" style="font-size: 20px; color: <?= $type['color'] ?>;"></i>
                            </div>
                            <div class="member-info">
                                <div class="member-name">
                                    <strong><?= htmlspecialchars($a['product_name']) ?></strong>
                                    <?php if (!$a['is_read'] && !$a['resolved_at']): ?>
                                        <span class="unread-dot"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="member-email">SKU: <?= htmlspecialchars($a['sku'] ?? '—') ?></div>
                            </div>
                        </div>

                        <!-- Cột 2: Nội dung tin nhắn -->
                        <div class="cell-member-tasks">
                            <div class="task-badge-info">
                                <span><?= htmlspecialchars($a['message']) ?></span>
                            </div>
                            <div class="role-text-info">
                                Phân loại: <strong style="color: <?= $type['color'] ?>;"><?= $type['label'] ?></strong>
                                · Mức độ: <span class="clean-badge <?= $sev['badge'] ?>" style="padding: 1px 6px; font-size: 11px;"><?= $sev['label'] ?></span>
                            </div>
                        </div>

                        <!-- Cột 3: Thời gian -->
                        <div class="cell-member-date">
                            <i class="ri-time-line"></i>
                            <span><?= date('d/m/Y H:i', strtotime($a['created'])) ?></span>
                        </div>

                        <!-- Cột 4: Thao tác -->
                        <div class="cell-member-actions" style="justify-content: flex-end;">
                            <?php if (!$a['is_read']): ?>
                            <button type="button" class="btn-icon-subtle" title="Đánh dấu đã đọc" onclick="markAlertRead(<?= $a['id'] ?>)">
                                <i class="ri-check-line"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (!$a['resolved_at']): ?>
                            <button type="button" class="btn-icon-subtle text-success" title="Xác nhận đã xử lý" onclick="resolveAlert(<?= $a['id'] ?>)">
                                <i class="ri-checkbox-circle-line"></i>
                            </button>
                            <?php else: ?>
                            <span class="clean-badge st-completed" style="font-size: 11px;"><i class="ri-check-line"></i> Đã xử lý</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/utils.js"></script>
<script src="<?= BASE_URL ?>/js/settings.js"></script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>