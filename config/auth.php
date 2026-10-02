<?php
// config/auth.php

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_samesite' => 'Strict',
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
    ]);
}

// ─── Hằng số đường dẫn gốc ───────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL',  '/smartware');

// ─── Thời gian session timeout (giây) ────────────────────────────────────
define('SESSION_TIMEOUT', 28800); // 8 giờ

// ─── Kiểm tra đã đăng nhập chưa ──────────────────────────────────────────
function isLoggedIn(): bool {
    // Bảng users trong schema không có company_id, nên chỉ cần kiểm tra user_id
    return isset($_SESSION['user_id']);
}

// ─── Bắt buộc đăng nhập — dùng đầu mỗi trang trong pages/ ───────────────
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/pages/auth/SignIn.php');
        exit;
    }

    // Kiểm tra session timeout
    if (time() - ($_SESSION['logged_in_at'] ?? 0) > SESSION_TIMEOUT) {
        // Hủy session hết hạn
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        // Khởi lại session mới để có thể set flash
        session_start([
            'cookie_samesite' => 'Strict',
            'cookie_httponly' => true,
            'cookie_secure'   => isset($_SERVER['HTTPS']),
        ]);
        setFlash('error', 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.');
        header('Location: ' . BASE_URL . '/pages/auth/SignIn.php');
        exit;
    }
}

// ─── Kiểm tra role ────────────────────────────────────────────────────────
function hasRole(string ...$roles): bool {
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}

// ─── Bắt buộc role — dùng cho trang giới hạn quyền ──────────────────────
function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        setFlash('error', 'Bạn không có quyền truy cập trang này.');
        header('Location: ' . BASE_URL . '/pages/dashboard/index.php');
        exit;
    }
}

// ─── Flash message ────────────────────────────────────────────────────────
function setFlash(string $type, string $message): void {
    // $type: 'success' | 'error' | 'warning' | 'info'
    $_SESSION['flash_' . $type] = $message;
}

function getFlash(string $type): ?string {
    if (isset($_SESSION['flash_' . $type])) {
        $msg = $_SESSION['flash_' . $type];
        unset($_SESSION['flash_' . $type]);
        return $msg;
    }
    return null;
}

// ─── Thông tin user hiện tại ──────────────────────────────────────────────
function currentUser(): array {
    return [
        'id'             => $_SESSION['user_id']       ?? null,
        'username'       => $_SESSION['username']      ?? '',
        'full_name'      => $_SESSION['user_name']      ?? 'Người dùng',
        'role'           => $_SESSION['user_role']      ?? 'staff',
        'email'          => $_SESSION['user_email']     ?? '',
        'status'         => $_SESSION['user_status']    ?? 'active',
        'last_login_at'  => $_SESSION['last_login_at']  ?? null,
    ];
}