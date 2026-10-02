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
</head>
<body>

<aside class="sidebar collapsed">
    <nav class="sidebar-nav">
        <ul class="sidebar-nav-list">
            <li>
                <a href="<?= BASE_URL ?>/pages/dashboard/index.php"
                   class="sidebar-link <?= navActive($current_path, 'dashboard') ?>"
                   data-tooltip="Tổng quan">
                    <span class="sidebar-icon"><i class="ri-home-fill"></i></span>
                    <span class="sidebar-label">Tổng quan</span>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/products/index.php"
                   class="sidebar-link <?= navActive($current_path, 'products') ?>"
                   data-tooltip="Sản phẩm">
                    <span class="sidebar-icon"><i class="ri-product-hunt-fill"></i></span>
                    <span class="sidebar-label">Sản phẩm</span>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/suppliers/index.php"
                   class="sidebar-link <?= navActive($current_path, 'suppliers') ?>"
                   data-tooltip="Đối tác">
                    <span class="sidebar-icon"><i class="ri-store-fill"></i></span>
                    <span class="sidebar-label">Đối tác</span>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/inbound/index.php"
                   class="sidebar-link <?= navActive($current_path, 'inbound') ?>"
                   data-tooltip="Nhập kho">
                    <span class="sidebar-icon"><i class="ri-arrow-up-circle-fill"></i></span>
                    <span class="sidebar-label">Nhập kho</span>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/outbound/index.php"
                   class="sidebar-link <?= navActive($current_path, 'outbound') ?>"
                   data-tooltip="Xuất kho">
                    <span class="sidebar-icon"><i class="ri-arrow-down-circle-fill"></i></span>
                    <span class="sidebar-label">Xuất kho</span>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/inventory/index.php"
                   class="sidebar-link <?= navActive($current_path, 'inventory') ?>"
                   data-tooltip="Tồn kho">
                    <span class="sidebar-icon"><i class="ri-archive-stack-fill"></i></span>
                    <span class="sidebar-label">Tồn kho</span>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/reports/overview.php"
                   class="sidebar-link <?= navActive($current_path, 'reports') ?>"
                   data-tooltip="Tồn kho">
                    <span class="sidebar-icon"><i class="ri-archive-stack-fill"></i></span>
                    <span class="sidebar-label">Tồn kho</span>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/ai/dashboard.php"
                   class="sidebar-link <?= navActive($current_path, 'ai') ?>"
                   data-tooltip="AI">
                    <span class="sidebar-icon"><i class="ri-openai-fill"></i></span>
                    <span class="sidebar-label">AI</span>
                    <?php if ($alert_count > 0): ?>
                        <span class="sidebar-badge"><?= $alert_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= BASE_URL ?>/pages/settings/index.php"
                   class="sidebar-link <?= navActive($current_path, 'settings') ?>"
                   data-tooltip="Cài đặt">
                    <span class="sidebar-icon"><i class="ri-settings-4-fill"></i></span>
                    <span class="sidebar-label">Cài đặt</span>
                </a>
            </li>
        </ul>

        <!-- Nhóm Cài đặt và Đăng xuất ở phía dưới -->
        <ul class="sidebar-footer-list">
            <li class="sidebar-divider"></li>
            <li>
                <form action="<?= BASE_URL ?>/pages/auth/logout.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                    <button type="submit" class="sidebar-link sidebar-logout-btn" data-tooltip="Đăng xuất">
                        <span class="sidebar-icon"><i class="ri-logout-box-r-line"></i></span>
                        <span class="sidebar-label">Đăng xuất</span>
                    </button>
                </form>
            </li>
        </ul>
    </nav>
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