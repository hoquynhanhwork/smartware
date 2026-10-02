<?php
// app/Services/SettingService.php

namespace App\Services;

use App\Contracts\SettingRepositoryInterface;

class SettingService
{
    // Config keys dùng trong hệ thống
    public const KEY_ALERT_EXPIRY_DAYS    = 'alert_expiry_days';
    public const KEY_ALERT_LOW_STOCK      = 'alert_low_stock_enabled';
    public const KEY_ALERT_OVERSTOCK      = 'alert_overstock_enabled';
    public const KEY_INBOUND_PREFIX       = 'inbound_ref_prefix';
    public const KEY_OUTBOUND_PREFIX      = 'outbound_ref_prefix';
    public const KEY_DEFAULT_PAYMENT_DAYS = 'default_payment_terms_days';

    // Default values
    private const DEFAULTS = [
        self::KEY_ALERT_EXPIRY_DAYS    => '30',
        self::KEY_ALERT_LOW_STOCK      => '1',
        self::KEY_ALERT_OVERSTOCK      => '1',
        self::KEY_INBOUND_PREFIX       => 'PN',
        self::KEY_OUTBOUND_PREFIX      => 'PX',
        self::KEY_DEFAULT_PAYMENT_DAYS => '30',
    ];

    public function __construct(
        private readonly SettingRepositoryInterface $repo,
    ) {}

    // ── Users ─────────────────────────────────────────────────────────────

    public function listUsers(): array
    {
        return $this->repo->listUsers();
    }

    public function addUser(array $input): array
    {
        $username  = trim($input['username']  ?? '');
        $full_name = trim($input['full_name'] ?? '');
        $password  = $input['password']       ?? '';
        $role      = $input['role']           ?? 'staff';
        $email     = trim($input['email']     ?? '');

        if ($username === '' || $full_name === '' || $password === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập đầy đủ tên đăng nhập, họ tên và mật khẩu.'];
        }
        if (!in_array($role, ['admin', 'manager', 'staff'])) {
            return ['ok' => false, 'message' => 'Vai trò không hợp lệ.'];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'message' => 'Mật khẩu phải có ít nhất 6 ký tự.'];
        }
        if ($this->repo->findUserByUsername($username)) {
            return ['ok' => false, 'message' => 'Tên đăng nhập đã tồn tại.'];
        }
        if ($email !== '' && $this->repo->findUserByEmail($email)) {
            return ['ok' => false, 'message' => 'Email đã tồn tại.'];
        }

        $id = $this->repo->createUser([
            'username'   => $username,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'full_name'  => $full_name,
            'email'      => $email ?: null,
            'role'       => $role,
        ]);

