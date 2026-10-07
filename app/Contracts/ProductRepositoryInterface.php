<?php
// app/Contracts/ProductRepositoryInterface.php

namespace App\Contracts;

interface ProductRepositoryInterface
{
    // ── Đọc danh sách + phân trang ────────────────────────────────────────
    public function list(
        string $keyword      = '',
        array  $categoryIds  = [],
        array  $statuses     = [],
        array  $supplierIds  = [],
        float  $stockMin     = 0,
        float  $stockMax     = 999999999,
        int    $limit        = 15,
        int    $offset       = 0,
    ): array;

    public function count(
        string $keyword      = '',
        array  $categoryIds  = [],
        array  $statuses     = [],
        array  $supplierIds  = [],
        float  $stockMin     = 0,
        float  $stockMax     = 999999999,
    ): int;

    public function countAll(): int;
    public function countByStatus(string $status): int;

    // ── Tìm 1 bản ghi ────────────────────────────────────────────────────
    public function findById(int $id): array|false;
    /** $includeDeleted = true: tính cả sản phẩm đã xóa mềm (SKU của chúng vẫn bị "giữ chỗ"). */
    public function findBySku(string $sku, int $excludeId = 0, bool $includeDeleted = false): array|false;

    // ── Ghi ───────────────────────────────────────────────────────────────
    /** @return int ID bản ghi mới */
    public function create(array $data): int;
    /**
     * Tạo sản phẩm với SKU tự sinh (SPxxxxx), sinh + ghi trong cùng 1 transaction có khóa
     * để các request đồng thời không nhận trùng SKU.
     * @return array{id:int, sku:string}
     */
    public function createWithAutoSku(array $data): array;
    public function update(int $id, array $data): bool;
    public function setStatus(int $id, string $status): void;
    public function softDelete(int $id): void;

    // ── AI helpers ────────────────────────────────────────────────────────
    public function getSalesHistory(string $from, string $to): array;
    public function updateAiScores(int $id, string $direction, float $pctChange, float $volatility): void;

    // ── Misc ──────────────────────────────────────────────────────────────
    public function nextSkuNumber(): int;
    public function hasStockHistory(int $id): bool;
}