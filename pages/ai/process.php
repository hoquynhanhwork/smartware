<?php
// pages/ai/process.php
// Proxy bảo mật: PHP ↔ FastAPI

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$AI_BASE = getenv('AI_SERVICE_URL') ?: 'http://localhost:8000/api/v1';
$AI_KEY  = getenv('AI_API_KEY')     ?: 'smartware';

$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?? [];
$action = $body['action'] ?? ($_GET['action'] ?? '');

// CSRF — chỉ validate action write, bỏ qua chat (read-only)
$csrfService  = new App\Services\CsrfService();
$writeActions = ['replenishment', 'forecast_advanced', 'risks', 'trends'];
if (in_array($action, $writeActions)) {
    $token = $body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$csrfService->validate($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

/**
 * Proxy call tới FastAPI.
 */
function proxyAI(string $endpoint, array $payload, string $base, string $key): array {
    $ch = curl_init("$base/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            "X-API-Key: $key",
        ],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $code >= 500) {
        return ['error' => "AI Service lỗi HTTP $code"];
    }
    if ($code === 400) {
        $detail = json_decode($resp, true);
        return ['error' => $detail['detail'] ?? 'Bad Request'];
    }
    return json_decode($resp, true) ?? ['error' => 'Invalid JSON from AI'];
}

switch ($action) {

    // ── Chat Q&A ─────────────────────────────────────────────────
    case 'chat':
        $msg = trim($body['message'] ?? '');
        if (!$msg) { echo json_encode(['error' => 'Thiếu message']); exit; }
        $result = proxyAI('chat', [
            'message'    => $msg,
            'context'    => null,
        ], $AI_BASE, $AI_KEY);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // ── Phân tích rủi ro đơn lẻ ──────────────────────────────────
    case 'risks':
        $pid = (int)($body['product_id'] ?? 0);
        if (!$pid) { echo json_encode(['error' => 'Thiếu product_id']); exit; }

        $pdo  = getPDO();
        $stmt = $pdo->prepare("
            SELECT product_name, current_stock, min_stock, max_stock,
                   avg_daily_out, estimated_days_left, days_to_nearest_exp,
                   nearest_exp_date
            FROM vw_stock_summary_for_ai
            WHERE product_id = :pid
        ");
        $stmt->execute([':pid' => $pid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['error' => 'Không tìm thấy sản phẩm']); exit; }

        $result = proxyAI('risks', [
            'product_id'          => $pid,
            'product_name'        => $p['product_name'],
            'current_stock'       => (int)$p['current_stock'],
            'min_stock'           => (int)$p['min_stock'],
            'max_stock'           => (int)$p['max_stock'],
            'avg_daily_out'       => (float)$p['avg_daily_out'],
            'estimated_days_left' => $p['estimated_days_left'] !== null ? (float)$p['estimated_days_left'] : null,
            'days_to_nearest_exp' => $p['days_to_nearest_exp'] !== null ? (int)$p['days_to_nearest_exp']   : null,
            'nearest_exp_date'    => $p['nearest_exp_date'] ?: null,
            'sales_history'       => [],
        ], $AI_BASE, $AI_KEY);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // ── Xu hướng tiêu thụ ────────────────────────────────────────
    case 'trends':
        $pid  = (int)($body['product_id']   ?? 0);
        $name = $body['product_name'] ?? '';
        if (!$pid) { echo json_encode(['error' => 'Thiếu product_id']); exit; }
        $result = proxyAI('trends', [
            'product_id'   => $pid,
            'product_name' => $name,
        ], $AI_BASE, $AI_KEY);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // ── Đề xuất nhập hàng ────────────────────────────────────────
    case 'replenishment':
        $pid = (int)($body['product_id'] ?? 0);
        if (!$pid) { echo json_encode(['error' => 'Thiếu product_id']); exit; }

        $pdo  = getPDO();
        $stmt = $pdo->prepare("
            SELECT product_name, current_stock, min_stock, max_stock,
                   avg_daily_out, estimated_days_left, nearest_exp_date, cost_price
            FROM vw_stock_summary_for_ai
            WHERE product_id = :pid
        ");
        $stmt->execute([':pid' => $pid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['error' => 'Không tìm thấy sản phẩm']); exit; }

        $result = proxyAI('replenishment', [
            'product_id'          => $pid,
            'product_name'        => $p['product_name'],
            'current_stock'       => (int)$p['current_stock'],
            'min_stock'           => (int)$p['min_stock'],
            'max_stock'           => (int)$p['max_stock'],
            'avg_daily_out'       => (float)$p['avg_daily_out'],
            'estimated_days_left' => $p['estimated_days_left'] !== null ? (float)$p['estimated_days_left'] : null,
            'nearest_exp_date'    => $p['nearest_exp_date'] ?: null,
            'cost_price'          => $p['cost_price'] ? (float)$p['cost_price'] : null,
        ], $AI_BASE, $AI_KEY);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // ── Dự báo Prophet ───────────────────────────────────────────
    case 'forecast_advanced':
        $pid = (int)($body['product_id'] ?? 0);
        if (!$pid) { echo json_encode(['error' => 'Thiếu product_id']); exit; }

        $pdo  = getPDO();
        $stmt = $pdo->prepare("
            SELECT product_name, current_stock, min_stock, max_stock
            FROM vw_stock_summary_for_ai
            WHERE product_id = :pid
        ");
        $stmt->execute([':pid' => $pid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['error' => 'Không tìm thấy sản phẩm']); exit; }

        $result = proxyAI('forecast/advanced', [
            'product_id'   => $pid,
            'product_name' => $p['product_name'],
            'current_stock' => (int)$p['current_stock'],
            'min_stock'    => (int)$p['min_stock'],
            'max_stock'    => (int)$p['max_stock'],
        ], $AI_BASE, $AI_KEY);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // ── Batch analyze ─────────────────────────────────────────────
    case 'batch_analyze':
        $result = proxyAI('analyze/batch', [
        'supplier_id'  => 0,  
        'limit'        => 500,
        'stock_status' => $body['stock_status'] ?? null,
        ], $AI_BASE, $AI_KEY);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Action '$action' không hợp lệ"]);
}