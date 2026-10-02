<?php
// pages/settings/alerts.php
$page_title = 'CẢNH BÁO HỆ THỐNG';

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

$typeMap = [
    'low_stock'   => ['label' => 'Tồn kho thấp',    'icon' => 'ri-error-warning-fill',  'color' => '#f59e0b'],
    'overstock'   => ['label' => 'Tồn kho cao',      'icon' => 'ri-stack-fill',          'color' => '#3b82f6'],
    'near_expiry' => ['label' => 'Sắp hết hạn',      'icon' => 'ri-time-fill',           'color' => '#ef4444'],
];
$sevMap = [
    'high'   => ['bg' => '#fef2f2', 'color' => '#dc2626', 'label' => 'Cao'],
    'medium' => ['bg' => '#fff7ed', 'color' => '#ea580c', 'label' => 'Trung bình'],
    'low'    => ['bg' => '#f0fdf4', 'color' => '#16a34a', 'label' => 'Thấp'],
];

include __DIR__ . '/../../layout/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/settings.css">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<div class="settings-container">
    <?php include '_sidebar.php'; ?>

    <div class="settings-content">
        <div class="settings-header" style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
                <h1><i class="ri-alarm-warning-line"></i> Cảnh báo hệ thống
                    <?php if ($unreadCount > 0): ?>
                        <span style="font-size:14px;background:#ef4444;color:white;padding:2px 8px;border-radius:999px;margin-left:8px"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </h1>
                <p class="page-subtitle"><?= count($alerts) ?> cảnh báo <?= $unreadOnly ? 'chưa đọc' : 'tất cả' ?></p>
            </div>
            <div style="display:flex;gap:8px">
                <a href="?filter=<?= $unreadOnly ? 'all' : 'unread' ?>" class="btn-secondary btn-sm">
                    <?= $unreadOnly ? 'Xem tất cả' : 'Chưa đọc (' . $unreadCount . ')' ?>
                </a>
                <?php if ($unreadCount > 0): ?>
                <form method="POST" action="process.php" style="margin:0">
                    <input type="hidden" name="action"     value="mark_all_read">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="btn-primary btn-sm">
                        <i class="ri-check-double-line"></i> Đánh dấu tất cả đã đọc
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash_success): ?><div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>

        <?php if (empty($alerts)): ?>
            <div style="text-align:center;padding:60px;color:#94a3b8">
                <i class="ri-checkbox-circle-line" style="font-size:48px;display:block;margin-bottom:12px"></i>
                Không có cảnh báo nào<?= $unreadOnly ? ' chưa đọc' : '' ?>
            </div>
        <?php else: ?>
        <div class="alerts-list">
            <?php foreach ($alerts as $a):
                $type = $typeMap[$a['type']] ?? ['label' => $a['type'], 'icon' => 'ri-alert-line', 'color' => '#94a3b8'];
                $sev  = $sevMap[$a['severity']] ?? $sevMap['low'];
                $isRead = $a['is_read'] || $a['resolved_at'];
            ?>
            <div class="alert-item <?= $isRead ? 'alert-read' : '' ?>" id="alert-<?= $a['id'] ?>">
                <div class="alert-icon" style="color:<?= $type['color'] ?>">
                    <i class="<?= $type['icon'] ?>"></i>
                </div>
                <div class="alert-body">
                    <div class="alert-meta">
                        <span class="alert-type" style="color:<?= $type['color'] ?>"><?= $type['label'] ?></span>
                        <span class="alert-sev" style="background:<?= $sev['bg'] ?>;color:<?= $sev['color'] ?>"><?= $sev['label'] ?></span>
                        <?php if ($a['resolved_at']): ?>
                            <span style="font-size:11px;color:#22c55e"><i class="ri-check-line"></i> Đã xử lý</span>
                        <?php elseif (!$a['is_read']): ?>
                            <span class="unread-dot"></span>
                        <?php endif; ?>
                    </div>
                    <div class="alert-product">
                        <strong><?= htmlspecialchars($a['product_name']) ?></strong>
                        <span style="color:#94a3b8">· <?= htmlspecialchars($a['sku'] ?? '') ?></span>
                    </div>
                    <div class="alert-message"><?= htmlspecialchars($a['message']) ?></div>
                    <div class="alert-time"><?= date('d/m/Y H:i', strtotime($a['created'])) ?></div>
                </div>
                <div class="alert-actions">
                    <?php if (!$a['is_read']): ?>
                    <button class="btn-icon-subtle" title="Đánh dấu đã đọc"
                            onclick="markRead(<?= $a['id'] ?>)">
                        <i class="ri-check-line"></i>
                    </button>
                    <?php endif; ?>
                    <?php if (!$a['resolved_at']): ?>
                    <button class="btn-icon-subtle" title="Đánh dấu đã xử lý"
                            onclick="resolveAlert(<?= $a['id'] ?>)">
                        <i class="ri-checkbox-circle-line"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

async function markRead(id) {
    const fd = new FormData();
    fd.append('action', 'mark_alert_read');
    fd.append('id', id);
    fd.append('csrf_token', CSRF);
    const resp = await fetch('process.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) {
        const el = document.getElementById('alert-' + id);
        if (el) el.classList.add('alert-read');
        el?.querySelector('.unread-dot')?.remove();
        el?.querySelector('[onclick*="markRead"]')?.remove();
    }
}

async function resolveAlert(id) {
    if (!confirm('Đánh dấu cảnh báo này là đã xử lý?')) return;
    const fd = new FormData();
    fd.append('action', 'resolve_alert');
    fd.append('id', id);
    fd.append('csrf_token', CSRF);
    const resp = await fetch('process.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) location.reload();
}
</script>

<?php include __DIR__ . '/../../layout/footer.php'; ?>