<?php
// pages/inbound/process.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\CsrfService;
use App\Services\InboundService;
use App\Repositories\InboundRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SupplierRepository;

requireLogin();

$user_id    = (int) $_SESSION['user_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$csrf       = new CsrfService();

$service = new InboundService(
    new InboundRepository($pdo),
    new CategoryRepository($pdo),
    new SupplierRepository($pdo),
    $pdo
);

// ── API: Tìm kiếm sản phẩm ──────────────────────────────────────────────
if ($action === 'search_products' && isset($_GET['term'])) {
    header('Content-Type: application/json');
    echo json_encode($service->searchProducts(trim($_GET['term'])));
    exit;
}

// ── API: Lấy chi tiết phiếu ──────────────────────────────────────────────
if ($action === 'get_detail' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $result = $service->getDetail((int) $_GET['id']);
    echo json_encode(
        $result
            ? array_merge(['success' => true], $result)
            : ['success' => false, 'message' => 'Không tìm thấy phiếu']
    );
    exit;
}

// ── API: Kiểm tra unique supplier ────────────────────────────────────────
if ($action === 'check_unique' && isset($_GET['field'], $_GET['value'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'exists' => $service->checkSupplierUnique(
            $_GET['field'],
            trim($_GET['value']),
            (int) ($_GET['id'] ?? 0)
        ),
    ]);
    exit;
}

// ── POST requests ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
          || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if (!$csrf->validate($_POST['_csrf_token'] ?? null)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'success' => false, 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
    } else {
        setFlash('error', 'Yêu cầu không hợp lệ. Vui lòng thử lại.');
        header('Location: index.php');
    }
    exit;
}

// ── Thêm sản phẩm mới (AJAX) ─────────────────────────────────────────────
if ($action === 'add_product') {
    header('Content-Type: application/json');
    echo json_encode($service->addProduct($_POST));
    exit;
}

// ── Thêm danh mục (AJAX) ──────────────────────────────────────────────────
if ($action === 'add_category') {
    header('Content-Type: application/json');
    echo json_encode($service->addCategory(
        $_POST['name']        ?? '',
        $_POST['description'] ?? ''
    ));
    exit;
}

// ── Thêm nhà cung cấp (AJAX) ──────────────────────────────────────────────
if ($action === 'add_supplier') {
    header('Content-Type: application/json');
    echo json_encode($service->addSupplier($_POST));
    exit;
}

// ── Tạo phiếu nhập ────────────────────────────────────────────────────────
if ($action === 'add_inbound' || $action === 'add') {
    $result = $service->add($user_id, $_POST);
    $csrf->rotate(); 
    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }
    header('Location: ' . ($result['redirect'] ?? 'index.php'));
    exit;
}

// ── Sửa phiếu nhập ─────────────────────────────────────────────────────────
if ($action === 'edit_inbound' || $action === 'edit') {
    $id     = (int) ($_POST['id'] ?? 0);
    $result = $service->edit($user_id, $id, $_POST);
    $csrf->rotate();
    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }
    header('Location: ' . ($result['redirect'] ?? 'index.php'));
    exit;
}

// ── Xóa phiếu nhập ────────────────────────────────────────────────────────
if ($action === 'delete_inbound' || $action === 'delete') {
    $id     = (int) ($_POST['id'] ?? 0);
    $result = $service->delete($id);
    $csrf->rotate();

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

// ── Fallback ──────────────────────────────────────────────────────────────
header('Location: index.php');
exit;