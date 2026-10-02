<?php
// app/Services/AuthService.php

namespace App\Services;

use App\Contracts\AuthRepositoryInterface;

class AuthService
{
    private const MAX_ATTEMPTS    = 5;

    private const LOCKOUT_WINDOW  = 900;

    public function __construct(
        private readonly AuthRepositoryInterface $repo,
        private readonly CsrfService             $csrf,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * @param  string|null $token  Giá trị từ $_POST['csrf_token']
     * @return bool
     */
    public function validateCsrf(?string $token): bool
    {
        return $this->csrf->validate($token);
    }

    public function getCsrfToken(): string
    {
        return $this->csrf->getToken();
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function login(string $username, string $password): array
    {
        // ── 1. Validate input ────────────────────────────────────────────────
        if ($username === '' || $password === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.'];
        }

        // ── 2. Rate limiting ─────────────────────────────────────────────────
        $ip             = $this->getClientIp();
        $byUsername     = $this->repo->countRecentFailures($username,    self::LOCKOUT_WINDOW);
        $byIp           = $this->repo->countRecentFailures($ip,          self::LOCKOUT_WINDOW);

        if ($byUsername >= self::MAX_ATTEMPTS || $byIp >= self::MAX_ATTEMPTS) {
            return [
                'ok'      => false,
                'message' => 'Tài khoản tạm thời bị khoá do đăng nhập sai nhiều lần. '
                           . 'Vui lòng thử lại sau 15 phút.',
            ];
        }

        // ── 3. Tìm user ───────────────────────────────────────────────────────
        $user = $this->repo->findByUsername($username);

        // ── 4. Verify password ────────────────────────────────────────────────
        if (!$user || !password_verify($password, $user['password'])) {
            $this->repo->recordFailedLogin($username);
            $this->repo->recordFailedLogin($ip);

            return ['ok' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng.'];
        }

        // ── 5. Kiểm tra trạng thái ───────────────────────────────────────────
        if ($user['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Tài khoản của bạn đã bị vô hiệu hoá. Vui lòng liên hệ quản trị viên.'];
        }

        // ── 6. Đăng nhập thành công ───────────────────────────────────────────
        $this->repo->clearFailures($username);
        $this->repo->clearFailures($ip);
        $this->csrf->rotate();
        $this->startSession($user);
        $this->repo->updateLastLogin($user['id']);
        return ['ok' => true];
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    // ── Helper nội bộ ─────────────────────────────────────────────────────────

    private function startSession(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id']      = $user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['user_name']    = $user['full_name'] ?: $user['username'];
        $_SESSION['user_role']    = $user['role'];
        $_SESSION['user_email']   = $user['email'];
        $_SESSION['user_status']  = $user['status'];
        $_SESSION['logged_in_at'] = time();
    }

    private function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}