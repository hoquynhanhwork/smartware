<?php
// pages/inventory/process.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use App\Services\CsrfService;

requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$csrf   = new CsrfService();

$service = new InventoryService(
    new InventoryRepository($pdo),
    $pdo
);

if ($action === 'get_history') {
    $filters = [
        'type'       => $_GET['type']       ?? '',
        'product_id' => (int) ($_GET['product_id'] ?? 0) ?: null,
        'batch_no'   => trim($_GET['batch_no'] ?? ''),
        'user_id'    => (int) ($_GET['user_id'] ?? 0) ?: null,
        'from'       => $_GET['from'] ?? '',
        'to'         => $_GET['to']   ?? '',
        'page'       => max(1, (int) ($_GET['page']  ?? 1)),
        'limit'      => min(100, (int) ($_GET['limit'] ?? 20)),
    ];
    $result = $service->getHistoryPage($filters);
    echo json_encode(array_merge(['success' => true], $result));
    exit;
}

if ($action === 'search_products' && isset($_GET['term'])) {
    echo json_encode($service->searchProducts($_GET['term']));
    exit;
}

if ($action === 'get_users') {
    echo json_encode($service->getActiveUsers());
    exit;
}

if ($action === 'get_batches_detail' && isset($_GET['product_id'])) {
    echo json_encode(
        $service->getBatchesForProduct((int) $_GET['product_id'])
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adjust_stock') {
    requireRole('admin', 'manager');

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$csrf->validate($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
        exit;
    }

    $result = $service->adjustStock(
        user_id:    (int) $_SESSION['user_id'],
        product_id: (int) ($_POST['product_id'] ?? 0),
        batch_no:   trim($_POST['batch_no']    ?? ''),
        actual_qty: (int) ($_POST['actual_qty'] ?? -1),
        reason:     trim($_POST['reason']      ?? '')
    );

    if ($result['success']) {
        $csrf->rotate();
    }

    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action không hợp lệ.']);
exit;