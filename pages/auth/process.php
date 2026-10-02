<?php
// pages/auth/process.php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\Response;
use App\Repositories\AuthRepository;
use App\Services\AuthService;
use App\Services\CsrfService;

// ── Wiring (Composition Root) ─────────────────────────────────────────────
$service = new AuthService(
    repo: new AuthRepository($pdo),
    csrf: new CsrfService(),
);

// ── Chỉ nhận POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::redirect(BASE_URL . '/pages/auth/SignIn.php');
}

// ── Nếu đã đăng nhập rồi ─────────────────────────────────────────────────
if (isLoggedIn()) {
    Response::redirect(BASE_URL . '/pages/dashboard/index.php');
}

// ── CSRF Validation ───────────────────────────────────────────────────────
if (!$service->validateCsrf($_POST['csrf_token'] ?? null)) {
    Response::redirect(
        BASE_URL . '/pages/auth/SignIn.php',
        'error',
        'Yêu cầu không hợp lệ. Vui lòng tải lại trang và thử lại.'
    );
}

// ── Đăng nhập ────────────────────────────────────────────────────────────
$result = $service->login(
    username: trim($_POST['username'] ?? ''),
    password: $_POST['password']     ?? '',
);

if (!$result['ok']) {
    setFlash('username', trim($_POST['username'] ?? ''));
    Response::redirect(
        BASE_URL . '/pages/auth/SignIn.php',
        'error',
        $result['message']
    );
}

Response::redirect(BASE_URL . '/pages/dashboard/index.php');