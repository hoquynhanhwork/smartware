<?php
// pages/outbound/process.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\OutboundService;
use App\Services\CsrfService;
use App\Repositories\OutboundRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;

function triggerInventoryAlert(PDO $pdo, array $post): void {
    // Lấy danh sách sản phẩm vừa xuất
    $items = $post['items'] ?? [];
    if (empty($items)) return;

    foreach ($items as $item) {
        $product_id = (int) ($item['product_id'] ?? 0);
        if (!$product_id) continue;

        // Lấy tồn kho hiện tại + min_stock + thông tin NCC
        $stmt = $pdo->prepare("
            SELECT 
                p.name          as product_name,
                p.min_stock,
                COALESCE(SUM(b.quantity), 0) as current_stock,
                s.name          as supplier_name,
                s.email         as supplier_email
            FROM products p
            LEFT JOIN inventory_batches b ON b.product_id = p.id
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.id = :product_id
            GROUP BY p.id, p.name, p.min_stock, s.name, s.email
        ");
        $stmt->execute([
            ':product_id' => $product_id,
        ]);
        $row = $stmt->fetch();

        if (!$row) continue;

        // Chỉ trigger nếu tồn kho dưới ngưỡng
        if ($row['current_stock'] >= $row['min_stock']) continue;

        // Gọi webhook n8n
        $payload = json_encode([
            'product_id'     => $product_id,
            'product_name'   => $row['product_name'],
            'quantity'       => (int) $row['current_stock'],
            'min_stock'      => (int) $row['min_stock'],
            'supplier_name'  => $row['supplier_name'] ?? 'Chưa có NCC',
            'supplier_email' => $row['supplier_email'] ?? '',
            'triggered_at'   => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('http://n8n:5678/webhook/inventory-alert');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
}
requireLogin();

$user_id = (int) $_SESSION['user_id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$csrf    = new CsrfService();

$service = new OutboundService(
    new OutboundRepository($pdo),
    new ProductRepository($pdo),
    new CategoryRepository($pdo),
    $pdo
);

// =========================================================================
// GET — read-only, không cần CSRF
// =========================================================================

if ($action === 'search_products' && isset($_GET['term'])) {
    header('Content-Type: application/json');
    echo json_encode($service->searchProducts(trim($_GET['term'])));
    exit;
}

if ($action === 'get_batches' && isset($_GET['product_id'])) {
    header('Content-Type: application/json');
    echo json_encode($service->getBatchesForProduct((int) $_GET['product_id']));
    exit;
}

if ($action === 'get_outbound_detail' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $result = $service->getDetail((int) $_GET['id']);
    echo json_encode($result
        ? array_merge(['success' => true], $result)
        : ['success' => false, 'message' => 'Không tìm thấy phiếu xuất']
    );
    exit;
}

// =========================================================================
// POST — tất cả write actions
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
          || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

$submittedToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$csrf->validate($submittedToken)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'ok' => false, 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
        exit;
    }
    setFlash('error', 'Phiên làm việc hết hạn, vui lòng thử lại.');
    header('Location: index.php');
    exit;
}

// =========================================================================
// AJAX POST phụ — KHÔNG rotate token
// Lý do: user có thể thêm sản phẩm/danh mục trước khi submit phiếu chính.
// Nếu rotate thì token trong outboundForm bị stale → submit phiếu fail 403.
// =========================================================================

if ($action === 'add_product') {
    header('Content-Type: application/json');
    echo json_encode($service->addProduct($_POST));
    // Không rotate
    exit;
}

if ($action === 'add_category') {
    header('Content-Type: application/json');
    echo json_encode($service->addCategory(
        $_POST['name']        ?? '',
        $_POST['description'] ?? ''
    ));
    // Không rotate
    exit;
}

// =========================================================================
// Form POST chính — rotate token sau khi xử lý
// =========================================================================

if ($action === 'add_outbound' || $action === 'add') {
    $result = $service->add($user_id, $_POST);
    $csrf->rotate();
    if ($result['ok']) {
        triggerInventoryAlert($pdo, $_POST);
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }
    header('Location: ' . ($result['redirect'] ?? 'index.php'));
    exit;
}

if ($action === 'edit_outbound' || $action === 'edit') {
    $id     = (int) ($_POST['outbound_id'] ?? $_POST['id'] ?? 0);
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

if ($action === 'delete_outbound' || $action === 'delete') {
    $id     = (int) ($_POST['id'] ?? $_POST['outbound_id'] ?? 0);
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

// Fallback
header('Location: index.php');
exit;