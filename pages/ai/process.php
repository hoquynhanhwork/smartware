<?php
// pages/ai/process.php
// Proxy bảo mật: PHP ↔ FastAPI

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$AI_BASE = getenv('AI_SERVICE_URL') ?: 'http://localhost:8000/api/v1';
$AI_KEY  = getenv('AI_API_KEY') ?: '';

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

// Nhả khóa session NGAY: phần dưới có thể chờ AI tới 120s. Nếu giữ khóa, mọi request khác
// của cùng người dùng (chuyển trang, tải bảng…) sẽ bị treo cho tới khi AI trả lời.
// Từ đây trở xuống chỉ ĐỌC $_SESSION, không ghi.
session_write_close();

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
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        error_log('[AI] cURL error: ' . $err);
        return ['error' => 'Không kết nối được AI Service'];
    }

    $decoded = json_decode($resp, true);

    if ($code >= 200 && $code < 300) {
        return is_array($decoded) ? $decoded : ['error' => 'AI Service trả dữ liệu không hợp lệ'];
    }

    // Mọi mã không phải 2xx đều là LỖI (trước đây 403/422 bị trả nguyên JSON {detail} và UI hiểu nhầm là "kho trống")
    error_log("[AI] $endpoint -> HTTP $code: " . substr((string) $resp, 0, 300));
    if ($code === 401 || $code === 403) {
        return ['error' => 'Không xác thực được với AI Service (kiểm tra AI_API_KEY khớp API_SECRET_KEY)'];
    }
    $detail = is_array($decoded) ? ($decoded['detail'] ?? null) : null;   // 422: detail là mảng → không dùng làm thông báo
    return ['error' => (is_string($detail) && $detail !== '') ? $detail : "AI Service lỗi HTTP $code"];
}

switch ($action) {

    // ── Chat Q&A ─────────────────────────────────────────────────
    case 'chat':
        $msg = trim($body['message'] ?? '');
        if (!$msg) { echo json_encode(['error' => 'Thiếu message']); exit; }
        if (mb_strlen($msg) > 1000) {          // giới hạn để tránh đốt token LLM
            http_response_code(413);
            echo json_encode(['error' => 'Câu hỏi quá dài (tối đa 1000 ký tự)'], JSON_UNESCAPED_UNICODE);
            exit;
        }
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

        // dùng $pdo từ config/db.php
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

        // dùng $pdo từ config/db.php
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

        // dùng $pdo từ config/db.php
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
        $stockStatus = $body['stock_status'] ?? null;
        if (!is_string($stockStatus) || $stockStatus === '' || strlen($stockStatus) > 30) {
            $stockStatus = null;               // không chuyển nguyên giá trị client tuỳ ý sang AI Service
        }
        $result = proxyAI('analyze/batch', [
            'supplier_id'  => 0,
            'limit'        => 500,
            'stock_status' => $stockStatus,
        ], $AI_BASE, $AI_KEY);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Action '$action' không hợp lệ"]);
}