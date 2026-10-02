<?php
// pages/products/process.php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SupplierRepository;
use App\Services\ProductService;
use App\Services\CsrfService;

requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$csrf   = new CsrfService();

// ── Composition Root ──────────────────────────────────────────────────────────
$productService = new ProductService(
    repo:         new ProductRepository($pdo),
    categoryRepo: new CategoryRepository($pdo),
    supplierRepo: new SupplierRepository($pdo),
);

// AJAX: kiểm tra SKU trùng (GET, read-only → không cần CSRF)
if ($action === 'check_sku' && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['sku'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'exists' => $productService->checkSku(
            trim($_GET['sku']),
            (int) ($_GET['id'] ?? 0)
        ),
    ]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['csrf_token']
          ?? null;

if (!$csrf->validate($csrfToken)) {
    $isAjax = isAjax();
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
    } else {
        setFlash('error', 'Yêu cầu không hợp lệ.');
        header('Location: index.php');
    }
    exit;
}

// ── AJAX: Thêm danh mục nhanh ─────────────────────────────────────────────
if ($action === 'add_category') {
    header('Content-Type: application/json');
    $result = $productService->addCategory(
        name:        trim($_POST['name']        ?? ''),
        description: trim($_POST['description'] ?? ''),
    );
    echo json_encode($result);
    exit;
}

// ── THÊM SẢN PHẨM ─────────────────────────────────────────────────────────
if ($action === 'add') {
    $result = $productService->add($_POST);
    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }
    header('Location: index.php');
    exit;
}

// ── CẬP NHẬT SẢN PHẨM ────────────────────────────────────────────────────
if ($action === 'edit') {
    $result = $productService->edit((int) ($_POST['id'] ?? 0), $_POST);
    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }
    header('Location: index.php');
    exit;
}

// ── XÓA / NGỪNG KINH DOANH ───────────────────────────────────────────────
if ($action === 'delete') {
    $result = $productService->delete((int) ($_POST['id'] ?? 0));
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
           || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }
    header('Location: index.php');
    exit;
}

// Fallback
header('Location: index.php');
exit;

// ── Helper ────────────────────────────────────────────────────────────────
function isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}