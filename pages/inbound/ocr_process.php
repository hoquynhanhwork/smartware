<?php
// pages/inbound/ocr_process.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

const OCR_MAX_BYTES = 20 * 1024 * 1024;   // phải khớp MAX_SIZE ở ai-service/app/routers/ocr.py

// FastAPI có thể mất tới ~2 phút (2 lần gọi GPT x 60s + backoff) → cho PHP chờ lâu hơn.
@set_time_limit(170);

function ocr_fail(string $message, int $http = 400): never
{
    http_response_code($http);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Body vượt post_max_size → PHP bỏ trống cả $_POST lẫn $_FILES, CSRF sẽ báo sai nguyên nhân → báo đúng ở đây
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    ocr_fail('File vượt giới hạn upload của máy chủ (post_max_size = ' . ini_get('post_max_size') . ')', 413);
}

// CSRF
$csrf  = new App\Services\CsrfService();
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$csrf->validate($token)) {
    ocr_fail('Invalid CSRF token', 403);
}

// ── Kiểm tra upload ─────────────────────────────────────────────────────────
$errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($errCode !== UPLOAD_ERR_OK) {
    $msg = match ($errCode) {
        UPLOAD_ERR_INI_SIZE   => 'File vượt giới hạn upload của máy chủ (upload_max_filesize = '
                                 . ini_get('upload_max_filesize') . '). Hãy nhờ quản trị tăng giới hạn hoặc nén nhỏ file PDF.',
        UPLOAD_ERR_FORM_SIZE  => 'File quá lớn (tối đa 20MB)',
        UPLOAD_ERR_PARTIAL    => 'File upload chưa hoàn tất, vui lòng thử lại',
        UPLOAD_ERR_NO_FILE    => 'Chưa chọn file, hoặc file vượt giới hạn post_max_size = ' . ini_get('post_max_size'),
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION  => "Máy chủ không lưu được file tạm (code $errCode)",
        default               => "Upload thất bại (code $errCode)",
    };
    ocr_fail($msg, $errCode === UPLOAD_ERR_INI_SIZE ? 413 : 400);
}

$file = $_FILES['file'];

if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
    ocr_fail('Chỉ hỗ trợ file PDF');
}
if (!is_uploaded_file($file['tmp_name'])) {
    ocr_fail('File upload không hợp lệ');
}
if ($file['size'] <= 0) {
    ocr_fail('File PDF rỗng');
}
if ($file['size'] > OCR_MAX_BYTES) {
    ocr_fail('File quá lớn (tối đa 20MB)', 413);
}

// Kiểm tra nội dung thật (chữ ký PDF), không chỉ đuôi file
$fh   = fopen($file['tmp_name'], 'rb');
$head = $fh ? fread($fh, 1024) : '';
if ($fh) fclose($fh);
if (!str_contains($head, '%PDF-')) {
    ocr_fail('File không phải PDF hợp lệ');
}

// ── Gọi AI service ──────────────────────────────────────────────────────────
$AI_BASE = getenv('AI_SERVICE_URL') ?: 'http://localhost:8000/api/v1';
$AI_KEY  = getenv('AI_API_KEY') ?: '';

$ch = curl_init("$AI_BASE/ocr/invoice");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'file' => new CURLFile(
            $file['tmp_name'],
            'application/pdf',
            $file['name']
        ),
    ],
    CURLOPT_HTTPHEADER => [
        "X-API-Key: $AI_KEY",
        // KHÔNG set Content-Type — curl tự set multipart/form-data + boundary
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 150,
]);

$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false || $err) {
    error_log('[OCR] cURL error: ' . $err);
    ocr_fail('Không kết nối được AI service (' . ($err ?: 'không rõ lỗi') . ')', 502);
}

$decoded = json_decode($resp, true);

if ($code !== 200) {
    $detail = is_array($decoded) ? ($decoded['detail'] ?? null) : null;
    // FastAPI trả detail là mảng khi lỗi validate (422) → không dùng trực tiếp làm thông báo
    if (!is_string($detail) || $detail === '') {
        $detail = "AI service trả lỗi HTTP $code";
    }
    if ($code === 403) {
        error_log('[OCR] AI service từ chối API key (kiểm tra AI_API_KEY của PHP khớp API_SECRET_KEY của FastAPI)');
        $detail = 'Không xác thực được với AI service (kiểm tra cấu hình API key)';
    }
    ocr_fail($detail, in_array($code, [400, 413, 422], true) ? 400 : 502);
}

if (!is_array($decoded)) {
    error_log('[OCR] Phản hồi không phải JSON: ' . substr($resp, 0, 300));
    ocr_fail('AI service trả dữ liệu không hợp lệ', 502);
}

// Trả JSON từ FastAPI về browser
echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
