<?php
// app/Contracts/InventoryRepositoryInterface.php

namespace App\Contracts;

interface InventoryRepositoryInterface
{
    // ── Lô hàng ───────────────────────────────────────────────────────────
    public function getBatches(
        string $keyword     = '',
        int    $category_id = 0,
        string $expiry      = '',
        int    $limit       = 25,
        int    $offset      = 0
    ): array;

    public function countBatches(
        string $keyword     = '',
        int    $category_id = 0,
        string $expiry      = ''
    ): int;

    public function getBatchKpi(): array;

    public function getBatchesForProduct(int $product_id): array;

    // ── Lịch sử xuất nhập ────────────────────────────────────────────────
    public function getHistory(array $filters, int $limit, int $offset): array;

    public function getHistoryKpi(array $filters): array;

    // ── Điều chỉnh tồn kho ───────────────────────────────────────────────
    public function findBatch(int $product_id, string $batch_no): array|false;

    public function updateBatchQuantity(int $id, int $quantity): void;

    public function writeAdjustHistory(
        int    $product_id,
        string $batch_no,
        int    $quantity,   // có dấu: dương = tăng, âm = giảm
        string $ref,
        int    $user_id
    ): void;

    // ── Misc ─────────────────────────────────────────────────────────────
    public function searchProducts(string $term): array;
    public function getActiveUsers(): array;
    public function getCategories(): array;
}