[1mdiff --git a/config/auth.php b/config/auth.php[m
[1mindex aedc74b..7d60dcb 100644[m
[1m--- a/config/auth.php[m
[1m+++ b/config/auth.php[m
[36m@@ -73,6 +73,29 @@[m [mfunction requireRole(string ...$roles): void {[m
     }[m
 }[m
 [m
[32m+[m[32m// ─── Chặn thao tác theo role (hỗ trợ cả AJAX lẫn form thường) ───────────[m
[32m+[m[32m// Khác requireRole(): với AJAX trả về 403 JSON thay vì chuyển hướng.[m
[32m+[m[32mfunction denyUnlessRole(string ...$roles): void {[m
[32m+[m[32m    requireLogin();[m
[32m+[m[32m    if (hasRole(...$roles)) {[m
[32m+[m[32m        return;[m
[32m+[m[32m    }[m
[32m+[m[32m    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])[m
[32m+[m[32m           || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');[m
[32m+[m[32m    if ($isAjax) {[m
[32m+[m[32m        http_response_code(403);[m
[32m+[m[32m        header('Content-Type: application/json');[m
[32m+[m[32m        echo json_encode([[m
[32m+[m[32m            'ok' => false, 'success' => false,[m
[32m+[m[32m            'message' => 'Bạn không có quyền thực hiện thao tác này.',[m
[32m+[m[32m        ]);[m
[32m+[m[32m        exit;[m
[32m+[m[32m    }[m
[32m+[m[32m    setFlash('error', 'Bạn không có quyền thực hiện thao tác này.');[m
[32m+[m[32m    header('Location: ' . BASE_URL . '/pages/dashboard/index.php');[m
[32m+[m[32m    exit;[m
[32m+[m[32m}[m
[32m+[m
 // ─── Flash message ────────────────────────────────────────────────────────[m
 function setFlash(string $type, string $message): void {[m
     // $type: 'success' | 'error' | 'warning' | 'info'[m
