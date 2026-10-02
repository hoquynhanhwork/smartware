<?php
// pages/suppliers/process.php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\SupplierService;
use App\Services\CsrfService;
use App\Repositories\SupplierRepository;

requireLogin();

$csrf    = new CsrfService();
$service = new SupplierService(new SupplierRepository($pdo));

// ── Hàm helper nhất quán với products/process.php ────────────────────────────
function isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // ── AJAX: Kiểm tra trùng lặp ─────────────────────────────────────────────
    if ($action === 'check_unique') {
        $allowedFields = ['name', 'phone', 'email', 'tax_code'];
        $field         = $_GET['field'] ?? '';

        if (!in_array($field, $allowedFields, true)) {
            header('Content-Type: application/json');
            echo json_encode(['exists' => false]);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'exists' => $service->checkUnique(
                $field,
                trim($_GET['value'] ?? ''),
                (int) ($_GET['id'] ?? 0)
            ),
        ]);
        exit;
    }

    // ── AJAX: Lấy sản phẩm của NCC ───────────────────────────────────────────
    if ($action === 'get_products') {
        $supplierId = (int) ($_GET['supplier_id'] ?? 0);
        header('Content-Type: application/json');

        if (!$supplierId) {
            echo json_encode(['error' => 'ID đối tác không hợp lệ']);
            exit;
        }

        echo json_encode($service->getProducts($supplierId));
        exit;
    }

    // ── AJAX: Lịch sử nhập hàng ──────────────────────────────────────────────
    if ($action === 'get_inbound_history') {
        $supplierId  = (int) ($_GET['supplier_id'] ?? 0);
        $validRanges = ['7d', '30d', '90d', '1y'];
        $range       = in_array($_GET['range'] ?? '', $validRanges, true)
                        ? $_GET['range'] : 'all';

        header('Content-Type: application/json');

        if (!$supplierId) {
            echo json_encode(['error' => 'ID đối tác không hợp lệ']);
            exit;
        }

        echo json_encode($service->getInboundHistory($supplierId, $range));
        exit;
    }

    // ── AJAX: Tổng tiền đã nhập ──────────────────────────────────────────────
    if ($action === 'get_total_import') {
        $supplierId = (int) ($_GET['supplier_id'] ?? 0);
        header('Content-Type: application/json');

        if (!$supplierId) {
            echo json_encode(['error' => 'ID đối tác không hợp lệ']);
            exit;
        }

        echo json_encode(['total' => $service->getTotalImport($supplierId)]);
        exit;
    }

    // ── AJAX: Chi tiết NCC ───────────────────────────────────────────────────
    if ($action === 'get_supplier_info') {
        $id = (int) ($_GET['id'] ?? 0);
        header('Content-Type: application/json');

        if (!$id) {
            echo json_encode(['error' => 'ID không hợp lệ']);
            exit;
        }

        $supplier = $service->getDetail($id);
        echo json_encode($supplier ?: ['error' => 'Không tìm thấy đối tác']);
        exit;
    }

    // GET không khớp action nào → về index
    header('Location: index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action    = $_POST['action'] ?? '';
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;

if (!$csrf->validate($csrfToken)) {
    if (isAjax()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
    } else {
        setFlash('error', 'Yêu cầu không hợp lệ. Vui lòng tải lại trang.');
        header('Location: index.php');
    }
    exit;
}

// ── THÊM ĐỐI TÁC
if ($action === 'add') {
    $result = $service->add($_POST);

    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }

    header('Location: index.php');
    exit;
}

// ── CẬP NHẬT ĐỐI TÁC
if ($action === 'edit') {
    $result = $service->edit((int) ($_POST['id'] ?? 0), $_POST);

    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }

    header('Location: index.php');
    exit;
}

// ── XÓA / NGỪNG HỢP TÁC ──────────────────────────────────────────────────────
if ($action === 'delete') {
    $result = $service->delete((int) ($_POST['id'] ?? 0));

    if (isAjax()) {
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