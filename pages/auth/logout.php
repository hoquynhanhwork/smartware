<?php
// pages/auth/logout.php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\Response;
use App\Repositories\AuthRepository;
use App\Services\AuthService;
use App\Services\CsrfService;

if (!isLoggedIn()) {
    Response::redirect(BASE_URL . '/pages/auth/SignIn.php');
}

// ── Chỉ chấp nhận POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::redirect(BASE_URL . '/pages/auth/SignIn.php');
}

$service = new AuthService(
    repo: new AuthRepository($pdo),
    csrf: new CsrfService(),
);

// ── CSRF chỉ lấy từ POST body ────────────────────────────────────────────
if (!$service->validateCsrf($_POST['csrf_token'] ?? null)) {
    Response::redirect(BASE_URL . '/pages/auth/SignIn.php');
}

$service->logout();

Response::redirect(
    BASE_URL . '/pages/auth/SignIn.php',
    'success',
    'Bạn đã đăng xuất thành công.'
);