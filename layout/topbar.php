<?php
// layout/topbar.php
$title = $page_title ?? 'TỔNG QUAN';

// Đảm bảo luôn có CSRF token cho form đăng xuất, không phụ thuộc trang gọi có khai báo $csrfToken hay không
if (empty($csrfToken)) {
    if (isset($csrf) && is_object($csrf) && method_exists($csrf, 'getToken')) {
        $csrfToken = $csrf->getToken();
    } elseif (class_exists('App\Services\CsrfService')) {
        $csrfToken = (new \App\Services\CsrfService())->getToken();
    } else {
        $csrfToken = '';
    }
}

// Đảm bảo luôn có tên người dùng hiển thị, tránh null gây deprecated warning
if (empty($user_name)) {
    $user_name = $_SESSION['user_name']
        ?? $_SESSION['user']['name']
        ?? $_SESSION['username']
        ?? 'Người dùng';
}
?>
<?php
// layout/topbar.php
$title = $page_title ?? 'Dashboard';
?>
<header class="topbar">
    <div class="topbar-left">
        <h1 class="page-title"><?= htmlspecialchars($title) ?></h1>
    </div>

    <div class="topbar-right">
        
        <!-- Nút chuông thông báo -->
        <button type="button" class="topbar-icon-btn" title="Thông báo">
            <i class="ri-notification-3-line"></i>
            <?php if (!empty($alert_count) && $alert_count > 0): ?>
                <span class="badge-dot"></span>
            <?php endif; ?>
        </button>
    </div>
</header>