        return ['ok' => true, 'message' => "Đã tạo tài khoản \"$username\" thành công.", 'id' => $id];
    }

    public function editUser(int $id, array $input): array
    {
        if (!$this->repo->findUser($id)) {
            return ['ok' => false, 'message' => 'Không tìm thấy người dùng.'];
        }

        $full_name = trim($input['full_name'] ?? '');
        $role      = $input['role']           ?? 'staff';
        $email     = trim($input['email']     ?? '');

        if ($full_name === '') {
            return ['ok' => false, 'message' => 'Họ tên không được để trống.'];
        }
        if (!in_array($role, ['admin', 'manager', 'staff'])) {
            return ['ok' => false, 'message' => 'Vai trò không hợp lệ.'];
        }
        if ($email !== '' && $this->repo->findUserByEmail($email, $id)) {
            return ['ok' => false, 'message' => 'Email đã được dùng bởi tài khoản khác.'];
        }

        $this->repo->updateUser($id, [
            'full_name' => $full_name,
            'email'     => $email ?: null,
            'role'      => $role,
        ]);

        return ['ok' => true, 'message' => 'Cập nhật tài khoản thành công.'];
    }

    public function toggleUserStatus(int $id, int $currentUserId): array
    {
        if ($id === $currentUserId) {
            return ['ok' => false, 'message' => 'Không thể khóa tài khoản đang đăng nhập.'];
        }

        $user = $this->repo->findUser($id);
        if (!$user) {
            return ['ok' => false, 'message' => 'Không tìm thấy người dùng.'];
        }

        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        $this->repo->setUserStatus($id, $newStatus);

        $label = $newStatus === 'active' ? 'Đã mở khóa' : 'Đã khóa';
        return ['ok' => true, 'message' => "$label tài khoản \"{$user['username']}\"."];
    }

    public function resetPassword(int $id, array $input): array
    {
        if (!$this->repo->findUser($id)) {
            return ['ok' => false, 'message' => 'Không tìm thấy người dùng.'];
        }

        $newPassword = $input['new_password'] ?? '';
        if (strlen($newPassword) < 6) {
            return ['ok' => false, 'message' => 'Mật khẩu mới phải có ít nhất 6 ký tự.'];
        }

        $this->repo->updatePassword($id, password_hash($newPassword, PASSWORD_BCRYPT));
        return ['ok' => true, 'message' => 'Đặt lại mật khẩu thành công.'];
    }

    // ── Profile (chính mình) ──────────────────────────────────────────────

    public function updateProfile(int $user_id, array $input): array
    {
        $full_name = trim($input['full_name'] ?? '');
        $email     = trim($input['email']     ?? '');

        if ($full_name === '') {
            return ['ok' => false, 'message' => 'Họ tên không được để trống.'];
        }
        if ($email !== '' && $this->repo->findUserByEmail($email, $user_id)) {
            return ['ok' => false, 'message' => 'Email đã được dùng bởi tài khoản khác.'];
        }

        $user = $this->repo->findUser($user_id);
        $this->repo->updateUser($user_id, [
            'full_name' => $full_name,
            'email'     => $email ?: null,
            'role'      => $user['role'], // giữ nguyên role
        ]);

        // Cập nhật session
        $_SESSION['user_name'] = $full_name;

        return ['ok' => true, 'message' => 'Cập nhật hồ sơ thành công.'];
    }

    public function changePassword(int $user_id, array $input): array
    {
        $old = $input['old_password'] ?? '';
        $new = $input['new_password'] ?? '';
        $cfm = $input['confirm_password'] ?? '';

        if ($old === '' || $new === '' || $cfm === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin.'];
        }
        if ($new !== $cfm) {
            return ['ok' => false, 'message' => 'Mật khẩu xác nhận không khớp.'];
        }
        if (strlen($new) < 6) {
            return ['ok' => false, 'message' => 'Mật khẩu mới phải có ít nhất 6 ký tự.'];
        }

        $hashed = $this->repo->getUserPassword($user_id);
        if (!$hashed || !password_verify($old, $hashed)) {
            return ['ok' => false, 'message' => 'Mật khẩu hiện tại không đúng.'];
        }

        $this->repo->updatePassword($user_id, password_hash($new, PASSWORD_BCRYPT));
        return ['ok' => true, 'message' => 'Đổi mật khẩu thành công.'];
    }

    // ── Configurations ────────────────────────────────────────────────────

    public function getConfigs(): array
    {
        $keys   = array_keys(self::DEFAULTS);
        $stored = $this->repo->getManyConfigs($keys);

        // Merge defaults
        foreach (self::DEFAULTS as $k => $default) {
            if ($stored[$k] === null) $stored[$k] = $default;
        }
        return $stored;
    }

    public function updateConfigs(array $input): array
    {
        $data = [];

        $expiryDays = (int)($input[self::KEY_ALERT_EXPIRY_DAYS] ?? 30);
        if ($expiryDays < 1 || $expiryDays > 90) {
            return ['ok' => false, 'message' => 'Số ngày cảnh báo hết hạn phải từ 1 đến 90.'];
        }
        $data[self::KEY_ALERT_EXPIRY_DAYS] = (string) $expiryDays;

        $payDays = (int)($input[self::KEY_DEFAULT_PAYMENT_DAYS] ?? 30);
        if ($payDays < 0 || $payDays > 365) {
            return ['ok' => false, 'message' => 'Số ngày thanh toán phải từ 0 đến 365.'];
        }
        $data[self::KEY_DEFAULT_PAYMENT_DAYS] = (string) $payDays;

        $inPrefix = trim($input[self::KEY_INBOUND_PREFIX]  ?? 'PN');
        $outPrefix = trim($input[self::KEY_OUTBOUND_PREFIX] ?? 'PX');
        if ($inPrefix === '' || $outPrefix === '') {
            return ['ok' => false, 'message' => 'Prefix mã phiếu không được để trống.'];
        }
        $data[self::KEY_INBOUND_PREFIX]  = strtoupper($inPrefix);
        $data[self::KEY_OUTBOUND_PREFIX] = strtoupper($outPrefix);

        $data[self::KEY_ALERT_LOW_STOCK] = isset($input[self::KEY_ALERT_LOW_STOCK]) ? '1' : '0';
        $data[self::KEY_ALERT_OVERSTOCK] = isset($input[self::KEY_ALERT_OVERSTOCK]) ? '1' : '0';

        $this->repo->setManyConfigs($data);
        return ['ok' => true, 'message' => 'Cập nhật cấu hình thành công.'];
    }

    // ── Alerts ────────────────────────────────────────────────────────────

    public function getAlerts(bool $unreadOnly = false): array
    {
        return $this->repo->listAlerts($unreadOnly);
    }

    public function markRead(int $id): array
    {
        $this->repo->markAlertRead($id);
        return ['ok' => true];
    }

    public function markAllRead(): array
    {
        $this->repo->markAllAlertsRead();
        return ['ok' => true, 'message' => 'Đã đánh dấu tất cả cảnh báo là đã đọc.'];
    }

    public function resolveAlert(int $id, int $user_id): array
    {
        $this->repo->resolveAlert($id, $user_id);
        return ['ok' => true, 'message' => 'Đã xử lý cảnh báo.'];
    }

    public function countUnreadAlerts(): int
    {
        return $this->repo->countUnread();
    }

    public function findUser(int $user_id): array|false
    {
        return $this->repo->findUser($user_id);
    }
}