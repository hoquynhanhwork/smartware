<?php
// app/Contracts/SupplierRepositoryInterface.php

namespace App\Contracts;

interface SupplierRepositoryInterface
{
    // ── Danh sách + phân trang ───────────────────────────────────────────
    public function list(array $filters, int $limit, int $offset): array;
    public function count(array $filters): int;
    public function listActive(): array;

    // ── Thống kê ─────────────────────────────────────────────────────────
    public function countAll(): int;
    public function countByStatus(string $status): int;

    // ── Tìm 1 bản ghi ────────────────────────────────────────────────────
    public function findById(int $id): array|false;
    public function findByIdWithDebt(int $id): array|false;

    // ── Địa chỉ ──────────────────────────────────────────────────────────
    public function getDefaultAddress(int $supplierId): array|false;

    // ── Ghi ───────────────────────────────────────────────────────────────
    /** @return int ID bản ghi mới */
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function setStatus(int $id, string $status): void;
    public function softDelete(int $id): void;

    // ── Kiểm tra trùng lặp ───────────────────────────────────────────────
    public function existsByField(string $field, string $value, int $excludeId = 0): bool;
    public function existsByContactInfo(string $name, string $phone, string $email, int $excludeId = 0): bool;

    // ── Ràng buộc xóa ────────────────────────────────────────────────────
    public function hasLinkedProducts(int $supplierId): bool;
    public function hasLinkedInbounds(int $supplierId): bool;

    // ── AJAX helpers ─────────────────────────────────────────────────────
    public function getProducts(int $supplierId): array;
    public function getInboundHistory(int $supplierId, string $range = 'all'): array;
    public function getTotalImport(int $supplierId): float;
}