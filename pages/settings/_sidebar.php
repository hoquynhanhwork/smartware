<?php
// pages/settings/_sidebar.php
// Include trong tất cả trang settings

$currentPage = basename($_SERVER['PHP_SELF']);

$navItems = [
    'profile' => [
        'file'  => 'profile.php',
        'icon'  => 'ri-user-line',
        'label' => 'Hồ sơ cá nhân',
        'roles' => ['admin', 'manager', 'staff'],
    ],
    'users' => [
        'file'  => 'users.php',
        'icon'  => 'ri-team-line',
        'label' => 'Người dùng',
        'roles' => ['admin'],
    ],
    'alerts' => [
        'file'  => 'alerts.php',
        'icon'  => 'ri-alarm-warning-line',
        'label' => 'Cảnh báo',
        'roles' => ['admin', 'manager'],
        'badge' => function() {
            // Đọc từ session nếu có, tránh query thêm
            static $count = null;
            if ($count === null) {
                global $pdo;
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM alerts
                    WHERE is_read = false AND resolved_at IS NULL
                ");
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
            }
            return $count;
        },
    ],
    'configurations' => [
        'file'  => 'configurations.php',
        'icon'  => 'ri-settings-3-line',
        'label' => 'Cấu hình hệ thống',
        'roles' => ['admin'],
    ],
];
?>
<aside class="settings-sidebar">
    <div class="settings-sidebar-title">Cài đặt</div>
    <nav class="settings-nav">
        <?php foreach ($navItems as $item):
            if (!hasRole(...$item['roles'])) continue;

            $isActive = ($currentPage === $item['file']);
            $badge    = isset($item['badge']) ? ($item['badge'])() : 0;
        ?>
        <a href="<?= $item['file'] ?>"
           class="settings-nav-item <?= $isActive ? 'active' : '' ?>">
            <i class="<?= $item['icon'] ?>"></i>
            <span><?= $item['label'] ?></span>
            <?php if ($badge > 0): ?>
                <span class="nav-badge"><?= $badge ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
</aside>