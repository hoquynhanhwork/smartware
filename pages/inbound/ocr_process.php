<?php
// pages/inbound/ocr_process.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

// CSRF
$csrf  = new App\Services\CsrfService();
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$csrf->validate($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? -1;
    echo json_encode(['error' => "Upload thất bại (code $errCode)"]);
    exit;
}

$file = $_FILES['file'];
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
    echo json_encode(['error' => 'Chỉ hỗ trợ file PDF']);
    exit;
}

$AI_BASE = getenv('AI_SERVICE_URL') ?: 'http://localhost:8000/api/v1';
$AI_KEY  = getenv('AI_API_KEY')     ?: 'smartware';

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
    CURLOPT_TIMEOUT    => 120,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'cURL: ' . $err]);
    exit;
}
if ($code !== 200) {
    $detail = json_decode($resp, true);
    echo json_encode(['error' => $detail['detail'] ?? "HTTP $code"]);
    exit;
}

// Trả thẳng JSON từ FastAPI về browser
echo $resp;