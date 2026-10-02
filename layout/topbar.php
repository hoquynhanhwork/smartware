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
<header class="topbar">
    <div class="topbar-left">
        <a href="<?= BASE_URL ?>/pages/dashboard/index.php" class="topbar-logo" title="Về trang chủ">
            <img src="<?= BASE_URL ?>/img/Logo.png" alt="SmartWare Logo">
        </a>
        <h1 class="page-title"><?= htmlspecialchars(mb_strtoupper($title ?? '', 'UTF-8')) ?></h1>
    </div>

    <div class="topbar-right">
        <div class="topbar-item">
            <i class="fa-solid fa-headset"></i>
            <span>Hỗ trợ</span>
        </div>
        <!-- Đã chuyển Cài đặt, Avatar và Dropdown Hồ sơ vào sidebar bên trái -->
    </div>
</header>