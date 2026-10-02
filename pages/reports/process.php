<?php
// pages/reports/process.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\ReportRepository;
use App\Services\ReportService;

requireLogin();
header('Content-Type: application/json');

$report     = $_GET['report'] ?? '';

$service = new ReportService(new ReportRepository($pdo));
[$from, $to] = $service->parsePeriod($_GET);

switch ($report) {
    case 'overview':
        echo json_encode(['success' => true, 'data' => $service->getOverviewData($from, $to)]);
        break;

    case 'inventory':
        $days = min(90, max(7, (int)($_GET['days'] ?? 30)));
        echo json_encode(['success' => true, 'data' => $service->getInventoryData($days)]);
        break;

    case 'products':
        echo json_encode(['success' => true, 'data' => $service->getProductData($from, $to, $_GET)]);
        break;

    case 'suppliers':
        echo json_encode(['success' => true, 'data' => $service->getSupplierData($from, $to, $_GET)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Report không hợp lệ.']);
}
exit;