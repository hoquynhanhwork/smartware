<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\CsrfService;

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard/index.php');
    exit;
}

// Lấy CSRF token để nhúng vào form
$csrfService = new CsrfService();
$csrfToken   = $csrfService->getToken();

// Lấy dữ liệu flash
$error        = getFlash('error');
$savedUsername = getFlash('username');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập — SmartWare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/auth.css">
</head>
<body>

<div class="signin-wrapper">

    <div class="brand-panel">
        <div>
            <div class="brand-tagline">
               <div class="icon-box"><i class="bi bi-box-seam"> </i>Quản lý kho <span>thông minh</span><br>với AI GPT-4o</div>
            </div>
            <div class="brand-desc">
                Hệ thống quản lý xuất nhập kho tích hợp trí tuệ nhân tạo, hỗ trợ dự báo nhu cầu và cảnh báo tồn kho tự động.
            </div>
            <ul class="feature-list">
                <li><i class="bi bi-graph-up-arrow"></i> Dự báo nhu cầu nhập hàng</li>
                <li><i class="bi bi-bell"></i> Cảnh báo tồn kho thông minh</li>
                <li><i class="bi bi-chat-dots"></i> Truy vấn kho bằng ngôn ngữ tự nhiên</li>
                <li><i class="bi bi-shield-check"></i> Quản lý lô hàng theo FEFO</li>
            </ul>
        </div>

        <div class="brand-footer">
            &copy; <?= date('Y') ?> SmartWare — NCKH Sinh viên
        </div>
    </div>

    <div class="form-panel">
        <div class="text-center mb-4">
            <img src="../../img/logo.jpg" alt="SmartWare Logo" style="max-width: 120px; height: auto;">
        </div>

        <div class="form-title">ĐĂNG NHẬP</div>
        <div class="form-subtitle">Chào mừng trở lại! Nhập thông tin để tiếp tục.</div>

        <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form action="process.php" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- Username -->
            <div class="mb-3">
                <label class="form-label" for="username">Tên đăng nhập</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Nhập tên đăng nhập"
                        value="<?= htmlspecialchars($savedUsername ?? '') ?>"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>
            </div>

            <!-- Password -->
            <div class="mb-4">
                <label class="form-label" for="password">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Nhập mật khẩu"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="btn btn-outline-secondary toggle-password" tabindex="-1">
                        <i class="bi bi-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-signin w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Đăng nhập
            </button>

        </form>
    </div>

</div>

<script>
    document.querySelector('.toggle-password').addEventListener('click', function () {
        const pwd  = document.getElementById('password');
        const icon = document.getElementById('eye-icon');
        const show = pwd.type === 'password';
        pwd.type   = show ? 'text' : 'password';
        icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
</script>

</body>
</html>