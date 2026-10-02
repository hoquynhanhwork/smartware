<?php
// layout/header.php

require_once __DIR__ . '/../config/auth.php';
requireLogin();

use App\Services\CsrfService;

$role         = $_SESSION['user_role']    ?? 'staff';
$user_name    = $_SESSION['user_name']    ?? 'Người dùng';
$company_name = 'SmartWare';
$current_path = $_SERVER['PHP_SELF'];

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM   alerts
    WHERE  is_read = false
");
$stmt->execute();
$alert_count = (int) $stmt->fetchColumn();
$csrfService = new CsrfService();
$csrfToken   = $csrfService->getToken();

function navActive(string $path, string $segment): string {
    return str_contains($path, '/' . $segment . '/') ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartWare — Quản lý kho</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4.3.0/fonts/remixicon.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/layout.css">

    <!-- ĐOẠN SCRIPT CHẶN GIẬT SIDEBAR ĐẶT NGAY TẠI ĐÂY -->
    <script>
        (function () {
            if (localStorage.getItem("sidebar_collapsed") === "true") {
                document.documentElement.classList.add("sidebar-collapsed");
            }
        })();
    </script>
</head>
<body>

<aside class="app-sidebar" id="appSidebar">
    <!-- Brand Logo & Nút Toggle -->
    <div class="sidebar-brand">
        <div class="brand-left">
            <div class="brand-logo">
                <svg width="28" height="28" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M16 4C9.37 4 4 9.37 4 16C4 22.63 9.37 28 16 28V4Z" fill="#18181B"/>
                    <path d="M22 10C20.9 10 20 10.9 20 12V20C20 21.1 20.9 22 22 22C25.31 22 28 19.31 28 16C28 12.69 25.31 10 22 10Z" fill="#18181B"/>
                </svg>
            </div>
            <span class="brand-name">SmartWare</span>
        </div>
        <!-- Nút thu/phóng sidebar -->
        <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" title="Thu gọn / Mở rộng">
            <i class="ri-menu-fold-line"></i>
        </button>
    </div>

    <!-- Navigation List -->
    <nav class="sidebar-menu">
        <!-- 1. Tổng quan -->
        <a href="<?= BASE_URL ?>/pages/dashboard/index.php" 
           class="nav-item <?= navActive($current_path, 'dashboard') ?>" 
           data-tooltip="Tổng quan">
            <i class="ri-dashboard-line nav-icon"></i>
            <span class="nav-label">Tổng quan</span>
        </a>

        <!-- 2. Sản phẩm -->
        <a href="<?= BASE_URL ?>/pages/products/index.php" 
           class="nav-item <?= navActive($current_path, 'products') ?>" 
           data-tooltip="Sản phẩm">
            <i class="ri-box-3-line nav-icon"></i>
            <span class="nav-label">Sản phẩm</span>
        </a>

        <!-- 3. Đối tác / Nhà cung cấp -->
        <a href="<?= BASE_URL ?>/pages/suppliers/index.php" 
           class="nav-item <?= navActive($current_path, 'suppliers') ?>" 
           data-tooltip="Đối tác">
            <i class="ri-store-2-line nav-icon"></i>
            <span class="nav-label">Đối tác</span>
        </a>

        <!-- 4. Nhập kho -->
        <a href="<?= BASE_URL ?>/pages/inbound/index.php" 
           class="nav-item <?= navActive($current_path, 'inbound') ?>" 
           data-tooltip="Nhập kho">
            <i class="ri-download-2-line nav-icon"></i>
            <span class="nav-label">Nhập kho</span>
        </a>

        <!-- 5. Xuất kho -->
        <a href="<?= BASE_URL ?>/pages/outbound/index.php" 
           class="nav-item <?= navActive($current_path, 'outbound') ?>" 
           data-tooltip="Xuất kho">
            <i class="ri-upload-2-line nav-icon"></i>
            <span class="nav-label">Xuất kho</span>
        </a>

        <!-- 6. Tồn kho -->
        <a href="<?= BASE_URL ?>/pages/inventory/index.php" 
           class="nav-item <?= navActive($current_path, 'inventory') ?>" 
           data-tooltip="Tồn kho">
            <i class="ri-archive-stack-line nav-icon"></i>
            <span class="nav-label">Tồn kho</span>
        </a>

        <!-- 7. Báo cáo & Thống kê 
        <a href="<?= BASE_URL ?>/pages/reports/overview.php" 
           class="nav-item <?= navActive($current_path, 'reports') ?>" 
           data-tooltip="Báo cáo">
            <i class="ri-bar-chart-2-line nav-icon"></i>
            <span class="nav-label">Báo cáo</span>
        </a>-->

        <!-- 8. Trợ lý AI -->
        <a href="<?= BASE_URL ?>/pages/ai/dashboard.php" 
           class="nav-item <?= navActive($current_path, 'ai') ?>" 
           data-tooltip="Dịch vụ AI">
            <i class="ri-sparkling-2-line nav-icon"></i>
            <span class="nav-label">Trợ lý AI</span>
            <?php if ($alert_count > 0): ?>
                <span class="nav-badge"><?= $alert_count ?></span>
            <?php endif; ?>
        </a>

        <!-- 9. Cài đặt hệ thống -->
        <a href="<?= BASE_URL ?>/pages/settings/index.php" 
           class="nav-item <?= navActive($current_path, 'settings') ?>" 
           data-tooltip="Cài đặt">
            <i class="ri-settings-3-line nav-icon"></i>
            <span class="nav-label">Cài đặt</span>
        </a>
    </nav>

    <!-- Footer Đăng xuất -->
    <div class="sidebar-footer">
        <form action="<?= BASE_URL ?>/pages/auth/logout.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
            <button type="submit" class="nav-item logout-btn" data-tooltip="Đăng xuất">
                <i class="ri-logout-box-r-line nav-icon"></i>
                <span class="nav-label">Đăng xuất</span>
            </button>
        </form>
    </div>
</aside>

<div class="page-wrapper">
    <?php include __DIR__ . '/topbar.php'; ?>

    <?php
    $flash_success = getFlash('success');
    $flash_error   = getFlash('error');
    $flash_warning = getFlash('warning');
    if ($flash_success || $flash_error || $flash_warning): ?>
    <div class="flash-container">
        <?php if ($flash_success): ?>
            <div class="flash flash-success">
                <i class="fa-solid fa-circle-check"></i>
                <?= htmlspecialchars($flash_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="flash flash-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($flash_error) ?>
            </div>
        <?php endif; ?>
        <?php if ($flash_warning): ?>
            <div class="flash flash-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= htmlspecialchars($flash_warning) ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
        setTimeout(() => {
            document.querySelectorAll('.flash').forEach(el => {
                el.style.transition = 'opacity .4s';
                el.style.opacity    = '0';
                setTimeout(() => el.remove(), 400);
            });
        }, 4000);
    </script>
    <?php endif; ?>

    <main class="main-content">