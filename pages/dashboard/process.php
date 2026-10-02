<?php
// pages/dashboard/process.php
//
// Dashboard là trang READ-ONLY — chỉ hiển thị dữ liệu, không có form POST.
// File này để trống có chủ ý.
//
// Nếu sau này Dashboard cần xử lý action (ví dụ: "đánh dấu tất cả cảnh báo là đã đọc"),
// thêm vào đây theo đúng pattern:
//
//   require_once __DIR__ . '/../../config/auth.php';
//   require_once __DIR__ . '/../../config/db.php';
//   require_once __DIR__ . '/../../vendor/autoload.php';
//
//   requireLogin();
//
//   use App\Services\DashboardService;
//   use App\Repositories\DashboardRepository;
//   use App\Services\CsrfService;
//
//   if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
//       header('Location: index.php'); exit;
//   }
//
//   $csrf = new CsrfService();
//   if (!$csrf->validate($_POST['csrf_token'] ?? null)) {
//       setFlash('error', 'Yêu cầu không hợp lệ.');
//       header('Location: index.php'); exit;
//   }
//
//   // ... xử lý action ...
//   header('Location: index.php'); exit;