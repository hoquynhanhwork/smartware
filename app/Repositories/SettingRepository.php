<?php
// app/Repositories/SettingRepository.php

namespace App\Repositories;

use App\Contracts\SettingRepositoryInterface;

class SettingRepository extends BaseRepository implements SettingRepositoryInterface
{
    // ── Configurations ────────────────────────────────────────────────────

    public function getConfig(string $key, mixed $default = null): mixed
    {
        $row = $this->fetchOne("
            SELECT config_value FROM configurations
            WHERE config_key = :key
        ", [':key' => $key]);

        return $row ? $row['config_value'] : $default;
    }

    public function getManyConfigs(array $keys): array
    {
        if (empty($keys)) return [];

        $ph = implode(',', array_map(fn($i) => ":k$i", array_keys($keys)));
        $params = [];
        foreach ($keys as $i => $k) $params[":k$i"] = $k;

        $rows = $this->fetchAll("
            SELECT config_key, config_value FROM configurations
            WHERE config_key IN ($ph)
        ", $params);

        $result = array_fill_keys($keys, null);
        foreach ($rows as $r) $result[$r['config_key']] = $r['config_value'];
        return $result;
    }

    public function setConfig(string $key, string $value, string $description = ''): void
    {
        $this->execute("
            INSERT INTO configurations (config_key, config_value, description, updated)
            VALUES (:key, :val, :desc, CURRENT_TIMESTAMP)
            ON CONFLICT (config_key)
            DO UPDATE SET config_value = EXCLUDED.config_value,
                          updated      = CURRENT_TIMESTAMP
        ", [':key' => $key, ':val' => $value, ':desc' => $description]);
    }

    public function setManyConfigs(array $data): void
    {
        // $data = ['key' => 'value', ...]
        foreach ($data as $key => $value) {
            $this->setConfig((string) $key, (string) $value);
        }
    }

    // ── Users ─────────────────────────────────────────────────────────────

    public function listUsers(): array
    {
        return $this->fetchAll("
            SELECT id, username, full_name, email, role, status,
                   last_login_at, created
            FROM users
            WHERE deleted_at IS NULL
            ORDER BY
                CASE role WHEN 'admin' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,
                full_name ASC
        ");
    }

    public function findUser(int $id): array|false
    {
        return $this->fetchOne("
            SELECT id, username, full_name, email, role, status, last_login_at, created
            FROM users
            WHERE id = :id AND deleted_at IS NULL
        ", [':id' => $id]);
    }

    public function findUserByUsername(string $username, int $excludeId = 0): array|false
    {
        $sql    = "SELECT id FROM users WHERE username = :u AND deleted_at IS NULL";
        $params = [':u' => $username];
        if ($excludeId > 0) { $sql .= " AND id != :ex"; $params[':ex'] = $excludeId; }
        return $this->fetchOne($sql, $params);
    }

    public function findUserByEmail(string $email, int $excludeId = 0): array|false
    {
        $sql    = "SELECT id FROM users WHERE email = :e AND deleted_at IS NULL";
        $params = [':e' => $email];
        if ($excludeId > 0) { $sql .= " AND id != :ex"; $params[':ex'] = $excludeId; }
        return $this->fetchOne($sql, $params);
    }

    public function createUser(array $data): int
    {
        return $this->insertReturningId("
            INSERT INTO users
                (username, password, full_name, email, role, status)
            VALUES
                (:username, :password, :full_name, :email, :role, 'active')
            RETURNING id
        ", [
            ':username'  => $data['username'],
            ':password'  => $data['password'],
            ':full_name' => $data['full_name'],
            ':email'     => $data['email']    ?? null,
            ':role'      => $data['role'],
        ]);
    }

    public function updateUser(int $id, array $data): void
    {
        $this->execute("
            UPDATE users
            SET full_name = :full_name,
                email     = :email,
                role      = :role,
                updated   = CURRENT_TIMESTAMP
            WHERE id = :id AND deleted_at IS NULL
        ", [
            ':full_name' => $data['full_name'],
            ':email'     => $data['email'] ?? null,
            ':role'      => $data['role'],
            ':id'        => $id,
        ]);
    }

    public function setUserStatus(int $id, string $status): void
    {
        $this->execute("
            UPDATE users SET status = :status, updated = CURRENT_TIMESTAMP
            WHERE id = :id
        ", [':status' => $status, ':id' => $id]);
    }

    public function updatePassword(int $id, string $hashed): void
    {
        $this->execute("
            UPDATE users SET password = :pw, updated = CURRENT_TIMESTAMP WHERE id = :id
        ", [':pw' => $hashed, ':id' => $id]);
    }

    public function getUserPassword(int $id): string|false
    {
        $row = $this->fetchOne("SELECT password FROM users WHERE id = :id", [':id' => $id]);
        return $row ? $row['password'] : false;
    }

    // ── Alerts ────────────────────────────────────────────────────────────

    public function listAlerts(bool $unreadOnly = false, int $limit = 50): array
    {
        $cond = "1 = 1";
        if ($unreadOnly) $cond = "a.is_read = false AND a.resolved_at IS NULL";

        return $this->fetchAll("
            SELECT a.*, p.name AS product_name, p.sku
            FROM alerts a
            JOIN products p ON p.id = a.product_id
            WHERE $cond
            ORDER BY
                CASE a.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                a.created DESC
            LIMIT :lim
        ", [':lim' => $limit]);
    }

    public function markAlertRead(int $id): void
    {
        $this->execute("
            UPDATE alerts SET is_read = true WHERE id = :id
        ", [':id' => $id]);
    }

    public function markAllAlertsRead(): void
    {
        $this->execute("
            UPDATE alerts SET is_read = true
            WHERE is_read = false
        ");
    }

    public function resolveAlert(int $id, int $user_id): void
    {
        $this->execute("
            UPDATE alerts
            SET is_read = true, resolved_at = CURRENT_TIMESTAMP, resolved_by = :uid
            WHERE id = :id
        ", [':uid' => $user_id, ':id' => $id]);
    }

    public function countUnread(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM alerts
            WHERE is_read = false AND resolved_at IS NULL
        ");
    }
}