<?php
// app/Contracts/SettingRepositoryInterface.php

namespace App\Contracts;

interface SettingRepositoryInterface
{
    // ── Configurations (key-value) ────────────────────────────────────────
    public function getConfig(string $key, mixed $default = null): mixed;
    public function getManyConfigs(array $keys): array;
    public function setConfig(string $key, string $value, string $description = ''): void;
    public function setManyConfigs(array $data): void;

    // ── Users ─────────────────────────────────────────────────────────────
    public function listUsers(): array;
    public function findUser(int $id): array|false;
    public function findUserByUsername(string $username, int $excludeId = 0): array|false;
    public function findUserByEmail(string $email, int $excludeId = 0): array|false;
    public function createUser(array $data): int;
    public function updateUser(int $id, array $data): void;
    public function setUserStatus(int $id, string $status): void;
    public function updatePassword(int $id, string $hashed): void;
    public function getUserPassword(int $id): string|false;

    // ── Alerts ────────────────────────────────────────────────────────────
    public function listAlerts(bool $unreadOnly = false, int $limit = 50): array;
    public function markAlertRead(int $id): void;
    public function markAllAlertsRead(): void;
    public function resolveAlert(int $id, int $user_id): void;
    public function countUnread(): int;
}