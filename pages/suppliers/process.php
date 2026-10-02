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

    // ── AJAX: Lấy số liệu biểu đồ nhập hàng theo Tháng / Năm ─────────────────
    if ($action === 'get_inbound_chart') {
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        $period     = $_GET['period'] ?? 'month';
        $year       = (int)($_GET['year'] ?? date('Y'));
        $month      = (int)($_GET['month'] ?? date('n'));

        header('Content-Type: application/json');

        if (!$supplierId) {
            echo json_encode(['labels' => [], 'values' => []]);
            exit;
        }

        $labels = [];
        $values = [];

        if ($period === 'month') {
            $monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            $mMap = array_fill(1, 12, 0);

            $stmt = $pdo->prepare("
                SELECT EXTRACT(MONTH FROM created) AS m, COALESCE(SUM(total_amount), 0) AS total 
                FROM stock_inbounds 
                WHERE supplier_id = ? AND deleted_at IS NULL AND EXTRACT(YEAR FROM created) = ?
                GROUP BY EXTRACT(MONTH FROM created)
            ");
            $stmt->execute([$supplierId, $year]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $mMap[(int)$r['m']] = (float)$r['total'];
            }

            $labels = $monthNames;
            $values = array_values($mMap);

        } elseif ($period === 'quarter') {
            $qMap = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
            $stmt = $pdo->prepare("
                SELECT EXTRACT(QUARTER FROM created) AS q, COALESCE(SUM(total_amount), 0) AS total 
                FROM stock_inbounds 
                WHERE supplier_id = ? AND deleted_at IS NULL AND EXTRACT(YEAR FROM created) = ?
                GROUP BY EXTRACT(QUARTER FROM created)
            ");
            $stmt->execute([$supplierId, $year]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $qMap[(int)$r['q']] = (float)$r['total'];
            }

            $labels = ["Quý 1", "Quý 2", "Quý 3", "Quý 4"];
            $values = array_values($qMap);

        } elseif ($period === 'year') {
            $yMap = [];
            for ($y = $year - 4; $y <= $year; $y++) {
                $yMap[$y] = 0;
            }
            $stmt = $pdo->prepare("
                SELECT EXTRACT(YEAR FROM created) AS y, COALESCE(SUM(total_amount), 0) AS total 
                FROM stock_inbounds 
                WHERE supplier_id = ? AND deleted_at IS NULL AND EXTRACT(YEAR FROM created) >= ? AND EXTRACT(YEAR FROM created) <= ?
                GROUP BY EXTRACT(YEAR FROM created)
            ");
            $stmt->execute([$supplierId, $year - 4, $year]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $yKey = (int)$r['y'];
                if (isset($yMap[$yKey])) $yMap[$yKey] = (float)$r['total'];
            }

            $labels = array_map('strval', array_keys($yMap));
            $values = array_values($yMap);

        } elseif ($period === 'week') {
            $labels = ["Tuần 1", "Tuần 2", "Tuần 3", "Tuần 4"];
            $wMap = [0, 0, 0, 0];
            $stmt = $pdo->prepare("
                SELECT EXTRACT(DAY FROM created) AS d, COALESCE(SUM(total_amount), 0) AS total 
                FROM stock_inbounds 
                WHERE supplier_id = ? AND deleted_at IS NULL 
                  AND EXTRACT(YEAR FROM created) = ? AND EXTRACT(MONTH FROM created) = ?
                GROUP BY EXTRACT(DAY FROM created)
            ");
            $stmt->execute([$supplierId, $year, $month]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $day = (int)$r['d'];
                $wIdx = min(3, (int)(($day - 1) / 7));
                $wMap[$wIdx] += (float)$r['total'];
            }
            $values = $wMap;
        }

        echo json_encode(['labels' => $labels, 'values' => $values]);
        exit;
    }

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
    $supplierId = (int) ($_POST['id'] ?? 0);
    $result     = $service->edit($supplierId, $_POST);

    if ($result['ok']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (str_contains($referer, 'detail.php')) {
        header('Location: detail.php?id=' . $supplierId);
    } else {
        header('Location: index.php');
    }
    exit;
}

// ── XÓA / NGỪNG HỢP TÁC
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

header('Location: index.php');
exit;