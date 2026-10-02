<?php
// pages/settings/process.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\SettingRepository;
use App\Services\SettingService;
use App\Services\CsrfService;

requireLogin();

$user_id    = (int) $_SESSION['user_id'];
$action     = $_POST['action'] ?? '';
$csrf       = new CsrfService();

$service = new SettingService(new SettingRepository($pdo));

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
          || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php'); exit;
}

// CSRF
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$csrf->validate($token)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Phiên làm việc hết hạn.']);
        exit;
    }
    setFlash('error', 'Phiên làm việc hết hạn. Vui lòng thử lại.');
    header('Location: users.php'); exit;
}

// ── Users (admin only) ────────────────────────────────────────────────────
if ($action === 'add_user') {
    requireRole('admin');
    $result = $service->addUser($_POST);
    header('Content-Type: application/json');
    echo json_encode($result); exit;
}

if ($action === 'edit_user') {
    requireRole('admin');
    $id     = (int)($_POST['id'] ?? 0);
    $result = $service->editUser($id, $_POST);
    header('Content-Type: application/json');
    echo json_encode($result); exit;
}

if ($action === 'toggle_user_status') {
    requireRole('admin');
    $id     = (int)($_POST['id'] ?? 0);
    $result = $service->toggleUserStatus($id, $user_id);
    header('Content-Type: application/json');
    echo json_encode($result); exit;
}

if ($action === 'reset_password') {
    requireRole('admin');
    $id     = (int)($_POST['id'] ?? 0);
    $result = $service->resetPassword($id, $_POST);
    header('Content-Type: application/json');
    echo json_encode($result); exit;
}

// ── Profile (tất cả roles) ────────────────────────────────────────────────
if ($action === 'update_profile') {
    $result = $service->updateProfile($user_id, $_POST);
    setFlash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: profile.php'); exit;
}

if ($action === 'change_password') {
    $result = $service->changePassword($user_id, $_POST);
    setFlash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: profile.php'); exit;
}

// ── Configurations (admin only) ───────────────────────────────────────────
if ($action === 'update_configs') {
    requireRole('admin');
    $result = $service->updateConfigs($_POST);
    setFlash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: configurations.php'); exit;
}

// ── Alerts (admin + manager) ──────────────────────────────────────────────
if ($action === 'mark_alert_read') {
    requireRole('admin', 'manager');
    $id     = (int)($_POST['id'] ?? 0);
    $result = $service->markRead($id);
    header('Content-Type: application/json');
    echo json_encode($result); exit;
}

if ($action === 'mark_all_read') {
    requireRole('admin', 'manager');
    $result = $service->markAllRead();
    setFlash('success', $result['message']);
    header('Location: alerts.php'); exit;
}

if ($action === 'resolve_alert') {
    requireRole('admin', 'manager');
    $id     = (int)($_POST['id'] ?? 0);
    $result = $service->resolveAlert($id, $user_id);
    header('Content-Type: application/json');
    echo json_encode($result); exit;
}

header('Location: users.php'); exit;
