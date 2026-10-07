<?php
// pages/settings/_tabs.php
// Header & Thanh Tabs ngang thuần Tiếng Việt, loại bỏ thanh cuộn ngang/dọc thừa

$currentPage = basename($_SERVER['PHP_SELF']);

$tabs = [
    'users' => [
        'file'  => 'users.php',
        'label' => 'Thành viên & Nhân sự',
        'roles' => ['admin', 'manager'],
    ],
    'profile' => [
        'file'  => 'profile.php',
        'label' => 'Cài đặt chung',
        'roles' => ['admin', 'manager', 'staff'],
    ],
    'alerts' => [
        'file'  => 'alerts.php',
        'label' => 'Thông báo & Cảnh báo',
        'roles' => ['admin', 'manager'],
        'badge' => function() {
            static $count = null;
            if ($count === null) {
                global $pdo;
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM alerts WHERE is_read = false AND resolved_at IS NULL");
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
            }
            return $count;
        },
    ],
    'configurations' => [
        'file'  => 'configurations.php',
        'label' => 'Hệ thống & Cấu hình',
        'roles' => ['admin'],
    ],
];
?>

<div class="settings-top-navbar">
    <!-- Thanh Tabs gạch chân ngang (Không bị cuộn) -->
    <nav class="settings-tabs-nav">
        <?php foreach ($tabs as $key => $tab):
            if (!hasRole(...$tab['roles'])) continue;
            $isActive = ($currentPage === $tab['file']);
            $badge    = isset($tab['badge']) ? ($tab['badge'])() : 0;
        ?>
        <a href="<?= $tab['file'] ?>" class="settings-tab-link <?= $isActive ? 'active' : '' ?>">
            <span><?= $tab['label'] ?></span>
            <?php if ($badge > 0): ?>
                <span class="tab-badge"><?= $badge ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>